#!/usr/bin/env python3
# Rushes — Media Management Software, by Alejandro Renteria.
# Open source: https://github.com/x0on/rushes — whoever finds this file on a computer can see what it is and who made it.
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

import argparse, errno, hashlib, json, os, platform, re, shutil, socket, subprocess, sys, threading, time, unicodedata
import urllib.parse, urllib.request
from collections import defaultdict
from pathlib import Path
try:
    # Beside this file in _rushes: saved progress for each transfer, and the
    # search updates still waiting to be accepted by Rushes.
    from transfer_state import TransferState
except ImportError:
    sys.exit("transfer_state.py is missing. It must sit beside ingest.py in _rushes.")

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
ARG_URL   = NAS_URL          # what it was started with; NAS_URL can move on from it

# Where Rushes was last found. The background service's start command is
# written once, but Rushes' address can change (a new number after a restart,
# or a name chosen in Setup); the watcher follows it, and remembers here.
# Only for the address it was started with: set up again for another Rushes,
# and this is ignored.
WHERE = HOME / "where.json"
def _where():
    try:
        w = json.loads(WHERE.read_text())
        return w if w.get("from") == ARG_URL else {"from": ARG_URL}
    except (OSError, ValueError):
        return {"from": ARG_URL}
if "--watch" in sys.argv and _where().get("current"):
    NAS_URL = _where()["current"]

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

_CHECKPOINTS = None

def checkpoints():
    """This computer's own record of what each transfer has finished, and the
    search updates still to be accepted. Local on purpose: it keeps working
    while the archive or the network is away, and survives a restart."""
    global _CHECKPOINTS
    if _CHECKPOINTS is None:
        HOME.mkdir(parents=True, exist_ok=True)
        key = hashlib.sha256(NAS_URL.encode()).hexdigest()[:16]
        _CHECKPOINTS = TransferState(HOME / ("transfer-" + key + ".sqlite"), NAS_URL)
    return _CHECKPOINTS

# ── two lanes ──────────────────────────────────────────────────────────────
# Copying (the main loop) and describing (a thread of its own) run side by
# side: one uses the network, the other this Mac's chip reading proxies. Each
# has its own Pause and its own live status; they share a few files, so writes
# to those take turns.
_lane = threading.local()          # .name == "describe" in the describing lane
_describing = threading.Event()    # set while a folder is being described
_io = threading.Lock()             # history, the done list: one writer at a time
_describe_jobs = []                # (folder, asked) — the queue's describing jobs, in order


def history(kind, source, files, byts, secs, note=""):
    """One line per run, appended, never rewritten. This is the answer to
    "where did I leave off" — the section list shows state, this shows order."""
    try:
        STATUS.mkdir(parents=True, exist_ok=True)
        event = f"{kind}\t{source}\t{files}\t{byts}"
        path = STATUS / "ingest-history.tsv"
        # The same empty result twice in a row is a retry, not an event.
        if files == 0 and path.exists():
            with open(path, "rb") as recent:
                recent.seek(max(0, path.stat().st_size - 4096))
                lines = recent.read().decode("utf-8", "replace").splitlines()
            if lines and "\t".join(lines[-1].split("\t")[1:5]) == event:
                return
        with _io, open(path, "a") as f:
            f.write(f"{time.strftime('%Y-%m-%d %H:%M')}\t{event}\t{int(secs)}\t{note}\n")
    except OSError:
        pass
    send_file("history")


def send_file(which):
    """Rushes keeps its own copy of the history and the section list: sent whole,
    whenever they change (and once at start), so nothing on the archive machine
    has to read them off the share. A send that fails is made up by the next."""
    try:
        body = (STATUS / f"ingest-{which}.tsv").read_text(errors="replace")
        data = urllib.parse.urlencode({"file": which, "body": body}).encode()
        urllib.request.urlopen(NAS_URL + "/db/status.php", data=data, timeout=15).read()
    except Exception:
        pass


_last_push = {}                    # lane -> [when, phase]

def _push(text, lane=""):
    if rushes_down():
        return      # Rushes is not answering: asking again here would not wait for the backoff
    try:
        body = urllib.parse.urlencode({"status": text, "lane": lane}).encode()
        urllib.request.urlopen(NAS_URL + "/db/status.php", data=body, timeout=5).read()
    except Exception:
        pass        # the next one says it


def status(**kw):
    # The same moment as a plain count of seconds ('ts'). The archive may be set
    # to another time zone than this computer — a QNAP often ships on Taipei
    # time — and read "15:08" as twelve hours ago. A count cannot be misread.
    text = (f"at\t{time.strftime('%Y-%m-%d %H:%M:%S')}\nts\t{int(time.time())}\n"
            + "".join(f"{k}\t{v}\n" for k, v in kw.items()))
    lane = getattr(_lane, "name", "")          # each lane has its own live status
    # Straight to Rushes, never onto the share: the runner used to copy a file
    # off VIDEO every minute; that was a share touched for nothing. A change of phase is sent at once;
    # progress at most every two seconds, without ever holding the copy up.
    phase = str(kw.get("phase", ""))
    last = _last_push.setdefault(lane, [0.0, ""])
    if phase != last[1]:
        last[:] = [time.time(), phase]; _push(text, lane)
    elif time.time() - last[0] >= 2:
        last[0] = time.time()
        threading.Thread(target=_push, args=(text, lane), daemon=True).start()


class Speed:
    """Bytes per second over the last half minute: steady enough to read,
    quick enough to show a slow file or a busy network."""
    def __init__(self):
        self.marks = [(time.time(), 0)]
    def add(self, done):
        now = time.time()
        self.marks.append((now, done))
        self.marks = [m for m in self.marks if now - m[0] <= 30] or self.marks[-1:]
    def rate(self):
        (t0, b0), (t1, b1) = self.marks[0], self.marks[-1]
        return int((b1 - b0) / (t1 - t0)) if t1 - t0 >= 1 else 0
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
    # Files copied since the list was built are candidates too, or a clip that
    # sits in two source folders would be copied twice in one night.
    for path, size in checkpoints().db.execute("SELECT path, bytes FROM arrivals"):
        by_size[size].append(path)
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


_HEARTBEAT = lambda: None     # set while copying, so a long hash still reports progress


# The copy proof's fingerprint: XXH3-128, what the film world's copy-proof
# standard (ASC MHL) and tools like Hedge and Silverstack use — very fast, and
# made for checking copies. It ships inside Rushes Helper (python-xxhash, BSD).
# Without it (an older app), BLAKE2 from Python itself: just as safe, but not
# one ASC MHL reads. Every record names the one it used.
try:
    import xxhash
    def new_fingerprint():
        return xxhash.xxh3_128(), "xxh128"
except ImportError:
    def new_fingerprint():
        return hashlib.blake2b(digest_size=16), "blake2b"


def read_back(path):
    """A file's fingerprint (see new_fingerprint), read from where it lies. Used on each new
    copy, read back from the archive: asked without the Mac's cache of what it
    has just written (F_NOCACHE, as far as macOS allows), so a bad write to the
    archive's disk cannot hide behind a good copy in memory."""
    h, _ = new_fingerprint()
    with open(path, "rb") as f:
        if sys.platform == "darwin":
            try:
                import fcntl
                fcntl.fcntl(f, 48, 1)                       # F_NOCACHE
            except (ImportError, OSError):
                pass
        for chunk in iter(lambda: f.read(8 << 20), b""):
            h.update(chunk); _HEARTBEAT()
    return h.hexdigest()


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
                for chunk in iter(lambda: f.read(8 << 20), b""):
                    h.update(chunk); _HEARTBEAT()
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
    if sp is None:
        return None                             # unreadable: never "the same"
    for c in local:
        _HEARTBEAT()
        if digest(c) == sp:                     # same size, same first and last MB
            if not PARANOID or digest(src, True) == digest(c, True):
                return c
    return None


# ───────────────────────────────── the run ──────────────────────────────────

WALK_ERRORS = []     # folders or files that could not be read while walking


def walk(source, everything=False):
    # A card is copied whole. The XML and index files beside the clips are what
    # Premiere and Resolve use to read some cameras' footage at all; dropping
    # them makes a copy that looks complete and is not.
    for root, dirs, files in os.walk(source, onerror=WALK_ERRORS.append):
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
    out, net = [], _network_mounts()
    stuck = ""
    for r in roots:
        if r.rstrip("/") in net:
            # A network share (the archive, a server to copy from): looked at
            # every ten minutes, within ten seconds, never searched for cards.
            # Looking at every share every 20 s is what kept a dying disk busy.
            seen = _netlook.get(r)
            if not seen or time.time() - seen[0] > 600:
                try:
                    seen = _netlook[r] = [time.time(), within("look " + r, 10, lambda r=r: _look(r, card=False))]
                except Stalled:
                    stuck = stuck or r                # said on the page; the last look stands meanwhile
                except OSError:
                    seen = _netlook[r] = [time.time(), None]
            if seen and seen[1]:
                out.append(seen[1])
            continue
        _looking[0] = r                       # which drive it is on, in case one stops answering
        try:
            v = _look(r)
        except OSError:
            continue                          # a drive going away mid-look
        if v: out.append(v)
    _looking[0] = ""
    _net_stuck[0] = stuck
    return out


_netlook = {}          # network share → [when it was last looked at, what was seen]
_net_stuck = [""]      # a network share that did not answer the last look

def _look(r, card=True):
    if not os.path.isdir(r):
        return None
    use = shutil.disk_usage(r)
    top = sorted(d for d in os.listdir(r)
                 if not d.startswith((".", "@", "$")) and os.path.isdir(os.path.join(r, d)))
    return {"path": r, "name": os.path.basename(r.rstrip("\\/")) or r,
            "total": use.total, "free": use.free,
            "card": card and bool(CARD_MARKS & set(top)),
            "archive": "_rushes" in top, "top": top[:200]}


def _network_mounts():
    """Mount points that are network shares, from the list of mounts (which
    does not ask the shares themselves anything)."""
    try:
        lines = subprocess.run(["mount"], capture_output=True, text=True, timeout=5).stdout.splitlines()
    except Exception:
        return set()
    net = set()
    for line in lines:
        m = re.match(r".*? on (.*?) (?:\((\w+)|type ([\w.]+))", line)
        if m and (m.group(2) or m.group(3)) in ("smbfs", "afpfs", "nfs", "nfs4", "webdav", "cifs", "smb3", "fuse.sshfs"):
            net.add(m.group(1).rstrip("/"))
    return net


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


# ── shares that drop: reconnect them without anyone having to ────────────────
# A network share that drops leaves its folder gone until someone connects it
# again in Finder. The helper remembers where each share it has seen lives,
# and asks macOS to connect it again: silently when the password is in the
# keychain. It tries every 2 minutes, then every 15, and says so each time.
SHARES = HOME / "shares.json"
_shares_seen = [0.0]

def remember_shares():
    if time.time() - _shares_seen[0] < 300:
        return
    _shares_seen[0] = time.time()
    try:
        known = json.loads(SHARES.read_text())
    except (OSError, ValueError):
        known = {}
    for path in [NAS_MOUNT] + [x.get("path", "") for x in (SETTINGS.get("sources") or [])]:
        if path and os.path.isdir(path):
            srv = server_of(path.rstrip("/"))
            if srv.startswith("//"):
                known[path.rstrip("/")] = srv
                known.pop("not a network share: " + path.rstrip("/"), None)
            else:                   # said, so it is clear why this one cannot be reconnected
                known["not a network share: " + path.rstrip("/")] = srv or "not in the list of mounted drives"
    try:
        HOME.mkdir(parents=True, exist_ok=True)
        SHARES.write_text(json.dumps(known, indent=1))
    except OSError:
        pass


def share_of(path):
    """(mount point, //server/share) a missing path belongs to, or (None, None)."""
    try:
        known = json.loads(SHARES.read_text())
    except (OSError, ValueError):
        return None, None
    for root, srv in known.items():
        if not srv.startswith("//"):
            continue
        if path == root or path.startswith(root + os.sep) or root.startswith(path + os.sep):
            return root, srv
    return None, None


def reachable(srv, timeout=3):
    """Is the server answering at all? Asked quietly, before asking macOS:
    every mount macOS cannot make puts up a "problem connecting" window, and
    a server that is down for an hour would get one every two minutes."""
    host = srv.lstrip("/").split("/")[0].rsplit("@", 1)[-1].split(":")[0]
    try:
        socket.create_connection((host, 445), timeout).close()      # 445: file sharing (SMB)
        return True
    except OSError:
        return False


