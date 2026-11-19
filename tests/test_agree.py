"""One rule, said the same everywhere; and every promise has a check.

Rushes says some rules in more than one language (the pages in PHP, the runner and the helper in Python, the NAS
scripts in shell). They have drifted before: the runner stopped trying code files while the page still listed
them (100 "could not" on one line, 35 on another). These fail the moment two copies differ.

    python3 -m unittest test_agree
"""
import json, os, re, subprocess, sys, unittest

HERE = os.path.dirname(os.path.abspath(__file__)); APP = os.path.join(HERE, "..", "app")
sys.path.insert(0, APP)
import runner, analyze

read = lambda *p: open(os.path.join(APP, *p), encoding="utf-8").read()
SAMPLES = ["/a/b.mov", "/a/cam/00001.MTS", "/a/Adobe Premiere Pro Video Previews/x.PRV/Rendered - 1.mov",
           "/a/Adobe Premiere Pro Audio Previews/a.cfa", "/a/app/node_modules/pkg/index.d.mts", "/a/types.d.mts",
           "/a/Cache/c.mov", "/a/Peak Files/p.pek", "/a/Proxies/x.mp4", "/a/caches/keep.mov", "/a/my proxies.mov",
           "/a/Node_Modules/x/y.mp4", "/a/x.prv/z.mov", "/a/x.prv.mov"]


class OneRule(unittest.TestCase):
    def test_what_is_not_footage_is_the_same_rule_everywhere(self):
        rule = runner.NOT_FOOTAGE.pattern
        self.assertEqual(analyze.NOT_FOOTAGE.pattern, rule, "analyze.py (describing) and runner.py (previews)")
        php = re.search(r"const NOT_FOOTAGE = '#(.*)#';", read("db", "config.php")).group(1)
        self.assertEqual(php.replace("\\\\", "\\"), rule, "config.php (the pages) and runner.py")
        names = re.search(r"/\((.*)\)/", rule).group(1)           # adobe premiere … | node_modules
        self.assertIn(names, read("proxy.sh"), "proxy.sh (previews on a NAS) names the same folders")
        self.assertIn(r"\.d\.mts$", read("proxy.sh"))
        # and they answer the same, file by file
        code = 'require $argv[1]; echo json_encode(array_map("not_footage", json_decode($argv[2])));'
        out = subprocess.run(["php", "-r", code, os.path.join(APP, "db", "config.php"), json.dumps(SAMPLES)],
                             capture_output=True, text=True, check=True).stdout
        self.assertEqual(json.loads(out), [bool(runner.NOT_FOOTAGE.search(s.lower())) for s in SAMPLES])
        self.assertEqual(sum(json.loads(out)), 9, "the samples that are not footage")

    def test_an_empty_video_is_the_same_size_everywhere(self):
        self.assertEqual(int(re.search(r"const EMPTY_VIDEO = (\d+);", read("db", "config.php")).group(1)), runner.EMPTY)

    def test_the_videos_that_get_a_preview_are_the_same_list_everywhere(self):
        mac = {e.lstrip(".") for e in runner.VIDEO_EXT}
        nas = set(re.search(r"rel !~ /\\\.\(([^)]*)\)\$/", read("proxy.sh")).group(1).lower().split("|"))
        page = set(re.search(r"preg_match\('/\\\.\(([a-z0-9|]+)\)\$/i', \$x\[0\]\)\) continue;\s+// the same list", read("db", "prepare.php")).group(1).split("|"))
        self.assertEqual(mac, nas, "runner.py and proxy.sh"); self.assertEqual(mac, page, "runner.py and prepare.php (the page's count)")


class Players(unittest.TestCase):
    """Search plays one video at a time. A player taken off the page still playing plays on unseen: sound over
    sound, and its shots written into the description of whatever is shown (0.13.58 and before)."""
    def test_a_player_is_stopped_before_it_leaves_the_page(self):
        js = read("db", "find.php")
        self.assertIn("v.pause(); v.removeAttribute('src'); v.load();", js, "quiet(): stopped and emptied")
        lines = js.splitlines()                            # the panel is never emptied or hidden with a player still in it
        for i, line in enumerate(lines):
            if re.search(r"\$\('inspect'\)\.(hidden = true|innerHTML = )", line) or "box.innerHTML = html" in line:
                self.assertIn("quiet(", lines[i - 1] + line, f"a player may be left playing here: {line.strip()[:90]}")
        self.assertIn("quiet(pv); pv.onclick = null;", js, "the picture starts the player once: a click on Pause is not Play")
        self.assertIn("quiet($('out')); $('out').innerHTML = html;", js, "a new page of results: a tile that was playing is stopped first")

    def test_search_plays_in_the_grid_and_keeps_properties_shut_until_asked(self):
        js = read("db", "find.php")
        self.assertIn("if (e.target.closest('video')) return;", js, "a click on the player's controls picks nothing and starts nothing")
        self.assertIn("function playTile(el)", js); self.assertIn("let PROPS = false;", js, "the panel is shut until Properties is asked for")
        self.assertIn('data-do="props"', js, "right-click offers Properties"); self.assertIn('data-act="info"', js, "and so does the i on a tile")
        self.assertNotIn("show 200 more", js); self.assertIn("function pager()", js, "pages, not a longer and longer page")


