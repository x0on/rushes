"""Rushes on a Mac: the jobs that move files (runner.py with dedupe.sh and
verify.sh), on a pretend drive, with a Mac's stat (BSD: -f, no -c) and the
awk macOS has (original-awk, when installed). Run: python3 -m unittest test_mac_runner
Nothing outside a temporary folder is touched."""
import json, os, shutil, stat, sys, tempfile, unittest

HERE = os.path.dirname(os.path.abspath(__file__))
APP = os.path.join(HERE, "..", "app")
sys.path.insert(0, APP)
import runner

# A Mac's stat: -f with %z (size), %m (date), %N (name); -c is refused, as BSD stat does.
BSD_STAT = r'''#!/usr/bin/env python3
import os, sys
a = sys.argv[1:]
if not a or a[0] != "-f":
    sys.stderr.write("stat: illegal option -- c\n"); sys.exit(1)
fmt, rc = a[1], 0
for p in a[2:]:
    try: st = os.stat(p)
    except OSError: rc = 1; continue
    print(fmt.replace("%z", str(st.st_size)).replace("%m", str(int(st.st_mtime))).replace("%N", p))
sys.exit(rc)
'''


class MacJobs(unittest.TestCase):
    def setUp(self):
        self.t = tempfile.mkdtemp()
        self.web, self.a = os.path.join(self.t, "web"), os.path.join(self.t, "Drive")
        os.makedirs(os.path.join(self.web, "queue"))
        shutil.copy(os.path.join(APP, "rules.json"), self.web)
        json.dump({"archive": {"local": self.a, "web": self.web}}, open(os.path.join(self.web, "settings.json"), "w"))
        b = os.path.join(self.t, "bin"); os.makedirs(b)
        open(os.path.join(b, "stat"), "w").write(BSD_STAT)
        os.chmod(os.path.join(b, "stat"), 0o755)
        if shutil.which("original-awk"):
            os.symlink(shutil.which("original-awk"), os.path.join(b, "awk"))
        self.path = os.environ["PATH"]; os.environ["PATH"] = b + os.pathsep + self.path
        self.r = runner.Runner(self.web, "http://127.0.0.1:9")

    def tearDown(self):
        os.environ["PATH"] = self.path
        shutil.rmtree(self.t)

    def put(self, rel, data):
        p = os.path.join(self.a, rel); os.makedirs(os.path.dirname(p), exist_ok=True)
        open(p, "wb").write(data); return p

    def log(self):
        return open(os.path.join(self.web, "job.log")).read()

    def test_duplicates_scan_plan_move_check_put_back(self):
        big = os.urandom(300_000)
        kept = self.put("Parks/2026/A.mov", big)
        copy = self.put("Card dumps/card 1/clip A.mov", big)
        # same size, same first and last 64 KB, one byte different in the middle: not the same file
        other = bytearray(big); other[150_000] ^= 1
        self.put("Card dumps/card 1/B.mov", bytes(other))
        for i in (1, 2):                              # identical frames of a sequence: never moved
            self.put(f"Parks/matte/m_{i:05d}.png", b"same")
        self.assertTrue(self.r.build_index())
        self.r.job("scan", {})
        res = open(os.path.join(self.web, "results_duplicates.txt")).read()
        self.assertEqual(res.count("---- Size"), 2, res)            # the clip, and the frames
        self.assertIn(f'"{copy}"', res); self.assertNotIn("B.mov", res)
        self.assertTrue(os.path.getsize(os.path.join(self.web, "dup-hashes.tsv")))
        open(os.path.join(self.web, "dedupe-rules.tsv"), "w").write("1000\tcontains\t/@Recycle/\n")
        self.r.job("plan", {"KEEP_SIDE": "short"})
        plan = open(os.path.join(self.web, "dedupe-plan.tsv")).read().splitlines()
        self.assertEqual(len(plan), 1, plan)
        self.assertEqual(plan[0].split("\t")[1:], [copy, kept])
        self.r.job("apply", {"KEEP_SIDE": "short", "DEST": "/etc"})               # a holding folder outside: refused
        moved = os.path.join(self.a, "_duplicates", "Card dumps/card 1/clip A.mov")
        self.assertTrue(os.path.isfile(moved) and not os.path.exists(copy) and os.path.isfile(kept))
        self.assertGreater(int(open(os.path.join(self.web, "holding-kb.txt")).read()), 0)
        self.assertNotIn(copy, open(os.path.join(self.web, "index.txt")).read())
        self.r.job("verify", {})
        self.assertIn("VERDICT\tSAFE", open(os.path.join(self.web, "verify-result.tsv")).read(), self.log())
        self.r.job("undo", {})
        self.assertTrue(os.path.isfile(copy) and not os.path.exists(moved))

    def test_mtimes_with_a_macs_stat(self):
        old = self.put("Parks/old.mov", b"x" * 5000); os.utime(old, (1e9, 1e9))
        new = self.put("A long folder name/new.mov", b"x" * 5000)
        self.r.build_index(); self.r.job("scan", {})
        open(os.path.join(self.web, "dedupe-rules.tsv"), "w").write("1000\tcontains\t/@Recycle/\n")
        self.r.job("plan", {"KEEP_SIDE": "oldest"})
        self.assertEqual(open(os.path.join(self.web, "dedupe-plan.tsv")).read().split("\t")[1:],
                         [new, old + "\n"], self.log())

    def caches(self):
        files = [self.put("Edit/Media Cache Files/a.cfa", b"c" * 10),
                 self.put("Edit/Adobe Premiere Pro Auto-Save/p.prproj", b"p"),
                 self.put("Photos/CaptureOne/Settings153/IMG.cos", b"edits"),
                 self.put("Photos/CaptureOne/Cache/Proxies/IMG.cop", b"proxy")]
        open(os.path.join(self.web, "cache-files.txt"), "w").write("\n".join(files + ["/etc/passwd"]) + "\n")
        return files

    def test_caches_moved_aside_on_a_shared_archive_and_put_back(self):
        cfa, auto, cos, cop = self.caches()
        self.r.job("cacheclean", {})
        hold = os.path.join(self.a, "_duplicates", "_media-cache")
        self.assertTrue(os.path.isfile(os.path.join(hold, "Edit/Media Cache Files/a.cfa")) and not os.path.exists(cfa))
        self.assertTrue(os.path.isfile(auto) and os.path.isfile(cos), "auto-save and Capture One's edits are never touched")
        self.assertFalse(os.path.exists(cop))
        self.r.job("cache-undo", {})
        self.assertTrue(os.path.isfile(cfa) and os.path.isfile(cop))

    def test_caches_deleted_on_a_persons_own_drives(self):
        s = json.load(open(os.path.join(self.web, "settings.json"))); s["archive"]["own"] = True
        json.dump(s, open(os.path.join(self.web, "settings.json"), "w"))
        cfa, auto, cos, cop = self.caches()
        self.r.job("cacheclean", {})
        self.assertFalse(os.path.exists(cfa) or os.path.exists(cop))
        self.assertFalse(os.path.exists(os.path.join(self.a, "_duplicates")))
        self.assertTrue(os.path.isfile(auto) and os.path.isfile(cos))
        gone = open(os.path.join(self.web, "cache-deleted.tsv")).read()
        self.assertIn(cfa, gone); self.assertIn(cop, gone); self.assertNotIn("passwd", gone)

    def test_nothing_moves_while_paused(self):
        p = self.put("a/x.mov", b"1"); self.put("b/x.mov", b"1")
        self.r.build_index()
        open(os.path.join(self.web, "helper-control.json"), "w").write('{"paused": true}')
        self.r.job("scan", {})
        self.assertFalse(os.path.exists(os.path.join(self.web, "results_duplicates.txt")))
        self.assertIn("nothing was done", self.log())


