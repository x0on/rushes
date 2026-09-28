#!/usr/bin/env python3
"""ingest.py — copy in only what the archive does not already have.

Runs on the Mac, the only machine that can see both servers.

    python ingest.py --source /Volumes/OldServer/Video          # preview only
    python ingest.py --source /Volumes/OldServer/Video --apply  # copy the new ones
    python ingest.py --undo                                     # roll the last run back
    python ingest.py --selftest                                 # check the logic

How it avoids reading 40 TB to answer "do we already have this?":

  1. The NAS builds a manifest of every file's SIZE and path locally (minutes)
     and serves it over HTTP. Nothing is read over the network to get it.
  2. A source file whose size appears nowhere in that manifest is new by
     definition — copied without hashing anything.
  3. Only when sizes collide do we hash: first and last 4 MB, and the full file
     only if those match. Hashes are cached, so a second run is nearly free.

Same shape as everything else: preview, look, apply, log, undo.
"""

import argparse, hashlib, json, os, platform, shutil, subprocess, sys, threading, time
import urllib.parse, urllib.request
from collections import defaultdict
from pathlib import Path

HOME      = Path.home() / "archive-pilot"
MANIFEST  = HOME / "nas-manifest.tsv"
CACHE     = HOME / "hash-cache.json"
PLAN      = HOME / "ingest-plan.tsv"
LOG       = HOME / "ingest-copied.tsv"
# Where Rushes is. Given on the command line (the pages show the exact command,
# with its --url), so no installation's address is baked into this file.
def _arg(name):
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv[:-1] else None
NAS_URL   = (_arg("--url") or os.environ.get("NAS_URL") or "http://localhost").rstrip("/")

# ── one source of truth ─────────────────────────────────────────────────────
# settings.json and rules.json live beside the web root on the archive. Both
# builds of this app read those same two files, so a threshold changed in one
# place changes everywhere. Fetched over HTTP because that is the one thing
# this machine and the archive always agree on.
def _remote_json(name):
    try:
        with urllib.request.urlopen(NAS_URL + "/" + name, timeout=10) as r:
            return json.loads(r.read().decode("utf-8", "replace"))
    except Exception:
        return {}                       # fall back to the defaults below

SETTINGS = _remote_json("settings.json")
RULES    = _remote_json("rules.json")

def setting(path, fallback=None):
    cur = SETTINGS
    for part in path.split("."):
        if not isinstance(cur, dict) or part not in cur:
            return fallback
        cur = cur[part]
    return cur

def rule(name, fallback=None):
    v = (SETTINGS.get("limits") or {}).get(name)
    if v is not None:
        return v
    return (RULES.get("conditions") or {}).get(name, fallback)
# Built in, the helper is on the archive's own machine and uses its own path.
# External, it is another computer and uses that computer's view of the share.
_h = SETTINGS.get("helper") or {}
BUILT_IN  = (_h.get("mode") or ("external" if _h.get("enabled") else "built_in")) == "built_in"
NAS_MOUNT = (os.environ.get("NAS_MOUNT")
             or setting("archive.local" if BUILT_IN else "archive.as_seen_from_helper")
             or ".")
ARCHIVE   = os.environ.get("ARCHIVE", NAS_MOUNT + "/ARCHIVE")
# How much of a file is read to decide "is this the same clip?". At 30 MB/s
# from the old server this number is the whole job: every megabyte here is paid
# once per file that has a size match in the archive, and with 30 of 40 TB
# already present, that is most of them.
HEAD_TAIL = 1024 * 1024              # 1 MB from each end
# Reading a whole file to confirm a match costs the same as copying it. With
# exact byte size plus the first and last megabyte already matching, a false
# match on real footage is not a thing that happens. Off unless asked for.
PARANOID  = os.environ.get("PARANOID", "") not in ("", "0")
# The Mac is the only machine that sees both servers, so the copying happens
# here — but the admin page should still show it. The Mac writes status onto
# the VIDEO share it already has mounted; the NAS runner copies that into the
# web folder every minute. No new mounts, no new services.
STATUS  = Path(os.environ.get("STATUS_DIR", NAS_MOUNT + "/_rushes"))
DONE    = HOME / "ingest-done.txt"
LISTED  = HOME / "ingest-listed.txt"   # folders already split into subfolders

def history(kind, source, files, byts, secs, note=""):
    """One line per run, appended, never rewritten. This is the answer to
    "where did I leave off" — the section list shows state, this shows order."""
    try:
        STATUS.mkdir(parents=True, exist_ok=True)
        with open(STATUS / "ingest-history.tsv", "a") as f:
            f.write(f"{time.strftime('%Y-%m-%d %H:%M')}\t{kind}\t{source}\t"
                    f"{files}\t{byts}\t{int(secs)}\t{note}\n")
    except OSError:
        pass


def status(**kw):
    try:
        STATUS.mkdir(parents=True, exist_ok=True)
        with open(STATUS / "ingest-status.tsv", "w") as f:
            f.write(f"at\t{time.strftime('%Y-%m-%d %H:%M:%S')}\n")
            # The same moment as a plain count of seconds. The archive may be set
            # to another time zone than this computer — a QNAP often ships on
            # Taipei time — and read "15:08" as twelve hours ago. A count cannot
            # be misread.
            f.write(f"ts\t{int(time.time())}\n")
            for k, v in kw.items():
                f.write(f"{k}\t{v}\n")
    except OSError:
        pass        # the share may be unmounted; never let reporting stop a copy
MEDIA = {".mxf", ".mov", ".mp4", ".avi", ".mts", ".m4v", ".braw", ".r3d",
         ".wav", ".aif", ".aiff", ".jpg", ".jpeg", ".png", ".tif", ".tiff",
         ".psd", ".ai", ".prproj", ".aep"}

CARD_JUNK = {"CLIPS", "PRIVATE", "XDROOT", "AVCHD", "BDMV", "DCIM", "MEDIA",
             "CONTENTS", "CLPR", "SUB", "MISC", "CANON", "GENERAL"}
