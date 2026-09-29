# Rushes roadmap

What is proven, what is not, and the order Rushes is being finished in.
Updated as things move; the newest decisions are at the top of each section.

## Where Rushes runs

One Rushes, the same pages, catalogue, rules, jobs and helper, packaged three ways:

| | A · Mac | B · Server | C · Windows |
|---|---|---|---|
| Who it is for | one editor, or a small team around one Mac | a team, on a NAS or Linux server | a Windows PC or Windows Server |
| Serves the pages | PHP bundled inside the app | PHP inside a container (QNAP, Synology, Unraid, TrueNAS, Linux) | PHP bundled in the installer |
| Runs the jobs | a background service | inside the container | a Windows service |
| The helper | built in | built in, plus Rushes Helper on workstations | built in, plus Rushes Helper on workstations |
| What a person allows | Full Disk Access, Local Network | which media share it may use | firewall, drive access |

Today Rushes runs on a NAS by hand, with Rushes Helper on a Mac. The three
packages come after the working loop below is finished, so each is built once,
with everything the final stage needs inside it.

## Status

### Proven in real use

- Search, and the search catalogue rebuilding itself after changes.
- Transfers: copies with a permanent where-it-came-from record, resume after any
  stop, Pause, and a live status that reports what is actually happening.
- Rushes Helper for Mac: a self-contained app (its own Python), set up in plain
  windows, runs in the background, updates itself from Rushes.
- Manage: Overview, activity, cache and duplicate review.
- The runner, and approving updated scripts before they run.

### Built, not yet proven on real data

- Describing footage: Manage → Jobs and tools → Describe footage queues a folder;
  the helper runs the vision model and speech on it, one file at a time after any
  copies, with live progress; one description per file in `_rushes/analysis`,
  named by content so it survives moves; search shows matching shots and spoken
  lines with their time and picture ("In the footage"). Themes in `rules.json`.
- The Rushes address for any installation: Setup fills it in from the address
  the page was opened at, warns when it is a number that can change and offers
  the machine's name when that works, and Rushes Helper learns every address
  Rushes has and follows it to a new one by itself, taking its saved progress along.
- Tidy-up: moving copied footage onto the organised shelf, recorded and undoable.
- Skip and "Try again now" during a real failure.
- Network shares that drop: the helper connects them again by itself (every 2
  minutes, then every 15), and each drop shows in Activity with how long it lasted.
- Matching earlier copies to their originals: keeps the originals it has
  listed folder by folder, so a stop part-way costs only the folder it was in;
  the page shows step 1 of 2 and 2 of 2 and how much is left.
- Transfer history: patched to show copied and already-present for older
  folders; not yet one consistent history.

### Not built yet

- Premiere relinking after tidy-up.
- Proxies (the plan can be run from Jobs and tools; building them is next).
- Faces.
- The analysis tools in the installer (today they are the pilot's, on the Mac).
- Rushes Helper for Windows.

## Vision model: notes from the pilot

The pilot (Qwen3-VL 8B, 4-bit, through MLX on the Mac; PySceneDetect cutting each
file into shots; two frames per shot at 768 px; about 6 s per shot) passed on
description quality. What it taught, to build in:

- **Never lose a shot to bad JSON.** 7 of 276 shots came back unparseable. Six
  were near-JSON with unquoted keys and are recovered from the saved raw text
  without re-running the model. One was a repetition loop (the same word over
  and over until cut off): cap list lengths, penalise repetition, retry the shot
  once, and flag it if it still fails. The raw answer is always kept.
- **On-screen text is evidence, tags are opinion.** Kept apart on purpose. Merge
  what several frames read (a title caught mid-animation reads short), drop
  duplicates, and keep tags from repeating the on-screen text.
- **Themes: a fixed list, not free words.** Each shot gets one to three themes
  from a list kept in `rules.json` (Events, Sports, Education, Parks & Recreation,
  Public Safety, …), so filtering is consistent and anyone can edit the list.
- **Shot size and people already come back.** Shot size gains *full* (head to
  toe) between wide and medium; people become none / one / a few / a crowd,
  since an exact count from a drone is a guess.
- **People filters:** count as no people / 1 / 2 / 3+ / large group; age as
  broad bands (child, teen, adult, senior), which a model estimates reasonably,
  never finer. Relationships (couples, families) are not guessed.
- **Faces, after descriptions work:** find faces, group the same face across
  the archive, and ask once "this person appears in 312 shots — who is it?".
  Only the people an organisation chooses are named, and become searchable by name; everyone
  else stays an unnamed group that still answers "other shots of this person". The face model must be free for any use:
  dlib's face recognition model (public domain) or OpenCV's YuNet detector with
  SFace (MIT / Apache-2.0), not InsightFace, whose trained models are for
  non-commercial research only.
- **Time of day from two places:** the camera's own clock (in the file) says
  morning, afternoon or evening; the model says what the light looks like
  (sunrise or sunset light, daylight, dusk, night).
- **Never the first frames.** A camera is still settling when it starts
  (moving, out of focus, exposure hunting). Frames are taken after the first
  second of a shot (two at the start of a recording) and before its last half
  second; a very short shot gets one look in its middle.
- **Stills use the same pipeline:** a photo is one shot, so descriptions, text,
  themes and faces work on images too, which is where faces work best.

### Speech: Whisper

No pilot needed: it goes straight into the analysis build. Every file with speech gets a timecoded transcript, in the
language that was spoken (Spanish and English alike; the model detects which),
kept verbatim like on-screen text: evidence, not description. It is how names,
streets and topics become searchable, since nobody says "medium shot of a man"
out loud. Voice detection runs first so music-only and silent files are skipped.
Same rules as the vision model: part of the installer, a setting not code
(default: Whisper large-v3-turbo, through MLX on a Mac, faster-whisper on NVIDIA),
and each transcript records which model made it.

### Models are swappable, not built in

- **Version 1:** which model to use is a setting, never code. What the model is
  asked (the prompt) and what it must answer (the fields) are fixed, so any
  model that answers in those fields fits. Every result records which model and
  which prompt made it, so an archive can hold results from two models and a
  re-run replaces only what is asked. The installer checks the machine and
  offers the few models that suit it, with size and speed. The default is
  Qwen3-VL 8B at 4-bit: the pilot showed it is good enough, and swapping is
  there for the future, not because it needs replacing.
- **Version 2:** try another model on a sample (say 20 shots) and see its
  answers beside the current ones before switching; add any compatible model by
  name; re-describe the archive, or only new footage, with the new one.
- **Faces are different:** a new face model means re-reading every face, since
  its fingerprints do not match the old one's. Names are kept and carried over
  to the new groups.

## Order

1. **Finish the loop on a real archive.** The copy completes, then tidy-up,
   relinking, proxies and the vision model. Real use finds what tests do not.
2. **Along the way: a portable core.** Whenever the runner or a path is touched,
   make it portable: the runner's work moves into Python (which travels with
   every package), every path comes from settings, and updates arrive as a
   normal update instead of a deploy folder. Nothing changes for a running
   installation.
3. **Package A · Mac, then B · Server, then C · Windows**, once proxies and the
   vision model work, so ffmpeg and the model runtime go into each package once.
   The installer carries the whole analysis stage, not just the app: ffmpeg for
   proxies, shot detection, the vision model's runtime, the face model, the
   theme list. It checks the machine first (Apple chip, NVIDIA card, or neither)
   and picks what runs there; the large model files download inside the
   installer with progress shown, and can be added later. Nobody installs any
   of it by hand.

