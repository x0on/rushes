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
        # never compared: the system's sidecars, and Premiere's previews (Cache looks after those)
        for d in ("Parks", "Library"):
            self.put(f"{d}/._clip.mov", b"\0" * 4096); self.put(f"{d}/Audio Previews/x 48000.cfa", b"cfa" * 100)
        self.assertTrue(self.r.build_index())
        self.r.job("scan", {})
        res = open(os.path.join(self.web, "results_duplicates.txt")).read()
        self.assertEqual(res.count("---- Size"), 2, res)            # the clip, and the frames
        self.assertIn(f'"{copy}"', res); self.assertNotIn("B.mov", res)
        self.assertNotIn("._clip", res); self.assertNotIn(".cfa", res)
        self.assertTrue(os.path.getsize(os.path.join(self.web, "dup-hashes.tsv")))
        # the card dump is in another top folder: it goes only because a rule says it passes files through
        open(os.path.join(self.web, "dedupe-rules.tsv"), "w").write("1000\tcontains\t/@Recycle/\n500\tcontains\t/Card dumps/\n")
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
        new = self.put("Parks/A long folder name/new.mov", b"x" * 5000)
        self.r.build_index(); self.r.job("scan", {})
        open(os.path.join(self.web, "dedupe-rules.tsv"), "w").write("1000\tcontains\t/@Recycle/\n")
        self.r.job("plan", {"KEEP_SIDE": "oldest"})
        self.assertEqual(open(os.path.join(self.web, "dedupe-plan.tsv")).read().split("\t")[1:],
                         [new, old + "\n"], self.log())

    def test_different_jobs_left_alone_and_remove_one_kind(self):
        same = os.urandom(200_000)
        a = self.put("Parks Promo/clip.mov", same); b = self.put("Library Opening/clip.mov", same)   # two jobs
        img = os.urandom(5000)
        i1 = self.put("Parks Promo/photos/IMG_1.HEIC", img); i2 = self.put("Parks Promo/photos/IMG_1 2.HEIC", img)
        w = os.urandom(7000)
        w1 = self.put("Parks Promo/AUDIO/steps.wav", w); w2 = self.put("Parks Promo/AUDIO/MATERIAL/steps.wav", w)
        self.r.build_index(); self.r.job("scan", {})
        open(os.path.join(self.web, "dedupe-rules.tsv"), "w").write("1000\tcontains\t/@Recycle/\n")
        self.r.job("plan", {"KEEP_SIDE": "short"})
        plan = [l.split("\t") for l in open(os.path.join(self.web, "dedupe-plan.tsv")).read().splitlines()]
        self.assertEqual(sorted(p[1] for p in plan), sorted([i2, w2]), plan)
        left = open(os.path.join(self.web, "dedupe-left.tsv")).read()
        self.assertIn(a if a in left.split("\t")[1] else b, left); self.assertEqual(len(left.splitlines()), 1, left)
        # Remove for one kind: only the copy written down moves; the other stays planned
        open(os.path.join(self.web, "dedupe-pick.txt"), "w").write(i2 + "\n")
        self.r.job("apply", {"KEEP_SIDE": "short", "PICK": "1"})
        self.assertFalse(os.path.exists(i2)); self.assertTrue(os.path.isfile(w2) and os.path.isfile(a) and os.path.isfile(b))
        rest = open(os.path.join(self.web, "dedupe-plan.tsv")).read()
        self.assertNotIn(i2, rest); self.assertIn(w2, rest)
        self.assertFalse(os.path.exists(os.path.join(self.web, "dedupe-plan.tsv.done")), "not all of it: not shown as done")
        self.assertFalse(os.path.exists(os.path.join(self.web, "dedupe-pick.txt")), "used once")
        open(os.path.join(self.web, "dedupe-pick.txt"), "w").write(w2 + "\n" + a + "\n")     # a copy not in the plan: ignored
        self.r.job("apply", {"KEEP_SIDE": "short", "PICK": "1"})
        self.assertFalse(os.path.exists(w2)); self.assertTrue(os.path.isfile(a) and os.path.isfile(b), "different jobs: never moved")
        self.assertTrue(os.path.exists(os.path.join(self.web, "dedupe-plan.tsv.done")))

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

    def test_delete_all_deletes_what_a_person_removed_in_tidy_up(self):
        """Tidy up's Move to Recently Removed writes removed-moves.tsv; Delete All deletes those, and keeps a file with no record."""
        held = self.put("_Recently Removed/VIDEOS/Parks/x.mov", b"x"); stray = self.put("_Recently Removed/stray.mov", b"y")
        open(os.path.join(self.web, "removed-moves.tsv"), "w").write(os.path.join(self.a, "VIDEOS/Parks/x.mov") + "\t" + held + "\n")
        self.r.job("empty", {"WHO": "Ana"})
        self.assertFalse(os.path.exists(held), "removed by a person, deleted by Delete All")
        self.assertTrue(os.path.exists(stray), "no record of where it came from: it stays")

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
        open(os.path.join(self.web, "dedupe-rules.tsv"), "w").write("1000\tcontains\t/@Recycle/\n500\tcontains\t/Card dumps/\n")    # run.php writes them
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
        open(os.path.join(self.web, "dedupe-rules.tsv"), "w").write("1000\tcontains\t/@Recycle/\n500\tcontains\t/Card dumps/\n")
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
        open(os.path.join(self.web, "dedupe-rules.tsv"), "w").write("1000\tcontains\t/@Recycle/\n500\tcontains\t/Card dumps/\n")
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