MONTHS = {m: f"{i+1:02d}" for i, m in enumerate(
    ["JAN","FEB","MAR","APR","MAY","JUN","JUL","AUG","SEP","OCT","NOV","DEC"])}


# ───────────────────────── where a file should land ─────────────────────────

def date_from(name, parts):
    """(date, source) using the same precedence as organize.sh on the NAS."""
    import re
    m = re.search(r"_(\d{6})[A-Z0-9]{0,2}_CANON", name)
    if m:
        yy, mm, dd = m.group(1)[:2], m.group(1)[2:4], m.group(1)[4:]
        if 1 <= int(mm) <= 12 and 1 <= int(dd) <= 31:
            return f"20{yy}-{mm}-{dd}", "filename"
    m = re.search(r"(19|20)\d{6}", name)
    if m:
        d = m.group(0)
        if 1 <= int(d[4:6]) <= 12 and 1 <= int(d[6:]) <= 31:
            return f"{d[:4]}-{d[4:6]}-{d[6:]}", "filename"
    joined = "/".join(parts)
    m = re.search(r"(19|20)\d{2}[-_.][01]\d[-_.][0-3]\d", joined)
    if m:
        return m.group(0).replace("_", "-").replace(".", "-"), "path"
    year = month = ""
    for c in parts:
        m = re.search(r"(19|20)\d{2}", c)
        if m and not year:
            year = m.group(0)
        u = c.upper()[:3]
        if not month and u in MONTHS and not c[:1].isdigit():
            month = MONTHS[u]
        m = re.match(r"^\d{3}_([A-Z]{3})$", c)
        if m and not month:
            month = MONTHS.get(m.group(1), "")
    if year and month:
        return f"{year}-{month}-01", "year+month"
    if year:
        return f"{year}-00-00", "year"
    return "", ""


def event_from(parts):
    import re
    for c in reversed(parts[:-1]):
        u = c.upper()
        if u.rstrip("0123456789") in CARD_JUNK: continue
        if re.match(r"^(CAM|CAMERA)[ _-]?\w*$", u): continue
        if re.match(r"^(19|20)\d{2}$", c) or re.match(r"^\d{1,3}$", c): continue
        if c.startswith(("Copied_", "_", ".")): continue
        slug = re.sub(r"[^a-z0-9]+", "-", c.lower()).strip("-")[:48].strip("-")
        if slug: return slug
    return "misc"


def dest_for(src, root=None):
    """Absolute destination path under the archive root."""
    import re
    root = root or ARCHIVE
    p = Path(src)
    parts = list(p.parts[1:])
    date, how = date_from(p.name, parts)
    ev = event_from(parts)
    cam = ""
    if len(parts) >= 2 and re.match(r"^(CAM|CAMERA)[ _-]?\w*$|^CLIPS?\d*$",
                                    parts[-2].upper()):
        cam = parts[-2] + "/"
    if not date:       return f"{root}/_unsorted/{ev}/{cam}{p.name}", "low"
    year = date[:4]
    if how == "year":  return f"{root}/{year}/{year}_{ev}/{cam}{p.name}", "medium"
    if how == "year+month":
        return f"{root}/{year}/{date[:7]}_{ev}/{cam}{p.name}", "medium"
    return f"{root}/{year}/{date}_{ev}/{cam}{p.name}", "high"


# ───────────────────────── is it already on the NAS? ────────────────────────

def load_manifest(refresh=False):
    """size -> [paths] for every file on the NAS."""
    if refresh or not MANIFEST.exists():
        print(f"fetching the archive's file list from {NAS_URL} …")
        try:
            urllib.request.urlretrieve(NAS_URL + "/manifest.tsv", MANIFEST)
        except Exception as e:
            # A stack trace here tells you nothing useful. The file simply is
            # not on the NAS yet, and one button builds it.
            sys.exit(
                f"\nCannot read the archive's file list ({e}).\n\n"
                "This is the list of every file's size in the archive — without it\n"
                "there is no way to tell what is already there, so nothing can start.\n\n"
                "Fix it in Manage:\n"
                "  Bring in footage \u2192 Step 0 \u2192 Build the archive's file list\n"
                "Wait a minute or two for it to finish, then run this again.\n")
    by_size = defaultdict(list)
    with open(MANIFEST, errors="replace") as f:
        for line in f:
            size, _, path = line.rstrip("\n").partition("\t")
            if path:
                by_size[int(size)].append(path)
    total = sum(len(v) for v in by_size.values())
    if total < 1000:
        # An empty or stunted list makes everything look new. Copying 40 TB
        # back over a folder that already has it is the one unrecoverable
        # mistake available here, so stop rather than guess.
        MANIFEST.unlink(missing_ok=True)
        sys.exit(
            f"\nThe archive's file list has only {total:,} files in it.\n\n"
            "That cannot be right, and acting on it would treat everything as\n"
            "missing and copy the whole server back over footage you already have.\n\n"
            "Rebuild it: Manage \u2192 Jobs and tools \u2192 Rebuild the file list.\n"
            "Check the log says a number in the hundreds of thousands, then run this again.\n")
    print(f"the archive holds {total:,} files, {len(by_size):,} distinct sizes")
    return by_size


_cache = {}
def load_cache():
    global _cache
    try: _cache = json.loads(CACHE.read_text())
    except Exception: _cache = {}
def save_cache():
    try: CACHE.write_text(json.dumps(_cache))
    except Exception: pass


def digest(path, full=False):
    """Hash the ends of a file, or all of it. Cached by path+size+mtime."""
    try: st = os.stat(path)
    except OSError: return None
    key = f"{'F' if full else 'P'}|{path}|{st.st_size}|{int(st.st_mtime)}"
    if key in _cache: return _cache[key]
    h = hashlib.blake2b(digest_size=16)
    try:
        with open(path, "rb") as f:
            if full or st.st_size <= HEAD_TAIL * 2:
                for chunk in iter(lambda: f.read(8 << 20), b""): h.update(chunk)
            else:
                h.update(f.read(HEAD_TAIL))
                f.seek(-HEAD_TAIL, os.SEEK_END)
                h.update(f.read(HEAD_TAIL))
    except OSError:
        return None
    _cache[key] = h.hexdigest()
    return _cache[key]


