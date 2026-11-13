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
