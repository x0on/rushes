# Rushes roadmap

What is proven, what is not, what is wrong today, and the order Rushes is being
finished in. What Rushes does now is in [HOW-IT-WORKS.md](HOW-IT-WORKS.md).

## Status

### Proven in real use

- Search, and the catalogue keeping itself up to date.
- Transfers from an old server: copies with a permanent record, resuming after
  any stop, Pause, and a live status.
- Rushes Helper for Mac: self-contained (its own Python), set up in plain
  windows, runs in the background.
- Manage: Overview, Activity, cache and duplicate review.
- The runner, and approving updated scripts before they run.

### Built, not yet proven on real data

- Describing footage (pictures and speech), and finding shots and spoken lines
  in search.
- Proxies on the video chip, the proxy settings test, Remake proxies.
- Preparing folders: proxies first, then describing, by themselves.
- The media ledger (resolution, frame rate, camera, timecode, reel).
- Copy proof in ASC MHL, checking older copies, counting copies, re-reading the
  archive.
- Tidy-up into departments, and pointing Premiere projects, and FCPXML or XML
  from Final Cut and Resolve, at the new places.
- Pulls with descriptions as Premiere markers, and as FCPXML for Final Cut and
  Resolve (not yet opened in either).
- Following Rushes to a new address, and reconnecting dropped shares.
- The hardening of autumn 2026:
  - the six rules;
  - approving pages as well as scripts;
  - pairing;
  - the daily database copy;
  - the private-file check;
  - asking on the button instead of in pop-ups.

### Not built yet