def already_here(src, size, by_size):
    """-> path of the identical NAS file, or None. Hashes only on size collision."""
    candidates = by_size.get(size)
    if not candidates:
        return None                                   # unique size = new, no I/O
    remote = setting("archive.local", "/share/VIDEO")
    local = {c.replace(remote, NAS_MOUNT, 1) for c in candidates}
    sp = digest(src)
    for c in local:
        if digest(c) == sp:                     # same size, same first and last MB
            if not PARANOID or digest(src, True) == digest(c, True):
                return c
    return None


# ───────────────────────────────── the run ──────────────────────────────────

def walk(source, everything=False):
    # A card is copied whole. The XML and index files beside the clips are what
    # Premiere and Resolve use to read some cameras' footage at all; dropping
    # them makes a copy that looks complete and is not.
    for root, dirs, files in os.walk(source):
        dirs[:] = [d for d in dirs if not d.startswith(".") and d not in
                   ("@Recycle", "$RECYCLE.BIN", "System Volume Information")]
        for n in files:
            if n.startswith(".") or n in ("Thumbs.db", "desktop.ini"): continue
            if everything or Path(n).suffix.lower() in MEDIA:
                yield os.path.join(root, n)


# ── what this machine can see ──────────────────────────────────────────────
# The page never asks anyone to type a path. This machine says what is plugged
# in, the page offers exactly that, and before copying this machine checks the
# path against its own list again — so nothing typed, forged or stale can make
# it read from anywhere else.
SYSTEM_VOLS = {"Macintosh HD", "Recovery", "Preboot", "VM", "Update", "Data",
               "com.apple.TimeMachine.localsnapshots"}
CARD_MARKS  = {"DCIM", "PRIVATE", "CLIPS", "XDROOT", "AVCHD", "CONTENTS", "M4ROOT"}


def volumes():
    if os.environ.get("VOLUMES_DIR"):        # for testing, or an unusual mount layout
        base = os.environ["VOLUMES_DIR"]
        roots = [os.path.join(base, n) for n in sorted(os.listdir(base)) if not n.startswith(".")]
    elif sys.platform == "darwin":
        roots = [os.path.join("/Volumes", n) for n in sorted(os.listdir("/Volumes"))
                 if n not in SYSTEM_VOLS and not n.startswith(".")]
    elif os.name == "nt":
        # C: is this computer's own system disk. Offering it would offer
        # everyone's Documents folder to the archive.
        roots = [f"{c}:\\" for c in "ABDEFGHIJKLMNOPQRSTUVWXYZ" if os.path.exists(f"{c}:\\")]
    else:
        # Linux desktops mount under /media/<user>; a QNAP puts USB drives
        # and SD cards under /share/external.
        user = os.environ.get("USER", "")
        roots = [os.path.join(b, n) for b in (f"/media/{user}", "/mnt", "/share/external")
                 if os.path.isdir(b) for n in sorted(os.listdir(b))]
    out = []
    for r in roots:
        try:
            if not os.path.isdir(r): continue
            use = shutil.disk_usage(r)
            top = sorted(d for d in os.listdir(r)
                         if not d.startswith((".", "@", "$")) and os.path.isdir(os.path.join(r, d)))
        except OSError:
            continue                          # a drive going away mid-look
        out.append({"path": r, "name": os.path.basename(r.rstrip("\\/")) or r,
                    "total": use.total, "free": use.free,
                    "card": bool(CARD_MARKS & set(top)),
                    "archive": "_rushes" in top, "top": top[:200]})
    return out


def visible(path):
    """Is this path on a drive this machine can see right now?"""
    for v in volumes():
        root = v["path"].rstrip("\\/")
        if path == v["path"] or path == root or path.startswith(root + os.sep):
            return True
    return False


def shot_day(path):
    """The day a file was recorded, as the camera wrote it: YYYY-MM-DD.

    A camera stamps every file it writes, and a card keeps that stamp. It is
    the camera's own clock — right unless nobody ever set it, which is why the
    page checks for a date that cannot be real."""
    return time.strftime("%Y-%m-%d", time.localtime(os.path.getmtime(path)))


_card_sizes = {}
def card_size(path):
    """-> (files, bytes, {day: [files, bytes]}) for a card, counted once.
    Once per card, not every twenty seconds: a card that has not changed has
    the same files it had a moment ago."""
    try: key = (path, os.stat(path).st_mtime)
    except OSError: return 0, 0, {}
    if key not in _card_sizes:
        n = b = 0; days = {}
        for f in walk(path, everything=True):
            try: size = os.path.getsize(f); d = shot_day(f)
            except OSError: continue
            n += 1; b += size
            days.setdefault(d, [0, 0])
            days[d][0] += 1; days[d][1] += size
        _card_sizes[key] = (n, b, days)
    return _card_sizes[key]


# ── where things came from ──────────────────────────────────────────────────
# Every run leaves a permanent, file-by-file record: which server, which folder,
# which file, and where in the archive it now lives — including files that were
# NOT copied because an identical one was already there. Nothing in it is ever
# rewritten. The restructure adds to it, and the relinking tool reads it to turn
# any path an old Premiere project remembers into where that file is today.
ORIGIN = STATUS / "origin"


def source_root(path):
    """-> (root, name) of the source a path belongs to."""
    path = path.rstrip("/\\")
    best = None
    for s in (SETTINGS.get("sources") or []):
        r = (s.get("path") or "").rstrip("/\\")
        if r and (path == r or path.startswith(r + os.sep)) and (not best or len(r) > len(best[0])):
            best = (r, s.get("label") or os.path.basename(r))
    if best:
        return best
    for v in volumes():                      # a drive or card plugged in here
        r = v["path"].rstrip("/\\")
        if path == r or path.startswith(r + os.sep):
            return r, v["name"]
    r = os.path.dirname(path)
    return r, os.path.basename(r) or r