class Rescue(unittest.TestCase):
    """Rescue: broken copies in the archive found (empty, too small, another file's data inside) and, ticked,
    replaced by their good copy, read back byte for byte; the broken one goes to Recently Removed."""
    setUp, tearDown, put = MacJobs.setUp, MacJobs.tearDown, MacJobs.put

    def test_a_sidecar_or_a_camera_name_is_not_sure_by_its_name_and_size(self):
        import sqlite3
        xml = b"<?xml version='1.0'?><NonRealTimeMeta/>"
        other = os.path.join(self.t, "OldServer", "FEB", "SHOOT 3", "data", "CAM3", "C0051M01.XML")
        card = os.path.join(self.t, "OldServer", "MAR", "Kite", "CLIP", "C0052M01.XML")
        fx = os.path.join(self.t, "OldServer", "AUG", "Bike", "FX30.0944.MP4")
        for f, b in ((other, xml), (card, xml), (fx, b"\x00\x00\x00\x18ftypmp42" + b"x" * 70000)):
            os.makedirs(os.path.dirname(f), exist_ok=True); open(f, "wb").write(b)
        db = sqlite3.connect(os.path.join(self.web, "rushes.sqlite"))
        db.execute("CREATE TABLE IF NOT EXISTS files (path TEXT, name TEXT, bytes INTEGER)")
        db.executemany("INSERT INTO files VALUES (?, ?, ?)", [(x, os.path.basename(x), os.path.getsize(x)) for x in (other, card, fx)])
        db.commit(); db.close()
        b1 = self.put("VIDEOS/APR/PENSIONS/CLIPS/C0051M01.XML", b"\x00" * 50)      # another month's sidecar
        b2 = self.put("VIDEOS/APR/Garden/CLIP/C0052M01.XML", b"\x00" * 50)        # only a card's own CLIP in common
        b3 = self.put("VIDEOS/SEP/Walk/FX30.0944.MP4", b"")                      # a camera's counter
        self.r.job("rescue-scan", {})
        rows = {l.split("\t")[0]: l.rstrip("\n").split("\t") for l in open(os.path.join(self.web, "rescue-found.tsv"))}
        for b in (b1, b2, b3):
            self.assertEqual((rows[b][4] != "", rows[b][7]), (True, "0"), f"{b}: offered, but as same name only")

    def test_stop_stops_only_the_job_it_was_pressed_for(self):
        self.r.now_job = "rescue-apply"
        open(os.path.join(self.web, "job-stop.txt"), "w").write("rescue-scan\n")
        self.assertFalse(self.r.stop_asked(), "a Stop pressed for another job is not this one's")
        open(os.path.join(self.web, "job-stop.txt"), "w").write("rescue-apply\n")
        self.assertTrue(self.r.stop_asked() and not self.r.may_v(), "Stop: this job stops at its next safe point")
        self.r.now_job = None
        self.assertFalse(self.r.stop_asked(), "and nothing else does")

    def test_small_real_files_and_code_are_not_called_broken(self):
        self.put("VIDEOS/FX/Thud.mp4", b"\x00\x00\x00\x18ftypmp42" + b"x" * 49000)        # a real 49 KB sound effect
        self.put("VIDEOS/App/node_modules/jiti/index.d.mts", b"\x47" + b"x" * 100)          # in an app's code: never looked at
        self.put("VIDEOS/App/src/types.d.mts", b"export type X = string;\n")               # TypeScript, not a clip
        bad = self.put("VIDEOS/FX/overlay.mov", b"x" * 110)
        self.r.job("rescue-scan", {})
        rows = [l.split("\t")[0] for l in open(os.path.join(self.web, "rescue-found.tsv"))]
        self.assertEqual(rows, [bad], "only the 110-byte .mov is broken")

    def test_a_server_whose_files_could_not_be_read_is_said(self):
        fake = os.path.join(self.t, "bin", "ssh")              # the server answers nothing (as a failing command would)
        open(fake, "w").write('#!/bin/sh\necho "sh: od: unrecognized option" >&2\n')
        os.chmod(fake, 0o755)
        os.makedirs(os.path.join(self.web, "ssh"), exist_ok=True)
        s = json.load(open(os.path.join(self.web, "settings.json")))
        s["ssh_sources"] = [{"name": "QNAP", "host": "admin@nas.local", "path": "/share/VIDEO"}]
        json.dump(s, open(os.path.join(self.web, "settings.json"), "w"))
        key = [x["key"] for x in self.r.ssh_servers()][0]
        open(os.path.join(self.web, "ssh", key + ".tsv"), "w").write("120000\t/share/VIDEO/Kite/Clip0007.MXF\n")
        self.put("VIDEOS/Kite/Clip0007.MXF", b"")
        self.r.job("rescue-scan", {})
        said = json.load(open(os.path.join(self.web, "rescue-ssh.json")))
        self.assertEqual((said["QNAP"]["want"], said["QNAP"]["read"]), (1, 0))
        self.assertIn("od", said["QNAP"]["why"], "what ssh said is kept, for the page")

    def test_broken_files_are_found_and_rescued_from_their_source(self):
        mp4 = b"\x00\x00\x00\x18ftypmp42" + os.urandom(200000)
        src = os.path.join(self.t, "OldServer", "Card"); os.makedirs(src)
        for n, b in (("C0153.MP4", mp4), ("C0155M01.XML", b"<?xml version='1.0'?><x/>"), ("C0163.MP4", mp4[::-1][:100] + mp4)):
            open(os.path.join(src, n), "wb").write(b)
        empty = self.put("VIDEOS/Card/C0153.MP4", b"")
        swapped = self.put("VIDEOS/Card/C0155M01.XML", mp4)                 # a video's bytes in a sidecar
        fine = self.put("VIDEOS/Card/C0154.MP4", mp4)
        self.put("VIDEOS/Card/C0156.MP4", b"\x00" * 70000)                  # not a clip inside, no copy anywhere
        os.makedirs(os.path.join(self.a, "_rushes", "origin"))
        open(os.path.join(self.a, "_rushes", "origin", "1 old copied.tsv"), "w").write(
            "".join(f"copied\t{src}/{n}\t{self.a}/VIDEOS/Card/{n}\t1\n" for n in ("C0153.MP4", "C0155M01.XML")))
        self.r.job("rescue-scan", {})
        rows = {l.split("\t")[0]: l.rstrip("\n").split("\t") for l in open(os.path.join(self.web, "rescue-found.tsv"))}
        self.assertEqual(set(rows), {empty, swapped, os.path.join(self.a, "VIDEOS/Card/C0156.MP4")}, "only the broken ones")
        self.assertTrue(rows[empty][2].startswith("empty") and rows[empty][4] == os.path.join(src, "C0153.MP4"), "its good copy: where it came from")
        self.assertEqual(rows[os.path.join(self.a, "VIDEOS/Card/C0156.MP4")][4], "", "no good copy anywhere: said, nothing invented")
        self.assertEqual(rows[empty][7], "1", "its own source: sure")
        open(os.path.join(self.web, "rescue-pick.tsv"), "w").write(f"{empty}\t{src}/C0153.MP4\n{swapped}\t{src}/C0155M01.XML\n")
        self.r.job("rescue-apply", {"WHO": "Ana"})
        self.assertEqual(open(empty, "rb").read(), mp4, "the good one in its place")
        self.assertTrue(open(swapped, "rb").read().startswith(b"<?xml"))
        self.assertEqual(os.path.getsize(os.path.join(self.a, "_Recently Removed", "VIDEOS/Card/C0153.MP4")), 0, "the broken one kept in Recently Removed")
        self.assertIn(empty + "\t", open(os.path.join(self.web, "removed-moves.tsv")).read())
        self.assertEqual(open(fine, "rb").read(), mp4, "a good file is never touched")
        self.assertNotIn(empty, open(os.path.join(self.web, "rescue-found.tsv")).read(), "rescued: off the list")


