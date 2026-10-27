#!/usr/bin/env python3
# Rushes — Media Management Software, by Alejandro Renteria.
# Source available: https://github.com/x0on/rushes — whoever finds this file on a computer can see what it is and who made it.
"""analyze.py — describe footage so it can be found by what it shows and says.

    analyze.py FOLDER_OR_FILE --store /Volumes/VIDEO/_rushes/analysis [--url RUSHES]

For every video, photo or audio file under the folder:
  1. cut it into shots (PySceneDetect), and take two stills of each shot (one
     of a very short shot), never in its first second or last half second;
  2. ask the vision model what each shot shows: a sentence, text on screen,
     shot size, people, age bands, setting, light, mood, themes, tags;
  3. write down what is said in it (Whisper), in the language it was said;
  4. keep one picture per shot (the first still the model saw, 768 px wide),
     for the search results.

Everything is written to one JSON file per media file, in --store, named by the
file's content fingerprint (not its path), so it survives the file being moved
or renamed by a tidy-up. The search index is rebuilt from these files; they are
the record, the database is only a view of them.

Which models run is a setting (--model, --whisper; Rushes passes its settings
analysis.model and analysis.whisper, and the defaults below are used only when
those are not set): the question and the answer fields are fixed, and every
result says which model and which question produced it. Resumable: a file
already described with the same vision model and question is skipped (a
change of speech model alone does not describe it again).

Network: the theme list is read from Rushes (--url). A model given by name
(as the defaults are) is downloaded from Hugging Face by the MLX libraries the
first time, if it is not already on this computer; no footage is sent.

Progress goes to stdout as lines starting "@@ " followed by JSON, which the
helper turns into the live status in Rushes. Everything else is plain log.

Runs in the analysis Python (mlx-vlm, mlx-whisper, scenedetect installed), not
the helper's own; the helper starts it. --selftest checks the parts that need
no model.
"""
import argparse, hashlib, json, os, re, shutil, subprocess, sys, tempfile, time, urllib.request
from pathlib import Path

VERSION = 4          # 4: composition (the lens: where the subject, eyes, horizon and lines fall); 3: moments inside long takes.
                     # A file described before is kept; Describe again adds what it lacks (a 3 gets only its composition, from its saved stills)
VIDEO = {".mxf", ".mov", ".mp4", ".avi", ".mts", ".m4v", ".mkv", ".m2ts", ".wmv"}
IMAGE = {".jpg", ".jpeg", ".png", ".tif", ".tiff", ".heic", ".webp", ".dng"}
AUDIO = {".wav", ".aif", ".aiff", ".mp3", ".m4a", ".aac", ".flac"}
SKIP_PARTS = ("/_rushes/", "/_duplicates/", "/@Recycle/", "/.@__thumb/", "/_media-cache/")

DEFAULT_MODEL = "mlx-community/Qwen3-VL-8B-Instruct-4bit"
DEFAULT_WHISPER = "mlx-community/whisper-large-v3-turbo"
DEFAULT_THEMES = ["Events", "Sports", "Education", "Health & Fitness", "Parks & Recreation",
                  "Public Safety", "Government & Meetings", "Community", "Infrastructure & Construction",
                  "Arts & Culture", "Business", "Holidays & Celebrations", "Environment",
                  "Transportation", "Technology", "Food & Beverage", "Music", "Friends & Family", "Animals"]
SHOT_SIZES = ["extreme-wide", "wide", "full", "medium", "close-up", "extreme-close-up"]
AGES = ["infant", "child", "teen", "adult", "senior"]
LIGHT = ["sunrise-sunset", "daylight", "dusk", "night", "artificial", "unclear"]

PROMPT = """You are describing one shot from a video archive so it can be found later by search.

Return ONLY valid JSON. Every key in double quotes. No text before or after it.

Keys:
  description     one factual sentence describing what is visible
  text_on_screen  array of the exact text you can read in the frame (titles, lower thirds,
                  signs, banners, jerseys, dates), copied verbatim, each once. One entry
                  per line, read left to right as a whole: a sign reading "I ❤ MY TOWN" is
                  one entry, never split into its words or symbols. [] if none.
  shot_size       one of: {sizes} — extreme-wide: a landscape or a whole
                  building or park from far away; wide: a whole place, or a group; full: a
                  person head to toe; medium: a person from the waist up, or an object
                  filling about half the frame; close-up: a face, or an object filling most
                  of the frame; extreme-close-up: a small detail
  people          one of: none, one, two, few, crowd
  ages            array, any of: {ages} — only for people clearly visible, [] if none
  setting         one of: indoor, outdoor, mixed
  light           one of: {light}
  mood            one or two words, e.g. celebratory, tense, routine, solemn, energetic
  themes          array of 0 to 3, chosen ONLY from: {themes} — only a theme
                  the shot is clearly about; [] when none fits
  tags            array of 3-8 short lowercase words for objects, actions and places you
                  see — not the on-screen text again

Be literal. Describe only what you can see. Do not guess who people are or where this is
unless it is written in the frame.
If the frame looks like two images blended together, it is a dissolve: say so and
describe the clearer one."""


COMPOSE = """Look at this video frame as a camera operator and mark where things are.

Return ONLY valid JSON. Every key in double quotes. No text before or after it.
Coordinates are 0 to 1000 across the frame: x from the left edge, y from the top edge.

Keys:
  subject   the main subject, what the eye goes to first (a person, a group, a podium, a sign,
            a building, the sun): {"what": <who or what it is, in your own words>, "box": [x0, y0, x1, y1]}, or null
            when nothing stands out (a crowd, an empty room)
  eyes      [x, y] between the eyes of the main person, or null when the subject is not a person
            or the eyes cannot be seen
  facing    where the subject looks or moves: left, right, camera, away, or none
  horizon   the horizon, or the main horizontal edge (a tree line, a stage edge, the far edge of a
            field): [[x, y], [x, y]] at its two ends, or null when there is none
  lines     up to 3 strong straight lines (a road, a rail, a roof edge, a table): each
            [[x, y], [x, y]], [] if none

Mark only what you can see."""
# ponytail: nothing is "judged" by the model (silhouette, symmetry, leading lines…): in two runs on 20 stills
# it said them on most frames, and differently each time (0.13.37). Measure them from the pixels later.
ROOM = 0.05          # rule of space or short-sided only when the room ahead and behind differ by more than this


def prompt_text(themes):
    return PROMPT.format(sizes=", ".join(SHOT_SIZES), ages=", ".join(AGES),
                         light=", ".join(LIGHT), themes="; ".join(themes))


def prompt_id(themes):
    """Which question produced a result: re-running with a new one is comparable."""
    return hashlib.sha256(prompt_text(themes).encode()).hexdigest()[:10]


def say(**kw):
    print("@@ " + json.dumps(kw), flush=True)