- Playing proxies in search, and jumping to a described moment.
- Faces (see [Decided](#decided-and-why)).
- The describing tools in an installer (today they are set up by hand on the Mac).
- Rushes Helper for Windows.
- Live support for organisations with a commercial license (see
  [Decided](#decided-and-why)).

## Known problems

Things Rushes does today that are not right yet. Each one is described where it
happens in HOW-IT-WORKS.

**Security**

- Plain http, one shared password, no user accounts. Rushes is for an office
  network only. Requests from other websites are refused, but a site that points
  its own name at your Rushes (DNS rebinding) is not.
- Without a password, anyone on the network can change and download pulls
  (including a zip of the files), and ingest a card. While no helper is paired,
  they can also press the helper's switches.
- Settings and status files in the web folder can be read by anyone who can open
  Rushes. They hold paths and progress, not passwords.
- Anyone who can write to the web share can change the runner (root). This is
  covered in INSTALL.md, but not enforced.
- The Terminal install command comes from the Rushes server over plain http, so
  its own check of the app can be changed on the way.
- The Rushes Helper app does not update itself. Its signing certificate is the
  author's own, not one Apple issued, so macOS asks once before opening it.
- The pairing ID is a plain-text secret in the web folder, protected only by
  being a `.php` file that prints nothing.

**Paths and portability**

- The runner looks for Docker where a QNAP keeps it, among other places, and
  INSTALL.md describes a QNAP's scheduler. The archive's path may not contain
  spaces (the runner refuses it and uses the QNAP's).
- Settings that nothing reads yet: `organise.shape`, `holding.*`,
  `helper.poll_seconds`, and parts of `rules.json` (`structure`, most of
  `duplicates`, two conditions). The list of video types for proxies is written in two places
  instead of coming from `rules.json`.

**Doing the work**

- A copy or a check that hangs in the middle of a file cannot be cut short
  (nothing can interrupt a stuck read). It is said after 2 minutes and stops
  the helper after 10, but the stuck file stays stuck until the drive is
  reconnected or the computer restarted.
- Card ingests do not show a transfer percentage, only the live status.
- `ingest.py --undo` covers only the last run, and is not reachable from the
  pages.
- The media ledger's source (`proxy-made.tsv`) grows by one line per proxy,
  for ever: about 200 bytes each.

**Describing**

- Photos get no camera clock and no time of day.
- Speech is transcribed again for a new speech model only in files that had
  speech before; a file that had none is not listened to again.

## Risks

What could go wrong, what was decided, and where it stands.

| Risk | Decided | Status |
|---|---|---|
| A disk stalls, and Rushes' own repeating work piles up on it | The six rules (HOW-IT-WORKS → What runs by itself) | Done |
| Two helpers at once copy over each other | Pairing | Done |
| Anyone who can write to the archive share publishes a page | Pages wait for approval, like scripts | Done |
| Anyone who can write to the archive share, or the network, changes the helper's code | Signed releases (`release.py`); the built-in helper runs only a checked copy | Done |
| Another website makes a browser press Rushes' buttons | Requests from other websites refused | Done |
| No backup of the footage (RAID is not a backup) | A second machine with a one-way copy that keeps versions, mounted by nobody; Rushes shows when it last ran and counts it as a copy | When a second machine is available |
| Ransomware encrypts every share a computer has mounted | Snapshots; few accounts with write access; a backup with versions; the NAS never on the internet | Depends on the installation (INSTALL.md) |
| A mistaken delete or move in Finder | The archive read-only for people; projects on a separate share; recycle bin on | Depends on the installation |
| Running out of space: logs and records grow | Rotate logs, show growth, warn early | Mostly done: logs trimmed; growth not shown yet |
| A system update removes the runner from the schedule | Overview says "not picking up jobs"; INSTALL.md says how to restore it | Done |
| The database is damaged; pulls exist only there | A checked copy every day, a week kept | Done |
| The helper's computer holds its own notes and the signing key | Keep a private copy of the key | To do |
| A system update or security software blocks the helper | It says "blocked" plainly; a signed, notarized app goes through IT once | Partly done |
| Model or Python packages change and describing breaks | Pin versions and keep model files locally, in the installer | With the installer |
| The vision model invents things and someone trusts them | Label descriptions as machine-made; show how sure it was | Partly done |
| Footage and descriptions are public records, or show people who did not agree | Ask whoever looks after records before sharing descriptions widely | For each installation |
| Default password, plain http, anyone on the network can open Manage | A real password; local network only; private files checked daily | Partly done |
| Live support becomes a way into someone's computer | Paid license only; off and not installed by default; the person at the computer opens each session, sees it the whole time, ends it with one button; every session logged | To build |
| Only one person knows how it fits together | HOW-IT-WORKS, INSTALL, DEVELOPING | Done |
| The archive machine ages out of security updates | Plan its replacement; keep the archive portable (the packages below) | To plan |

## Version 1: what makes it a product for filmmakers

In this order of importance:

1. **A simple install, and updates you can trust.** One installer that checks
   the machine and sets everything up, with no Terminal. Updates are signed,
   say what changed, and can be rolled back.
2. **No lock-in.** Everything Rushes knows (descriptions, records, copy proofs)
   can be exported in open formats: CSV, JSON, XMP, ASC MHL.
3. **Round trip with every editor:** Premiere, DaVinci Resolve and Final Cut Pro
   (FCPXML). Pulls, markers and relinking for all three. *Built for all three;
   to be tried in Final Cut and Resolve.*
4. **Card offload you can trust:** a clear "verified — safe to format this
   card", and copying to two places at once.

Later versions:

- camera card structures and RAW as one clip;
- watching in search;
- rights and releases;
- selects, ratings and notes;
- teams, roles and safe remote access;
- long-term storage (LTO, cold cloud storage).

## Where Rushes will run

One Rushes, packaged three ways:

| | Mac | Server | Windows |
|---|---|---|---|
| For | one editor or a small team | a team, on a NAS or Linux server | a Windows PC or server |
| Pages | bundled in the app | in a container | bundled in the installer |
| Jobs | a background service | in the container | a Windows service |
| The helper | built in | built in, plus Rushes Helper on workstations | built in, plus Rushes Helper on workstations |

## Order

1. **Finish the loop on a real archive:** the copy, then tidy-up, relinking,
   proxies and describing.
2. **A portable core, along the way.** Whenever the runner or a path is
   touched, make it portable: the runner's work moves into Python, every path
   comes from settings, and updates arrive as normal signed updates instead of a
   drop folder.
3. **The packages: Mac, then server, then Windows.** The installer carries the
   whole describing stage (ffmpeg, shot detection, the models). It checks the
   machine first and shows progress while the models download. On managed
   computers nothing is a loose script: the app is signed and notarized, so IT
   approves one publisher once.

## Decided, and why

- **Help is in the open; live support is paid, and never unasked.** For
  everyone, help is a GitHub issue with a diagnostics file the person reads
  first, and the free version has no remote access at all. Organisations with a
  commercial license get live support: the Rushes team can connect to fix a
  problem, but only through Rushes Helper, only when the person at the computer
  opens a session, shown on screen the whole time, ended with one button, and
  logged. It is off, and not even installed, unless the organisation turns it
  on. Rushes never reports home, so nothing about it is used to check licenses.
- **Copy proof lives beside the footage,** in ASC MHL, the film industry's
  format, so other tools can check it. Records are never deleted, only moved
  along with the footage.
- **Models are a setting, not code.** The question and the answer fields are
  fixed, every result records which model made it, and any model that answers
  in those fields fits. The default (Qwen3-VL 8B, 4-bit) passed the pilot.
- **People:** only counted, with broad age bands. Relationships are never
  guessed.
- **Faces** (not built): group the same face across the archive. A person names
  a group, and each organisation decides whose names it records. The face
  model must be free for any use (dlib, or OpenCV's YuNet with SFace), not
  InsightFace, whose models are for non-commercial research only.
- **A card is copied whole,** even when some of its files are already in the
  archive. A shoot's folder should hold the whole card, as the camera wrote it:
  an editor opening it expects every clip, and camera software expects the
  card's structure. Duplicates finds the copies later.
- **Never the first frames of a shot:** a camera is still settling when it
  starts.
- **Ideas taken from established software** (their licenses stay theirs; see
  [CREDITS.md](CREDITS.md)):
  - Jellyfin: older Intel chips and the i965 driver;
  - ASC MHL: copy proof;
  - restic and Borg: re-reading the archive;
  - git-annex: counting copies;
  - rsync and rclone: safe copying;
  - OpenTimelineIO: relinking, and pulls as timelines later;
  - Immich: background jobs.
