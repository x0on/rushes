# Rushes Watcher, Rushes and the helper, end to end, on a fixture: an editor
# saves a Premiere project kept on his Desktop that uses files from all over his
# Mac, exports into Output, then quits Premiere.
#   cd tests && python3 -m unittest test_watcher
import gzip
import hashlib
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
import urllib.parse
import urllib.request

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / "app"))


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    m = importlib.util.module_from_spec(spec)
    with patch("urllib.request.urlopen", side_effect=OSError("offline")):
        spec.loader.exec_module(m)
    return m


class FakeRushes:
    """What watcher.php, helper.php and delivered.php do, in memory."""
    def __init__(self, key, folder):
        self.key, self.folder = key, folder
        self.inbox, self.queue, self.where, self.reported = {}, [], {}, {}
        self.resting = []

    def __call__(self, req, data=None, timeout=None):
        url = req.full_url if isinstance(req, urllib.request.Request) else req
        body = req.data if isinstance(req, urllib.request.Request) else data
        u = urllib.parse.urlsplit(url); q = {k: v[0] for k, v in urllib.parse.parse_qs(u.query, keep_blank_values=True).items()}
        form = {k: v[0] for k, v in urllib.parse.parse_qs(body.decode(), keep_blank_values=True).items()} \
            if body and "upload" not in q else {}
        js = lambda x: io.BytesIO(json.dumps(x).encode())
        if u.path == "/db/watcher.php":
            if "hello" in q:
                return js({"key": self.key, "folder": self.folder, "archive": "VIDEO", "shelf": "Shelf",
                           "cache": {}, "resting": self.resting})
            if "upload" in q:
                b = self.inbox.setdefault(q["batch"], {"files": {}, "list": ""})
                have = b["files"].setdefault(q["name"], b"")
                if body is None:
                    return js({"have": len(have), "done": len(have) == int(q.get("size", -1))})
                assert int(q["offset"]) == len(have), "pieces in order"
                b["files"][q["name"]] = have = have + body
                done = len(have) == int(q["size"])
                if done:
                    assert "sha256:" + hashlib.sha256(have).hexdigest() == q["fp"]
                return js({"have": len(have), "done": done})
            if "where" in q:
                proj = f"{self.folder}/{q['project']}"
                return js({"files": {o: v for o, v in self.where.items() if v["project"] == proj}})
            if form.get("action") == "delivered":
                b = self.inbox[form["batch"]]
                b["list"] = "\n".join(["rushes-delivery 2", f"watcher\t{self.key}", "host\tedit-1", f"folder\t{self.folder}",
                                       f"project\t{self.folder}/{form['name']}", f"shoot\t{form['shoot']}"]
                                      + form["lines"].split("\n") + ["end"]) + "\n"
                self.queue.append(f"{self.key}/{form['batch']}")
            if form.get("action") == "project":
                self.reported = form
            return js({"ok": True})
        if u.path == "/db/helper.php" and "inbox" in q:
            key, batch, rest = q["inbox"].split("/", 2)
            b = self.inbox[batch]
            return io.BytesIO(b["list"].encode() if rest == "batch.tsv" else b["files"][rest[len("files/"):]])
        if u.path == "/db/helper.php" and form.get("action") == "inbox-done":
            self.inbox.pop(form["batch"].split("/")[1], None)
            return js({"ok": True})
        if u.path == "/db/delivered.php":
            rows = [l.split("\t") for l in form["files"].splitlines()]
            for o, rel, fp, kind, project, size in rows:
                self.where[o] = {"rel": rel, "kind": kind, "project": project}
            return js({"recorded": len(rows), "refused": []})
        raise AssertionError(f"unexpected request {url}")