# ── the file itself ─────────────────────────────────────────────────────────
def fingerprint(path, chunk=4 << 20):
    """Size plus the first and last 4 MB: stable when a file moves or is renamed,
    different for different footage. Read in seconds even over a network."""
    size = os.path.getsize(path)
    h = hashlib.sha256(str(size).encode())
    with open(path, "rb") as f:
        h.update(f.read(chunk))
        if size > chunk:
            f.seek(max(chunk, size - chunk))
            h.update(f.read(chunk))
    return h.hexdigest()[:24]


def tool(name):
    """ffmpeg/ffprobe: a background service has a bare PATH, so look where
    Homebrew puts them too. Also puts them on PATH for Whisper, which calls ffmpeg."""
    for d in (os.path.expanduser("~/archive-pilot/ai/bin"), "/opt/homebrew/bin", "/usr/local/bin"):   # Install the AI's, then Homebrew's
        if os.path.exists(os.path.join(d, name)) and d not in os.environ.get("PATH", ""):
            os.environ["PATH"] = d + os.pathsep + os.environ.get("PATH", "")
    found = shutil.which(name)
    if not found:
        sys.exit(f"{name} is not installed on this computer — the analysis tools need it.")
    return found


def probe(path):
    """-> duration (s), has audio, has video, camera clock (as the file says it)."""
    out = subprocess.run([tool("ffprobe"), "-v", "error", "-show_entries",
                          "format=duration:format_tags=creation_time:stream=codec_type",
                          "-of", "json", str(path)], capture_output=True, text=True).stdout
    try:
        d = json.loads(out or "{}")
    except ValueError:
        d = {}
    kinds = {s.get("codec_type") for s in d.get("streams", [])}
    fmt = d.get("format", {})
    try:
        dur = float(fmt.get("duration") or 0)
    except ValueError:
        dur = 0.0
    return dur, "audio" in kinds, "video" in kinds, (fmt.get("tags") or {}).get("creation_time", "")


def part_of_day(clock):
    """The camera's own clock: morning, afternoon, evening or night. Cameras often
    store local time marked as UTC; the hour is taken as written."""
    m = re.search(r"T?(\d{2}):\d{2}", clock or "")
    if not m:
        return ""
    h = int(m.group(1))
    return "night" if h < 5 else "morning" if h < 12 else "afternoon" if h < 17 else "evening" if h < 21 else "night"


# ── shots and frames ────────────────────────────────────────────────────────
def shots_of(path, duration):
    from scenedetect import detect, ContentDetector
    try:
        found = detect(str(path), ContentDetector(threshold=27.0))
    except Exception as e:
        print(f"    shot detection failed ({e}) — treating it as one shot")
        found = []
    if not found:
        return [(0.0, duration)]
    return whole_shots([(s.get_seconds(), e.get_seconds()) for s, e in found])


def whole_shots(cuts, least=1.0):
    """A "shot" under a second is a flash, a dissolve or a false cut, not a shot
    anyone looks for: it becomes part of the shot before it (the first, of the
    one after)."""
    out = []
    for a, b in cuts:
        if out and b - a < least:
            out[-1] = (out[-1][0], b)
        else:
            out.append((a, b))
    if len(out) > 1 and out[0][1] - out[0][0] < least:
        out[1] = (out[0][0], out[1][1]); out.pop(0)
    return out


def moments(cuts, long=8.0, every=5.0):
    """A long take (a handheld speech, a dance floor, a swing) is one shot to the cut detector, and its best
    moments hide inside it: a shot longer than `long` seconds is looked at every `every` seconds too, each piece
    its own entry, said as part of its take. -> [(start, end, take, moment, of)]; moment 0 is a whole shot."""
    out = []
    for t, (a, b) in enumerate(cuts):
        if b - a <= long:
            out.append((a, b, t, 0, 1)); continue
        n = max(2, round((b - a) / every)); step = (b - a) / n
        out += [(a + k * step, a + (k + 1) * step if k < n - 1 else b, t, k + 1, n) for k in range(n)]
    return out


def sample_times(a, b):
    """Where in a shot to look. Never its first frames: the camera is still
    settling — moving, out of focus, exposure hunting — and a recording's very
    start is the worst of all. Skip the first second of a shot (the first two of
    the file) and its last half second; look at 35% and 70% of what is left.
    A shot too short for that gets one look, in its middle."""
    lo = a + (2.0 if a < 0.01 else 1.0)
    hi = b - 0.5
    if hi - lo < 0.5:
        return [(a + b) / 2]
    return [lo + (hi - lo) * 0.35, lo + (hi - lo) * 0.7]


def frame(path, t, out, width=768):
    subprocess.run([tool("ffmpeg"), "-nostdin", "-loglevel", "error", "-ss", f"{t:.3f}", "-i", str(path),
                    "-frames:v", "1", "-vf", f"scale={width}:-2", "-q:v", "4", "-y", str(out)], check=False)
    return out.exists() and out.stat().st_size > 0


# ── what the model answers, made trustworthy ────────────────────────────────
def parse_json(text):
    """Models answer in near-JSON: unquoted keys, trailing commas, prose around
    it. Repair those rather than lose the answer."""
    s, e = text.find("{"), text.rfind("}")
    if s < 0 or e <= s:
        return None
    blob = text[s:e + 1]
    quoted = re.sub(r'([{,]\s*)([A-Za-z_][A-Za-z0-9_]*)\s*:', r'\1"\2":', blob)
    for attempt in (blob, quoted, re.sub(r',(\s*[}\]])', r'\1', quoted)):
        try:
            v = json.loads(attempt)
            return v if isinstance(v, dict) else None
        except json.JSONDecodeError:
            continue
    return None


def pick(v, allowed, default):
    v = str(v or "").strip().lower()
    return v if v in allowed else default


def people_of(v):
    if isinstance(v, (int, float)):
        n = int(v)
        return "none" if n <= 0 else "one" if n == 1 else "two" if n == 2 else "few" if n < 8 else "crowd"
    return pick(v, ["none", "one", "two", "few", "crowd"], "none")


def uniq(xs, key=lambda x: x.lower()):
    seen, out = set(), []
    for x in xs:
        k = key(x)
        if k and k not in seen:
            seen.add(k); out.append(x)
    return out


