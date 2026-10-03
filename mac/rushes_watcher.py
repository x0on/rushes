# Rushes — Media Management Software, by Alejandro Renteria.
# Source available: https://github.com/x0on/rushes — whoever finds this file on a computer can see what it is and who made it.
"""Rushes Watcher — on each editor's computer (HOW-IT-WORKS.md → Projects in and out).

It keeps a project and everything it uses, without anyone pressing anything:

The editor works wherever he likes (Desktop, Documents, a drive). This
computer has its own folder on the NAS, Projects/<its name> on the shelf, made
when it was paired, and each of its projects a folder there.

- **On save.** While an editing program is open, it asks Spotlight which
  Premiere projects were saved. Once one has been quiet a few minutes, it sends
  Rushes the files the project uses that the NAS does not have yet (music,
  stock, downloads, graphics, voiceover: only what was imported into the
  project, not the rest of a Downloads folder), and makes an Output folder
  beside the project, for the finished exports.
- **On close.** When the editing program is quit, a dated copy of each project
  it saved goes to the NAS, pointing at the NAS's copies of what it uses, and
  what is new in Output goes with it: the deliverables.

The project and the files on this Mac are only read, never moved or changed
(the Output folder is the one thing it makes). Files go to Rushes over the
network, piece by piece; the helper checks each one and puts it in place.
Every step is written in its log, which the editor can read, and which Rushes
shows on Manage → Editors' projects. Nothing here deletes anything.

    python3 rushes_watcher.py pair <rushes address> <six numbers>
    python3 rushes_watcher.py run         (what the app runs; stays open)
    python3 rushes_watcher.py once        (one look, then stops)
    python3 rushes_watcher.py --selftest

Only Premiere projects for now; Final Cut and Resolve come later (ROADMAP.md).
Only a Mac for now; the Windows Watcher comes later.
"""
import gzip
import hashlib
import html
import json
import os
import platform
import re
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

def _version():
    """The app's version (app/VERSION, written into its Info.plist when built); "dev" when run from the repository."""
    try:
        import plistlib
        with open(os.path.join(os.environ["RUSHES_APP"], "Contents", "Info.plist"), "rb") as f:
            return plistlib.load(f)["CFBundleShortVersionString"]
    except Exception:
        return "dev"


VERSION = _version()
HOME = os.environ.get("RUSHES_WATCHER_HOME") or os.path.expanduser("~/Library/Application Support/Rushes Watcher")
LOG = os.environ.get("RUSHES_WATCHER_LOG") or os.path.expanduser("~/Library/Logs/Rushes Watcher/watcher.log")
CONFIG, STATE = os.path.join(HOME, "config.json"), os.path.join(HOME, "state.json")
PAUSED = os.path.join(HOME, "paused")          # the menu's Pause watching (P3); a file, so it outlives a restart

EVERY = 20             # s between looks at which programs are open (on this computer only)
SCAN_EVERY = 120       # s between asking Spotlight for saved projects, only while an editing program is open
QUIET = 180            # s a saved project must stay unchanged before its files are sent
EDITORS = ("Adobe Premiere Pro", "Final Cut Pro", "DaVinci Resolve")
AUDIO = {"wav", "mp3", "aif", "aiff", "m4a", "flac", "ogg", "aac"}
# ponytail: guessed from the path; a menu to correct a file's kind comes with the icon (P3)
STOCK_HINTS = r"(stock|artlist|pond5|storyblocks|envato|shutterstock|getty|motion ?array|epidemic)"
SKIP_PARTS = ("Adobe Premiere Pro Video Previews", "Adobe Premiere Pro Audio Previews",
              "Adobe Premiere Pro Auto-Save", "Media Cache", "/.Trash", "/Rushes backups/", "/_Moved aside/")


# ── its words ───────────────────────────────────────────────────────────────
def log(msg):
    line = f"{time.strftime('%Y-%m-%d %H:%M')}  {msg}"
    print(line, flush=True)
    try:
        os.makedirs(os.path.dirname(LOG), exist_ok=True)
        if os.path.exists(LOG) and os.path.getsize(LOG) > 2_000_000:      # the newest 500 KB kept
            with open(LOG, "rb") as f:
                f.seek(-500_000, 2); keep = f.read()
            with open(LOG, "wb") as f:
                f.write(keep[keep.find(b"\n") + 1:])
        with open(LOG, "a", encoding="utf-8") as f:
            f.write(line + "\n")
    except OSError:
        pass


