# Rushes — Media Management Software, by Alejandro Renteria.
# Source available: https://github.com/x0on/rushes
"""The minute's work, for a Rushes whose archive is a drive on this computer.

runner.sh does this on a NAS, as root, from cron. On a Mac, the Rushes app runs
Rushes itself (rushes_helper.py --server) and calls Runner.minute() once a
minute. Same files in the web folder, same rules (HOW-IT-WORKS.md → The
minute's work); only what a drive on this computer needs:

  - the heartbeat (runner-alive.txt), free space (disk.txt), load (load.txt)
  - the search update (import.php ?part=web, then ?part=video)
  - the daily database copy onto the archive (_rushes/db-copies)
  - the private-file check (exposed.txt)
  - the queue: Rebuild the file list (reindex, manifest), Try again (reset-breaker);
    and the first file list of a new archive, by itself
  - duplicates: the scan (here, in Python: no container), the plan, moving the
    copies aside, putting them back, checking the holding folder (dedupe.sh and
    verify.sh, as on a NAS)
  - editing caches: moved aside, or deleted on a person's own drives (Setup)
  - drives that come and go (Setup 01: Leave media on its own drives): each
    known by its own ID, listed where it is, its last list kept while it is
    away, and listed again when it comes back (drives.json, drives/)

Every touch of the archive has a time limit, and three stalls in a row stop
it touching the archive until Try again — the same breaker as runner.sh.
Proxies are made here too (proxies(), as proxy.sh does on a NAS, with the
same records), on the Mac's media engine, in software when it cannot.
"""
import hashlib, json, os, platform, re, shutil, sqlite3, subprocess, tempfile, threading, time, urllib.request
import transfer_state as ts          # the careful file operations (beside this file, in the app and in _rushes)

HERE = os.path.dirname(os.path.abspath(__file__))       # dedupe.sh and verify.sh are beside this file

SKIP = {"@Recycle", ".Trashes", ".Spotlight-V100", ".fseventsd", ".DocumentRevisions-V100",
        ".TemporaryItems", ".DS_Store_cache"}
TRIM = ("helper.log", "proxy.log", "proxy-built.tsv", "proxy-built.tsv.err", "proxy-speed.tsv", "proxy-failed.tsv", "php-errors.log")
# Where Remove puts duplicate copies and caches on the archive and on each drive, nothing deleted:
# Recently Removed, as in Photos (Manage → Duplicates and Cache say so). Called _duplicates before
# 0.12.4: that folder is renamed by itself (migrate_holding), and the records of what moved follow.
HOLD, OLD_HOLD = "_Recently Removed", "_duplicates"
VIDEO_EXT = (".mxf", ".mov", ".mp4", ".avi", ".mts", ".m4v", ".braw", ".r3d")     # the same list as proxy.sh


def broken(path, size, head=None):
    """Rescue: why a file is broken, or None. Only clips and their XML sidecars are judged:
    empty; too small to be a clip; or not starting the way its kind must (an MP4 or MOV with "ftyp", "moov"…
    in its first bytes, an MXF with its key, an XML with "<"): a 1 KB "MP4", or an XML holding a video's
    bytes, as a damaged disk leaves them. Only the first 16 bytes are read (head: already read, over SSH)."""
    ext = os.path.splitext(path)[1].lower()
    if ext not in VIDEO_EXT + (".xml", ".m2ts"):
        return None
    if size == 0:
        return "empty: zero bytes"
    h = head
    if h is None:
        try:
            with open(path, "rb") as f:
                h = f.read(16)
        except OSError as e:
            return f"cannot be read ({e.strerror or e})"
    if ext in (".mts", ".m2ts") and h and all(32 <= c < 127 or c in b"\t\r\n" for c in h):
        return None                    # plain text: a TypeScript module (.d.mts), not a camera's clip
    ok = {".mp4": lambda: h[4:8] in (b"ftyp", b"moov", b"mdat", b"wide", b"free", b"skip", b"pnot"),
          ".mxf": lambda: h[:4] == b"\x06\x0e\x2b\x34",
          ".avi": lambda: h[:4] == b"RIFF",
          ".mts": lambda: h[:1] == b"\x47" or h[4:5] == b"\x47",
          ".xml": lambda: h.lstrip(b"\xef\xbb\xbf \t\r\n")[:1] == b"<"}
    ok[".mov"] = ok[".m4v"] = ok[".mp4"]; ok[".m2ts"] = ok[".mts"]
    small = ext != ".xml" and size < 65536
    if ext in ok and not ok[ext]():
        return f"too small to be a clip ({size:,} bytes)" if small else \
            "what is inside is not " + ("XML" if ext == ".xml" else f"a {ext[1:].upper()} clip") + " (another file's data, or damaged)"
    # a small file that starts as its kind must is a real one (a sound effect of 49 KB); below 4 KB, or of a kind
    # whose start is not checked, small is still too small
    if small and (size < 4096 or ext not in ok):
        return f"too small to be a clip ({size:,} bytes)"
    return None


# A card's own folders, the same on every card: a match there says nothing about which shoot it is
CARD_FOLDERS = {"clip", "clips", "clpr", "sub", "private", "m4root", "xdroot", "bpav", "avchd", "bdmv", "stream",
                "dcim", "general", "data", "contents", "video", "videos", "media", "footage", "thmbnl", "take"}


def card_folder(n):
    n = n.lower()
    return n in CARD_FOLDERS or re.fullmatch(r"\d{3}[a-z_]*|(cam|card|camera|a|b)[ _-]?\d{1,3}", n) is not None


def same_folders(c, p):
    """Rescue: how many named folders, up from the file, a candidate shares with the broken file (stopping at
    the first that differs); a card's own folders (CLIP, PRIVATE, CAM 2) are passed over, not counted."""
    q, r, k, i = c.split("/"), p.split("/"), 0, 2
    while i <= min(len(q), len(r)) - 1 and q[-i] == r[-i]:
        k += not card_folder(q[-i]); i += 1
    return k


def camera_name(p):
    """A name a camera gives, the same on every card: a counter in it and no spaces (Clip0004, C0051M01,
    DJI_0128, FX30.0944, A069C942_2510161F_CANON, IMG_6583). An editor's export has words (FIU PROMO v2)."""
    stem = os.path.splitext(os.path.basename(p))[0]
    return " " not in stem and re.search(r"\d{3}", stem) is not None


# ── a server over SSH (Rescue): looked at and read from, never written to ──────────────────────────
# Only with the Mac's own SSH login (a key it already has): BatchMode, so it never waits for a password.
SSH_OPTS = ["-o", "BatchMode=yes", "-o", "ConnectTimeout=20", "-o", "ServerAliveInterval=30"]
SSH_SKIP = r"\( -name '.*' -o -name '@Recycle' -o -name '@Recently-Snapshot' -o -name '.@__thumb' -o -name '_duplicates' -o -name '_Recently Removed' \) -prune -o"


def ssh_ok(host, path):
    """A server as Setup names it: user@address and an absolute folder, nothing a shell would read twice."""
    return bool(re.fullmatch(r"[A-Za-z0-9._][A-Za-z0-9._-]*@[A-Za-z0-9._:-]+|[A-Za-z0-9._][A-Za-z0-9._:-]*", host or "")) and \
        bool(re.fullmatch(r"/[^\x00-\x1f'\"`$\\]*", path or "")) and ".." not in path.split("/")


def ssh_heads(host, paths):
    """The first 16 bytes of each file on the server, in one connection -> ({path: bytes or None}, what ssh said).
    Only dd, wc and printf there: a NAS has a cut-down set of commands (BusyBox), whose od may not take the
    options a Mac's does. Each file comes back as a line, its byte count and path, then those bytes as they are."""
    script = ('while IFS= read -r f; do n=$(dd if="$f" bs=16 count=1 2>/dev/null | wc -c); '
              'printf \'%s\\t%s\\n\' "$((n+0))" "$f"; dd if="$f" bs=16 count=1 2>/dev/null; done')
    r = subprocess.run(["ssh", *SSH_OPTS, "--", host, script], capture_output=True, timeout=3600,
                       input=("\n".join(paths) + "\n").encode("utf-8", "surrogateescape"))
    out, b, i, asked = {}, r.stdout, 0, set(paths)
    while i < len(b):
        j = b.find(b"\n", i)
        n, _, p = b[i:j if j >= 0 else len(b)].partition(b"\t")
        p = p.decode("utf-8", "surrogateescape")
        if j < 0 or not n.strip().isdigit() or p not in asked:
            break                      # out of step: what is read so far stands, the rest counts as not read
        n = int(n); out[p] = b[j + 1:j + 1 + n] or None; i = j + 1 + n
    return out, r.stderr.decode("utf-8", "replace").strip()[-300:]


PROBE_WAIT = 300       # ffmpeg's report of a file that does not come in 5 minutes: none (probe)
CHIP_STALL = 180       # on the media engine, nothing for 3 minutes: on to software (encode)
STALL = 900            # a proxy that grows by nothing for 15 minutes is given up (encode)
RECENT = 7200          # a file written in the last two hours may still be arriving: its proxy waits for a later run
PRIVATE = ("rushes.sqlite", "db-copy.sqlite", "ingest-queue.tsv", "helper-refused.tsv", "activity.tsv")


def archive_ok(a, web):
    """An archive: a folder that exists, at least two deep, never a system folder, never the web folder."""
    bad = ("/System", "/Library", "/usr", "/bin", "/sbin", "/etc", "/private", "/Applications", "/dev", "/Users/Shared")
    return (a.startswith("/") and a.count("/") >= 2 and "/." not in a and ".." not in a.split("/")
            and not a.startswith(bad) and a != web and not a.startswith(web + "/") and os.path.isdir(a))


class Halted(Exception):
    """A watched read stopped between pieces (Pause)."""


