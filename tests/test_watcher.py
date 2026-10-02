# Rushes Watcher and the helper, end to end, on a fixture: an editor saves a
# project that uses files from outside the archive, then quits Premiere.
#   cd tests && python3 -m unittest test_watcher
import gzip
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / "app"))


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    m = importlib.util.module_from_spec(spec)
    with patch("urllib.request.urlopen", side_effect=OSError("offline")):
        spec.loader.exec_module(m)
    return m


class EndToEnd(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); r = self.root = Path(self.tmp.name)
        self.arch, self.proj, self.deliv, self.out = r / "VIDEO", r / "Projects", r / "Deliveries", r / "ed"
        (self.arch / "Shelf/PARKS/2026/Kite").mkdir(parents=True)
        (self.arch / "Shelf/PARKS/2026/Kite/A001.mov").write_bytes(b"camera")
        (self.proj / "PARKS/2026/Kite").mkdir(parents=True); self.deliv.mkdir()
        (self.out / "Music").mkdir(parents=True); (self.out / "Artlist").mkdir()
        (self.out / "Music/Song & Co.wav").write_bytes(b"la" * 100)
        (self.out / "Artlist/drone.mov").write_bytes(b"d" * 300)
        (self.out / "title.png").write_bytes(b"t" * 30)
        self.prproj = self.proj / "PARKS/2026/Kite/Kite.prproj"
        files = [self.arch / "Shelf/PARKS/2026/Kite/A001.mov", self.out / "Music/Song & Co.wav",
                 self.out / "Artlist/drone.mov", self.out / "title.png", self.out / "gone.png",
                 self.out / "Adobe Premiere Pro Video Previews/p.mov"]
        xml = "<PremiereData>" + "".join(f"<Media><ActualMediaFilePath>{str(f).replace('&', '&amp;')}</ActualMediaFilePath></Media>"
                                         for f in files) + "</PremiereData>"
        self.prproj.write_bytes(gzip.compress(xml.encode()))
        old = time.time() - 600; os.utime(self.prproj, (old, old))

        os.environ["RUSHES_WATCHER_HOME"] = str(r / "watcher-home")
        os.environ["RUSHES_WATCHER_LOG"] = str(r / "watcher.log")
        self.w = load("rushes_watcher_t", HERE.parent / "mac/rushes_watcher.py")
        self.h = load("rushes_ingest_w", HERE.parent / "app/ingest.py")
        h = self.h
        h.HOME = r / "helper"; h.HOME.mkdir(); h.DONE = h.HOME / "done.txt"
        h.NAS_MOUNT = str(self.arch); h.STATUS = self.arch / "_rushes"; h.ORIGIN = h.STATUS / "origin"
        h.SETTINGS = {"archive": {"local": str(self.arch)}, "shares": {"deliveries_helper": str(self.deliv)},
                      "organise": {"shelves": "Shelf"}}

        # Rushes, in between: what watcher.php and delivered.php do, in memory
        self.key, self.queue, self.where = "ab12cd34ef567890", [], {}
        def rushes(cfg, get=None, post=None, path="/db/watcher.php", timeout=15):
            if get and "hello" in get:
                return {"key": self.key, "archive": "VIDEO", "projects": "Projects", "deliveries": "Deliveries",
                        "shelf": "Shelf", "cache": {"sweep": [{"ext": ["pek"]}]}}
            if get and "where" in get:
                return {"files": {o: v for o, v in self.where.items() if v["project"] == get["project"]}}
            if post and post.get("action") == "delivered":
                self.queue.append(f"{self.key}/{post['batch']}")
            if post and post.get("action") == "project":
                self.reported = post
            return {"ok": True}
        self.w.rushes = rushes
        self.cfg = {"url": "http://rushes.test", "id": "0" * 32, "archive": str(self.arch),
                    "projects": str(self.proj), "deliveries": str(self.deliv)}

    def tearDown(self):
        self.tmp.cleanup()

    def helper_runs(self):
        """The helper takes in whatever was queued; delivered.php keeps where each file went."""
        def urlopen(url, data=None, timeout=None):
            from urllib.parse import parse_qs
            q = parse_qs(data.decode())
            for l in q["files"][0].splitlines():
                o, rel, fp, kind, project, size = l.split("\t")
                self.where[o] = {"rel": rel, "kind": kind, "project": project}
            return io.BytesIO(json.dumps({"recorded": len(q["files"][0].splitlines()), "refused": []}).encode())
        with patch.object(self.h, "control", return_value={}), patch.object(self.h, "stopped", return_value=""), \
             patch.object(self.h, "_push"), patch.object(self.h, "send_file"), patch.object(self.h, "free_bytes", return_value=None), \
             patch("urllib.request.urlopen", side_effect=urlopen), patch("sys.stdout", new_callable=io.StringIO):
            while self.queue:
                self.h.deliver(self.queue.pop(0))

    def test_save_then_quit(self):
        w = self.w.Watcher(self.cfg)
        with patch.object(self.w, "editing", return_value=["Adobe Premiere Pro"]), patch("sys.stdout", new_callable=io.StringIO):
            w.since = time.time() - 3600                      # Premiere has been open an hour
            w.tick()
        self.assertEqual(len(self.queue), 1)
        batch = (self.deliv / self.queue[0] / "batch.tsv").read_text()
        self.assertIn("shoot\tShelf/PARKS/2026/Kite", batch)
        self.assertIn("\tmusic\t" + str(self.out / "Music/Song & Co.wav"), batch)
        self.assertIn("\tstock\t" + str(self.out / "Artlist/drone.mov"), batch)
        self.assertNotIn("A001.mov", batch)                   # already in the archive
        self.assertNotIn("Previews", batch)                   # rebuilds itself
        self.assertEqual(self.reported["missing"], "gone.png")
        self.helper_runs()
        self.assertEqual((self.arch / "Stock Library/Music/Song & Co.wav").read_bytes(), b"la" * 100)
        self.assertTrue((self.arch / "Stock Library/Stock footage/drone.mov").exists())
        self.assertTrue((self.arch / "Shelf/PARKS/2026/Kite/Finished/Kite/Media/title.png").exists())   # made for this project
        self.assertEqual(list(self.deliv.joinpath(self.key).iterdir()), [])   # taken in: the batch is gone

        # saved again, nothing new: nothing delivered again
        later = time.time() - 400; os.utime(self.prproj, (later, later))
        with patch.object(self.w, "editing", return_value=["Adobe Premiere Pro"]), patch("sys.stdout", new_callable=io.StringIO):
            w.last_scan = 0; w.tick()
        self.assertEqual(self.queue, [])

        # Premiere quit: the project points at the archive, a backup beside it, a copy into the archive
        with patch.object(self.w, "editing", return_value=[]), patch("sys.stdout", new_callable=io.StringIO):
            w.tick()
        xml = gzip.decompress(self.prproj.read_bytes()).decode()
        self.assertIn(f">{self.arch}/Stock Library/Music/Song &amp; Co.wav<", xml)
        self.assertIn(f">{self.arch}/Stock Library/Stock footage/drone.mov<", xml)
        self.assertIn(f">{self.arch}/Shelf/PARKS/2026/Kite/Finished/Kite/Media/title.png<", xml)
        self.assertIn(str(self.out / "gone.png"), xml)        # what it cannot find stays as it was
        backups = list((self.prproj.parent / "Rushes backups").iterdir())
        self.assertEqual(len(backups), 1)
        self.assertIn(str(self.out / "Artlist/drone.mov"), gzip.decompress(backups[0].read_bytes()).decode())
        self.helper_runs()
        kept = list((self.arch / "Shelf/PARKS/2026/Kite/Finished/Kite").glob("Kite *.prproj"))
        self.assertEqual(len(kept), 1)
        self.assertEqual(kept[0].read_bytes(), self.prproj.read_bytes())
        log = Path(os.environ["RUSHES_WATCHER_LOG"]).read_text()
        self.assertIn("now point at the archive's copies", log)
        self.assertIn("cannot be found", log)

    def test_another_computers_project_is_left_alone(self):
        for f in ("Music/Song & Co.wav", "Artlist/drone.mov", "title.png"): (self.out / f).unlink()
        w = self.w.Watcher(self.cfg)
        with patch.object(self.w, "editing", return_value=["Adobe Premiere Pro"]), patch("sys.stdout", new_callable=io.StringIO):
            w.since = time.time() - 3600; w.tick()
        self.assertEqual(self.queue, [])
        self.assertFalse(w.state["projects"][str(self.prproj)].get("touched"))

    def test_resting_and_moved_aside_are_said_once(self):
        w, hello = self.w.Watcher(self.cfg), self.w.rushes
        quiet = {"resting": ["PARKS/2026/Kite/Kite.prproj"], "aside": ["PARKS/2025/Old/Old.prproj"]}
        self.w.rushes = lambda cfg, get=None, **k: dict(hello(cfg, get=get, **k), **quiet) if get and "hello" in get else hello(cfg, get=get, **k)
        with patch.object(self.w, "editing", return_value=[]), patch("sys.stdout", new_callable=io.StringIO):
            w.tick(); w.hello_at = 0; w.tick()
        log = Path(os.environ["RUSHES_WATCHER_LOG"]).read_text()
        self.assertEqual(log.count("Kite.prproj is resting"), 1)
        self.assertEqual(log.count("Old.prproj was moved aside"), 1)
        quiet["resting"] = []                             # saved again: said again when it next rests
        with patch.object(self.w, "editing", return_value=[]), patch("sys.stdout", new_callable=io.StringIO):
            w.hello_at = 0; w.tick()
        self.assertNotIn("PARKS/2026/Kite/Kite.prproj", w.state["told"])

    def test_idle_reads_nothing(self):
        w = self.w.Watcher(self.cfg)
        with patch.object(self.w, "editing", return_value=[]), patch.object(self.w, "find_projects") as look, \
             patch("sys.stdout", new_callable=io.StringIO):
            w.tick()
        look.assert_not_called()

    def test_selftest(self):
        with patch("sys.stdout", new_callable=io.StringIO):
            self.assertEqual(self.w.selftest(), 0)


if __name__ == "__main__":
    unittest.main()