def reconnect(root, srv):
    """Ask macOS to connect the share again. True when its folder is back."""
    if sys.platform != "darwin" or not srv or not reachable(srv):
        return False
    try:
        subprocess.run(["osascript", "-e", f'mount volume "smb:{srv}"'],
                       capture_output=True, timeout=90)
    except Exception:
        pass
    return os.path.isdir(root)


class Dropped:
    """One share that has gone: when, how many tries, and what to tell the page."""
    def __init__(self, path):
        self.path = path
        self.root, self.srv = share_of(path)
        self.root = self.root or path
        self.name = os.path.basename(self.root.rstrip("/")) or self.root
        self.at = time.time(); self.tries = 0; self.next = 0.0

    def try_again(self):
        """Ask macOS to connect the share again; True if that brought it back.
        A share that is there with the folder missing is not a drop: nothing
        to reconnect, and the watcher simply waits for the folder."""
        if not self.srv or os.path.isdir(self.root) or time.time() < self.next:
            return False
        if control().get("no_reconnect"):            # turned off in Manage: leave it to the person
            return False
        self.tries += 1
        print(f"{time.strftime('%H:%M:%S')}  reconnecting {self.name} ({self.srv}), try {self.tries} …")
        if reconnect(self.root, self.srv):
            return True
        self.next = time.time() + (120 if self.tries < 5 else 900)
        return False

    def note(self):
        since = time.strftime("%H:%M", time.localtime(self.at))
        if not self.srv or os.path.isdir(self.root):
            return f"cannot see {self.path} since {since} — connect it again in Finder"
        if control().get("no_reconnect"):
            return f"{self.name} dropped at {since} — reconnecting is off (Manage); connect it in Finder when the network is back"
        if self.tries >= 5:
            return (f"{self.name} dropped at {since} and does not reconnect by itself — connect it in Finder "
                    "(Go → Connect to Server) and tick “Remember this password in my keychain” so it can next time")
        return f"{self.name} dropped at {since} — reconnecting by itself (try {self.tries})"

    def back(self):
        mins = max(1, round((time.time() - self.at) / 60))
        how = "reconnected by itself" if self.tries else "connected again"
        print(f"\n{time.strftime('%H:%M:%S')}  {self.name} is back — {how} after {mins} min. Carrying on.")
        history("dropped", self.root, 0, 0, time.time() - self.at, how)


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


def replay():
    """Every record, oldest first, played forward: for each file a record
    knows, where it is NOW -> [(what, original, bytes)]. A tidy-up's "moved"
    line carries the file's origin along with it, so after any number of
    tidy-ups (and undos) each file still knows where it first came from."""
    at = {}
    for f in sorted(ORIGIN.glob("*.tsv")) if ORIGIN.exists() else []:
        for l in open(f, errors="replace"):
            p = l.rstrip("\n").split("\t")
            if len(p) < 4 or not p[1] or not p[2]:
                continue
            if p[0] == "moved":
                if p[1] in at:
                    at.setdefault(p[2], []).extend(at.pop(p[1]))
            elif p[0] in ("copied", "traced", "already"):
                at.setdefault(p[2], []).append((p[0], p[1], p[3]))
    return at


def load_origins():
    """source path -> where that exact file is in the archive, from every record.
    Checked before copying anything, so a file brought over once is never
    brought over again — whatever layout it landed in, wherever a tidy-up has
    since moved it, and however stale the archive's file list is."""
    known = {}
    for arch, rows in replay().items():
        for _, src, size in rows:
            known[src] = (arch, size)
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
    # Only a trace that got to the end counts. One stopped part-way (Ctrl-C,
    # a sleeping Mac) left files unmatched, and those would be copied again.
    for f in ORIGIN.glob("*.tsv") if ORIGIN.exists() else []:
        with open(f, "rb") as fh:
            head = fh.read(2000).decode("utf-8", "replace")
            if ("# kind\ttraced" not in head or f"# source root\t{src_root}\n" not in head
                    or f"# into\t{archive}\n" not in head):
                continue
            fh.seek(max(0, os.path.getsize(f) - 200))
            if b"\n# finished\t" in fh.read():
                return False
    return True


def trace_paused():
    """Pause from Manage stops the matching too. It only reads, so nothing is
    half-done: after Resume it carries on, skipping every copy an earlier run
    already matched (those lines are in its record and count). Only a run that
    got to the end marks the source as traced (needs_trace wants "# finished")."""
    if control().get("paused"):
        print("\n*** paused from Manage — the matching carries on from here after Resume (it only reads)")
        status(phase="paused", source="", note="paused from Manage")
        sys.exit(1)


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
        for l in (fp.read_text(errors="replace").splitlines() if fp.exists() else []):
            f = l.rstrip("\n").split("\t")
            kind = f[1] if fn.startswith("ingest-history") else f[0]
            if len(f) > col and kind == want and f[col].startswith(src_root + os.sep):
                if not any(f[col] == x or f[col].startswith(x + os.sep) for x in sections):
                    sections = [x for x in sections if not x.startswith(f[col] + os.sep)] + [f[col]]
    # The earlier copies to match: everything in the archive's copied area
    # EXCEPT the exact-copy folders, which already carry their origin in their
    # path. Counted first (it is quick), so the page can say how much is left.
    names = {s.get("label") for s in (SETTINGS.get("sources") or [])} | {name}
    copies = [p for top in sorted(os.listdir(archive)) if top not in names and not top.startswith(".")
              for p in walk(os.path.join(archive, top), everything=True)]
    print(f"{len(copies):,} earlier copies to match")

    # The originals, listed once and kept on this computer folder by folder.
    # A stop part-way (the source dropped, the Mac slept, Pause) keeps every
    # folder already read; the next run reads only the rest.
    HOME.mkdir(parents=True, exist_ok=True)
    saved = HOME / f"trace-originals-{hashlib.sha256(src_root.encode()).hexdigest()[:12]}.tsv"
    by_size = defaultdict(list); n = 0; read = set()
    try:
        if time.time() - saved.stat().st_mtime > 7 * 86400:
            saved.unlink()                      # a week old: the source may have changed, read it again
    except OSError:
        pass
    # Only whole folders count: lines after the last "# read" are a folder cut
    # off part-way, dropped here and read again.
    lines = saved.read_text(errors="replace").splitlines(keepends=True) if saved.exists() else []
    lines = lines[:1 + max((i for i, l in enumerate(lines) if l.startswith("# read\t")), default=-1)]
    saved.write_text("".join(lines))
    for l in lines:
        f = l.rstrip("\n").split("\t")
        if f[0] == "# read" and len(f) > 1:
            read.add(f[1])
        elif len(f) == 3 and f[0] == "" and f[2].isdigit():
            by_size[int(f[2])].append(f[1]); n += 1
    todo = [x for x in sections if x not in read]
    print(f"reading {len(todo)} of {len(sections)} folder(s) copied from {name}"
          + (f" — {len(read)} already read on an earlier run ({n:,} originals)" if read else "") + " …")
    with open(saved, "a") as keep:
        for k, sec in enumerate(todo):
            for p in walk(sec, everything=True):
                try:
                    size = os.path.getsize(p)
                except OSError:
                    continue
                by_size[size].append(p); n += 1
                keep.write(f"\t{p}\t{size}\n")
                if n % 2000 == 0: print(f"  {n:,} originals listed")
                if n % 50 == 0:
                    status(phase="tracing", source=src_root, step="listing", checked=n,
                           folders=len(sections), folders_read=len(read) + k, copies=len(copies))
                    trace_paused()
            keep.write(f"# read\t{sec}\n"); keep.flush()
    print(f"  {n:,} originals listed\n")

    # Copies an earlier run already matched keep their answer — it stopped
    # part-way (Pause, a drop, a sleeping Mac), but every line it wrote is in
    # its record and counts. Without this, each Pause started hours of
    # matching over from the first file.
    done = set()
    for f in ORIGIN.glob("*.tsv") if ORIGIN.exists() else []:
        with open(f, errors="replace") as fh:
            head = fh.read(2000)
            if ("# kind\ttraced" not in head or f"# source root\t{src_root}\n" not in head
                    or f"# into\t{archive}\n" not in head):
                continue
            fh.seek(0)
            for l in fh:
                p = l.rstrip("\n").split("\t")
                if len(p) > 2 and p[0] in ("traced", "untraced"): done.add(p[2])
    left = [p for p in copies if p not in done]
    if done:
        print(f"{len(copies) - len(left):,} already matched on an earlier run — {len(left):,} left")

    o = Origin("traced", src_root, src_root, name, archive)
    t0 = time.time(); i = len(copies) - len(left)
    for p in left:
            i += 1
            try: size = os.path.getsize(p)
            except OSError: continue
            match = None
            for c in by_size.get(size, []):
                if digest(c) == digest(p):
                    match = c; break
            if match: o.add("traced", match, p, size, "copied before the record existed; matched by content")
            else:     o.add("untraced", "", p, size, "no identical original found")
            if i % 20 == 0:
                trace_paused()
                status(phase="tracing", source=src_root, step="matching", checked=i, of=len(copies),
                       traced=o.n["traced"], untraced=o.n["untraced"], originals=n)
            if i % 500 == 0:
                print(f"  {i:,} checked — {o.n['traced']:,} traced, {o.n['untraced']:,} not "
                      f"({time.time() - t0:.0f}s)")
    o.f.write(f"# finished\t{time.strftime('%Y-%m-%d %H:%M')}\n")
    o.close(); save_cache()
    tn, un = o.n['traced'], o.n['untraced']
    print(f"\n{tn:,} file{'' if tn == 1 else 's'} traced to {'its' if tn == 1 else 'their'} original, "
          f"{un:,} not found on {name}.")
    print(f"record: _rushes/origin/{o.path.name}")


# ───────────────────────────── the tidy-up ─────────────────────────────────
# Moves what the copies brought into ARCHIVE onto the shelf, by the plan
# Structure shows and the admin approves. The plan says only "this source
# folder -> that shelf folder"; everything below it keeps its layout. Every
# file is moved by name on the same share (a rename, so nothing is re-copied),
# never over another file, never while its folder is still being copied, and
# every move is a line in a record, so it can be undone and relinked.

NOTE = "Where this came from.txt"

# ───────────────────────── copy proof: ASC MHL ─────────────────────────────
# The film world's standard record of "these are the exact bytes that were
# copied" (ASC MHL 2.0, by the American Society of Cinematographers): an
# `ascmhl` folder beside the footage holding one numbered file per event —
# copied, moved, checked — and a chain file that fingerprints each of those,
# so none can be edited quietly afterwards. Any MHL tool (ascmhl, Hedge,
# Silverstack, Pomfort) reads and verifies it without Rushes. Written from the
# fingerprints taken while copying: nothing is read a second time for it.
MHL = "ascmhl"
MHL_NS = "{urn:ASC:MHL:v2.0}"
MHL_CHAIN_NS = "{urn:ASC:MHL:DIRECTORY:v2.0}"
MHL_IGNORE = (".DS_Store", MHL, MHL + "/", NOTE, "*.part")


def _when(t=None):
    import datetime
    return datetime.datetime.fromtimestamp(time.time() if t is None else t).astimezone().isoformat(timespec="seconds")


def _history_above(path, stop=None):
    """The nearest folder above `path`, up to `stop`, that has an ascmhl record."""
    stop = (stop or NAS_MOUNT).rstrip(os.sep)
    d = os.path.dirname(path)
    while d == stop or d.startswith(stop + os.sep):
        if os.path.isdir(os.path.join(d, MHL)):
            return d
        if d == stop: break
        d = os.path.dirname(d)
    return None


def mhl_read(root):
    """rel path -> (xxh128, when it was taken), for every file root's record knows."""
    import xml.etree.ElementTree as ET
    known = {}
    for g in sorted(Path(root, MHL).glob("*.mhl")):
        try: t = ET.parse(g)
        except (OSError, ET.ParseError): continue
        for h in t.iter(MHL_NS + "hash"):
            p, x = h.find(MHL_NS + "path"), h.find(MHL_NS + "xxh128")
            if p is None or x is None or not p.text or x.get("action") == "failed":
                continue
            prev = h.findtext(MHL_NS + "previousPath")
            if prev: known.pop(prev, None)
            known[p.text] = (x.text, x.get("hashdate") or "")
    return known