def tidy(raw, themes):
    """One consistent record, whatever the model's habits: known values only,
    each list without repeats, tags that do not echo the on-screen text."""
    text = uniq([str(t).strip() for t in (raw.get("text_on_screen") or []) if str(t).strip()])[:12]
    # A lone letter or symbol ("I", "❤") is a piece of a line the model split up,
    # not text anyone searches for: kept only when it is all there is.
    whole = [t for t in text if len(re.sub(r"\W", "", t)) >= 2]
    text = whole or text
    said = {t.lower() for t in text}
    tags = uniq([str(t).strip().lower() for t in (raw.get("tags") or []) if str(t).strip()])
    tags = [t for t in tags if t not in said and not any(t in s or s in t for s in said)][:8]
    by_name = {t.lower(): t for t in themes}
    return {
        "description": str(raw.get("description") or "").strip()[:400],
        "text_on_screen": text,
        "shot_size": pick(raw.get("shot_size"), SHOT_SIZES, ""),
        "people": people_of(raw.get("people")),
        "ages": uniq([a for a in (str(x).strip().lower() for x in (raw.get("ages") or [])) if a in AGES]),
        "setting": pick(raw.get("setting"), ["indoor", "outdoor", "mixed"], ""),
        "light": pick(raw.get("light") or raw.get("time_of_day"), LIGHT, "unclear"),
        "mood": str(raw.get("mood") or "").strip().lower()[:40],
        "themes": uniq([by_name[t.lower()] for t in (str(x).strip() for x in (raw.get("themes") or []))
                        if t.lower() in by_name])[:3],
        "tags": tags,
    }


def merge_text(records):
    """Several frames read the same title; a title caught mid-animation reads
    short. Keep the longest reading of each."""
    out = []
    for t in sorted({x for r in records for x in r}, key=len, reverse=True):
        if not any(t.lower() in o.lower() for o in out):
            out.append(t)
    return out


# ── the models ──────────────────────────────────────────────────────────────
class Vision:
    def __init__(self, name, themes):
        from mlx_vlm import load
        from mlx_vlm.utils import load_config
        self.name, self.themes = name, themes
        print(f"loading the vision model {name} …", flush=True)
        self.model, self.processor = load(name)
        self.config = load_config(name)

    def ask(self, frames, extra="", question=None, tokens=420):
        from mlx_vlm import generate
        from mlx_vlm.prompt_utils import apply_chat_template
        p = apply_chat_template(self.processor, self.config, (question or prompt_text(self.themes)) + extra,
                                num_images=len(frames))
        try:   # a repetition penalty stops the model looping on one word
            out = generate(self.model, self.processor, p, frames, max_tokens=tokens,
                           repetition_penalty=1.15, verbose=False)
        except TypeError:
            out = generate(self.model, self.processor, p, frames, max_tokens=tokens, verbose=False)
        return out if isinstance(out, str) else getattr(out, "text", str(out))

    def describe(self, frames):
        """-> (record, raw text, ok). Tried twice; a failure keeps the raw answer."""
        raw = self.ask(frames)
        got = parse_json(raw)
        if got is None:
            raw = self.ask(frames, "\n\nYour last answer was not valid JSON. Answer with the JSON only, "
                                   "each key in double quotes, no repeated items.")
            got = parse_json(raw)
        if got is None:
            return {"description": "", "parse_error": True}, raw[:2000], False
        return tidy(got, self.themes), "", True

    def compose(self, frame):
        """Where the subject, eyes, horizon and lines are in one frame (the model's part), and the composition
        that follows from them (the lens: geometry, no model). None when the model's answer cannot be read."""
        q = COMPOSE
        got = parse_json(self.ask([frame], question=q, tokens=300))
        if got is None:
            got = parse_json(self.ask([frame], "\n\nAnswer with the JSON only.", question=q, tokens=300))
        return composition(got, frame) if isinstance(got, dict) else None


# ── composition: the lens (HOW-IT-WORKS.md → Composition) ───────────────────
# The model marks where things are; the tags are worked out here, by geometry, the same way as the
# Composition Lens page, so a tag can always be checked: each lit tag says which mark fell on which guide,
# and how far from it (a fraction of the frame's short side).
PHI = 0.381966
TOL = 0.05


def _anchors_from(got):
    """The model's marks (0 to 1000) as frame fractions, kept inside the frame; anything malformed is left out."""
    def pt(v):
        try:
            x, y = float(v[0]), float(v[1])
        except (TypeError, ValueError, IndexError):
            return None
        return {"x": min(1, max(0, x / 1000)), "y": min(1, max(0, y / 1000))}
    def ln(v):
        try:
            a, b = pt(v[0]), pt(v[1])
        except (TypeError, IndexError):
            return None
        return {"a": a, "b": b} if a and b and (abs(a["x"] - b["x"]) + abs(a["y"] - b["y"])) > 0.02 else None
    m = {}
    sub = got.get("subject")
    if isinstance(sub, dict):
        try:
            x0, y0, x1, y1 = [min(1, max(0, float(v) / 1000)) for v in sub.get("box", [])[:4]]
            if x1 - x0 > 0.01 and y1 - y0 > 0.01:
                m["subject"] = {"x0": x0, "y0": y0, "x1": x1, "y1": y1}
                what = str(sub.get("what", "")).strip()
                m["what"] = "" if what.startswith("<") or what.lower() in ("a few words", "few words") else what[:80]
        except (TypeError, ValueError):
            pass
    if got.get("eyes") is not None:
        e = pt(got["eyes"])
        if e: m["eyes"] = e
    if got.get("horizon"):
        h = ln(got["horizon"])
        if h: m["horizon"] = h
    lines = [ln(v) for v in (got.get("lines") or [])[:3]]
    if any(lines): m["lines"] = [l for l in lines if l]
    f = str(got.get("facing", "none")).lower().strip()
    m["facing"] = f if f in ("left", "right", "camera", "away") else "none"
    return m


def _layer(lid, W, H):
    """One guide of the lens as lines and points, in pixels of a W x H frame."""
    lines, points = [], []
    L = lambda a, b, label: lines.append((a, b, label))
    P = lambda p, label: points.append((p, label))
    def foot(c, a, b):
        vx, vy = b[0] - a[0], b[1] - a[1]; t = ((c[0] - a[0]) * vx + (c[1] - a[1]) * vy) / (vx * vx + vy * vy)
        return (a[0] + t * vx, a[1] + t * vy)
    def to_edge(c, d):
        s = min([v for v in [(W - c[0]) / d[0] if d[0] > 1e-9 else None, -c[0] / d[0] if d[0] < -1e-9 else None,
                             (H - c[1]) / d[1] if d[1] > 1e-9 else None, -c[1] / d[1] if d[1] < -1e-9 else None] if v is not None])
        return (c[0] + d[0] * s, c[1] + d[1] * s)
    def grid(f, name):
        for i, t in enumerate((f, 1 - f)):
            L((W * t, 0), (W * t, H), ("right" if i else "left") + " vertical " + name)
            L((0, H * t), (W, H * t), ("lower" if i else "upper") + " horizontal " + name)
        for i, tx in enumerate((f, 1 - f)):
            for j, ty in enumerate((f, 1 - f)):
                P((W * tx, H * ty), ("lower" if j else "upper") + "-" + ("right" if i else "left") + " " + name + " intersection")
    TL, TR, BL, BR = (0, 0), (W, 0), (0, H), (W, H)
    if lid == "thirds": grid(1 / 3, "third")
    if lid == "phi": grid(PHI, "phi line")
    if lid == "center":
        L((W / 2, 0), (W / 2, H), "vertical centre line"); L((0, H / 2), (W, H / 2), "horizontal centre line"); P((W / 2, H / 2), "centre")
    if lid == "diag":
        L(TL, BR, "descending diagonal (top-left to bottom-right)"); L(BL, TR, "ascending diagonal (bottom-left to top-right)")
    if lid == "diag45":
        for c, d, n in ((TL, (1, 1), "top-left"), (TR, (-1, 1), "top-right"), (BL, (1, -1), "bottom-left"), (BR, (-1, -1), "bottom-right")):
            L(c, to_edge(c, d), "45° line from the " + n + " corner")
    if lid in ("gtri", "gtri2"):
        a, b, c1, c2 = (BL, TR, TL, BR) if lid == "gtri" else (TL, BR, TR, BL)
        L(a, b, "main diagonal")
        for c in (c1, c2):
            f = foot(c, a, b); L(c, f, "perpendicular from a corner"); P(f, "golden triangle point (where the perpendicular meets the diagonal)")
    if lid == "dynsym":
        L(TL, BR, "descending diagonal"); L(BL, TR, "ascending diagonal")
        for c, a, b, n in ((TL, BL, TR, "top-left"), (BR, BL, TR, "bottom-right"), (TR, TL, BR, "top-right"), (BL, TL, BR, "bottom-left")):
            f = foot(c, a, b); L(c, to_edge(c, (f[0] - c[0], f[1] - c[1])), "reciprocal from the " + n + " corner")
            P(f, "eye where the " + n + " reciprocal meets a diagonal")
    return lines, points