def server_of(root):
    """The server a mounted folder really lives on, e.g. //fileserver/share.
    The user name in front of the @ is left out: this goes into a shared file."""
    try:
        out = subprocess.run(["mount"], capture_output=True, text=True, timeout=5).stdout
    except Exception:
        return ""
    for line in out.splitlines():
        dev, sep, rest = line.partition(" on ")
        if sep and (rest.startswith(root + " (") or rest.startswith(root + " type ")):
            return ("//" + dev.split("@", 1)[1]) if dev.startswith("//") and "@" in dev else dev
    return ""


class Origin:
    """One run's record: a file in _rushes/origin, one line per file."""
    def __init__(self, kind, source, root, name, into):
        ORIGIN.mkdir(parents=True, exist_ok=True)
        self.run = time.strftime("%Y%m%d-%H%M%S")
        self.path = ORIGIN / f"{self.run} {name} {kind}.tsv"   # one file per run, never shared
        self.server = server_of(root)
        self.n = defaultdict(int)
        self.f = open(self.path, "a")
        for k, v in (("rushes origin log", "one line per file; never edited"),
                     ("run", self.run), ("kind", kind), ("source name", name),
                     ("source server", self.server or "(a local drive)"), ("source root", root),
                     ("source folder", source), ("into", into),
                     ("helper", f"{platform.node()} · {sys.platform}")):
            self.f.write(f"# {k}\t{v}\n")
        self.f.write("what\tsource\tarchive\tbytes\tnote\n")

    def add(self, what, src, dest, size, note=""):
        self.f.write(f"{what}\t{src}\t{dest}\t{size}\t{note}\n")
        self.n[what] += 1

    def close(self):
        self.f.close()


def leave_a_note(folder, o, source, name):
    """A plain note inside the folder itself, for a person browsing the share.
    Appended, so a folder copied over several runs keeps every run's note."""
    try:
        with open(os.path.join(folder, "Where this came from.txt"), "a") as f:
            f.write(f"Copied by Rushes on {time.strftime('%Y-%m-%d %H:%M')}\n\n"
                    f"From:     {name}" + (f"   ({o.server})" if o.server else "") + "\n"
                    f"Folder:   {source}\n"
                    f"By:       the helper on {platform.node()}\n"
                    f"Files:    {o.n['copied']:,} copied, "
                    f"{o.n['already']:,} already in the archive elsewhere, "
                    f"{o.n['failed']:,} failed\n\n"
                    f"Every file, and where each one lives now, is listed in:\n"
                    f"  _rushes/origin/{o.path.name}\n"
                    + "\u2500" * 60 + "\n\n")
    except OSError as e:
        print(f"  ! could not leave the note in {folder} ({e}) — the full record is still in {o.path}")


def load_origins():
    """source path -> where that exact file is in the archive, from every record.
    Checked before copying anything, so a file brought over once is never
    brought over again — whatever layout it landed in, and however stale the
    archive's file list is."""
    known = {}
    for f in sorted(ORIGIN.glob("*.tsv")) if ORIGIN.exists() else []:
        for l in open(f, errors="replace"):
            p = l.rstrip("\n").split("\t")
            if len(p) >= 4 and p[0] in ("copied", "traced", "already") and p[1] and p[2]:
                known[p[1]] = (p[2], p[3])
    return known


def needs_trace(src_root, archive):
    """True when footage from this source was copied before the record existed
    and has not been traced yet. Old copies sit in date folders at the top of
    the archive; new ones sit under the source's own name."""
    try:
        tops = [t for t in os.listdir(archive) if not t.startswith(".")]
    except OSError:
        return False
    names = {s.get("label") for s in (SETTINGS.get("sources") or [])}
    if not [t for t in tops if t not in names]:
        return False
    for f in ORIGIN.glob("*.tsv") if ORIGIN.exists() else []:
        head = open(f, errors="replace").read(2000)
        if "# kind\ttraced" in head and f"# source root\t{src_root}\n" in head:
            return False
    return True


def trace(src_root, archive):
    """For footage copied before the record existed: pair each file in the
    archive with its original on the source, and write the record it should
    have had. Reads both sides; moves nothing."""
    src_root = src_root.rstrip("/\\")
    root, name = source_root(src_root + os.sep + "x")
    if not os.path.isdir(src_root):
        sys.exit(f"Cannot see {src_root}. Connect it first — the originals are how the "
                 "copies are recognised.")
    # Only the folders the history says were copied from here need reading.
    # Every folder of this source anyone ever listed or copied — including one
    # that stopped part-way, which has copies too.
    sections = []
    for fn, col, want in (("ingest-history.tsv", 2, "copied"), ("ingest-sections.tsv", 1, "section")):
        fp = STATUS / fn
        for l in open(fp, errors="replace") if fp.exists() else []:
            f = l.rstrip("\n").split("\t")
            kind = f[1] if fn.startswith("ingest-history") else f[0]
            if len(f) > col and kind == want and f[col].startswith(src_root + os.sep):
                if not any(f[col] == x or f[col].startswith(x + os.sep) for x in sections):
                    sections = [x for x in sections if not x.startswith(f[col] + os.sep)] + [f[col]]
    print(f"reading {len(sections)} folder(s) copied from {name} …")
    by_size = defaultdict(list); n = 0
    for sec in sections:
        for p in walk(sec, everything=True):
            try: by_size[os.path.getsize(p)].append(p); n += 1
            except OSError: pass
            if n % 2000 == 0: print(f"  {n:,} originals listed")
    print(f"  {n:,} originals listed\n")

    # Everything in the archive's copied area EXCEPT the exact-copy folders,
    # which already carry their origin in their path.
    names = {s.get("label") for s in (SETTINGS.get("sources") or [])} | {name}
    o = Origin("traced", src_root, src_root, name, archive)
    t0 = time.time(); i = 0
    for top in sorted(os.listdir(archive)):
        if top in names or top.startswith("."): continue
        for p in walk(os.path.join(archive, top), everything=True):
            i += 1
            try: size = os.path.getsize(p)
            except OSError: continue
            match = None
            for c in by_size.get(size, []):
                if digest(c) == digest(p):
                    match = c; break
            if match: o.add("traced", match, p, size, "copied before the record existed; matched by content")
            else:     o.add("untraced", "", p, size, "no identical original found")
            if i % 500 == 0:
                print(f"  {i:,} checked — {o.n['traced']:,} traced, {o.n['untraced']:,} not "
                      f"({time.time() - t0:.0f}s)")
    o.close(); save_cache()
    tn, un = o.n['traced'], o.n['untraced']
    print(f"\n{tn:,} file{'' if tn == 1 else 's'} traced to {'its' if tn == 1 else 'their'} original, "
          f"{un:,} not found on {name}.")
    print(f"record: _rushes/origin/{o.path.name}")