class RescueByName(unittest.TestCase):
    """A file of the same name is a good copy for sure only with more than the name: cameras number clips the same
    on every card. Read from Search (rushes.sqlite) as Rushes keeps it."""
    setUp, tearDown, put = MacJobs.setUp, MacJobs.tearDown, MacJobs.put

    def test_a_counter_name_from_another_card_is_never_sure(self):
        import sqlite3
        mp4 = b"\x00\x00\x00\x18ftypmp42" + os.urandom(100000)
        bad = self.put("VIDEOS/2021/CASA LINDA/Clip0004.MXF", b"")
        other = self.put("VIDEOS/2020/AUGUST/Clip/Clip0004.MXF", b"\x06\x0e\x2b\x34" + os.urandom(90000))
        same = self.put("OLD/2021/CASA LINDA/Clip0005.MXF", b"\x06\x0e\x2b\x34" + os.urandom(90000))
        bad5 = self.put("VIDEOS/2021/CASA LINDA/Clip0005.MXF", b"")
        named = self.put("OLD/AMAZON/Meadow Park.mov", mp4); bad_named = self.put("VIDEOS/AMAZON2/Meadow Park.mov", b"")
        db = sqlite3.connect(os.path.join(self.web, "rushes.sqlite"))
        db.execute("CREATE TABLE files (path TEXT, name TEXT, bytes INTEGER)")
        for p in (other, same, named): db.execute("INSERT INTO files VALUES (?, ?, ?)", (p, os.path.basename(p), os.path.getsize(p)))
        db.commit(); db.close()
        self.r.job("rescue-scan", {})
        rows = {l.split("\t")[0]: l.rstrip("\n").split("\t") for l in open(os.path.join(self.web, "rescue-found.tsv"))}
        self.assertEqual((rows[bad][4], rows[bad][7]), (other, "0"), "Clip0004 from another card: offered as same name only")
        self.assertEqual((rows[bad5][4], rows[bad5][7]), (same, "1"), "the same name in a folder of the same name: sure")
        self.assertEqual((rows[bad_named][4], rows[bad_named][7]), (named, "1"), "a name no camera gives, one size everywhere: sure")


