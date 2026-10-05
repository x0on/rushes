# Rushes — Media Management Software, by Alejandro Renteria.
# Source available: https://github.com/x0on/rushes
"""The minute's work, for a Rushes whose archive is a drive on this computer.

runner.sh does this on a NAS, as root, from cron. On a Mac, Rushes Helper runs
Rushes itself (rushes_helper.py --server) and calls Runner.minute() once a
minute. Same files in the web folder, same rules (HOW-IT-WORKS.md → The
minute's work); only what a drive on this computer needs:

  - the heartbeat (runner-alive.txt), free space (disk.txt), load (load.txt)
  - the search update (import.php ?part=web, then ?part=video)
  - the daily database copy onto the archive (_rushes/db-copies)
  - the private-file check (exposed.txt)
  - the queue: Rebuild the file list (reindex, manifest), Try again (reset-breaker);
    and the first file list of a new archive, by itself

Every touch of the archive has a time limit, and three stalls in a row stop
it touching the archive until Try again — the same breaker as runner.sh.
Jobs that move files (duplicates, caches, proxies) are not here yet: they are
refused with a line in the log (ROADMAP.md → Order, 2 and 3).
"""
import json, os, shutil, threading, time, urllib.request

SKIP = {"@Recycle", ".Trashes", ".Spotlight-V100", ".fseventsd", ".DocumentRevisions-V100",
        ".TemporaryItems", ".DS_Store_cache"}
TRIM = ("helper.log", "proxy.log", "proxy-built.tsv", "proxy-built.tsv.err", "proxy-speed.tsv", "proxy-failed.tsv", "php-errors.log")
PRIVATE = ("rushes.sqlite", "db-copy.sqlite", "ingest-queue.tsv", "helper-refused.tsv")


def archive_ok(a, web):
    """An archive: a folder that exists, at least two deep, never a system folder, never the web folder."""
    bad = ("/System", "/Library", "/usr", "/bin", "/sbin", "/etc", "/private", "/Applications", "/dev", "/Users/Shared")
    return (a.startswith("/") and a.count("/") >= 2 and "/." not in a and ".." not in a.split("/")
            and not a.startswith(bad) and a != web and not a.startswith(web + "/") and os.path.isdir(a))