class Runner:
    def __init__(self, web, url, limit=20):
        self.web, self.url, self.limit = web, url.rstrip("/"), limit
        self.busy = {}                                   # "upkeep" / "jobs": the thread doing it
        self.pending = {}                                # what the archive was asked and has not answered yet → its thread
        self.quiet = 120                                 # a long read with no data for this long is walked away from (watched)
        self.fresh = True                                # the first minute looks for a job a restart cut short (interrupted)

    # ── small things ─────────────────────────────────────────────────────────
    def p(self, name):
        return os.path.join(self.web, name)

    def write(self, name, text):
        with open(self.p(name) + ".new", "w", encoding="utf-8", errors="surrogateescape") as f:   # a name in another encoding is kept as it is
            f.write(text)
        os.replace(self.p(name) + ".new", self.p(name))

    def log(self, line):
        f = self.p("job.log")
        try:
            if os.path.getsize(f) > 2_000_000:          # keep the newest half megabyte
                with open(f, "rb") as h:
                    h.seek(-500_000, 2); tail = h.read()
                with open(f, "wb") as h:
                    h.write(tail[tail.find(b"\n") + 1:])
        except OSError:
            pass
        with open(f, "a") as h:
            h.write(line + "\n")

    def say(self, line):
        self.log(time.strftime("%Y-%m-%d %H:%M:%S  ") + line)

    def activity(self, kind, text, who=""):
        """One line in Activity (db/activity.php reads activity.tsv): drives coming and going, and
        what a job asked for in Manage did (who: the name of whoever asked, from the job)."""
        try:
            with open(self.p("activity.tsv"), "a") as f:
                f.write(f"{time.strftime('%Y-%m-%d %H:%M:%S')}\t{kind}\t{who}\t{text}\n")
        except OSError:
            pass

    def arch(self):
        """The archive, from settings.json: a folder that exists, never a system folder, never the web folder."""
        try:
            with open(self.p("settings.json")) as f:
                a = json.load(f)["archive"]["local"].rstrip("/")
        except (OSError, ValueError, KeyError, TypeError, AttributeError):
            return None
        return a if archive_ok(a, self.web) else None

    def get(self, path, timeout):
        with urllib.request.urlopen(self.url + path, timeout=timeout) as r:
            return r.read().decode("utf-8", "replace")

    # ── reaching the archive: with a time limit, and a breaker ──────────────
    def tripped(self):
        return os.path.exists(self.p("video-tripped.txt"))

    def may_v(self):
        try:
            paused = '"paused":true' in open(self.p("helper-control.json")).read().replace(" ", "")
        except OSError:
            paused = False
        return not paused and not self.stop_asked() and not self.tripped() and self.arch() is not None

    def stop_asked(self):
        """Stop, from the top bar (db/stop.php writes job-stop.txt with the job's name): only the job it was
        pressed for stops, at its next safe point; the file goes when that job ends."""
        now = getattr(self, "now_job", None)
        try:
            return bool(now) and open(self.p("job-stop.txt")).read().strip() == now
        except OSError:
            return False

    def v(self, what, fn, limit=None):
        """fn() in a thread, waited for at most `limit` seconds; one that has not
        finished is walked away from and counted. True when it finished.
        Walked away from is not finished: it may still happen (a copy, a move).
        Until it does, the same thing is not started again beside it."""
        old = self.pending.get(what)
        if old is not None and old.is_alive():
            # Still out: not started again beside it, and counted as the archive not answering
            return self.count_stall(f"{what}: the last one has not finished yet, so it is not started again until it does")
        self.pending.pop(what, None)
        out = {}
        def go():
            try: out["ok"] = fn() is not False
            except Exception as e: out["why"] = e          # answered, with an error: not a stall
        t = threading.Thread(target=go, daemon=True)
        t.start(); t.join(limit or self.limit)
        if not t.is_alive():
            if "why" in out: self.say(f"{what}: {out['why']}")
            return out.get("ok", False)
        self.pending[what] = t
        return self.count_stall(f"the archive did not answer within {limit or self.limit}s ({what})", what)

    def watched(self, what, fn):
        """A long read (every byte of a file) in a thread of its own: fn(tick), where tick() is
        called after each piece read. Waited for as long as data keeps coming, however big the
        file; one that gets no data for `quiet` seconds (a disk that answers what is in a folder
        but stalls reading a file) is walked away from, counted as the archive not answering,
        and not started again beside itself (pending). Pause stops it between pieces.
        -> (finished, fn's answer); fn's own error (OSError: unreadable) is raised here."""
        old = self.pending.get(what)
        if old is not None and old.is_alive():
            self.count_stall(f"{what}: the last read has not finished yet, so it is not started again until it does")
            return False, None
        self.pending.pop(what, None)
        last, out, halt = [time.time()], {}, threading.Event()
        def tick():
            last[0] = time.time()
            if halt.is_set():
                raise Halted()
        def go():
            try: out["v"] = fn(tick)
            except Halted: pass
            except Exception as e: out["e"] = e
        t = threading.Thread(target=go, daemon=True)
        t.start()
        while t.is_alive():
            t.join(1)
            if t.is_alive() and not self.may_v():
                halt.set()                               # paused: stops at its next piece
            if t.is_alive() and time.time() - last[0] > self.quiet:
                self.pending[what] = t
                self.count_stall(f"the archive gave no data for {self.quiet}s ({what})", what)
                return False, None
        if "e" in out:
            raise out["e"]
        return "v" in out, out.get("v")

    def count_stall(self, said, what=""):
        """One more time in a row the archive did not answer; the third stops touching it. -> False"""
        what = what or said.split(":")[0]
        self.stalled = True
        try: n = int(open(self.p("video-stalls.txt")).read()) + 1
        except (OSError, ValueError): n = 1
        self.write("video-stalls.txt", str(n))
        self.say(f"{said} — {n} in a row")
        if n >= 3 and not self.tripped():
            self.write("video-tripped.txt", f"{int(time.time())}\tthe archive did not answer {n} times in a row (last: {what})\n")
            self.say("STOPPED touching the archive until Try again is pressed in Manage")
        return False

    # ── copying goes first ───────────────────────────────────────────────────
    def copying(self):
        """The helper is at work on the disks right now (its live status, ingest-status.tsv,
        said in the last 10 minutes). Reading every byte of thousands of files beside it
        (Find duplicates, Delete All's comparing) would slow the copy and keep it longer at
        risk of a drive dropping mid-file."""
        try:
            if time.time() - os.path.getmtime(self.p("ingest-status.tsv")) > 600:
                return False
            with open(self.p("ingest-status.tsv")) as f:
                phase = next((l.split("\t", 1)[1].strip() for l in f if l.startswith("phase\t")), "")
        except OSError:
            return False
        return phase not in ("", "waiting", "idle", "paused", "stopped", "blocked", "done", "planned")

    def make_way(self):
        """Heavy reading waits while the helper copies, and says so; looked at every few seconds."""
        if time.time() - getattr(self, "_way_at", 0) < 5:
            return
        said = False
        while self.copying() and self.may_v():
            if not said:
                self.log("  waiting while copying uses the disk — this carries on after"); said = True
            time.sleep(10)
        self._way_at = time.time()
        if said:
            self.log("  the copy is done: carrying on")

    # ── drives that come and go ──────────────────────────────────────────────
    def settings(self):
        try:
            with open(self.p("settings.json")) as f:
                return json.load(f)
        except (OSError, ValueError):
            return {}

    def drives(self):
        """The drives catalogued where they are: those in Setup 03's list kept where
        they are (each drive's own choice when added; Setup 01's for older ones). Each is known by its volume's own ID, not
        its name: a drive plugged in under another name (or "X 1") is found by
        its ID, and another drive that takes its name is not taken for it.
        -> [{source, name, id, path, connected, seen}] (drives.json)."""
        s, a = self.settings(), self.arch()
        shape = (s.get("organise") or {}).get("shape")    # a drive added before it was asked how follows Setup 01
        try:
            known = {d["source"]: d for d in json.load(open(self.p("drives.json")))}
        except (OSError, ValueError, KeyError, TypeError):
            known = {}
        out = []
        for src in s.get("sources") or []:
            if not isinstance(src, dict) or src.get("how", shape) != "in_place":
                continue                                   # a place to copy from, not searched where it is
            p = str(src.get("path", "")).rstrip("/")
            if not p.startswith("/") or ".." in p.split("/") or (a and (p == a or p.startswith(a + "/") or a.startswith(p + "/"))):
                continue                                   # not a drive of its own: the archive, or inside it
            was = known.get(p, {})
            d = {"source": p, "name": src.get("label") or os.path.basename(p), "id": was.get("id", ""),
                 "path": was.get("path") or p, "connected": False, "seen": was.get("seen", 0)}
            here = None
            if os.path.isdir(p) and archive_ok(p, self.web):
                vid = volume_id(p)
                if not d["id"] or vid == d["id"]:
                    here, d["id"] = p, d["id"] or vid
            if here is None and d["id"]:                   # renamed, or plugged in as "X 1": found by its ID
                rel = was.get("rel", "")
                for m in mounts():
                    if volume_id(m) == d["id"] and os.path.isdir(os.path.join(m, rel) if rel else m):
                        here = os.path.join(m, rel) if rel else m; break
            if here:
                d.update(path=here, connected=True, seen=int(time.time()))
                mp = mount_of(here); d["rel"] = here[len(mp):].lstrip("/") if here != mp else ""
            elif "rel" in was:
                d["rel"] = was["rel"]
            out.append(d)
        return out

    def drive_list(self, d):
        """Where a drive's last list is kept (drives/<its key>.tsv)."""
        os.makedirs(self.p("drives"), exist_ok=True)
        return os.path.join(self.p("drives"), drive_key(d) + ".tsv")

    def root(self, f):
        """Where a duplicates job works: the archive, or a drive kept where it is
        (DRIVE=<its place in Setup>, plugged in). -> (path, suffix of its files), or None."""
        want = f.get("DRIVE", "")
        if not want:
            return self.arch(), ""
        for d in self.drives():
            if d["source"] == want and d["connected"]:
                return d["path"], "-" + drive_key(d)
        return None

    # ── the minute ───────────────────────────────────────────────────────────
    def interrupted(self):
        """A job that was running when Rushes stopped (an update, a restart, the Mac shut down) never finishes, and
        job-status.txt would say "running" for ever: the page waits, Stop does nothing. Its process is checked
        (job-pid.txt); one that is not there any more is said, in the log and Activity, and the status is idle."""
        try: st = open(self.p("job-status.txt")).read().strip()
        except OSError: return
        if not st.startswith("running:"):
            return
        try: pid = int(open(self.p("job-pid.txt")).read().strip())
        except (OSError, ValueError): pid = 0
        if pid:
            try:
                os.kill(pid, 0); return                    # still running, in another process (the NAS's next minute)
            except ProcessLookupError: pass
            except OSError: return
        elif platform.system() != "Darwin":
            return                                         # from before job-pid.txt, on the NAS: not knowable, left
        job = st.split(":", 1)[1].strip()
        self.log(f"\n{time.strftime('%Y-%m-%d %H:%M:%S')}  {job} was cut short: Rushes restarted while it ran (an update, or the Mac). Nothing was half done; ask for it again.")
        self.activity("problem", f"{job.replace('-', ' ').capitalize()} did not finish: Rushes restarted while it ran (an update, or the Mac). Start it again.")
        for n in ("job-pid.txt", "job-stop.txt", "rescue-at.txt"):
            try: os.remove(self.p(n))
            except OSError: pass
        self.write("job-status.txt", "idle\n")

    def minute(self):
        if os.path.exists(self.p("STOP")):
            return
        os.makedirs(self.p("queue"), exist_ok=True)
        self.write("runner-alive.txt", f"{int(time.time())}\n")
        if self.fresh:
            self.fresh = False; self.interrupted()
        t0, self.stalled = time.time(), False
        a = self.arch()
        # The archive's own drive, plugged in or not: said in Activity when that changes
        here = "1" if a and archive_ok(a, self.web) else "0"
        try: was = open(self.p("archive-here.txt")).read().strip()
        except OSError: was = ""
        if was != here:
            self.write("archive-here.txt", here + "\n")
            if was:
                name = os.path.basename((self.settings().get("archive") or {}).get("local", "") or "") or "The archive"
                self.activity("problem" if here == "0" else "changed", f"{name} was unplugged: Search keeps showing its files, marked not plugged in"
                              if here == "0" else f"{name} is plugged in again")
        if self.may_v():
            def df():
                total, free = space(a)         # the columns of df -P, in KB, as state.php reads them
                used = max(0, total - free)
                self.write("disk.txt", f"archive {total // 1024} {used // 1024} {free // 1024} "
                                       f"{round(100 * used / total) if total else 0}% {a}\n")
            self.v("free space", df)
        self.mounts_said()
        # Drives that come and go: what is plugged in now. One that has just come
        # back (or was just added) is listed again by itself.
        ds = self.drives()
        if ds or os.path.exists(self.p("drives.json")):
            try: before = {d["source"]: d for d in json.load(open(self.p("drives.json")))}
            except (OSError, ValueError, KeyError, TypeError): before = {}
            self.write("drives.json", json.dumps(ds, indent=1))
            back = [d for d in ds if d["connected"] and (not before.get(d["source"], {}).get("connected")
                                                          or before[d["source"]].get("path") != d["path"]      # found under another name
                                                          or not os.path.exists(self.drive_list(d)))]
            if back and not any(x.endswith("-drive.job") for x in os.listdir(self.p("queue"))):
                self.write(f"queue/{time.strftime('%Y%m%d-%H%M%S')}-drive.job", "ACTION=reindex\nDRIVES=1\n")
                self.say("plugged in: " + ", ".join(d["name"] for d in back) + " — its list is made again")
            for d in ds:
                b = before.get(d["source"])
                if b and b.get("connected") and not d["connected"]:
                    self.activity("changed", f"{d['name']} was unplugged: Search keeps showing its files, marked not plugged in")
                elif d["connected"] and not (b or {}).get("connected"):
                    self.activity("changed", f"{d['name']} was plugged in" + (" again" if b else "") + ": its list of files is made again")
        # The helper runs in the Rushes app itself, beside this; Setup asks.
        self.write("helper-builtin.txt", "running\n")
        load = " ".join(f"{x:.2f}" for x in os.getloadavg())
        self.write("load.txt", f"{int(time.time())}\t{load}\t{int(time.time() - t0)}\n")
        # Folders being prepared (Manage → Describe): Rushes writes the next one needing
        # proxies into proxy-next.txt (prepare.php); started here when none are being made.
        try: nxt = self.prep_folder(open(self.p("proxy-next.txt")).readline())
        except OSError: nxt = ""
        if nxt and self.may_v() and not self.proxy_running():
            try: os.remove(self.p("proxy-next.txt"))
            except OSError: pass
            self.say(f"making proxies for {nxt}")
            self.proxy_start(nxt)
        for name, fn in (("upkeep", self.upkeep), ("jobs", self.jobs)):
            if not (self.busy.get(name) and self.busy[name].is_alive()):      # the last one still at it: left alone
                self.busy[name] = threading.Thread(target=self.guarded, args=(name, fn), daemon=True)
                self.busy[name].start()

    def guarded(self, name, fn):
        try:
            fn()
        except Exception as e:                           # said, and tried again next minute
            self.say(f"{name}: {e}")
            if name == "proxies":                        # said where the page shows the proxies' own words, too
                self.plog(f"{time.strftime('%Y-%m-%d %H:%M:%S')}  the build stopped: {type(e).__name__}: {e}"[:400])

    # ── upkeep ───────────────────────────────────────────────────────────────
    def mounts_said(self, every=300):
        """mounts.json, for Overview → Drives: each volume this Mac sees, whether it is a network
        share (from the list of mounts, which asks no share anything) and its size as df gives it.
        Every five minutes; a share that does not answer within a few seconds is left out, never waited on."""
        if time.time() - getattr(self, "_mounts_at", 0) < every:
            return
        self._mounts_at = time.time()
        kinds = {}
        try:
            out = subprocess.run(["mount"], capture_output=True, text=True, timeout=5).stdout
            for line in out.splitlines():      # "//u@srv/Share on /Volumes/Share (smbfs, …)" or "… on /mnt/x type nfs (…)"
                m = re.match(r".+? on (.+?) (?:\((\w+)|type (\S+))", line)
                if m: kinds[m.group(1)] = m.group(2) or m.group(3)
        except (OSError, subprocess.SubprocessError):
            pass
        said = {}
        for m in mounts():
            fs = kinds.get(m, "")
            total, free = space(m, quiet=True)
            said[m] = {"fs": fs, "net": fs in NET_FS, "total": total, "free": free}
        self.write("mounts.json", json.dumps({"at": int(time.time()), "mounts": said}))

    def upkeep(self):
        self.stalled = False
        for n in TRIM:                                   # logs that only grow: the newest 1 MB stays
            f = self.p(n)
            try:
                if os.path.getsize(f) > 5_000_000:
                    with open(f, "rb") as h:
                        h.seek(-1_000_000, 2); tail = h.read()
                    with open(f, "r+b") as h:            # the same file, so a program writing to it carries on
                        h.write(tail[tail.find(b"\n") + 1:]); h.truncate()
                    self.say(f"trimmed {n} to its newest 1 MB")
            except OSError:
                pass
        try:
            sync = self.get("/db/import.php?part=web", 3600)
        except Exception as e:
            sync = f"could not ask Rushes ({e})"
        if self.may_v():
            out = {}
            if self.v("search update", lambda: out.update(s=self.get("/db/import.php?part=video", 110)), 120):
                sync += " " + out.get("s", "")
        if '"state":"current"' not in sync.replace(" ", "") or "could not" in sync:
            self.say(f"search update: {sync.strip()}")
        self.dbcopy()
        self.exposed()
        if not self.stalled:                             # a whole minute without a stall: the count starts again
            try: os.remove(self.p("video-stalls.txt"))
            except OSError: pass

    def dbcopy(self):
        """Rushes' own daily database copy, onto the archive: one per weekday (_rushes/db-copies)."""
        src, done = self.p("db-copy.sqlite"), self.p("db-copied")
        if not os.path.exists(src) or (os.path.exists(done) and os.path.getmtime(done) >= os.path.getmtime(src)):
            return
        if not self.may_v():
            return
        day = time.strftime("%a"); d = os.path.join(self.arch(), "_rushes", "db-copies")
        def cp():
            os.makedirs(d, exist_ok=True)
            shutil.copyfile(src, os.path.join(d, f"rushes-{day}.sqlite.part"))
            os.replace(os.path.join(d, f"rushes-{day}.sqlite.part"), os.path.join(d, f"rushes-{day}.sqlite"))
        if self.v("database copy", cp, 600):
            open(done, "w").close()
            self.say(f"database copied to _rushes/db-copies/rushes-{day}.sqlite")

    def exposed(self):
        """Once a day: can anything private be downloaded from this machine's own web server?"""
        f = self.p("exposed.txt")
        if os.path.exists(f) and time.time() - os.path.getmtime(f) < 86400:
            return
        out = []
        for n in PRIVATE:
            if os.path.exists(self.p(n)):
                try:
                    with urllib.request.urlopen(f"{self.url}/{n}", timeout=5) as r:
                        if r.status == 200: out.append(n)
                except Exception:
                    pass                                 # refused: as it should be
        self.write("exposed.txt", "".join(x + "\n" for x in out))

    # ── proxies, on this Mac (proxy.sh on a NAS): the same records, so Rushes reads them the same ──
    # proxy-status.txt (what the page shows), proxy-folders.tsv (each folder's runs: what starts its
    # describing), proxy-made.tsv (the media ledger), proxy-built.tsv, proxy-speed.tsv, proxy-failed.tsv.
    def ffmpeg(self):
        for d in (os.path.expanduser("~/archive-pilot/ai/bin"), "/opt/homebrew/bin", "/usr/local/bin"):
            if os.access(os.path.join(d, "ffmpeg"), os.X_OK):
                return os.path.join(d, "ffmpeg")
        return shutil.which("ffmpeg")

    # The same pinned ffmpeg Install the AI brings (ingest.py AI_GET), checked against its fingerprint
    FFMPEG_GET = ("https://github.com/eugeneware/ffmpeg-static/releases/download/b6.0/ffmpeg-darwin-arm64.gz",
                  "6be74d6f449889c2e87a75873894f8520cad56c08ac76f2a628d85b0519daaca")

    def get_ffmpeg(self, only):
        """No ffmpeg on this Mac: the pinned one, downloaded once, so nobody opens Terminal. -> its path, or None."""
        import gzip
        if platform.system() != "Darwin" or platform.machine() != "arm64":
            return None
        d = os.path.expanduser("~/archive-pilot/ai/bin"); os.makedirs(d, exist_ok=True)
        dest, part, h = os.path.join(d, "ffmpeg"), os.path.join(d, "ffmpeg.gz.part"), hashlib.sha256()
        self.proxy_state(state="planning", only=only, step="downloading ffmpeg, the tool that makes proxies (19 MB), once")
        try:
            with urllib.request.urlopen(self.FFMPEG_GET[0], timeout=60) as r, open(part, "wb") as f:
                for b in iter(lambda: r.read(1 << 20), b""):
                    h.update(b); f.write(b)
            if h.hexdigest() != self.FFMPEG_GET[1]:
                raise OSError("the download is not the one expected (its fingerprint differs): not used")
            with gzip.open(part) as g, open(dest + ".part", "wb") as f:
                shutil.copyfileobj(g, f)
            os.chmod(dest + ".part", 0o755); os.replace(dest + ".part", dest)
            subprocess.run(["codesign", "-s", "-", "-f", dest], capture_output=True)    # an Apple chip runs only signed programs
            self.plog(f"{time.strftime('%Y-%m-%d %H:%M:%S')}  downloaded ffmpeg ✓ {dest}")
            return dest
        except Exception as e:
            self.plog(f"could not download ffmpeg: {e}")
            return None
        finally:
            try: os.remove(part)
            except OSError: pass

    def proxy_state(self, **kv):
        self.write("proxy-status.txt", f"at\t{int(time.time())}\n" + "".join(f"{k}\t{v}\n" for k, v in kv.items()))
        self.write("proxy-alive.txt", f"{int(time.time())}\n")       # the heartbeat: a run is going (prepare.php)

    def plog(self, line):
        with open(self.p("proxy.log"), "a", errors="surrogateescape") as f:
            f.write(line + "\n")

    @staticmethod
    def ledger(report):
        return " ".join(l for l in report.splitlines() if re.search(
            r"Duration:|Video:|Audio:|creation_time|modification_date|timecode|reel_name|model|make|product_name|company_name|encoder", l)
            ).replace("\t", " ")[:4000]

    def proxy_running(self):
        t = self.busy.get("proxies")
        return bool(t and t.is_alive())

    def proxy_start(self, only):
        self.proxy_halt = threading.Event()
        self.write("proxy-alive.txt", f"{int(time.time())}\n")
        self.busy["proxies"] = threading.Thread(target=self.guarded, args=("proxies", lambda: self.proxies(only, build=True)), daemon=True)
        self.busy["proxies"].start()

    def proxy_setting(self):
        """Manage → Describe → Proxy quality: height and Mbit/s, or "sw" (software). 720p at 4 otherwise."""
        try: h, b = open(self.p("proxy-setting.txt")).read().split()[:2]
        except (OSError, ValueError): h, b = "720", "4"
        if h not in ("720", "1080") or b not in ("4", "6", "sw"): h, b = "720", "4"
        return int(h), b

    def media_engine(self, ff):
        """Can this ffmpeg use the Mac's media engine (VideoToolbox)? One tiny frame, tried."""
        if getattr(self, "_engine", None) is None:
            r = subprocess.run([ff, "-nostdin", "-loglevel", "error", "-f", "lavfi", "-i", "color=c=black:s=320x240:d=0.2",
                                "-c:v", "h264_videotoolbox", "-f", "null", "-"], capture_output=True, text=True)
            self._engine = (r.returncode == 0, (r.stderr.strip().splitlines() or [""])[-1][:160])
        return self._engine

    def probe(self, ff, src):
        """ffmpeg's own report of a file: what it is, and what the camera wrote inside it."""
        # errors="replace": cameras and Windows write metadata in other encodings, which must never stop a build;
        # a file that does not answer in 5 minutes (a bad patch of disk) is reported as nothing
        # (a read stuck in a share may not even let ffmpeg end when told to: then it is left, never waited on)
        p = subprocess.Popen([ff, "-hide_banner", "-nostdin", "-i", src], stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True, errors="replace")
        try: return p.communicate(timeout=PROBE_WAIT)[1]
        except subprocess.TimeoutExpired:
            p.kill()
            try: p.wait(30)
            except subprocess.TimeoutExpired: pass
            return ""

    @staticmethod
    def light(report, src):
        """Already light enough to play as it is (an AI or stock download, a web export): an MP4 or MOV
        in H.264, 8-bit, at most 1080p and 10 Mbit/s, with sound a browser plays (AAC, MP3) or none.
        Its proxy would only be a copy of it: it is used as it is. H.264 only: every browser plays it."""
        if not src.lower().endswith((".mp4", ".mov", ".m4v")):
            return False
        v = re.search(r"Video: (\w+)([^\n]*?), (\d{2,5})x(\d{2,5})", report)
        br = re.search(r"bitrate: (\d+) kb/s", report)
        sound = re.findall(r"Audio: (\w+)", report)
        return bool(v and v[1] == "h264" and re.search(r"yuv(j)?420p[,(]", v[2] + ",") and min(int(v[3]), int(v[4])) <= 1080   # the short side: a phone's 1080x1920 is HD
                    and br and int(br[1]) <= 10000 and all(a in ("aac", "mp3") for a in sound))

    def encode(self, ff, src, out, h, b, engine):
        """One proxy: on the media engine, else in software. -> (made, how). Stop ends it at once."""
        nice = ["nice", "-n", "15"] if shutil.which("nice") else []      # copies, search and editors come first
        audio = ["-c:a", "aac", "-b:a", "128k", "-movflags", "+faststart"]
        tries = []
        # the short side becomes {h}: a vertical clip keeps its width (720x1280, not 405x720)
        fit = f"scale='if(gt(iw,ih),-2,{h})':'if(gt(iw,ih),{h},-2)'"
        if engine and b != "sw":
            tries.append(("video chip", ["-hwaccel", "videotoolbox", "-i", src, "-vf", fit, "-c:v", "h264_videotoolbox",
                                         "-b:v", f"{b}M", "-maxrate", f"{int(b) * 3 // 2}M"]))
        tries.append(("software", ["-i", src, "-vf", fit, "-c:v", "libx264", "-preset", "veryfast", "-crf", "23"]))
        err, stalls = "", []
        for k, (how, args) in enumerate(tries):
            if self.proxy_halt.is_set():
                return False, how
            # ffmpeg's words go to a file, never a pipe read only at the end: a file it has much to say about
            # (thousands of lines) filled the pipe, ffmpeg waited for it to be read, and made nothing for ever
            log = tempfile.TemporaryFile(mode="w+", errors="replace")
            self.proxy_proc = subprocess.Popen(nice + [ff, "-nostdin", "-loglevel", "error", "-y", *args, *audio, out],
                                               stdout=subprocess.DEVNULL, stderr=log)
            # the heartbeat while a long file is made; and a try that makes nothing for STALL seconds is ended:
            # the next way is tried (software after the media engine), then the file is given up and the next made
            grew, last, stalled = time.time(), -1, False
            while self.proxy_proc.poll() is None:
                self.write("proxy-alive.txt", f"{int(time.time())}\n")
                try: self.proxy_proc.wait(min(10, STALL))
                except subprocess.TimeoutExpired: pass
                try: now = os.path.getsize(out)
                except OSError: now = -1
                if now != last: last, grew = now, time.time()
                elif time.time() - grew > (STALL if how == "software" else min(STALL, CHIP_STALL)):   # the chip makes MBs a second: 3 minutes of nothing is stuck
                    stalled = True; self.proxy_proc.kill()
                    try: self.proxy_proc.wait(30)            # a read stuck in the share may not even let it end: left
                    except subprocess.TimeoutExpired: pass
                    break
            rc = self.proxy_proc.returncode; self.proxy_proc = None
            try: log.seek(0); err = log.read()[-20000:]
            except (OSError, ValueError): err = ""
            log.close()
            with open(self.p("proxy-built.tsv.err"), "a", errors="surrogateescape") as f: f.write(err[-4000:])
            if not stalled and rc == 0 and os.path.exists(out) and os.path.getsize(out) > 0:
                return True, how
            try: os.remove(out)
            except OSError: pass
            if stalled:
                stalls.append(how)
                if k + 1 < len(tries):
                    self.plog(f"{time.strftime('%Y-%m-%d %H:%M:%S')}  {os.path.basename(src)}: nothing was made {'on the ' + how if how == 'video chip' else 'in ' + how} for {(STALL if how == 'software' else min(STALL, CHIP_STALL)) // 60} minutes; trying {tries[k + 1][0]}")
        said = (err.strip().splitlines() or [""])[-1][:200]
        if stalls:
            self.last_err = (f"nothing was made for {STALL // 60} minutes ({' or '.join(stalls)}): " + self.readable(src)
                             + (f" · ffmpeg said: {said}" if said else ""))
        else:
            self.last_err = said or "ffmpeg stopped"
        return False, tries[-1][0]

    @staticmethod
    def readable(src, budget=60):
        """When a proxy made nothing: the file's bytes are read (its start, middle and end, a few MB each, at most
        `budget` seconds, never waited on longer), to say whether the disk gives them. Never "damaged": a file that
        reads fine may play perfectly (QuickTime) and still be one ffmpeg cannot make a proxy of."""
        out = {}
        def read():
            try:
                n = os.path.getsize(src)
                with open(src, "rb") as f:
                    for at in (0, n // 2, max(0, n - (4 << 20))):
                        f.seek(at); f.read(4 << 20)
                out["ok"] = True
            except OSError as e:
                out["err"] = e.strerror or str(e)
        t = threading.Thread(target=read, daemon=True); t.start(); t.join(budget)
        if out.get("ok"):
            return "the file reads fine (the disk gives its data), but ffmpeg made nothing from it; Show in Finder to look at it, and Try again"
        if out.get("err"):
            return f"the disk refused to give its data ({out['err']}): a bad patch of disk, or a damaged file"
        return "the disk did not give its data within a minute: a bad patch of the disk, or the share not answering for this file"

    def prep_folder(self, raw):
        """A folder to make previews for, as Rushes asked: a folder of the archive (its path inside it), or a
        folder on a drive kept where it is (its full path, only on such a drive, found by drives()). "" if neither."""
        raw = raw.strip()
        if raw.startswith("/"):
            raw = raw.rstrip("/")
            if ".." in raw.split("/") or any(c in raw for c in "\t\n\r"):
                return ""
            return raw if self.kept_drive(raw) else ""
        q = re.sub(r"[^A-Za-z0-9 _./&(),+\x80-\U0010ffff-]", "", raw).strip("/")[:200]
        return "" if ".." in q.split("/") else q

    def kept_drive(self, path):
        """The drive kept where it is that holds this path, plugged in now, or None."""
        for d in self.drives():
            r = d["path"].rstrip("/")
            if d["connected"] and (path == r or path.startswith(r + "/")):
                return d
        return None

    def preview_root(self, a, d):
        """Where a drive's previews live: on the archive, in Rushes' records, under the drive's own ID
        (_rushes/previews/<ID>/<same path as on the drive>.mp4). Nothing is written on the drive itself.
        A note of where the drive was beside them (.from), so the helper finds them when it describes."""
        pr = os.path.join(a, "_rushes", "previews", drive_key(d))
        os.makedirs(pr, exist_ok=True)
        with open(os.path.join(pr, ".from"), "w") as f:
            f.write(d["path"].rstrip("/") + "\n")
        return pr

    def proxies(self, only, build):
        """Plan (what is missing) or build the proxies of one folder (or the whole archive)."""
        a = self.arch()
        if a is None:
            self.plog("the archive is not there: no proxies"); return
        self.proxy_state(state="planning", only=only, step="listing the videos in the folder")
        ff = self.ffmpeg() or self.get_ffmpeg(only)
        if not ff:
            self.proxy_state(state="no-ffmpeg"); self.plog("no ffmpeg on this Mac, and it could not be downloaded (proxy.log says why)")
            return
        drive = self.kept_drive(only) if only.startswith("/") else None
        if only.startswith("/") and not drive:
            self.proxy_state(state="no-folder", only=only); self.plog(f"{only}: not on a drive kept where it is, plugged in now"); return
        root = only if drive else os.path.join(a, only) if only else a
        one = os.path.isfile(root) and root.lower().endswith(VIDEO_EXT)      # Search's "Make its proxy now": that video alone
        if not one and not os.path.isdir(root):
            self.proxy_state(state="no-folder", only=only); return
        # the archive's previews mirror it under PROXIES; a kept drive's, on the archive under its ID
        top = drive["path"].rstrip("/") if drive else a
        proot = self.preview_root(a, drive) if drive else os.path.join(a, "PROXIES")
        plan, have, videos = [], 0, 0
        for d, dirs, files in ([(os.path.dirname(root), [], [os.path.basename(root)])] if one else os.walk(root)):
            dirs[:] = sorted(x for x in dirs if x not in SKIP and not x.startswith(".")
                             and not (d == top and x in ("PROXIES", HOLD, OLD_HOLD, "_rushes")))
            for n in sorted(files):
                if n.startswith(".") or not n.lower().endswith(VIDEO_EXT):
                    continue
                src = os.path.join(d, n); videos += 1
                out = os.path.join(proot, os.path.splitext(os.path.relpath(src, top))[0] + ".mp4")
                if os.path.exists(out): have += 1
                else: plan.append((src, out))
        try: asis = {l.split("\t")[0] for l in open(self.p("proxy-made.tsv")) if l.rstrip("\n").endswith("\t1")}
        except OSError: asis = set()
        have += sum(1 for s, _ in plan if s in asis); plan = [(s, o) for s, o in plan if s not in asis]
        size = lambda x: os.path.getsize(x) if os.path.exists(x) else 0
        h, b = self.proxy_setting()
        engine, why = self.media_engine(ff)
        hw = "1" if engine and b != "sw" else "0"
        if not build:
            self.proxy_state(state="planned", only=only, have=have, missing=len(plan), source_gb=round(sum(size(s) for s, _ in plan) / 2**30),
                             hw=hw, height=h, videos=videos, hw_why="" if engine else f"this ffmpeg cannot use the Mac's media engine ({why})",
                             cpu=platform.processor() or platform.machine(), ffmpeg=ff)
            return
        runs = lambda st, ok, failed, later, total: open(self.p("proxy-folders.tsv"), "a").write(
            f"{only}\t{st}\t{ok}\t{failed}\t{later}\t{total}\t{int(time.time())}\n")
        need, free = sum(size(s) for s, _ in plan) * 0.05 + 2e9, shutil.disk_usage(a).free
        if free < need:                                  # said once, plainly, instead of failing file after file
            self.proxy_state(state="no-room", only=only, need=int(need), free=free); runs("no-room", 0, 0, 0, len(plan))
            self.plog(f"not enough room for the proxies: about {need / 1e9:,.0f} GB needed, {free / 1e9:,.0f} GB free"); return
        total, ok, failed, later, n, used = len(plan), 0, 0, 0, 0, 0
        count = {"video chip": 0, "software": 0}
        self.plog(f"{time.strftime('%Y-%m-%d %H:%M:%S')}  {only or 'the whole archive'}: {total} proxies to make, {have} already made · "
                  + ("on the media engine" if hw == "1" else "in software") + f" · {h}p · {ff}")
        os.makedirs(proot, exist_ok=True)
        for src, out in plan:
            n += 1
            if self.proxy_halt.is_set():
                self.proxy_state(state="stopped", only=only, done=n - 1, total=total, ok=ok, failed=failed, later=later)
                runs("stopped", ok, failed, later, total); self.plog(f"stopped from Manage after {ok} proxies"); return
            if os.path.exists(out):
                continue
            try:
                if time.time() - os.path.getmtime(src) < RECENT:
                    later += 1; continue
            except OSError:
                failed += 1; continue
            # one file, guarded: a file that breaks something (a name or metadata in another encoding, a probe that
            # never ends) is said and skipped; it never ends the whole build, which would start again on it for ever
            try:
                self.proxy_state(state="building", only=only, done=n, total=total, ok=ok, failed=failed, later=later,
                                 file=os.path.relpath(src, top), hw=hw, chip=count["video chip"], mixed=0, soft=count["software"], asis=used)
                report = self.probe(ff, src)
                if self.light(report, src):              # no proxy: the original plays as it is
                    with open(self.p("proxy-made.tsv"), "a", errors="surrogateescape") as f: f.write(f"{src}\t{int(time.time())}\t{self.ledger(report)}\t1\n")
                    with open(self.p("proxy-built.tsv"), "a", errors="surrogateescape") as f: f.write(f"{src}\t{src}\tused as it is\n")
                    used += 1; ok += 1; continue
                os.makedirs(os.path.dirname(out), exist_ok=True)
                tmp, t0 = out + ".part.mp4", time.time()      # never a playable-looking half file
                made, how = self.encode(ff, src, tmp, h, b, hw == "1")
                if made:
                    os.replace(tmp, out); count[how] += 1; ok += 1
                    with open(self.p("proxy-speed.tsv"), "a") as f: f.write(f"{size(src)}\t{int(time.time() - t0)}\t{how}\n")
                    with open(self.p("proxy-built.tsv"), "a", errors="surrogateescape") as f: f.write(f"{src}\t{out}\t{how}\n")
                    # What the original is and what the camera wrote inside it, read once here (the media ledger)
                    with open(self.p("proxy-made.tsv"), "a", errors="surrogateescape") as f: f.write(f"{src}\t{int(time.time())}\t{self.ledger(report)}\n")
                else:
                    if self.proxy_halt.is_set():
                        continue                             # Stop: said at the top of the next turn
                    failed += 1
                    err = getattr(self, "last_err", "")
                    self.plog(f"{time.strftime('%Y-%m-%d %H:%M:%S')}  could not make {os.path.relpath(src, top)}: {err}"[:500])   # said, by name
                    with open(self.p("proxy-built.tsv"), "a", errors="surrogateescape") as f: f.write(f"FAILED\t{src}\n")
                    with open(self.p("proxy-failed.tsv"), "a", errors="surrogateescape") as f: f.write(f"{src}\t{int(time.time())}\t{err.replace(chr(9), ' ')}\n")
                    if "Permission denied" in err or "No space left" in err:   # nothing can be written: stop once, saying why
                        self.proxy_state(state="stopped", only=only, done=n, total=total, ok=ok, failed=failed, why=err)
                        runs("stopped", ok, failed, later, total); self.plog(f"stopped: the proxies cannot be written — {err}"); return
            except Exception as e:
                failed += 1
                why = f"{type(e).__name__}: {e}"[:300]
                self.plog(f"{time.strftime('%Y-%m-%d %H:%M:%S')}  could not make {os.path.relpath(src, top)}: {why}"[:500])
                with open(self.p("proxy-failed.tsv"), "a", errors="surrogateescape") as f: f.write(f"{src}\t{int(time.time())}\t{why.replace(chr(9), ' ')}\n")
                for x in (out + ".part.mp4",):
                    try: os.remove(x)
                    except OSError: pass
            if n % 25 == 0:
                self.plog(f"progress: {n} of {total}")
        if self.proxy_halt.is_set():
            self.proxy_state(state="stopped", only=only, done=n, total=total, ok=ok, failed=failed, later=later)
            runs("stopped", ok, failed, later, total); self.plog(f"stopped from Manage after {ok} proxies"); return
        self.proxy_state(state="done", only=only, done=n, total=total, ok=ok, failed=failed, later=later,
                         chip=count["video chip"], mixed=0, soft=count["software"], asis=used)
        runs("done", ok, failed, later, total)
        self.plog(f"{time.strftime('%Y-%m-%d %H:%M:%S')}  built {ok - used} proxies, {used} light enough to play as they are, "
                  f"{failed} failed, {later} left for a later run (still arriving)")

    # ── the queue ────────────────────────────────────────────────────────────
    def jobs(self):
        q = self.p("queue")
        for job in sorted(x for x in os.listdir(q) if x.endswith(".job")):
            try:
                with open(os.path.join(q, job)) as f:
                    fields = dict(l.rstrip("\n").split("=", 1) for l in f if "=" in l)
                os.remove(os.path.join(q, job))
            except OSError:
                continue
            action = fields.get("ACTION", "")
            for n in ("job-stop.txt",):                   # a Stop pressed for an earlier job is not this one's
                try: os.remove(self.p(n))
                except OSError: pass
            self.now_job = action
            self.write("job-pid.txt", f"{os.getpid()}\n")    # whose job it is: a restart that cuts it short is told apart
            self.write("job-status.txt", f"running: {action}\n")
            self.log(f"\n{'=' * 62}\n{time.strftime('%Y-%m-%d %H:%M:%S')}  {action}\n{'=' * 62}")
            try:
                self.job(action, fields)
            except Exception as e:                       # said; the queue carries on
                self.log(f"  {action} stopped: {e}")
            if self.stop_asked():
                self.log("  stopped, as asked (Stop)")
            self.now_job = None
            for n in ("job-stop.txt", "job-pid.txt"):
                try: os.remove(self.p(n))
                except OSError: pass
            self.log(f"--- finished {time.strftime('%H:%M:%S')} ---")
            self.write("job-status.txt", "idle\n")
        # A new archive has no list yet: the first one is made by itself (once each start).
        if not os.path.exists(self.p("manifest.tsv")) and not getattr(self, "first", False) and self.may_v():
            self.first = True
            self.write("job-status.txt", "running: manifest\n")
            self.log(f"{time.strftime('%Y-%m-%d %H:%M:%S')}  no file list yet: walking the archive for the first one")
            self.build_index()
            self.write("job-status.txt", "idle\n")
        if not os.path.exists(self.p("job-status.txt")):
            self.write("job-status.txt", "idle\n")

    def hold(self, top):
        """Recently Removed on the archive or a drive, renamed from _duplicates the first time."""
        self.migrate_holding(top)
        return os.path.join(top, HOLD)

    def migrate_holding(self, top):
        """_duplicates (before 0.12.4) becomes Recently Removed, on the same drive: a rename,
        so nothing is copied. The records of what moved (dedupe-moves*.tsv, cache-moves.tsv)
        are rewritten to the new place, so Recover still finds every file."""
        old, new = os.path.join(top, OLD_HOLD), os.path.join(top, HOLD)
        if not os.path.isdir(old) or os.path.islink(old) or os.path.exists(new):
            return
        ts.rename_new(old, new)
        for log in [n for n in os.listdir(self.web) if n.startswith("dedupe-moves") or n in ("cache-moves.tsv", "removed-moves.tsv")]:
            try:
                with open(self.p(log), encoding="utf-8", errors="surrogateescape") as f:
                    t = f.read()
                if old + "/" in t:
                    self.write(log, t.replace(old + "/", new + "/"))
            except OSError:
                pass
        self.say(f"{old} is called {HOLD} now (renamed, nothing moved)")

    def removed_at(self, sfx, when=None):
        """When something last went into Recently Removed (removed-at<drive>.txt): its age is said in Manage."""
        if when is not None:
            self.write(f"removed-at{sfx}.txt", f"{int(when)}\n")
        try:
            return int(open(self.p(f"removed-at{sfx}.txt")).read().split()[0])
        except (OSError, ValueError, IndexError):
            return 0

    def lines(self, name):
        """How many files a record of moves names (its # lines are notes, not files)."""
        try:
            with open(self.p(name), encoding="utf-8", errors="surrogateescape") as f:
                return sum(1 for l in f if l.strip() and not l.startswith("#"))
        except OSError:
            return 0

    def empty(self, top, sfx, who):
        """Delete All: what is in Recently Removed on the archive or that drive goes, for good.
        Only what is inside that folder; asked for by a person (Manage, with Sure?), never by itself.
        A duplicate goes only when every byte of it is still the same as the copy that stays:
        if that copy changed or broke since the scan, the one here may be the last good one, and
        it stays. Cache files go (editing software makes them again), and what a person removed in
        Reorganize → Tidy up (removed-moves.tsv). A file with no record of where it came from stays. Recover forgets only the files that were deleted."""
        h = self.hold(top)
        if not os.path.isdir(h) or os.path.islink(h):
            self.log("  Recently Removed is empty: nothing to delete"); return
        logs = [x for x in os.listdir(self.web) if x.startswith("dedupe-moves") or x in ("cache-moves.tsv", "removed-moves.tsv")]
        keeper, cache = {}, set()                    # held file -> the copy that stays; held cache files
        for log in logs:
            try:
                with open(self.p(log), encoding="utf-8", errors="surrogateescape") as f:
                    for line in f:
                        x = line.rstrip("\n").split("\t")
                        if len(x) < 2 or not x[1].startswith(h + "/"):
                            continue
                        if log in ("cache-moves.tsv", "removed-moves.tsv"):   # cache, and what a person removed in Tidy up
                            cache.add(x[1])
                        elif len(x) > 2 and x[2]:
                            keeper[x[1]] = x[2]
            except OSError:
                pass
        held = [os.path.join(r, x) for r, _, fs in os.walk(h) for x in fs]
        gone, n, b, differ, unread, unknown, last, stopped = set(), 0, 0, 0, 0, 0, time.time(), False
        self.log(f"  {len(held):,} files in {h}: each duplicate is compared with the copy that stays before it goes")
        for i, p in enumerate(held, 1):
            self.make_way()
            if not self.may_v():
                stopped = True; break
            if time.time() - last > 30:
                last = time.time()
                self.log(f"progress: {i} of {len(held)} ({100 * i // max(len(held), 1)}%)")
            if p in keeper:
                done, same = self.watched("Delete All, comparing", lambda tick: ts.same_bytes(p, keeper[p], tick))
                if not done:                             # paused, or the drive stopped giving data: this file stays
                    stopped = True; break
                if same is not True:
                    if same is False: differ += 1
                    else: unread += 1
                    self.log(f"  kept {p}: " + ("it is not the same as the copy that stays any more" if same is False
                                                else f"it or the copy that stays ({keeper[p]}) could not be read"))
                    continue
            elif p not in cache and "/_media-cache/" not in p:
                unknown += 1
                self.log(f"  kept {p}: no record of where it came from")
                continue
            try:
                size = os.lstat(p).st_size; os.remove(p)
                gone.add(p); n += 1; b += size
            except OSError as e:
                self.log(f"  could not delete {p}: {e.strerror or e}")
        for r, ds, _ in os.walk(h, topdown=False):       # folders left empty
            for x in ds:
                try: os.rmdir(os.path.join(r, x))
                except OSError: pass
        for log in logs:                                 # Recover forgets only what is gone
            try:
                with open(self.p(log), encoding="utf-8", errors="surrogateescape") as f:
                    keep = [l for l in f if (l.split("\t") + ["", ""])[1].rstrip("\n") not in gone]
                self.write(log, "".join(keep))
            except OSError:
                pass
        left = sum(len(fs) for _, _, fs in os.walk(h))
        if not left:
            self.removed_at(sfx, 0)                      # empty: no age; anything left keeps its own
        name = os.path.basename(top.rstrip("/")) or top
        why = ", ".join(t for t in (f"{differ:,} no longer the same as the copy that stays" if differ else "",
                                     f"{unread:,} could not be compared" if unread else "",
                                     f"{unknown:,} with no record of where they came from" if unknown else "",
                                     "stopped part-way: copying was paused or the archive stopped answering" if stopped else "") if t)
        self.say(f"Delete All: {n} files deleted from {h} ({b / 1e9:,.1f} GB)" + (f"; {left:,} kept ({why})" if left else ""))
        self.activity("changed", f"Deleted for good from Recently Removed on {name}: {n:,} {'file' if n == 1 else 'files'}"
                      + (f" ({b / 1e9:,.1f} GB)" if b >= 1e9 else "")
                      + (f"; {left:,} kept, {why}" if left else ""), who)

    def job(self, action, f):
        a = self.arch()
        who = re.sub(r"[\x00-\x1f]", " ", f.get("WHO", ""))[:40]
        moving = action in ("plan", "apply", "undo", "verify", "scan", "find", "empty", "cacheclean", "cache-undo", "holding", "rescue-scan", "rescue-apply")
        if moving and (a is None or not self.may_v()):
            self.log("  the archive is not there, copying is paused, or it stopped after not answering — nothing was done")
            return
        if action in ("reindex", "manifest"):
            self.log("walking the archive once — file list and search index together")
            self.build_index(said=f.get("DRIVES") == "1", moved=f.get("NEW_ARCHIVE") == "1")
        elif action == "reset-breaker":
            for n in ("video-tripped.txt", "video-stalls.txt"):
                try: os.remove(self.p(n))
                except OSError: pass
            self.log("  the archive may be reached again — the next minute tries it")
        elif action == "empty":
            r = self.root(f)
            if r is None:
                self.log("  that drive is not plugged in (or not in Setup any more) — nothing was done"); return
            self.empty(*r, who)
            self.refresh()
        elif action in ("plan", "apply", "undo", "verify", "find"):
            # The archive, or one drive kept where it is: each its own plan, moves and holding folder.
            r = self.root(f)
            if r is None:
                self.log(f"  that drive is not plugged in (or not in Setup any more) — nothing was done"); return
            top, sfx = r
            if sfx:
                self.log(f"  on {top}")
            files = dict(RESULTS=self.p(f"results_duplicates{sfx}.txt"), PLAN=self.p(f"dedupe-plan{sfx}.tsv"),
                         MTIMES=self.p(f"dedupe-mtimes{sfx}.txt"), LEFT=self.p(f"dedupe-left{sfx}.tsv"), ARCH=top)
            # Remove for one box or one job: the copies the page wrote down (db/dupgroups.php); dedupe.sh
            # takes only those that are also in the plan
            if action == "apply" and f.get("PICK") == "1":
                files["PICK"] = self.p(f"dedupe-pick{sfx}.txt")
            if action == "verify":
                q = re.sub(r"[^A-Za-z0-9 _./&(),+\x80-\U0010ffff-]", "", f.get("QUERY", ""))[:200]
                if ".." in q:
                    self.log("  refused a folder with .. in it"); return
                self.script("verify.sh", [q] if q else [], PLAN=files["PLAN"], MOVES=self.p(f"dedupe-moves{sfx}.tsv"),
                            OUT=self.p(f"verify-result{sfx}.tsv"), HOLD=self.hold(top), ARCH=top)
                return
            if action == "find":
                # Find duplicates: one press, two steps the person never has to tell apart
                # (which files are the same, then which copy stays and which go)
                self.scan()
                action = "plan"
            # never trust the queue file (as runner.sh): only these, only inside the archive (or that drive).
            # Which copy stays is Rushes' own choice (the shelf's copy over a card dump's); a person changes it
            # per group, in the plan itself (db/dupgroups.php), not with a setting.
            keep = f.get("KEEP_SIDE") if f.get("KEEP_SIDE") in ("project", "card", "short", "oldest") else "project"
            log, dest = f"dedupe-moves{sfx}.tsv", self.hold(top)
            before = self.lines(log)
            self.script("dedupe.sh", {"plan": [], "apply": ["--apply"], "undo": ["--undo"]}[action],
                        KEEP_SIDE=keep, DEST=dest, LOG=self.p(log), **files)
            name = os.path.basename(top.rstrip("/")) or top
            if action == "apply":
                n = self.lines(log) - before
                if n > 0:
                    self.removed_at(sfx, time.time())
                self.activity("changed", f"Removed {n:,} duplicate {'copy' if n == 1 else 'copies'} on {name} into Recently Removed (nothing deleted)", who)
            elif action == "undo":
                self.activity("changed", f"Recovered the duplicate copies in Recently Removed on {name}: back where they were", who)
            if action != "plan":
                self.refresh()
        elif action == "scan":
            self.scan()
        elif action == "rescue-scan":
            self.rescue_scan()
        elif action == "ssh-list":
            name = re.sub(r"[\x00-\x1f]", "", f.get("NAME", ""))[:60]
            try:
                self.ssh_list(name)
            except Exception as e:             # said on the page, not only in the log
                sv = next((x for x in self.ssh_servers() if x["name"] == name), None)
                if sv: self.write(f"ssh-{sv['key']}.json", json.dumps({"state": "failed", "why": f"stopped: {e}", "at": int(time.time())}))
                raise
        elif action == "rescue-apply":
            self.rescue_apply(who)
        elif action in ("cacheclean", "cache-undo"):
            n = (self.cache_clean if action == "cacheclean" else self.cache_undo)()
            self.activity("changed", f"Removed {n:,} cache files into Recently Removed (nothing deleted; editing software rebuilds them)"
                          if action == "cacheclean" else f"Recovered {n:,} cache files from Recently Removed", who)
            self.refresh()
        elif action == "holding":
            self.holding()
        elif action == "df":
            u = shutil.disk_usage(a)
            self.log(f"  {a}: {u.free / 1e9:,.1f} GB free of {u.total / 1e9:,.1f} GB")
        elif action == "organize-undo":
            self.log("  the old date-based layout was never used on this computer: nothing to put back")
        elif action in ("proxy-plan", "proxy-build", "proxy-remake", "proxy-stop"):
            q = self.prep_folder(f.get("QUERY", ""))
            if not q and f.get("QUERY", "").strip("/ "):
                self.log("  refused: not a folder of the archive, nor of a drive kept where it is and plugged in"); return
            if action == "proxy-plan":
                self.proxies(q, build=False)
            elif action == "proxy-stop":
                if self.proxy_running():
                    self.proxy_halt.set()
                    if getattr(self, "proxy_proc", None): self.proxy_proc.terminate()
                    self.log("  asked the proxy build to stop")
                else:
                    self.log("  no proxy build was running")
            elif self.proxy_running():
                self.log("  proxies are already being made (detail in proxy.log)")
            else:
                # Remake: that folder's proxies thrown away first (derived files, only inside PROXIES)
                kd = self.kept_drive(q) if q.startswith("/") else None
                base = os.path.join(a or "", "_rushes", "previews", drive_key(kd)) if kd else os.path.join(a or "", "PROXIES")
                d = os.path.join(base, os.path.relpath(q, kd["path"].rstrip("/"))) if kd else os.path.join(base, q)
                if action == "proxy-remake" and q and a and os.path.isdir(d) and os.path.realpath(d).startswith(os.path.realpath(base) + "/"):
                    shutil.rmtree(d, ignore_errors=True)
                    self.log(f"  threw away the proxies of {q} — making them again with the setting in use")
                self.proxy_start(q)
                self.log("  making proxies in the background: progress in Manage → Describe, detail in proxy.log")
        elif action != "refused":
            # ponytail: Test the video chip and Test proxy settings are the NAS's (runner.sh)
            self.log(f"  {action}: not on this computer yet — nothing was done")

    def script(self, name, args, **env):
        """dedupe.sh or verify.sh, as runner.sh runs them, their words into the log."""
        env.setdefault("ARCH", self.arch())
        with open(self.p("job.log"), "a") as out:
            r = subprocess.run(["sh", os.path.join(HERE, name), *args], stdout=out, stderr=out, stdin=subprocess.DEVNULL,
                               env=dict(os.environ, WEB=self.web, **env))
        if r.returncode:
            self.log(f"  {name} ended with {r.returncode}")

    def refresh(self):
        """After anything that moves files: the file list again, the holding folder, free space."""
        self.log("\nre-reading the archive after the job...")
        self.build_index()
        self.holding()
        u = shutil.disk_usage(self.arch())
        self.log(f"  free space: {u.free / 1e9:,.1f} GB")

    def holding(self):
        """How much sits in Recently Removed on the archive and each drive plugged in: what
        Delete All would give back (holding-kb<drive>.txt: KB, then files; removed-at says since when)."""
        for top, sfx in [(self.arch(), "")] + [(d["path"], "-" + drive_key(d)) for d in self.drives() if d["connected"]]:
            h, kb, n = self.hold(top), 0, 0
            for root, _, files in os.walk(h):
                for x in files:
                    try: kb += os.lstat(os.path.join(root, x)).st_size // 1024; n += 1
                    except OSError: pass
            if n and not self.removed_at(sfx):
                self.removed_at(sfx, time.time())      # there before its age was noted (renamed from _duplicates): from now
            self.write(f"holding-kb{sfx}.txt", f"{kb} {n}\n")
            self.log(f"Recently Removed{' on ' + top if sfx else ''}: {kb / 1e6:,.1f} GB")

    # ── duplicates: the scan ─────────────────────────────────────────────────
    # ── Rescue: broken copies found, good ones brought back (HOW-IT-WORKS.md → Rescue) ──
    def origins(self, a):
        """Where each file in the archive first came from, from the copy records (_rushes/origin), as the
        helper keeps them: copied/traced lines, carried along by every "moved" line. -> {path now: source}"""
        at = {}
        d = os.path.join(a, "_rushes", "origin")
        for f in sorted(os.listdir(d)) if os.path.isdir(d) else []:
            if not f.endswith(".tsv"):
                continue
            try:
                for l in open(os.path.join(d, f), encoding="utf-8", errors="surrogateescape"):
                    x = l.rstrip("\n").split("\t")
                    if len(x) < 3 or not x[1] or not x[2]:
                        continue
                    if x[0] == "moved":
                        if x[1] in at: at[x[2]] = at.pop(x[1])
                    elif x[0] in ("copied", "traced"):
                        at[x[2]] = x[1]
            except OSError:
                pass
        return at

    def ssh_servers(self):
        """The servers Rushes may look at over SSH (Rescue → A server over SSH): [{name, host, path, key}]."""
        out = []
        for x in (self.settings().get("ssh_sources") or []):
            if isinstance(x, dict) and ssh_ok(x.get("host", ""), x.get("path", "")):
                out.append(dict(x, key=re.sub(r"[^A-Za-z0-9-]", "_", x["host"] + x["path"])))
        return out

    def ssh_list(self, name):
        """One server's files, listed over SSH (names and sizes only: nothing of the footage is read), into
        ssh/<key>.tsv: size, then the full path on the server. Said as it goes; a test first."""
        import shlex
        sv = next((x for x in self.ssh_servers() if x["name"] == name), None)
        if not sv:
            self.log(f"  no server called {name} in Rescue"); return
        st = lambda **k: self.write(f"ssh-{sv['key']}.json", json.dumps(dict(k, at=int(time.time()))))
        st(state="testing")
        t = subprocess.run(["ssh", *SSH_OPTS, "--", sv["host"], "test -d " + shlex.quote(sv["path"]) + " && echo ok"], capture_output=True, text=True, timeout=60)
        if t.stdout.strip() != "ok":
            why = (t.stderr.strip().splitlines() or ["no answer"])[-1][:300]
            st(state="failed", why=why if t.returncode else f"there is no folder {sv['path']} there")
            self.log(f"  {sv['host']}: could not look ({why})"); return
        os.makedirs(self.p("ssh"), exist_ok=True)
        out = self.p(os.path.join("ssh", sv["key"] + ".tsv"))
        # ls -ln, not stat: every NAS has it with the same columns (busybox, GNU, BSD), size fifth, the name after the time
        cmd = f"find {shlex.quote(sv['path'].rstrip('/') + '/')} {SSH_SKIP} -type f -exec ls -ln {{}} +"
        n, t0 = 0, time.time()
        with open(out + ".new", "w") as f:
            p = subprocess.Popen(["ssh", *SSH_OPTS, "--", sv["host"], cmd], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, errors="surrogateescape")
            for l in p.stdout:
                f8 = l.rstrip("\n").split(None, 8)
                size, path = (f8[4], f8[8].replace("//", "/")) if len(f8) == 9 else ("", "")
                if size.isdigit() and path.startswith(sv["path"]):
                    f.write(f"{size}\t{path}\n"); n += 1
                    if n % 5000 == 0:
                        self.log(f"listing: {sv['name']} · {n:,} files so far"); st(state="listing", n=n)
            p.wait()
        if p.returncode not in (0, 1) or not n:
            why = (p.stderr.read().strip().splitlines() or ["nothing listed"])[-1][:300]
            st(state="failed", why=why); self.log(f"  {sv['name']}: listing stopped ({why})")
            try: os.remove(out + ".new")
            except OSError: pass
            return
        os.replace(out + ".new", out)
        st(state="listed", n=n, secs=int(time.time() - t0))
        self.log(f"  {sv['name']}: {n:,} files listed in {int(time.time() - t0)} s")
        self.activity("check", f"Listed {sv['name']} over SSH for Rescue: {n:,} files (nothing on it was changed)")

    def rescue_scan(self):
        """Every clip and sidecar in the archive looked at (rescue-found.tsv): the broken ones, where each came
        from, and the best good copy Rushes can see (its source, or a file of the same name elsewhere in Search,
        on the archive or a drive, that is not broken itself). Nothing is changed."""
        a = self.arch()
        seen, found, t0 = 0, [], time.time()
        top_skip = {"PROXIES", HOLD, OLD_HOLD, "_rushes"}
        for d, dirs, files in os.walk(a):
            # node_modules: an app's code (thousands of small files, some named .mts), never footage
            dirs[:] = [x for x in dirs if x not in SKIP and x != "node_modules" and not x.startswith(".") and not (d == a and x in top_skip)]
            for n in files:
                if n.startswith("."):
                    continue
                p = os.path.join(d, n); seen += 1
                if seen % 5000 == 0:
                    self.log(f"listing: the archive · {seen:,} files so far")
                    self.make_way()
                    if not self.may_v():
                        self.log("  stopped " + ("as asked (Stop)" if self.stop_asked() else "the archive stopped answering, or copying is paused")
                                 + " — look again later"); return
                try: size = os.lstat(p).st_size
                except OSError: continue
                why = broken(p, size)
                if why:
                    found.append((p, size, why))
        self.log(f"  {seen:,} files looked at in {int(time.time() - t0)} s: {len(found):,} broken")
        src = self.origins(a) if found else {}
        db = None
        try:
            dbp = (self.settings().get("archive") or {}).get("database") or self.p("rushes.sqlite")
            db = sqlite3.connect(f"file:{dbp}?mode=ro", uri=True, timeout=30)
        except (sqlite3.Error, OSError):
            pass
        rows, hold = [], os.path.join(a, HOLD) + "/"
        # each server's list (ssh_list), by name: a file of the same name there is a candidate too, "ssh:<name>:<path>"
        remote = {}
        for sv in self.ssh_servers():
            try:
                for l in open(self.p(os.path.join("ssh", sv["key"] + ".tsv")), encoding="utf-8", errors="surrogateescape"):
                    size, _, path = l.rstrip("\n").partition("\t")
                    remote.setdefault(os.path.basename(path), []).append((sv, path, int(size)))
            except (OSError, ValueError):
                pass
        heads, sshread = {}, {}                # the server's first bytes, read once for every candidate there
        if remote:
            want = {}
            for p, _, _ in found:
                for sv, path, size in remote.get(os.path.basename(p), [])[:40]:
                    if size > 0: want.setdefault(sv["host"], {}).setdefault(path, sv)
            for host, ps in want.items():
                name = next(iter(ps.values()))["name"]
                self.log(f"  reading the first bytes of {len(ps):,} files on {name} ({host}), in one connection")
                try: got, said = ssh_heads(host, list(ps))
                except (OSError, subprocess.SubprocessError) as e:
                    got, said = {}, str(e)
                n = sum(1 for v in got.values() if v)
                self.log(f"  {name}: read {n:,} of {len(ps):,}" + (f" (ssh said: {said})" if said and n < len(ps) else ""))
                # said out loud on Rescue's page: a server that could not be read is never a silent "no copy there"
                sshread[name] = {"want": len(ps), "read": n, "why": said if n < len(ps) else ""}
                for path, sv in ps.items(): heads[(sv["name"], path)] = got.get(path)
        for i, (p, size, why) in enumerate(found, 1):
            if i % 50 == 0:
                self.log(f"progress: {i} of {len(found)} ({100 * i // len(found)}%)")
            came = src.get(p, "")
            cands = [came] if came else []
            if db:
                try: cands += [x[0] for x in db.execute("SELECT path FROM files WHERE name = ? AND path != ? AND bytes > 0 LIMIT 40", (os.path.basename(p), p))]
                except sqlite3.Error: pass
            score = lambda c: same_folders(c, p)
            ok = []
            for sv, path, cs in remote.get(os.path.basename(p), [])[:40]:
                h = heads.get((sv["name"], path))
                if cs > 0 and h is not None and broken(path, cs, h) is None:
                    ok.append((f"ssh:{sv['name']}:{path}", cs, score(path)))
            for c in dict.fromkeys(cands):
                if c.startswith(hold) or c == p:
                    continue
                try: cs = os.path.getsize(c)
                except OSError: continue       # not there now (a drive unplugged)
                if cs > 0 and broken(c, cs) is None:
                    ok.append((c, cs, score(c)))
            # Sure only with evidence beyond a name: cameras number clips the same on every card (Clip0004,
            # DJI_0004, C0153, C0051M01.XML, FX30.0944), so a file of the same name may be another shoot. Sure:
            # where it was copied from; or the only one in a folder of the same name (a named folder: a card's
            # own CLIP or PRIVATE is on every card); or, for a clip, a name no camera gives whose copies are all
            # one size. A sidecar (XML) is never sure by its name and size: they are all nearly the same size.
            # Anything else is offered as "same name only", never ticked for you.
            ok.sort(key=lambda x: (x[0] == came, x[2], x[1]), reverse=True)
            good, gsize, other, sure = "", 0, max(0, len(ok) - 1), 0
            if ok:
                good, gsize, k = ok[0]
                if good == came:
                    sure = 1
                elif k >= 1 and sum(1 for x in ok if x[2] == k and x[1] != gsize) == 0:
                    sure = 1
                elif not camera_name(p) and not p.lower().endswith(".xml") and len({x[1] for x in ok}) == 1:
                    sure = 1
            rows.append("\t".join(map(str, (p, size, why, came, good, gsize, other, sure))))
        if db: db.close()
        self.write("rescue-found.tsv", "".join(r + "\n" for r in rows))
        self.write("rescue-looked.txt", f"{int(time.time())}\t{seen}\n")
        self.write("rescue-ssh.json", json.dumps(sshread))
        n = sum(1 for r in rows if r.split("\t")[7] == "1")
        bad = [f"{k}: read {v['read']:,} of {v['want']:,} files there" for k, v in sshread.items() if v["read"] < v["want"]]
        self.activity("check", f"Rescue looked at {seen:,} files: {len(rows):,} broken, {n:,} with a good copy found for sure" + "".join(" · " + x for x in bad))

    def rescue_apply(self, who):
        """The ticked broken files (rescue-pick.tsv: broken, good — written by db/rescue.php from what the scan
        found), each replaced by its good copy: the good one copied in beside it under a temporary name and read
        back byte for byte; the broken one moved to Recently Removed (Delete All may take it: removed-moves.tsv);
        then the good one takes its name, never over another file. Written down in _rushes/origin."""
        a = self.arch()
        try: picks = [l.rstrip("\n").split("\t") for l in open(self.p("rescue-pick.tsv")) if "\t" in l]
        except OSError: picks = []
        done, failed, stopped, t0 = [], 0, False, int(time.time())
        rec = os.path.join(a, "_rushes", "origin"); os.makedirs(rec, exist_ok=True)
        rec = os.path.join(rec, time.strftime("%Y%m%d-%H%M%S") + " archive rescued.tsv")
        for i, (bad, good) in enumerate(picks, 1):
            self.log(f"progress: {i} of {len(picks)} ({100 * i // len(picks)}%)")
            self.write("rescue-at.txt", bad + "\n")          # the one being rescued now, for its row on the page
            self.make_way()
            if not self.may_v():
                stopped = True
                self.log("  stopped " + ("as asked (Stop)" if self.stop_asked() else "the archive stopped answering, or copying is paused")
                         + " — the rest stay as they were"); break
            tmp = None
            try:
                if not ts.inside(bad, a) or ts.inside(bad, os.path.join(a, HOLD)):
                    raise OSError("not a file of the archive")
                if broken(bad, os.path.getsize(bad)) is None:
                    raise OSError("it is not broken any more")
                if good.startswith("ssh:"):
                    # from a server over SSH: read there, and the fingerprint (md5) taken there and here must agree
                    import hashlib as hl, shlex
                    _, name, rpath = good.split(":", 2)
                    sv = next((x for x in self.ssh_servers() if x["name"] == name), None)
                    if not sv or not rpath.startswith(sv["path"].rstrip("/") + "/"):
                        raise OSError(f"{name} is not a server in Rescue any more")
                    out, tmp = ts.new_part(bad); md = hl.md5()
                    with out:
                        pr = subprocess.Popen(["ssh", *SSH_OPTS, "--", sv["host"], "cat " + shlex.quote(rpath)], stdout=subprocess.PIPE)
                        while True:
                            b = pr.stdout.read(ts.CHUNK)
                            if not b: break
                            out.write(b); md.update(b)
                        pr.wait()
                    if pr.returncode != 0:
                        raise OSError("the server stopped sending it")
                    there = subprocess.run(["ssh", *SSH_OPTS, "--", sv["host"], "md5sum " + shlex.quote(rpath)], capture_output=True, text=True, timeout=3600)
                    if there.stdout.split()[:1] != [md.hexdigest()]:
                        raise OSError("the copy's fingerprint did not match the one taken on the server")
                    gs = os.path.getsize(tmp)
                    if broken(bad, gs, open(tmp, "rb").read(16)) is not None:
                        raise OSError("the copy from the server is not good either")
                else:
                    gs = os.path.getsize(good)
                    if broken(good, gs) is not None:
                        raise OSError("the good copy is not good any more")
                    out, tmp = ts.new_part(bad)
                    with out, open(good, "rb") as g:
                        while True:
                            b = g.read(ts.CHUNK)
                            if not b: break
                            out.write(b)
                    if ts.same_bytes(tmp, good) is not True:
                        raise OSError("the copy did not match the good one byte for byte")
                held = os.path.join(a, HOLD, os.path.relpath(bad, a))
                if os.path.lexists(held): held += time.strftime(" (%Y-%m-%d %H%M%S)")
                os.makedirs(os.path.dirname(held), exist_ok=True)
                bsize = os.path.getsize(bad)
                ts.rename_new(bad, held)
                try:
                    ts.rename_new(tmp, bad); tmp = None
                except OSError:
                    ts.rename_new(held, bad)       # the broken one back where it was: the place is never left empty
                    raise
                with open(self.p("removed-moves.tsv"), "a") as f: f.write(f"{bad}\t{held}\n")
                with open(rec, "a") as f: f.write(f"copied\t{good}\t{bad}\t{gs}\trescued; the broken one ({bsize:,} bytes) is in {HOLD}\n")
                with open(self.p("rescue-done.tsv"), "a") as f: f.write(f"{bad}\t{good}\t{gs}\t{int(time.time())}\n")
                done.append(bad)
                self.log(f"  rescued {os.path.relpath(bad, a)} from {good}")
            except OSError as e:
                failed += 1
                self.log(f"  not rescued {bad}: {e.strerror or e}")
                with open(self.p("rescue-failed.tsv"), "a") as f: f.write(f"{bad}\t{e.strerror or e}\t{int(time.time())}\n")
                if tmp:
                    try: os.remove(tmp)
                    except OSError: pass
        if done:
            self.removed_at("", time.time())
        for n in ("rescue-pick.tsv", "rescue-at.txt"):
            try: os.remove(self.p(n))
            except OSError: pass
        # how this run ended, for the page: said there until the next run (db/rescue.php)
        self.write("rescue-run.json", json.dumps({"asked": len(picks), "done": len(done), "failed": failed,
                                                  "stopped": stopped, "from": t0, "at": int(time.time())}))
        left = [l for l in open(self.p("rescue-found.tsv"))] if os.path.exists(self.p("rescue-found.tsv")) else []
        self.write("rescue-found.tsv", "".join(l for l in left if l.split("\t")[0] not in set(done)))
        self.activity("changed", f"Rescued {len(done):,} broken files from their good copies; the broken ones are in Recently Removed"
                      + (f"; {failed} could not be" if failed else ""), who)

    def scan(self):
        """Which files are the same, by every byte: files of one size (from the
        file list, so no walk), then their first and last 64 KB, then all of
        them. A fingerprint is remembered with the file's size, date and own number
        (dup-hashes.tsv), so a scan again reads only what changed, and a scan
        stopped (paused, the drive gone) carries on where it was. The answer is
        results_duplicates.txt, in the form dedupe.sh reads (Czkawka's)."""
        a = self.arch()
        if not os.path.exists(self.p("manifest.tsv")):
            self.log("  no file list yet: Rebuild the file list first"); return
        # The archive, and each drive kept where it is: copies are moved aside only within one;
        # the same file on two of them is often the only backup, so it is only counted.
        roots = [("", a, True)] + [("-" + drive_key(d), d["path"], d["connected"]) for d in self.drives()]
        roots.sort(key=lambda r: -len(r[1]))
        def root_of(path):
            return next((r for r in roots if path.startswith(r[1] + "/")), None)
        skip = tuple(r[1] + x for r in roots for x in (f"/{HOLD}/", f"/{OLD_HOLD}/", "/_rushes/"))
        aside = self.not_compared()
        by_size, listed = {}, set()
        with open(self.p("manifest.tsv"), encoding="utf-8", errors="surrogateescape") as m:
            for line in m:
                size, _, path = line.rstrip("\n").partition("\t")
                listed.add(path)
                r = root_of(path)
                if (not size.isdigit() or int(size) == 0 or r is None or not r[2] or path.startswith(skip)
                        or "/@Recycle/" in path or '"' in path or aside(path)):
                    continue                             # a drive not plugged in is not read now
                by_size.setdefault(int(size), []).append(path)
        groups = {k: v for k, v in by_size.items() if len(v) > 1}
        todo = sum(len(v) for v in groups.values())
        self.log(f"  {todo:,} files share a size with another; reading them to compare")
        cache = {}
        try:
            with open(self.p("dup-hashes.tsv"), encoding="utf-8", errors="surrogateescape") as h:
                for line in h:
                    x = line.rstrip("\n").split("\t", 4)
                    if len(x) == 5: cache[x[4]] = x[:4]          # size, date, first-and-last, every byte
        except OSError:
            pass
        keep, seen, unread, last, stopped = {}, 0, 0, time.time(), False

        def fingerprint(path, size, part, tick=lambda: None):
            key = [str(size), ts.file_key(os.stat(path))]   # size, date to the nanosecond, the file's own number
            got = cache.get(path)
            if not got or got[:2] != key:
                got = cache[path] = key + ["", ""]           # new, or changed since: read again
            i = 2 if part else 3
            if not got[i]:
                h = hashlib.blake2b(digest_size=20)
                with open(path, "rb") as f:
                    if part and size > 131072:
                        h.update(f.read(65536)); f.seek(-65536, 2); h.update(f.read(65536))
                    else:
                        while chunk := f.read(4 << 20):
                            h.update(chunk); tick()
                if os.path.getsize(path) != size:
                    raise OSError("it changed while it was read")
                got[i] = h.hexdigest()
            return got[i]

        out = []
        for size, paths in sorted(groups.items(), reverse=True):        # the biggest first: where the space is
            for part in (True, False):
                split = {}
                for path in paths:
                    self.make_way()
                    if not self.may_v():
                        stopped = True; break
                    try:
                        done, fp = self.watched("Find duplicates, reading", lambda tick: fingerprint(path, size, part, tick))
                        if not done:                     # paused, or the drive stopped giving data
                            stopped = True; break
                        split.setdefault(fp, []).append(path)
                    except OSError:
                        unread += 1
                    if time.time() - last > 30:
                        last = time.time()
                        self.log(f"progress: {seen} of {todo} ({100 * seen // max(todo, 1)}%)")
                if stopped: break
                paths = [p for g in split.values() if len(g) > 1 for p in g]
                if not part:
                    out += [(size, g) for g in split.values() if len(g) > 1]
            seen += len(groups[size])
            if stopped: break
        with open(self.p("dup-hashes.tsv.new"), "w", encoding="utf-8", errors="surrogateescape") as h:
            for path, x in cache.items():
                if path in listed:                       # only files still listed: it never grows for ever
                    h.write("\t".join(x + [path]) + "\n")
        os.replace(self.p("dup-hashes.tsv.new"), self.p("dup-hashes.tsv"))
        if stopped:
            self.log("  stopped: copying was paused, or the archive stopped answering. What was read is remembered: "
                     "Scan the archive again carries on from there. The last results are kept.")
            return
        within, across = {r[0]: [] for r in roots if r[2]}, []
        for size, g in out:
            by = {}
            for p in g:
                by.setdefault(root_of(p)[0], []).append(p)
            for k, ps in by.items():
                if len(ps) > 1:
                    within[k].append((size, ps))
            if len(by) > 1:
                across.append((size, g))
        def results(name, sets):
            with open(self.p(name + ".new"), "w", encoding="utf-8", errors="surrogateescape") as r:
                for size, g in sets:
                    r.write(f"---- Size {size} B ({size} bytes) - {len(g)} files\n")
                    r.writelines(f'"{p}"\n' for p in sorted(g))
                    r.write("\n")
            os.replace(self.p(name + ".new"), self.p(name))
        summary = {"at": int(time.time()), "roots": {}, "across": {
            "sets": len(across), "files": sum(len(g) for _, g in across), "bytes": sum(size for size, _ in across)}}
        for k, sets in within.items():
            results(f"results_duplicates{k}.txt", sets)     # what dedupe.sh reads, one per archive or drive
            summary["roots"][k or "archive"] = {"sets": len(sets), "copies": sum(len(g) - 1 for _, g in sets),
                                                 "bytes": sum(size * (len(g) - 1) for size, g in sets)}
        results("dup-across.txt", across)                  # on two drives or more: shown, left alone
        self.write("dup-summary.json", json.dumps(summary))
        n = sum(len(g) - 1 for _, g in out); b = sum(size * (len(g) - 1) for size, g in out)
        self.log(f"progress: {todo} of {todo} (100%)")
        self.log(f"scan done: {len(out):,} sets of identical files, {n:,} copies beyond the first ({b / 1e9:,.1f} GB)"
                 + (f"; {len(across):,} of those sets are on more than one drive, and are left alone" if across else "")
                 + (f"; {unread:,} files could not be read and were left out" if unread else ""))

    def not_compared(self):
        """-> path -> True for what Duplicates never compares: the system's own files (._ sidecars,
        .DS_Store: rules.json → system_junk; nearly empty and alike, but each belongs to the file
        beside it) and editing caches, which Manage → Cache looks after (Premiere's previews are
        alike across projects by design)."""
        try:
            with open(self.p("rules.json")) as f:
                r = json.load(f)
        except (OSError, ValueError):
            r = {}
        j, c = r.get("system_junk", {}), r.get("cache", {})
        groups = [g for k in ("sweep", "keep") for g in c.get(k, []) if isinstance(g, dict)]
        exts = {e.lower() for g in groups for e in g.get("ext", [])}
        parts = [x.lower() for g in groups for x in g.get("path_contains", [])] + [x.lower() for x in j.get("path_contains", [])]
        names, starts = set(j.get("name_is", [])), tuple(j.get("name_starts", []))
        def aside(path):
            name = path.rsplit("/", 1)[-1]
            if name in names or (starts and name.startswith(starts)):
                return True
            low = path.lower()
            return os.path.splitext(low)[1][1:] in exts or any(x in low for x in parts)
        return aside

    # ── editing caches ───────────────────────────────────────────────────────
    def cache_rule(self, path):
        """'sweep' when rules.json says the path is an editing cache, 'keep' when it
        must never be touched, '' otherwise. Asked again of every file before it is
        moved or deleted: the list (cache-files.txt) is only where to look."""
        with open(self.p("rules.json")) as f:                # the pages' copy: the same rules they listed with
            c = json.load(f)["cache"]
        low, ext = path.lower(), os.path.splitext(path)[1][1:].lower()
        hit = lambda g: ext in [e.lower() for e in g.get("ext", [])] or any(x.lower() in low for x in g.get("path_contains", []))
        if any(hit(g) for g in c.get("keep", []) if isinstance(g, dict)):
            return "keep"
        for g in c.get("sweep", []):
            if isinstance(g, dict) and hit(g):
                return "delete" if g.get("rebuilds") else "sweep"
        return ""

    def cache_clean(self):
        """Remove: every file on cache-files.txt that the rules still call a cache goes into
        Recently Removed/_media-cache on its own drive (cache-moves.tsv, so Recover can).
        Never deleted here: that is Delete All, pressed by a person. -> how many moved."""
        a = self.arch()
        # the archive, and each drive kept where it is and plugged in: each its own holding folder
        tops = sorted([a] + [d["path"] for d in self.drives() if d["connected"]], key=len, reverse=True)
        moved, left = 0, 0
        try:
            paths = open(self.p("cache-files.txt"), encoding="utf-8", errors="surrogateescape").read().splitlines()
        except OSError:
            self.log("  no list: press Remove in Manage → Cache"); return 0
        holds = {t: self.hold(t) for t in tops}
        self.log(f"moving cache files into {HOLD}/_media-cache")
        with open(self.p("cache-moves.tsv"), "a", encoding="utf-8", errors="surrogateescape") as mv:
            for i, f in enumerate(paths):
                if i % 250 == 0:
                    self.log(f"progress: {i} of {len(paths)} ({100 * i // max(len(paths), 1)}%)")
                # never from the recycle bin (that would undelete it) or a holding folder itself
                top = next((t for t in tops if f.startswith(t + "/")), None)
                if (top is None or "/@Recycle/" in f or f.startswith(holds[top] + "/") or f.startswith(top + f"/{OLD_HOLD}/")
                        or ".." in f.split("/") or not os.path.isfile(f) or os.path.islink(f)):
                    continue
                rule = self.cache_rule(f)
                if rule not in ("sweep", "delete"):
                    left += 1; continue
                d = holds[top] + "/_media-cache/" + f[len(top) + 1:]
                if os.path.exists(d):
                    continue
                os.makedirs(os.path.dirname(d), exist_ok=True)
                try: ts.rename_new(f, d)
                except FileExistsError: continue    # something took that name meanwhile: left where it is
                mv.write(f"{f}\t{d}\n"); moved += 1
        self.log(f"moved {moved} cache files" + (f"; {left} on the list are not caches by the rules now, and were left" if left else ""))
        if moved:
            for t in tops:
                self.removed_at("" if t == a else "-" + next((drive_key(d) for d in self.drives() if d["path"] == t), ""), time.time())
        return moved

    def cache_undo(self):
        """Every cache file still in the holding folder goes back where it was. Deleted ones cannot: they rebuild."""
        n = 0
        try:
            lines = open(self.p("cache-moves.tsv"), encoding="utf-8", errors="surrogateescape").read().splitlines()
        except OSError:
            lines = []
        for line in lines:
            src, _, dst = line.partition("\t")
            if os.path.isfile(dst) and not os.path.exists(src):
                os.makedirs(os.path.dirname(src), exist_ok=True)
                try: ts.rename_new(dst, src); n += 1
                except FileExistsError: pass        # something is in its old place already: left in Recently Removed
        self.log(f"recovered {n} cache files")
        return n

    def listing(self, name):
        """-> tick(n) for walk(): "listing: <name> · 12,500 files so far" in the log, every few seconds
        (db/state.php reads it, so the top bar and Activity say what is being listed and how far)."""
        last = [0.0]
        def tick(n):
            if time.time() - last[0] > 3:
                last[0] = time.time(); self.log(f"listing: {name} · {n:,} files so far")
        return tick

    def build_manifest(self, said=False, moved=False):
        """size<TAB>path for every file on the archive (manifest.tsv). A folder
        that cannot be read means a partial list, and a partial list never
        replaces a complete one. Returns a line for the log, or raises."""
        a = self.arch()
        if a is None or not self.may_v():
            raise RuntimeError("the archive is not there, paused, or stopped after not answering")
        started = int(time.time()); n = odd = 0
        new = self.p("manifest.tsv.new")
        with open(new, "w", encoding="utf-8", errors="surrogateescape") as out:
            try:
                n, odd = walk(a, out, self.listing(self.settings().get("archive", {}).get("label") or os.path.basename(a.rstrip("/")) or "the archive"))
            except OSError as e:
                out.close(); os.remove(new)
                raise RuntimeError(f"could not read {getattr(e, 'filename', '') or 'a folder'} ({e.strerror or e})")
            # Drives that come and go: one plugged in is listed now; one away keeps its last list,
            # so its files stay searchable; one that cannot be read keeps its last list too.
            for d in self.drives():
                keep = self.drive_list(d)
                if d["connected"]:
                    try:
                        with open(keep + ".new", "w", encoding="utf-8", errors="surrogateescape") as dl:
                            dn, dodd = walk(d["path"], dl, self.listing(d["name"]))
                        os.replace(keep + ".new", keep); odd += dodd
                        self.log(f"  {d['name']}: {dn} files")
                        if said:            # just plugged in or added: said in Activity, with how many
                            self.activity("changed", f"Listed {d['name']}: {dn:,} {'file' if dn == 1 else 'files'}, found in Search by name")
                    except OSError as e:
                        self.log(f"  {d['name']} could not be read ({e.strerror or e}): its last list is kept")
                elif os.path.exists(keep):
                    self.log(f"  {d['name']} is not plugged in: its last list is kept, so its files stay searchable")
                if os.path.exists(keep):
                    with open(keep, encoding="utf-8", errors="surrogateescape") as dl:
                        for line in dl:
                            out.write(line); n += 1
        old = sum(1 for _ in open(self.p("manifest.tsv"), "rb")) if os.path.exists(self.p("manifest.tsv")) else 0
        # the same guard as runner.sh and sync.php: less than half the last list is a drive that answered partly
        # a new archive (Make this the archive) is another drive: its list is not held against the last one,
        # until its first list is in (archive-moved.txt, written by db/backup.php); it may be empty
        try: moved = moved or open(self.p("archive-moved.txt")).read().strip() == a
        except OSError: pass
        if not moved and ((old > 1000 and n < old // 2) or (old and not n)):
            os.replace(new, self.p("manifest-rejected.tsv"))
            raise RuntimeError(f"refused: {n} files listed where the last list had {old} — kept the last list "
                               "(the new one is manifest-rejected.tsv)")
        self.write("manifest-started.txt", f"{started}\n")
        os.replace(new, self.p("manifest.tsv"))
        if moved:
            try: os.remove(self.p("archive-moved.txt"))
            except OSError: pass
        return n, odd

    def build_index(self, said=False, moved=False):
        """One walk, two files: index.txt is the manifest without its sizes."""
        try:
            n, odd = self.build_manifest(said, moved)
        except Exception as e:
            self.log(f"File list not replaced ({e}); keeping the previous one"); return False
        with open(self.p("manifest.tsv"), encoding="utf-8", errors="surrogateescape") as m, \
             open(self.p("index.txt.new"), "w", encoding="utf-8", errors="surrogateescape") as i:
            for line in m:
                i.write(line.split("\t", 1)[1])
        os.replace(self.p("index.txt.new"), self.p("index.txt"))
        self.log(f"  file list and search index: {n} files, one pass"
                 + (f" ({odd} with a tab or a line break in the name left out)" if odd else ""))
        return True


def walk(top, out, tick=None):
    """size<TAB>path for every file under top, into out; -> (files, names left out).
    tick(n): told how many so far, now and then (the page says it as it goes).
    Raises OSError when a folder or file cannot be read: a partial list is never a list."""
    errors, n, odd = [], 0, 0
    # ponytail: symbolic links are not followed (find -L did); add when an archive needs them
    for root, dirs, files in os.walk(top, onerror=errors.append):
        dirs[:] = [d for d in dirs if d not in SKIP]
        for name in files:
            path = os.path.join(root, name)
            if "\n" in path or "\t" in path:             # would break the list's lines
                odd += 1; continue
            try:
                size = os.lstat(path).st_size
            except OSError as e:
                errors.append(e); break
            out.write(f"{size}\t{path}\n"); n += 1
            if tick and n % 500 == 0:
                tick(n)
        if errors:
            break
    if errors:
        raise errors[0] if isinstance(errors[0], OSError) else OSError(str(errors[0]))
    return n, odd


def drive_key(d):
    """A drive's key in file names: its ID, or (before it was ever seen) its place in Setup."""
    return re.sub(r"[^A-Za-z0-9-]", "_", d.get("id") or hashlib.sha1(d["source"].encode()).hexdigest())


NET_FS = ("smbfs", "afpfs", "nfs", "webdav", "cifs", "smb3", "sshfs", "fuse.sshfs")


def space(path, quiet=False):
    """-> (total, free) in bytes, as df gives them. Python's own (statvfs) wraps round on a Mac for a
    big network share (27 TB free of 5 TB); df asks the way Finder does. A share that does not answer
    within 8 s is not waited on: (0, 0) when quiet, otherwise Python's figure."""
    try:
        r = subprocess.run(["df", "-kP", path], capture_output=True, text=True, timeout=8)
        f = r.stdout.splitlines()[-1].split() if r.returncode == 0 else []
        if len(f) >= 4 and f[1].isdigit() and f[3].isdigit():
            return int(f[1]) * 1024, int(f[3]) * 1024
    except (OSError, subprocess.SubprocessError, IndexError):
        if quiet: return 0, 0
    if quiet: return 0, 0
    u = shutil.disk_usage(path)
    return u.total, u.free


def mount_of(path):
    """The top of the volume a path is on."""
    path = os.path.abspath(path)
    while path != "/" and not os.path.ismount(path):
        path = os.path.dirname(path)
    return path


def mounts():
    """The volumes this computer sees: /Volumes on a Mac, /media and /mnt elsewhere."""
    out = []
    for base in ("/Volumes", "/media", "/mnt") + tuple(filter(None, [os.environ.get("RUSHES_VOLUMES")])):
        try:
            out += [os.path.join(base, n) for n in sorted(os.listdir(base)) if not n.startswith(".")]
        except OSError:
            pass
    return [m for m in out if os.path.isdir(m)]


_ids = {}
def volume_id(path):
    """A volume's own ID: on a Mac its VolumeUUID (diskutil), which stays when the
    drive is renamed or mounted under another name; elsewhere the device number.
    Asked once per volume while it stays mounted."""
    m = mount_of(path)
    try: dev = os.stat(m).st_dev
    except OSError: return ""
    if (m, dev) not in _ids:
        vid = ""
        if shutil.which("diskutil"):
            import plistlib
            try:
                r = subprocess.run(["diskutil", "info", "-plist", m], capture_output=True, timeout=20)
                info = plistlib.loads(r.stdout) if r.returncode == 0 else {}
                vid = info.get("VolumeUUID") or info.get("DiskUUID") or ""
            except Exception:
                pass
        _ids[(m, dev)] = vid or os.environ.get("RUSHES_TEST_VOLUME_ID_" + os.path.basename(m).replace(" ", "_"), "") or f"dev-{dev}"
    return _ids[(m, dev)]


if __name__ == "__main__":
    # A check of its own, on a pretend archive: python3 app/runner.py
    import tempfile
    t = tempfile.mkdtemp(); web, arc = os.path.join(t, "web"), os.path.join(t, "Archive")
    os.makedirs(os.path.join(arc, "Parks", "2026")); os.makedirs(os.path.join(web, "queue")); os.makedirs(os.path.join(arc, ".Trashes"))
    for i in range(3):
        open(os.path.join(arc, "Parks", "2026", f"c{i}.mov"), "w").write("x" * i)
    open(os.path.join(arc, ".Trashes", "gone.mov"), "w").close()
    json.dump({"archive": {"local": arc, "web": web}}, open(os.path.join(web, "settings.json"), "w"))
    r = Runner(web, "http://127.0.0.1:9")                # nobody there: the search update says so, nothing breaks
    assert r.build_index() and open(os.path.join(web, "index.txt")).read().count("\n") == 3
    assert ".Trashes" not in open(os.path.join(web, "manifest.tsv")).read()
    for i in range(3): os.remove(os.path.join(arc, "Parks", "2026", f"c{i}.mov"))
    assert not r.build_index() and open(os.path.join(web, "index.txt")).read().count("\n") == 3, "an empty walk kept the last list"
    open(os.path.join(web, "queue", "1.job"), "w").write("ACTION=reset-breaker\n")
    open(os.path.join(web, "video-tripped.txt"), "w").write("1\tx\n")
    r.minute(); r.busy["jobs"].join(); r.busy["upkeep"].join(30)
    assert not os.path.exists(os.path.join(web, "video-tripped.txt")) and open(os.path.join(web, "job-status.txt")).read() == "idle\n"
    r.minute(); r.busy["upkeep"].join(30)                # Try again done: the archive is reached again
    assert os.path.exists(os.path.join(web, "runner-alive.txt")) and open(os.path.join(web, "disk.txt")).read().startswith("archive ")
    json.dump({"archive": {"local": "/etc/x", "web": web}}, open(os.path.join(web, "settings.json"), "w"))
    assert r.arch() is None, "a system folder is never the archive"
    stall = threading.Event()
    for _ in range(3): r.v("a drive that hangs", stall.wait, 0.2)
    assert r.tripped(), "three stalls stop it"
    shutil.rmtree(t); print("runner.py: all checks pass")