LAYERS = ["thirds", "phi", "center", "diag", "diag45", "gtri", "gtri2", "dynsym"]
POINT_ON_LINES = ("thirds", "phi", "center", "diag45")


def _dist(p, q): return ((p[0] - q[0]) ** 2 + (p[1] - q[1]) ** 2) ** 0.5
def _dist_line(p, a, b):
    vx, vy = b[0] - a[0], b[1] - a[1]; return abs(vx * (a[1] - p[1]) - vy * (a[0] - p[0])) / ((vx * vx + vy * vy) ** 0.5)
def _dist_seg(p, a, b):
    vx, vy = b[0] - a[0], b[1] - a[1]; t = max(0, min(1, ((p[0] - a[0]) * vx + (p[1] - a[1]) * vy) / (vx * vx + vy * vy)))
    return _dist(p, (a[0] + t * vx, a[1] + t * vy))
def _angle(a, b):
    import math
    d = math.degrees(math.atan2(b[1] - a[1], b[0] - a[0])) % 180; return d
def _adiff(x, y):
    d = abs(x - y) % 180; return min(d, 180 - d)


def lens(m, W, H, plain=None, tol=TOL):
    """Every mark against every guide -> (tags, lit). The same rules as the Composition Lens page:
    a subject's centre counts left to right only (vertical lines); eyes against points first, then lines,
    for the grids and the 45° method; horizon and lines against lines of the same angle (within 5°);
    golden only when closer than the thirds; the 45° method for a point only when closer than both grids;
    the spiral is never a tag (it is judged). `plain`: how much of the frame outside the subject has no edges."""
    import math
    T, S = tol * min(W, H), min(W, H)
    an = []
    if "subject" in m:
        s = m["subject"]; an.append(("subject", "point", (W * (s["x0"] + s["x1"]) / 2, H * (s["y0"] + s["y1"]) / 2)))
    if "eyes" in m: an.append(("eyes", "point", (W * m["eyes"]["x"], H * m["eyes"]["y"])))
    if "horizon" in m: an.append(("horizon", "line", ((W * m["horizon"]["a"]["x"], H * m["horizon"]["a"]["y"]), (W * m["horizon"]["b"]["x"], H * m["horizon"]["b"]["y"]))))
    for i, l in enumerate(m.get("lines", [])): an.append(("line %d" % (i + 1), "line", ((W * l["a"]["x"], H * l["a"]["y"]), (W * l["b"]["x"], H * l["b"]["y"]))))
    res = {}
    grids = {k: _layer(k, W, H) for k in LAYERS}
    for lid in LAYERS:
        lines, points = grids[lid]; hits = []
        for what, kind, g in an:
            if kind == "point" and what == "subject":
                if lid in POINT_ON_LINES and lid != "diag45":
                    for a, b, label in lines:
                        if abs(a[0] - b[0]) <= 1 and _dist_seg(g, a, b) <= T: hits.append((what, label, _dist_seg(g, a, b) / S))
                continue
            if kind == "point":
                best = min(((_dist(g, p), label) for p, label in points), default=None)
                if best and best[0] <= T * 1.2:
                    hits.append((what, best[1], best[0] / S)); continue
                if lid in POINT_ON_LINES:
                    for a, b, label in lines:
                        d = _dist_seg(g, a, b)
                        if d <= T: hits.append((what, label, d / S))
            else:
                ang = _angle(*g)
                for a, b, label in lines:
                    if _adiff(ang, _angle(a, b)) > 5: continue
                    d = (_dist_line(g[0], a, b) + _dist_line(g[1], a, b)) / 2
                    if d <= T: hits.append((what, label, d / S))
        seen = set(); hits = [h for h in hits if not ((h[0], h[1]) in seen or seen.add((h[0], h[1])))]
        if lid == "phi":
            tl, tp = grids["thirds"]
            def closer(h):
                g = next(x[2] for x in an if x[0] == h[0])
                if isinstance(g[0], tuple):
                    ds = [(_dist_line(g[0], a, b) + _dist_line(g[1], a, b)) / 2 for a, b, _ in tl if _adiff(_angle(*g), _angle(a, b)) <= 5]
                elif "intersection" in h[1]: ds = [_dist(g, p) for p, _ in tp]
                else: ds = [_dist_seg(g, a, b) for a, b, label in tl if ("vertical" in label) == ("vertical" in h[1])]
                return not ds or h[2] < min(ds) / S
            hits = [h for h in hits if closer(h)]
        if lid == "diag45":
            gl = grids["thirds"][0] + grids["phi"][0]
            hits = [h for h in hits if not isinstance(next(x[2] for x in an if x[0] == h[0])[0], (int, float))
                    or h[2] < min(_dist_seg(next(x[2] for x in an if x[0] == h[0]), a, b) for a, b, _ in gl) / S]
        res[lid] = hits
    tags = []
    add = lambda t: t not in tags and tags.append(t)
    lit = lambda k: bool(res[k])
    if lit("thirds"):
        add("rule_of_thirds")
        if any("intersection" in h[1] for h in res["thirds"]): add("thirds_intersection")
        if any(h[0] == "horizon" for h in res["thirds"]): add("horizon_on_third")
    if lit("phi"): add("golden_rectangle")
    if lit("center"):
        if any(h[0] != "horizon" and ("vertical" in h[1] or h[1] == "centre") for h in res["center"]): add("centered")
        if any(h[0] == "horizon" for h in res["center"]): add("horizon_centered")
    if lit("diag"): add("diagonals")
    if lit("diag45"): add("diagonal_method")
    if lit("gtri") or lit("gtri2"): add("golden_triangle")
    if lit("dynsym"): add("dynamic_symmetry")
    if "horizon" in m:
        a, b = m["horizon"]["a"], m["horizon"]["b"]
        t = abs(math.degrees(math.atan2((b["y"] - a["y"]) * H, (b["x"] - a["x"]) * W)))
        t = min(t, 180 - t)
        if t <= 2: add("level_horizon")
        # ponytail: no Dutch angle from the model's line: three runs on 20 stills drew tilted lines on level
        # frames (a cart path, a table edge). Back when the horizon is measured from the pixels.
    for l in m.get("lines", []):
        dx, dy = (l["b"]["x"] - l["a"]["x"]) * W, (l["b"]["y"] - l["a"]["y"]) * H
        deg = abs(math.degrees(math.atan2(dy, dx))) % 180; slope = 180 - deg if deg > 90 else deg
        if 20 <= slope <= 70:
            rising = dx * -dy > 0
            add("ascending_diagonal" if rising else "descending_diagonal"); add("active_diagonal" if rising else "entrance_diagonal")
    if "subject" in m:
        s = m["subject"]; area = abs((s["x1"] - s["x0"]) * (s["y1"] - s["y0"]))
        if area < 0.10 and plain is not None and plain > 0.75: add("negative_space")
        if area > 0.60: add("fill_the_frame")
        f = m.get("facing")
        if f in ("left", "right"):
            ahead = 1 - s["x1"] if f == "right" else s["x0"]; behind = s["x0"] if f == "right" else 1 - s["x1"]
            if abs(ahead - behind) > ROOM:                                     # centred: neither
                add("rule_of_space" if ahead > behind else "short_sided")
    lit_list = [{"layer": k, "anchor": h[0], "on": h[1], "distance": round(h[2], 3)} for k in LAYERS for h in res[k]]
    return tags, lit_list


