# The menu bar icon's answers (rushes_helper.py --menu), for both apps, on a
# fixture home folder: what the launcher draws, and a switch chosen in it.
#   cd tests && python3 -m unittest test_menu
import importlib.util
import io
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

    def test_one_icon_for_both_apps_on_one_mac(self):
        """Helper and Watcher on the same Mac: the Helper draws the one icon, with a section for the
        Watcher whose choices reach the Watcher; the Watcher hides its own."""
        both = m_ok = lambda m: patch.object(m, "launchctl", return_value=m.subprocess.CompletedProcess([], 0, "pid = 42", ""))
        w = front("Rushes Watcher", self.home)
        with patch.dict(os.environ, {"HOME": str(self.home)}), both(w):
            got = serve(w)("menu?fresh=1")
        self.assertIs(got.get("hide"), True)
        h = front("Rushes", self.home)
        with patch.dict(os.environ, {"HOME": str(self.home)}), both(h):
            ask = serve(h)
            got = ask("menu?fresh=1")
            self.assertTrue(plain(got), got)
            self.assertNotIn("hide", got)
            labels = [i.get("label") for i in got["items"]]
            self.assertEqual(labels[0], "RUSHES")
            self.assertIn("RUSHES WATCHER", labels)
            sw = next(i for i in got["items"] if i.get("label") == "Watch projects")
            self.assertEqual(sw["do"], "watcher-watch-pause")
            self.assertEqual(sum(1 for i in got["items"] if i.get("do") == "open-rushes"), 1)
            self.assertEqual(got["items"][-1], {"label": "Quit Rushes and Rushes Watcher", "do": "quit-all"})
            with patch.object(h.role("Rushes Watcher")[0], "launchctl", return_value=h.subprocess.CompletedProcess([], 0, "pid = 42", "")):
                ask("do?a=watcher-watch-pause")                # chosen in the Helper's menu: done by the Watcher
            self.assertTrue((self.home / "Library/Application Support/Rushes Watcher/paused").exists())

    def test_helper_without_rushes(self):
        m = front("Rushes", self.home)
        with patch.object(m, "launchctl", return_value=m.subprocess.CompletedProcess([], 1, "", "")):
            ask = serve(m)
            first = ask("menu?fresh=1")                  # Rushes is asked in the background: never waited for
            self.assertIn("Asking Rushes", first["items"][0]["label"])
            time.sleep(2.2)
            got = ask("menu?fresh=1")
        self.assertTrue(plain(got), got)
        self.assertEqual(got["icon"], "wifi.exclamationmark")
        self.assertIn("Cannot reach Rushes", got["items"][0]["label"])
        sw = next(i for i in got["items"] if i.get("label") == "Copy footage")
        self.assertNotIn("do", sw)                       # Rushes keeps these switches: not while it cannot be reached
        self.assertEqual(got["items"][-1], {"label": "Quit Rushes", "do": "quit"})


class InstallWindowTests(unittest.TestCase):
    def test_each_step_says_what_it_is_doing_and_all_set_knows_rushes_is_on_this_mac(self):
        m = front("Rushes", Path(tempfile.mkdtemp()))
        w = m.Window(); seen = []
        with patch.object(m, "copy_to_applications", side_effect=lambda: seen.append(w.s["doing"]) or ""), \
             patch.object(m, "local", return_value={"archive": "/Volumes/Drive"}), \
             patch.object(m, "install_service", side_effect=lambda url: seen.append(w.s["doing"]) or True), \
             patch.object(m, "has_full_disk_access", side_effect=lambda: seen.append(w.s["doing"]) or True), \
             patch.object(m, "restart_service", side_effect=lambda: seen.append(w.s["doing"])):
            w.install("http://127.0.0.1:8642")
            st = w.state()
        self.assertEqual(seen, ["Putting Rushes in Applications …", "Installing the background service …",
                                "Checking Full Disk Access …", "Starting the background service again, with this version …"])
        self.assertEqual((st["step"], st["doing"], st["local"]), ("all-set", "", True))
        self.assertIn("Background service installed and started — it starts by itself when you log in", st["done"])


