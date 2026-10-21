# Handoff

For anyone (person or AI) picking up Rushes. Read this first. It points to the
other documents instead of repeating them. The private details of the first
installation (its drives, server and people) are kept outside this repository
by the owner and are given to you separately. They never go in here.

## What Rushes is for

An archive for a video team's footage that one person can run without a
terminal. It brings footage in with proof that nothing was lost, catalogues
it, makes small previews, describes every shot (pictures, and later speech),
so it can be searched. It then tidies the archive into departments, years and
shoots, and points editors' projects at the new places. It is honest about
what it is doing at every moment.

Today it is built **Mac app first**: one app on one Mac, with the archive on any
drive that Mac can see (a share, an external drive). The first installation's
NAS lost its RAID in October 2026, which is why. A server package and Windows
come later ([ROADMAP.md → Order](ROADMAP.md#order)).

## Where to read

| Document | What it holds |
|---|---|
| [README.md](README.md) | What Rushes is, in a page |
| [HOW-IT-WORKS.md](HOW-IT-WORKS.md) | What every part does today, in detail |
| [ROADMAP.md](ROADMAP.md) | Proven vs built vs not built, known problems, the order, what was set aside, what was decided and why |
| [DEVELOPING.md](DEVELOPING.md) | Where the code is, the six rules, tests, signing, building, releasing |
| [CHANGES.md](CHANGES.md) | Every version, newest first |
| [INSTALL.md](INSTALL.md) | Installing, for people |

## Where things stand (0.13.26, October 2026)

- The Mac app is in daily use on the first installation's real archive (tens of
  terabytes on an office share, plus external drives).
- **The goal right now is previews and descriptions running unattended**, folder
  by folder, and showing honestly what is running. **No new features until that
  is reliable.**
- Done on real footage: whole departments previewed and described. Long stalls
  and crash loops on single files are fixed (stderr to a file, a stall timer,
  a software fallback when the video chip stalls, a guard around each file).
  Files that truly cannot be read are listed with Show in Finder and Remove.
- **One list of what is happening** (`happening` in `app/db/state.php`). It feeds
  the top bar, Overview, Activity, a drive's Health box and the menu bar. Never
  add a second source of "is something running": that was the cause of the
  worst bug of October ("nothing running" while previews ran).
- The interface is **drive-centric**. Drives sit in the rail. Each drive has its
  own page (`#drive=<root>`) with Health, a drive bar and a right-click menu. The
  rail and the Activity column fold away.
- Rescue (finding files a failed server lost, and copying them back from other
  copies) groups results as Ready to rescue / Check first / No copy found,
  shown as a folder tree.
- Speech (Whisper) is **off** unless `settings.analysis.speech` is set. That was
  parked on purpose.

## Open, not agreed yet (ask before building)

- When the archive moves to another drive, Describe entries made against the old
  one point nowhere. The proposal: convert them into entries for that drive.
- A "Keep up to date" switch per drive (describe new files as they arrive).
- Describe from a drive's right-click menu.
- A "Came from" line in the file info panel (from the origin records).
- Everything under [ROADMAP.md → Known problems](ROADMAP.md#known-problems) and
  [Set aside](ROADMAP.md#set-aside-october-2026-to-come-back-to).

## How the owner works with you

- **Discuss first, code once agreed.** "do it", "go ahead" or "yes" means build.
  Explain the *why*.
- Plain sentences and no multiple-choice pop-up cards. One message with what
  you found and what you changed.
- **Never call something fixed until it is tested on real files.** Check
  progress yourself where you can, rather than asking for screenshots.
- Nothing happens invisibly: every action shows progress and confirms itself.
- Say so whenever a change touches **copying, deleting or signing**.
- No repeated disclaimers.

## Rules that never bend

- **Rushes never deletes by itself.** Delete All is a person's choice. Unwanted
  files are moved to Recently Removed (the action is called **Remove**).
- **Updates are offered, never installed by themselves.**
- **No terminal for users.** Everything happens in the app. Never bypass the
  office's security software: tell IT instead.
- **Signing keys stay on the owner's Mac** (outside the repository). They never go
  in the cloud or in git. Never type passwords or tokens for the owner.
- **This repository is public.** It never names the installation's drives,
  places, colleagues or organisation. Grep before every commit. Tests use
  invented names ("Meadow Park", "Picnic Shelter").

## Each build

1. Bump `app/VERSION` (one number for the pages, the helper code and both apps), add a CHANGES.md
   entry and HOW-IT-WORKS.md where behaviour changed.
2. **If `ingest.py`, `transfer_state.py`, `analyze.py` or `release.py` changed,
   re-sign them** (`python3 app/release.py sign <dir with the 4 files> <key>`)
   and commit the new `app/release.sig`. Otherwise a Mac keeps the old helper
   code without saying so (it happened from 0.12.11 to 0.13.25).
   `tests/test_menu.py` fails on a stale signature: always run it.
3. Run the tests ([DEVELOPING.md → Running the tests](DEVELOPING.md#running-the-tests)).
4. Simple rounds are committed straight to `main`.
5. Build and sign both apps and the disk image (`mac/build.py`,
   `mac/build.py watcher`, `mac/dmg.py`), each version in its own folder.
6. The owner publishes the GitHub release (`gh release create vX …` with the
   dmg and both zips). An older build folder is deleted only once its release
   exists. A superseded build that was never released is deleted, and its notes
   are merged into the next one.