class Drives(MacJobs):
    """Drives that come and go: known by their ID, listed where they are, their
    last list kept while away, found again under another name, duplicates only
    within a drive, the same file on two drives left alone."""
    def setUp(self):
        super().setUp()
        self.vol = os.path.join(self.t, "Volumes"); os.makedirs(self.vol)
        self.ids = {"Films 1": "UUID-ONE", "Films 1 1": "UUID-ONE", "Other": "UUID-OTHER"}
        self.patch = [(runner, n, getattr(runner, n)) for n in ("mounts", "mount_of", "volume_id")]
        runner.mounts = lambda: [os.path.join(self.vol, n) for n in sorted(os.listdir(self.vol))]
        runner.mount_of = lambda p: os.path.join(self.vol, p[len(self.vol) + 1:].split("/")[0]) if p.startswith(self.vol + "/") else "/"
        runner.volume_id = lambda p: self.ids.get(runner.mount_of(p)[len(self.vol) + 1:], "")
        s = json.load(open(os.path.join(self.web, "settings.json")))
        s["organise"] = {"shape": "in_place"}
        s["sources"] = [{"label": "Films 1", "path": os.path.join(self.vol, "Films 1")}]
        json.dump(s, open(os.path.join(self.web, "settings.json"), "w"))

    def tearDown(self):
        for mod, n, f in self.patch:
            setattr(mod, n, f)
        super().tearDown()

    def drive(self, rel, data, name="Films 1"):
        p = os.path.join(self.vol, name, rel); os.makedirs(os.path.dirname(p), exist_ok=True)
        open(p, "wb").write(data); return p

    def minute(self):
        self.r.minute(); self.r.busy["jobs"].join(); self.r.busy["upkeep"].join(60)
        return {d["name"]: d for d in json.load(open(os.path.join(self.web, "drives.json")))}

    def test_a_drive_is_listed_kept_while_away_and_found_under_another_name(self):
        self.put("Parks/a.mov", b"a")
        clip = self.drive("Shoots/2019/b.mov", b"bb")
        d = self.minute()["Films 1"]                            # plugged in: listed by itself
        self.assertTrue(d["connected"]); self.assertEqual(d["id"], "UUID-ONE")
        self.minute()
        self.assertIn(clip, open(os.path.join(self.web, "index.txt")).read())
        os.rename(os.path.join(self.vol, "Films 1"), os.path.join(self.t, "away"))            # unplugged
        self.assertFalse(self.minute()["Films 1"]["connected"])
        self.r.build_index()
        self.assertIn(clip, open(os.path.join(self.web, "index.txt")).read(), "an unplugged drive stays searchable")
        os.rename(os.path.join(self.t, "away"), os.path.join(self.vol, "Films 1 1"))           # back, as "Films 1 1"
        d = self.minute()["Films 1"]
        self.assertTrue(d["connected"]); self.assertEqual(d["path"], os.path.join(self.vol, "Films 1 1"))
        self.minute()
        idx = open(os.path.join(self.web, "index.txt")).read()
        self.assertIn(os.path.join(self.vol, "Films 1 1", "Shoots/2019/b.mov"), idx); self.assertNotIn(clip, idx)
        self.ids["Films 1"] = "UUID-SOMEONE-ELSES"
        self.drive("x.mov", b"x", name="Films 1")               # another drive takes the old name: not it
        self.assertEqual(self.minute()["Films 1"]["path"], os.path.join(self.vol, "Films 1 1"))

    def test_duplicates_within_a_drive_move_aside_there_and_across_drives_stay(self):
        both = os.urandom(5000)
        self.put("Parks/same.mov", both); on_drive = self.drive("Card/same.mov", both)
        twice = os.urandom(7000)
        a = self.drive("A/clip.mov", twice); b = self.drive("A long folder/clip.mov", twice)
        self.minute(); self.minute()
        self.r.job("scan", {})
        summary = json.load(open(os.path.join(self.web, "dup-summary.json")))
        self.assertEqual(summary["across"]["sets"], 1)
        self.assertEqual(open(os.path.join(self.web, "results_duplicates.txt")).read(), "", "nothing within the archive")
        open(os.path.join(self.web, "dedupe-rules.tsv"), "w").write("1000\tcontains\t/@Recycle/\n")
        src = os.path.join(self.vol, "Films 1")
        self.r.job("plan", {"KEEP_SIDE": "short", "DRIVE": src})
        self.r.job("apply", {"KEEP_SIDE": "short", "DRIVE": src})
        self.assertTrue(os.path.isfile(os.path.join(src, "_duplicates", "A long folder/clip.mov")) and os.path.isfile(a))
        self.assertTrue(os.path.isfile(on_drive), "the copy on another drive than the archive's stays")
        self.assertGreater(int(open(os.path.join(self.web, f"holding-kb-UUID-ONE.txt")).read()) + 1, 0)
        self.r.job("verify", {"DRIVE": src})
        self.assertIn("VERDICT\tSAFE", open(os.path.join(self.web, "verify-result-UUID-ONE.tsv")).read())
        self.r.job("undo", {"DRIVE": src})
        self.assertTrue(os.path.isfile(b))


if __name__ == "__main__":
    unittest.main()