def log_tail(n=40):
    try:
        with open(LOG, "rb") as f:
            f.seek(0, 2); f.seek(max(0, f.tell() - 12000))
            return [l for l in f.read().decode("utf-8", "replace").splitlines() if l.strip()][-n:]
    except OSError:
        return []


def load(path, fallback):
    try:
        with open(path, encoding="utf-8") as f:
            return json.load(f)
    except (OSError, ValueError):
        return fallback


def save(path, data):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path + ".new", "w", encoding="utf-8") as f:
        json.dump(data, f, indent=1)
    os.replace(path + ".new", path)


def say_now(state, note=""):
    """What it is doing, for its icon and its window (rushes_helper.py reads it)."""
    try: save(os.path.join(HOME, "now.json"), {"state": state, "note": note, "at": int(time.time())})
    except OSError: pass


# ── Rushes ──────────────────────────────────────────────────────────────────
def rushes(cfg, get=None, post=None, path="/db/watcher.php", timeout=15):
    url = cfg["url"].rstrip("/") + path + ("?" + urllib.parse.urlencode(get) if get else "")
    req = urllib.request.Request(url, data=urllib.parse.urlencode(post).encode() if post is not None else None,
                                 headers={"X-Rushes-Watcher": cfg.get("id", "")})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read().decode("utf-8", "replace"))
    except urllib.error.HTTPError as e:              # Rushes' own words, when it gives them
        try: raise RuntimeError(json.loads(e.read().decode("utf-8", "replace"))["error"]) from None
        except (ValueError, KeyError): raise e from None


def pair(url, code):
    cfg = {"url": url.rstrip("/")}
    if not re.match(r"^https?://", cfg["url"]):
        cfg["url"] = "http://" + cfg["url"]
    r = rushes(cfg, post={"code": code, "host": platform.node().split(".")[0], "role": "watcher"}, path="/db/pair.php")
    if r.get("role") != "watcher" or not re.fullmatch(r"[0-9a-f]{32}", r.get("id", "")):
        raise RuntimeError("Rushes did not pair this computer as an editor's computer")
    cfg["id"] = r["id"]
    save(CONFIG, cfg)
    log(f"paired with Rushes at {cfg['url']} as {r.get('host') or 'this computer'}")
    return cfg


# ── where things are, on this computer ──────────────────────────────────────
def archive_mount(hello, cfg):
    """The archive as this Mac sees it: /Volumes/<name>, unless the config says
    otherwise (mounted under another name). Files in it are never sent."""
    if cfg.get("archive"):
        return cfg["archive"]
    return os.path.join(cfg.get("volumes") or "/Volumes", hello["archive"]) if hello.get("archive") else ""


def inside(p, top):
    return bool(top) and (p == top or p.startswith(top.rstrip("/") + "/"))


def editing():
    """Which editing programs are open here. Asked of this computer only."""
    try:
        out = subprocess.run(["ps", "-axo", "comm"], capture_output=True, text=True, timeout=10).stdout
    except (OSError, subprocess.SubprocessError):
        return []
    return sorted({e for e in EDITORS if e in out})


def find_projects(archive, cache_rules):
    """Every Premiere project on this Mac, wherever the editor keeps it (Desktop,
    Documents, Downloads, a drive): {path: mtime}. Asked of Spotlight, the
    index macOS keeps of every file, so nothing is walked through. Not the
    archive's own copies, auto-saves or caches."""
    try:
        out = subprocess.run(["mdfind", 'kMDItemFSName == "*.prproj"'], capture_output=True, text=True, timeout=60).stdout
    except (OSError, subprocess.SubprocessError) as e:
        raise TimeoutError(f"Spotlight did not answer ({e})")
    found = {}
    for p in out.splitlines():
        if p.endswith(".prproj") and not inside(p, archive) and not skip(p, cache_rules) and "/." not in p:
            try: found[p] = os.stat(p).st_mtime
            except OSError: pass
    return found


def skip(path, cache_rules):
    """Caches, previews and auto-saves: they rebuild themselves, so they are never sent."""
    if any(s in path for s in SKIP_PARTS):
        return True
    ext = path.rsplit(".", 1)[-1].lower() if "." in os.path.basename(path) else ""
    for r in (cache_rules.get("sweep") or []) + (cache_rules.get("keep") or []):
        if ext and ext in r.get("ext", []) and not r.get("path_contains"):
            return True
        if any(s in path for s in r.get("path_contains", [])):
            return True
    return False