def mhl_write(root, files, comment, process="in-place"):
    """Add one generation to root's record. files: (path, size, xxh128, action,
    when taken — "" for now). Returns the new file's path, or None."""
    import xml.etree.ElementTree as ET
    from xml.sax.saxutils import escape, quoteattr
    if not files: return None
    d = os.path.join(root, MHL)
    os.makedirs(d, exist_ok=True)
    chain = os.path.join(d, "ascmhl_chain.xml")
    links = []
    if os.path.exists(chain):     # never rewritten from a chain it could not read: that would lose links
        for e in ET.parse(chain).getroot():
            links.append((int(e.get("sequencenr")), e.findtext(MHL_CHAIN_NS + "path"), e.findtext(MHL_CHAIN_NS + "c4")))
    seq = max([n for n, _, _ in links] + [int(f[:4]) for f in os.listdir(d) if f[:4].isdigit()] + [0]) + 1
    name = f"{seq:04d}_{os.path.basename(root.rstrip(os.sep))}_{time.strftime('%Y-%m-%d_%H%M%SZ', time.gmtime())}.mhl"
    now, rows = _when(), []
    for path, size, hexd, action, taken in sorted(files):
        try: mod = f' lastmodificationdate="{_when(os.stat(path).st_mtime)}"'
        except OSError: mod = ""
        rel = os.path.relpath(path, root).replace(os.sep, "/")
        rows.append(f'    <hash>\n      <path size="{int(size)}"{mod}>{escape(rel)}</path>\n'
                    f'      <xxh128 action="{action}" hashdate="{taken or now}">{hexd}</xxh128>\n    </hash>\n')
    text = ('<?xml version="1.0" encoding="UTF-8"?>\n<hashlist version="2.0" xmlns="urn:ASC:MHL:v2.0">\n'
            f'  <creatorinfo>\n    <creationdate>{now}</creationdate>\n    <hostname>{escape(platform.node())}</hostname>\n'
            f'    <tool version={quoteattr(VERSION or "1")}>Rushes (github.com/x0on/rushes)</tool>\n'
            f'    <comment>{escape(comment)}</comment>\n  </creatorinfo>\n'
            f'  <processinfo>\n    <process>{process}</process>\n    <ignore>\n'
            + "".join(f"      <pattern>{escape(p)}</pattern>\n" for p in MHL_IGNORE)
            + '    </ignore>\n  </processinfo>\n  <hashes>\n' + "".join(rows) + '  </hashes>\n</hashlist>\n')
    gen = os.path.join(d, name)
    with open(gen + ".part", "w", encoding="utf-8") as f:
        f.write(text); f.flush(); os.fsync(f.fileno())
    os.replace(gen + ".part", gen)
    abc = "123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz"       # C4, the chain's fingerprint
    n, c4 = int(hashlib.sha512(text.encode("utf-8")).hexdigest(), 16), ""
    while n: n, r = divmod(n, 58); c4 = abc[r] + c4
    links.append((seq, name, "c4" + c4.rjust(88, "1")))
    with open(chain + ".part", "w", encoding="utf-8") as f:
        f.write('<?xml version="1.0" encoding="UTF-8"?>\n<ascmhldirectory xmlns="urn:ASC:MHL:DIRECTORY:v2.0">\n'
                + "".join(f'  <hashlist sequencenr="{s}">\n    <path>{escape(p)}</path>\n    <c4>{c}</c4>\n  </hashlist>\n'
                          for s, p, c in links) + '</ascmhldirectory>\n')
        f.flush(); os.fsync(f.fileno())
    os.replace(chain + ".part", chain)
    try:                              # the checker's list of every record, by its place on the archive
        rel = os.path.relpath(root, NAS_MOUNT)
        roots = STATUS / "proof-roots.txt"
        if rel not in (roots.read_text(errors="replace").splitlines() if roots.exists() else []):
            with _io, open(roots, "a") as f:
                f.write(rel + "\n")
    except (OSError, ValueError):
        pass
    return gen


def mhl_copied(top, files, o, source):
    """A copy run's proof: one generation per record the copied files belong to."""
    groups = defaultdict(list)
    for dest, size, hexd in files:
        groups[_history_above(dest, top) or top].append((dest, size, hexd, "original", ""))
    for root, fs in groups.items():
        try:
            g = mhl_write(root, fs, f"Copied by Rushes from {source}. Each file was read back from the "
                                    "archive and matched its original before it was put in place.", "transfer")
            o.add("proof", g, "", len(fs), "ASC MHL record of this copy")
        except (OSError, ValueError) as e:
            print(f"  ! could not write the copy proof in {root} ({e}) — each file's fingerprint is still in the record")


def mhl_follow(pairs, o, why):
    """Files that moved (a tidy-up, its undo) take their fingerprints along: a
    generation in the record where they landed. A record left with no footage
    is kept in _rushes/ascmhl-moved, never thrown away."""
    known, groups = {}, defaultdict(list)
    for old, new in pairs:
        r = _history_above(old)
        if not r: continue
        if r not in known: known[r] = mhl_read(r)
        rel = os.path.relpath(old, r)
        h = known[r].get(rel.replace(os.sep, "/"))
        if not h: continue
        # The record goes to the same folder in its new place: the part of the
        # path the move kept tells where that is.
        a, b = rel.split(os.sep), new.split(os.sep)
        k = 0
        while k < len(a) and k < len(b) - 1 and a[-1 - k] == b[-1 - k]: k += 1
        nr = os.sep.join(b[:len(b) - k]) if k else os.path.dirname(new)
        nr = _history_above(new, nr) or nr
        try: size = os.path.getsize(new)
        except OSError: continue
        groups[(r, nr)].append((new, size, h[0], "original", h[1]))
    for (r, nr), fs in groups.items():
        try:
            g = mhl_write(nr, fs, f"Moved here by Rushes ({why}) from {r}. Fingerprints as first taken; "
                                  "the earlier record is in that folder or in _rushes/ascmhl-moved.")
            o.add("proof", g, "", len(fs), "ASC MHL record follows the files")
        except (OSError, ValueError) as e:
            print(f"  ! could not write the proof in {nr} ({e})")
    for r in known:
        left = False
        for dp, dn, fn in os.walk(r):
            if MHL in dn: dn.remove(MHL)
            if any(f not in (NOTE, ".DS_Store") for f in fn):
                left = True; break
        if left: continue
        to = os.path.join(NAS_MOUNT, "_rushes", "ascmhl-moved", os.path.relpath(r, NAS_MOUNT), time.strftime("%Y%m%d-%H%M%S"))
        try:
            os.makedirs(os.path.dirname(to), exist_ok=True)
            os.rename(os.path.join(r, MHL), to)
            o.add("proof kept", os.path.join(r, MHL), to, 0, "its footage all moved; the record is kept here")
        except OSError as e:
            print(f"  ! left the record in {r} ({e})")


# ───────────── checking: older copies once, then the archive for ever ──────
# Work for when there is nothing to copy, on the copying lane, one folder at a
# time, stopping between files the moment a copy is asked for or Pause is
# pressed, and carrying on from the same file next time:
#  1. Once: files copied before every copy was read back had only their size
#     checked. Each is read again beside its original; a match puts its
#     fingerprint into the folder's ASC MHL record, a difference is reported.
#  2. Always: as the backup tools restic and Borg do, every recorded file is
#     read again now and then (each folder every 90 days, a setting) and
#     compared with its fingerprint, so a disk quietly damaging a file is
#     found while the original or a backup can still replace it. Each check is
#     a generation in the record too.
# ponytail: reads over the network from this computer; in the server package
# the same code runs on the archive machine, reading its own disks.

def _proof_state():                       # the checker's place in its work, on this computer
    try: return json.loads((HOME / "proof.json").read_text())
    except (OSError, ValueError): return {}


def _proof_save(st):
    tmp = HOME / "proof.json.part"
    tmp.write_text(json.dumps(st)); os.replace(tmp, HOME / "proof.json")


def _older_todo():
    """record folder -> [[copy, original], …]: what the records put in the
    archive that no ASC MHL record knows yet — copied before copies were read back."""
    todo, under, seen = defaultdict(list), {}, {}
    for arch, rows in replay().items():
        d = os.path.dirname(arch)
        if d not in under: under[d] = _history_above(arch)
        r = under[d]
        if r:
            if r not in seen: seen[r] = mhl_read(r)
            if os.path.relpath(arch, r).replace(os.sep, "/") in seen[r]: continue
        # ponytail: a folder no record covers gets its own record; a copied
        # folder's top would be tidier, but the records do not keep it after a tidy-up
        src = next((s for w, s, _ in rows if w != "already"), rows[0][1])
        todo[r or d].append([arch, src])
    return dict(todo)


def check_due():
    """Is there checking to do? Answered from this computer's own notes, so a
    helper with nothing to do never looks at the archive to find that out."""
    if new_fingerprint()[1] != "xxh128":
        return False
    st = _proof_state(); o = st.get("older", {})
    return ("todo" not in o or bool(o["todo"]) or time.time() - o.get("built", 0) > 7 * 86400
            or time.time() - st.get("copies", {}).get("done", 0) > 7 * 86400
            or bool(st.get("scrub")) or time.time() >= st.get("quiet_until", 0))