def plainness(frame, box):
    """How much of the frame outside the subject has no edges (sky, a wall, water, darkness): 0 to 1, or None."""
    try:
        from PIL import Image
        im = Image.open(frame).convert("L"); w = 160; h = max(2, round(160 * im.height / im.width)); im = im.resize((w, h))
        px = im.load(); out = plain = 0
        for y in range(1, h - 1):
            for x in range(1, w - 1):
                if box and box["x0"] <= x / w <= box["x1"] and box["y0"] <= y / h <= box["y1"]: continue
                out += 1
                if abs(px[x + 1, y] - px[x - 1, y]) + abs(px[x, y + 1] - px[x, y - 1]) <= 24: plain += 1
        return plain / out if out else None
    except Exception:
        return None


def composition(got, frame):
    """The model's marks -> the composition record kept on a shot: marks, tags, and what lit."""
    m = _anchors_from(got)
    try:
        from PIL import Image
        with Image.open(frame) as im: W, H = im.size
    except Exception:
        W, H = 1920, 1080
    tags, lit = lens(m, W, H, plainness(frame, m.get("subject")) if "subject" in m else None)
    return {"marks": m, "tags": tags, "lit": lit}


def transcribe(path, model):
    """What is said, with times, in the language it was said. Stretches Whisper
    marks as probably not speech are dropped: that is where it invents words."""
    import mlx_whisper
    tool("ffmpeg")
    r = mlx_whisper.transcribe(str(path), path_or_hf_repo=model, condition_on_previous_text=False,
                               word_timestamps=False, verbose=None)
    segs = [{"start": round(s["start"], 2), "end": round(s["end"], 2), "text": s["text"].strip()}
            for s in r.get("segments", [])
            if s.get("text", "").strip() and s.get("no_speech_prob", 0) < 0.6]
    return {"language": r.get("language", ""), "segments": segs} if segs else None