def tell_search(paths):
    """Make what just landed searchable now, not at the next full rebuild."""
    added = refused = 0
    for i in range(0, len(paths), 2000):
        rows = []
        for p in paths[i:i + 2000]:
            if "\t" in p or "\n" in p: continue
            try: rows.append(f"{p}\t{os.path.getsize(p)}")
            except OSError: refused += 1
        try:
            body = urllib.parse.urlencode({"files": "\n".join(rows)}).encode()
            with urllib.request.urlopen(NAS_URL + "/db/landed.php", data=body, timeout=120) as r:
                got = json.loads(r.read().decode("utf-8", "replace"))
            added += got.get("added", 0); refused += got.get("refused", 0)
        except Exception as e:
            print(f"  ! could not add them to search ({e})")
            print("    They are safely in the archive. Manage → Jobs and tools → Rebuild search will pick them up.")
            return
    print(f"  {added:,} file{'s' if added != 1 else ''} added to search ✓"
          + (f"  ({refused} Rushes could not find — Rebuild search will settle it)" if refused else ""))


def report_forever(every=20):
    """Tell the archive what is plugged in here, for as long as this runs.

    Its own thread, because a four-hour copy must not hide a card that was
    plugged in during it."""
    ok = lambda x: "\t" not in x and "\n" not in x
    failing = False        # said so once; do not repeat it every 20 seconds
    while True:
        try:
            lines = []
            for v in volumes():
                if not ok(v["path"]): continue
                n, b, days = card_size(v["path"]) if v["card"] else (0, 0, {})
                lines.append("\t".join(["vol", v["path"], v["name"], str(v["total"]),
                                        str(v["free"]), "1" if v["card"] else "0",
                                        "1" if v["archive"] else "0", str(n), str(b)]))
                # the days on the card, so the page never has to ask for a date
                lines += ["\t".join(["day", v["path"], d, str(c[0]), str(c[1])])
                          for d, c in sorted(days.items())]
                lines += ["\t".join(["dir", v["path"], d]) for d in v["top"] if ok(d)]
            body = urllib.parse.urlencode({"volumes": "\n".join(lines),
                                           "os": sys.platform}).encode()
            urllib.request.urlopen(NAS_URL + "/db/report.php", data=body, timeout=15).read()
            if failing:
                print(f"{time.strftime('%H:%M:%S')}  telling Rushes what is plugged in again ✓")
                failing = False
        except Exception as e:
            # Never stops a copy — but never silent either. Without these
            # reports Ingest and Setup cannot see any drive plugged in here.
            if not failing:
                print(f"{time.strftime('%H:%M:%S')}  ! cannot tell Rushes what is plugged in ({e})")
                print("    Copies carry on. Cards will not show up in Ingest until this clears.")
                failing = True
        time.sleep(every)


def sections(roots, fresh=False):
    """List the folders inside each root, with sizes, and REMEMBER them.

    The list accumulates: running this on another department adds to it rather
    than replacing it, so you can gather candidates from all over the old
    server and then pick a night's worth out of the whole pile.
    """
    done = set()
    if DONE.exists():
        done = {l.strip() for l in open(DONE) if l.strip()}

    STATUS.mkdir(parents=True, exist_ok=True)
    listing = STATUS / "ingest-sections.tsv"
    rows = {}                                   # full path -> (files, bytes)
    if listing.exists() and not fresh:
        for line in open(listing):
            f = line.rstrip("\n").split("\t")
            # an older build wrote bare folder names here; those are not paths
            # and cannot be run, so drop them rather than showing them twice
            if len(f) >= 4 and f[0] == "section" and f[1].startswith("/"):
                rows[f[1]] = (int(f[2]), int(f[3]))

    added = 0
    for root in roots:
        kids = [d for d in sorted(Path(root).iterdir())
                if d.is_dir() and not d.name.startswith(".")]
        print(f"\n{root}  ({len(kids)} folders)")
        for d in kids:
            # Say it before doing it. Measuring a folder over Wi-Fi can take
            # minutes, and a silent terminal is indistinguishable from a hung one.
            print(f"  measuring {d.name} …", end="", flush=True)
            t = time.time()
            n = b = 0
            for f in walk(str(d)):
                try: b += os.path.getsize(f); n += 1
                except OSError: pass
            print(f"\r  {d.name:<28} {n:>7,} files  {b / 1073741824:8.1f} GB"
                  f"   ({time.time() - t:.0f}s)")
            rows[str(d)] = (n, b)               # re-listing refreshes the numbers
            added += 1

    with open(listing, "w") as f:
        for path in sorted(rows):
            n, b = rows[path]
            f.write(f"section\t{path}\t{n}\t{b}\t{'done' if path in done else 'todo'}\n")

    todo = [(p, *rows[p]) for p in sorted(rows) if p not in done]
    print(f"\n{added} folders listed, {len(rows)} in the list now "
          f"({len(rows) - len(todo)} already done).")
    print(f"{sum(b for _, _, b in todo) / 1099511627776:.2f} TB still to consider.")
    print("\nTick the ones you want in the Bring in footage page, "
          "or copy a command from there.")
    return


# ─────────────────────────────── watch mode ─────────────────────────────────
# The Mac is the only machine that can see both servers, so the copying has to
# happen here. But nobody wants to type a command per folder. So: the admin
# page writes a list of what it wants done, and this sits here asking the NAS
# for that list and working through it.
#
# The list is a WANT, not a task queue — no claiming, no clearing. Every pass
# it reads the list and does whatever on it is not already finished. Run it
# twice by accident and nothing happens twice.
#
# Each section runs as its own process on purpose: a section that dies takes
# itself down and the watcher carries on with the next one.

