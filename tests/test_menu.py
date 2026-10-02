# The menu bar icon's answers (rushes_helper.py --menu), for both apps, on a
# fixture home folder: what the launcher draws, and a switch chosen in it.
#   cd tests && python3 -m unittest test_menu
import importlib.util
import json
import os
from pathlib import Path
import socket
import sys
import tempfile
import threading
import time
import unittest
import urllib.request
from unittest.mock import patch

HERE = Path(__file__).resolve().parent


def front(name, home):
    with patch.dict(os.environ, {"RUSHES_NAME": name, "HOME": str(home), "RUSHES_APP": f"/Applications/{name}.app"}):
        spec = importlib.util.spec_from_file_location("front_" + name.split()[-1], HERE.parent / "mac/rushes_helper.py")
        m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
    return m


def serve(m):
    s = socket.socket(); s.bind(("127.0.0.1", 0)); port = s.getsockname()[1]; s.close()
    threading.Thread(target=m.menu, args=(port, "k"), daemon=True).start()
    base = f"http://127.0.0.1:{port}/k/"
    for _ in range(50):
        try: urllib.request.urlopen(base + "menu", timeout=2); break
        except OSError: time.sleep(0.1)
    return lambda path: json.loads(urllib.request.urlopen(base + path, timeout=20).read() or b"{}")


def plain(v):
    """Only what the launcher reads: strings, true/false, lists and objects (never null)."""
    if isinstance(v, dict): return all(isinstance(k, str) and plain(x) for k, x in v.items())
    if isinstance(v, list): return all(plain(x) for x in v)
    return isinstance(v, (str, bool))


class MenuTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); self.home = Path(self.tmp.name)

    def tearDown(self):
        self.tmp.cleanup()

    def test_watcher(self):
        m = front("Rushes Watcher", self.home)
        os.makedirs(m.WDIR)
        Path(m.WDIR, "config.json").write_text('{"url": "http://rushes.test", "id": "' + "0" * 32 + '"}')
        Path(m.WDIR, "now.json").write_text('{"state": "watching", "note": "Adobe Premiere Pro"}')
        with patch.object(m, "launchctl", return_value=m.subprocess.CompletedProcess([], 0, "pid = 42", "")):
            ask = serve(m)
            got = ask("menu?fresh=1")
            self.assertTrue(plain(got), got)
            self.assertEqual(got["icon"], "eye")
            self.assertIn("Watching", got["items"][0]["label"])
            sw = next(i for i in got["items"] if i.get("label") == "Watch projects")
            self.assertEqual((sw["on"], sw["do"]), (True, "watch-pause"))
            self.assertEqual(got["items"][-1], {"label": "Quit Rushes Watcher", "do": "quit"})
            ask("do?a=watch-pause")                      # chosen in the menu: done at once, and said
            self.assertTrue(os.path.exists(os.path.join(m.WDIR, "paused")))
            got = ask("menu?fresh=1")
            self.assertEqual(got["icon"], "pause.circle")
            self.assertIn("Watching paused", json.dumps(got, ensure_ascii=False))
            sw = next(i for i in got["items"] if i.get("label") == "Watch projects")
            self.assertEqual((sw["on"], sw["do"]), (False, "watch-resume"))

    def test_watcher_not_paired(self):
        m = front("Rushes Watcher", self.home)
        with patch.object(m, "launchctl", return_value=m.subprocess.CompletedProcess([], 1, "", "")):
            got = serve(m)("menu?fresh=1")
        self.assertEqual(got["icon"], "exclamationmark.triangle")
        self.assertTrue(any(i.get("do") == "open-window" and "Pair" in i["label"] for i in got["items"]))

    def test_helper_without_rushes(self):
        m = front("Rushes Helper", self.home)
        with patch.object(m, "launchctl", return_value=m.subprocess.CompletedProcess([], 1, "", "")):
            got = serve(m)("menu?fresh=1")
        self.assertTrue(plain(got), got)
        self.assertEqual(got["icon"], "wifi.exclamationmark")
        self.assertIn("Cannot reach Rushes", got["items"][0]["label"])
        sw = next(i for i in got["items"] if i.get("label") == "Copy footage")
        self.assertNotIn("do", sw)                       # Rushes keeps these switches: not while it cannot be reached
        self.assertEqual(got["items"][-1], {"label": "Quit Rushes Helper", "do": "quit"})


if __name__ == "__main__":
    unittest.main()
