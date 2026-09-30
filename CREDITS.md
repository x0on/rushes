# What Rushes is made of

Rushes is by Alejandro Renteria, open source at https://github.com/x0on/rushes.
It stands on other people's work, listed here with what each part does in Rushes,
where it runs, and its license. Each belongs to its authors; Rushes claims none of it.

## Rushes itself

| Part | What it does in Rushes | Where it runs | License | Where it comes from |
|---|---|---|---|---|
| Rushes | Copying footage: Rushes' own code reads each file and writes it in 8 MB pieces with Python's standard library — no copy tool such as rsync or rclone — to a temporary name, checks the size, then puts it in place. Recognising files already in the archive: a BLAKE2 fingerprint of each file's start and end (Python's standard library). Also matching earlier copies to their originals, tidy-up, search, the pages | Archive machine and the helper | Open source | github.com/x0on/rushes — written with AI coding assistants (Claude by Anthropic, Codex by OpenAI) |

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