class EndToEnd(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); r = self.root = Path(self.tmp.name)
        self.arch, self.ed = r / "VIDEO", r / "ed"
        (self.arch / "Shelf/PARKS/2026/Kite").mkdir(parents=True)
        (self.arch / "Shelf/PARKS/2026/Kite/A001.mov").write_bytes(b"camera")
        for d in ("Desktop/Kite", "Music", "Artlist", "Downloads"):
            (self.ed / d).mkdir(parents=True)
        (self.ed / "Music/Song & Co.wav").write_bytes(b"la" * 100)
        (self.ed / "Artlist/drone.mov").write_bytes(b"d" * 300)
        (self.ed / "Downloads/title.png").write_bytes(b"t" * 30)
        (self.ed / "Downloads/never used.wav").write_bytes(b"n" * 30)
        self.prproj = self.ed / "Desktop/Kite/Kite.prproj"
        files = [self.arch / "Shelf/PARKS/2026/Kite/A001.mov", self.ed / "Music/Song & Co.wav",
                 self.ed / "Artlist/drone.mov", self.ed / "Downloads/title.png", self.ed / "gone.png",
                 self.ed / "Adobe Premiere Pro Video Previews/p.mov"]
        xml = "<PremiereData>" + "".join(f"<Media><ActualMediaFilePath>{str(f).replace('&', '&amp;')}</ActualMediaFilePath></Media>"
                                         for f in files) + "</PremiereData>"
        self.prproj.write_bytes(gzip.compress(xml.encode()))
        old = time.time() - 600; os.utime(self.prproj, (old, old))
        self.original = self.prproj.read_bytes()

        os.environ["RUSHES_WATCHER_HOME"] = str(r / "watcher-home")
        os.environ["RUSHES_WATCHER_LOG"] = str(r / "watcher.log")
        self.w = load("rushes_watcher_t", HERE.parent / "mac/rushes_watcher.py")
        self.h = load("rushes_ingest_w", HERE.parent / "app/ingest.py")
        h = self.h
        h.HOME = r / "helper"; h.HOME.mkdir(); h.DONE = h.HOME / "done.txt"
        h.NAS_MOUNT = str(self.arch); h.STATUS = self.arch / "_rushes"; h.ORIGIN = h.STATUS / "origin"
        h.SETTINGS = {"archive": {"local": str(self.arch)}, "organise": {"shelves": "Shelf"}}
        self.key, self.folder = "ab12cd34ef567890", "Maria (ab12)"
        self.rushes = FakeRushes(self.key, self.folder)
        self.cfg = {"url": "http://rushes.test", "id": "0" * 32, "archive": str(self.arch)}
        self.proj = self.arch / "Shelf/Projects" / self.folder / "Kite"

    def tearDown(self):
        self.tmp.cleanup()

    def tick(self, editing):
        with patch.object(self.w, "editing", return_value=editing), \
             patch.object(self.w, "find_projects", side_effect=lambda a, c: {str(self.prproj): self.prproj.stat().st_mtime}), \
             patch("urllib.request.urlopen", side_effect=self.rushes), patch("sys.stdout", new_callable=io.StringIO):
            self.watcher.tick()

    def helper_runs(self):
        """The helper places whatever was queued, from Rushes' inbox."""
        with patch.object(self.h, "control", return_value={}), patch.object(self.h, "stopped", return_value=""), \
             patch.object(self.h, "_push"), patch.object(self.h, "send_file"), patch.object(self.h, "free_bytes", return_value=None), \
             patch("urllib.request.urlopen", side_effect=self.rushes), patch("sys.stdout", new_callable=io.StringIO):
            while self.rushes.queue:
                self.h.deliver(self.rushes.queue.pop(0))

    def test_save_export_quit(self):
        self.watcher = self.w.Watcher(self.cfg)
        self.watcher.since = time.time() - 3600                          # Premiere has been open an hour
        self.tick(["Adobe Premiere Pro"])
        self.assertEqual(len(self.rushes.queue), 1)
        sent = self.rushes.inbox[self.rushes.queue[0].split("/")[1]]["list"]
        self.assertIn("\tmusic\t" + str(self.ed / "Music/Song & Co.wav"), sent)
        self.assertIn("\tstock\t" + str(self.ed / "Artlist/drone.mov"), sent)
        self.assertIn("\tproject\t" + str(self.ed / "Downloads/title.png"), sent)
        self.assertNotIn("A001.mov", sent)                                  # already in the archive
        self.assertNotIn("never used", sent)                                # downloaded, never imported
        self.assertNotIn("Previews", sent)                                  # rebuilds itself
        self.assertEqual(self.rushes.reported["missing"], "gone.png")
        self.assertEqual(self.rushes.reported["shoot"], "Shelf/PARKS/2026/Kite")
        self.assertTrue((self.prproj.parent / "Output").is_dir())          # made for the exports
        self.helper_runs()
        self.assertEqual((self.arch / "Shelf/Projects/Stock Library/Music/Song & Co.wav").read_bytes(), b"la" * 100)
        self.assertTrue((self.arch / "Shelf/Projects/Stock Library/Stock footage/drone.mov").exists())
        self.assertEqual((self.proj / "Media/title.png").read_bytes(), b"t" * 30)
        self.assertEqual(self.rushes.inbox, {})                             # placed and recorded: the inbox copy went

        # exported, then Premiere quit: the export and a dated copy of the project, pointing at the NAS
        (self.prproj.parent / "Output/Kite final.mp4").write_bytes(b"final" * 20)
        self.tick([])
        self.helper_runs()
        self.assertEqual((self.proj / "Output/Kite final.mp4").read_bytes(), b"final" * 20)
        kept = list(self.proj.glob("Kite *.prproj"))
        self.assertEqual(len(kept), 1)
        xml = gzip.decompress(kept[0].read_bytes()).decode()
        self.assertIn(f">{self.arch}/Shelf/Projects/Stock Library/Music/Song &amp; Co.wav<", xml)
        self.assertIn(f">{self.proj}/Media/title.png<", xml)
        self.assertIn(f">{self.arch}/Shelf/PARKS/2026/Kite/A001.mov<", xml)   # archive footage, as it was
        self.assertIn(str(self.ed / "gone.png"), xml)                        # what it cannot find, as it was
        self.assertEqual(self.prproj.read_bytes(), self.original)            # the editor's own project: untouched
        self.assertFalse((self.prproj.parent / "Rushes backups").exists())
        log = Path(os.environ["RUSHES_WATCHER_LOG"]).read_text()
        self.assertIn("made the Output folder", log)
        self.assertIn("cannot be found", log)
        self.assertIn("a copy of the project is kept on the NAS", log)

        # opened and quit again with nothing new: nothing sent twice, no second copy of the same project
        self.watcher.since = time.time() - 60
        os.utime(self.prproj, (time.time() - 400, time.time() - 400))
        self.tick(["Adobe Premiere Pro"]); self.tick([]); self.helper_runs()
        self.assertEqual(len(list(self.proj.glob("Kite *.prproj"))), 1)
        self.assertEqual(len(list((self.proj / "Media").iterdir())), 1)

    def put(self, batch, files, project="Kite", end=True):
        """A batch in Rushes' inbox, as watcher.php leaves it."""
        lines = []
        for name, data, kind in files:
            fp = "sha256:" + hashlib.sha256(data).hexdigest()
            lines.append(f"projectfile\t{name}\t{fp}\t{len(data)}\t{project}" if kind == "projectfile"
                         else f"file\t{name}\t{fp}\t{len(data)}\t{kind}\t/Users/ed/{name}")
        self.rushes.inbox[batch] = {"files": {n: d for n, d, _ in files}, "list": "\n".join(
            ["rushes-delivery 2", f"watcher\t{self.key}", "host\tedit-1", f"folder\t{self.folder}",
             f"project\t{self.folder}/{project}", "shoot\t"] + lines + (["end"] if end else [])) + "\n"}
        self.rushes.queue.append(f"{self.key}/{batch}")

    def test_the_helper_stores_once_checks_and_refuses(self):
        self.put("b1", [("song.wav", b"la" * 50, "music")]); self.helper_runs()
        self.put("b2", [("song copy.wav", b"la" * 50, "music")]); self.helper_runs()          # the same music again
        self.assertEqual(sorted(p.name for p in (self.arch / "Shelf/Projects/Stock Library/Music").iterdir() if p.is_file()), ["song.wav"])
        self.assertEqual(self.rushes.where["/Users/ed/song copy.wav"]["rel"], "Shelf/Projects/Stock Library/Music/song.wav")
        self.put("b3", [("hit.wav", b"boom", "sfx")]); self.rushes.inbox["b3"]["files"]["hit.wav"] = b"bang"   # changed on the way
        self.helper_runs()
        self.assertFalse((self.arch / "Shelf/Projects/Stock Library/Sound effects/hit.wav").exists())
        self.assertIn("b3", self.rushes.inbox)                                              # not taken: the batch stays
        self.put("b4", [("x.wav", b"x", "music")], end=False); self.helper_runs()          # not complete: refused whole
        self.assertIn(f"refused\tdeliver {self.key}/b4", (self.h.STATUS / "ingest-history.tsv").read_text())
        for bad in (f"rushes-delivery 2\nfolder\t{self.folder}\nproject\t../../etc\nend\n",
                    f"rushes-delivery 2\nfolder\t{self.folder}\nproject\tOther (cd34)/Kite\nend\n",      # another computer's folder
                    f"rushes-delivery 2\nfolder\t{self.folder}\nproject\t{self.folder}/Kite\nfile\t../x\tsha256:{'0' * 64}\t1\tmusic\t/x\nend\n"):
            with self.assertRaises(ValueError):
                self.h.read_batch(bad)

    def test_resting_is_said_once(self):
        self.watcher = self.w.Watcher(self.cfg)
        self.rushes.resting = [f"{self.folder}/Kite"]
        self.tick([]); self.watcher.hello_at = 0; self.tick([])
        log = Path(os.environ["RUSHES_WATCHER_LOG"]).read_text()
        self.assertEqual(log.count("Kite is resting"), 1)

    def test_idle_reads_nothing(self):
        self.watcher = self.w.Watcher(self.cfg)
        with patch.object(self.w, "editing", return_value=[]), patch.object(self.w, "find_projects") as look, \
             patch("urllib.request.urlopen", side_effect=self.rushes), patch("sys.stdout", new_callable=io.StringIO):
            self.watcher.tick()
        look.assert_not_called()

    def test_the_shoot_a_project_uses_with_departments_at_the_top(self):
        m = self.w
        a = "/Volumes/VIDEO"
        files = [f"{a}/PARKS/2026/20261002 Kite/A001.MXF", f"{a}/PARKS/2026/20261002 Kite/A002.MXF", f"{a}/Projects/Maria (a1b2)/x.wav"]
        self.assertEqual(m.shoot_of(files, a, "/"), "PARKS/2026/20261002 Kite")
        self.assertEqual(m.shoot_of([f"{a}/001 VIDEO/PARKS/2026/20261002 Kite/A001.MXF"], a, "001 VIDEO"), "001 VIDEO/PARKS/2026/20261002 Kite")

    def test_selftest(self):
        with patch("sys.stdout", new_callable=io.StringIO):
            self.assertEqual(self.w.selftest(), 0)


if __name__ == "__main__":
    unittest.main()
