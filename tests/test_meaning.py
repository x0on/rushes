"""meaning.py without its model: the index learns each moment once, keeps it by its words,
follows the catalogue, and a search returns the closest moments, best first.
Run: python3 tests/test_meaning.py   (the real model's own check: meaning.py --check DIR)"""
import os, sqlite3, sys, tempfile
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "app"))
try:
    import numpy as np
except ImportError:
    print("SKIP numpy is not installed here (meaning.py runs in the AI's Python)"); sys.exit(0)
import meaning

WORDS = ["old", "grandparents", "abuelos", "building", "drone", "aerial", "dron", "park", "car"]
SAME = {"grandparents": "old", "abuelos": "old", "aerial": "drone", "dron": "drone"}


class Fake:
    """A stand-in model: one number per idea, so words meaning the same land together."""
    name, calls = "fake", 0

    def __call__(self, texts):
        Fake.calls += len(texts)
        out = np.zeros((len(texts), meaning.DIMS), np.float32)
        for i, t in enumerate(texts):
            for w in t.lower().replace(".", " ").replace(",", " ").split():
                w = SAME.get(w, w)
                if w in WORDS: out[i, WORDS.index(w)] += 1
            out[i, -1] += 0.01
        return out / np.linalg.norm(out, axis=1, keepdims=True)


def check(ok, what):
    if not ok: raise SystemExit(f"FAIL {what}")
    print(f"PASS {what}")


d = tempfile.mkdtemp()
db = os.path.join(d, "rushes.sqlite")
c = sqlite3.connect(db)
c.execute("CREATE TABLE moments (fp, path, kind, shot, start_s, end_s, what, on_screen, themes, tags)")
c.executemany("INSERT INTO moments (fp, kind, start_s, what, themes, tags) VALUES (?,?,?,?,?,?)", [
    ("v1", "shot", 0.0, "An old man in a car", "family", "car · old"),
    ("v2", "shot", 4.0, "A building on a street", "", ""),
    ("v3", "shot", 0.0, "Drone over a park", "", ""),
    ("v3", "speech", 2.0, "Look at the park from up here", "", ""),
    ("v4", "failed", 0.0, "", "", ""),
])
c.commit()

idx = meaning.Index(Fake(), db, os.path.join(d, "meaning.sqlite"))
idx.refresh()
check(idx.state["ready"] and idx.state["moments"] == 4 and idx.state["understood"] == 4, "every shot and line of speech is learnt (a failed shot is not)")
r = idx.search("abuelos", k=10, floor=0.3)
check(r and r[0][:3] == ["v1", "shot", 0.0], "a word in another language finds what it means")
check(idx.search("aerial", floor=0.3)[0][0] == "v3", "and a word never written finds its meaning (aerial: the drone shot)")
check(all(x[3] >= 0.3 for x in r) and [x[3] for x in r] == sorted((x[3] for x in r), reverse=True), "only close moments, best first")
check(idx.search("   ") == [], "an empty search finds nothing")

before = Fake.calls
idx.refresh()
check(Fake.calls == before, "nothing changed: nothing is worked out again")
c.execute("INSERT INTO moments (fp, kind, start_s, what, themes, tags) VALUES ('v5', 'shot', 1.0, 'An old man in a car', 'family', 'car · old')")
c.commit()
idx.refresh()
check(Fake.calls == before and idx.state["moments"] == 5 and {x[0] for x in idx.search("old", floor=0.5)} >= {"v1", "v5"},
      "a new moment with words already known is found at once, without working them out again")
before = Fake.calls                                    # (the search above worked out its own words)
idx2 = meaning.Index(Fake(), db, os.path.join(d, "meaning.sqlite"))
idx2.refresh()
check(Fake.calls == before, "after a restart, what was learnt is kept (its own file)")
check(meaning.text_of("shot", "A car", "family · trip", "") == "A car. family, trip" and meaning.text_of("speech", "hola", "x", "y") == "hola",
      "a shot means its description and themes; speech, what was said")
print("Meaning tests complete.")

# The runner (runner.py) says how meaning search is, for Manage → Describe, and never starts it without the AI
import json, runner
web = tempfile.mkdtemp()
r = runner.Runner(web, "http://127.0.0.1:9")
r.ai_python = lambda: ""
r.meaning()
check(json.load(open(os.path.join(web, "meaning.json")))["state"] == "no-ai" and not getattr(r, "meaning_proc", None),
      "no AI on this Mac: meaning search is not started, and Describe is told why")
r.ai_python = lambda: sys.executable
r.meaning_dir = lambda: os.path.join(web, "none")
r.meaning()
check(json.load(open(os.path.join(web, "meaning.json")))["state"] == "not-installed", "the AI, but not the model: Describe offers to install it")
print("Runner meaning tests complete.")