# ── one file ────────────────────────────────────────────────────────────────
def analyse(path, store, vision, whisper_model, themes, force, n, of, proxy=None, again=False):
    fp = fingerprint(path)                  # always the original: that is what search finds
    out = store / fp[:2] / f"{fp}.json"
    pid = prompt_id(themes)
    if out.exists() and not force:
        try:
            old = json.loads(out.read_text())
            if again and old.get("version", 1) == 3 and old.get("prompt") == pid and old.get("model") == vision.name:
                return compose_only(old, out, store / fp[:2] / fp, vision, path, n, of)
            if old.get("prompt") == pid and old.get("model") == vision.name and not (again and old.get("version", 1) < VERSION):
                changed = False
                pod = part_of_day(old.get("camera_clock", ""))
                if old.get("part_of_day", "") != pod:                         # the clock read right (0.13.34: midnight to 5 is night)
                    old["part_of_day"] = pod; changed = True
                if str(path) not in old.get("seen_at", []):          # the same footage, somewhere else too
                    old["seen_at"] = sorted(set(old.get("seen_at", [])) | {str(path)}); changed = True
                # Speech only, again: it failed last time, or a different speech
                # model is chosen now. The picture's description is kept.
                if whisper_model and (old.get("speech_failed") or old.get("whisper") not in ("", whisper_model)):
                    print(f"[{n}/{of}] {path.name} — speech again ({'it failed last time' if old.get('speech_failed') else 'new speech model'})")
                    say(file=path.name, n=n, of=of, step="speech")
                    try:
                        old["speech"] = transcribe(proxy or path, whisper_model)
                        old["whisper"] = whisper_model; old.pop("speech_failed", None)
                        result = "done"
                    except Exception as e:
                        old["speech_failed"] = str(e)[:200]
                        print(f"    could not transcribe ({e}) — its pictures stay described")
                        result = "speech"           # described; only its speech is missing (not "could not be described")
                    tmp = out.with_suffix(".json.new")
                    tmp.write_text(json.dumps(old, indent=1, ensure_ascii=False)); os.replace(tmp, out)
                    return result
                if changed:
                    out.write_text(json.dumps(old, indent=1, ensure_ascii=False))
                if old.get("version", 1) < VERSION:                          # kept: Describe again redoes it
                    print(f"[{n}/{of}] {path.name} — already described (an older version: Describe again adds what it lacks)")
                    return "older"
                print(f"[{n}/{of}] {path.name} — already described")
                return "already"
        except (OSError, ValueError):
            pass
    ext = path.suffix.lower()
    t0 = time.time()
    src = proxy or path                     # cuts and sound from the proxy when there is one; stills from the original
    duration, has_audio, has_video, clock = (0.0, False, True, "") if ext in IMAGE else probe(src)
    if proxy:                               # the camera clock is in the original
        clock = probe(path)[3] or clock
    if ext not in IMAGE and ext not in AUDIO and not has_video and not has_audio:
        # ffprobe could not read it at all: not described, so it is tried again next time
        raise RuntimeError("could not be read (ffprobe found no picture and no sound)")
    thumbs = store / fp[:2] / fp
    thumbs.mkdir(parents=True, exist_ok=True)
    rec = {"version": VERSION, "fingerprint": fp, "file": str(path), "seen_at": [str(path)],
           "kind": "image" if ext in IMAGE else "audio" if ext in AUDIO else "video",
           "duration": round(duration, 2), "camera_clock": clock, "part_of_day": part_of_day(clock),
           "model": vision.name, "prompt": pid, "whisper": "", "analysed": "",
           "shots": [], "speech": None, "failed_shots": 0, "read_from": "proxy" if proxy else "original"}
    print(f"[{n}/{of}] {path.name}", flush=True)

    if ext in IMAGE or (ext not in AUDIO and has_video):
        cuts = [(0.0, 0.0)] if ext in IMAGE else shots_of(src, duration)
        pics = {"original": 0, "proxy": 0}
        with tempfile.TemporaryDirectory() as td:
            td = Path(td)
            for i, (a, b, take, k, of_take) in enumerate(moments(cuts)):
                frames = []
                if ext in IMAGE:
                    f = td / "f0.jpg"
                    subprocess.run([tool("ffmpeg"), "-nostdin", "-loglevel", "error", "-i", str(path),
                                    "-vf", "scale=768:-2", "-y", str(f)], check=False)
                    frames = [str(f)] if f.exists() else []
                else:
                    looks = sample_times(a, b) if not k else [max(a + (2.0 if a < 0.01 else 0.5), (a + b) / 2)]
                    for j, at in enumerate(looks):
                        # The still from the ORIGINAL: full quality for the model, and
                        # one seek reads only a few MB of it. The proxy is where the
                        # cuts were found and the sound is heard; its picture is only
                        # used when the original cannot give one (RAW, unreadable).
                        f = td / f"f{j}.jpg"
                        if frame(path, at, f):
                            pics["original"] += 1; frames.append(str(f))
                        elif proxy and frame(proxy, at, f):
                            pics["proxy"] += 1; frames.append(str(f))
                if not frames:
                    continue
                shutil.copyfile(frames[0], thumbs / f"{i:04d}.jpg")          # the picture search shows
                got, raw, ok = vision.describe(frames)
                if not ok:
                    rec["failed_shots"] += 1
                shot = {"shot": i, "start": round(a, 3), "end": round(b, 3), **got}
                shot["composition"] = compose_safe(vision, frames[0])      # the still search shows is the one measured
                if k:
                    shot.update(take=take, moment=k, of=of_take)          # one moment of a long take
                if raw:
                    shot["raw"] = raw
                rec["shots"].append(shot)
                say(file=path.name, n=n, of=of, shot=i + 1, shots=len(moments(cuts)),
                    per_shot=round((time.time() - t0) / (i + 1), 1), failed=rec["failed_shots"])
        rec["pictures_from"] = {k: v for k, v in pics.items() if v}     # which file each still came from
        for s in rec["shots"]:        # one title read by two frames, merged
            s["text_on_screen"] = merge_text([s.get("text_on_screen", [])])

    if whisper_model and (ext in AUDIO or has_audio):
        say(file=path.name, n=n, of=of, step="speech")
        try:
            rec["speech"] = transcribe(src, whisper_model)
            rec["whisper"] = whisper_model
        except Exception as e:
            rec["speech_failed"] = str(e)[:200]          # tried again next time, speech only
            print(f"    could not transcribe ({e})")

    rec["analysed"] = time.strftime("%Y-%m-%dT%H:%M:%S")
    tmp = out.with_suffix(".json.new")
    tmp.write_text(json.dumps(rec, indent=1, ensure_ascii=False))
    os.replace(tmp, out)
    per = (time.time() - t0) / max(len(rec["shots"]), 1)
    print(f"    {len(rec['shots'])} shots, {per:.1f}s each"
          + (f", {len(rec['speech']['segments'])} lines of speech ({rec['speech']['language']})" if rec["speech"] else "")
          + (f", {rec['failed_shots']} could not be read" if rec["failed_shots"] else ""), flush=True)
    return "done"


def compose_safe(vision, frame):
    """The composition of one still, or None: a composition that cannot be read never costs the description."""
    try:
        return vision.compose(frame)
    except Exception as e:
        print(f"    composition not read ({e})")
        return None


def compose_only(old, out, thumbs, vision, path, n, of):
    """Describe again on a file described with version 3: only its composition is added, from the stills
    already saved beside its description. The video is not opened and nothing described is changed."""
    shots = old.get("shots") or []
    print(f"[{n}/{of}] {path.name} — composition only ({len(shots)} shots, from the stills already saved)", flush=True)
    t0 = time.time()
    for k, s in enumerate(shots):
        still = thumbs / f"{s.get('shot', k):04d}.jpg"
        if still.exists():
            s["composition"] = compose_safe(vision, str(still))
        say(file=path.name, n=n, of=of, shot=k + 1, shots=len(shots), step="composition",
            per_shot=round((time.time() - t0) / (k + 1), 1), failed=0)
    old["version"] = VERSION
    tmp = out.with_suffix(".json.new")
    tmp.write_text(json.dumps(old, indent=1, ensure_ascii=False)); os.replace(tmp, out)
    return "done"


def proxy_of(path, archive, proxies):
    """The proxy made for an original, if there is one: same path under the
    proxies folder, as .mp4. Reading it is several times faster than reading
    4K MXF over the network, and describes the same pictures and sound."""
    if not archive or not proxies:
        return None
    p, a = str(path), str(archive).rstrip("/")
    if not p.startswith(a + "/"):
        return None
    cand = Path(proxies) / Path(p[len(a) + 1:]).with_suffix(".mp4")
    return cand if cand.exists() and cand.stat().st_size > 0 else None


def media_under(target):
    t = Path(target)
    if t.is_file():
        return [t]
    return sorted(p for p in t.rglob("*")
                  if p.suffix.lower() in VIDEO | IMAGE | AUDIO and not p.name.startswith(("._", "."))
                  and not any(s in str(p) + "/" for s in SKIP_PARTS))