def check_some(budget=300):
    """Some of the checking, for up to `budget` seconds. False: nothing to do."""
    if new_fingerprint()[1] != "xxh128":
        return False                       # an older app without XXH3: no record it could write
    global _HEARTBEAT
    st, until, now_ = _proof_state(), time.time() + budget, {}
    stop = lambda: time.time() > until or control().get("paused") or control().get("check_paused")
    beat = [0.0]
    def hb():                              # a long file still reports it is alive
        if time.time() - beat[0] > 60: beat[0] = time.time(); status(**now_)
    _HEARTBEAT = hb
    try:
        # 1 · older copies, against their originals
        o = st.setdefault("older", {})
        if "todo" not in o or (not o["todo"] and time.time() - o.get("built", 0) > 7 * 86400):
            status(phase="proving", step="older", source="", note="listing the copies made before copies were read back")
            o.update(todo=_older_todo(), built=time.time(), got={}); _proof_save(st)
        while o["todo"]:
            root = next(iter(o["todo"]))
            items, res = o["todo"][root], o["got"].setdefault(root, {"ok": [], "bad": [], "away": 0, "t0": time.time()})
            while items:
                if stop(): _proof_save(st); return True
                arch, src = items[0]
                now_ = dict(phase="proving", step="older", source=root, file=os.path.basename(arch),
                            n=len(res["ok"]) + len(res["bad"]) + res["away"] + 1, of=len(res["ok"]) + len(res["bad"]) + res["away"] + len(items),
                            ok=len(res["ok"]), bad=len(res["bad"]), missing=res["away"])
                status(**now_); beat[0] = time.time()
                try:
                    if not os.path.isfile(arch):
                        res["bad"].append([arch, src, "not in the archive any more"])
                    elif not os.path.isfile(src):
                        res["away"] += 1           # original not reachable now: tried again in a week
                    elif os.path.getsize(src) != os.path.getsize(arch):
                        res["bad"].append([arch, src, "a different size from its original"])
                    else:
                        mine = read_back(arch)
                        if mine == read_back(src): res["ok"].append([arch, os.path.getsize(arch), mine])
                        else: res["bad"].append([arch, src, "different bytes from its original"])
                except OSError:
                    if not os.path.isdir(STATUS): _proof_save(st); return True   # the archive went away: later
                    res["away"] += 1
                items.pop(0); _proof_save(st)
            secs = time.time() - res["t0"]
            if res["ok"]:
                try:
                    mhl_write(root, [(p, s, h, "original", "") for p, s, h in res["ok"]],
                              "Checked by Rushes: copied before copies were read back, now read again from the "
                              "archive and matched byte for byte with the original.")
                except (OSError, ValueError) as e:
                    print(f"  ! could not write the proof in {root} ({e})")
            if res["bad"]:
                rec = Origin("check", root, root, "archive", root)
                for arch, src, why in res["bad"]: rec.add("differs", src, arch, 0, why)
                rec.close()
            note = ", ".join(x for x in (f"{len(res['bad'])} differ ({os.path.basename(res['bad'][0][0])}{' …' if len(res['bad']) > 1 else ''})" if res["bad"] else "",
                                          f"{res['away']} originals not reachable" if res["away"] else "") if x) or "all match their originals"
            history("proven", root, len(res["ok"]), sum(s for _, s, _ in res["ok"]), secs, note)
            print(f"{time.strftime('%H:%M:%S')}  older copies in {root}: {len(res['ok'])} proven — {note}")
            del o["todo"][root]; o["got"].pop(root, None); _proof_save(st)

        # 2 · how many copies (git-annex's idea): once a week, is the original
        # each file came from still there, at the same size? Only looking at
        # names and sizes, so it is quick. A source not connected now is not
        # looked at: its last answer stands, rather than a false "gone".
        cc = st.setdefault("copies", {})
        if time.time() - cc.get("done", 0) > 7 * 86400:
            at = replay()
            todo = sorted(p for p in at if p > cc.get("pos", ""))
            up, total = {}, len(at)
            while True:
                chunk, todo = todo[:2000], todo[2000:]
                seen = {}
                for arch in chunk:
                    for _, src, size in at[arch]:
                        root, name = source_root(src)
                        if root not in up: up[root] = os.path.isdir(root)
                        if not up[root]: continue
                        try: ok = os.path.getsize(src) == int(size or -1)
                        except (OSError, ValueError): ok = False
                        seen[(arch, name)] = seen.get((arch, name)) or ok
                now_ = dict(phase="proving", step="copies", source="", n=total - len(todo), of=total, ok=sum(seen.values()),
                            bad=len(seen) - sum(seen.values()), missing=0)
                status(**now_)
                body = urllib.parse.urlencode({"copies": "\n".join(f"{a_}\t{n}\t{int(v)}" for (a_, n), v in seen.items()),
                                               "done": "" if todo else "1"}).encode()
                try:
                    with urllib.request.urlopen(NAS_URL + "/db/copies.php", data=body, timeout=120) as r: r.read()
                except Exception:
                    return True                        # Rushes did not answer: the same files again next time
                if not todo: break
                cc["pos"] = chunk[-1]; _proof_save(st)
                if stop(): return True
            history("counted", "copies", total, 0, 0, "the original each file came from, looked for where it was")
            cc.update(done=time.time(), pos=""); _proof_save(st)

        # 3 · the archive, against its fingerprints
        days = float(setting("proof.check_every_days") or 90)
        last, cur = st.setdefault("checked", {}), st.get("scrub")
        if not cur:
            try: roots = (STATUS / "proof-roots.txt").read_text(errors="replace").splitlines()
            except OSError: roots = []
            due = [r for r in dict.fromkeys(roots) if r and time.time() - last.get(r, 0) > days * 86400
                   and os.path.isdir(os.path.join(NAS_MOUNT, r, MHL))]
            if not due:
                st["quiet_until"] = time.time() + 6 * 3600     # rule 1: not looked for again for six hours
                _proof_save(st); return False
            cur = st["scrub"] = {"root": min(due, key=lambda r: last.get(r, 0)), "done": [], "missing": [], "tries": {}, "t0": time.time()}
        root = os.path.join(NAS_MOUNT, cur["root"])
        known = mhl_read(root)
        seen = {d[0] for d in cur["done"]} | set(cur["missing"])
        for rel in sorted(known):
            if rel in seen: continue
            if stop(): _proof_save(st); return True
            path = os.path.join(root, rel)
            now_ = dict(phase="proving", step="archive", source=root, file=os.path.basename(rel), n=len(seen) + 1, of=len(known),
                        ok=sum(d[3] == "verified" for d in cur["done"]), bad=sum(d[3] != "verified" for d in cur["done"]), missing=len(cur["missing"]))
            status(**now_); beat[0] = time.time()
            try:
                if not os.path.isfile(path):
                    if not os.path.isdir(STATUS): _proof_save(st); return True
                    cur["missing"].append(rel)
                else:
                    h = read_back(path)
                    cur["done"].append([rel, os.path.getsize(path), h, "verified" if h == known[rel][0] else "failed"])
            except OSError as e:
                if not os.path.isdir(STATUS): _proof_save(st); return True
                cur["tries"][rel] = cur["tries"].get(rel, 0) + 1
                if cur["tries"][rel] < 3: _proof_save(st); return True     # a blip: that file again next time
                cur["done"].append([rel, 0, "", f"could not be read ({e.strerror or e})"])
            seen.add(rel); _proof_save(st)
        read = [d for d in cur["done"] if d[2]]
        try:
            mhl_write(root, [(os.path.join(root, r), s, h, a, "") for r, s, h, a in read],
                      "Checked by Rushes: every file read again from the archive and compared with its fingerprint.")
        except (OSError, ValueError) as e:
            print(f"  ! could not write the check into {root} ({e})")
        bad = [d for d in cur["done"] if d[3] != "verified"]
        if bad or cur["missing"]:
            rec = Origin("check", root, root, "archive", root)
            for r, _, _, a in bad: rec.add("damaged" if a == "failed" else "unreadable", "", os.path.join(root, r), 0, a)
            for r in cur["missing"]: rec.add("missing", "", os.path.join(root, r), 0, "recorded here, not found")
            rec.close()
        note = ", ".join(x for x in (f"{len(bad)} differ from their fingerprint ({os.path.basename(bad[0][0])}{' …' if len(bad) > 1 else ''})" if bad else "",
                                      f"{len(cur['missing'])} missing" if cur["missing"] else "") if x) or "all match"
        history("checked", root, len(read), sum(d[1] for d in read), time.time() - cur["t0"], note)
        print(f"{time.strftime('%H:%M:%S')}  checked {root}: {len(read)} files — {note}")
        last[cur["root"]] = time.time(); st.pop("scrub"); _proof_save(st)
        return True
    finally:
        _HEARTBEAT = lambda: None


def _fetch(url):
    with urllib.request.urlopen(url, timeout=30) as r:
        return r.read().decode("utf-8", "replace")


def _shelf():
    return os.path.join(NAS_MOUNT, (setting("organise.shelves") or "Library").strip("/\\"))


def _mark_done(key):
    HOME.mkdir(exist_ok=True)
    with _io, open(DONE, "a") as f:
        f.write(key + "\n")


def move_proxy(old, new, o):
    """A proxy follows its original. Proxies mirror the archive's paths under
    PROXIES, so when a file moves, its proxy moves to the matching place — and
    search, describing and Premiere keep finding it. Never over another file."""
    root = NAS_MOUNT.rstrip("/")
    if not (old.startswith(root + os.sep) and new.startswith(root + os.sep)):
        return
    pr = os.path.join(root, "PROXIES")
    a = os.path.splitext(os.path.join(pr, old[len(root) + 1:]))[0] + ".mp4"
    b = os.path.splitext(os.path.join(pr, new[len(root) + 1:]))[0] + ".mp4"
    if not os.path.isfile(a) or os.path.lexists(b):
        return
    try:
        os.makedirs(os.path.dirname(b), exist_ok=True)
        os.rename(a, b)
        o.add("proxy moved", a, b, os.path.getsize(b), "follows its original")
    except OSError as e:
        o.add("proxy not moved", a, b, 0, str(e))


def clear_out(paths, stop, o, examples=None):
    """Remove the folders a tidy-up emptied, deepest first — never `stop` or
    anything above it. A folder's note goes with its files; Finder's
    .DS_Store is the only thing ever thrown away."""
    stop = stop.rstrip("/\\")
    dirs = set()
    for p in paths:
        d = os.path.dirname(p)
        while d.startswith(stop + os.sep) and d not in dirs:
            dirs.add(d); d = os.path.dirname(d)
    gone = 0
    for d in sorted(dirs, key=len, reverse=True):
        try: left = [n for n in os.listdir(d) if n != ".DS_Store"]
        except OSError: continue
        if left == [NOTE] and examples and d in examples:
            old, new = examples[d]
            tail = old[len(d):]
            if new.endswith(tail):              # the folder's twin on the shelf
                here, there = os.path.join(d, NOTE), os.path.join(new[:len(new) - len(tail)], NOTE)
                try:
                    if os.path.exists(there):   # two notes: keep both, in one file
                        with open(here, errors="replace") as a, open(there, "a") as b:
                            b.write(a.read())
                        os.remove(here)
                        o.add("note", here, there, 0, "its words were added to the note already there")
                    else:
                        size = os.path.getsize(here)
                        os.rename(here, there)
                        o.add("moved", here, there, size, "the folder's note")
                    left = []
                except OSError:
                    pass
        if left:
            continue
        try:
            if os.path.exists(os.path.join(d, ".DS_Store")):
                os.remove(os.path.join(d, ".DS_Store"))
            os.rmdir(d); gone += 1
        except OSError:
            pass
    return gone


def tell_moved(pairs):
    """Search and Pulls follow the files to where they are now."""
    n = 0
    for i in range(0, len(pairs), 2000):
        rows = "\n".join(f"{a}\t{b}" for a, b in pairs[i:i + 2000] if "\t" not in a + b and "\n" not in a + b)
        try:
            body = urllib.parse.urlencode({"moves": rows}).encode()
            with urllib.request.urlopen(NAS_URL + "/db/moved.php", data=body, timeout=120) as r:
                n += json.loads(r.read().decode("utf-8", "replace")).get("updated", 0)
        except Exception as e:
            print(f"  ! could not tell search about the moves ({e})")
            print("    The files are moved and recorded. Manage → Jobs and tools → Rebuild search settles it.")
            return
    print(f"  search and pulls updated: {n:,} file{'s' if n != 1 else ''} ✓")


def tidy(plan_id):
    t0 = time.time()
    shelf = _shelf()
    area = ARCHIVE.rstrip("/\\") + os.sep
    _mark_done(f"tidy {plan_id}")               # asked once: a refusal is not retried every 20 s
    try:
        body = _fetch(f"{NAS_URL}/tidy-{plan_id}.tsv")
        queue = _fetch(QUEUE_URL)
    except Exception as e:
        print(f"Cannot read the tidy-up plan from Rushes ({e}). Nothing moved — approve it again.")
        history("refused", f"tidy {plan_id}", 0, 0, 0, "could not read the plan")
        return
    maps = []
    for l in body.splitlines():
        f = l.split("\t")
        if len(f) == 3 and f[0] == "map" and f[1] and f[2]:
            to = f[2].rstrip("/\\")
            # The one boundary that matters: nothing is moved anywhere but the shelf.
            if not to.startswith(shelf + os.sep) or ".." in to.split(os.sep):
                print(f"  ! refused a line of the plan: {to} is not on the shelf ({shelf})"); continue
            maps.append((f[1].rstrip("/\\"), to))
    maps.sort(key=lambda m: -len(m[0]))          # the most specific line wins

    done = {l.rstrip("\n") for l in open(DONE) if l.strip()} if DONE.exists() else set()
    busy = [f[1].rstrip("/\\") for f in (l.split("\t") for l in queue.splitlines())
            if len(f) >= 2 and f[0] == "copy" and f[1] not in done]
    inside = lambda p, d: p == d or p.startswith(d + os.sep)

    print(f"reading every record to see what came from where …")
    work = []
    for arch, rows in replay().items():
        if not arch.startswith(area):
            continue                             # on the shelf already, or never in ARCHIVE
        src = next((s for w, s, _ in rows if w != "already"), rows[0][1])
        for frm, to in maps:
            if inside(src, frm):
                work.append((arch, to + src[len(frm):], src, int(rows[0][2] or 0))); break
    print(f"{len(work):,} files to move, by {len(maps)} line{'s' if len(maps) != 1 else ''} of the plan\n")

    o = Origin("tidy", f"tidy-up plan {plan_id}", ARCHIVE, "archive", shelf)
    status(phase="tidying", source=plan_id, copied=0, of=len(work))
    moved, examples, mb = [], {}, 0
    for i, (old, new, src, size) in enumerate(work, 1):
        if any(inside(src, b) for b in busy):
            o.add("skipped", old, new, size, "its folder is still being copied — the next tidy-up takes it")
        elif not os.path.isfile(old):
            o.add("skipped", old, new, size, "not there any more")
        elif os.path.lexists(new):
            o.add("skipped", old, new, size, "a file of that name is already there — left where it was")
        else:
            try:
                os.makedirs(os.path.dirname(new), exist_ok=True)
                os.rename(old, new)
                o.add("moved", old, new, size, src)
                move_proxy(old, new, o)
                moved.append((old, new)); mb += size
                d = os.path.dirname(old)
                while d.startswith(area) and d not in examples:
                    examples[d] = (old, new); d = os.path.dirname(d)
            except OSError as e:
                o.add("failed", old, new, size, str(e))
        if i % 500 == 0:
            print(f"  {i:,} of {len(work):,} — {len(moved):,} moved")
            status(phase="tidying", source=plan_id, copied=len(moved), of=len(work))
    mhl_follow(moved, o, f"tidy-up {plan_id}")
    rm = clear_out([a for a, _ in moved], ARCHIVE, o, examples)
    o.close()
    left = o.n["skipped"] + o.n["failed"]
    print(f"\nmoved {len(moved):,} files onto the shelf, {left:,} left where they were"
          + (f" ({o.n['failed']} could not be moved)" if o.n["failed"] else "")
          + f", {rm:,} emptied folders removed")
    print(f"  every move: _rushes/origin/{o.path.name}")
    if moved:
        tell_moved(moved)
    history("tidied", f"tidy {plan_id}", len(moved), mb, time.time() - t0,
            f"{left} left where they were" if left else "")
    status(phase="done", source=f"tidy-up {plan_id}", copied=len(moved), of=len(work), failed=o.n["failed"])