class PremierePanel(unittest.TestCase):
    """The panel inside Premiere shows Rushes' own Search and the two speak in short notes; a name changed on one
    side only is caught here."""
    def test_the_panel_is_whole_and_asks_for_what_rushes_answers(self):
        import json, tempfile, zipfile, subprocess, sys
        root = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
        rd = lambda *p_: open(os.path.join(root, *p_)).read()
        m = json.loads(rd("premiere", "manifest.json")); js = rd("premiere", "index.js"); html = rd("premiere", "index.html")
        page = rd("app", "db", "find.php"); php = rd("app", "db", "pulls.php")
        self.assertEqual((m["host"]["app"], m["manifestVersion"], m["main"]), ("premierepro", 5, "index.html"))
        self.assertEqual(m["requiredPermissions"]["webview"]["enableMessageBridge"], "localAndRemote", "the page and the panel can speak")
        for i in m["icons"] + m["entrypoints"][0]["icons"]:
            self.assertTrue(os.path.exists(os.path.join(root, "premiere", i["path"])), i["path"])
        # the panel opens the page dressed for Premiere, and the page knows that dress
        self.assertIn("/db/find.php?premiere=1", js); self.assertIn("isset($_GET['premiere'])", page)
        self.assertIn("wv.src = PAGE + '&t=' + Date.now()", js, "asked for afresh: never a page kept from an older Rushes")
        self.assertNotIn("src=", html.split("<webview")[1].split(">")[0]); self.assertIn("if ($PP) header('Cache-Control: no-store')", page)
        # the notes: each is sent by one side and read by the other
        for note in ("hello", "import"):
            self.assertIn("rushes: '%s'" % note, page); self.assertIn("d.rushes === '%s'" % note, js)
        for note in ("project", "imported"):
            self.assertIn("rushes: '%s'" % note, js); self.assertIn("d.rushes === '%s'" % note, page)
        # the page asks pulls.php for what pulls.php answers, and imports the copies the runner says it made
        for asked, answered in (("?places=1", "isset($_GET['places'])"), ("action: 'copy'", "'copy'"), ("action: 'copy-stop'", "'copy-stop'"), ("action: 'remove'", "'remove'")):
            self.assertIn(asked, page); self.assertIn(answered, php)
        self.assertIn("c.files", page); self.assertIn('state["files"].append([src, out])', rd("app", "runner.py"))
        out = os.path.join(tempfile.mkdtemp(), "p.ccx")
        subprocess.run([sys.executable, os.path.join(root, "premiere", "pack.py"), out], check=True, capture_output=True)
        z = zipfile.ZipFile(out); self.assertEqual(sorted(n for n in z.namelist() if "/" not in n), ["index.html", "index.js", "manifest.json"])
        self.assertEqual(json.loads(z.read("manifest.json"))["version"], rd("app", "VERSION").strip(), "the panel carries Rushes' version")

    def test_search_outside_premiere_is_as_it_was(self):
        page = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "app", "db", "find.php")).read()
        self.assertIn("if (RUSHES.premiere) (function () {", page, "what is Premiere's runs only there")
        self.assertIn("""<?= $PP ? ' class="pp" data-theme="dark"' : '' ?>""", page, "and its dress is put on only there")


class Promises(unittest.TestCase):
    """PROMISES.md: what Rushes always does, or never does. Each line names the check that holds it to it."""
    def test_every_promise_names_a_check_that_exists(self):
        text = open(os.path.join(HERE, "..", "PROMISES.md"), encoding="utf-8").read()
        lines = [l for l in text.splitlines() if l.startswith("- ")]
        self.assertGreater(len(lines), 5)
        for l in lines:
            m = re.search(r"`(tests/[\w.]+)`: “(.+?)”\s*$", l)
            self.assertTrue(m, f"a promise with no check named: {l}")
            body = open(os.path.join(HERE, "..", m.group(1)), encoding="utf-8").read()
            self.assertIn(m.group(2), body, f"{m.group(1)} has no check called “{m.group(2)}”")


if __name__ == "__main__":
    unittest.main()