class UpdateWindowTests(unittest.TestCase):
    def test_update_shows_its_steps_in_the_window_and_opens_the_new_one(self):
        m = front("Rushes", Path(tempfile.mkdtemp())); os.makedirs(m.DIR)
        opened, seen = [], []
        def app_update(url, app, say, result):
            say("updating Rushes 0.12.9 → 0.12.10: downloading it from GitHub …"); seen.append(w.s["doing"])
            say("Rushes 0.12.10 checked (signed by the Rushes author) — it is put in place and starts again in a few seconds")
            seen.append(w.s["doing"]); Path(result).write_text("ok\t0.12.10\n")
            return "0.12.10"
        fake = type(sys)("release"); fake.app_update = app_update
        run = lambda a, **k: opened.append(a)
        with patch.dict(sys.modules, {"release": fake}), patch.object(m.subprocess, "run", side_effect=run), \
             patch.object(m, "local", return_value={"archive": "/Volumes/Drive"}):
            w = m.Window(); w.in_menu = True
            w.update_app()                               # the menu: the window does it
            self.assertTrue(os.path.exists(m.UPDATE_NOW))
            self.assertEqual(opened.pop(), ["open", "-n", m.APP])
            w = m.Window(); w.update_app()               # the window
        self.assertEqual(seen, ["Downloading Rushes 0.12.10 from GitHub …",
                                "Putting the new Rushes in place and starting it again …"])
        self.assertEqual((w.s["step"], w.s["done"]), ("update", ["Downloaded", "Checked: signed by the Rushes author"]))
        self.assertEqual(opened, [["open", "-n", m.APP]]); self.assertTrue(w.quit.is_set())
        self.assertEqual(m.update_said(), "✓ Updated to 0.12.10")

    def test_check_from_the_menu_answers_in_the_window_and_old_news_fades(self):
        m = front("Rushes", Path(tempfile.mkdtemp())); os.makedirs(m.DIR)
        opened = []
        with patch.object(m.subprocess, "run", side_effect=lambda a, **k: opened.append(a)):
            w = m.Window(); w.in_menu = True
            w.act("check-updates", {})                   # a menu closes when clicked: the window says the answer
        self.assertTrue(os.path.exists(m.CHECK_NOW)); self.assertEqual(opened, [["open", "-n", m.APP]])
        # the window was already open (macOS only brings it to the front): it still answers, at its next look
        w = m.Window(); w.s["step"] = "home"; done = []
        w.act = lambda do, a: done.append(do)
        self.assertTrue(m.asked_from_menu(w)); self.assertEqual(done, ["check-updates"])
        self.assertFalse(os.path.exists(m.CHECK_NOW)); self.assertFalse(m.asked_from_menu(w), "answered once, not at every look")
        w = m.Window(); w.set(said="✓ Updated to 0.12.10")
        self.assertEqual(w.state()["said"], "✓ Updated to 0.12.10", "news just after the update")
        w.said_time -= 61
        self.assertEqual(w.state()["said"], "", "a minute later it is gone: it never stands beside an update that waits")


class OneCopyTests(unittest.TestCase):
    """Rushes runs from where it was put, one copy only (0.12.2: the disk image)."""
    def test_dragged_to_applications_it_stays_there_and_the_other_copy_is_found(self):
        import plistlib
        with tempfile.TemporaryDirectory() as t:
            m = front("Rushes", Path(t))                      # RUSHES_APP: /Applications/Rushes.app
            self.assertEqual(m.HOMEAPP, "/Applications/Rushes.app", "dragged into Applications: run from there")
            self.assertEqual(m.other_copy(), "", "no service yet: no other copy")
            os.makedirs(os.path.dirname(m.PLIST))
            with open(m.PLIST, "wb") as f:                    # set up before from Applications in the home folder
                plistlib.dump({"ProgramArguments": [f"{t}/Applications/Rushes.app/Contents/MacOS/Rushes", "--service"]}, f)
            self.assertEqual(m.other_copy(), f"{t}/Applications/Rushes.app", "the copy the service ran is the one to retire")
            with open(m.PLIST, "wb") as f:
                plistlib.dump({"ProgramArguments": ["/Applications/Rushes.app/Contents/MacOS/Rushes", "--service"]}, f)
            self.assertEqual(m.other_copy(), "", "the service runs this copy: nothing to retire")
        with tempfile.TemporaryDirectory() as t, patch.dict(os.environ, {"RUSHES_NAME": "Rushes", "HOME": t,
                                                                          "RUSHES_APP": "/Volumes/Rushes 0.12.2/Rushes.app"}):
            spec = importlib.util.spec_from_file_location("front_dmg", HERE.parent / "mac/rushes_helper.py")
            m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
            self.assertIn(m.HOMEAPP, ("/Applications/Rushes.app", f"{t}/Applications/Rushes.app"),
                          "opened from the disk image: it goes into Applications")