class Runner:
    def __init__(self, web, url, limit=20):
        self.web, self.url, self.limit = web, url.rstrip("/"), limit
        self.busy = {}                                   # "upkeep" / "jobs": the thread doing it

    # ── small things ─────────────────────────────────────────────────────────
    def p(self, name):
        return os.path.join(self.web, name)

    def write(self, name, text):
        with open(self.p(name) + ".new", "w") as f:
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
        return not paused and not self.tripped() and self.arch() is not None

    def v(self, what, fn, limit=None):
        """fn() in a thread, waited for at most `limit` seconds; one that has not
        finished is walked away from and counted. True when it finished."""
        out = {}
        def go():
            try: out["ok"] = fn() is not False
            except Exception as e: out["why"] = e          # answered, with an error: not a stall
        t = threading.Thread(target=go, daemon=True)
        t.start(); t.join(limit or self.limit)
        if not t.is_alive():
            if "why" in out: self.say(f"{what}: {out['why']}")
            return out.get("ok", False)
        self.stalled = True
        try: n = int(open(self.p("video-stalls.txt")).read()) + 1
        except (OSError, ValueError): n = 1
        self.write("video-stalls.txt", str(n))
        self.say(f"the archive did not answer within {limit or self.limit}s ({what}) — {n} in a row")
        if n >= 3 and not self.tripped():
            self.write("video-tripped.txt", f"{int(time.time())}\tthe archive did not answer {n} times in a row (last: {what})\n")
            self.say("STOPPED touching the archive until Try again is pressed in Manage")
        return False

    # ── the minute ───────────────────────────────────────────────────────────
    def minute(self):
        if os.path.exists(self.p("STOP")):
            return
        os.makedirs(self.p("queue"), exist_ok=True)
        self.write("runner-alive.txt", f"{int(time.time())}\n")
        t0, self.stalled = time.time(), False
        a = self.arch()
        if self.may_v():
            def df():
                u = shutil.disk_usage(a)       # the columns of df -P, in KB, as state.php reads them
                self.write("disk.txt", f"archive {u.total // 1024} {u.used // 1024} {u.free // 1024} "
                                       f"{round(100 * u.used / u.total) if u.total else 0}% {a}\n")
            self.v("free space", df)
        # The helper runs in Rushes Helper itself, beside this; Setup asks.
        self.write("helper-builtin.txt", "running\n")
        load = " ".join(f"{x:.2f}" for x in os.getloadavg())
        self.write("load.txt", f"{int(time.time())}\t{load}\t{int(time.time() - t0)}\n")
        for name, fn in (("upkeep", self.upkeep), ("jobs", self.jobs)):
            if not (self.busy.get(name) and self.busy[name].is_alive()):      # the last one still at it: left alone
                self.busy[name] = threading.Thread(target=self.guarded, args=(name, fn), daemon=True)
                self.busy[name].start()

    def guarded(self, name, fn):
        try:
            fn()
        except Exception as e:                           # said, and tried again next minute
            self.say(f"{name}: {e}")

    # ── upkeep ───────────────────────────────────────────────────────────────
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
            self.write("job-status.txt", f"running: {action}\n")
            self.log(f"\n{'=' * 62}\n{time.strftime('%Y-%m-%d %H:%M:%S')}  {action}\n{'=' * 62}")
            if action in ("reindex", "manifest"):
                self.log("walking the archive once — file list and search index together")
                self.build_index()
            elif action == "reset-breaker":
                for n in ("video-tripped.txt", "video-stalls.txt"):
                    try: os.remove(self.p(n))
                    except OSError: pass
                self.log("  the archive may be reached again — the next minute tries it")
            elif action != "refused":
                # ponytail: moving jobs come with organizing a drive (ROADMAP.md → Order, 2)
                self.log(f"  {action}: not on this computer yet — nothing was done")
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

    def build_manifest(self):
        """size<TAB>path for every file on the archive (manifest.tsv). A folder
        that cannot be read means a partial list, and a partial list never
        replaces a complete one. Returns a line for the log, or raises."""
        a = self.arch()
        if a is None or not self.may_v():
            raise RuntimeError("the archive is not there, paused, or stopped after not answering")
        started = int(time.time()); errors = []; n = odd = 0
        new = self.p("manifest.tsv.new")
        # ponytail: symbolic links are not followed (find -L did); add when an archive needs them
        with open(new, "w", encoding="utf-8", errors="surrogateescape") as out:
            for root, dirs, files in os.walk(a, onerror=errors.append):
                dirs[:] = [d for d in dirs if d not in SKIP]
                for name in files:
                    path = os.path.join(root, name)
                    if "\n" in path or "\t" in path:     # would break the list's lines
                        odd += 1; continue
                    try:
                        size = os.lstat(path).st_size
                    except OSError as e:
                        errors.append(e); break
                    out.write(f"{size}\t{path}\n"); n += 1
                if errors:
                    break
        if errors:
            os.remove(new)
            raise RuntimeError(f"could not read {getattr(errors[0], 'filename', '') or 'a folder'} ({errors[0].strerror or errors[0]})")
        old = sum(1 for _ in open(self.p("manifest.tsv"), "rb")) if os.path.exists(self.p("manifest.tsv")) else 0
        # the same guard as runner.sh and sync.php: less than half the last list is a drive that answered partly
        if (old > 1000 and n < old // 2) or (old and not n):
            os.replace(new, self.p("manifest-rejected.tsv"))
            raise RuntimeError(f"refused: {n} files listed where the last list had {old} — kept the last list "
                               "(the new one is manifest-rejected.tsv)")
        self.write("manifest-started.txt", f"{started}\n")
        os.replace(new, self.p("manifest.tsv"))
        return n, odd

    def build_index(self):
        """One walk, two files: index.txt is the manifest without its sizes."""
        try:
            n, odd = self.build_manifest()
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
