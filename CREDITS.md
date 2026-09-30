# What Rushes is made of

Rushes is by Alejandro Renteria, open source at https://github.com/x0on/rushes.
It stands on other people's work, listed here with what each part does in Rushes,
where it runs, and its license. Each belongs to its authors; Rushes claims none of it.

## Rushes itself

| Part | What it does in Rushes | Where it runs | License | Where it comes from |
|---|---|---|---|---|
| Rushes | Copying footage with its own code (below, why), each copy verified byte for byte by BLAKE2 fingerprint; matching earlier copies to their originals, tidy-up, search, the pages | Archive machine and the helper | Open source | github.com/x0on/rushes — written with AI coding assistants (Claude by Anthropic, Codex by OpenAI) |

### Why Rushes copies with its own code, not rsync or rclone

The copying itself is a few lines: read 8 MB, write 8 MB, with Python's standard
library. What makes it Rushes is what sits around those lines, which copy tools
such as rsync and rclone do not do:

- **It skips what is already anywhere in the archive.** rsync and rclone only
  compare against the same path at the destination. Rushes recognises a file
  even when an earlier copy sits in another folder, under another structure, so
  nothing is copied twice.
- **A permanent where-it-came-from record for every file**, which tidy-up, its
  undo and Premiere relinking all depend on.
- **Live progress in Rushes, Pause at a safe point, and resuming exactly where it
  stopped** after a network drive drops.
- **Search is updated file by file**, so a new file can be found as soon as it lands.

How each copy is made safe:

1. Written under a temporary name, so an interrupted copy never looks finished.
2. Confirmed as stored on the archive's disk (fsync) before it gets its real name.
3. Verified byte for byte: the original's BLAKE2 fingerprint is taken while it is
   read, the copy is read back from the archive and fingerprinted again, and the
   two must match. A copy that does not match is thrown away and made again.
4. The fingerprint is kept in the file's where-it-came-from record, so the
   archive copy can be proven to be the original years later.
5. An original that changes while it is being read (a camera or another copy
   still writing it) is not kept; it is copied on a later run, once it has settled.
6. Nothing half-copied is ever left behind, whatever stopped the copy.
7. A full archive stops the copy once, with that reason, instead of failing
   every file one by one; it carries on from the same place when there is room.
8. A name with accents ("Día") can be stored two ways that look identical; both
   count as the same name, so an earlier copy is recognised instead of copied again.

Ideas, not code, taken from rsync and rclone, whose years of use found these
problems first: rsync checks every transferred file against a whole-file
checksum (point 3); rclone aborts a file that changes while it is read (5) and
treats the two ways of storing accented names as one (8).

rsync and rclone have years of real-world use behind them; this code has less.
Its tests (tests/ in the repository) cover interrupted copies, dropped drives,
damaged copies and resuming, and every file it copies is checked as above.

## On this Mac: Rushes Helper

| Part | What it does in Rushes | Where it runs | License | Where it comes from |
|---|---|---|---|---|
| Python 3.12 | The language the helper is written in; a private copy inside the app | Inside Rushes Helper | PSF License | python.org |
| python-build-standalone | The ready-to-ship build of that Python | Inside Rushes Helper | MPL-2.0 | github.com/astral-sh/python-build-standalone (Astral) |
| macOS | Its file sharing (SMB) is how the helper reads the source drives and writes to the archive over the network; launchctl runs the background service; ditto copies the app into Applications; osascript connects a dropped network drive; open | Part of macOS | Apple | Apple |

## On this Mac: describing footage

| Part | What it does in Rushes | Where it runs | License | Where it comes from |
|---|---|---|---|---|
| Qwen3-VL 8B Instruct | Describes what each shot shows, reads text on screen | This Mac (4-bit MLX version) | Apache-2.0 | Qwen team, Alibaba Cloud — huggingface.co/Qwen; 4-bit conversion by the MLX Community |
| Whisper large-v3-turbo | Writes down what is said, in the language it was said | This Mac | MIT | OpenAI — github.com/openai/whisper |
| MLX | Runs the models on Apple chips | This Mac | MIT | Apple — github.com/ml-explore/mlx |
| mlx-vlm | Runs the vision model with MLX | This Mac | MIT | Prince Canuma — github.com/Blaizzy/mlx-vlm |
| mlx-whisper | Runs Whisper with MLX | This Mac | MIT | Apple — github.com/ml-explore/mlx-examples |
| PySceneDetect | Cuts footage into shots | This Mac | BSD-3-Clause | Brandon Castellano — github.com/Breakthrough/PySceneDetect |
| FFmpeg | Reads frames and sound from video | This Mac | LGPL-2.1+ (some builds GPL) | ffmpeg.org |

## On the archive machine

| Part | What it does in Rushes | Where it runs | License | Where it comes from |
|---|---|---|---|---|
| PHP | Serves the Rushes pages | Archive machine | PHP License 3.01 | php.net |
| SQLite | The search catalogue, the media ledger, transfers | Archive machine and the helper | Public domain | sqlite.org |
| FFmpeg | Makes the 1080p proxies | Archive machine | LGPL-2.1+ (some builds GPL) | ffmpeg.org |
| Czkawka | Finds duplicate files | Archive machine (its container) | MIT | Rafał Mikrut — github.com/qarmin/czkawka |
| BusyBox and the NAS system | The shell the scheduled jobs run in | Archive machine | GPL-2.0 (part of the NAS system) | busybox.net |

## Building the app (used to make it, not inside it)

| Part | What it does in Rushes | Where it runs | License | Where it comes from |
|---|---|---|---|---|
| Zig | Compiles the app's small launcher for both Mac chips | The build machine | MIT | ziglang.org |
| apple-codesign (rcodesign) | Signs the app | The build machine | MPL-2.0 | Gregory Szorc — github.com/indygreg/apple-platform-rs |