def untidy(name):
    """Put back exactly what one tidy-up moved, newest move first."""
    t0 = time.time()
    _mark_done(f"untidy {name}")
    rec = ORIGIN / name
    if os.sep in name or "/" in name or not name.endswith(" tidy.tsv") or not rec.is_file():
        print(f"There is no tidy-up record called {name}. Nothing moved.")
        history("refused", f"untidy {name}", 0, 0, 0, "no such record"); return
    rows = []
    for l in open(rec, errors="replace"):
        p = l.rstrip("\n").split("\t")
        if len(p) >= 4 and p[0] == "moved" and p[1] and p[2]:
            rows.append((p[1], p[2], int(p[3] or 0)))
    o = Origin("untidy", f"undo of {name}", ARCHIVE, "archive", ARCHIVE)
    status(phase="tidying", source=f"undo {name}", copied=0, of=len(rows))
    back, mb = [], 0
    for old, new, size in reversed(rows):
        if not os.path.isfile(new):
            o.add("skipped", new, old, size, "not where the tidy-up put it any more")
        elif os.path.lexists(old):
            o.add("skipped", new, old, size, "something is in its old place already")
        else:
            try:
                os.makedirs(os.path.dirname(old), exist_ok=True)
                os.rename(new, old)
                o.add("moved", new, old, size, "undo"); back.append((new, old)); mb += size
                move_proxy(new, old, o)
            except OSError as e:
                o.add("failed", new, old, size, str(e))
    mhl_follow(back, o, f"undo of {name}")
    # Empty folders the tidy-up made on the shelf go; the department folders stay.
    shelf = _shelf()
    for dept in {os.path.join(shelf, n[len(shelf) + 1:].split(os.sep)[0]) for n, _ in back if n.startswith(shelf + os.sep)}:
        clear_out([n for n, _ in back if n.startswith(dept + os.sep)], dept, o)
    o.close()
    print(f"put back {len(back):,} files, {o.n['skipped'] + o.n['failed']:,} could not be")
    print(f"  record: _rushes/origin/{o.path.name}")
    if back:
        tell_moved(back)
    history("untidied", f"untidy {name}", len(back), mb, time.time() - t0, "")
    status(phase="done", source=f"undo {name}", copied=len(back), of=len(rows))


def tell_search(paths):
    """Make what landed searchable. Queued on this computer first and sent in
    batches; only what Rushes accepts leaves the queue, so a dropped network
    or a restart loses nothing — the watcher keeps retrying."""
    cp = checkpoints()
    for p in paths:
        if "\t" in p or "\n" in p: continue
        try: cp.landed(p, os.path.getsize(p))
        except OSError: pass
    cp.flush(force=True)

_looked = [[], 0.0]        # the latest look at what is plugged in here, and when
_looking = [""]            # the drive being looked at right now


def look_forever(every=20):
    """What is plugged in, looked at over and over in a thread of its own. A
    network drive that stops answering can hold a look up for minutes (the
    system waits on it); only this thread waits, and the reports carry on."""
    ok = lambda x: "\t" not in x and "\n" not in x
    while True:
        if control().get("paused"):
            # Paused is paused: no drive is looked at (a network share included).
            # The last look stands; a card plugged in now shows after Resume.
            _looked[1] = time.time()
            time.sleep(every); continue
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
            _looked[:] = [lines, time.time()]
        except Exception:
            pass
        time.sleep(every)


def report_forever(every=20):
    """Tell the archive what is plugged in here, for as long as this runs —
    which is also how Rushes knows this helper is alive. Its own thread, because
    a four-hour copy must not hide a card plugged in during it; the looking has
    a thread of its own too, so a drive that stops answering never silences it."""
    failing = 0            # reports in a row that did not get there; said once
    late = False
    threading.Thread(target=look_forever, daemon=True).start()
    for _ in range(20):                    # the first look, before the first report (up to 10 s)
        if _looked[1]: break
        time.sleep(0.5)
    while True:
        try:
            lines, at = _looked
            if at and time.time() - at > 120 and not late:
                print(f"{time.strftime('%H:%M:%S')}  ! {_looking[0] or 'a connected drive'} is not answering — "
                      "cards plugged in now may not show in Ingest until it does. Copies carry on.")
            late = bool(at) and time.time() - at > 120
            py, model, speech = analysis_tools()
            stuck = _net_stuck[0] or (_looking[0] if late or (not at and _looking[0]) else "")
            body = urllib.parse.urlencode({"volumes": "\n".join(lines), "os": sys.platform,
                                           "an": ("ready" if py else "missing") + "\t" + os.path.basename(model.rstrip("/")) + "\t" + (speech or ""),
                                           "ver": VERSION, "how": "service" if "--service" in sys.argv else "window",
                                           "host": platform.node(), "stuck": stuck}).encode()
            urllib.request.urlopen(NAS_URL + "/db/report.php", data=body, timeout=15).read()
            if failing:
                print(f"{time.strftime('%H:%M:%S')}  telling Rushes what is plugged in again ✓")
                failing = 0
        except Exception as e:
            # Never stops a copy — but never silent either. Without these
            # reports Ingest and Setup cannot see any drive plugged in here.
            if not failing:
                print(f"{time.strftime('%H:%M:%S')}  ! cannot tell Rushes what is plugged in ({e})")
                print("    Copies carry on. Cards will not show up in Ingest until this clears.")
            failing += 1
        time.sleep(max(every, backoff(failing)) if failing else every)      # rule 4: asked less and less


def sections(roots, fresh=False):
    """List the folders inside each root, with sizes, and REMEMBER them.

    The list accumulates: running this on another department adds to it rather
    than replacing it, so you can gather candidates from all over the old
    server and then pick a night's worth out of the whole pile.
    """
    done = set()
    if DONE.exists():
        done = {l.rstrip("\n") for l in open(DONE) if l.strip()}

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
    send_file("sections")

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
DENIED = ("macOS is not letting the helper open the archive. Turn on Rushes Helper in Full Disk Access: "
          "open Rushes Helper from Applications and it walks you through it."
          if os.environ.get("RUSHES_APP") else
          "macOS is not letting the helper open the archive. Give python3 Full Disk Access: "
          "System Settings → Privacy & Security → Full Disk Access (Setup → 04 Helper shows how).")


# ── what Manage asks of the helper: pause, "try again now", folders to skip ──
_control = [0.0, {}, 0]     # when to ask next, the last answer, failures in a row
# Rule 4 (RISKS.md): asking again after a failure waits longer each time —
# 20 s, 1 min, 5 min, then every 15 min. Asking a web page that is not there
# costs nothing and harms nothing, so it never stops for good: a laptop that
# leaves the office and comes back carries on by itself.
BACKOFF = (20, 60, 300, 900)

def backoff(n):
    return BACKOFF[min(max(n, 1), len(BACKOFF)) - 1]

def rushes_down():
    return _control[2] >= 2

def control():
    """Rushes' buttons for this helper, asked for at most every 5 seconds. If
    Rushes cannot be reached, the last answer stands: never stop over this."""
    if time.time() >= _control[0]:
        try:
            with urllib.request.urlopen(NAS_URL + "/db/helper.php?control", timeout=5) as r:
                _control[1] = json.loads(r.read().decode("utf-8", "replace")) or {}
            _control[0], _control[2] = time.time() + 5, 0
        except Exception:
            _control[2] += 1
            _control[0] = time.time() + backoff(_control[2])
    return _control[1]


# ── a share that stops answering: walked away from, then stopped (RISKS.md) ──
# A disk that is dying can hold any read of its share for minutes, or for good,
# and a process waiting on it cannot even be killed. So the quick questions
# ("is the archive there?") are asked in a thread of their own and walked away
# from after a time limit (rule 2); a question still stuck is not asked again
# until it comes back (rule 3); three in a row and the helper stops touching
# the shares at all until a person presses Try again (rule 4), and says so (5).
class Stalled(Exception):
    pass

_asking = {}                # what is being asked → its thread, while it has not come back
_stalls = [0]

def _stopped_file():
    return HOME / "stopped.txt"

def within(key, seconds, fn):
    t = _asking.get(key)
    if t and t.is_alive():
        raise Stalled(key)                       # the last one never came back: not again
    out = {}
    def ask():
        try: out["v"] = fn()
        except BaseException as e: out["e"] = e
    t = threading.Thread(target=ask, daemon=True); t.start(); t.join(seconds)
    if t.is_alive():
        _asking[key] = t
        raise Stalled(key)
    _asking.pop(key, None)
    if "e" in out:
        raise out["e"]
    return out["v"]

def stall(what):
    _stalls[0] += 1
    print(f"{time.strftime('%H:%M:%S')}  ! {what} did not answer within 30 s ({_stalls[0]} in a row) — walked away from it")
    if _stalls[0] >= 3:
        why = (f"{what} stopped answering at {time.strftime('%H:%M')} — the helper touches no share "
               "until you press Try again (Rushes Helper, or Try again now in Manage)")
        try:
            HOME.mkdir(parents=True, exist_ok=True)
            _stopped_file().write_text(f"{control().get('nudge', 0)}\n{why}\n")
        except OSError:
            pass
        print(f"\n*** STOPPED: {why}")

def unstalled():
    _stalls[0] = 0

def stopped():
    """Why the helper stopped, or "" when it has not. Try again in the Rushes
    Helper window removes the file; Try again now in Manage changes the nudge."""
    try:
        nudge, _, why = _stopped_file().read_text().partition("\n")
    except OSError:
        if _stalls[0] >= 3: _stalls[0] = 0      # Try again in the window: counted from nothing
        return ""
    if str(control().get("nudge", 0)) != nudge.strip():
        try: _stopped_file().unlink()
        except OSError: pass
        _stalls[0] = 0
        print(f"\n{time.strftime('%H:%M:%S')}  Try again pressed — carrying on")
        return ""
    return why.strip()

def _archive_here():
    """"ok", "gone" (not mounted) or "denied" (macOS keeps this program out)."""
    if not os.path.isdir(STATUS):
        return "gone"
    try:
        os.listdir(STATUS); open(STATUS / "ingest-sections.tsv", "rb").close()
    except FileNotFoundError:
        pass
    except PermissionError:
        return "denied"
    return "ok"


def wait(seconds):
    """Sleep, but wake at once for "Try again now", Pause or Resume."""
    c = control(); start = (c.get("nudge", 0), c.get("paused", False))
    end = time.time() + seconds
    while time.time() < end:
        time.sleep(min(2, max(0.0, end - time.time())))
        c = control()
        if (c.get("nudge", 0), c.get("paused", False)) != start:
            return


def answers(url):
    """Is Rushes at this address? (Not just anything that answers.)"""
    try:
        with urllib.request.urlopen(url + "/db/helper.php?hash", timeout=6) as r:
            return b"ingest.py" in r.read()
    except Exception:
        return False


def _save_where(w):
    try:
        HOME.mkdir(parents=True, exist_ok=True)
        WHERE.with_suffix(".new").write_text(json.dumps(w, indent=1))
        os.replace(WHERE.with_suffix(".new"), WHERE)
    except OSError:
        pass


_learned = [0.0]

def learn_where():
    """Every few minutes, ask Rushes every address it can be reached at, and
    keep them. When Setup names a new address and it answers from here, move."""
    if time.time() - _learned[0] < 300:
        return
    _learned[0] = time.time()
    try:
        with urllib.request.urlopen(NAS_URL + "/db/helper.php?where", timeout=6) as r:
            got = json.loads(r.read().decode("utf-8", "replace")) or {}
    except Exception:
        return
    w = _where()
    known = [got.get("url"), got.get("name"), NAS_URL, ARG_URL] + w.get("known", [])
    w["known"] = list(dict.fromkeys(u.rstrip("/") for u in known if u))[:6]
    _save_where(w)
    want = (got.get("url") or "").rstrip("/")
    if want and want != NAS_URL and answers(want):
        move_to(want, "Setup in Rushes has a new address for it")