class AppUpdateTests(unittest.TestCase):
    def test_newer_on_rushes_is_offered_in_the_menu(self):
        import plistlib
        sys.path.insert(0, str(HERE.parent / "app")); import release
        with tempfile.TemporaryDirectory() as t:
            app = Path(t) / "Rushes.app"; (app / "Contents").mkdir(parents=True)
            (app / "Contents/Info.plist").write_bytes(plistlib.dumps({"CFBundleShortVersionString": "0.9.1",
                "CFBundleIdentifier": "org.rushes.helper", "CFBundleExecutable": "Rushes"}))
            for theirs, want in (("0.9.2", "0.9.2"), ("0.9.1", ""), ("0.8.9", ""), ("0.10.0", "0.10.0")):
                with patch("urllib.request.urlopen", return_value=io.BytesIO(theirs.encode())):
                    self.assertEqual(release.app_update("http://rushes.test", str(app), check_only=True), want, theirs)
        m = front("Rushes", Path(tempfile.mkdtemp()))
        with patch.object(m, "launchctl", return_value=m.subprocess.CompletedProcess([], 1, "", "")), \
             patch.object(m.Window, "newer", return_value="0.9.2"):
            got = serve(m)("menu?fresh=1")
        self.assertIn({"label": "Update to 0.9.2", "do": "update-app"}, got["items"])
        self.assertEqual(got["state"], "offline")

    def test_rushes_is_asked_once_a_week_and_nothing_installs_by_itself(self):
        home = Path(tempfile.mkdtemp())
        m = front("Rushes", home)
        sys.path.insert(0, str(HERE.parent / "app")); import release
        asked = []
        with patch.object(release, "app_update", side_effect=lambda *a, **k: asked.append(k) or "0.9.3"), \
             patch.object(m, "app_version", return_value="0.9.2"), patch.object(m.os.path, "realpath", side_effect=lambda p: "same"):
            w = m.Window(); w.s["url"] = "http://rushes.test"
            self.assertEqual(w.newer(), "0.9.3")
            self.assertEqual(w.newer(), "0.9.3")
            self.assertEqual(len(asked), 1)                              # remembered for a week
            self.assertEqual(asked[0].get("check_only"), True)           # asked, never installed
            w.newer(now=True)
            self.assertEqual(len(asked), 2)                              # Check for updates: at once
        with patch.object(m, "launchctl", return_value=m.subprocess.CompletedProcess([], 1, "", "")), \
             patch.object(m.Window, "newer", return_value=""):
            got = serve(m)("menu?fresh=1")
        self.assertIn("check-updates", [i.get("do") for i in got["items"]])
        upd = next(i for i in got["items"] if i.get("do") == "check-updates")
        self.assertTrue(upd["label"].startswith("Rushes ") and upd["label"].endswith("— Check for updates"), upd)

    def test_rushes_on_this_mac_looks_on_github_for_the_newest_release(self):
        import plistlib
        sys.path.insert(0, str(HERE.parent / "app")); import release
        with tempfile.TemporaryDirectory() as t:
            app = Path(t) / "Rushes.app"; (app / "Contents").mkdir(parents=True)
            (app / "Contents/Info.plist").write_bytes(plistlib.dumps({"CFBundleShortVersionString": "0.12.7",
                "CFBundleIdentifier": "org.rushes.app", "CFBundleExecutable": "Rushes"}))
            asked = []
            def gh(q, timeout=None):
                asked.append(getattr(q, "full_url", q))
                return io.BytesIO(json.dumps({"tag_name": "v0.12.8", "assets": [
                    {"name": "Rushes.zip", "browser_download_url": "https://github.com/x0on/rushes/releases/download/v0.12.8/Rushes.zip"}]}).encode())
            with patch("urllib.request.urlopen", side_effect=gh):
                self.assertEqual(release.app_update("", str(app), check_only=True), "0.12.8")
            self.assertEqual(asked, ["https://api.github.com/repos/x0on/rushes/releases/latest"])
            # A Watcher asks its Rushes which version; that Rushes has no app to give: that version's release on GitHub
            (app / "Contents/Info.plist").write_bytes(plistlib.dumps({"CFBundleShortVersionString": "0.12.7",
                "CFBundleIdentifier": "org.rushes.watcher", "CFBundleExecutable": "Rushes Watcher"}))
            asked.clear()
            def mac(q, timeout=None):
                u = getattr(q, "full_url", q); asked.append(u)
                if u.endswith("?version"): return io.BytesIO(b"0.12.8")
                if "helper.php?app=" in u: raise OSError("404 Not Found")
                if "/releases/tags/v0.12.8" in u:
                    return io.BytesIO(json.dumps({"tag_name": "v0.12.8", "assets": [
                        {"name": "Rushes.Watcher.0.12.8.zip", "browser_download_url": "https://gh.test/Rushes.Watcher.0.12.8.zip"}]}).encode())
                return io.BytesIO(b"not a zip")
            with patch("urllib.request.urlopen", side_effect=mac), self.assertRaises(RuntimeError) as e:
                release.app_update("http://rushes.test", str(app), say=lambda m: None)
            self.assertIn("https://gh.test/Rushes.Watcher.0.12.8.zip", asked, asked)
            self.assertIn("could not", str(e.exception))      # here no macOS to unpack it: said, nothing changed
            with patch("urllib.request.urlopen", side_effect=__import__("urllib.error").error.HTTPError("u", 404, "Not Found", {}, None)), \
                 self.assertRaises(RuntimeError) as e:
                release.latest_release()
            self.assertIn("no release", str(e.exception))

    def test_the_menu_in_order_one_open_help_in_a_submenu_and_what_an_update_did(self):
        m = front("Rushes", Path(tempfile.mkdtemp()))
        os.makedirs(os.path.dirname(m.UPDATED), exist_ok=True)
        Path(m.UPDATED).write_text("ok\t0.12.8\n")
        with patch.object(m, "launchctl", return_value=m.subprocess.CompletedProcess([], 1, "", "")), \
             patch.object(m.Window, "newer", return_value=""):
            got = serve(m)("menu?fresh=1")
        labels = [i.get("label") for i in got["items"]]
        self.assertEqual(sum(1 for l in labels if l and l.startswith("Open Rushes")), 1, labels)
        self.assertIn("Show the Rushes window", labels)
        self.assertNotIn("Lately:", labels)
        self.assertIn("✓ Updated to 0.12.8", labels)
        help_ = next(i for i in got["items"] if i.get("label") == "Help")
        self.assertEqual([i["label"] for i in help_["items"]], ["Show the log", "Collect diagnostics", "Ask for help…"])
        self.assertTrue(plain(got), got)
        Path(m.UPDATED).write_text("failed\t0.12.8\tOperation not permitted\n")
        self.assertIn("App Management", m.update_said())