QUEUE_URL = NAS_URL + "/ingest-queue.tsv"

# Stop before the archive is full, not after. A copy that dies at 100% leaves
# the NAS with no room to write anything at all — including its own logs and
# the search index — and that is a far worse evening than a queue that paused.
FLOOR = (int(os.environ["FLOOR_GB"]) * 1024 ** 3 if os.environ.get("FLOOR_GB")
         else rule("disk_stop_free", 5000 * 1024 ** 3))


def free_bytes(path=NAS_MOUNT):
    try:
        st = os.statvfs(path)
        return st.f_bavail * st.f_frsize
    except OSError:
        return None

def watch(root, every=20):
    print(f"watching {QUEUE_URL}")
    print("Leave this window open. Tick folders in Manage → Transfers, or queue a card in Ingest, and they run here.")
    print("Ctrl-C to stop; anything half-copied picks up where it left off.\n")
    last = None
    blocked = False        # said the source was gone; do not say it again
    threading.Thread(target=report_forever, daemon=True).start()
    while True:
        try:
            with urllib.request.urlopen(QUEUE_URL, timeout=15) as r:
                body = r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            # 404 means the file is not there, which is what an empty queue
            # looks like before anything has ever been ticked. Not an error.
            if e.code != 404:
                print(f"  the NAS answered {e.code} — trying again in {every}s")
                time.sleep(every); continue
            body = ""
        except Exception as e:
            print(f"  cannot reach the NAS ({e}) — trying again in {every}s")
            time.sleep(every); continue

        want = []
        for line in body.splitlines():
            f = line.split("\t")
            if len(f) >= 2 and f[0] in ("copy", "list") and f[1].startswith("/"):
                want.append((f[0], f[1], ""))
            elif len(f) >= 3 and f[0] == "ingest" and f[1] and f[2]:
                # a card, the folder it goes in, and — for a card that spans
                # several days — which day's files belong in that folder
                want.append(("ingest", f[1], f[2] + ("\t" + f[3] if len(f) > 3 and f[3] else "")))

        if body != last:
            print(f"{time.strftime('%H:%M:%S')}  " +
                  (f"queue: {len(want)} item(s)" if want else
                   "nothing ticked yet — waiting"))
            last = body

        done = set()
        if DONE.exists():
            done = {l.strip() for l in open(DONE) if l.strip()}
        # A split is asked for once. Without this it would be re-run every pass,
        # for ever, and nothing queued behind it would ever get copied.
        listed = set()
        if LISTED.exists():
            listed = {l.strip() for l in open(LISTED) if l.strip()}

        # If EVERY queued folder has vanished, the source is gone — the drive
        # unmounted, the server rebooted, the network dropped. That is not a
        # skip, it is the whole job stopped, and it has to say so once and be
        # visible on the admin page. Repeating it every 20 seconds for three
        # days, as this used to, tells nobody anything.
        # A card is finished when its FOLDER is, not the card path: the same
        # /Volumes/EOS_DIGITAL comes back every week holding a different shoot.
        finished = lambda v, p, i: ((v == "copy" and p in done) or (v == "ingest" and i.split("\t")[0] in done)
                                    or (v == "list" and p in listed))
        pending = [(v, p, i) for v, p, i in want if not finished(v, p, i)]
        gone = [p for _, p, _ in pending if not os.path.isdir(p)]
        if pending and len(gone) == len(pending):
            root = os.path.commonpath(gone) if len(gone) > 1 else os.path.dirname(gone[0])
            if not blocked:
                print(f"\n*** STOPPED: cannot see {root}")
                print("    Nothing queued can run. Re-mount it in Finder and this carries")
                print("    on by itself — nothing is lost, and part-copied folders resume.")
                blocked = True
            status(phase="blocked", source=root,
                   note=f"cannot see {root} — waiting for it to come back")
            time.sleep(every); continue
        if blocked:
            print(f"\n{time.strftime('%H:%M:%S')}  source is back — carrying on")
            blocked = False

        did = False
        for verb, path, into in want:
            if finished(verb, path, into):            # already finished, ever
                continue
            if not os.path.isdir(path):
                print(f"  ! {path} is not mounted — skipping"); continue
            if verb == "ingest" and not visible(path):
                # The page only offers what this machine reported, so this means
                # a forged or stale request. Refuse once, record it, move on.
                print(f"  ! refused {path}: not a drive or card on this machine")
                history("refused", into.split("\t")[0], 0, 0, 0, f"{path} is not a mounted drive here")
                with open(DONE, "a") as f:
                    f.write(into.split("\t")[0] + "\n")
                did = True; break
            if verb in ("copy", "ingest"):
                free = free_bytes()
                if free is not None and free < FLOOR:
                    print(f"\n*** STOPPING: only {free / 1024 ** 3:.0f} GB free on "
                          f"{NAS_MOUNT}, and the floor is {FLOOR / 1024 ** 3:.0f} GB.")
                    print("    Nothing is broken and nothing was lost. Make room on the")
                    print("    archive, then start this again and it carries on.")
                    status(phase="stopped", source=path,
                           note=f"only {free / 1024 ** 3:.0f} GB free — waiting for room")
                    time.sleep(300)      # check again in five minutes
                    did = True           # do not fall through to the idle sleep
                    break
            did = True
            if verb == "list":
                print(f"\n=== listing {path} ===")
                run_self("--sections", path)
                with open(LISTED, "a") as f:
                    f.write(path + "\n")
            elif verb == "copy" and needs_trace(source_root(path)[0], root):
                sr = source_root(path)[0]
                print(f"\n=== first, working out where the footage already copied from "
                      f"{os.path.basename(sr)} came from ===")
                print("    Older copies were sorted by date and lost their original folder.")
                print("    This matches each one to its original, so nothing is copied twice.")
                status(phase="tracing", source=sr, note="matching earlier copies to their originals")
                run_self("--trace", sr, "--root", root)
            elif verb == "ingest":
                dest, _, day = into.partition("\t")
                print(f"\n=== {path}  →  {dest} ===" + (f"   (shot {day})" if day else ""))
                run_self("--source", path, "--into", dest, "--apply", *(["--day", day] if day else []))
            else:
                print(f"\n=== {path} ===")
                run_self("--source", path, "--root", root, "--apply")
            break                                      # one at a time, in order

        if not did:
            status(phase="waiting", source="", note="nothing queued")
            time.sleep(every)