# ── a Premiere project: gzipped XML naming each file by its path ────────────
TEXT = re.compile(r">([^<>]{3,2000})<")
LOOKS = re.compile(r"^(/|[A-Za-z]:\\|\\\\).*\.\w{2,5}$")


def read_project(path):
    with open(path, "rb") as f:
        raw = f.read()
    return (gzip.decompress(raw) if raw[:2] == b"\x1f\x8b" else raw).decode("utf-8", "replace"), raw[:2] == b"\x1f\x8b"


def files_named(xml):
    """Every file path the project names: what was imported into it (its bins)."""
    return sorted({html.unescape(t) for t in TEXT.findall(xml) if LOOKS.match(html.unescape(t))})


def repoint(xml, where):
    """The same project, each file in `where` named by its new path. -> (xml, how many)"""
    n = 0
    def one(m):
        nonlocal n
        to = where.get(html.unescape(m.group(1)))
        if not to: return m.group(0)
        n += 1
        return ">" + html.escape(to, quote=False) + "<"
    return TEXT.sub(one, xml), n


def kind_of(path):
    low = path.lower()
    if re.search(r"(^|[^a-z])(sfx|sound ?effects?|foley)([^a-z]|$)", low): return "sfx"
    if re.search(r"(voice ?over|(^|[^a-z])vo([^a-z]|$)|narration)", low): return "project"
    if low.rsplit(".", 1)[-1] in AUDIO: return "music"
    if re.search(STOCK_HINTS, low): return "stock"
    return "project"


def shoot_of(files, archive, shelf):
    """The shoot a project mostly uses, from the archive's own footage it names:
    <shelf>/<department>/<year>/<shoot>. '' when it uses none."""
    count = {}
    for f in files:
        if inside(f, archive):
            parts = os.path.relpath(f, archive).split("/")
            b = 0 if shelf == "/" else 1 if shelf and parts[0] == shelf else -1      # "/": departments at the top
            if b >= 0 and len(parts) > b + 3 and parts[b] != "Projects":
                k = "/".join(parts[:b + 3]); count[k] = count.get(k, 0) + 1
    return max(count, key=count.get) if count else ""


# ── sending: to Rushes, over the network ────────────────────────────────────
def plain(name):
    name = re.sub(r"[/\\:\t\n\x00-\x1f]+", "-", name).strip().lstrip(".")
    return name[:200] or "file"