class BundledCodeTests(unittest.TestCase):
    """A new Rushes Helper brings the helper's code inside it, a signed release,
    installed when newer than the one in place, never when it is not signed."""
    def test_installed_when_newer_and_signed(self):
        import shutil
        with tempfile.TemporaryDirectory() as t:
            home, app = Path(t) / "home", Path(t) / "Rushes Helper.app/Contents/Resources"
            app.mkdir(parents=True); home.mkdir()
            shutil.copy(HERE.parent / "mac/rushes_helper.py", app)
            for f in ("ingest.py", "transfer_state.py", "analyze.py", "release.py", "release.sig"):
                shutil.copy(HERE.parent / "app" / f, app)
            with patch.dict(os.environ, {"RUSHES_NAME": "Rushes", "HOME": str(home)}):
                spec = importlib.util.spec_from_file_location("front_bundled", app / "rushes_helper.py")
                m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
            sys.path.insert(0, str(app))
            try:
                with patch.object(m.os, "execv"), patch("sys.stdout", new_callable=io.StringIO) as out:
                    m.service(["--watch"])
                self.assertIn("installed the helper's code that came with this app", out.getvalue())
                for f in ("ingest.py", "release.sig"):
                    self.assertEqual((Path(m.DIR) / f).read_bytes(), (app / f).read_bytes())
                with patch.object(m.os, "execv"), patch("sys.stdout", new_callable=io.StringIO) as out:
                    m.service(["--watch"])                    # the same release again: left as it is
                self.assertNotIn("installed", out.getvalue())
                (Path(m.DIR) / "release.sig").write_text("")  # older in place, but the app's copy was changed:
                (app / "ingest.py").write_bytes(b"print('not signed')")
                with patch.object(m.os, "execv"), patch("sys.stdout", new_callable=io.StringIO) as out:
                    m.service(["--watch"])
                self.assertIn("was not installed", out.getvalue())
                self.assertNotEqual((Path(m.DIR) / "ingest.py").read_bytes(), b"print('not signed')")
            finally:
                sys.path.remove(str(app)); sys.modules.pop("release", None)


if __name__ == "__main__":
    unittest.main()