def rushes_elsewhere():
    """The first other address Rushes answers at, or None."""
    for u in [ARG_URL] + _where().get("known", []):
        if u and u != NAS_URL and answers(u):
            return u
    return None


def move_to(url, why):
    """Carry on at a new address: this computer's saved progress moves with
    it, and the helper restarts itself in place pointing there."""
    if _describing.is_set():
        print(f"  Rushes has moved to {url}; following it once describing reaches a stopping point")
        return
    for sfx in ("", "-wal", "-shm"):
        old = HOME / f"transfer-{hashlib.sha256(NAS_URL.encode()).hexdigest()[:16]}.sqlite{sfx}"
        new = HOME / f"transfer-{hashlib.sha256(url.encode()).hexdigest()[:16]}.sqlite{sfx}"
        if old.exists() and not new.exists():
            os.replace(old, new)
    w = _where(); w["current"] = url
    w["known"] = list(dict.fromkeys([url] + w.get("known", [])))[:6]
    _save_where(w)
    app = Path.home() / "Library" / "Application Support" / "Rushes" / "url"
    if app.exists():                      # what Rushes Helper's window shows
        try: app.write_text(url + "\n")
        except OSError: pass
    print(f"\n{time.strftime('%H:%M:%S')}  Rushes is now at {url} — {why}. Restarting with the new address …")
    sys.stdout.flush()
    os.execv(sys.executable, [sys.executable, "-u"] + sys.argv)


_LOCK = None
try:
    with open(os.path.abspath(__file__), "rb") as _me:
        VERSION = hashlib.sha256(_me.read()).hexdigest()[:12]
except OSError:
    VERSION = ""

def only_one():
    """One helper per computer. A second one — say a Terminal window while the
    background one runs — would copy the same folders twice."""
    global _LOCK
    try:
        import fcntl
    except ImportError:
        return                                   # Windows: no locking here yet
    HOME.mkdir(parents=True, exist_ok=True)
    _LOCK = open(HOME / "helper.lock", "w")
    try:
        fcntl.flock(_LOCK, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        sys.exit("Another Rushes helper is already running on this computer — most likely\n"
                 "the background one, which Manage in Rushes shows. Nothing to do here.")


_checked = [0.0]

def update_self():
    """Stay the same as the helper on the archive. Checked between steps,
    never during a copy; after an update it restarts itself in place, so a
    change to the archive's copy reaches every computer without anyone
    touching it. A half-downloaded or broken file is never put in place."""
    if time.time() - _checked[0] < 300 or _describing.is_set():
        return                        # a restart would cut a folder being described in half: later
    _checked[0] = time.time()
    try:
        with urllib.request.urlopen(NAS_URL + "/db/helper.php?hash", timeout=10) as r:
            want = json.loads(r.read().decode("utf-8", "replace"))
    except Exception:
        return
    here = os.path.dirname(os.path.abspath(__file__))
    stale = {}
    for f, h in (want or {}).items():
        if f not in ("ingest.py", "transfer_state.py", "analyze.py") or not h:
            continue
        try: mine = hashlib.sha256(open(os.path.join(here, f), "rb").read()).hexdigest()
        except OSError: mine = ""
        if mine != h:
            stale[f] = h
    if not stale:
        return
    try:
        got = {}
        for f, h in stale.items():
            with urllib.request.urlopen(NAS_URL + "/db/helper.php?code=" + f, timeout=60) as r:
                data = r.read()
            if hashlib.sha256(data).hexdigest() != h:
                return                           # changed while downloading: next time
            compile(data, f, "exec")             # a broken file never goes in
            got[f] = data
        for f, data in got.items():
            with open(os.path.join(here, f + ".new"), "wb") as fh:
                fh.write(data)
        for f in got:
            os.replace(os.path.join(here, f + ".new"), os.path.join(here, f))
    except Exception as e:
        print(f"  ! could not update the helper ({e}) — carrying on with this version")
        return
    print(f"\n{time.strftime('%H:%M:%S')}  a new version of the helper is on the archive — updated, restarting …")
    sys.stdout.flush()
    os.execv(sys.executable, [sys.executable] + sys.argv)

# Stop before the archive is full, not after. A copy that dies at 100% leaves
# the NAS with no room to write anything at all — including its own logs and
# the search index — and that is a far worse evening than a queue that paused.
FLOOR = (int(os.environ["FLOOR_GB"]) * 1024 ** 3 if os.environ.get("FLOOR_GB")
         else rule("disk_stop_free", 5000 * 1024 ** 3))


def free_bytes(path=NAS_MOUNT):
    """Free space on the archive, as the archive itself counts it. Over SMB a
    Mac misreads a volume this big — the block counts wrap, 'used' comes back
    negative and 15 TB free reads as 3 TB — so an external helper asks Rushes,
    which reads df on its own disk every minute. The share's own figure is
    only the fallback."""
    if not BUILT_IN:
        try:
            with urllib.request.urlopen(NAS_URL + "/db/state.php", timeout=15) as r:
                free = int(json.loads(r.read().decode("utf-8", "replace"))["disk"]["free"])
            if free > 0:
                return free
        except Exception:
            pass
    try:
        st = os.statvfs(path)
        return st.f_bavail * st.f_frsize
    except OSError:
        return None

def watch(root, every=20):
    print(f"watching {QUEUE_URL}")
    if "--service" in sys.argv:
        print("Running in the background: it starts at login and restarts if it stops.")
        print("Manage in Rushes shows what it is doing; this log keeps the detail.\n")
    else:
        print("Leave this window open. Tick folders in Manage → Transfers, or queue a card in Ingest, and they run here.")
        print("Ctrl-C to stop; anything half-copied picks up where it left off.\n")
    last = None
    blocked = False        # said the source was gone; do not say it again
    threading.Thread(target=report_forever, daemon=True).start()
    threading.Thread(target=describe_lane, daemon=True).start()      # the second lane
    if not control().get("paused"):                                  # Rushes' copies, brought up to date once
        send_file("history"); send_file("sections")
    if sys.platform == "darwin":
        print("The Mac is kept awake while a copy runs (the screen can still sleep).\n")
    paused = False         # said it was paused; once
    denied = False         # said macOS would not let it in; once
    lost = False           # said the archive was gone; do not say it again
    jobless = False        # said the saved transfer could not be read; once
    unreached = 0          # tries in a row Rushes did not answer
    while True:
        update_self()                  # between steps only; restarts itself if it did
        learn_where()                  # and follows Rushes to a new address, if it has one
        remember_shares()              # where each network share lives, to reconnect it if it drops
        checkpoints().flush()          # search updates still waiting, if any
        try:
            with urllib.request.urlopen(QUEUE_URL, timeout=15) as r:
                body = r.read().decode("utf-8", "replace")
            unreached = 0
        except urllib.error.HTTPError as e:
            # 404 means the file is not there, which is what an empty queue
            # looks like before anything has ever been ticked. Not an error.
            if e.code != 404:
                print(f"  the NAS answered {e.code} — trying again in {every}s")
                wait(every); continue
            body = ""
        except Exception as e:
            unreached += 1
            later = max(every, backoff(unreached))
            print(f"  cannot reach Rushes ({e}) — asking again in {later // 60} min" if later >= 60
                  else f"  cannot reach Rushes ({e}) — asking again in {later}s")
            if unreached >= 3:         # a minute or so: not a blip. Is it somewhere else now?
                u = rushes_elsewhere()
                if u:
                    move_to(u, f"it stopped answering at {NAS_URL}")
            wait(later); continue

        # Paused from Manage: said once, and then nothing on any share is touched —
        # no folder checked, nothing listed, nothing written — until Resume.
        # Only Rushes is asked, over the network, whether Resume was pressed.
        c = control()
        if stopped():
            # Rule 4: a share stopped answering three times. Nothing is touched
            # until a person presses Try again (Rushes Helper, or Manage).
            status(phase="blocked", source="", note=stopped())
            wait(every); continue
        if c.get("paused"):
            if not paused:
                print(f"\n{time.strftime('%H:%M:%S')}  paused from Manage — nothing touches the shares until Resume")
                status(phase="paused", source="", note="paused from Manage")
                paused = True
            wait(every); continue

        want = []
        for line in body.splitlines():
            f = line.split("\t")
            if len(f) >= 2 and f[0] in ("copy", "list") and f[1].startswith("/"):
                want.append((f[0], f[1], ""))
            elif len(f) >= 2 and f[0] == "analyze" and f[1].startswith("/"):
                # the third field is when it was asked for: asking again (new footage) runs again
                want.append(("analyze", f[1], f[2] if len(f) > 2 and f[2].isdigit() else ""))
            elif len(f) >= 2 and f[0] in ("tidy", "untidy") and f[1]:
                want.append((f[0], f[1], ""))
            elif len(f) >= 3 and f[0] == "ingest" and f[1] and f[2]:
                # a card, the folder it goes in, and — for a card that spans
                # several days — which day's files belong in that folder
                want.append(("ingest", f[1], f[2] + ("\t" + f[3] if len(f) > 3 and f[3] else "")))

        # Describing is the other lane's: hand it over, and keep copying here.
        with _io:
            _describe_jobs[:] = [(p, i) for v, p, i in want if v == "analyze"]
        want = [w for w in want if w[0] != "analyze"]

        if body != last:
            print(f"{time.strftime('%H:%M:%S')}  " +
                  (f"queue: {len(want)} item(s)" if want else
                   "nothing ticked yet — waiting"))
            last = body

        # The selection and its saved progress. If Rushes cannot say, copies
        # still run from the queue — the progress just is not saved meanwhile.
        try:
            with urllib.request.urlopen(NAS_URL + "/db/transfer.php", timeout=15) as r:
                job = json.loads(r.read().decode("utf-8", "replace")) or {}
            if jobless:
                print(f"{time.strftime('%H:%M:%S')}  transfer progress is being saved again")
            jobless = False
        except Exception as e:
            job = {}
            if not jobless:
                print(f"  ! cannot read the saved transfer from Rushes ({e}).")
                print("    Copies carry on; the overall percentage catches up when it answers.")
            jobless = True
        job_id = job.get("id", "")
        job_items = {i["source"]: i for i in job.get("items", [])}

        done = set()
        if DONE.exists():
            done = {l.rstrip("\n") for l in open(DONE) if l.strip()}
        # A split is asked for once. Without this it would be re-run every pass,
        # for ever, and nothing queued behind it would ever get copied.
        listed = set()
        if LISTED.exists():
            listed = {l.rstrip("\n") for l in open(LISTED) if l.strip()}

        # If EVERY queued folder has vanished, the source is gone — the drive
        # unmounted, the server rebooted, the network dropped. That is not a
        # skip, it is the whole job stopped, and it has to say so once and be
        # visible on the admin page. Repeating it every 20 seconds for three
        # days, as this used to, tells nobody anything.
        # A card is finished when its FOLDER is, not the card path: the same
        # /Volumes/EOS_DIGITAL comes back every week holding a different shoot.
        # A folder in the saved transfer is finished when the transfer says so;
        # one the transfer does not know is finished when this computer did it.
        item_done = lambda p: job_items[p]["phase"] in ("done", "removed")
        finished = lambda v, p, i: ((v == "copy" and (item_done(p) if p in job_items else p in done)) or (v == "ingest" and i.split("\t")[0] in done)
                                    or (v == "list" and p in listed) or (v in ("tidy", "untidy") and f"{v} {p}" in done)
                                    or (v == "analyze" and f"analyze {p}{' ' + i if i else ''}" in done))
        pending = [(v, p, i) for v, p, i in want if not finished(v, p, i)]
        # A tidy-up works inside the archive, so a source going away does not stop it.
        copies = [p for v, p, _ in pending if v not in ("tidy", "untidy")]
        try:
            gone = within("sources", 30, lambda: [p for p in copies if not os.path.isdir(p)]) if copies else []
        except Stalled:
            stall("a source drive"); wait(every); continue
        if copies and len(gone) == len(copies):
            # Its own name: this used to reuse `root`, which is the ARCHIVE — so
            # once a source had gone missing, every copy after it was aimed at
            # the source instead of the archive.
            missing = os.path.commonpath(gone) if len(gone) > 1 else os.path.dirname(gone[0])
            if not blocked:
                blocked = Dropped(missing)
                print(f"\n*** STOPPED: cannot see {missing}")
                print("    Nothing is lost, and part-copied folders resume."
                      + (" Reconnecting it by itself …" if blocked.srv else " Connect it again in Finder."))
            if blocked.try_again():
                blocked.back(); blocked = False
                continue
            status(phase="blocked", source=missing, note=blocked.note())
            for p in gone:
                checkpoints().report(job_id, p, "blocked", force=True)
            if len(copies) == len(pending):
                wait(every); continue
        elif blocked:
            blocked.back(); blocked = False

        # Rule 1: nothing to do, nothing looked at. Checking copies has its own
        # notion of "nothing due" (below) and looks at the archive only then.
        if not pending and (c.get("check_paused") or not check_due()):
            status(phase="waiting", source="", note="nothing queued")
            wait(every); continue

        # Is the archive there, and allowed in? Asked within a time limit: a
        # share that does not answer is walked away from, not waited on.
        try:
            here = within("archive", 30, _archive_here)
        except Stalled:
            stall("the archive share"); wait(every); continue
        unstalled()

        # The archive itself going away — VIDEO unmounted — stops everything,
        # this program's own steps included, since they are read from it.
        # Say so once, keep telling the page, and carry on when it is back.
        if here == "gone":
            if not lost:
                lost = Dropped(NAS_MOUNT)
                print(f"\n*** STOPPED: cannot see the archive at {NAS_MOUNT}")
                print("    Nothing is lost, and part-copied folders resume."
                      + (" Reconnecting it by itself …" if lost.srv else " Connect it again in Finder."))
            if not lost.try_again():
                status(phase="blocked", source=NAS_MOUNT, note=lost.note())
                wait(every); continue
            lost.back(); lost = False; continue      # back: looked at again from the top
        if lost:
            lost.back(); lost = False

        # There, but not allowed in: macOS keeps a background program away from
        # network and removable drives until it is given Full Disk Access.
        # Nothing can be copied or checked like this, so nothing is tried.
        if here == "ok":
            if denied:
                print(f"\n{time.strftime('%H:%M:%S')}  allowed in now — carrying on")
            denied = False
        else:
            if not denied:
                print(f"\n*** STOPPED: {DENIED}")
                print("    Nothing is copied until then. It notices within a minute once allowed.")
                denied = True
            status(phase="blocked", source=NAS_MOUNT, note=DENIED)
            wait(every); continue

        # Paused from Manage: nothing new starts until Resume.
        c = control()
        if c.get("paused"):
            if not paused:
                print(f"\n{time.strftime('%H:%M:%S')}  paused from Manage — nothing new starts until Resume")
                paused = True
            status(phase="paused", source="", note="paused from Manage")
            wait(every); continue
        if paused:
            print(f"\n{time.strftime('%H:%M:%S')}  resumed from Manage — carrying on")
            paused = False

        did = False
        for verb, path, into in want:
            if finished(verb, path, into):            # already finished, ever
                continue
            if verb == "tidy":
                did = True
                print(f"\n=== tidy-up {path}: moving what the copies brought onto the shelf ===")
                status(phase="tidying", source=path, copied=0, of=0)
                run_self("--tidy", path); break
            if verb == "untidy":
                did = True
                print(f"\n=== undoing tidy-up {path} ===")
                run_self("--untidy", path); break
            if verb == "copy" and path in c.get("skip", []):
                continue                               # skipped from Manage
            item = job_items.get(path) if verb == "copy" else None
            # Finished before progress was saved per transfer: say so, from
            # this computer's own list, instead of walking it all again.
            if item and path in done:
                checkpoints().report(job_id, path, "done", force=True,
                                     done_bytes=item.get("total_bytes", 0), done_files=item.get("total_files", 0))
                continue
            if not os.path.isdir(path):
                if not blocked:
                    print(f"  ! {path} is not mounted — skipping")
                checkpoints().report(job_id, path, "blocked", force=True)
                continue
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
                    checkpoints().report(job_id, path, "stopped", force=True)
                    wait(300)            # check again in five minutes, or on "Try again now"
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
                checkpoints().report(job_id, path, "checking", force=True)   # not "interrupted": it is working
                run_self("--trace", sr, "--root", root)
            elif verb == "ingest":
                dest, _, day = into.partition("\t")
                print(f"\n=== {path}  →  {dest} ===" + (f"   (shot {day})" if day else ""))
                run_self("--source", path, "--into", dest, "--apply", *(["--day", day] if day else []))
            else:
                print(f"\n=== {path} ===")
                if run_self("--source", path, "--root", root, "--apply", "--job", job_id):
                    checkpoints().report(job_id, path, "paused" if control().get("paused") else "interrupted", force=True)
                time.sleep(5)      # a folder that did not finish is never retried in a tight loop
            break                                      # one at a time, in order

        if not did:
            # Nothing to copy: some checking (older copies, then the archive), a few minutes at a time.
            try: busy = not c.get("check_paused") and check_some()
            except Exception as e:                 # never let checking stop the helper
                print(f"  ! checking stopped: {e}"); busy = False
            if busy:
                continue
            status(phase="waiting", source="", note="nothing queued")
            wait(every)


# ── describing footage: the vision model and speech ─────────────────────────
# The models run in their own Python (the analysis tools: mlx-vlm, mlx-whisper,
# scenedetect), never this one. Which Python and which models are settings in
# Rushes (analysis.python / .model / .whisper), with the machine's own install
# as the default. analyze.py writes one description per file into the
# archive's _rushes/analysis, and reports progress as "@@ {json}" lines.
def analysis_tools():
    a = SETTINGS.get("analysis") or {}
    py = os.path.expanduser(a.get("python") or "~/archive-pilot/venv/bin/python")
    local = os.path.expanduser("~/archive-pilot/qwen3vl8b")
    model = a.get("model") or (local if os.path.isdir(local) else "mlx-community/Qwen3-VL-8B-Instruct-4bit")
    return (py if os.path.exists(py) else ""), model, a.get("whisper", "mlx-community/whisper-large-v3-turbo")


def describe_lane(every=20):
    """The describing lane: the queue's describing jobs, one folder at a time,
    beside the copies. Its own Pause (Manage, or Rushes Helper's window)."""
    _lane.name = "describe"
    said = ""
    while True:
        try:
            if control().get("describe_paused"):
                if said != "paused":
                    status(phase="paused", note="describing paused — Resume describing carries on"); said = "paused"
                time.sleep(10); continue
            if stopped():                     # a share stopped answering: nothing until Try again
                time.sleep(every); continue
            with _io:
                jobs = list(_describe_jobs)
                done = set(DONE.read_text().splitlines()) if DONE.exists() else set()
            todo = [(p, a) for p, a in jobs if f"analyze {p}{' ' + a if a else ''}" not in done]
            try:
                todo = within("describe", 30, lambda: [(p, a) for p, a in todo if os.path.exists(p)]) if todo else []
            except Stalled:
                stall("the archive share (describing)"); time.sleep(every); continue
            if not todo:
                if said != "idle":
                    status(phase="idle", note="nothing to describe"); said = "idle"
                time.sleep(every); continue
            path, asked = todo[0]; said = ""
            print(f"\n=== describing {path} (vision model and speech), beside the copies ===")
            _describing.set()
            try:
                if describe_folder(path, asked) == 0:
                    _mark_done(f"analyze {path}{' ' + asked if asked else ''}")
            finally:
                _describing.clear()
            time.sleep(5)
        except Exception as e:                # the lane never takes the copies down with it
            print(f"  describing: {e} — trying again in a minute")
            time.sleep(60)


def describe_folder(path, asked=""):
    py, model, whisper = analysis_tools()
    name = os.path.basename(path.rstrip("/")) or path
    if not py:
        print("  the analysis tools are not installed on this computer — nothing described")
        status(phase="blocked", source=path, note="the analysis tools are not installed on this computer")
        wait(300)
        return 2
    cmd = [py, "-u", os.path.join(os.path.dirname(os.path.abspath(__file__)), "analyze.py"), path,
           "--store", os.path.join(NAS_MOUNT, "_rushes", "analysis"), "--url", NAS_URL,
           "--model", model, "--whisper", whisper,
           "--archive", NAS_MOUNT, "--proxies", os.path.join(NAS_MOUNT, "PROXIES")]
    if sys.platform == "darwin" and shutil.which("caffeinate"):
        cmd = ["caffeinate", "-i"] + cmd
    status(phase="analysing", source=path, label=name, step="loading the model")
    t0, done, stopped = time.time(), {}, ""
    last = dict(phase="analysing", source=path, label=name, step="loading the model")
    p = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, bufsize=1)
    def beat():
        # Listening to a long file, or loading the model, says nothing for
        # minutes: the page is told again every minute that it is still at it.
        _lane.name = "describe"
        while p.poll() is None:
            time.sleep(60)
            if p.poll() is None: status(**last)
    threading.Thread(target=beat, daemon=True).start()
    for line in p.stdout:
        if line.startswith("@@ "):
            try:
                d = json.loads(line[3:])
            except ValueError:
                continue
            if d.get("finished"):
                done = d; continue
            last = dict(phase="analysing", source=path, label=name, file=d.get("file", ""),
                        n=d.get("n", 0), of=d.get("of", 0), shot=d.get("shot", 0), shots=d.get("shots", 0),
                        per_shot=d.get("per_shot", ""), failed=d.get("failed", 0), step=d.get("step", "shots"))
            status(**last)
        else:
            print(line.rstrip(), flush=True)
        c = control()                         # Pause describing stops it between lines; a file part-done is redone
        if c.get("describe_paused") or path in c.get("skip", []):
            stopped = "describing paused" if c.get("describe_paused") else "skipped from Manage"
            p.terminate(); break
    rc = p.wait()
    secs = time.time() - t0
    if stopped:
        print(f"*** {stopped} — the files already described are kept")
        status(phase="paused", source=path, label=name, note=f"{stopped} — the files already described are kept")
        return 1
    history("analysed", path, done.get("done", 0) + done.get("already", 0), 0, secs,
            "; ".join(x for x in (f"asked={asked}" if asked else "",
                                  f"{done.get('failed', 0)} could not be described" if done.get("failed") else "") if x))
    return 0 if rc == 0 and done else 1


