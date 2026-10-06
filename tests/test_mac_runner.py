"""Rushes on a Mac: the jobs that move files (runner.py with dedupe.sh and
verify.sh), on a pretend drive, with a Mac's stat (BSD: -f, no -c) and the
awk macOS has (original-awk, when installed). Run: python3 -m unittest test_mac_runner
Nothing outside a temporary folder is touched."""
import json, os, shutil, stat, sys, tempfile, unittest, unittest.mock

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
        moved = os.path.join(self.a, "_Recently Removed", "Card dumps/card 1/clip A.mov")
        self.assertTrue(os.path.isfile(moved) and not os.path.exists(copy) and os.path.isfile(kept))
        self.assertEqual(open(os.path.join(self.web, "holding-kb.txt")).read().split()[1], "1", "one file in Recently Removed")
        self.assertGreater(int(open(os.path.join(self.web, "removed-at.txt")).read()), 0, "since when: its age is said")
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
        hold = os.path.join(self.a, "_Recently Removed", "_media-cache")
        self.assertTrue(os.path.isfile(os.path.join(hold, "Edit/Media Cache Files/a.cfa")) and not os.path.exists(cfa))
        self.assertTrue(os.path.isfile(auto) and os.path.isfile(cos), "auto-save and Capture One's edits are never touched")
        self.assertFalse(os.path.exists(cop))
        self.r.job("cache-undo", {})
        self.assertTrue(os.path.isfile(cfa) and os.path.isfile(cop))

    def test_caches_are_never_deleted_by_themselves_even_on_own_drives(self):
        s = json.load(open(os.path.join(self.web, "settings.json"))); s["archive"]["own"] = True     # the old switch: no longer read
        json.dump(s, open(os.path.join(self.web, "settings.json"), "w"))
        cfa, auto, cos, cop = self.caches()
        self.r.job("cacheclean", {"WHO": "Ana"})
        hold = os.path.join(self.a, "_Recently Removed", "_media-cache")
        self.assertTrue(os.path.isfile(os.path.join(hold, "Edit/Media Cache Files/a.cfa")), "moved into Recently Removed, not deleted")
        self.assertFalse(os.path.exists(os.path.join(self.web, "cache-deleted.tsv")))
        said = open(os.path.join(self.web, "activity.tsv")).read()
        self.assertIn("\tAna\tRemoved 2 cache files into Recently Removed", said)

    def test_the_old_holding_folder_becomes_recently_removed_and_recover_still_works(self):
        big = os.urandom(5000)
        kept = self.put("Parks/A.mov", big); old = self.put("_duplicates/Cards/A.mov", big)
        open(os.path.join(self.web, "dedupe-moves.tsv"), "w").write(f"{self.a}/Cards/A.mov\t{old}\t5000\n")
        self.r.holding()
        new = os.path.join(self.a, "_Recently Removed", "Cards/A.mov")
        self.assertTrue(os.path.isfile(new) and not os.path.exists(os.path.join(self.a, "_duplicates")), "renamed, on the same drive")
        self.assertIn(new, open(os.path.join(self.web, "dedupe-moves.tsv")).read(), "the record of what moved follows it")
        self.r.job("undo", {})
        self.assertTrue(os.path.isfile(os.path.join(self.a, "Cards/A.mov")), "Recover finds it in its new place")

    def test_delete_all_deletes_only_recently_removed_when_asked(self):
        big = os.urandom(5000)
        kept = self.put("Parks/A.mov", big); copy = self.put("Card dumps/card 1/A.mov", big)    # the longer path goes
        open(os.path.join(self.web, "dedupe-rules.tsv"), "w").write("1000\tcontains\t/@Recycle/\n")    # run.php writes them
        self.r.build_index(); self.r.job("find", {})
        self.assertEqual(len(open(os.path.join(self.web, "dedupe-plan.tsv")).read().splitlines()), 1, "Find: scan and plan in one")
        self.r.job("apply", {"WHO": "Ana"})
        gone = os.path.join(self.a, "_Recently Removed", "Card dumps/card 1/A.mov")
        self.assertTrue(os.path.isfile(gone))
        self.r.job("empty", {"WHO": "Ana"})
        self.assertFalse(os.path.exists(gone), "deleted, for good")
        self.assertTrue(os.path.isfile(kept), "the copy kept is never touched")
        self.assertNotIn(gone, open(os.path.join(self.web, "dedupe-moves.tsv")).read(), "Recover forgets what is gone")
        self.assertEqual(open(os.path.join(self.web, "holding-kb.txt")).read().split(), ["0", "0"])
        said = open(os.path.join(self.web, "activity.tsv")).read()
        self.assertIn("\tAna\tRemoved 1 duplicate copy on Drive into Recently Removed", said)
        self.assertIn("\tAna\tDeleted for good from Recently Removed on Drive: 1 file", said)

    def test_delete_all_keeps_a_duplicate_whose_kept_copy_changed(self):
        # Report #2: the copy that stays changed after the scan (same size): the one held may be
        # the last good one. verify says so, and Delete All keeps it.
        big = os.urandom(5000)
        kept = self.put("Parks/A.mov", big); self.put("Card dumps/card 1/A.mov", big)
        open(os.path.join(self.web, "dedupe-rules.tsv"), "w").write("1000\tcontains\t/@Recycle/\n")
        self.r.build_index(); self.r.job("find", {}); self.r.job("apply", {})
        held = os.path.join(self.a, "_Recently Removed", "Card dumps/card 1/A.mov")
        open(kept, "wb").write(b"B" * 5000)
        self.r.job("verify", {})
        self.assertIn("VERDICT\tUNSAFE", open(os.path.join(self.web, "verify-result.tsv")).read())
        self.r.job("empty", {"WHO": "Ana"})
        self.assertEqual(open(held, "rb").read(), big, "kept: it may be the last good copy")
        self.assertIn(held, open(os.path.join(self.web, "dedupe-moves.tsv")).read(), "Recover still knows it")
        self.assertIn("no longer the same as the copy that stays", open(os.path.join(self.web, "activity.tsv")).read())

    def test_a_file_delete_all_could_not_delete_keeps_its_record(self):
        # Report #5: one held file cannot be deleted; Recover must still know where it came from.
        a = self.put("_Recently Removed/_media-cache/x.pek", b"1"); b = self.put("_Recently Removed/_media-cache/y.pek", b"2")
        open(os.path.join(self.web, "cache-moves.tsv"), "w").write(f"{self.a}/x.pek\t{a}\n{self.a}/y.pek\t{b}\n")
        self.r.removed_at("", 1000)
        remove = os.remove
        def refuse(p):
            if p == a: raise PermissionError(1, "Operation not permitted")
            remove(p)
        with unittest.mock.patch.object(runner.os, "remove", side_effect=refuse):
            self.r.empty(self.a, "", "Ana")
        moves = open(os.path.join(self.web, "cache-moves.tsv")).read()
        self.assertIn(a, moves); self.assertNotIn(b, moves)
        self.assertEqual(self.r.removed_at(""), 1000, "what is left keeps its age")

    def test_a_stuck_operation_is_not_started_again_beside_itself(self):
        # Report #6: walked away from is not finished.
        stuck, ran = __import__("threading").Event(), []
        self.assertFalse(self.r.v("database copy", stuck.wait, 0.1))
        self.assertFalse(self.r.v("database copy", lambda: ran.append(1)))
        self.assertEqual(ran, [], "not started a second time while the first is still out")
        self.assertIn("has not finished yet", self.log())
        stuck.set(); self.r.pending["database copy"].join(2)
        self.assertTrue(self.r.v("database copy", lambda: ran.append(1)))
        self.assertEqual(ran, [1])

    def test_delete_all_walks_away_from_a_read_that_stalls_and_keeps_the_file(self):
        # Review of 0.12.5 (comment 2): a drive that lists folders but stalls reading a file.
        big = os.urandom(5000)
        self.put("Parks/A.mov", big); self.put("Card dumps/card 1/A.mov", big)
        open(os.path.join(self.web, "dedupe-rules.tsv"), "w").write("1000\tcontains\t/@Recycle/\n")
        self.r.build_index(); self.r.job("find", {}); self.r.job("apply", {})
        held = os.path.join(self.a, "_Recently Removed", "Card dumps/card 1/A.mov")
        stuck = __import__("threading").Event()
        self.r.quiet = 0.5
        with unittest.mock.patch.object(runner.ts, "same_bytes", side_effect=lambda a, b, tick=None: stuck.wait()):
            self.r.job("empty", {})
            self.assertTrue(os.path.isfile(held), "not proven a duplicate: it stays")
            self.assertIn(held, open(os.path.join(self.web, "dedupe-moves.tsv")).read())
            self.assertIn("gave no data for 0.5s", self.log())
            self.r.job("empty", {})                     # pressed again while the first read is still stuck
            self.assertIn("not started again until it does", self.log())
        stuck.set()

    def test_pause_stops_a_long_read_between_pieces(self):
        self.put("x.mov", b"1")
        pieces = []
        def endless(tick):
            while True:
                pieces.append(1); tick()
                if len(pieces) == 3:
                    open(os.path.join(self.web, "helper-control.json"), "w").write('{"paused": true}')
                __import__("time").sleep(0.05)
        done, _ = self.r.watched("a long read", endless)
        self.assertFalse(done)
        self.assertLess(len(pieces), 60, "stopped within a second or so of Pause")

    def test_heavy_reading_waits_while_the_helper_copies(self):
        self.put("x.mov", b"1")                      # the archive is there
        st = os.path.join(self.web, "ingest-status.tsv")
        open(st, "w").write("phase\tcopying\n")
        self.assertTrue(self.r.copying())
        waits = []
        def sleep(_):
            waits.append(1); open(st, "w").write("phase\twaiting\n")     # the copy finishes
        with unittest.mock.patch.object(runner.time, "sleep", side_effect=sleep):
            self.r.make_way()
        self.assertEqual(waits, [1])
        self.assertIn("waiting while copying uses the disk", self.log())
        os.utime(st, (0, 0)); open(st, "a").close(); os.utime(st, (0, 0))
        self.assertFalse(self.r.copying(), "a status nobody has renewed for ten minutes is not a copy")

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
        said = [l.split("\t")[1:] for l in open(os.path.join(self.web, "activity.tsv")).read().splitlines()]
        self.assertEqual([t for _, _, t in said if "Films 1" in t], [
            "Films 1 was plugged in: its list of files is made again",
            "Films 1 was unplugged: Search keeps showing its files, marked not plugged in",
            "Films 1 was plugged in again: its list of files is made again"], "Activity says when a drive comes and goes")
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
        self.assertTrue(os.path.isfile(os.path.join(src, "_Recently Removed", "A long folder/clip.mov")) and os.path.isfile(a))
        self.assertTrue(os.path.isfile(on_drive), "the copy on another drive than the archive's stays")
        self.assertEqual(open(os.path.join(self.web, "holding-kb-UUID-ONE.txt")).read().split()[1], "1", "the drive's own Recently Removed")
        self.r.job("verify", {"DRIVE": src})
        self.assertIn("VERDICT\tSAFE", open(os.path.join(self.web, "verify-result-UUID-ONE.tsv")).read())
        self.r.job("undo", {"DRIVE": src})
        self.assertTrue(os.path.isfile(b))