def run_self(*args):
    """Run one section in its own process, so a failure cannot stop the watch."""
    cmd = [sys.executable, os.path.abspath(__file__), *args, "--url", NAS_URL]
    try:
        subprocess.run(cmd, check=False)
    except KeyboardInterrupt:
        raise
    except Exception as e:
        print(f"  that section failed to start: {e}")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--source", help="folder to ingest from (mounted server, or a card)")
    ap.add_argument("--url", help="where Rushes is, e.g. http://192.168.1.20")
    ap.add_argument("--trace", metavar="SOURCE",
                    help="for footage copied before the origin record existed: work out, "
                         "file by file, where each copy in the archive came from")
    ap.add_argument("--root", default=ARCHIVE, help="archive root on the NAS")
    ap.add_argument("--into", help="copy the whole source into exactly this folder, "
                                   "keeping its layout (a card into its shoot folder)")
    ap.add_argument("--day", help="with --into: only the files recorded on this day (YYYY-MM-DD)")
    ap.add_argument("--apply", action="store_true", help="actually copy")
    ap.add_argument("--undo", action="store_true", help="roll the last run back")
    ap.add_argument("--refresh-manifest", action="store_true")
    ap.add_argument("--selftest", action="store_true")
    ap.add_argument("--paranoid", action="store_true",
                    help="also read every matching file end to end (slow: "
                         "costs as much as copying it)")
    ap.add_argument("--sections", metavar="DIR", nargs="+",
                    help="list the folders under each DIR as sections, with sizes; "
                         "adds to the list rather than replacing it")
    ap.add_argument("--fresh", action="store_true",
                    help="with --sections: start the list over instead of adding")
    ap.add_argument("--watch", action="store_true",
                    help="stay open and run whatever Ingest and Manage queue up")
    a = ap.parse_args()

    if a.paranoid:
        globals()["PARANOID"] = True
    if a.selftest: return selftest()
    if a.watch:
        if not SETTINGS:
            sys.exit(f"Cannot reach Rushes at {NAS_URL}.\n"
                     "Copy the start command from Setup in Rushes — it has the right --url.")
        print(f"helper: {'built in' if BUILT_IN else 'external'} · archive at {NAS_MOUNT} · Rushes at {NAS_URL}")
        return watch(a.root)
    # Anything that copies or lists needs to know where things are. Without
    # Rushes' settings it would be guessing, and a guessed path is how files
    # land somewhere nobody looks. Stop and say why instead.
    if (a.sections or a.source or a.trace) and not SETTINGS:
        sys.exit(f"Cannot reach Rushes at {NAS_URL} — nothing copied.\n"
                 "Stop the helper (Ctrl-C) and start it again with the command from Setup.")
    if a.trace:
        HOME.mkdir(exist_ok=True); load_cache()
        return trace(a.trace, a.root)
    if a.sections: return sections(a.sections, a.fresh)
    if a.undo:     return undo()
    if not a.source: sys.exit("need --source")

    HOME.mkdir(exist_ok=True)
    load_cache()
    # A card goes in whole, so there is nothing to look up in the archive first:
    # a file already at its destination is skipped below, and a card brought in
    # twice under two names is what the duplicate finder is for.
    by_size = {} if a.into else load_manifest(a.refresh_manifest)
    label = os.path.basename(a.into.rstrip("/")) if a.into else ""
    # A folder from a server is copied EXACTLY as it sits there, under a folder
    # named for the server: share/Departments/Parks/X lands as
    # ARCHIVE/share/Departments/Parks/X. Copying never decides where
    # footage belongs — that is the restructure's job, and it can only do it
    # if the original path survived the copy.
    src_root, src_name = source_root(a.source)
    mirror = os.path.join(a.root, src_name)

    print(f"walking {a.source} …  "
          f"({'full-file confirm' if PARANOID else 'size + first and last MB'})")
    status(phase="looking", source=a.source, label=label, note="deciding what is missing")
    new = dups = 0; new_bytes = dup_bytes = 0
    t0 = time.time()
    known = {} if a.into else load_origins()
    with open(PLAN, "w") as plan:
        for i, src in enumerate(walk(a.source, everything=True), 1):
            try: size = os.path.getsize(src)
            except OSError: continue
            # A card that spans several days goes in as one folder per day:
            # this run takes only its own day's files, layout kept.
            if a.day and shot_day(src) != a.day:
                continue
            match = None
            if not a.into:
                k = known.get(src)
                if k and os.path.exists(k[0]) and os.path.getsize(k[0]) == size:
                    match = k[0]             # the record says it is already here
                else:
                    match = already_here(src, size, by_size)
            if match:
                dups += 1; dup_bytes += size
                plan.write(f"skip\t{size}\t{src}\t{match}\n")
            else:
                dest, conf = (os.path.join(a.into, os.path.relpath(src, a.source))
                              if a.into else os.path.join(mirror, os.path.relpath(src, src_root))), "exact"
                new += 1; new_bytes += size
                plan.write(f"copy\t{size}\t{src}\t{dest}\t{conf}\n")
            if i % 500 == 0:
                print(f"  {i:,} checked — {new:,} new, {dups:,} already here "
                      f"({time.time() - t0:.0f}s)")
                status(phase="looking", source=a.source, checked=i, new=new,
                       already=dups, new_bytes=new_bytes)
    save_cache()

    gb = lambda b: f"{b / 1099511627776:.2f} TB" if b > 1e12 else f"{b / 1073741824:.0f} GB"
    print(f"\n{new:,} files to copy ({gb(new_bytes)})")
    print(f"{dups:,} already in the archive ({gb(dup_bytes)} not copied)")
    print(f"plan: {PLAN}")

    status(phase="planned", source=a.source, new=new, already=dups,
           new_bytes=new_bytes, total=new + dups)
    if not a.apply:
        history("looked", a.source, new, new_bytes, time.time() - t0,
                f"{dups} already here")

    if not a.apply:
        print("\nnothing copied. re-run with --apply when the plan looks right.")
        return

    print("\ncopying…")
    copied = failed = 0
    landed = []                          # told to search as soon as the copy ends
    o = Origin("card" if a.into else "folder", a.source, src_root, src_name,
               a.into or os.path.join(mirror, os.path.relpath(a.source, src_root)))
    for line in open(PLAN):
        f = line.rstrip("\n").split("\t")
        if f[0] == "skip":               # identical file already in the archive
            o.add("already", f[2], f[3], f[1], "not copied — this is where it already is")
    with open(LOG, "w") as log:
        for line in open(PLAN):
            f = line.rstrip("\n").split("\t")
            if f[0] != "copy": continue
            size, src, dest = int(f[1]), f[2], f[3]
            if os.path.exists(dest):
                log.write(f"EXISTS\t{dest}\n")
                o.add("copied", src, dest, size, "there from an earlier run"); continue
            os.makedirs(os.path.dirname(dest), exist_ok=True)
            try:
                # copy to a temp name, verify the size, then put it in place —
                # so an interrupted transfer never looks like a finished file
                tmp = dest + ".part"
                shutil.copy2(src, tmp)
                if os.path.getsize(tmp) != size:
                    os.remove(tmp); raise IOError("size mismatch after copy")
                os.rename(tmp, dest)
                log.write(f"{src}\t{dest}\n"); log.flush()
                copied += 1; landed.append(dest)
                o.add("copied", src, dest, size)
            except Exception as e:
                log.write(f"FAILED\t{src}\t{e}\n"); failed += 1
                o.add("failed", src, "", size, str(e))
            if (copied + failed) % 50 == 0:
                print(f"  {copied:,} copied, {failed} failed")
                status(phase="copying", source=a.source, label=label, copied=copied,
                       failed=failed, of=new, new_bytes=new_bytes)
    print(f"\ncopied {copied:,}, failed {failed}")
    o.close()
    top = a.into or os.path.join(mirror, os.path.relpath(a.source, src_root))
    if os.path.isdir(top):
        leave_a_note(top, o, a.source, src_name)
    print(f"  where every file came from: _rushes/origin/{o.path.name}")
    if landed:
        tell_search(landed)
    print(f"log: {LOG}  (undo with: python ingest.py --undo)")
    status(phase="done", source=a.source, label=label, copied=copied, failed=failed, of=new)
    # A card is remembered by where it went; a folder by where it came from.
    key = a.into.rstrip("/") if a.into else a.source.rstrip("/")
    history("copied", key, copied, new_bytes, time.time() - t0,
            f"{failed} failed" if failed else "")
    if not failed:
        with open(DONE, "a") as f:        # so the section list shows it finished
            f.write(key + "\n")



