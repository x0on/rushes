# Rushes — Media Management Software, by Alejandro Renteria.
# Source available: https://github.com/x0on/rushes — whoever finds this file on a computer can see what it is and who made it.
"""Rushes Watcher — on each editor's computer (HOW-IT-WORKS.md → Projects in and out).

It keeps a project and everything it uses, without anyone pressing anything:

- **On save.** While an editing program is open, it looks for projects saved
  on the Projects share. Once one has been quiet a few minutes, it copies the
  files the project uses from outside the archive (music, stock, downloads,
  graphics, voiceover) into its own folder of the Deliveries share, and tells
  Rushes. The helper takes them into the archive. The project is only read.
- **On close.** When the editing program is quit, each project it saved is
  pointed at the archive's copies (a backup of the project first, beside it,
  in "Rushes backups"), and a copy of the project file goes into the archive.

Every step is written in its log, which the editor can read, and which Rushes
shows on Manage → Editors' projects. It has no archive code and no models: it
only reads projects and copies files. Nothing here deletes anything.

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

VERSION = "1"
HOME = os.environ.get("RUSHES_WATCHER_HOME") or os.path.expanduser("~/Library/Application Support/Rushes Watcher")
LOG = os.environ.get("RUSHES_WATCHER_LOG") or os.path.expanduser("~/Library/Logs/Rushes Watcher/watcher.log")
CONFIG, STATE = os.path.join(HOME, "config.json"), os.path.join(HOME, "state.json")
PAUSED = os.path.join(HOME, "paused")          # the menu's Pause watching (P3); a file, so it outlives a restart

EVERY = 20             # s between looks at which programs are open (on this computer only)
SCAN_EVERY = 120       # s between looks at the Projects share, only while an editing program is open
QUIET = 180            # s a saved project must stay unchanged before its files are copied
SCAN_LIMIT = 60        # s a look at the Projects share may take; then it stops and says so
EDITORS = ("Adobe Premiere Pro", "Final Cut Pro", "DaVinci Resolve")
AUDIO = {"wav", "mp3", "aif", "aiff", "m4a", "flac", "ogg", "aac"}
# ponytail: guessed from the path; a menu to correct a file's kind comes with the icon (P3)
STOCK_HINTS = r"(stock|artlist|pond5|storyblocks|envato|shutterstock|getty|motion ?array|epidemic)"
SKIP_PARTS = ("Adobe Premiere Pro Video Previews", "Adobe Premiere Pro Audio Previews",
              "Adobe Premiere Pro Auto-Save", "Media Cache", "/.Trash", "/Rushes backups/")


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
def mounts(hello, cfg):
    """The three shares, as this Mac sees them: /Volumes/<name>, unless the
    config says otherwise (a share mounted under another name)."""
    v = cfg.get("volumes") or "/Volumes"
    return {k: (cfg.get(k) or (os.path.join(v, hello[k]) if hello.get(k) else ""))
            for k in ("archive", "projects", "deliveries")}


def inside(p, top):
    return bool(top) and (p == top or p.startswith(top.rstrip("/") + "/"))


def editing():
    """Which editing programs are open here. Asked of this computer only."""
    try:
        out = subprocess.run(["ps", "-axo", "comm"], capture_output=True, text=True, timeout=10).stdout
    except (OSError, subprocess.SubprocessError):
        return []
    return sorted({e for e in EDITORS if e in out})


def find_projects(top, cache_rules, limit=SCAN_LIMIT):
    """Every Premiere project on the Projects share: {path: mtime}. Hidden
    folders, caches, previews and auto-saves are not looked into.
    ponytail: a walk of the share each look; FSEvents from the app (P3) when shares grow large."""
    found, until = {}, time.monotonic() + limit
    for d, dirs, files in os.walk(top):
        if time.monotonic() > until:
            raise TimeoutError(f"looking through {top} took more than {limit} s")
        dirs[:] = [x for x in dirs if not x.startswith(".") and not skip(os.path.join(d, x) + "/", cache_rules)]
        for f in files:
            if f.endswith(".prproj") and not f.startswith("."):
                p = os.path.join(d, f)
                try: found[p] = os.stat(p).st_mtime
                except OSError: pass
    return found


def skip(path, cache_rules):
    """Caches, previews and auto-saves: they rebuild themselves, so they are never delivered."""
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
    """Every file path the project names (its media, graphics, music …)."""
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


def shoot_of(project_rel, where_, hello):
    """The shoot a project belongs to: its folder on the Projects share has the
    same shape as the archive, on the shelf or not. '' when none matches."""
    parts = project_rel.split("/")[:-1]
    shelf = (hello.get("shelf") or "").strip("/")
    while parts:
        rel = "/".join(parts)
        for cand in ([f"{shelf}/{rel}"] if shelf else []) + [rel]:
            if where_["archive"] and os.path.isdir(os.path.join(where_["archive"], cand)):
                return cand
        parts.pop()
    return ""


# ── delivering ──────────────────────────────────────────────────────────────
def plain(name):
    name = re.sub(r"[/\\:\t\n\x00-\x1f]+", "-", name).strip().lstrip(".")
    return name[:200] or "file"


def copy_in(src, dest):
    """Copied with its fingerprint taken on the same read, and stored on the
    share's disk before it counts. -> 'sha256:<hex>'"""
    h = hashlib.sha256()
    with open(src, "rb") as fi, open(dest + ".part", "wb") as fo:
        for buf in iter(lambda: fi.read(8 << 20), b""):
            fo.write(buf); h.update(buf)
        fo.flush(); os.fsync(fo.fileno())
    shutil.copystat(src, dest + ".part")
    os.replace(dest + ".part", dest)
    return "sha256:" + h.hexdigest()