def themes_from(url):
    """The theme list lives in rules.json, so it can be changed without code."""
    if url:
        try:
            with urllib.request.urlopen(url.rstrip("/") + "/rules.json", timeout=15) as r:
                t = (json.loads(r.read().decode()).get("analysis") or {}).get("themes")
                if t:
                    return [str(x) for x in t]
        except Exception:
            pass
    return DEFAULT_THEMES


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("target", nargs="?", help="a folder or one file")
    ap.add_argument("--store", help="where the descriptions go, e.g. /Volumes/VIDEO/_rushes/analysis")
    ap.add_argument("--url", default="", help="Rushes, for the theme list")
    ap.add_argument("--model", default=DEFAULT_MODEL)
    ap.add_argument("--whisper", default=DEFAULT_WHISPER, help="'' to skip speech")
    ap.add_argument("--force", action="store_true", help="describe again even if already done")
    ap.add_argument("--again", action="store_true", help="describe again the files described with an older version (Describe again)")
    ap.add_argument("--archive", default="", help="the archive root, to find each file's proxy")
    ap.add_argument("--proxies", default="", help="where the proxies are (same paths, as .mp4)")
    ap.add_argument("--selftest", action="store_true")
    a = ap.parse_args()
    if a.selftest:
        return selftest()
    if not a.target or not a.store:
        ap.error("a folder or file, and --store, are needed")
    files = media_under(a.target)
    if not files:
        print(f"nothing to describe under {a.target}")
        return 0
    themes = themes_from(a.url)
    store = Path(a.store); store.mkdir(parents=True, exist_ok=True)
    print(f"{len(files)} file(s) · model {a.model} · speech {a.whisper or 'off'} · question {prompt_id(themes)}")
    vision = Vision(a.model, themes)
    counts = {"done": 0, "already": 0, "older": 0, "speech": 0, "failed": 0}
    for n, f in enumerate(files, 1):
        try:
            counts[analyse(f, store, vision, a.whisper, themes, a.force, n, len(files),
                           proxy_of(f, a.archive, a.proxies), a.again)] += 1
        except Exception as e:                       # one bad file never stops the folder
            counts["failed"] += 1
            print(f"    could not describe {f.name}: {e}", flush=True)
    say(finished=True, **counts)
    print(f"\ndescribed {counts['done']}, already described {counts['already']}, could not {counts['failed']}"
          + (f", speech failed on {counts['speech']} (their pictures are described)" if counts["speech"] else "")
          + (f", {counts['older']} described with an older version (kept)" if counts["older"] else ""))
    return 0