class RescueOverSSH(unittest.TestCase):
    """A server only SSH reaches (a NAS whose sharing is off): listed, looked in, copied from with a fingerprint taken
    on both ends; never written to. ssh here is a stand-in that runs the command on this computer."""
    setUp, tearDown, put = MacJobs.setUp, MacJobs.tearDown, MacJobs.put

    def test_a_broken_file_is_rescued_from_the_server(self):
        fake = os.path.join(self.t, "bin", "ssh")
        open(fake, "w").write('#!/bin/sh\nwhile [ "$1" != "--" ]; do shift; done; shift; shift; exec sh -c "$1"\n')
        os.chmod(fake, 0o755)
        nas = os.path.join(self.t, "nas", "VIDEO"); os.makedirs(os.path.join(nas, "001 ALL CARD", "Kite"))
        mxf = b"\x06\x0e\x2b\x34" + os.urandom(120000)
        open(os.path.join(nas, "001 ALL CARD", "Kite", "Clip0007.MXF"), "wb").write(mxf)
        os.makedirs(os.path.join(nas, ".@__thumb")); open(os.path.join(nas, ".@__thumb", "x.MXF"), "wb").write(b"t")
        bad = self.put("VIDEOS/Parks/Kite/Clip0007.MXF", b"")
        s = json.load(open(os.path.join(self.web, "settings.json")))
        s["ssh_sources"] = [{"name": "QNAP", "host": "admin@nas.local", "path": nas}]
        json.dump(s, open(os.path.join(self.web, "settings.json"), "w"))
        self.r.job("ssh-list", {"NAME": "QNAP"})
        listed = open(os.path.join(self.web, "ssh", [f for f in os.listdir(os.path.join(self.web, "ssh"))][0])).read()
        self.assertIn("Clip0007.MXF", listed); self.assertNotIn(".@__thumb", listed, "the NAS's own folders are not listed")
        self.r.job("rescue-scan", {})
        row = open(os.path.join(self.web, "rescue-found.tsv")).read().rstrip("\n").split("\t")
        self.assertEqual((row[4], row[7]), (f"ssh:QNAP:{nas}/001 ALL CARD/Kite/Clip0007.MXF", "1"), "on the server, in a folder of the same name: sure")
        open(os.path.join(self.web, "rescue-pick.tsv"), "w").write(f"{bad}\t{row[4]}\n")
        self.r.job("rescue-apply", {"WHO": "Ana"})
        self.assertEqual(open(bad, "rb").read(), mxf, "copied from the server, fingerprints agreeing")
        self.assertEqual(open(os.path.join(nas, "001 ALL CARD", "Kite", "Clip0007.MXF"), "rb").read(), mxf, "the server's copy untouched")

    def test_an_address_a_shell_or_ssh_would_misread_is_refused(self):
        self.assertFalse(runner.ssh_ok("-oProxyCommand=x", "/share/VIDEO"))
        self.assertFalse(runner.ssh_ok("admin@nas", "/share/$(rm -rf x)"))
        self.assertTrue(runner.ssh_ok("admin@192.168.1.20", "/share/VIDEO"))


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

    def test_previews_of_a_kept_drive_live_on_the_archive(self):
        """Its previews are made on the archive, under the drive's own ID, never on the drive."""
        import subprocess, time
        os.makedirs(self.a); os.makedirs(os.path.join(self.vol, "Films 1")); self.minute()
        clip = self.drive("Gala/A001.mov", b"")
        subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-f", "lavfi", "-i", "testsrc=s=1280x720:d=1", "-c:v", "mpeg4", clip], check=True)
        old = time.time() - 3 * 3600; os.utime(clip, (old, old))
        folder = os.path.join(self.vol, "Films 1", "Gala")
        with unittest.mock.patch.object(self.r, "ffmpeg", return_value=shutil.which("ffmpeg")):
            self.r.job("proxy-build", {"QUERY": folder}); self.r.busy["proxies"].join(60)
        home = os.path.join(self.a, "_rushes", "previews", "UUID-ONE")
        self.assertTrue(os.path.getsize(os.path.join(home, "Gala", "A001.mp4")) > 0, "on the archive, under the drive's ID")
        self.assertEqual(open(os.path.join(home, ".from")).read().strip(), os.path.join(self.vol, "Films 1"))
        self.assertEqual(sorted(os.listdir(folder)), ["A001.mov"], "nothing written on the drive")
        self.assertIn(clip + "\t", open(os.path.join(self.web, "proxy-made.tsv")).read())
        # only a kept drive's folder: anywhere else is refused, and nothing is made
        self.assertEqual(self.r.prep_folder("/etc"), "")
        self.assertEqual(self.r.prep_folder(folder + "/../.."), "")
        self.assertEqual(self.r.prep_folder(folder + "/"), folder)

    def test_each_drive_says_how_it_is_kept(self):
        # asked per drive (Overview): its own answer wins over Setup 01's
        os.makedirs(os.path.join(self.vol, "Films 1")); os.makedirs(os.path.join(self.vol, "Other"))
        s = json.load(open(os.path.join(self.web, "settings.json")))
        s["organise"] = {"shape": "one_place"}
        s["sources"] = [{"label": "Films 1", "path": os.path.join(self.vol, "Films 1"), "how": "in_place"},
                        {"label": "Other", "path": os.path.join(self.vol, "Other"), "how": "copy"},
                        {"label": "Old", "path": os.path.join(self.vol, "Old")}]          # before it was asked: Setup 01
        json.dump(s, open(os.path.join(self.web, "settings.json"), "w"))
        self.assertEqual([d["name"] for d in self.r.drives()], ["Films 1"])

    def test_a_new_archive_is_not_held_against_the_old_list(self):
        for i in range(1200): self.put(f"Parks/{i}.mov", b"a")
        self.assertTrue(self.r.build_index())
        new = os.path.join(self.t, "New"); os.makedirs(new); open(os.path.join(new, "x.mov"), "wb").write(b"x")
        s = json.load(open(os.path.join(self.web, "settings.json"))); s["archive"]["local"] = new; s["sources"] = []
        json.dump(s, open(os.path.join(self.web, "settings.json"), "w"))
        self.assertFalse(self.r.build_index(), "an ordinary list a tenth the size is still refused")
        self.assertTrue(self.r.build_index(moved=True))
        self.assertEqual(open(os.path.join(self.web, "index.txt")).read().count("\n"), 1)
        # Make this the archive leaves a mark until the new archive's first list is in: a retry, an empty drive
        empty = os.path.join(self.t, "Empty"); os.makedirs(empty)
        s["archive"]["local"] = empty; json.dump(s, open(os.path.join(self.web, "settings.json"), "w"))
        open(os.path.join(self.web, "archive-moved.txt"), "w").write(empty + "\n")
        self.assertTrue(self.r.build_index(), "the new archive's first list, though empty")
        self.assertFalse(os.path.exists(os.path.join(self.web, "archive-moved.txt")))

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
            "Listed Films 1: 1 file, found in Search by name",
            "Films 1 was unplugged: Search keeps showing its files, marked not plugged in",
            "Films 1 was plugged in again: its list of files is made again",
            "Listed Films 1: 1 file, found in Search by name"], "Activity says when a drive comes and goes, and when it is listed")
        self.ids["Films 1"] = "UUID-SOMEONE-ELSES"
        self.drive("x.mov", b"x", name="Films 1")               # another drive takes the old name: not it
        self.assertEqual(self.minute()["Films 1"]["path"], os.path.join(self.vol, "Films 1 1"))

    def test_duplicates_within_a_drive_move_aside_there_and_across_drives_stay(self):
        both = os.urandom(5000)
        self.put("Parks/same.mov", both); on_drive = self.drive("Card/same.mov", both)
        twice = os.urandom(7000)
        a = self.drive("A/clip.mov", twice); b = self.drive("A/A long folder/clip.mov", twice)
        self.minute(); self.minute()
        self.r.job("scan", {})
        summary = json.load(open(os.path.join(self.web, "dup-summary.json")))
        self.assertEqual(summary["across"]["sets"], 1)
        self.assertEqual(open(os.path.join(self.web, "results_duplicates.txt")).read(), "", "nothing within the archive")
        open(os.path.join(self.web, "dedupe-rules.tsv"), "w").write("1000\tcontains\t/@Recycle/\n")
        src = os.path.join(self.vol, "Films 1")
        self.r.job("plan", {"KEEP_SIDE": "short", "DRIVE": src})
        self.r.job("apply", {"KEEP_SIDE": "short", "DRIVE": src})
        self.assertTrue(os.path.isfile(os.path.join(src, "_Recently Removed", "A/A long folder/clip.mov")) and os.path.isfile(a))
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
            self.r.job("proxy-build", {"QUERY": "AI/openart-video_1.mp4"}); self.r.busy["proxies"].join(60)   # Search's "Make its proxy now": one file
            st = dict(l.rstrip("\n").split("\t", 1) for l in open(os.path.join(self.web, "proxy-status.txt")))
            self.assertEqual(st["state"], "done", "one video can be asked for alone, not only its folder")
        # what is not light: HEVC, 4K, a heavy bitrate, sound a browser cannot play
        for report in ("Video: hevc (Main), yuv420p(tv), 1920x1080 bitrate: 3000 kb/s Audio: aac",
                       "Video: h264 (High), yuv420p(tv), 3840x2160 bitrate: 9000 kb/s Audio: aac",
                       "Video: h264 (High), yuv420p(tv), 1920x1080 bitrate: 45000 kb/s Audio: aac",
                       "Video: h264 (High), yuv420p(tv), 1920x1080 bitrate: 8000 kb/s Audio: pcm_s16le",
                       "Video: h264 (High 10), yuv420p10le(tv), 1920x1080 bitrate: 8000 kb/s"):
            self.assertFalse(runner.Runner.light(report, "x.mp4"), report)
        self.assertFalse(runner.Runner.light("Video: h264 (High), yuv420p(tv), 1280x720 bitrate: 4000 kb/s", "x.mxf"))
        # a phone's vertical HD is HD: the short side is what counts; vertical 4K is not light
        self.assertTrue(runner.Runner.light("Video: h264 (High), yuv420p(tv), 1080x1920 bitrate: 8000 kb/s Audio: aac", "x.mp4"))
        self.assertFalse(runner.Runner.light("Video: h264 (High), yuv420p(tv), 2160x3840 bitrate: 9000 kb/s Audio: aac", "x.mp4"))
