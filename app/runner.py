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
  - duplicates: the scan (here, in Python: no container), the plan, moving the
    copies aside, putting them back, checking the holding folder (dedupe.sh and
    verify.sh, as on a NAS)
  - editing caches: moved aside, or deleted on a person's own drives (Setup)
  - drives that come and go (Setup 01: Leave media on its own drives): each
    known by its own ID, listed where it is, its last list kept while it is
    away, and listed again when it comes back (drives.json, drives/)

Every touch of the archive has a time limit, and three stalls in a row stop
it touching the archive until Try again — the same breaker as runner.sh.
Proxies are not here yet: those jobs are refused with a line in the log
(ROADMAP.md → Order, 5).
"""
import hashlib, json, os, re, shutil, subprocess, threading, time, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))       # dedupe.sh and verify.sh are beside this file

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

    # ── drives that come and go ──────────────────────────────────────────────
    def settings(self):
        try:
            with open(self.p("settings.json")) as f:
                return json.load(f)
        except (OSError, ValueError):
            return {}

    def drives(self):
        """The drives catalogued where they are: Setup 03's list, when Setup 01 says
        to leave media on its own drives. Each is known by its volume's own ID, not
        its name: a drive plugged in under another name (or "X 1") is found by
        its ID, and another drive that takes its name is not taken for it.
        -> [{source, name, id, path, connected, seen}] (drives.json)."""
        s, a = self.settings(), self.arch()
        if (s.get("organise") or {}).get("shape") != "in_place":
            return []
        try:
            known = {d["source"]: d for d in json.load(open(self.p("drives.json")))}
        except (OSError, ValueError, KeyError, TypeError):
            known = {}
        out = []
        for src in s.get("sources") or []:
            p = str((src or {}).get("path", "")).rstrip("/")
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
                self.write(f"queue/{time.strftime('%Y%m%d-%H%M%S')}-drive.job", "ACTION=reindex\n")
                self.say("plugged in: " + ", ".join(d["name"] for d in back) + " — its list is made again")
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
            try:
                self.job(action, fields)
            except Exception as e:                       # said; the queue carries on
                self.log(f"  {action} stopped: {e}")
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

    def job(self, action, f):
        a = self.arch()
        moving = action in ("plan", "apply", "undo", "verify", "scan", "cacheclean", "cache-undo", "holding")
        if moving and (a is None or not self.may_v()):
            self.log("  the archive is not there, copying is paused, or it stopped after not answering — nothing was done")
            return
        if action in ("reindex", "manifest"):
            self.log("walking the archive once — file list and search index together")
            self.build_index()
        elif action == "reset-breaker":
            for n in ("video-tripped.txt", "video-stalls.txt"):
                try: os.remove(self.p(n))
                except OSError: pass
            self.log("  the archive may be reached again — the next minute tries it")
        elif action in ("plan", "apply", "undo", "verify"):
            # The archive, or one drive kept where it is: each its own plan, moves and holding folder.
            r = self.root(f)
            if r is None:
                self.log(f"  that drive is not plugged in (or not in Setup any more) — nothing was done"); return
            top, sfx = r
            if sfx:
                self.log(f"  on {top}")
            files = dict(RESULTS=self.p(f"results_duplicates{sfx}.txt"), PLAN=self.p(f"dedupe-plan{sfx}.tsv"),
                         MTIMES=self.p(f"dedupe-mtimes{sfx}.txt"), ARCH=top)
            if action == "verify":
                q = re.sub(r"[^A-Za-z0-9 _./&(),+\x80-\U0010ffff-]", "", f.get("QUERY", ""))[:200]
                if ".." in q:
                    self.log("  refused a folder with .. in it"); return
                self.script("verify.sh", [q] if q else [], PLAN=files["PLAN"], MOVES=self.p(f"dedupe-moves{sfx}.tsv"),
                            OUT=self.p(f"verify-result{sfx}.tsv"), HOLD=top + "/_duplicates", ARCH=top)
                return
            # never trust the queue file (as runner.sh): only these, only inside the archive (or that drive)
            keep = f.get("KEEP_SIDE") if f.get("KEEP_SIDE") in ("project", "card", "short", "oldest") else "project"
            dest = f.get("DEST", "") if not sfx else ""
            if not dest.startswith(top + "/") or ".." in dest.split("/"):
                dest = top + "/_duplicates"
            self.script("dedupe.sh", {"plan": [], "apply": ["--apply"], "undo": ["--undo"]}[action],
                        KEEP_SIDE=keep, DEST=dest, LOG=self.p(f"dedupe-moves{sfx}.tsv"), **files)
            if action != "plan":
                self.refresh()
        elif action == "scan":
            self.scan()
        elif action in ("cacheclean", "cache-undo"):
            (self.cache_clean if action == "cacheclean" else self.cache_undo)()
            self.refresh()
        elif action == "holding":
            self.holding()
        elif action == "df":
            u = shutil.disk_usage(a)
            self.log(f"  {a}: {u.free / 1e9:,.1f} GB free of {u.total / 1e9:,.1f} GB")
        elif action == "organize-undo":
            self.log("  the old date-based layout was never used on this computer: nothing to put back")
        elif action != "refused":
            # ponytail: proxies come with the app's own describing (ROADMAP.md → Order, 5)
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
        """How much sits in each holding folder: what emptying it would give back
        (holding-kb.txt for the archive, holding-kb-<drive>.txt for each drive plugged in)."""
        for top, sfx in [(self.arch(), "")] + [(d["path"], "-" + drive_key(d)) for d in self.drives() if d["connected"]]:
            h, kb = os.path.join(top, "_duplicates"), 0
            for root, _, files in os.walk(h):
                for n in files:
                    try: kb += os.lstat(os.path.join(root, n)).st_size // 1024
                    except OSError: pass
            self.write(f"holding-kb{sfx}.txt", f"{kb}\n")
            self.log(f"holding folder{' on ' + top if sfx else ''}: {kb / 1e6:,.1f} GB")

    # ── duplicates: the scan ─────────────────────────────────────────────────
    def scan(self):
        """Which files are the same, by every byte: files of one size (from the
        file list, so no walk), then their first and last 64 KB, then all of
        them. A fingerprint is remembered with the file's size and date
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
        skip = tuple(r[1] + x for r in roots for x in ("/_duplicates/", "/_rushes/"))
        by_size, listed = {}, set()
        with open(self.p("manifest.tsv"), encoding="utf-8", errors="surrogateescape") as m:
            for line in m:
                size, _, path = line.rstrip("\n").partition("\t")
                listed.add(path)
                r = root_of(path)
                if (not size.isdigit() or int(size) == 0 or r is None or not r[2] or path.startswith(skip)
                        or "/@Recycle/" in path or '"' in path):
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

        def fingerprint(path, size, part):
            key = [str(size), str(int(os.stat(path).st_mtime))]
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
                            h.update(chunk)
                if os.path.getsize(path) != size:
                    raise OSError("it changed while it was read")
                got[i] = h.hexdigest()
            return got[i]

        out = []
        for size, paths in sorted(groups.items(), reverse=True):        # the biggest first: where the space is
            for part in (True, False):
                split = {}
                for path in paths:
                    if not self.may_v():
                        stopped = True; break
                    try:
                        split.setdefault(fingerprint(path, size, part), []).append(path)
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

    def own_drives(self):
        try:
            with open(self.p("settings.json")) as f:
                return json.load(f).get("archive", {}).get("own") is True
        except (OSError, ValueError, AttributeError):
            return False

    def cache_clean(self):
        """Every file on cache-files.txt that the rules still call a cache: deleted
        when the archive is a person's own drives and that kind rebuilds itself
        (cache-deleted.tsv says what went), moved into _duplicates/_media-cache
        otherwise (cache-moves.tsv, so Put them back can)."""
        a = self.arch()
        # the archive, and each drive kept where it is and plugged in: each its own holding folder
        tops = sorted([a] + [d["path"] for d in self.drives() if d["connected"]], key=len, reverse=True)
        own, moved, deleted, left = self.own_drives(), 0, 0, 0
        try:
            paths = open(self.p("cache-files.txt"), encoding="utf-8", errors="surrogateescape").read().splitlines()
        except OSError:
            self.log("  no list: press Move them out in Manage → Cache"); return
        self.log("deleting caches that rebuild themselves, moving the rest to _duplicates/_media-cache" if own
                 else "moving cache files to _duplicates/_media-cache")
        with open(self.p("cache-moves.tsv"), "a", encoding="utf-8", errors="surrogateescape") as mv, \
             open(self.p("cache-deleted.tsv"), "a", encoding="utf-8", errors="surrogateescape") as gone:
            for i, f in enumerate(paths):
                if i % 250 == 0:
                    self.log(f"progress: {i} of {len(paths)} ({100 * i // max(len(paths), 1)}%)")
                # never from the recycle bin (that would undelete it) or a holding folder itself
                top = next((t for t in tops if f.startswith(t + "/")), None)
                if (top is None or "/@Recycle/" in f or f.startswith(top + "/_duplicates/")
                        or ".." in f.split("/") or not os.path.isfile(f) or os.path.islink(f)):
                    continue
                rule = self.cache_rule(f)
                if rule not in ("sweep", "delete"):
                    left += 1; continue
                if own and rule == "delete":
                    size = os.path.getsize(f)
                    os.remove(f)
                    gone.write(f"{int(time.time())}\t{size}\t{f}\n"); deleted += 1
                    continue
                d = top + "/_duplicates/_media-cache/" + f[len(top) + 1:]
                if os.path.exists(d):
                    continue
                os.makedirs(os.path.dirname(d), exist_ok=True)
                os.rename(f, d)
                mv.write(f"{f}\t{d}\n"); moved += 1
        self.log(f"moved {moved} cache files" + (f", deleted {deleted} that rebuild themselves" if own else "")
                 + (f"; {left} on the list are not caches by the rules now, and were left" if left else ""))

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
                os.rename(dst, src); n += 1
        self.log(f"put back {n} cache files")

    def build_manifest(self):
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
                n, odd = walk(a, out)
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
                            dn, dodd = walk(d["path"], dl)
                        os.replace(keep + ".new", keep); odd += dodd
                        self.log(f"  {d['name']}: {dn} files")
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


def walk(top, out):
    """size<TAB>path for every file under top, into out; -> (files, names left out).
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
        if errors:
            break
    if errors:
        raise errors[0] if isinstance(errors[0], OSError) else OSError(str(errors[0]))
    return n, odd


def drive_key(d):
    """A drive's key in file names: its ID, or (before it was ever seen) its place in Setup."""
    return re.sub(r"[^A-Za-z0-9-]", "_", d.get("id") or hashlib.sha1(d["source"].encode()).hexdigest())


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