def deliver(cfg, hello, where_, project_rel, shoot, files, project_file=None):
    """One batch into Deliveries/<key>/<batch>, batch.tsv written last, and
    Rushes told. files: [(original path, kind)]. -> batch name"""
    batch = time.strftime("%Y%m%d-%H%M%S") + "-" + hashlib.sha256(project_rel.encode()).hexdigest()[:6]
    folder = os.path.join(where_["deliveries"], hello["key"], batch)
    os.makedirs(os.path.join(folder, "files"))
    lines, used = ["rushes-delivery 1", f"watcher\t{hello['key']}", f"host\t{plain(platform.node().split('.')[0])}",
                   f"project\t{project_rel}", f"shoot\t{shoot}"], set()
    for orig, kind in files:
        name, n = plain(os.path.basename(orig)), 2
        stem, ext = os.path.splitext(name)
        while name in used:
            name = f"{stem} ({n}){ext}"; n += 1
        used.add(name)
        fp = copy_in(orig, os.path.join(folder, "files", name))
        lines.append(f"file\t{name}\t{fp}\t{os.path.getsize(os.path.join(folder, 'files', name))}\t{kind}\t{orig}")
    if project_file:
        name = plain(os.path.basename(project_file))
        fp = copy_in(project_file, os.path.join(folder, "files", name))
        lines.append(f"projectfile\t{name}\t{fp}\t{os.path.getsize(os.path.join(folder, 'files', name))}\t"
                     + plain(os.path.splitext(os.path.basename(project_file))[0]))
    with open(os.path.join(folder, "batch.tsv.part"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines + ["end"]) + "\n")
    os.replace(os.path.join(folder, "batch.tsv.part"), os.path.join(folder, "batch.tsv"))
    rushes(cfg, post={"action": "delivered", "batch": batch})
    return batch