def selftest():
    th = DEFAULT_THEMES
    assert parse_json('Sure! {description: "a", tags: ["x",],}') == {"description": "a", "tags": ["x"]}
    assert parse_json('"ARC", "ARC", "AR') is None
    r = tidy({"description": "Aerial", "text_on_screen": ["RIVERTON", "Riverton", "CENTRAL PARK"],
              "people": 0, "shot_size": "WIDE", "time_of_day": "day", "themes": ["parks & recreation", "Space"],
              "tags": ["Aerial", "riverton", "aerial", "water park"], "ages": ["adult", "elder"]}, th)
    assert r["text_on_screen"] == ["RIVERTON", "CENTRAL PARK"], r
    assert r["tags"] == ["aerial", "water park"], r
    assert r["people"] == "none" and r["shot_size"] == "wide" and r["light"] == "unclear"
    assert r["themes"] == ["Parks & Recreation"] and r["ages"] == ["adult"]
    assert [people_of(x) for x in (1, 2, 5, 40, "Crowd")] == ["one", "two", "few", "crowd", "crowd"]
    assert merge_text([["CENTRAL PAR", "CENTRAL PARK", "RIVERTON"]]) == ["CENTRAL PARK", "RIVERTON"]
    assert tidy({"text_on_screen": ["RIVERTON", "❤️", "I"]}, th)["text_on_screen"] == ["RIVERTON"]   # the pieces of a split line
    assert tidy({"text_on_screen": ["❤️"]}, th)["text_on_screen"] == ["❤️"]                    # unless that is all there is
    assert tidy({"themes": []}, th)["themes"] == []                                             # no theme is an answer
    assert whole_shots([(0, 5), (5, 5.2), (5.2, 9), (9, 9.5)]) == [(0, 5.2), (5.2, 9.5)]
    assert whole_shots([(0, 0.4), (0.4, 6)]) == [(0, 6)] and whole_shots([(0, 0.4)]) == [(0, 0.4)]
    assert part_of_day("2025-12-03T15:20:11.000000Z") == "afternoon" and part_of_day("") == ""
    assert part_of_day("2026-10-17T02:19:04.000000Z") == "night" and part_of_day("2026-10-17T00:15:05Z") == "night", "after midnight is night"
    m = moments([(0.0, 6.0), (6.0, 216.0), (216.0, 226.0)])
    assert m[0] == (0.0, 6.0, 0, 0, 1) and all(x[2] == 1 for x in m[1:43]) and m[-1][2] == 2, m
    assert len([x for x in m if x[2] == 1]) == 42 and m[1][0] == 6.0 and m[42][1] == 216.0, "a 3.5-minute take: a moment every 5 s"
    assert [x[4] for x in m if x[2] == 2] == [2, 2], "10 s: two moments" 
    with tempfile.TemporaryDirectory() as td:
        p = Path(td) / "a.mov"; p.write_bytes(b"x" * 100)
        q = Path(td) / "b.mov"; q.write_bytes(b"x" * 100)
        assert fingerprint(p) == fingerprint(q) and len(fingerprint(p)) == 24
        q.write_bytes(b"y" * 100)
        assert fingerprint(p) != fingerprint(q)
        (Path(td) / "_rushes").mkdir(); (Path(td) / "_rushes" / "c.mov").write_bytes(b"z")
        assert [x.name for x in media_under(td)] == ["a.mov", "b.mov"]
    assert prompt_id(th) == prompt_id(list(th)) and prompt_id(th) != prompt_id(th[:3])
    assert all(t >= 2.0 for t in sample_times(0.0, 10.0))          # never the start of a recording
    assert all(t >= 21.0 for t in sample_times(20.0, 30.0))        # never the first second of a shot
    assert sample_times(5.0, 6.0) == [5.5]                         # a short shot: its middle
    with tempfile.TemporaryDirectory() as td:
        arch, prox = Path(td) / "VIDEO", Path(td) / "VIDEO" / "PROXIES"
        (arch / "PARKS").mkdir(parents=True); (prox / "PARKS").mkdir(parents=True)
        (arch / "PARKS" / "A001.MXF").write_bytes(b"o"); (prox / "PARKS" / "A001.mp4").write_bytes(b"p")
        assert proxy_of(arch / "PARKS" / "A001.MXF", arch, prox) == prox / "PARKS" / "A001.mp4"
        assert proxy_of(arch / "PARKS" / "B.MXF", arch, prox) is None
    # the lens: the same tags as the Composition Lens page, on 20 stills marked by hand (768x432, frame fractions)
    B = lambda a, b, c, d: {"x0": a, "y0": b, "x1": c, "y1": d}
    P = lambda x, y: {"x": x, "y": y}
    H = lambda a, b, c, d: {"a": P(a, b), "b": P(c, d)}
    for m, want in [
        (dict(subject=B(.79, .30, .98, 1), eyes=P(.86, .39), facing="left", horizon=H(0, .55, .6, .55)), "golden_rectangle level_horizon rule_of_space"),
        (dict(subject=B(0, .24, .26, 1), lines=[H(.13, .12, .68, .42)]), ""),
        (dict(subject=B(.22, .24, .56, 1), eyes=P(.39, .42), horizon=H(.6, .5, 1, .5)), "golden_rectangle horizon_centered level_horizon"),
        (dict(subject=B(.53, .24, .62, .9), eyes=P(.565, .30), facing="left"), "rule_of_thirds rule_of_space"),
        (dict(subject=B(.49, .27, .76, .68), horizon=H(0, .6, 1, .6)), "golden_rectangle level_horizon"),
        (dict(subject=B(.42, .62, .58, .76), horizon=H(.12, .68, .55, .66)), "rule_of_thirds horizon_on_third centered level_horizon"),
        (dict(subject=B(.12, .36, .27, .84), eyes=P(.205, .39)), "golden_rectangle"),
        (dict(subject=B(.48, .20, .66, .85), horizon=H(0, .665, 1, .665)), "rule_of_thirds horizon_on_third level_horizon"),
        (dict(subject=B(.12, .20, .55, .95), eyes=P(.32, .47)), "rule_of_thirds"),
        (dict(subject=B(.13, .37, .27, .9), eyes=P(.18, .46), facing="right"), "rule_of_space"),
        (dict(subject=B(.13, .25, .42, .55), eyes=P(.35, .38)), "rule_of_thirds thirds_intersection diagonal_method"),
        (dict(subject=B(.6, .35, .8, .6)), ""),
        (dict(subject=B(.2, .05, .5, 1), eyes=P(.32, .16), facing="right"), "rule_of_thirds rule_of_space"),
        (dict(subject=B(.36, .25, .55, .75), eyes=P(.46, .37)), "rule_of_thirds golden_rectangle"),
        (dict(subject=B(.07, .15, .33, 1), eyes=P(.2, .27), facing="right"), "rule_of_space"),
        (dict(subject=B(.40, .20, .58, 1), eyes=P(.505, .29), facing="right"), "rule_of_thirds centered"),
        (dict(subject=B(.43, .43, .53, .60)), "centered"),
        (dict(subject=B(.42, .33, .58, .62), eyes=P(.45, .38), horizon=H(0, .64, 1, .64)), "rule_of_thirds horizon_on_third golden_rectangle centered level_horizon"),
        (dict(subject=B(.33, .12, .62, 1), eyes=P(.44, .25)), "centered diagonal_method"),
        ({}, "")]:
        assert sorted(lens(m, 768, 432)[0]) == sorted(want.split()), (m, lens(m, 768, 432))
    assert not {"dutch_angle", "level_horizon"} & set(lens({"horizon": H(0, .4, 1, .6)}, 768, 432)[0]), "a tilted line: no Dutch angle from the model"
    assert not {"rule_of_space", "short_sided"} & set(lens({"subject": B(.418, .2, .593, 1), "facing": "right"}, 768, 432)[0]), "centred: no space tag"
    assert _anchors_from({"subject": {"what": "a few words", "box": [0, 0, 500, 500]}})["what"] == ""
    assert "{{" not in COMPOSE and "judged" not in COMPOSE, "the composition question is sent as written"
    assert "negative_space" in lens({"subject": B(.1, .1, .2, .3)}, 768, 432, plain=.9)[0]
    assert "negative_space" not in lens({"subject": B(.1, .1, .2, .3)}, 768, 432, plain=.5)[0], "a busy frame is not negative space"
    m = _anchors_from({"subject": {"what": "a kite flyer", "box": [490, 270, 760, 680]}, "eyes": "no", "facing": "Left",
                       "horizon": [[0, 600], [1000, 600]], "lines": [[[1, 2]], "x"], "judged": ["silhouette"]})
    assert m["subject"]["x0"] == .49 and "eyes" not in m and m["facing"] == "left" and "lines" not in m and m["what"] == "a kite flyer"
    # speech that failed is tried again, speech only; the picture's description is kept
    global transcribe, say
    real, real_say = transcribe, say
    try:
        say = lambda **k: None
        with tempfile.TemporaryDirectory() as td:
            f = Path(td) / "a.mov"; f.write_bytes(b"x" * 100); store = Path(td) / "store"
            fp = fingerprint(f); out = store / fp[:2] / f"{fp}.json"; out.parent.mkdir(parents=True)
            vis = type("V", (), {"name": "m"})()
            out.write_text(json.dumps({"version": 2, "prompt": prompt_id(th), "model": "m", "whisper": "", "seen_at": [str(f)],
                                       "camera_clock": "2026-10-17T02:19:04Z", "part_of_day": "afternoon"}))
            assert analyse(f, store, vis, "", th, False, 1, 1) == "older", "described before moments in long takes: kept, said older"
            assert json.loads(out.read_text())["part_of_day"] == "night", "its part of day put right from its clock, nothing described again"
            out.write_text(json.dumps({"version": VERSION, "prompt": prompt_id(th), "model": "m", "whisper": "", "seen_at": [str(f)],
                                       "shots": [{"description": "kept"}], "speech_failed": "boom"}))
            transcribe = lambda p, m: {"language": "en", "segments": [{"start": 0, "end": 1, "text": "hi"}]}
            assert analyse(f, store, vis, "w", th, False, 1, 1) == "done"
            r = json.loads(out.read_text())
            assert r["speech"]["segments"][0]["text"] == "hi" and "speech_failed" not in r and r["shots"][0]["description"] == "kept"
            assert analyse(f, store, vis, "w", th, False, 1, 1) == "already"            # nothing left to do
            def boom(p, m): raise RuntimeError("no")
            transcribe = boom
            assert analyse(f, store, vis, "w2", th, False, 1, 1) == "speech"           # new speech model, failed: described, speech said apart
            assert json.loads(out.read_text())["speech_failed"] == "no"
            # version 3, Describe again: only the composition is added, from the stills already saved
            out.write_text(json.dumps({"version": 3, "prompt": prompt_id(th), "model": "m", "whisper": "", "seen_at": [str(f)],
                                       "shots": [{"shot": 0, "description": "kept"}, {"shot": 1, "description": "no still"}]}))
            (store / fp[:2] / fp).mkdir(); (store / fp[:2] / fp / "0000.jpg").write_bytes(b"jpg")
            vis.compose = lambda fr: {"tags": ["centered"], "seen": Path(fr).name}
            assert analyse(f, store, vis, "", th, False, 1, 1) == "older", "not asked: kept, said older"
            assert analyse(f, store, vis, "", th, False, 1, 1, again=True) == "done"
            r = json.loads(out.read_text())
            assert r["version"] == VERSION and r["shots"][0] == {"shot": 0, "description": "kept", "composition": {"tags": ["centered"], "seen": "0000.jpg"}}
            assert "composition" not in r["shots"][1], r
            assert analyse(f, store, vis, "", th, False, 1, 1, again=True) == "already"
    finally:
        transcribe, say = real, real_say
    print("analyze: all checks pass")
    return 0


if __name__ == "__main__":
    sys.exit(main())