def run_self(*args):
    """Run one section in its own process, so a failure cannot stop the watch."""
    cmd = [sys.executable, os.path.abspath(__file__), *args, "--url", NAS_URL]
    if sys.platform == "darwin" and shutil.which("caffeinate"):
        cmd = ["caffeinate", "-i"] + cmd       # awake while this step runs, and only then
    t0 = time.time()
    try:
        rc = subprocess.run(cmd, check=False).returncode
    except KeyboardInterrupt:
        raise
    except Exception as e:
        print(f"  that section failed to start: {e}"); rc = -1
    # A step that fails the moment it starts would otherwise be retried at
    # once, for ever, as fast as the machine can go. Wait before the next try.
    if rc not in (0, 130) and time.time() - t0 < 10:
        print("  that step stopped straight away — trying again in a minute")
        wait(60)
    return rc


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--job", default="", help=argparse.SUPPRESS)
    ap.add_argument("--service", action="store_true", help=argparse.SUPPRESS)  # started by the Mac itself   # the saved transfer this belongs to
    ap.add_argument("--source", help="folder to ingest from (mounted server, or a card)")
    ap.add_argument("--url", help="where Rushes is, e.g. http://192.168.1.20")
    ap.add_argument("--trace", metavar="SOURCE",
                    help="for footage copied before the origin record existed: work out, "
                         "file by file, where each copy in the archive came from")
    ap.add_argument("--root", default=ARCHIVE, help="archive root on the NAS")
    ap.add_argument("--tidy", metavar="PLAN", help="move copied footage onto the shelf by an approved plan")
    ap.add_argument("--untidy", metavar="RECORD", help="put back what one tidy-up moved")
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
            u = rushes_elsewhere()
            if u:
                move_to(u, f"it does not answer at {NAS_URL}")
            sys.exit(f"Cannot reach Rushes at {NAS_URL}.\n"
                     "Copy the start command from Setup in Rushes — it has the right --url.")
        only_one()
        print(f"helper: {'built in' if BUILT_IN else 'external'} · archive at {NAS_MOUNT} · Rushes at {NAS_URL}"
              + (" · running in the background" if a.service else ""))
        return watch(a.root)
    # Anything that copies or lists needs to know where things are. Without
    # Rushes' settings it would be guessing, and a guessed path is how files
    # land somewhere nobody looks. Stop and say why instead.
    if (a.sections or a.source or a.trace or a.tidy or a.untidy) and not SETTINGS:
        sys.exit(f"Cannot reach Rushes at {NAS_URL} — nothing copied.\n"
                 "Stop the helper (Ctrl-C) and start it again with the command from Setup.")
    # The one boundary every copy must respect: it lands inside the archive.
    # Whatever went wrong upstream, a copy aimed anywhere else stops here.
    inside = lambda p: os.path.abspath(p) == os.path.abspath(NAS_MOUNT) or \
        os.path.abspath(p).startswith(os.path.abspath(NAS_MOUNT) + os.sep)
    for where in ([a.into] if a.into else [a.root] if (a.source or a.trace) else []):
        if not inside(where):
            print(f"Refused: {where} is not inside the archive ({NAS_MOUNT}). Nothing copied.")
            sys.exit(1)
    if a.trace:
        HOME.mkdir(exist_ok=True); load_cache()
        return trace(a.trace, a.root)
    if a.tidy:
        if not re.fullmatch(r"[0-9-]+", a.tidy): sys.exit("That is not a tidy-up plan.")
        return tidy(a.tidy)
    if a.untidy:   return untidy(a.untidy)
    if a.sections: return sections(a.sections, a.fresh)
    if a.undo:     return undo()
    if not a.source: sys.exit("need --source")

    HOME.mkdir(exist_ok=True)
    load_cache()
    cp = checkpoints()
    try:
        os.listdir(a.source); os.listdir(STATUS)
    except PermissionError:
        print(DENIED); cp.report(a.job, a.source, "blocked", force=True); sys.exit(1)
    except OSError:
        pass                                      # missing: handled just below
    gone = lambda: not os.path.isdir(a.source) or not os.path.isdir(NAS_MOUNT)
    if gone():
        # Missing is not empty. Nothing is marked done; the watcher waits.
        print(f"Cannot see {a.source if not os.path.isdir(a.source) else NAS_MOUNT}. Nothing copied.")
        cp.report(a.job, a.source, "blocked", force=True)
        sys.exit(1)
    cp.report(a.job, a.source, "checking", force=True)
    WALK_ERRORS.clear()
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
    walked = 0
    with open(PLAN, "w") as plan:
        for i, src in enumerate(walk(a.source, everything=True), 1):
            walked = i
            try: size = os.path.getsize(src)
            except OSError as e:
                WALK_ERRORS.append(e); continue
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
            if i % 50 == 0:              # status() itself keeps this to one every 2 s
                status(phase="looking", source=a.source, label=label, checked=i, new=new,
                       already=dups, new_bytes=new_bytes)
                cp.report(a.job, a.source, "checking")
    save_cache()

    # A folder that had files when it was listed, and now shows none, is a
    # share that came back wrong (half-mounted, or renamed by the Mac) — not
    # an empty folder. Marking it done would skip it for ever.
    if not a.into and walked == 0:
        had = 0
        for l in open(STATUS / "ingest-sections.tsv", errors="replace") if (STATUS / "ingest-sections.tsv").exists() else []:
            f = l.rstrip("\n").split("\t")
            if len(f) > 2 and f[0] == "section" and f[1] == a.source.rstrip("/") and f[2].isdigit():
                had = int(f[2])
        if had:
            print(f"\n*** {a.source} shows no files, but it had {had:,} when it was listed.")
            print("    Not marking it done. Check the share is connected properly (eject and")
            print("    connect it again in Finder); this folder is tried again after that.")
            status(phase="blocked", source=a.source, note=f"{os.path.basename(a.source.rstrip('/'))} shows no files — check the share")
            sys.exit(1)

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
    copied = failed = copied_b = 0
    done_b, speed, shown = 0, Speed(), [0.0]       # bytes read and written: the speed
    total_bytes, total_files = new_bytes + dup_bytes, new + dups
    o = Origin("card" if a.into else "folder", a.source, src_root, src_name,
               a.into or os.path.join(mirror, os.path.relpath(a.source, src_root)))

    def progress(name, force=False, phase="copying"):
        # Two readers: the live line (every 2 s: speed, time left, the file)
        # and the saved progress of the whole transfer (every 10 s).
        if not force and time.time() - shown[0] < 2: return
        shown[0] = time.time(); speed.add(done_b); r = speed.rate()
        status(phase=phase, source=a.source, label=label, copied=copied, failed=failed,
               of=new, new_bytes=new_bytes, done_bytes=done_b, rate=r,
               eta=int((new_bytes - done_b) / r) if r else "", file=name)
        cp.report(a.job, a.source, phase, force=force, total_bytes=total_bytes,
                  total_files=total_files, measured=1, failed=failed, **cp.counts(a.job, a.source))
        cp.flush()
    global _HEARTBEAT
    _HEARTBEAT = lambda: progress("")

    stopped = ""                          # why the whole folder stopped, if it did
    log = open(LOG, "w")
    proof = []                            # (copy, size, xxh128): this run's ASC MHL record
    top = a.into or os.path.join(mirror, os.path.relpath(a.source, src_root))

    def bring(f):
        """One file from the plan: -> "copied" or "already", or raises OSError."""
        nonlocal copied, copied_b, done_b
        size, src, dest = int(f[1]), f[2], f[3]
        name = os.path.basename(src)
        # The same name can be stored two ways: "Día" as one character or as
        # "i" plus an accent (macOS often gives the second). They look the same
        # and are not the same bytes, so a copy could land beside itself. As
        # rclone does, treat both as one name: use the one already there.
        if not os.path.exists(dest):
            for form in ("NFC", "NFD"):
                alt = unicodedata.normalize(form, dest)
                if alt != dest and os.path.exists(alt):
                    dest = alt; break
        if f[0] == "skip":               # identical file already in the archive
            if not os.path.isfile(dest) or os.path.getsize(dest) != size:
                raise OSError("its match in the archive is not there any more")
            kind, note = "already", "not copied — this is where it already is"
        elif os.path.exists(dest):
            # A file of that name already there counts only if it is the same file.
            mine = digest(src, full=PARANOID)
            if os.path.getsize(dest) != size or mine is None or mine != digest(dest, full=PARANOID):
                raise OSError("a different file with this name is already there — left untouched")
            kind, note = "already", "there from an earlier run"
        else:
            # Copy to a temp name, make sure it is on the archive's disk, check
            # it byte for byte, then put it in place — so an interrupted or bad
            # copy never looks like a finished file.
            os.makedirs(os.path.dirname(dest), exist_ok=True)
            tmp = dest + ".part"
            progress(name, force=True)
            h, algo = new_fingerprint()              # the original's fingerprint, taken while reading it anyway
            before = os.stat(src)
            try:
                with open(src, "rb") as fi, open(tmp, "wb") as fo:
                    while True:
                        buf = fi.read(8 << 20)
                        if not buf: break
                        fo.write(buf); h.update(buf); done_b += len(buf)
                        progress(name)
                    fo.flush(); os.fsync(fo.fileno())    # stored on the archive's disk, not only on its way there
                shutil.copystat(src, tmp)
                # As rclone does: an original that changed while it was read
                # (still being written by a camera or another copy) gives a copy
                # of neither version. Thrown away; it is tried again later.
                after = os.stat(src)
                if (after.st_size, after.st_mtime_ns) != (before.st_size, before.st_mtime_ns):
                    raise OSError("the original changed while it was being copied (still being written?)")
            except BaseException:
                # No half-copied file is ever left behind, whatever stopped it.
                try: os.remove(tmp)
                except OSError: pass
                raise
            if os.path.getsize(tmp) != size:
                os.remove(tmp)
                if os.path.getsize(src) != size:
                    raise OSError("the original has changed since it was listed (still being written?) — it is copied on the next run")
                raise OSError("size mismatch after copy")
            if read_back(tmp) != h.hexdigest():      # the copy, read back from the archive
                os.remove(tmp); raise OSError("the copy did not match the original byte for byte")
            os.rename(tmp, dest)
            log.write(f"{src}\t{dest}\n"); log.flush()
            # Kept in the where-it-came-from record: years from now, the archive
            # copy can still be proven to be the original.
            copied += 1; copied_b += size; kind, note = "copied", f"verified {algo} {h.hexdigest()}"
            if algo == "xxh128":
                proof.append((dest, size, h.hexdigest()))
        cp.landed(dest, size)
        cp.complete_file(a.job, a.source, src, size, kind)
        o.add(kind, src, dest, size, note)

    # Two passes. A file that fails is set aside and tried once more at the
    # end — a network hiccup should not cost a file — and only then written
    # down as failed. A disconnection stops the folder instead: it resumes.
    retry = []
    for attempt in (1, 2):
        if attempt == 1:
            with open(PLAN) as plan:
                todo = [l.rstrip("\n").split("\t") for l in plan]
        else:
            todo = retry
        if attempt == 2 and retry:
            print(f"  trying {len(retry)} file{'s' if len(retry) != 1 else ''} again …"); time.sleep(5)
        retry_next = []
        for f in todo:
            if gone():
                stopped = "the source or the archive disconnected"; break
            c = control()
            if c.get("paused"):
                stopped = "paused from Manage"; break
            if a.source.rstrip("/") in c.get("skip", []):
                stopped = "skipped from Manage"; break
            try:
                bring(f)
            except OSError as e:
                if gone():                   # not this file's fault: stop, resume later
                    stopped = str(e); break
                if e.errno in (errno.ENOSPC, errno.EDQUOT):
                    # A full archive fails every file the same way: stop, say
                    # so once, and carry on from here when there is room.
                    stopped = "the archive is full — free some space, then it carries on from here"; break
                if attempt == 1:
                    retry_next.append(f); continue
                # Twice now. Write it down, say so, carry on with the rest:
                # one unreadable file must not hold up a whole folder for ever.
                failed += 1
                log.write(f"FAILED\t{f[2]}\t{e}\n"); log.flush()
                o.add("failed", f[2], "", int(f[1]), str(e))
                print(f"  ! could not copy {os.path.basename(f[2])}: {e}")
            if copied and copied % 50 == 0:
                r = speed.rate()
                print(f"  {copied:,} copied" + (f" — {r / 1e6:.0f} MB/s" if r else ""))
        if stopped: break
        retry = retry_next
    log.close()
    for e in WALK_ERRORS:                    # what could not even be listed
        failed += 1
        print(f"  ! could not read {getattr(e, 'filename', '') or 'a folder'}: {e.strerror or e}")
        o.add("failed", getattr(e, "filename", "") or "", "", 0, f"could not be read: {e.strerror or e}")
    mhl_copied(top, proof, o, a.source)   # stopped part-way too: what did land is proven
    o.close()
    key = a.into.rstrip("/") if a.into else a.source.rstrip("/")

    if stopped or gone():
        why = stopped or "the source or the archive disconnected"
        print(f"\n*** stopped part-way: {why}")
        print("    Everything copied so far is kept. This folder carries on from here next time.")
        progress("", force=True, phase="paused" if why == "paused from Manage" else "interrupted")
        cp.flush(force=True)
        history("interrupted", key, copied, copied_b, time.time() - t0, why)
        sys.exit(1)

    if os.path.isdir(top):
        leave_a_note(top, o, a.source, src_name)
    print(f"\ncopied {copied:,}" + (f", {failed} could not be copied — each is listed in the record" if failed else ""))
    print(f"  where every file came from: _rushes/origin/{o.path.name}")
    progress("", force=True, phase="done")
    cp.flush(force=True)
    print(f"log: {LOG}  (undo with: python ingest.py --undo)")
    status(phase="done", source=a.source, label=label, copied=copied, failed=failed, of=new)
    # Finished, even with files that could not be copied: those are written in
    # the record and shown on the page. Re-select the folder to try them again.
    history("copied", key, copied, copied_b, time.time() - t0,
            f"{failed} could not be copied" if failed else "")
    with open(DONE, "a") as f:            # a card by where it went; a folder by where it came from
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

    # a tidy-up's move carries a file's origin along, and an undo brings it back
    import tempfile
    global ORIGIN
    keep, ORIGIN = ORIGIN, Path(tempfile.mkdtemp())
    (ORIGIN / "1 a folder.tsv").write_text("copied\t/S/a.mov\t/A/x/a.mov\t5\t\n")
    (ORIGIN / "2 archive tidy.tsv").write_text("moved\t/A/x/a.mov\t/V/P/a.mov\t5\t/S/a.mov\n")
    assert load_origins() == {"/S/a.mov": ("/V/P/a.mov", "5")}, load_origins()
    (ORIGIN / "3 archive untidy.tsv").write_text("moved\t/V/P/a.mov\t/A/x/a.mov\t5\tundo\n")
    assert load_origins() == {"/S/a.mov": ("/A/x/a.mov", "5")}, load_origins()
    ORIGIN = keep
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
