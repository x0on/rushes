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
        self.assertIn("""<?= $PP ? ' class="pp" data-theme="dark"' : ($VW ? ' class="vw"' : '') ?>""", page, "and its dress is put on only there")
        self.assertIn("if (RUSHES.viewer) (function () {", page, "what is the Viewer's runs only there")
        self.assertIn("if (!RUSHES.viewer) run();", page)


class Parts(unittest.TestCase):
    """mac/parts.json: every part Rushes for Mac ships or downloads, at its exact version. The parts list given
    with each release (SBOM) is made from it, so it must say what the code really fetches, and what CREDITS.md says."""
    def setUp(self):
        self.root = os.path.join(HERE, "..")
        self.parts = {x["name"]: x for x in json.load(open(os.path.join(self.root, "mac", "parts.json"), encoding="utf-8"))["parts"]}
        self.rd = lambda *p_: open(os.path.join(self.root, *p_), encoding="utf-8").read()

    def test_the_list_says_what_the_code_fetches(self):
        build, ingest, run = self.rd("mac", "build.py"), self.rd("app", "ingest.py"), self.rd("app", "runner.py")
        py, pbs, php, ff = (self.parts[k] for k in ("CPython", "python-build-standalone", "PHP", "FFmpeg"))
        self.assertIn(f'PBS = "cpython-{py["version"]}+{pbs["version"]}-', build, "the Python the app is built with")
        self.assertIn(f'PHP = "php-{php["version"]}-cli-macos-', build, "the PHP the app is built with")
        self.assertIn(f'cpython-{py["version"]}%2B{pbs["version"]}-aarch64-apple-darwin', ingest, "the Python Install the AI downloads")
        self.assertIn(pbs["sha256"]["aarch64"], ingest)
        for f, h in ff["sha256"].items(): self.assertIn(f'"{ff["url"]}{f}",\n' + " " * 16 + f'"{h}"', ingest, "FFmpeg, by its address and its fingerprint")
        one = next(f for f in ff["sha256"] if f.startswith("ffmpeg-"))
        self.assertIn(ff["url"] + one, run, "the runner downloads the same FFmpeg"); self.assertIn(ff["sha256"][one], run)
        self.assertEqual(ff["license"], "LGPL-2.1-or-later", "no GPL part: the FFmpeg rushes-parts compiles")
        self.assertIn("x0on/rushes-parts", ff["url"]); self.assertIn("x0on/rushes-parts", php["url"], "FFmpeg and PHP are the ones compiled from source, in the open")
        self.assertEqual(sorted(php["sha256"]), [f"php-{php['version']}-cli-macos-{c}.tar.gz" for c in ("aarch64", "x86_64")], "PHP for each chip, each with its fingerprint")
        self.assertIn("is not the one parts.json names", build)
        self.assertFalse([n for n in self.parts if n in ("GMP", "libiconv")], "no LGPL library inside PHP")
        asked = re.search(r"AI_PACKAGES = \[(.*)\]\n", ingest).group(1)
        for k in ("mlx-vlm", "mlx-whisper", "scenedetect", "huggingface_hub"):
            self.assertRegex(asked, re.escape(k) + r"(\[[^\]]*\])?==" + re.escape(self.parts[k]["version"]) + '"', f"{k} is asked for at the version listed")
        for one in re.findall(r'"([^"]+)"', asked): self.assertIn("==", one, "no describing tool without a version")
        self.assertIn(f'mlx=={self.parts["MLX"]["version"]} ', ingest)

    def test_every_part_is_credited_and_every_shipped_part_has_a_license_text_named(self):
        credits = self.rd("CREDITS.md")
        for n, x in self.parts.items():
            self.assertIn(f"| {x['credits']} |", credits, f"{n} is in the parts list, so CREDITS.md names it ({x['credits']})")
            self.assertIn(x["where"], ("in the app", "downloaded at setup", "model, downloaded at setup"))
            if x["where"] == "in the app" and x["license"] != "blessing":
                self.assertTrue(x.get("license_file"), f"{n} is shipped inside the app: its license text goes with it")
        build = self.rd("mac", "build.py")
        self.assertIn('sys.exit(f"build.py: no license text for', build, "the build stops without one")
        self.assertIn('.cdx.json")', build, "and writes the parts list beside the app")

    def test_a_build_without_xxhash_is_refused_and_a_release_without_it_shows(self):
        # the part that went missing once (October–November 2026): the build stops, the app that lacks it fails its check
        import io, zipfile, tempfile
        sys.path.insert(0, os.path.join(self.root, "mac")); import check_app
        build = self.rd("mac", "build.py")
        self.assertIn("xxh", self.parts["python-xxhash"]["name"]); self.assertEqual(self.parts["python-xxhash"]["where"], "in the app")
        self.assertEqual(len(self.parts["python-xxhash"]["sha256"]), 2, "a wheel for each chip, each with its fingerprint")
        self.assertIn('is not beside build.py', build); self.assertIn('"check_app.py"), z,', build, "what was built is looked into before it is handed over")
        self.assertIn("mac/check_app.py", self.rd(".github", "workflows", "release-check.yml"), "and every published release is, on GitHub")
        self.assertIn("xxhash==" + self.parts["python-xxhash"]["version"], self.rd(".github", "workflows", "tests.yml"), "the checks run with the same xxHash, so with the fingerprint the app uses")
        import ingest; self.assertEqual(ingest.new_fingerprint()[1], "xxh128", "and these checks are running with it now")
        def app(with_xx):
            f = os.path.join(tempfile.mkdtemp(), "Rushes.zip"); R = "Rushes.app/Contents/Resources/"
            with zipfile.ZipFile(f, "w") as z:
                z.writestr("Rushes.app/Contents/Info.plist", "x")
                for n in ["rushes_helper.py", "CREDITS.md", "pages/VERSION", "pages/db/find.php", "pages/db/viewer.js", "runner.py", "ingest.py", "analyze.py", "release.py",
                          "php-arm64", "php-x86_64", "python-arm64/bin/python3", "python-x86_64/bin/python3"]: z.writestr(R + n, "0.0.0")
                z.writestr(R + "release.sig", "rushes-release 1\n")
                for x in self.parts.values():
                    if x["where"] == "in the app" and x.get("license_file"): z.writestr(R + "LICENSES/" + x["license_file"], "L" * 300)
                if with_xx:
                    for c in ("arm64", "x86_64"): z.writestr(R + f"python-{c}/lib/python3.12/site-packages/xxhash/_xxhash.cpython-312-darwin.so", "so")
            return check_app.check(f)
        self.assertFalse([l for l in app(True) if l.startswith("FAIL")], "an app with every part passes")
        self.assertEqual([l[:21] for l in app(False) if l.startswith("FAIL")], ["FAIL xxHash for arm64", "FAIL xxHash for x86_6"], "one without xxHash fails, and says which part")

    def test_what_credits_says_ships_in_the_app_is_in_the_list(self):
        rows = [l.split("|") for l in self.rd("CREDITS.md").splitlines() if "| Inside Rushes Helper |" in l]
        self.assertGreater(len(rows), 3)
        named = {x["credits"] for x in self.parts.values()}
        for r in rows: self.assertIn(r[1].strip(), named, "shipped inside the app, so it is in the parts list")


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