def fingerprint(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for buf in iter(lambda: f.read(8 << 20), b""):
            h.update(buf)
    return "sha256:" + h.hexdigest()


CHUNK = 4 << 20        # each piece well under what a web server takes in one request


def upload(cfg, batch, name, path, fp, say=None):
    """One file to Rushes' inbox for this computer, piece by piece, carrying on
    from what has arrived already; Rushes keeps it only when it is whole and
    has the fingerprint taken here."""
    size = os.path.getsize(path)
    have = rushes(cfg, get={"upload": "", "batch": batch, "name": name}).get("have", 0)
    with open(path, "rb") as f:
        f.seek(have)
        while True:
            buf = f.read(CHUNK) if have < size else b""
            q = urllib.parse.urlencode({"upload": "", "batch": batch, "name": name, "offset": have, "size": size, "fp": fp})
            req = urllib.request.Request(f"{cfg['url'].rstrip('/')}/db/watcher.php?{q}", data=buf, method="POST",
                                         headers={"X-Rushes-Watcher": cfg.get("id", ""), "Content-Type": "application/octet-stream"})
            try:
                with urllib.request.urlopen(req, timeout=120) as r:
                    got = json.loads(r.read().decode("utf-8", "replace"))
            except urllib.error.HTTPError as e:
                try: raise RuntimeError(json.loads(e.read().decode("utf-8", "replace"))["error"]) from None
                except (ValueError, KeyError): raise e from None
            if got.get("done"):
                return
            have = got["have"]
            if say and size > 50 << 20:
                say(f"{name}: {have * 100 // size}%")


def deliver(cfg, project, shoot, files, project_bytes=None, project_name=""):
    """One batch: each file sent to Rushes, then its list, so the helper puts
    them in Projects/<this computer>/<project>/. files: [(path, kind)]. -> batch"""
    batch = time.strftime("%Y%m%d-%H%M%S") + "-" + os.urandom(3).hex()      # never the same twice, even in one second
    lines, used = [], set()
    for path, kind in files:
        name, n = plain(os.path.basename(path)), 2
        stem, ext = os.path.splitext(name)
        while name in used:
            name = f"{stem} ({n}){ext}"; n += 1
        used.add(name)
        fp = fingerprint(path)
        upload(cfg, batch, name, path, fp, say=lambda m: say_now("delivering", m))
        lines.append(f"file\t{name}\t{fp}\t{os.path.getsize(path)}\t{kind}\t{path}")
    if project_bytes is not None:
        import tempfile
        with tempfile.NamedTemporaryFile(delete=False) as t:
            t.write(project_bytes)
        try:
            name, fp = plain(project_name), fingerprint(t.name)
            upload(cfg, batch, name, t.name, fp)
            lines.append(f"projectfile\t{name}\t{fp}\t{len(project_bytes)}\t{plain(project)}")
        finally:
            os.remove(t.name)
    rushes(cfg, post={"action": "delivered", "batch": batch, "name": project, "shoot": shoot, "lines": "\n".join(lines)})
    return batch


class Watcher:
    def __init__(self, cfg):
        self.cfg, self.state = cfg, load(STATE, {"projects": {}})
        self.hello, self.hello_at, self.last_scan, self.since, self.said, self.now = None, 0, 0, 0, 0, ""

    def report(self, state, force=False, note=""):
        say_now(state, note)
        if not force and state == self.now and time.time() - self.said < 300:
            return
        self.now, self.said = state, time.time()
        try:
            rushes(self.cfg, post={"action": "report", "state": state, "now": "", "ver": VERSION, "log": "\n".join(log_tail())})
        except Exception:
            pass                                       # said again at the next change, or in five minutes

    def tick(self):
        """One look. Idle (no editing program open): nothing is read but the list of programs."""
        if os.path.exists(PAUSED):
            return self.report("paused")
        open_ = editing()
        if open_ and not self.since:
            self.since = time.time()
            log(f"{', '.join(open_)} open — watching for saved projects")
        if time.time() - self.hello_at > 3600 or not self.hello:
            self.hello, self.hello_at = rushes(self.cfg, get={"hello": ""}), time.time()
            if not self.hello.get("folder"):
                self.hello = None
                raise RuntimeError("Rushes has no folder for this computer: pair it again (Rushes → Setup → Editors' work)")
            self.heard()
        archive = archive_mount(self.hello, self.cfg)
        if open_ and time.time() - self.last_scan >= SCAN_EVERY:
            self.last_scan = time.time()
            self.look(archive)
        # Closed: each project saved while it was open is kept on the NAS, as it is now.
        pending = [p for p, s in self.state["projects"].items() if s.get("touched")]
        if not open_ and self.since:
            self.since = 0
            if pending:
                log("editing program closed — keeping its projects on the NAS")
        if not open_:
            for p in pending:
                self.close(p, archive)
        self.report("watching" if open_ else "idle", note=", ".join(open_))

    def heard(self):
        """Its projects that Rushes says are resting: said in its log, once each."""
        told = self.state.setdefault("told", {})
        for p in self.hello.get("resting") or []:
            if told.get(p) != "resting":
                log(f"{p.split('/')[-1]} is resting: not saved for a while; nothing moves"); told[p] = "resting"
        for p in [p for p in told if p not in (self.hello.get("resting") or [])]:
            del told[p]                                # saved again: said again next time it rests
        save(STATE, self.state)

    def look(self, archive):
        try:
            found = find_projects(archive, self.hello.get("cache") or {})
        except (TimeoutError, OSError) as e:
            return log(f"! {e}; looked at again in {SCAN_EVERY // 60} min")
        for p, mtime in found.items():
            s = self.state["projects"].setdefault(p, {"saved": mtime, "done": 0, "delivered": {}, "output": {}})
            # saved while an editing program was open here, quiet since, and not handled yet
            if mtime > s["done"] and mtime >= self.since - QUIET and time.time() - mtime >= QUIET:
                self.saved(p, mtime, archive)
        save(STATE, self.state)

    def uses(self, p, archive):
        """What a project uses: from outside the archive (and here), and missing."""
        xml, zipped = read_project(p)
        cache = self.hello.get("cache") or {}
        named = files_named(xml)
        outside, missing = [], []
        for f in named:
            if inside(f, archive) or skip(f, cache) or f.endswith(".prproj"):
                continue
            (outside if os.path.isfile(f) else missing).append(f)
        return xml, zipped, named, outside, missing

    def outputs(self, p):
        """Its Output folder, beside the project: made the first time, so exports always have their place."""
        out = os.path.join(os.path.dirname(p), "Output")
        if not os.path.isdir(out):
            try:
                os.mkdir(out); log(f"made the Output folder beside {os.path.basename(p)}: export the finished work there")
            except OSError as e:
                log(f"! could not make the Output folder beside {os.path.basename(p)} ({e})")
            return []
        return [os.path.join(out, n) for n in sorted(os.listdir(out))
                if not n.startswith(".") and os.path.isfile(os.path.join(out, n))]

    def new(self, s, key, files):
        """The files not sent yet, or changed since (size and time)."""
        out = []
        for f in files:
            st = os.stat(f)
            if s[key].get(f) != [st.st_size, int(st.st_mtime)]:
                out.append(f)
        return out

    def sent(self, s, key, files):
        for f in files:
            st = os.stat(f); s[key][f] = [st.st_size, int(st.st_mtime)]

    def saved(self, p, mtime, archive):
        """Saved and quiet: the files it uses that the NAS does not have yet are sent now, so nothing is lost."""
        s, name = self.state["projects"][p], os.path.splitext(os.path.basename(p))[0]
        try:
            _, _, named, outside, missing = self.uses(p, archive)
        except (OSError, EOFError, gzip.BadGzipFile) as e:
            return log(f"! could not read {name} ({e}); tried again after its next save")
        self.outputs(p)
        shoot = shoot_of(named, archive, self.hello.get("shelf") or "")
        new = self.new(s, "delivered", outside)
        if new:
            say_now("delivering", f"{len(new)} file(s) used by {name}")
            try:
                batch = deliver(self.cfg, name, shoot, [(f, kind_of(f)) for f in new])
                self.sent(s, "delivered", new)
                log(f"{name}: {len(new)} file(s) it uses sent to the NAS ({batch})" + "".join(f"\n      {f}" for f in new))
            except Exception as e:
                return log(f"! {name}: could not send its files ({e}); tried again after its next save")
        if missing:
            log(f"! {name}: {len(missing)} file(s) it uses cannot be found:" + "".join(f"\n      {f}" for f in missing[:20]))
        s.update(done=mtime, saved=mtime, touched=True, shoot=shoot)
        try:
            rushes(self.cfg, post={"action": "project", "name": name, "saved": int(mtime), "files": len(named),
                                   "outside": len(outside), "missing": ";".join(os.path.basename(f) for f in missing)[:1900],
                                   "shoot": shoot})
        except Exception as e:
            log(f"! could not tell Rushes about {name} ({e})")

    def close(self, p, archive):
        """The editing program was quit: the project kept on the NAS as it is now,
        a dated copy pointing at the archive's copies of what it uses, and what is
        new in Output. The project on this Mac is only read, never changed."""
        s, name = self.state["projects"][p], os.path.splitext(os.path.basename(p))[0]
        say_now("delivering", name)
        try:
            xml, zipped, named, outside, missing = self.uses(p, archive)
            new = self.new(s, "delivered", outside)
            outs = self.new(s, "output", self.outputs(p))
            if new or outs:
                batch = deliver(self.cfg, name, s.get("shoot", ""), [(f, kind_of(f)) for f in new] + [(f, "output") for f in outs])
                self.sent(s, "delivered", new); self.sent(s, "output", outs)
                log(f"{name}: {len(new)} file(s) it uses and {len(outs)} from Output sent to the NAS ({batch})")
            got = rushes(self.cfg, get={"where": "", "project": name}).get("files") or {}
            waiting = [f for f in outside if f not in got]
            if waiting and time.time() - s.get("close_at", time.time()) < 3600:
                s.setdefault("close_at", time.time())
                return                                 # its files are still being put in place: the project waits for them
            to = {o: os.path.join(archive, f["rel"]) for o, f in got.items()}
            fixed, n = repoint(xml, to)
            data = fixed.encode("utf-8")
            batch = deliver(self.cfg, name, s.get("shoot", ""), [], project_bytes=gzip.compress(data) if zipped else data,
                            project_name=os.path.basename(p))
            log(f"{name}: a copy of the project is kept on the NAS ({batch}), pointing at the NAS for {n} file(s)"
                + (f"; {len(waiting)} were not there yet and still point at this Mac" if waiting else "")
                + ("" if self.outputs(p) else ". Nothing in Output yet: export the finished work into the Output folder beside it"))
            s.update(touched=False, done=os.stat(p).st_mtime)
            s.pop("close_at", None)
        except Exception as e:
            log(f"! {name}: could not be kept on the NAS ({e}); tried again in a minute")
        save(STATE, self.state)


def run(once=False):
    cfg = load(CONFIG, {})
    while not cfg.get("id"):
        # Waits, looking at nothing, until it is paired (in its window); said once.
        if once:
            sys.exit("Not paired yet: in Rushes → Setup → Editors' work, get a code, then\n"
                     "  python3 rushes_watcher.py pair <rushes address> <code>")
        if not os.path.exists(os.path.join(HOME, "now.json")) or load(os.path.join(HOME, "now.json"), {}).get("state") != "unpaired":
            log("not paired with Rushes yet — open Rushes Watcher to pair it")
        say_now("unpaired")
        time.sleep(30); cfg = load(CONFIG, {})
    w, fails = Watcher(cfg), 0
    log(f"Rushes Watcher {VERSION} started, for Rushes at {cfg['url']}")
    while True:
        try:
            w.tick(); fails = 0
        except Exception as e:                         # Rushes not answering: asked less and less (rule 4)
            fails += 1
            later = min(EVERY * 2 ** fails, 900)
            if fails in (1, 3, 10):
                log(f"! cannot reach Rushes ({e}) — asking again in {later // 60 or 1} min")
            say_now("offline", f"asking again in {later // 60 or 1} min")
            w.hello = None
            if once: raise
            time.sleep(later); continue
        if once: return w
        time.sleep(EVERY)


def selftest():
    xml = ('<PremiereData><Media><ActualMediaFilePath>/Users/ed/Music/A &amp; B.wav</ActualMediaFilePath>'
           '<FilePath>/Volumes/VIDEO/Shelf/x.mov</FilePath><Title>Not a path</Title>'
           '<FilePath>/Users/ed/Adobe Premiere Pro Video Previews/p.mov</FilePath></Media></PremiereData>')
    assert files_named(xml) == ["/Users/ed/Adobe Premiere Pro Video Previews/p.mov", "/Users/ed/Music/A & B.wav",
                                "/Volumes/VIDEO/Shelf/x.mov"], files_named(xml)
    out, n = repoint(xml, {"/Users/ed/Music/A & B.wav": "/Volumes/VIDEO/Shelf/Projects/Stock Library/Music/A & B.wav"})
    assert n == 1 and ">/Volumes/VIDEO/Shelf/Projects/Stock Library/Music/A &amp; B.wav<" in out
    assert skip("/Users/ed/Adobe Premiere Pro Video Previews/p.mov", {}) and skip("/x/a.pek", {"sweep": [{"ext": ["pek"]}]})
    assert shoot_of(["/Volumes/VIDEO/S/PARKS/2026/Kite/a.mov", "/Volumes/VIDEO/S/PARKS/2026/Kite/b.mov",
                     "/Volumes/VIDEO/S/PARKS/2025/Old/c.mov", "/Users/ed/x.wav"], "/Volumes/VIDEO", "S") == "S/PARKS/2026/Kite"
    assert [kind_of(p) for p in ("/d/song.mp3", "/d/SFX/boom.wav", "/d/VO take 2.wav", "/d/Artlist/drone.mov", "/d/title.png")] \
        == ["music", "sfx", "project", "stock", "project"]
    print("watcher: all checks pass")
    return 0


def main(argv):
    if argv[:1] == ["--selftest"]: return selftest()
    if argv[:1] == ["pair"] and len(argv) == 3:
        try: pair(argv[1], argv[2]); print("paired ✓"); return 0
        except Exception as e: print(f"not paired: {e}"); return 1
    if argv[:1] in (["run"], ["once"]):
        try: run(once=argv[0] == "once"); return 0
        except KeyboardInterrupt: print("\nRushes Watcher stopped."); return 130
    print(__doc__); return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