if __name__ == "__main__":
    unittest.main()


@unittest.skipUnless(shutil.which("ffmpeg"), "needs ffmpeg")
class MacProxies(MacJobs):
    """Proxies made on a Mac for a folder on the list (runner.py), with the records proxy.sh keeps on a NAS."""
    def test_a_folder_on_the_list_gets_its_proxies_and_its_records(self):
        import subprocess, time
        clip = os.path.join(self.a, "Parks", "2026", "A001C002.mov"); os.makedirs(os.path.dirname(clip))
        subprocess.run(["ffmpeg", "-loglevel", "error", "-f", "lavfi", "-i", "testsrc=s=1280x720:d=1", "-f", "lavfi", "-i", "sine=d=1",
                        "-shortest", "-c:v", "mpeg4", "-c:a", "aac", clip], check=True)
        old = time.time() - 3 * 3600; os.utime(clip, (old, old))                 # not still arriving
        self.put("Parks/2026/notes.txt", b"not a video")
        self.put("Parks/2026/._A001C002.mov", b"macOS leftover")
        open(os.path.join(self.web, "proxy-next.txt"), "w").write("Parks\n")
        with unittest.mock.patch.object(self.r, "ffmpeg", return_value=shutil.which("ffmpeg")):
            self.r.minute(); self.r.busy["proxies"].join(60)
        out = os.path.join(self.a, "PROXIES", "Parks", "2026", "A001C002.mp4")
        st = dict(l.rstrip("\n").split("\t", 1) for l in open(os.path.join(self.web, "proxy-status.txt")))
        self.assertTrue(os.path.getsize(out) > 0, "the proxy is made, beside nothing of the footage")
        self.assertEqual((st["state"], st["ok"], st["failed"]), ("done", "1", "0"))
        self.assertEqual(open(os.path.join(self.web, "proxy-folders.tsv")).read().split("\t")[:3], ["Parks", "done", "1"])
        self.assertIn("Duration:", open(os.path.join(self.web, "proxy-made.tsv")).read())          # the media ledger
        self.assertFalse(os.path.exists(os.path.join(self.web, "proxy-next.txt")))
        self.assertFalse([f for f in os.listdir(os.path.dirname(out)) if f.endswith(".part.mp4")])
        # asked again: nothing is made twice
        self.r.job("proxy-build", {"QUERY": "Parks"}); self.r.busy["proxies"].join(60)
        self.assertIn("1 already made", open(os.path.join(self.web, "proxy.log")).read())

    def test_a_light_video_is_used_as_it_is(self):
        """An AI or stock download (H.264, 720p, a few Mbit/s, AAC) needs no copy: it plays as it is."""
        import subprocess, time
        light = os.path.join(self.a, "AI", "openart-video_1.mp4"); os.makedirs(os.path.dirname(light))
        subprocess.run(["ffmpeg", "-loglevel", "error", "-f", "lavfi", "-i", "testsrc=s=1280x720:d=1", "-f", "lavfi", "-i", "sine=d=1",
                        "-shortest", "-c:v", "libx264", "-pix_fmt", "yuv420p", "-b:v", "2M", "-c:a", "aac", light], check=True)
        old = time.time() - 3 * 3600; os.utime(light, (old, old))
        with unittest.mock.patch.object(self.r, "ffmpeg", return_value=shutil.which("ffmpeg")):
            self.r.job("proxy-build", {"QUERY": "AI"}); self.r.busy["proxies"].join(60)
            st = dict(l.rstrip("\n").split("\t", 1) for l in open(os.path.join(self.web, "proxy-status.txt")))
            self.assertEqual((st["state"], st["ok"], st["asis"]), ("done", "1", "1"))
            self.assertFalse(os.path.exists(os.path.join(self.a, "PROXIES", "AI", "openart-video_1.mp4")), "no copy of a light file")
            made = open(os.path.join(self.web, "proxy-made.tsv")).read()
            self.assertTrue(made.startswith(light + "\t") and made.rstrip("\n").endswith("\t1") and "h264" in made)
            self.r.job("proxy-build", {"QUERY": "AI"}); self.r.busy["proxies"].join(60)     # not looked at again
            self.assertEqual(open(os.path.join(self.web, "proxy-made.tsv")).read(), made)
        # what is not light: HEVC, 4K, a heavy bitrate, sound a browser cannot play
        for report in ("Video: hevc (Main), yuv420p(tv), 1920x1080 bitrate: 3000 kb/s Audio: aac",
                       "Video: h264 (High), yuv420p(tv), 3840x2160 bitrate: 9000 kb/s Audio: aac",
                       "Video: h264 (High), yuv420p(tv), 1920x1080 bitrate: 45000 kb/s Audio: aac",
                       "Video: h264 (High), yuv420p(tv), 1920x1080 bitrate: 8000 kb/s Audio: pcm_s16le",
                       "Video: h264 (High 10), yuv420p10le(tv), 1920x1080 bitrate: 8000 kb/s"):
            self.assertFalse(runner.Runner.light(report, "x.mp4"), report)
        self.assertFalse(runner.Runner.light("Video: h264 (High), yuv420p(tv), 1280x720 bitrate: 4000 kb/s", "x.mxf"))