class Watcher:
    def __init__(self, cfg):
        self.cfg, self.state = cfg, load(STATE, {"projects": {}})
        self.hello, self.hello_at, self.last_scan, self.since, self.said, self.now = None, 0, 0, 0, 0, ""

    def report(self, state, force=False):
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
        where_ = mounts(self.hello, self.cfg)
        if open_ and time.time() - self.last_scan >= SCAN_EVERY:
            self.last_scan = time.time()
            self.look(where_)
        if not open_ and self.since:
            self.since = 0
            log("editing program closed — pointing its projects at the archive")
            for p in [p for p, s in self.state["projects"].items() if s.get("touched")]:
                self.close(p, where_)
        self.report("watching" if open_ else "idle")

    def look(self, where_):
        if not where_["projects"] or not os.path.isdir(where_["projects"]):
            return log(f"cannot see the Projects share ({where_['projects'] or 'not set in Rushes'}) — is it connected?")
        try:
            found = find_projects(where_["projects"], self.hello.get("cache") or {})
        except (TimeoutError, OSError) as e:
            return log(f"! {e}; looked at again in {SCAN_EVERY // 60} min")
        for p, mtime in found.items():
            s = self.state["projects"].setdefault(p, {"saved": mtime, "done": 0, "delivered": {}})
            # saved while an editing program was open here, quiet since, and not handled yet
            if mtime > s["done"] and mtime >= self.since - QUIET and time.time() - mtime >= QUIET:
                self.saved(p, mtime, where_)
        save(STATE, self.state)

    def saved(self, p, mtime, where_):
        s, rel = self.state["projects"][p], os.path.relpath(p, where_["projects"]).replace(os.sep, "/")
        try:
            xml, _ = read_project(p)
        except (OSError, EOFError, gzip.BadGzipFile) as e:
            return log(f"! could not read {rel} ({e}); tried again after its next save")
        cache = self.hello.get("cache") or {}
        outside, missing = [], []
        for f in files_named(xml):
            if inside(f, where_["archive"]) or inside(f, where_["deliveries"]) or skip(f, cache) or f.endswith(".prproj"):
                continue
            (outside if os.path.isfile(f) else missing).append(f)
        if missing and not outside and not s.get("touched"):
            s["done"] = mtime                          # another computer's project: its files are there, not here
            return
        shoot = shoot_of(rel, where_, self.hello)
        new = []
        for f in outside:
            st = os.stat(f)
            if s["delivered"].get(f) != [st.st_size, int(st.st_mtime)]:
                new.append((f, kind_of(f)))
        if new:
            try:
                batch = deliver(self.cfg, self.hello, where_, rel, shoot, new)
                for f, _ in new:
                    st = os.stat(f); s["delivered"][f] = [st.st_size, int(st.st_mtime)]
                log(f"{rel}: {len(new)} file(s) from outside the archive copied to Deliveries ({batch})"
                    + "".join(f"\n      {k}: {f}" for f, k in new))
            except Exception as e:
                return log(f"! {rel}: could not deliver its files ({e}); tried again after its next save")
        if missing:
            log(f"! {rel}: {len(missing)} file(s) it uses cannot be found:" + "".join(f"\n      {f}" for f in missing[:20]))
        s.update(done=mtime, saved=mtime, touched=True, shoot=shoot)
        try:
            rushes(self.cfg, post={"action": "project", "path": rel, "name": os.path.splitext(os.path.basename(p))[0],
                                   "saved": int(mtime), "files": len(outside) + len(missing), "outside": len(outside),
                                   "missing": ";".join(os.path.basename(f) for f in missing)[:1900], "shoot": shoot})
        except Exception as e:
            log(f"! could not tell Rushes about {rel} ({e})")

    def close(self, p, where_):
        """The project, pointed at the archive's copies (a backup first), and a copy into the archive."""
        s, rel = self.state["projects"][p], os.path.relpath(p, where_["projects"]).replace(os.sep, "/")
        try:
            got = rushes(self.cfg, get={"where": "", "project": rel}).get("files") or {}
            before = os.stat(p).st_mtime
            xml, zipped = read_project(p)
            to = {o: os.path.join(where_["archive"], f["rel"]) for o, f in got.items()
                  if os.path.isfile(os.path.join(where_["archive"], f["rel"]))}
            fixed, n = repoint(xml, to)
            if n:
                keep = os.path.join(os.path.dirname(p), "Rushes backups")
                os.makedirs(keep, exist_ok=True)
                backup = os.path.join(keep, f"{os.path.splitext(os.path.basename(p))[0]} {time.strftime('%Y-%m-%d %H%M')}.prproj")
                shutil.copy2(p, backup)
                if os.stat(p).st_mtime != before:
                    return log(f"{rel} was saved again meanwhile — pointed at the archive after its next save")
                data = fixed.encode("utf-8")
                with open(p + ".rushes-new", "wb") as f:
                    f.write(gzip.compress(data) if zipped else data)
                    f.flush(); os.fsync(f.fileno())
                os.replace(p + ".rushes-new", p)
                log(f"{rel}: {n} file(s) now point at the archive's copies; the project as it was is in Rushes backups")
            batch = deliver(self.cfg, self.hello, where_, rel, s.get("shoot", ""), [], project_file=p)
            log(f"{rel}: a copy of the project goes into the archive ({batch})")
            s.update(touched=False, done=os.stat(p).st_mtime)
        except Exception as e:
            log(f"! {rel}: could not be pointed at the archive ({e}); tried again when the editing program is next closed")
        save(STATE, self.state)


def run(once=False):
    cfg = load(CONFIG, {})
    if not cfg.get("id"):
        sys.exit("Not paired yet: in Rushes → Setup → Editors' computers, get a code, then\n"
                 "  python3 rushes_watcher.py pair <rushes address> <code>")
    w, fails = Watcher(cfg), 0
    log(f"Rushes Watcher {VERSION} started, for Rushes at {cfg['url']}")
    while True:
        try:
            w.tick(); fails = 0
        except Exception as e:                         # Rushes not answering: asked less and less (rule 4)
            fails += 1
            if fails in (1, 3, 10):
                log(f"! cannot reach Rushes ({e}) — asking again in {min(EVERY * 2 ** fails, 900) // 60 or 1} min")
            w.hello = None
            if once: raise
            time.sleep(min(EVERY * 2 ** fails, 900)); continue
        if once: return w
        time.sleep(EVERY)


def selftest():
    xml = ('<PremiereData><Media><ActualMediaFilePath>/Users/ed/Music/A &amp; B.wav</ActualMediaFilePath>'
           '<FilePath>/Volumes/VIDEO/Shelf/x.mov</FilePath><Title>Not a path</Title>'
           '<FilePath>/Users/ed/Adobe Premiere Pro Video Previews/p.mov</FilePath></Media></PremiereData>')
    assert files_named(xml) == ["/Users/ed/Adobe Premiere Pro Video Previews/p.mov", "/Users/ed/Music/A & B.wav",
                                "/Volumes/VIDEO/Shelf/x.mov"], files_named(xml)
    out, n = repoint(xml, {"/Users/ed/Music/A & B.wav": "/Volumes/VIDEO/Stock Library/Music/A & B.wav"})
    assert n == 1 and ">/Volumes/VIDEO/Stock Library/Music/A &amp; B.wav<" in out
    assert skip("/Users/ed/Adobe Premiere Pro Video Previews/p.mov", {}) and skip("/x/a.pek", {"sweep": [{"ext": ["pek"]}]})
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
