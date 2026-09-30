#!/usr/bin/env python3
# Rushes — Media Management Software, by Alejandro Renteria.
# Open source: https://github.com/x0on/rushes — whoever finds this file on a computer can see what it is and who made it.
"""analyze.py — describe footage so it can be found by what it shows and says.

    analyze.py FOLDER_OR_FILE --store /Volumes/VIDEO/_rushes/analysis [--url RUSHES]

For every video, photo or audio file under the folder:
  1. cut it into shots (PySceneDetect), and sample two frames of each shot;
  2. ask the vision model what each shot shows: a sentence, text on screen,
     shot size, people, age bands, setting, light, mood, themes, tags;
  3. write down what is said in it (Whisper), in the language it was said;
  4. keep one small picture per shot, for the search results.

Everything is written to one JSON file per media file, in --store, named by the
file's content fingerprint (not its path), so it survives the file being moved
or renamed by a tidy-up. The search index is rebuilt from these files; they are
the record, the database is only a view of them.

Which models run is a setting (--model, --whisper), never code: the question
and the answer fields are fixed, and every result says which model and which
question produced it. Resumable: a file already described with the same model
and question is skipped.

Progress goes to stdout as lines starting "@@ " followed by JSON, which the
helper turns into the live status in Rushes. Everything else is plain log.

Runs in the analysis Python (mlx-vlm, mlx-whisper, scenedetect installed), not
the helper's own; the helper starts it. --selftest checks the parts that need
no model.
"""
import argparse, hashlib, json, os, re, shutil, subprocess, sys, tempfile, time, urllib.request
from pathlib import Path

VERSION = 2
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
                  signs, banners, jerseys, dates), copied verbatim, each once. [] if none.
  shot_size       one of: {sizes}
  people          one of: none, one, two, few, crowd
  ages            array, any of: {ages} — only for people clearly visible, [] if none
  setting         one of: indoor, outdoor, mixed
  light           one of: {light}
  mood            one or two words, e.g. celebratory, tense, routine, solemn, energetic
  themes          array of 1 to 3, chosen ONLY from: {themes}
  tags            array of 3-8 short lowercase words for objects, actions and places you
                  see — not the on-screen text again

Be literal. Describe only what you can see. Do not guess who people are or where this is
unless it is written in the frame. "full" means a person seen head to toe.
If the frame looks like two images blended together, it is a dissolve: say so and
describe the clearer one."""


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
    for d in ("/opt/homebrew/bin", "/usr/local/bin"):
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
    return "morning" if 5 <= h < 12 else "afternoon" if h < 17 else "evening" if h < 21 else "night"


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
    return [(s.get_seconds(), e.get_seconds()) for s, e in found]


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

    def ask(self, frames, extra=""):
        from mlx_vlm import generate
        from mlx_vlm.prompt_utils import apply_chat_template
        p = apply_chat_template(self.processor, self.config, prompt_text(self.themes) + extra,
                                num_images=len(frames))
        try:   # a repetition penalty stops the model looping on one word
            out = generate(self.model, self.processor, p, frames, max_tokens=420,
                           repetition_penalty=1.15, verbose=False)
        except TypeError:
            out = generate(self.model, self.processor, p, frames, max_tokens=420, verbose=False)
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
def analyse(path, store, vision, whisper_model, themes, force, n, of, proxy=None):
    fp = fingerprint(path)                  # always the original: that is what search finds
    out = store / fp[:2] / f"{fp}.json"
    pid = prompt_id(themes)
    if out.exists() and not force:
        try:
            old = json.loads(out.read_text())
            if old.get("prompt") == pid and old.get("model") == vision.name:
                if str(path) not in old.get("seen_at", []):          # the same footage, somewhere else too
                    old["seen_at"] = sorted(set(old.get("seen_at", [])) | {str(path)})
                    out.write_text(json.dumps(old, indent=1, ensure_ascii=False))
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
            for i, (a, b) in enumerate(cuts):
                frames = []
                if ext in IMAGE:
                    f = td / "f0.jpg"
                    subprocess.run([tool("ffmpeg"), "-nostdin", "-loglevel", "error", "-i", str(path),
                                    "-vf", "scale=768:-2", "-y", str(f)], check=False)
                    frames = [str(f)] if f.exists() else []
                else:
                    for k, at in enumerate(sample_times(a, b)):
                        # The still from the ORIGINAL: full quality for the model, and
                        # one seek reads only a few MB of it. The proxy is where the
                        # cuts were found and the sound is heard; its picture is only
                        # used when the original cannot give one (RAW, unreadable).
                        f = td / f"f{k}.jpg"
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
                if raw:
                    shot["raw"] = raw
                rec["shots"].append(shot)
                say(file=path.name, n=n, of=of, shot=i + 1, shots=len(cuts),
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
    counts = {"done": 0, "already": 0, "failed": 0}
    for n, f in enumerate(files, 1):
        try:
            counts[analyse(f, store, vision, a.whisper, themes, a.force, n, len(files),
                           proxy_of(f, a.archive, a.proxies))] += 1
        except Exception as e:                       # one bad file never stops the folder
            counts["failed"] += 1
            print(f"    could not describe {f.name}: {e}", flush=True)
    say(finished=True, **counts)
    print(f"\ndescribed {counts['done']}, already described {counts['already']}, could not {counts['failed']}")
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
    assert part_of_day("2025-12-03T15:20:11.000000Z") == "afternoon" and part_of_day("") == ""
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
    print("analyze: all checks pass")
    return 0


if __name__ == "__main__":
    sys.exit(main())