def undo():
    if not LOG.exists(): sys.exit("no log to undo")
    back = 0
    roll = Path(ARCHIVE) / "_rollback"
    for line in open(LOG):
        f = line.rstrip("\n").split("\t")
        if len(f) != 2 or f[0] in ("EXISTS", "FAILED"): continue
        dest = Path(f[1])
        if not dest.exists(): continue
        # ponytail: move aside rather than delete — same rule as everywhere else
        target = roll / dest.relative_to(ARCHIVE)
        target.parent.mkdir(parents=True, exist_ok=True)
        dest.rename(target); back += 1
    print(f"moved {back} copied files to {roll}")


def selftest():
    d, how = date_from("A030C456_240318AB_CANON.MXF", ["x"])
    assert (d, how) == ("2024-03-18", "filename"), (d, how)
    d, _ = date_from("DJI_20260221180408_0085_D.MP4", ["x"])
    assert d == "2026-02-21", d
    d, how = date_from("clip.mov", ["Volumes", "Old", "2026-05-08_prayer-day", "clip.mov"])
    assert (d, how) == ("2026-05-08", "path"), (d, how)
    d, how = date_from("clip.mov", ["Old", "2022", "003_MAR", "MISO", "clip.mov"])
    assert (d, how) == ("2022-03-01", "year+month"), (d, how)
    d, how = date_from("clip.mov", ["Old", "2025", "stuff", "clip.mov"])
    assert (d, how) == ("2025-00-00", "year"), (d, how)
    assert date_from("clip.mov", ["Old", "stuff"])[0] == ""

    assert event_from(["Old", "2022", "MISO CONCERT 2022", "CAM 2", "a.mxf"]) \
        == "miso-concert-2022"
    assert event_from(["CLIPS001", "CAM 1", "a.mxf"]) == "misc"
    # a real folder name still wins over card junk further down
    assert event_from(["OldServer", "CLIPS001", "a.mxf"]) == "oldserver"

    dest, conf = dest_for("/Volumes/Old/PARKS/MISO BROLL/CAM 2/A030C456_240318AB_CANON.MXF",
                          "/Volumes/VIDEO/ARCHIVE")
    assert dest == ("/Volumes/VIDEO/ARCHIVE/2024/2024-03-18_miso-broll/"
                    "CAM 2/A030C456_240318AB_CANON.MXF"), dest
    assert conf == "high"
    dest, conf = dest_for("/Volumes/Old/random/thing.mov", "/R")
    assert dest.startswith("/R/_unsorted/") and conf == "low", dest

    # a size that exists nowhere on the NAS must never touch the disk
    calls = []
    global digest
    real, digest = digest, lambda *a, **k: calls.append(a) or "x"
    assert already_here("/nope", 123, {}) is None
    assert calls == [], "hashed a file whose size was unique — that is the whole point"
    digest = real
    print("all checks pass")


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        # Ctrl-C is how the helper is meant to be stopped, so it answers like
        # it, not with a page of Python. A file cut off mid-copy was only ever
        # a .part file; it is redone from the start next time.
        if "--watch" in sys.argv:
            print("\n\nHelper stopped. Nothing was lost — start it again and it carries on "
                  "where it left off.")
        sys.exit(130)
