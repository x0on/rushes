# How Rushes works

This is the one document that says everything Rushes does. It covers every
button, everything that runs by itself, every file it reads or writes, and every
check that keeps footage safe. It is written in plain words, so you do not need
to be a programmer to read it.

Each section ends with **In the code**, which names the files and functions that
do what the section describes. A reader holding this document and the code
should find a match both ways: nothing in the code that is not here, and nothing
here that is not in the code. If you find a difference, it is a bug in one of
the two. Please open an issue.

Where Rushes falls short of what it should do, this document says so, and the
[roadmap](ROADMAP.md#known-problems) tracks the fix.

**Contents**

1. [The three parts](#the-three-parts)
2. [Where things are kept](#where-things-are-kept)
3. [Finding footage](#finding-footage)
4. [Bringing footage in](#bringing-footage-in)
5. [How a file is copied](#how-a-file-is-copied)
6. [Checking copies later](#checking-copies-later)
7. [Making footage findable: search, proxies, descriptions](#making-footage-findable)
8. [Keeping the archive tidy](#keeping-the-archive-tidy)
9. [What runs by itself](#what-runs-by-itself)
10. [Stopping things](#stopping-things)
11. [Updates](#updates)
12. [Pairing: one helper only](#pairing-one-helper-only)
13. [Who can do what](#who-can-do-what)
14. [What leaves your network](#what-leaves-your-network)
15. [What Rushes deletes](#what-rushes-deletes)
16. [Rushes' own backups](#rushes-own-backups)
17. [What could go wrong](#what-could-go-wrong)

---

## The three parts

Rushes is three programs that talk to each other.

**1. Rushes itself: the pages and the catalogue.** These are web pages served
by the machine that holds the archive (today a NAS). They are written in PHP
and keep a catalogue of every file in a SQLite database. People use them in a
browser:

- **Search** finds footage.
- **Ingest** brings in a camera card.
- **Pulls** are clip lists to hand to an editor.
- **Manage** is where the person looking after the archive works. It is behind
  a password.

The pages never copy, move or delete footage themselves. They write down what
was asked and leave the work to the other two parts.

**2. The runner.** A small script on the archive machine (`runner.sh`). The
machine's scheduler (cron) starts it once a minute, and it runs with full
rights (as root). It does the jobs the pages ask for, such as making proxies,
looking for duplicates, measuring space and installing approved updates. It
also keeps the catalogue up to date.

**3. The helper.** The program that copies footage (`ingest.py`). It runs
where it can see both the footage and the archive. That is usually a Mac,
inside the **Rushes Helper** app. It can also run on the archive machine itself
("built in"). It asks Rushes what to do, copies, proves each copy, checks
older copies, describes footage with a vision model, and says what it is doing
as it goes.

```text
 browser ──► Rushes pages ──► catalogue (SQLite)
                 │  writes what was asked
                 ▼
     job files + the queue (in the web folder)
        │                         │
        ▼                         ▼
     runner (archive machine)   helper (Mac, or built in)
        │                         │
        └──── archive share ◄─────┘  (copies, proxies, records)
```

**In the code:** `app/` holds the pages (`*.php`, `db/*.php`), the runner
(`runner.sh`) and the scripts it calls (`proxy.sh`, `dedupe.sh`, `verify.sh`,
`organize.sh`). The helper is `ingest.py` with `transfer_state.py`, and
describing is `analyze.py`. The Mac app is `mac/`: `rushes_helper.py` is its
window and service, `launcher.c` its program, and `build.py` builds it.

---

## Where things are kept

| Where | What | Who writes it |
|---|---|---|
| **The web folder** (`settings → archive.web`, default `/share/Web`) | the pages, `settings.json` (this installation), `rules.json` (media rules), the catalogue `rushes.sqlite`, the job queue `queue/*.job`, the helper's work list `ingest-queue.tsv`, status files (`*-status.tsv`, `disk.txt`, `job.log`, …) | the pages and the runner |
| **The archive share** (`settings → archive.local`, default `/share/VIDEO`) | your footage; `PROXIES/` (proxies, same paths as the originals); `_duplicates/` (the holding folder); `_rushes/` (Rushes' records, below) | the helper and the runner, never the pages |
| `_rushes/` on the archive | `origin/` (a record of every copy and move), `analysis/` (one description per file), `ingest-history.tsv`, `ingest-sections.tsv`, `proof-roots.txt` (folders with copy proofs), `ascmhl-moved/`, `db-copies/` (a week of database copies), `scripts/` and `deploy/` (updates waiting for approval), the helper's own code (`ingest.py`, `transfer_state.py`, `analyze.py`), `Rushes Helper.zip` | the helper and the runner, and you (when you drop an update in) |
| An `ascmhl/` folder inside each copied folder | the ASC MHL copy proof for the files below it | the helper |
| `Where this came from.txt` inside each copied folder | a short note: when, from where, by which computer, how many files | the helper |
| **The helper's computer**, `~/archive-pilot/` | its own notes: progress (`transfer-*.sqlite`), what it finished (`ingest-done.txt`), fingerprints it has already read (`hash-cache.json`), checking progress (`proof.json`), known addresses (`where.json`), network shares (`shares.json`), the pairing ID (`helper-id`), the stop note (`stopped.txt`), the one-helper lock (`helper.lock`) | the helper and the Rushes Helper app |
| On a Mac: `~/Library/Application Support/Rushes`, `~/Library/Logs/Rushes`, `~/Library/LaunchAgents/org.rushes.helper.plist`, `~/Applications/Rushes Helper.app` | the helper's code, its logs, the background service, the app | Rushes Helper |

`settings.json` describes one installation: its paths, address, sources,
departments and helper. `rules.json` holds what is the same everywhere: kinds
of media, card folders, caches, limits and themes. The rule is that no code
decides what a file *is*; that belongs in `rules.json`. A few places still
break this rule (see the [roadmap](ROADMAP.md#known-problems)).

**In the code:** `db/config.php` (`settings()`, `rules()`, `web_dir()`,
`archive_dir()`, `limit()`), `db/schema.php` (`DB_PATH`, `db_init()`), and in
`ingest.py` the constants `HOME`, `STATUS`, `NAS_MOUNT` and `ARCHIVE`.

---

## Finding footage

### Search

Type words into Search (the first tab). Every word must appear in the file's
path, its camera or its reel. Results are grouped by shoot (the folder that
names the event), not by folder or filename, because that is how people
remember footage. You can narrow the results to video, photos, audio or
projects. Files sitting in the holding folder show as "moved aside".

When footage has been described, a panel called **In the footage** shows the
matching moments: shots whose description, on-screen text, themes or tags
match, with a still, and lines that were spoken, with their time.

Click a file to see what it is:

- resolution, frame rate, codec and length;
- when it was recorded, the camera, timecode and reel (read from the file when
  its proxy is made);
- how many copies of it exist and where;
- whether it has a proxy;
- the shoot and year;
- where it lives.

Buttons:

- **Copy path** copies the file's path, written the way your computer sees it.
- **Add to pull** adds it to the current pull.

The left column has shortcuts (Everything, Archive, Projects, Library). Each one
adds a word to the search; it does not filter by folder. It also shows your
last five pulls and your recent searches, which are kept in your browser only.

**In the code:** `db/find.php` (the page), `db/search.php` (the search),
`db/analysis.php` (`analysis_search()`), `db/thumb.php` (the stills).

### Pulls

A pull is a named list of clips, made for handing to an editor.

- **Start one** from Search ("Add to a pull" → name, your name). Every pull has
  its own link to send.
- **Order the clips** with ↑ ↓, remove them with ✕, and rename the pull.
- **Download** in one of three forms, written for the computer doing the
  downloading. First choose "This is a Mac", "This is a PC" or "Something else",
  so the paths match how that computer sees the archive.
  - **For Premiere (XML):** a Premiere bin with every clip. Each described shot
    and each line spoken becomes a marker at its frame, and the first shot's
    description goes into the clip's Description. Markers need the clip's frame
    rate, so they appear only for files that have a proxy.
  - **List of paths:** a text file.
  - **The files (zip):** up to 1 GB and 1,000 files, stored without compression.

Everyone who can open Rushes can see and change every pull. They are the team's
lists. No password is needed.

**In the code:** `pull.php` (the page), `db/pulls.php` (create, add, remove,
move, rename), `db/pull-export.php` (the three downloads).

---

## Bringing footage in

### A camera card: Ingest

1. Plug the card into the helper's computer. Rushes sees it within about 20
   seconds, because the helper reports every drive it can see.
2. In **Ingest**, choose the card, the department (or client, or project), and
   for each day on the card, what was shot. Rushes builds the destination; nobody
   types a path:

   `<shelf>/<department folder>/<YYYY>/<YYYYMMDD> <what it was>`

   A card holding several days becomes one folder per day.
3. If the card's clock was never set (dates in 1970 or on 1 January), or the shoot
   is more than a year old, Rushes asks for the right date.
4. Press **Start ingest**, then press it again: the button asks "Sure?" and
   shows where each day will go. Follow the copy in "Moving now".
   When it has landed, "Find it" opens Search on it.

Anyone who can open Rushes can ingest a card, without a password. A new
department can be added here too, if the archive's administrator allowed that in
Reorganize. The request is checked twice: by the page, and again by `queue.php`,
which accepts only a card the helper really reports.

**In the code:** `ingest.php` (the page), `queue.php` (the `ingest_src`
branch), and in `ingest.py`, `watch()` (the `ingest` lines) and `main()` (with
`--into` and `--day`).

### Folders from an old server: Transfers

For bringing over a whole server, folder by folder:

1. Add the server under **Setup → 03 Where footage comes from**.
2. **Manage → Transfers** lists its folders ("sections") and what is already in
   the archive.
3. Tick folders and press **Copy the ticked ones**, then again to confirm.
   Folders over 1 TB can be **split** into their subfolders, to do in pieces.
4. Overview shows the transfer: a percentage, bytes copied and already there,
   folders done, and the folder being copied now. Progress is saved, so it
   survives restarts on both sides.

The folder layout is kept exactly as it was on the server. The first time a
server is copied into an archive that already has footage from it, the helper
first **matches earlier copies to their originals** (see below), so nothing is
copied twice.

**In the code:** `db/admin.php` (`drawMove`, `drawTransfer`), `queue.php` (the
folder branch), `db/transfers.php` (the saved transfer), `db/transfer.php` (the
helper reports progress), and in `ingest.py` `watch()`, `sections()` (`--sections`)
and `trace()`. `transfer_state.py` keeps progress on the helper's computer.

### Matching earlier copies (trace)

An archive often holds copies made before Rushes existed. Before copying from a
source for the first time, the helper does two things:

1. **It lists the originals** on the source, folder by folder. That list is
   kept for 7 days, so a stop part-way costs only the folder it was in.
2. **It reads each file already in the archive** and looks for an original of the
   same size whose first and last megabyte match.

Each match is written to a record ("copied before the record existed; matched
by content"). After that, the copy skips those files. Nothing is moved. A Pause
stops it, and it carries on later. The page shows "step 1 of 2" or "step 2 of 2"
and how much is left.

**In the code:** `ingest.py`: `needs_trace()`, `trace()`, `trace_paused()`.

---

## How a file is copied

This is the core of Rushes. Every copy, from a card or a server, follows the
same steps.

**Before copying:**

- **Is the source still there, and the archive?** If either has gone, the
  folder is marked "blocked" and nothing is marked done.
- **Is it already in the archive?** First the records are checked: a file
  Rushes copied before, still there at the same size, is not copied again.
  Then the list of every file in the archive (by size) is checked. A file whose
  size appears nowhere is new, and no reading is needed. When the sizes match,
  Rushes reads the first and last megabyte of both files and compares their
  fingerprints. With `--paranoid` it reads the whole file instead. Fingerprints
  are cached, so a second look is almost free.
- **A source that suddenly looks empty is not believed.** If a folder that held
  files now shows none, the copy stops, rather than marking it done.
- **Is there room?** Before starting a folder, the helper checks free space
  against a floor (5 TB by default, `disk_stop_free`).

**Copying one file:**

1. It is written under a temporary name (`….part`), in 8 MB pieces, while its
   fingerprint is taken (XXH3-128, or BLAKE2 on a computer without the xxhash
   library).
2. It is flushed to disk (fsync), and the date and permissions are copied.
3. If the original changed while it was being read (size or modification
   time), the copy is thrown away.
4. **It is read back from the archive and compared with the original's
   fingerprint.** On a Mac this read skips the computer's cache, so it really
   comes from the disk. Only if they match does the file get its real name.
5. A file that is already there, with the same name, size and content, counts
   as "already here". A *different* file with the same name is never
   overwritten: it is reported as a problem and left alone.
6. Names are matched whichever way their accents are written (NFC or NFD).

**After each file:** it is added to the search catalogue within seconds, and to
the transfer's progress.

**If something goes wrong:**

- A file that fails is tried once more at the end of its folder. If it fails
  again, it is recorded as "could not be copied", and the rest of the folder
  carries on.
- If the source or the archive disconnects, or someone presses Pause or Skip,
  the folder stops part-way. It resumes later: files already in place match and
  are not copied again, and a cut-off file was only a `.part` and is redone.
- A full archive stops the folder at once.
- A network share that drops is reconnected by the helper. It asks macOS to
  mount it again, at most every 2 minutes and then every 15, but only when the
  server answers, so no stream of "problem connecting" windows appears. This
  can be turned off in Manage and in Rushes Helper.

**What every copy leaves behind:**

- **An origin record** in `_rushes/origin/`. It has one line per file (copied,
  already here, failed), with where it came from and its size. It is never
  edited. Rushes reads these records to know what it has done, even after files
  move.
- **An ASC MHL generation** in the `ascmhl/` folder of the copied folder. This
  is the film industry's standard copy proof (it can be checked by Hedge,
  Silverstack or the ASC's own tool). A stopped copy writes one too, for what
  landed.
- **`Where this came from.txt`** in the copied folder.
- A line in `ingest-history.tsv`.

**In the code:** `ingest.py`:

- deciding: `main()`, `load_manifest()`, `load_origins()`, `already_here()`, `digest()`;
- copying: `bring()` (inside `main()`), `read_back()`;
- recording: `mhl_copied()`, `mhl_write()`, `Origin`, `leave_a_note()`, `history()`;
- drives: `free_bytes()`, `Dropped`, `reconnect()`.

On the server, `db/landed.php` takes the new files into search.

---

## Checking copies later

When the helper has nothing to copy, it checks, a few minutes at a time. It
reads only; it never repairs or moves anything. Manage and Rushes Helper each
have a **Pause checking** switch.

1. **Older copies, once.** Copies made before read-back existed are read again
   beside their originals. Those that match get an ASC MHL record. Those that
   differ are written in a "check" record and shown in Activity.
2. **How many copies exist, weekly.** For every file, it asks whether the
   original it came from is still there at the same size. It compares names and
   sizes only, and skips sources that are not connected. Overview then shows how
   much is kept twice, warns when files exist only in the archive, and each file
   shows its copies.
3. **The whole archive, every 90 days per folder.** Every file with a copy proof
   is read again and compared with its fingerprint, the way backup tools do.
   Damaged, unreadable or missing files go into a "check" record. The 90 days
   is a setting (`proof.check_every_days`). When nothing is due, the helper does
   not look again for six hours.

The helper decides whether anything is due from its own notes, without looking
at the archive.

**In the code:** `ingest.py`: `check_due()`, `check_some()`, `_older_todo()`. On
the server, `db/copies.php` (the counts) and `db/state.php` (the "kept twice"
tile and card).

---

## Making footage findable

### The catalogue

The runner keeps a list of every file in the archive with its size
(`manifest.tsv`). Rushes turns that list into the search catalogue. A new list
replaces the catalogue only if it is complete: an empty list, or one less than
half the size of the last, is refused, and the old catalogue stays. Files the
helper copies are added at once, without waiting for the next list.

The full list is rebuilt when you ask (Manage → Jobs and tools → **Rebuild the
file list**). After any job that moves files, the runner also rebuilds it by
itself.

**In the code:** `runner.sh` (`build_manifest`, `build_index`, `refresh_state`),
`db/sync.php` (`sync_search()`), `db/import.php`, `db/landed.php`.

### Proxies

A proxy is a small H.264 copy of a video, used for describing and, later, for
playing in search. Proxies are made on the archive machine. They are saved in
`PROXIES/` with the same paths as the originals.

- **Settings:** Manage → Describe → Proxy settings. You can choose 720p or
  1080p, at 4 or 6 Mbit/s on the video chip, or made in software (higher
  quality, slower). Nothing below 4 Mbit/s is offered. The default is 720p at
  4 Mbit/s.
- **Test before choosing:** "Test proxy settings…" lets you pick a clip. Rushes
  makes a 20-second sample with each setting and shows stills side by side. The
  samples stay in `_rushes/proxy-test` until the next test.
- **The video chip:** Rushes uses it when it can. Kinds of file the chip cannot
  read are learnt, and go straight to the processor next time.
  - Proxies are made at low priority.
  - A proxy is written under a temporary name first.
  - Files changed in the last two hours are left for later (they may still be
    arriving).
  - Before starting, Rushes checks there is room for the proxies.
- **Stop proxies** stops at once. The proxy being made is thrown away, and
  finished ones are kept.
- **Remake proxies** deletes that folder's proxies and makes them again. It is
  refused while proxies are being made, or while the folder is being described.
- **Test the video chip** (Jobs and tools) measures what the chip can do and
  writes a report. It makes no files.

When a proxy is made, the original's details are read and kept: resolution,
frame rate, codec, length, the camera's clock, timecode, reel, and make and
model.

**In the code:** `proxy.sh` (planning and making), `runner.sh` (the
`proxy-test`, `proxy-plan`, `proxy-build`, `proxy-remake`, `proxy-stop` and
`gpu-test` jobs, and the automatic start), `db/analyze.php` (the buttons),
`db/prepare.php` (`media_import()`, `media_probe()`).

### Describing footage

The helper describes footage with two models that run on its own computer:

- **a vision model** (default Qwen3-VL 8B, 4-bit, through MLX on Apple chips),
  for the picture;
- **Whisper** (default large-v3-turbo), for speech.

Both are settings (`analysis.model`, `analysis.whisper`, `analysis.python`).

**What happens to a file:**

1. It is cut into shots (PySceneDetect). Shots shorter than a second are joined
   to their neighbour.
2. For each shot, two stills are taken at 768 px from the **original**. If the
   original cannot be read, the proxy is used instead. Stills are never taken
   from the first second of a shot (the first two seconds of a recording,
   while the camera settles) or its last half second. A very short shot gets
   one still from its middle.
3. The model gets both stills together and is asked for:
   - one factual sentence;
   - the text on screen, copied exactly, one entry per line;
   - the shot size (extreme wide to extreme close-up);
   - how many people (none, one, two, few, crowd) and their broad ages;
   - indoor or outdoor;
   - the light;
   - the mood;
   - up to three themes, only from the list in `rules.json`;
   - a few tags.

   It is told to be literal, and not to guess who people are or where this is
   unless it is written in the picture.
4. An answer that is not valid JSON is repaired if possible, or asked for once
   more. If it still fails, the shot is counted as "could not be read" and the
   raw answer is kept. A file that cannot be read at all, or that fails part-way,
   is not recorded, so it is tried again on the next run.
5. Speech is transcribed in whatever language was spoken. Silence and music
   are dropped.
6. Morning, afternoon, evening or night come from the camera's clock, not from
   the model.

**What it writes:** one description per file in `_rushes/analysis/`. It is
named by a fingerprint of the file's content (its size plus first and last 4 MB),
so it survives moves and renames. Next to it is one still per shot. A file
already described with the same model and question is skipped.

**No face recognition exists in Rushes.** People are only counted, with
broad age bands.

**Describing has its own lane.** It runs beside copying, one folder and one
file at a time, with its own **Pause describing** switch (Manage and Rushes
Helper). Rushes takes the descriptions into search while the helper is
describing, and once a day otherwise.

**Preparing folders** (Manage → Describe) puts the two steps in order. You add
folders to a list, and for each one the archive machine makes the proxies,
then the helper describes it. One step after the other, by itself, never twice.
You can reorder the list, start the next folder now, try a folder again, or take
it off the list. The page shows the time left, measured from real speeds, and
why any proxy failed.

**In the code:**

- `analyze.py`: `shots_of()`, `whole_shots()`, `sample_times()`, `frame()`,
  `Vision`, `PROMPT`, `tidy()`, `transcribe()`, `fingerprint()`;
- `ingest.py`: `describe_lane()`, `describe_folder()`, `analysis_tools()`;
- the server: `db/prepare.php` (`prepare_table()`, `prepare_advance()`),
  `db/analyze.php`, `db/analysis.php` (`analysis_import()`).

---

## Keeping the archive tidy

### Reorganize: departments and the tidy-up

**Manage → Reorganize** holds the plan the archive follows:

- what your top folders are called (departments, clients, projects, or your
  own word);
- the list of them;
- whether new ones may be added at Ingest.

Each department is linked to the folder it already has. Writing the plan
renames nothing and breaks no Premiere project.

**The tidy-up** moves footage that was copied into `ARCHIVE` onto the shelf, into
its department's folder:

- Rushes groups the files by where they came from and suggests a department for
  each group. You confirm.
- The helper then moves each file (a rename on the same disk, so it is instant).
  It never moves a file onto one that exists, and never moves anything from a
  folder still being copied.
- Each file's proxy moves with it, and so does its copy proof (a new ASC MHL
  generation where it lands). A record left with no files is moved to
  `_rushes/ascmhl-moved`, never deleted.
- Search and every pull follow each file to its new place.
- Folders left empty are removed (see [What Rushes deletes](#what-rushes-deletes)).

**Put back** undoes a tidy-up from its record.

**Premiere projects after a tidy-up** (Reorganize → 05):

1. Choose a `.prproj` file. Your browser opens it and sends only its file
   paths to Rushes. The project itself never leaves your computer.
2. Rushes answers with where each clip went.
3. You save a corrected copy of the project. Mac and Windows paths both work.

**In the code:** `structure.php` (the page), `db/tidy.php` (the proposal and
asking), `db/relink.php` (Premiere paths), `db/moved.php` (search follows), and
in `ingest.py` `tidy()`, `untidy()`, `move_proxy()`, `mhl_follow()` and
`clear_out()`.

### Duplicates

Rushes finds files that are the same file, keeps one, and moves the others into
the holding folder (`_duplicates`). The folder structure is kept, so anything
can be put back.

1. **The scan** compares every file by content. It is done by Czkawka, a
   separate duplicate finder running in a container on the archive machine. It
   takes hours, so it is started by the `scan` job, which has no button. The
   runner's comments show how to schedule it weekly.
2. **Look for duplicates** (Manage → Duplicates) works out which copy to keep
   and shows the plan. It moves nothing. You choose which copy wins:
   - the project folder over card dumps (the default);
   - card dumps;
   - the shortest path;
   - the oldest file.

   - Some copies always lose, such as `Copied_…` folders, Premiere's Media Cache
     and doubled extensions like `.MXF.MXF`.
   - Copies in the recycle bin are never kept and never moved.
   - Numbered image-sequence frames are never moved, even when identical,
     because removing one breaks the sequence.
3. **Move the copies aside** moves exactly the plan you were shown, as long as
   it was made from the same scan and with the same choice. Otherwise it plans
   again first. Before moving each file, it checks the file still exists at the
   size the scan saw, and the copy being kept is there. Every move is written to
   a log that is added to, never emptied.
4. **Put them back** returns every file still in the holding folder.
5. **Check the holding folder** goes through every file in it. For each, it
   checks that its twin (the copy kept) is still in the archive at the same
   size. The answer is SAFE only if every file has its twin. It names the files
   that do not, and ignores system clutter, saying which rule ignored what.

Rushes never empties the holding folder. Emptying it is your step, after the
check says SAFE.

**In the code:** `runner.sh` (the `scan`, `plan`, `apply`, `undo` and `verify`
jobs), `dedupe.sh`, `verify.sh`.

### Editing caches

Editing software scatters caches through the archive: Premiere's `.pek`, `.cfa`
and `.ims` files, Capture One's cache folders, and others. They are listed in
`rules.json`. All of them are rebuilt from the originals on demand.

**Manage → Cache** shows how much there is and gives examples. It counts from
the catalogue, so it takes a second rather than a sweep of the archive. Things
that look like clutter but are not (such as Premiere's Auto-Save, an edit's
only rescue after a crash) are shown as "left alone" and never moved.

- **Move them out** moves them into the holding folder. It never takes anything
  from the recycle bin (that would undelete it) or from the holding folder
  itself.
- **Put them back** returns every cache file still in the holding folder.

**In the code:** `db/junk.php` (the list), `runner.sh` (the `cachescan`,
`cacheclean` and `cache-undo` jobs), and `db/config.php` (`cache_groups()`,
`cache_sql()`).

### The old date-based layout

An early version could sort the whole archive by date. That was dropped. Only
its undo is left (the `organize-undo` job), so anything it moved can still be
put back. **In the code:** `organize.sh`.

---

## What runs by itself

Rushes follows six rules for anything that repeats:

1. **Nothing to do, do nothing.** An idle check touches no share and writes nothing.
2. **Every step has a time limit.** A step that cannot finish is abandoned and said.
3. **Never two at once.** If the last run is still busy, the next does not start.
4. **Failing slows down, then stops.** It waits 20 s, 1 min, 5 min, then 15 min.
   After that it stops, says so, and waits for a person to press Try again. The
   one exception is asking Rushes' web page: that harms nothing, so it keeps
   asking every 15 minutes, and a laptop that leaves the office carries on when
   it is back.
5. **Visible.** Overview → **What runs by itself** shows each repeating task,
   how often it runs, when it last ran, and whether it stopped.
6. **Pages ask only while someone is looking,** and ask less when the archive is
   slow.

### The runner, every minute

In this order:

1. **STOP:** if a file called `STOP` is in the web folder, it does nothing at
   all.
2. It writes the time to `runner-alive.txt`. Overview says "not picking up
   jobs" if this goes quiet for three minutes.
3. If the last minute's run is still busy, it stops here.
4. It reads whether copying is paused, and whether VIDEO has stopped answering.
   Below, "if VIDEO may be read" means neither is true.
5. If VIDEO may be read, it measures free space (`disk.txt`), within 20 s.
6. It keeps the machine's scratch space (`/tmp`) at least 256 MB and notes how
   full it is.
7. **Built-in helper only:** if it is not running, the runner starts it. It does
   this only if VIDEO may be read.
8. **Proxies:** if a prepared folder needs proxies, nothing else is being made,
   and VIDEO may be read, it starts making them.
9. **Updates:** if someone pressed **Check now**, an install just finished, or
   there is no list yet, and VIDEO may be read, it looks in `_rushes` for
   waiting updates, within 20 s.
10. Then, if no job is running (one at a time; a lock left by a run that was
    killed is taken over, and said in the log):
    - **the jobs** you asked for, in order;
    - **the catalogue update** (`import.php`). With VIDEO paused or not
      answering, only what lives in the web folder is updated;
    - **the database copy** onto VIDEO, if there is a new one (within ten
      minutes);
    - **once a day,** a check that private files cannot be downloaded (see
      [Who can do what](#who-can-do-what)).

**The time limit** works like this. Every automatic touch of VIDEO is started
in the background and abandoned if it has not answered in 20 seconds. It is
never waited on: a process stuck on a dying disk cannot even be killed. After
three in a row, the runner stops touching VIDEO by itself. Overview shows
"Rushes stopped reaching the VIDEO share by itself", with **Try again**.

Jobs you ask for (duplicates, proxies, tests, and so on) are not under this
time limit, and still run while copying is paused.

### Rushes, while someone is looking

- The top bar asks every 5 seconds what is happening. Manage asks every 4
  seconds, plus every 10 seconds about describing. Ingest asks every 5 seconds.
- A hidden tab asks nothing. After a slow or failed answer, the wait doubles,
  up to a minute, and the top bar says "the archive is slow".
- These repeating questions read only the web folder, never the archive share.
  Some things a person opens or presses do read the archive once:
  - opening Setup (it lists the shares) or Reorganize (it lists the shelf);
  - opening the tidy-up;
  - planning a folder;
  - a still in search;
  - a zip download.

### The helper

| What | How often | Touches the archive? |
|---|---|---|
| Asks Rushes for work | every 20 s; if Rushes does not answer, 20 s, 1, 5, then every 15 min | no |
| Asks for Pause, Try again now, skip | at most every 5 s while waiting | no |
| Says what is plugged in (its heartbeat) | every 20 s | no |
| Looks at local drives and cards | every 20 s, not while paused or stopped | local drives only |
| Looks at network shares (the archive, servers) | every 10 min, within 10 s; never searched for cards | yes, briefly |
| Is the archive there? Are the sources there? | only when there is work, within 30 s | yes |
| Remembers where network shares live (for reconnecting) | only when there is work, at most every 5 min | yes |
| Checking copies | when there is nothing to copy, a few minutes at a time | yes, reading |
| Describing | when a folder is queued | yes |
| Looks for a new version of its own code | once an hour, between jobs | no (asks Rushes) |
| Looks for a new address for Rushes | every 5 min | no |
| Sends its status | when it changes, at most every 2 s while working | no |

**Stopped by itself:** if a share does not answer within 30 seconds three times
in a row, the helper stops touching shares. It says so in Rushes Helper ("Stopped
by itself", with **Try again**) and on the pages ("helper stopped · press Try
again"). **Try again now** in Manage also clears it.

**In the code:**

- the runner: `runner.sh`, top to bottom (`v()`, `may_v()`, `survey()`, the job
  loop, `dbcopy()`);
- the pages: `head.php` (`every()`, the wrapped `fetch`);
- the helper, in `ingest.py`:
  - `watch()` (the main loop), `control()`, `wait()`, `backoff()`;
  - `report_forever()`, `look_forever()`, `volumes()`;
  - `within()`, `stall()`, `stopped()`, `check_due()`;
  - `update_self()`, `learn_where()`, `remember_shares()`.
- What runs by itself: `db/state.php` (`$repeats`).

---

## Stopping things

| You want to… | Do this | What it stops |
|---|---|---|
| pause copying | Manage → **Pause copying**, or Rushes Helper → Copy footage off | The helper starts nothing new and stops the current folder at its next safe point. While paused, copying touches no share, the runner leaves VIDEO alone, and no drive is looked at. Nothing is lost. |
| pause describing | **Pause describing** (Manage → Describe, the helper row, or Rushes Helper) | The describing lane only. Files already described are kept. |
| pause checking | **Pause checking** (Manage or Rushes Helper) | Checking copies only, after the file it is reading. |
| stop the helper on a Mac | Rushes Helper → **Run in the background** off | It stops now and does not start at the next login. |
| stop everything on the archive machine | Put a file called `STOP` in the web folder | The runner does nothing at all, every minute, until it is removed. |
| skip one folder | Overview → **Skip** (offered when a folder has stopped) | That folder is taken out of the transfer and the queue. |
| look again now | **Try again now** | The helper stops waiting and looks again. It also clears "stopped by itself". |

Buttons that start work, move files or take something away ask twice, on the
button itself. The first press turns it into "Sure? …" and says beside it
what will happen. A second press within six seconds does it. Rushes never uses
a browser pop-up. Buttons that only change a setting you can change back (a
pause, a proxy setting, the order of a list) act on the first press and say
what they did.

**In the code:** `db/helper.php` (the switches), `db/config.php`
(`helper_control()`), `ingest.py` (`control()`, `watch()`, `describe_lane()`,
`check_some()`), `runner.sh` (`STOP`, `PAUSED`), `mac/rushes_helper.py`
(`stop_service()`).

---

## Updates

There are three kinds of update, and they work differently.

**Scripts the runner uses** (`runner.sh`, `proxy.sh`, …) run with full rights,
so they always wait for a person:

1. Put the new version in `_rushes/scripts/` on the archive.
2. Manage → What runs by itself → **Check now**. Within a minute, Overview lists
   what is waiting, with each file's fingerprint.
3. Press **Install it**, then **Sure?**. The runner installs exactly the file you
   approved: it checks the fingerprint of the copy it made, and refuses a file
   changed since.

**Pages** work the same way, through `_rushes/deploy/`. Only `.php`, `.html`,
`.js`, `.css` and `.json` files are accepted, at the top level or in `db/`. An
installed page leaves the drop folder.

Rushes does not look for updates on a timer. They only exist when a person puts
them in `_rushes`, so it looks only when you press **Check now**, after an
install, or when it has no list yet.

**The helper's own code** (`ingest.py`, `transfer_state.py`, `analyze.py`) does
*not* wait for approval:

- Once an hour, between jobs and never while describing, the helper asks Rushes
  for the fingerprints of the versions in `_rushes`.
- If they differ from its own, it downloads them from Rushes. It checks each
  download against its fingerprint and checks that it is valid Python, replaces
  its files, and restarts itself.
- It only ever asks your Rushes server, never the internet.
- The download is plain http and not signed. Whoever controls your Rushes server,
  or your network, can therefore change what runs on the helper's computer.
- In built-in mode, the runner starts the helper directly from `_rushes`, with
  full rights.

Signed updates are on the [roadmap](ROADMAP.md).

When Rushes Helper is first set up, it downloads the same three files the same
way, and checks each against its fingerprint before installing it.

**The Rushes Helper app itself** (its window, Python and launcher) is not
updated this way. A new version is downloaded from Setup and opened.

**In the code:** `runner.sh` (`survey()`, `page_ok()`, the `update-scripts`
job), `db/helper.php` (`check-updates`, `scripts`, `?hash`, `?code`),
`db/config.php` (`waiting_read()`), `ingest.py` (`update_self()`).

---

## Pairing: one helper only

Two helpers working from the same list would copy over each other. So one
helper is **paired** with Rushes, and only it is given work.

1. Setup → 04 Helper → **Pair a helper** shows six numbers. They work once, for
   ten minutes. Five wrong tries cancel them.
2. Type them into Rushes Helper on the Mac. Rushes gives that Mac an ID, which
   it sends with every request from then on.
3. Any other helper that asks for work, or reports anything, is refused.
   Overview names it, by computer name and address. A refused helper touches
   nothing, and asks less and less often.

Pairing another Mac takes the work away from the one paired before. The button
asks twice. Until a helper is paired, every helper is given work, and Setup
says so. A helper built into the archive machine counts as paired.

Separately, only one helper can run on one computer at a time.

**In the code:** `db/pair.php`, `db/config.php` (`helper_pairing()`,
`helper_gate()`, `helper_refused()`), `setup.php` (the box), the doors that call
`helper_gate()` (`report.php`, `status.php`, `landed.php`, `moved.php`,
`copies.php`, `transfer.php`, `helper.php?queue`), `ingest.py` (`HELPER_ID`,
`fetch_queue()`, `only_one()`), `mac/rushes_helper.py` (`pair()`).

---

## Who can do what

Rushes is built for a trusted office network. Here is exactly what each person
can do today.

**Anyone who can open Rushes, without a password, can:**

- search, see everything Overview shows (paths, progress, the recent log), and
  read descriptions and stills;
- make, change and download pulls, including downloading files as a zip (up to
  1 GB at a time);
- ingest a card, and add a department at Ingest if that is allowed;
- while no helper is paired: press the helper's switches (Pause, Try again
  now, …), and act as a helper.

**Someone signed in to Manage can also:**

- run jobs (duplicates, proxies, tests, rebuilds);
- install updates;
- choose folders to transfer;
- reorganize;
- change settings;
- pair a helper;
- change the password.

**The paired helper** gets work and reports what it did. Its own window can press
its switches.

**How Rushes protects itself:**

- **The password** starts as the app's name (`rushes`). Until it is changed, the
  sign-in page says so and Overview shows a warning. A changed password is kept
  only as a hash, in `adminpass.php`, a file that prints nothing if someone asks
  the web server for it.
- **The pairing ID** is kept the same way, in `helper-id.php`.
- **The catalogue, its daily copy, the work list and the refused-helper list**
  are protected by `.htaccess`, which tells the web server never to hand them
  out. Not every web server obeys `.htaccess`, so once a day the runner asks its
  own web server for them. If any comes back, Overview says in red which ones,
  and how to fix it.
- **The settings file and status files** are readable by anyone who can open
  Rushes. They hold paths and progress, not passwords.
- **The runner checks every job again,** whatever the page already checked. A
  folder that would climb out of the archive (`..`) is refused.
- **Pages never touch footage.** They only write down what was asked.

**Known limits:**

- The pages use plain http, not https, so passwords cross the network
  unencrypted.
- There is no protection against another website making your browser press a
  button (no CSRF tokens). Sessions use `SameSite=Lax` cookies.
- Anyone who can write to the web share can change the runner, which runs with
  full rights. Give write access to the web share to the administrator only
  (INSTALL.md).

All of these are on the [roadmap](ROADMAP.md#known-problems). To use Rushes from
outside the office, connect through a VPN. Do not put it on the internet.

**In the code:** `db/auth.php`, `.htaccess`, `runner.sh` (the daily self-check,
job validation), `db/state.php` (the cards).

---

## What leaves your network

- **Nothing about your footage.** Footage, descriptions, transcripts and stills
  stay on your machines.
- **Models, the first time.** If the vision model or Whisper is not already on
  the helper's computer, the libraries download it from Hugging Face the first
  time describing runs. The default Whisper model is always fetched by name. No
  footage is sent.
- **Ask for help** (Rushes Helper) opens a new GitHub issue in your browser.
  The page's address carries the Rushes Helper version, the macOS version and the
  processor type. The diagnostics file is saved on your Desktop and is *not*
  sent. You read it and decide.
- **Docker, once,** if you run **Test the video chip**: it downloads a public
  ffmpeg image.

Inside your network:

- the helper talks to your Rushes server;
- describing reads the theme list from it;
- reconnecting a share talks to that file server.

**In the code:** `analyze.py` (`Vision`, `transcribe()`, `themes_from()`),
`mac/rushes_helper.py` (`ask_help()`, `diagnostics()`), `runner.sh` (`gpu-test`).

---

## What Rushes deletes

Rushes never deletes your footage, the originals or their copies. Duplicates
and caches are **moved** to the holding folder, and only you empty it. This is
everything Rushes does delete, and when:

**On the archive share:**

- **Proxies of one folder,** when you press **Remake proxies**. They are made
  again straight after. This is refused while proxies are being made or the
  folder is being described. It is the only folder Rushes deletes as a whole.
- **The previous proxy test,** when you run a new one (`_rushes/proxy-test`).
- **Unfinished pieces:** a `.part` copy that failed or was interrupted, and a
  proxy that failed or was stopped.
- **After a tidy-up:**
  - folders left empty (only `.DS_Store` inside), below `ARCHIVE` or, after a
    Put back, below the department folders;
  - a copied folder's `Where this came from.txt`, when the folder empties, is
    added to the same note where the files went, or moved there.
- **An approved page** in `_rushes/deploy`, once it is installed.
- **A database copy** in `_rushes/db-copies` is replaced by the one made a week
  later on the same weekday.

**In the web folder and on the helper's computer:** Rushes' own temporary and
status files. Each job file is deleted when it is picked up. The helper's
downloaded file list is deleted when it looks incomplete. Its list of
originals is deleted after 7 days. `stopped.txt` is deleted on Try again. Old
logs are trimmed.

**On a Mac:**

- Rushes Helper's **Remove** deletes its background service.
- When Rushes Helper installs, it replaces an older copy of the app in
  `~/Applications`.
- The remove command shown in Setup (pasted into Terminal) deletes the app and
  its service.

**In the code:** `runner.sh` (`proxy-remake`, `proxy-test`, `update-scripts`,
`dbcopy()`), `proxy.sh`, `ingest.py` (`bring()` inside `main()`, `clear_out()`,
`mhl_follow()`), `mac/rushes_helper.py` (`remove_service()`,
`copy_to_applications()`).

---

## Rushes' own backups

Your footage needs its own backup. RAID is not a backup. This is about what
Rushes itself knows, which mostly lives in the catalogue database. Search can
be rebuilt from the archive, but **pulls and the transfer progress exist only
in the database.** So:

- Once a day, Rushes checks the database (SQLite's quick check). If it is good,
  Rushes copies it with SQLite's own backup, which is safe while the database is
  in use.
- The runner puts that copy on the archive as
  `_rushes/db-copies/rushes-<weekday>.sqlite`. That gives a week of copies.
- A database that fails the check is never copied over the good copies.
  Overview says so in red.

The descriptions (`_rushes/analysis`) and the records (`_rushes/origin`,
`ascmhl/`) already live on the archive, beside the footage.

**In the code:** `db/schema.php` (`db_daily_copy()`), `runner.sh` (`dbcopy()`),
`db/state.php` (the card).

---

## What could go wrong

The risks (a disk stalling, two helpers, no backup, ransomware, a mistaken delete,
running out of space, a damaged database, the model inventing things, public
records) are listed with what was decided and where each stands in the
[roadmap](ROADMAP.md#risks). This document describes only what Rushes does
about them, in the sections above.
