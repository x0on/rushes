# How Rushes works

This is the one document that says everything Rushes does. It covers every
page and button, everything that runs by itself, every file it reads, writes
or deletes, everything it sends over the network, and every check that keeps
footage safe. It is written in plain words, so you do not need to be a
programmer to read it.

Each section ends with **In the code**, which names the files and functions
that do what the section describes. A reader holding this document and the
code should find a match both ways: nothing in the code that is not here, and
nothing here that is not in the code. If you find a difference, it is a bug in
one of the two. Please open an issue.

Where Rushes falls short of what it should do, this document says so, and the
[roadmap](ROADMAP.md#known-problems) tracks the fix.

**Contents**

1. [The three parts](#the-three-parts)
2. [Where things are kept](#where-things-are-kept)
3. [Settings](#settings)
4. [Finding footage](#finding-footage)
5. [Bringing footage in](#bringing-footage-in)
6. [How a file is copied](#how-a-file-is-copied)
7. [Checking copies later](#checking-copies-later)
8. [Making footage findable](#making-footage-findable)
9. [Projects in and out](#projects-in-and-out)
10. [Keeping the archive tidy](#keeping-the-archive-tidy)
11. [Manage](#manage)
12. [The Mac app: Rushes](#the-mac-app-rushes)
13. [What runs by itself](#what-runs-by-itself)
14. [Stopping things](#stopping-things)
15. [Updates](#updates)
16. [Pairing: one helper only](#pairing-one-helper-only)
17. [Who can do what](#who-can-do-what)
18. [What crosses the network](#what-crosses-the-network)
19. [What Rushes deletes or moves](#what-rushes-deletes-or-moves)
20. [Rushes' own backups](#rushes-own-backups)
21. [What could go wrong](#what-could-go-wrong)

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
was asked, as a job file or a line in the helper's work list, and the other
two parts do the work.

**2. The runner.** A small script on the archive machine (`runner.sh`). The
machine's scheduler (cron) starts it once a minute, and it runs with full
rights (as root). It does the jobs the pages ask for, such as making proxies,
looking for duplicates, measuring space and installing approved updates. It
also keeps the catalogue up to date and copies the database every day. When
the archive is a drive on a Mac, Rushes Helper does the runner's everyday part
itself (`runner.py`; see [Rushes on this Mac](#rushes-on-this-mac)).

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
     job files + the helper's work list (in the web folder)
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

**The web folder** (`settings → archive.web`, by default `/share/Web`). This
is the folder the archive machine's web server serves.

- **Rushes' own files:**
  - the pages;
  - `settings.json` (this installation) and `rules.json` (media rules);
  - `adminpass.php` (the password);
  - `helper-id.php` (the pairing ID);
  - `rushes.sqlite` (the catalogue, unless `archive.database` puts it
    elsewhere).
- **Work waiting:**
  - `queue/*.job` (jobs for the runner);
  - `ingest-queue.tsv` (the helper's work list);
  - `prepare.tsv` (folders to prepare);
  - `tidy-<id>.tsv` (a tidy-up plan);
  - `cache-files.txt` (caches to move).
- **What the runner and helper report:**
  - `job.log` and `job-status.txt` (the runner);
  - `runner-alive.txt` (the runner's heartbeat);
  - `disk.txt` and `tmp-disk.txt` (free space);
  - `helper-volumes.tsv` (drives the helper sees);
  - `ingest-status.tsv` and `describe-status.tsv` (the helper's two lanes);
  - `ingest-history.tsv` and `ingest-sections.tsv` (sent by the helper);
  - `helper-control.json` (Pause and the other switches);
  - `waiting.tsv` (updates waiting);
  - `manifest.tsv` and `index.txt` (every file in the archive);
  - `copies-summary.json`;
  - the proxy files (`proxy-*.txt`, `proxy-*.tsv`, `proxy.log`, `proxy-test/`);
  - the duplicate files (`results_duplicates.txt`, `dedupe-*.tsv` including
    `dedupe-rules.tsv`,
    `verify-result.tsv`, `cache-moves.tsv`);
  - `video-stalls.txt` and `video-tripped.txt` (the breaker);
  - `exposed.txt` (the daily private-file check);
  - `archive-path.txt` (where the archive is, for the runner);
  - `release.py` (installed like the runner's scripts) and, for the built-in
    helper, `helper-code/` (the checked copy it runs from);
  - `db-copy.sqlite` and `db-copied` (the daily database copy; the copy is
    written beside the catalogue, wherever `archive.database` puts it);
  - `db-damaged.txt`;
  - `helper-refused.tsv`;
  - `gpu-test.txt`;
  - built-in helper only: `helper.log`, `helper.pid` and `helper-builtin.txt`.

**The archive share** (`settings → archive.local`, by default `/share/VIDEO`):

- your footage;
- `ARCHIVE/` (where copied folders land, until a tidy-up moves them);
- your departments' folders on the shelf (`organise.shelves`);
- `PROXIES/` (proxies, with the same paths as the originals);
- `_Recently Removed/` (what Remove put out of the way; `_duplicates/` before 0.12.4, and on a NAS);
- `_rushes/`, holding Rushes' records:
  - `origin/` (a record of every copy, move and check);
  - `analysis/` (one description per file, with its stills);
  - `ingest-history.tsv` and `ingest-sections.tsv`;
  - `proof-roots.txt` (folders with copy proofs);
  - `ascmhl-moved/` (proof records whose footage all moved);
  - `db-copies/` (a week of database copies);
  - `proxy-test/` (the last proxy test);
  - `gpu-test.txt`;
  - `scripts/` and `deploy/` (updates waiting for approval);
  - the helper's own code (`ingest.py`, `transfer_state.py`, `analyze.py`,
    `release.py`), its signature `release.sig`, and `Rushes Helper.zip`.
- Inside every copied folder:
  - an `ascmhl/` folder (the copy proof);
  - `Where this came from.txt`.

**The helper's computer**, in `~/archive-pilot/`:

- `transfer-*.sqlite`: progress, files still to add to search, everything it
  copied;
- `ingest-done.txt`: what it finished;
- `ingest-listed.txt`: folders it already split;
- `nas-manifest.tsv`: its copy of the archive's file list;
- `hash-cache.json`: fingerprints it already read (saved once per run; one not used
  for 180 days, or whose file has changed since, is dropped);
- `ingest-plan.tsv` and `ingest-copied.tsv`: the last run's plan and log;
- `trace-originals-*.tsv`: originals it listed;
- `proof.json`: checking progress (saved at most every 30 seconds while checking,
  and whenever it stops: after a crash, a few files are read again);
- `where.json`: addresses for Rushes;
- `shares.json`: where network shares live;
- `helper-id`: the pairing ID;
- `stopped.txt`: present when it stopped by itself;
- `helper.lock`: one helper per computer;
- `describe-tries.json`: folders describing will try again, and when;
- `tidy-<id>-late.txt`: moves a stopped tidy-up gave up on, checked when it
  carries on.

**On a Mac**, Rushes Helper also uses:

- `~/Applications/Rushes Helper.app`;
- `~/Library/Application Support/Rushes`: the helper's code (with its
  `release.sig`) and the Rushes
  address;
- `~/Library/Logs/Rushes`: `helper.log` and `setup.log`;
- `~/Library/LaunchAgents/org.rushes.helper.plist`: the background service.

The runner and its scripts find the web folder as the folder `runner.sh` is
in (when it holds `settings.json` and its path has no spaces), and the archive
from `archive-path.txt`, one line Rushes writes there whenever settings are
saved, and every minute if it is missing. The runner, which acts on that folder
with full rights, checks the line again before using it:

- plain letters, digits, dots, dashes and underscores, no spaces and no `..`;
- under a place where data volumes live: `/share/…` (QNAP), `/volume1/…`
  (Synology), `/srv/…`, `/mnt/…`, `/media/…` or `/data/…`;
- not a hidden folder, not a QNAP system folder, not the web folder;
- a folder that exists.

Otherwise it uses the QNAP's places, `/share/Web` and `/share/VIDEO`.

**In the code:**

- `db/config.php`: `settings()`, `web_dir()`, `archive_dir()`, `shelf_dir()`;
- `db/schema.php`: `DB_PATH`;
- `ingest.py`: `HOME`, `STATUS`, `NAS_MOUNT` and `ARCHIVE`;
- `mac/rushes_helper.py`: the constants at the top.

---

## Settings

`settings.json` describes one installation. **Setup** in Manage writes most of
it. `rules.json` holds what is the same everywhere: kinds of media, card
folders, caches, limits and the themes for describing. Rushes should never
decide what a file *is* in its code; that belongs in `rules.json`. A few places
still do ([known problem](ROADMAP.md#known-problems)).

| Setting | What it changes |
|---|---|
| `name` | the name shown on the pages. While the password is still the default, the default is this name in lower case, with everything but letters and digits removed. |
| `archive.local`, `archive.web`, `archive.url`, `archive.label` | where the archive and the web folder are on the archive machine, and the address people open Rushes at. The runner reads `archive.local` from `archive-path.txt` (see [Where things are kept](#where-things-are-kept)). |
| `archive.as_seen_from_helper` | where the helper's computer sees the archive (e.g. `/Volumes/VIDEO`) |
| `archive.runs_on`, `archive.own` | `mac` when Rushes runs inside Rushes Helper ([Rushes on this Mac](#rushes-on-this-mac)); `own`: the archive is a person's own drives, so caches that rebuild are deleted ([Editing caches](#editing-caches)) |
| `archive.database` | puts the catalogue outside the web folder |
| `sources` | servers and drives to copy from, with a name for each |
| `helper.mode`, `helper.label` | built in or external, and the helper computer's name |
| `organise.shelves`, `organise.departments`, `organise.kind`, `organise.add_at_ingest` | the folder in the archive the departments live in (the shelf), the departments and their folders, what they are called, and whether Ingest may add one. All set in Setup → Archive structure. The shelf has no default: until it is chosen, Ingest and the tidy-up wait. |
| `duplicates.never_keep`, `duplicates.card_dumps` | this archive's own folders whose copies are never kept, and where whole cards were once copied (chosen in Manage → Duplicates, from the folders the copies are in). What is true for every archive is in `rules.json → duplicates.never_keep`. |
| `organise.shape` | `one_place`, or `in_place`: the drives in `sources` are listed where they are ([Drives that come and go](#drives-that-come-and-go)) |
| `limits.disk_stop_free`, `limits.disk_warn_free` | stop copying below this free space (5 TB), and warn below this (8 TB). They override `rules.json`. |
| `rules.json → conditions` | when Overview warns: runner silent 3 min, a copy stalled 15 min, more than 100 cache files, more than 1 GB in Recently Removed |
| `proof.check_every_days` | how often each folder of the archive is read again (90) |
| `analysis.python`, `analysis.model`, `analysis.whisper` | the Python and the models used for describing. By default, a `venv` and a `qwen3vl8b` folder in `~/archive-pilot` if they exist, otherwise the models by name. |

A few switches are set by environment instead, for testing or unusual setups:

- in the helper: `FLOOR_GB`, `PARANOID`, `NAS_MOUNT`, `ARCHIVE`, `STATUS_DIR`
  and `VOLUMES_DIR`;
- in the runner: `RUSHES_URL`, `VLIMIT` and `IMPORT_LIMIT`.

**In the code:** `settings.example.json`, `rules.json`, `db/config.php`
(`limit()`), `setup.php`, `structure.php`, and in `ingest.py` `setting()` and
`rule()`.

---

## Finding footage

### Search

Type words into Search (the first tab). Every word must appear in the file's
path, its camera or its reel. Results are grouped by shoot (the folder that
names the event), not by folder or filename, because that is how people
remember footage.

- Narrow by kind: video, photos, audio, projects, sidecar files, other.
- **Show 200 more** loads the next results.
- Files sitting in Recently Removed show as "moved aside".
- Links work: `?q=` opens a search, and `?pull=` makes a pull the current one.

When footage has been described, a panel called **In the footage** shows the
matching moments: shots whose description, on-screen text, themes or tags
match, with the still the model looked at, and lines that were spoken, with
their time. Click one and its file's proxy plays from that moment, in a window
over the page.

Click a file to see what it is:

- resolution, frame rate, codec and length;
- when it was recorded, the camera, timecode and reel (read when its proxy is
  made);
- how many copies of it exist and where;
- whether it has a proxy, and if so a player with it;
- the shoot and year;
- where it lives.

**Playing** is always the proxy, never the original. Only a file the catalogue
knows can be played, and only its own proxy (`PROXIES/<its path>.mp4`): nothing
else on the archive can be asked for this way. It is sent in pieces as the
player asks, so a jump reads only from there, and the archive is read only while
someone plays.

Buttons: **Add to pull**, and **Copy path**, which copies the file's path
written the way your computer sees it.

The left column has shortcuts (Everything, Archive, Projects, Library). Each one
adds a word to the search; it does not filter by folder. It also shows your
last five pulls and recent searches.

Your browser keeps a few things for itself, on your computer only:

- recent searches;
- the current pull;
- your name for pulls;
- how your computer sees the archive;
- light or dark (the button in the top bar).

**In the code:** `db/find.php` (the page), `db/search.php` (the search),
`db/analysis.php` (`analysis_search()`), `db/thumb.php` (the stills), `db/play.php` (playing),
`head.php` (the top bar).

### Pulls

A pull is a named list of clips, made for handing to an editor.

- **Start one** from Search ("Add to a pull" → its name, your name). Every pull
  has its own link to send. **All pulls** lists them.
- **Order** the clips with ↑ ↓, **remove** them with ✕ (asks twice), and
  **rename** the pull in place.
- A clip that is no longer in the archive is struck through and left out of
  downloads.
- **Download**, written for the computer doing the downloading. First choose
  "This is a Mac", "This is a PC" or "Something else", so the paths match how
  that computer sees the archive.
  - **For Premiere (XML):** a Premiere bin with every clip. Each described shot
    and each line spoken becomes a marker at its frame. The first shot's
    description goes into the clip's Description, and the themes of all its
    shots into its notes.
    Markers need the clip's frame rate, which is known once it has a proxy.
  - **For Final Cut or Resolve (FCPXML):** an event of clips in FCPXML 1.8,
    which Final Cut Pro and DaVinci Resolve both open. Each clip carries the
    same markers, its first shot's description as a note, and its themes as a
    keyword. FCPXML needs each clip's length and frame rate, so clips without a
    proxy yet are left out, and named in a note at the top of the file.
  - **List of paths:** a text file.
  - **The files (zip):** up to 1 GB and 1,000 files, stored without compression.
    It is built in the web folder under a hidden name, and deleted once sent.

Everyone who can open Rushes can see and change every pull. They are the team's
lists. No password is needed.

**In the code:** `pull.php` (the page), `db/pulls.php` (create, add, remove,
move, rename), `db/pull-export.php` (the four downloads).

---

## Bringing footage in

The helper works through its list in this order: cards, then tidy-ups, then
folder copies, then describing. One thing at a time. Describing has a lane of
its own, but **copying goes first at the disk**: both read and write the same
archive, and a copy competing with the vision model would be slower, and longer
at risk of a drive dropping mid-file. While the helper copies (a card, a
folder, a tidy-up, a delivery), describing starts no folder, and a folder
already being described is held still (its process stopped, the model kept in
memory) and carries on where it was once the copy is done. Its status says
"waiting while copying uses the disk". On a Mac, **Find duplicates** and
**Delete All**, which read every byte of many files, wait the same way, and say
so in the job's log (`make_way()` in `runner.py`).

### A camera card: Ingest

1. **Plug the card into the helper's computer.** Rushes shows it in Ingest
   within a minute or less: the helper looks every 20 s, reports every 20 s, and
   the page asks every 5 s. The first time, the helper counts the card's files.
   A card is a drive with a camera's folder at the top: `DCIM`, `PRIVATE`,
   `CLIPS`, `XDROOT`, `AVCHD`, `CONTENTS` or `M4ROOT`. Other drives are copied
   through Copying instead.
2. **Choose the department** (or client, or project) and, for each day on the
   card, what was shot. A card's days come from its files' modification times.
   Rushes builds the destination; nobody types a path:

   `<shelf>/<department folder>/<YYYY>/<YYYYMMDD> <what it was>`

   A card holding several days becomes one folder per day. "What it was" loses
   characters a folder name cannot hold and is cut to 80 characters.
3. **Check the date.** A date before 2005, in the future, or on 1 January (a
   clock never set) must be corrected before starting. For a shoot more than a
   year old, a date field is offered.
4. **Press Start ingest, then press it again.** The button asks "Sure?" and
   shows where each day will go. Then follow it in "Moving now". When it has
   landed, "Find it" opens Search on it. **Queue another source** starts over.
5. **Safe to format?** When every folder a card went into has landed, Ingest
   says so for the card, plainly: **safe to format** (every file is on the
   archive, read back from its disk and checked against the card) or **do not
   format it yet** (how many files could not be copied; they are in the record,
   and queueing the card again tries them). It never says safe while anything is
   still copying or any file failed.

If no departments have been set up yet, or the folder they live in has not
been chosen, Ingest says so and points to Setup → Archive structure. Anyone who can open Rushes can ingest a card, without a password.
A new department can be added here too, if Setup → Archive structure allows it.

`queue.php` checks the request again: it accepts only a card the helper really
reports, a real date, and not one before 2005 or in the future (the 1 January
warning is the page's only). The helper checks a third time: it refuses a card it cannot see itself,
and records "refused".

**In the code:** `ingest.php` (the page), `queue.php` (the `ingest_src`
branch), and in `ingest.py`, `watch()`, `main()` (with `--into` and `--day`)
and `card_size()`.

### Upload from a phone

Photos and video from a phone (or any computer), without a card: **Upload**
(the phone menu, or **Upload files instead** on Ingest). Open like Ingest, to
anyone who can reach Rushes; on the office network, or from elsewhere through a
VPN (INSTALL.md).

- **Two things to say, as for a card in Ingest:** the department and what
  the shoot was. Who is the name that phone's browser was given (Activity →
  Who; "Uploading as Ana · not you?"), never an account; the day comes from the
  files, shown in words ("Shot on Tue, Oct 6, 2026"), with **change** for a day
  that is wrong. Rushes works out the folder, as for a card:
  `<shelf>/<department>/<year>/<date> <what it was>`.
- **On the phone's Home Screen** (Safari → Share → Add to Home Screen), Upload
  opens like an app. Away from the office it needs the VPN, like all of Rushes.
- **Nothing is changed or made smaller.** The files go up exactly as the
  browser hands them over, 4K and ProRes included. The page says to choose
  them from **Files**: from the Photo Library an iPhone may convert or shrink a
  file before it leaves the phone, which a web page cannot prevent. A video the
  iPhone re-encoded (its name starts with `trim.`) is pointed out in yellow.
- **Checked piece by piece.** Each file goes up in pieces of 4 MB, each with
  its SHA-256 taken on the phone (`db/upload.php`), and is kept only if it
  arrives the same; a dropped connection carries on from what arrived. Whole
  files wait in Rushes' inbox (`inbox/phone/<batch>`, never handed out), with
  their own fingerprint and the time they were shot.
- **Into the archive like a card.** The helper fetches each file, checks it
  again against that fingerprint, gives it back the time it was shot, and puts
  it in with the card copy: the same check, origin record (“a phone, uploaded by
  Maria”) and copy proof. Only when every file is in its place does the inbox
  copy go; otherwise it stays, and Activity says why. Paused is paused: it waits.
- **The receipt:** who, the shoot, how many files and how big, where they are,
  when, and whether they are in the archive yet, with **Send to myself** (the
  phone's own share sheet: Mail, Messages, Notes) and **Open in Search**. The
  link opens Rushes, so it works where Rushes does.
- **If Safari restarts the page** (an iPhone short of memory does this, often
  while it prepares a big video from Photos), the form comes back filled in and
  says the upload was cut off. A page cannot keep the files chosen: choosing
  the same ones again carries on from what arrived.

### Copying: a whole server in, or the archive out (the backup)

**Manage → Copying** starts with **From → To**, chosen from lists, never typed:

- **A source → the archive:** a whole server or drive brought in, folder by
  folder, once (below).
- **The archive → another drive:** the backup, every night or only when asked
  ([The backup](#the-backup)).
- Anything else (a drive straight onto another drive) is not something Copying
  does: bring it in, and back the archive up. With drives kept where they are
  (Setup 01), nothing is brought in, so only the backup is offered.

With nothing chosen, the page says what it is for. Cards and new shoots come
in through Ingest, not here.

#### Folders from an old server

For bringing over a whole server, folder by folder:

1. Add the server under **Setup → 03 Where footage comes from** (From's last
   line goes there).
2. Choose it under From, the archive under To, and press **List its folders**:
   the helper lists its folders ("sections"), shown beside what is already in
   the archive. Listing adds to the list, it never takes anything off it.
3. Tick folders, then press **Copy the ticked ones** and confirm.
   - **Tick everything** ticks them all.
   - Unticking folders that were waiting turns the button into **Clear the
     list**: those come off the transfer.
   - Ticking a folder that was skipped un-skips it.
   - Folders over 1 TB can be **split** into their subfolders (asks twice).
4. Overview shows the transfer: a percentage, bytes copied and already there,
   folders done, and the folder being copied now. Progress is saved, so it
   survives restarts on both sides.

A folder lands at `ARCHIVE/<source name>/<its path on the server>`, keeping the
server's layout exactly.

#### The backup

The archive, copied into a folder on another drive (`<drive>/Rushes backup` by
default), keeping its layout. Set under Copying: the drive (only one the helper
reports, never the archive's own), the folder's name, and **Every night** or
**Only when I press Back up now**.

- **When:** from 10 pm to 7 am, once a night, after anything else the helper has
  to copy (a card someone is standing there with comes first). **Back up now**
  asks for one run at once, besides. A run is named for its night (or its
  press), so it is done once; one that stops part-way carries on the next time.
- **How:** the same careful copy as everything else ([How a file is
  copied](#how-a-file-is-copied)), with `--backup` (`ingest.py`): written under
  a temporary name, read back from the backup drive and compared, and only then
  given its name.
- **Only adds.** A file already there with the same name is read in full, both
  copies (remembered fingerprints: a second night reads only what changed), and
  counts as backed up only if every byte matches. A *different* file with that
  name is never replaced: it is reported as could not be copied, and stays as
  it was. **Nothing on the backup drive is ever deleted**, even what has gone
  from the archive: a backup that followed deletions would lose a file twice.
- **Left out:** Recently Removed, and what the copy always leaves out (names
  starting with a dot, the system's own folders).
- **Refused:** a folder on the same disk as the archive (that is not a second
  copy), one inside the archive, or a backup drive the archive is inside. Said in
  the record, nothing copied.
- **Kept apart:** not in Search, and its record of every file goes to
  `_rushes/backup/`, not with the records of where footage came from (Relink and
  Reorganize read those).
- **Seen:** each run in the helper's history (backed up, stopped part-way, waited
  because the drive was not plugged in); on Copying, the last good run and the
  last few; on Overview, a **Backed up** tile, red when a nightly backup has not
  succeeded for two days; and a line in What runs by itself.

**In the code:** `db/backup.php` (the choice, `backup_due()`, `backup_line()`
added to the helper's queue by `db/helper.php`), `ingest.py` (`--backup`,
`backup_refused()`, the `backup` line in `watch()`), `db/admin.php` (`drawCopy`).

**In the code (bringing in):** `db/admin.php` (`drawMove`, `drawTransfer`), `queue.php` (the
folder branch), `db/transfers.php` (the saved transfer), `db/transfer.php` (the
helper reports progress), and in `ingest.py`, `watch()`, `sections()`
(`--sections`) and `source_root()`. `transfer_state.py` keeps progress on the
helper's computer.

### Matching earlier copies (trace)

An archive often holds copies made before Rushes existed. If `ARCHIVE` has any
top folder that is not named after a source, the helper assumes it might. So
the first time it copies from a source, it first matches what is there:

1. **It lists the originals** on the source, folder by folder. That list is
   kept for 7 days, so a stop part-way costs only the folder it was in.
2. **It reads each file already in the archive** and looks for an original of the
   same size whose first and last megabyte match.

Each match is written to a record ("copied before the record existed; matched
by size and both ends"). That record only says where to look: before a file
counts as already here because of it, every byte is compared (below). Nothing is moved. A Pause
stops it, and it carries on later. Once a source's match has finished, it is not
run again. The page shows "step 1 of 2" or "step 2 of 2" and how much is left.

**In the code:** `ingest.py`: `needs_trace()`, `trace()`, `trace_paused()`.

---

## How a file is copied

This is the core of Rushes. Cards and folders are copied the same way, except
where this section says otherwise.

**What is not copied:**

- names starting with a dot;
- `Thumbs.db` and `desktop.ini`;
- recycle-bin and system folders (`@Recycle`, `$RECYCLE.BIN`,
  `System Volume Information`).

**Before copying:**

- **Are the source and the archive both there?** If either has gone, the folder
  is marked "blocked" and nothing is marked done.
- **A source that suddenly looks empty is not believed** (folders only). If a
  folder that held files now shows none, the copy stops, rather than marking it
  done.
- **Is it already in the archive?** This check is for folders only. A card is
  copied whole into its new folder.
  1. First the records: a file Rushes copied before, still there at the same
     size **and the same in every byte**, is not copied again.
  2. Then the archive's file list, by size. The helper downloads it from Rushes
     and keeps a copy, fetched again when it is more than 7 days old (or with
     `--refresh-manifest`). If Rushes cannot be reached, the old copy is used
     and it says so. What the helper copied since comes from its own records,
     and is forgotten there once a new list has it. A file whose size appears
     nowhere is new, and nothing needs to be read.
  3. When the sizes match, Rushes reads the first and last megabyte of both
     files: that only finds which files to look at. When those match too, it
     reads **every byte** of both, and only then is the file "already here".
     Two clips can share their size and both ends and differ in the middle; the
     ends alone would leave footage out of the archive. This costs what reading
     the file to copy it would; the writing is what is saved. Fingerprints are
     remembered with each file's size, the time it last changed to the
     nanosecond and its own number on the drive, so a second look reads only
     what changed. (`--paranoid` is no longer needed: this is what always happens.)
  4. When the copy starts, each "already here" is checked again, the same way:
     the archive copy may have changed since the look.
- **Is there room?** Before starting a folder or a card, and again before each
  file, the helper checks free space against a floor (5 TB by default; on a
  Mac, a share of the drive). An external helper asks Rushes for the free space,
  because a Mac misreads very large network volumes (once a minute while
  copying, taking off what it copied meanwhile). If there is not enough, it
  stops, and looks again every 5 minutes.

**Copying one file:**

1. It is written under a temporary name of its own (`….<random>.part`), in 8 MB
   pieces, while its fingerprint is taken. That file is new: one already there
   with a similar name is never opened, nor written through a shortcut. The fingerprint is XXH3-128, or BLAKE2 on a computer
   without the `xxhash` library.
2. It is flushed to disk (fsync), and the date and permissions are copied.
3. If the original changed while it was being read (size or modification time),
   the copy is thrown away.
4. **It is read back from the archive and compared with the original's
   fingerprint.** On a Mac this read skips the computer's cache, so it really
   comes from the disk. Only if they match does the file get its real name,
   and only if nothing has that name by then: a file put there meanwhile (by
   Finder, an editor, another copy) is never replaced. The operating system's
   own "rename, but never over a file" is used (macOS `renamex_np`, Linux
   `renameat2`); on a drive without it, a hard link; on one without either, a
   look just before. The folder is then flushed too, so the name survives a
   power cut as well as the bytes.
5. **Every copy lands in the archive, where it really leads:** a folder in the
   archive that is a shortcut (symlink) to somewhere else does not count as
   inside, and nothing is copied through it. Tidy-ups move only where the path
   really leads into the shelf.
6. A file already there, with the same name, size and content, counts as
   "already here". Its content is **read in full**, both copies, before it
   counts: it may not be Rushes' own copy, and one dragged over in Finder can
   match at both ends and be broken in the middle. A *different* file with the
   same name is never overwritten: it is reported as a problem and left alone.
7. Names are matched whichever way their accents are written (NFC or NFD).

**After each file,** it is added to the search catalogue within seconds and to
the transfer's progress. The list of files still to add survives restarts, and
is retried every 10 seconds, up to every 5 minutes.

**If something goes wrong:**

- **A file that fails** is tried once more at the end of its folder. If it fails
  again, it is recorded as "could not be copied", and the rest of the folder
  carries on.
- **If the source or the archive disconnects,** or someone presses Pause or Skip,
  the folder stops part-way. It resumes later: files already in place match and
  are not copied again, and a cut-off file was only a `.part` and is redone.
  (The `.part` file is that copy's own; it is the only file a failed copy removes.)
- **A full archive** stops the folder at once.
- **A read or write that stops getting data.** A file can hang in the middle on
  a dying disk, and nothing can cut that short. So the helper watches: after 2
  minutes with no data, the pages say which file and for how long; after 10, it
  stops by itself, as after three stalls (see [What runs by
  itself](#what-runs-by-itself)). Restarting the helper's computer, or
  reconnecting the drive, frees the stuck file.
- **A network share that drops** is reconnected by the helper (on a Mac).
  - It first checks that the file server answers on its file-sharing port (445).
    Only then does it ask macOS to mount the share again, using the password
    saved in the keychain.
  - It tries every 2 minutes, and after 4 tries every 15.
  - After 5 tries it says to connect the share in Finder, and to tick
    "Remember this password".
  - This can be turned off in Manage and in Rushes Helper.

**What every copy leaves behind:**

- **An origin record** in `_rushes/origin/`, one line per file (copied, already
  here, failed), with where it came from and its size. It is never edited.
  Rushes reads these records to know what it has done, even after files move.
- **An ASC MHL generation** in the `ascmhl/` folder of the copied folder. This is
  the film industry's standard copy proof, which Hedge, Silverstack or the ASC's
  own tool can check. A stopped copy writes one too, for what landed. It is
  written only when the fingerprint is XXH3 (the `xxhash` library is present).
- **`Where this came from.txt`** in the copied folder.
- A line in `ingest-history.tsv`, which the helper sends to Rushes whole.

**Undo from the command line:** `ingest.py --undo` moves the files the helper's
*last* run copied into `ARCHIVE` to `ARCHIVE/_rollback/`. Files it copied
elsewhere, such as a card's, are left where they are. It is not in the pages.

**In the code:** `ingest.py`:

- deciding: `main()`, `walk()`, `load_manifest()`, `load_origins()`,
  `already_here()`, `digest()`;
- copying: `bring()` (inside `main()`), `read_back()`;
- recording: `mhl_copied()`, `mhl_write()`, `Origin`, `leave_a_note()`,
  `history()`, `send_file()`;
- space and drives: `free_bytes()`, `Dropped`, `reachable()`, `reconnect()`;
- a file that stops getting data: `fed()`, `watch_feeding()`;
- undo: `undo()`.

`transfer_state.py` keeps the list of files still to add to search. On the
server, `db/landed.php` takes the new files into search.

---

## Checking copies later

When the helper has nothing to copy, it checks, a few minutes at a time. It
never repairs or moves footage. All it writes are its own records: copy proofs,
"check" records, history lines, and the counts it sends to Rushes. Checking needs the `xxhash`
library, and does nothing without it. Manage and Rushes Helper each have a
**Pause checking** switch.

1. **Older copies.** Copies made before read-back existed, which no copy proof
   covers yet, are read again beside their originals.
   - Those that match get an ASC MHL record.
   - Those that differ are written in a "check" record and shown in Activity.
   - Originals that cannot be reached now are tried when the list is next
     rebuilt, at most once a week.
2. **How many copies exist, weekly.** For every file, it asks whether the original
   it came from is still there at the same size. It compares names and sizes
   only, and skips sources that are not connected. Overview then shows how
   much is kept twice, warns when files exist only in the archive, and each file
   shows its copies.
3. **The whole archive, every 90 days per folder.** Every file with a copy proof
   is read again and compared with its fingerprint, the way backup tools do.
   Damaged, unreadable or missing files go into a "check" record. When nothing
   is due, the helper does not look again for six hours.

The helper decides whether anything is due from its own notes, without looking
at the archive.

**In the code:** `ingest.py`: `check_due()`, `check_some()`, `_older_todo()`.
On the server, `db/copies.php` (the counts), `db/state.php` (the card) and
`db/admin.php` (`drawTiles`, the "kept twice" tile).

---

## Making footage findable

### The catalogue

The runner keeps a list of every file in the archive with its size
(`manifest.tsv`), and Rushes turns that list into the search catalogue.

A new list is refused if it is empty, or, once the catalogue holds more than
1,000 files, if it is less than half the size of the last. The old catalogue
then stays. (`manifest.tsv` itself is already replaced by then; [known
problem](ROADMAP.md#known-problems).)

Files the helper copies are added at once, without waiting for the next list.
The helper's reports are checked first: a path must be inside the archive, on
disk, at exactly the size it says.

The full list is rebuilt:

- when you ask: Manage → Setup → Jobs and tools → **Rebuild the file list**;
- by itself, after the runner's jobs that move files: moving duplicates or
  caches aside or back, and the old layout's undo. A tidy-up is done by the
  helper, which tells search about each move as it goes.

**Rebuild search** turns the current list into the catalogue again, even if
search already has it.

**In the code:** `runner.sh` (`build_manifest`, `build_index`, `refresh_state`),
`db/sync.php` (`sync_search()`), `db/import.php`, `db/landed.php`.

### Proxies

A proxy is a small H.264 copy of a video, with AAC sound. It is used for
describing and, later, for playing in search. Proxies are made on the archive
machine, for `mxf`, `mov`, `mp4`, `avi`, `mts`, `m4v`, `braw` and `r3d` files.
They are saved in `PROXIES/` with the same paths as the originals, and owned by
the same user as their original.

- **Settings** (Manage → Describe → Proxy settings): 720p or 1080p, at 4 or 6
  Mbit/s on the video chip, or made in software (higher quality, slower).
  Nothing below 4 Mbit/s is offered. The default is 720p at 4 Mbit/s.
- **Test before choosing.** "Test proxy settings…" lets you pick a clip. Rushes
  makes a 20-second sample with each setting and shows stills side by side.
  - The samples stay in `_rushes/proxy-test` on the archive, and the stills in
    the web folder, until the next test.
  - The test runs in an ffmpeg container, which can see the archive. Docker
    downloads the container the first time.
- **The video chip.** Rushes uses it through the ffmpeg container, when that is
  already on the machine. Otherwise it uses the NAS's own ffmpeg, which works
  in software only.
  - Kinds of file the chip cannot read are learnt, and go straight to the
    processor next time.
  - Proxies are made at low priority, and written under a temporary name first.
  - Files changed in the last two hours are left for later (they may still be
    arriving). For a folder on the prepare list, they are made by themselves on
    a run two hours later; otherwise, the next time proxies are made.
  - Before starting, Rushes checks there is room for 5% of the originals plus
    2 GB.
  - A build stops at once on "Permission denied" or "No space left".
- **Stop proxies** stops at once. The proxy being made is thrown away, and
  finished ones are kept.
- **Remake proxies** deletes that folder's proxies and makes them again. It is
  refused while proxies are being made, or while the folder is being described.
- **Test the video chip** (Jobs and tools) measures what the chip can do, with
  test encodes and one real clip from the archive.
  - It writes a report (`gpu-test.txt`) to the web folder and to `_rushes`.
  - It makes no video files.
  - It asks Docker for the latest public ffmpeg container each time it runs.

When a proxy is made, the original's details are read and kept: resolution,
frame rate, codec, length, the camera's clock, timecode, reel, and make and
model.

**In the code:**

- `proxy.sh` (planning and making);
- `runner.sh`:
  - the `proxy-test`, `proxy-plan`, `proxy-build`, `proxy-remake`,
    `proxy-stop` and `gpu-test` jobs;
  - the automatic start;
- `db/analyze.php` (the buttons);
- `db/prepare.php`: `media_import()`, `media_probe()`, `prepare_advance()`.

### Describing footage

The helper describes footage with two models that run on its own computer:

- **a vision model** (default Qwen3-VL 8B, 4-bit, through MLX on Apple chips),
  for the picture;
- **Whisper** (default large-v3-turbo), for speech.

It describes videos, photos and audio, including HEIC, DNG, MP3 and M4A. It
skips `_rushes`, `_duplicates`, the recycle bin, NAS thumbnail folders and
`_media-cache`. A photo gets one look. An audio file gets only speech. For a
video, the cuts and the sound come from its proxy when there is one.

**What happens to a video:**

1. It is cut into shots (PySceneDetect). Shots shorter than a second are joined
   to their neighbour.
2. For each shot, two stills are taken at 768 px from the **original**. If the
   original cannot be read, the proxy is used instead.
   - Stills are never taken from the first second of a shot (the first two
     seconds of a recording, while the camera settles), or its last half second.
   - A very short shot gets one still from its middle.
3. The model gets both stills together and is asked for:
   - one factual sentence;
   - the text on screen, copied exactly, one entry per line;
   - the shot size (extreme wide to extreme close-up);
   - how many people (none, one, two, few, crowd) and their broad ages;
   - indoor or outdoor;
   - the light;
   - the mood;
   - up to three themes, only from the list Rushes gives (from `rules.json`,
     or a list in `analyze.py` if Rushes cannot be reached);
   - a few tags.

   It is told to be literal, and not to guess who people are or where this is
   unless it is written in the picture.
4. An answer that is not valid JSON is repaired if possible, or asked for once
   more. If it still fails, the shot is counted as "could not be read" and the
   raw answer is kept.
5. Speech is transcribed in whatever language was spoken. Stretches Whisper
   itself rates as probably not speech are dropped. If transcription fails, the
   file is kept without speech and marked, and the next time the folder is
   described only its speech is tried again. Choosing a different speech model
   transcribes again, the same way, every file that had speech.
6. Morning, afternoon, evening or night come from the camera's clock, not from
   the model.

**What it writes:** one description per file in `_rushes/analysis/`. It is
named by a fingerprint of the file's content (its size plus first and last 4 MB),
so it survives moves and renames. Next to it is one still per shot.

- Every place the same footage is seen is added to its description.
- A file already described with the same vision model and question is skipped.
- A file that cannot be read at all, or that fails part-way, is not written.
  A folder where some files could not be described is tried again half an hour
  later, only those files, up to three times in all. After that it counts as
  described; asking for the folder again tries once more. The tries are noted in
  `describe-tries.json` on the helper's computer.

**No face recognition exists in Rushes.** People are only counted, with
broad age bands.

**Describing has its own lane.** It runs beside copying, one folder and one
file at a time, with its own **Pause describing** switch (Manage and Rushes
Helper). A folder skipped from Manage is left alone. Rushes takes new
descriptions into search while the helper is describing, and once a day
otherwise.

**Preparing folders** (Manage → Describe) puts the two steps in order:

1. You add folders to a list (or pick one in Finder). Each one is checked on the
   archive.
2. For each folder, the archive machine makes the proxies, then the helper
   describes it. One step after the other, by itself, never twice.
3. You can reorder the list (↑ ↓), **Start now**, **Try again**, **Remake
   proxies** or **Take off the list**. Each asks twice, except ↑ ↓.

The page shows the time left, measured from real speeds, and why any proxy
failed.

**In the code:**

- `analyze.py`: `media_under()`, `shots_of()`, `whole_shots()`,
  `sample_times()`, `frame()`, `Vision`, `PROMPT`, `tidy()`, `transcribe()`,
  `fingerprint()`, `themes_from()`;
- `ingest.py`: `describe_lane()`, `describe_folder()`, `analysis_tools()`;
- the server:
  - `db/prepare.php`: `prepare_table()`, `prepare_advance()`;
  - `db/analyze.php`;
  - `db/analysis.php`: `analysis_import()`.

---

## Projects in and out

Editors work as usual, wherever they like on their own computer. Rushes keeps
every Premiere project they save, with the files it uses and what they export,
without anyone pressing anything. *Premiere, on a Mac; Final Cut and Resolve
come later ([roadmap](ROADMAP.md)).*

**One folder per computer.** In Setup → Editors' work, **Add an editor's
computer** asks for a name (the editor's, usually), then gives a six-number code
with a Copy button; Rushes Watcher on that computer pastes it (**Paste the code
from Rushes**). The code is the computer's identity. Its folder is made by
Rushes, on the shelf, beside the departments, named by the name and the start of
its key, so two "Maria"s never share one:

```
<shelf>/Projects/
  Maria (a1b2)/                               one folder per editor's computer
    Kite Festival/                            one folder per project (the project file's name)
      Kite Festival 2026-10-02 1530.prproj    a dated copy at each close, pointing at the archive
      Media/                                  the files the project uses, from outside the archive
      Output/                                 what was exported into Output: the deliverables
  Stock Library/                              music, stock footage, sound effects: shared, stored once
    Music/  Stock footage/  Sound effects/
```

Nobody chooses a share, and editors need no share they can write to: the
archive stays read-only for people, and only Rushes writes it. `Projects` cannot
be a department's name, and Duplicates never moves anything in it: archived
projects point at those files.

**Rushes Watcher** runs on each editor's computer. It follows the rules every
Rushes program does:

- **Idle reads nothing.** While no editing program is open, it only asks this
  computer which programs are open, every 20 seconds, and tells Rushes it is
  there every 5 minutes.
- **While Premiere is open,** every 2 minutes it asks Spotlight (the index
  macOS keeps of every file) which Premiere projects were saved, wherever they
  are: Desktop, Documents, a drive. Nothing is walked through. The archive's
  own copies, auto-saves and caches are left out.
- **A project saved, then quiet for 3 minutes,** is read (only read) for the
  files it names: what was imported into it, not the rest of a Downloads
  folder. Files already in the archive are left where they are; caches and
  previews too. The rest are sent to Rushes now, so nothing is lost if the
  laptop is, unless already sent unchanged. Files it cannot find are said in
  its log and in Rushes. It makes an **Output** folder beside the project the
  first time, so exports always have their place.
- **Which kind:** guessed from the file: sound effects (`sfx`, `foley` in its
  path), voiceover (`vo`, `narration`: made for the project), other audio is
  music, video from a stock site's folder is stock, the rest is made for the
  project.
- **When Premiere is quit,** for each project saved: what is new in Output is
  sent, then a dated copy of the project, rewritten in memory to point at the
  archive's copies of what it uses (once the helper has placed them; after an
  hour, as far as they are). **The editor's own project and files are only
  read, never moved or changed**: the Output folder is the one thing it makes.
  If nothing is in Output, the log says so.
- Its log (`~/Library/Logs/Rushes Watcher/watcher.log`) says every step, and its
  last 40 lines go to Rushes with each report. If Rushes does not answer, it
  asks less and less, up to every 15 minutes.

**Sending.** Files go to Rushes over the network, into this computer's inbox
in the web folder (`inbox/<its key>/<batch>/`, never handed out by the web
server), in pieces of 4 MB, each request well under what a web server takes. A
file that stopped part-way carries on from what arrived. The last piece is
checked: kept only if it is the size and the SHA-256 fingerprint taken on the
editor's computer. Rushes refuses a file when the server has less than 20 GB
free beyond it (`limits.inbox_free_min`). When every file of a batch has
arrived, the Watcher sends its list, and Rushes writes the batch's
`batch.tsv` itself (who sent it, and its folder, are Rushes' own words), then
queues `deliver <key>/<batch>` for the helper, after cards and tidy-ups:

```
rushes-delivery 2
watcher     <its key>
host        <the computer's name>
folder      <its folder in Projects>
project     <its folder>/<project name>
shoot       <the shoot the project mostly uses, from the archive's footage it names, or empty>
file        <name>  <sha256:…>  <bytes>  <music|stock|sfx|project|output>  <where it was>
projectfile <name>  <sha256:…>  <bytes>  <project name>
end
```

**Taking it in.** The helper (`ingest.py --deliver`) reads `batch.tsv` from
Rushes, refuses the whole batch if it names another computer, another
computer's folder, or a file it cannot check, then, file by file, fetches it
from the inbox, checks it again against its fingerprint, and puts it in place
[the careful way](#how-a-file-is-copied):

- **Music, stock footage, sound effects** go into the stock library,
  `Projects/Stock Library/Music`, `/Stock footage`, `/Sound effects`, stored once:
  `_rushes/library.tsv` lists every fingerprint, and the same file again,
  under any name, for any project, points at the copy already there.
- **Things made for the project** go into `Projects/<computer>/<project>/Media/`;
  **exports** into its `Output/`; **the project file** beside them, dated, one
  per change (unchanged since the last one kept, nothing new is kept).
- A file of the same name already there gets ` (2)`; nothing is written over.

Every file is in the run's origin record and in a copy proof. Pause stops it at
the next file; it carries on from there. Then the helper tells Rushes where
each file is now (`db/delivered.php`, which takes only files really inside the
archive, at the size said), and **only then** is the batch removed from the
inbox. Otherwise the whole batch stays, and Activity says why.

**Seeing it.** Manage → Editors' projects lists every project the Watchers
report: its computer, last save, files from outside, files missing, what is in
the archive, and its state. Below, each editor's computer: what it is doing,
when it was last heard from, and the end of its log. Search has **Deliverables**
(everything in Output folders) and the **Stock library** as sections of their
own.

**No Finished button.** A project is ongoing until it is not: after some days
without a save (10, Setup → Editors' work) it is *resting*, said in Manage and once in the
editor's Watcher. Nothing moves. *(Moving a workspace folder aside after 90
days, `db/projects.php`, applied to a Projects share editors worked on. Projects
now live on each editor's computer and are kept as copies, so it is off unless
`projects.aside_days` is set.)*

## Keeping the archive tidy

### The archive's structure, and Reorganize

**Manage → Setup → Archive structure** holds the plan the archive follows
(it was Reorganize's part 1 until 0.12.23; Reorganize is now only the tidy-up):

- what your top folders are called (departments, clients, projects, or your own
  word), and which folder at the top of the archive they live in (the shelf),
  picked from the folders that are there, or **the archive itself**, with the
  departments straight at its top;
- the list of them, each linked to the folder it already has. Writing the plan
  renames nothing and breaks no Premiere project. A link counts only while its
  folder is on the shelf: after the shelf moves, a department whose old folder
  is not up there gets a new folder named exactly as the department, never a
  copy of the old folder's name (typos included). The old link stays, so the
  tidy-up still knows where that department's footage came from.

Building the list:

- Paste a list of names, and Rushes suggests a folder for each by close
  spelling. It saves nothing until you do.
- Catch-all names (others, misc, general, various, unsorted, …) and names a
  folder cannot have are refused.
- **Folders not in the plan** shows shelf folders no department uses.
- You choose whether new ones may be added at Ingest.

**The tidy-up** moves footage onto the shelf, into its department's folder. Two
kinds of footage are offered, each row saying which:

- **copied in:** what Rushes copied into `ARCHIVE`, grouped by where it came
  from (its record);
- **already in the archive:** every other folder of the archive outside the
  shelf (an old server's layout, a drive's own folders), from the catalogue,
  so press Rebuild the file list first if folders changed outside Rushes.
  Never Rushes' own folders (`ARCHIVE`, `PROXIES`, `_rushes`, `_duplicates`),
  hidden ones, the recycle bin, `Projects`, or loose files at the archive's
  top. These move as they are: the plan names each file the row counted, and
  only those (a file that arrived since is left for the next tidy-up).

Then:

- Rushes groups the files and suggests a department for each group, when a
  name in its path matches closely enough (a misspelt old folder too). You
  confirm.
- The helper then moves each file (a rename on the same disk, so it is instant).
  It never moves a file onto one that exists, and never moves anything from a
  folder still being copied. A plan that points outside the shelf is refused.
- Each file's proxy moves with it, and so does its copy proof (a new ASC MHL
  generation where it lands). A record left with no files is moved to
  `_rushes/ascmhl-moved`, never deleted.
- Search, every pull, and what describing found all follow each file to its new
  place.
- Folders left empty are removed, up to the top of `ARCHIVE` for copies, and
  up to the archive's top for folders already there (an old server's top
  folder goes once everything in it has moved). `.DS_Store` is the only file
  ever removed with them.
- A tidy-up stops at the next file when copying is paused, when the helper has
  stopped by itself, or when a move does not answer within 30 seconds. What it
  moved so far is recorded, and search is told; the rest is moved when work
  resumes.

**Put back** undoes a tidy-up from its record.

**Edit projects after a tidy-up** (Manage → Editors' projects):

1. Choose a Premiere project (`.prproj`), or an FCPXML or XML exported from
   Final Cut Pro or DaVinci Resolve. Your browser opens it and sends only its
   file paths (up to 50,000) to Rushes. The project itself never leaves your
   computer. In FCPXML and XML, files are written as addresses
   (`file:///Volumes/…`); they are turned into paths and back, written the way
   the file wrote them.
2. Rushes answers with where each clip went, and lists clips that are missing.
3. You save a corrected copy (`… (relinked)`), and open or import it. Mac and
   Windows paths both work.

Reorganize's list folds by top folder (`T7 / KITE FEST`): one line each, with one **Goes to**
for all of it and how many folders, files and how much it holds; opened only when its folders need
to go different ways (its line then says "different for each folder"). Beside it, the same Activity
column as Copying, Duplicates and Cache, so each move shows as it happens.

**In the code:** `setup.php` (the plan, its own forms inside Setup's), `structure.php` (Reorganize: the tidy-up), `db/tidy.php` (the proposal and
asking; `here_files()` for folders already in the archive), `ingest.py`
(`tidy()`: `map` lines for copies, `file` lines for folders already there), `db/relink.php` (Premiere paths), `db/moved.php` (search, pulls and
descriptions follow), and in `ingest.py` `tidy()`, `untidy()`, `move_proxy()`,
`mhl_follow()` and `clear_out()`.

### Duplicates

Rushes finds files that are the same file, keeps one, and **Remove** puts the
others in **Recently Removed** (below). The folder structure is kept, so
anything can be recovered.

**Manage → Duplicates** has one button to start: **Find duplicates**. It does
the scan and the plan (1 and 2 below) one after the other, and moves nothing.
Then the page leads with what it found ("7.1 GB in 3 extra copies"), in three
kinds, from the safest, so a person makes three decisions and not one per file
(`db/dupgroups.php`):

- **Extra copies in the same folder:** every other copy is beside the one kept
  ("IMG_3241 2.HEIC"). A few examples, and one **Remove**.
- **Copies in other folders of the same job** (the same top folder), or in
  folders that only pass files through (card dumps, `Copied_…`): a project may
  use one, so each job has **Show** and its own **Remove**.
- **The same file in different jobs:** left alone, never in the plan
  (`dedupe-left.tsv`). Each job's project may point at its own copy, and moving
  it would make that project's media go offline. Shown, so the space is known.
  A copy in a folder that only passes files through is the exception: it goes,
  as before.

**Show the files** opens one kind (or job): each file, the copy Rushes keeps,
marked **Keeps**, with an ⓘ saying why (on the shelf, where it belongs; the
others are in folders that only pass files through; the shortest path; or
your choice), and the copies that would go, with where the paths differ shown
bright. **Keep this one** beside any other copy makes it the one that stays,
in the plan itself; the other copies go instead. Each **Remove** writes down
its copies (`dedupe-pick.txt`) and carries out only those, which must also be
in the plan (3); the rest stay planned. **Recover** undoes it (4), **Find
again** looks again. Nobody has to choose a rule first: Rushes keeps the
shelf's copy over a card dump's.

Never compared: the system's own files (`._` sidecars, `.DS_Store`: rules.json
→ `system_junk`) and editing caches (rules.json → `cache`), which Cache looks
after. Premiere makes the same audio preview in every project that uses a
clip, and a Mac writes nearly identical `._` files on exFAT drives: neither is
a duplicate anyone should decide about.

1. **The scan** compares every file by content. On a NAS it is done by
   Czkawka, a separate duplicate finder running in a container on the archive
   machine. On a Mac, Rushes does it itself (`scan()` in `runner.py`): only
   files that share a size with another (from the file list, so nothing is
   walked), first their first and last 64 KB, then every byte (BLAKE2), so two
   files count as the same only when both were read in full and match. Each
   fingerprint is remembered with the file's size and date
   (`dup-hashes.tsv`): a scan again reads only what changed, and a scan
   stopped by Pause copying, or by the drive going, carries on where it was.
   Files it cannot read are left out and counted. The answer is written in
   Czkawka's form, so everything after it is the same on both.
   - **Find duplicates** (Manage → Duplicates) starts it, after asking twice.
     It takes hours the first time, and other jobs wait meanwhile (the upkeep
     does not).
   - It needs a container called `czkawka` with the archive mounted as
     `/storage`; its results are copied out with `docker cp`. Without the
     container, the job says so and does nothing.
   - The runner's comments show how to schedule it weekly.
2. **The plan** works out which copy to keep (`dedupe.sh`, the second half of
   Find duplicates). It moves nothing.
   - Each copy gets a weight from the rules, and the lightest copy is kept.
     The rules are written for the script when you press the button
     (`dedupe-rules.tsv`), and the plan lists them first:
     - for every archive, from `rules.json`: the recycle bin (1000), `Copied_…`
       folders (400), Premiere's Media Cache (300), doubled extensions in
       capitals such as `.MXF.MXF` (200);
     - for this archive, from `settings.json → duplicates` (set before 0.12.4 in
       Manage, kept): stopovers, whose copies are never kept (500), and folders
       of whole cards (450).
   - **Which copy is kept** is Rushes' own choice: the one on the shelf (card
     dumps lose; `KEEP_SIDE=project`). Between equal weights, the shorter path
     wins. A person changes it per group with **Keep this one**, not with a
     setting (`dedupe.sh` still takes `card`, `short` and `oldest`, unused by
     the pages).
   - Copies in the recycle bin are never kept and never moved.
   - Numbered image-sequence frames are never moved, even when identical,
     because removing one breaks the sequence.
3. **Remove** moves exactly the plan you were shown, as long as
   it was made from the same scan and with the same choice. Otherwise it plans
   again first.
   - If the rules changed since the plan was made, it plans again too.
   - Before moving each file, it checks the file still exists at the size the
     scan saw, and that the copy being kept is there.
   - Every move is written to a log that is added to, never emptied.
4. **Recover** returns every duplicate copy still in Recently Removed.
5. **Check Recently Removed** (Jobs and tools) goes through every file in it.
   - For each, it checks that its twin (the copy kept) is still in the archive
     at the same size. The answer is SAFE only if every file has its twin.
   - It names the files that do not.
   - It ignores editing caches, the recycle bin, and system clutter, and says
     which rule ignored what.

### Recently Removed

Where **Remove** puts duplicate copies and caches, on the same drive (a rename,
so nothing is copied): `_Recently Removed` at the top of the archive, and of
each drive kept where it is. It was called `_duplicates` before 0.12.4; that
folder is renamed by itself the first time the Mac app looks
(`migrate_holding()`), and the records of what moved follow it, so Recover
still finds every file. (A NAS's `runner.sh` still uses `_duplicates`.)

**Rushes never deletes anything by itself; when a person decides to, Rushes
does it.** Duplicates and Cache both end with a **Recently Removed** box, one
line per drive: how much is in it, since when, and **Delete All**. Rushes
suggests keeping things a week, in case something was needed; the line says
"suggested to keep 4 more days", then "ready to delete". Delete All is there
all the time, with **Sure?** on the button, which says when the week is not
over yet. Once confirmed (`empty()` in `runner.py`, the `empty` job):

- **a duplicate is deleted only if every byte of it is still the same as the
  copy that stays.** Waiting a week proves nothing about that copy: if it
  changed or broke since the scan, the one in Recently Removed may be the last
  good one, and it stays, said in the job's log and in Activity. One that
  cannot be read to compare stays too. The comparison is watched, not timed: it
  goes on as long as data keeps coming, however big the file, and a drive that
  gives no data for 2 minutes is walked away from (the file stays, Delete All
  stops, and it counts as the archive not answering). Pause stops it within
  seconds. Find duplicates reads the same way (`watched()` in `runner.py`);
- cache files are deleted (editing software makes them again);
- a file with no record of where it came from stays;
- the space comes back at once, Recover forgets **only the files that were
  deleted** (one that could not be deleted keeps its record, and its age), and
  Activity says who did it, how much, and what was kept and why ("Deleted for
  good from Recently Removed on Drive: 1,280 files (412.0 GB); 2 kept, 2 no
  longer the same as the copy that stays").

Check before deleting (`verify.sh`) answers the same way: safe only when the
copy kept is there and every byte matches (`cmp`), never on size alone. On a NAS, Delete All is not there yet: empty `_duplicates`
in File Station.

**In the code:** `runner.sh` (the `scan`, `plan`, `apply`, `undo` and `verify`
jobs; on a Mac `runner.py`: `job()`, `scan()`), `dedupe.sh`, `verify.sh`, `run.php` and `db/config.php`
(`dedupe_rules_write()`), `setup.php` (05).

### Editing caches

Editing software scatters caches through the archive: Premiere's `.pek`, `.cfa`
and `.ims` files, Capture One's cache folders, and others. They are listed in
`rules.json`, and all of them are rebuilt from the originals on demand.

**Manage → Cache** shows how much there is, with examples. It counts from the
catalogue, so it takes a second rather than a sweep of the archive. Things that
look like clutter but are not (such as Premiere's Auto-Save, an edit's only
rescue after a crash) are shown under "Never touched" and are never on the move list.

The page leads with how much there is ("3.9 GB of cache, in 2,410 files"),
with **Remove** and **Recover**; what is never touched is folded under
**Never touched**, with an ⓘ.

- **Remove** moves them into `_Recently Removed/_media-cache/` on their own
  drive, keeping their paths. Every move is written to `cache-moves.tsv`. It
  never takes anything from the recycle bin (that would undelete it) or from
  Recently Removed itself. Nothing is deleted: that is **Delete All**, pressed
  by a person (Recently Removed, above). (Before 0.12.4, a switch, These are
  my own drives, deleted caches at once; it is gone: one way everywhere.)
- **Recover** returns every cache file still in Recently Removed.
- On a Mac, each file is checked against `rules.json` again just before it
  is moved (`cache_rule()` in `runner.py`): the list is only where to look,
  and anything under Never touched is never touched.
- **Left alone** includes Capture One's adjustments (`.cos`, `.coa`,
  `.comask`, `CaptureOne/Settings…`) and Resolve's gallery stills
  (`.gallery`): they are someone's work, not a cache, and nothing makes them
  again.

**In the code:** `db/junk.php` (the list, and the own-drives switch), `runner.sh`
(the `cacheclean` and `cache-undo` jobs; on a Mac `runner.py`: `cache_clean()`,
`cache_undo()`, `cache_rule()`), `db/config.php` (`cache_groups()`,
`cache_sql()`).

### The old date-based layout

An early version could sort the whole archive by date. That was dropped. Only
its undo is left (the `organize-undo` job), so anything it moved can still be
put back. **In the code:** `organize.sh`.

---

## Manage

Manage is behind the password. Its left column: **Overview** (the dashboard:
how things are, and every on/off switch), then **Transfer** (Copying,
Reorganize, Duplicates, Cache: the pages that move files, each showing
everything first, asking first, recording every move, able to put it back),
**Footage** (Describe, Editors' projects) and **System** (Activity, Setup),
plus **Sign out**. "Copying" was called Transfers until 0.12.24. Each has its own icon. Search is the top bar's,
so the column does not repeat it. Activity in full hides the short feed beside
the other panes. Every pane uses the window's width; paragraphs stay a readable
width.

### Overview

**Tiles:**

- **In the archive:** files and size.
- **Free space:** warns at 80% full, alarms at 90%.
- **Kept twice:** how much has a second copy.
- One tile for each thing that needs you. Pressing it is the button.

**Cards** say what is true right now, in plain words, with a button when there
is something to do:

| Card | When |
|---|---|
| The helper machine cannot see the footage any more | a copy is blocked because its source is gone |
| A copy stopped part-way | the helper was copying and has said nothing for 15 minutes |
| The NAS is not picking up jobs | the runner has been silent for 3 minutes |
| The archive is full enough to stop copying, or Running low on space | free space is below the floor, or below the warning |
| Search needs a check, will update again, is updating, or is ready to index | the catalogue's state. The last one has **Build the first file list**. |
| N cache files are taking up … | more than 100 cache files (**Look at them**: Manage → Cache) |
| … is waiting in Recently Removed | more than 1 GB there, as last measured (after a job that moves files, or the `holding` job). **Check it is safe**, or "safe" once checked. |
| Moving the old server over: d of n folders | a server's folders are listed but not all copied |
| The helper is not running | a transfer is waiting and the helper has gone quiet |
| Rushes stopped reaching the VIDEO share by itself | the breaker tripped (**Try again**) |
| Anyone on your network can download … | the daily private-file check found something |
| The database did not pass its daily check | the daily check failed |
| A helper that is not the paired one asked for work | in the last hour |
| N updates are waiting to be installed, or Installing … | updates in `_rushes`, with the start of each fingerprint (**Install it**) |
| The system scratch space is N% full | `/tmp` is 80% full or more |
| N files now exist only in the archive | the weekly copy count found originals gone |
| Everything is in order | nothing else is true |

**Below the cards:**

- **The helper row:** which computer, how it runs, when it was last heard from,
  and its switches (see [Stopping things](#stopping-things)).
- **The transfer:** progress, and what is moving now.
- **What runs by itself:** five rows (the runner, reaching VIDEO, looking for
  updates, the database copy, the helper), each with how often it runs, when it
  last ran, and whether it stopped. Updates have **Check now**.

**Activity** (a side column, and its own pane) lists what happened, newest
first, and shows the runner's raw log.

### Activity

What came in, what went out, what changed, and who did it, in plain sentences,
newest first (`db/activity.php`). Manage → Activity and the Rushes app's
Activity page tell the same story; both can show one kind at a time: **In**,
**Out**, **Changes**, **Checks**, **Problems**. The raw log stays one click
below, for when something is wrong.

It is two records read together:

- **What the copier did** (`ingest-history.tsv`): cards and drives copied,
  projects taken in from editors' computers, phone uploads, tidy-ups, copies
  checked, describing, a copy that stopped part-way, a share that dropped off.
  A card with nothing new on it is not an event.
- **What people did, and drives coming and going** (`activity.tsv`, appended
  one line at a time, the last 5,000 kept): a pull made, a pull downloaded
  (for Premiere, Final Cut or Resolve, as a list, or as a zip), a project sent
  from an editor's computer, an editor's computer added or removed, a switch
  turned (copying, describing, checking copies, reconnecting), a job asked for
  in Manage that changes something (duplicates or caches moved to holding, put
  back, previews), and, on a Mac, a drive or the archive's own drive unplugged
  or plugged in again.

**Who.** Rushes has one password, not accounts, so a name is asked once in
each browser, before anything else: "Who is using Rushes here?" over the page,
which waits behind it until a name is given (there is no Not now: a record of
who did what needs everyone in it). The name is kept in that browser (the
`rushes_who` cookie, on that computer or phone only) and goes beside what is
done from it; **Who?** (or your name) at the top asks again, with Cancel, and
so does the phone menu. Giving a name is itself a line ("Started using
Rushes on a phone"). The name also fills in "Your name" when making a pull and
uploading from a phone. Editors' computers are known by the name they were
paired with, and the Rushes app on a Mac by the name of whoever is signed in
to that Mac.

A typed name is not proof: anyone can type any name. It is for knowing who did
what in a team that trusts itself, not for keeping anyone out (Who can do
what). Editors' computers, which send work into the archive, are the ones that
are paired; personal sign-ins can come with Watchers on a server (ROADMAP).
Searches and the clips someone only looks at are not recorded.

Names are people's: `activity.php` gives them only to someone signed in, or to
the paired Rushes app, and `activity.tsv` is never handed to a browser
(`.htaccess`, `router.php`, and the daily check of both).

### Jobs and tools

At the end of Setup, folded, with the admin password and **Take everything with
you** above it (until 0.12.23 these were their own pane). Buttons for running
things by hand, each asking twice:

- Rebuild the file list;
- Rebuild search;
- Check Recently Removed;
- Measure free space;
- Plan proxies (counts what is missing, makes nothing);
- Test the video chip.

Below them: **Admin password**, to change it. It needs the current password,
and the new one must be at least 4 characters.

**Take everything with you** (below the buttons) downloads, to the computer
you are on, what Rushes knows, in open formats:

- every file in the catalogue and what it is: kind, size, department, year,
  event, and from its proxy its size, frame rate, codec, length, camera clock,
  timecode, reel and camera (CSV);
- what describing found: every shot and line spoken, with its time (CSV);
- every pull, with its clips in order (JSON);
- where else each file exists, as last counted (CSV).

Each is read and sent as it goes, signed in only. Text a spreadsheet would read
as a formula is written with a `'` in front. The rest of what Rushes keeps is
already open files on the archive: the descriptions (`_rushes/analysis`, JSON),
the record of every copy and move (`_rushes/origin`), the copy proofs (ASC MHL).

A few jobs exist only for scripts and have no button: `reindex`, `holding`
(measures Recently Removed), `organize-undo`, and checking one folder or file
in Recently Removed. A page can start them only when signed in.

### Setup

Setup is a tree: each section is one line, closed, with how it stands (a green ✓ when it is done,
amber when something waits on it). The first one still to do opens by itself, marked **Start
here**, in this order: how media is organised, this archive, the archive's structure, the helper
(on a server), the admin password. Opening one section closes the others, and Archive structure's
four parts (what the top folders are and where they live, the list, folders not in the plan, how
a shoot is named) open the same way inside it. Each section saves with its own **Save**, and folds
back to its line; the next one still to do then opens. A link to a section (`/setup.php#password`,
`#plan`, `#tools`) opens it. **Tools** at the end: the admin password, Take everything with you,
and Jobs and tools.

- **01 How media is organised:** bring everything to one place, or leave media
  on its own drives: then 03 lists **the drives** Rushes looks after where they
  are ([Drives that come and go](#drives-that-come-and-go); on a Mac).
- **02 This archive:**
  - its name;
  - where it lives on this machine;
  - the address people open Rushes at. This is filled in from the address you
    opened Setup at. If it is a number that can change, Setup says so, tries
    the machine's own name (`<name>.local`) from your browser, and offers
    **Use the name**.
- **03 Where footage comes from** (or **The drives**, when media stays on its
  own drives): add a drive or folder (picked from what the archive machine or
  the helper actually sees, never typed), rename one, or remove one. For a
  drive kept where it is, each line also says whether it is plugged in (or
  since when not), and what is on it: files and size, footage, editing
  caches, copies (from the last duplicates scan) and what waits in its
  Recently Removed.
- **04 Helper:**
  - **built in**, or **external** with its name, and where it sees the archive;
  - whether it is running, and how many drives it sees;
  - for external: pairing (see [Pairing](#pairing-one-helper-only)) and how to
    install Rushes Helper. That means the download button, or a Terminal command
    to paste (`curl … ?install | sh`) and its remove command. For Windows, a
    Command Prompt line.
  - for built in: a warning if the archive machine has no Python.
- **05 Duplicates: which copy is kept:** this archive's own folders whose
  copies are never kept, and its card-dump folders, one name per line (see
  [Duplicates](#duplicates)).

Opening Setup lists the shares on the archive machine.

**In the code:** `db/admin.php`, `db/rail.php`, `db/state.php`, `setup.php`, `db/export.php`.

---

## The Mac app: Rushes

**Its name.** Until 0.12 the Mac app was called **Rushes Helper**: it only
copied footage, for a Rushes on a NAS. Since it can hold Rushes itself, it is
called **Rushes** (`mac/build.py`), with an ID of its own, `org.rushes.app`, so
macOS lists it as Rushes everywhere (Full Disk Access, Login Items). That
means macOS asks for its permissions once more. Opened where an older Rushes
Helper was set up, it takes its place by itself (`was_helper()`, then the usual
Installing): Rushes Helper's background service (`org.rushes.helper`) is
stopped and its plist goes to the Trash (`retire_old_helper()`), `Rushes.app`
goes into Applications with the same settings, and an old `Rushes Helper.app`
there, or in the Mac's own Applications folder, goes to the Trash. Its entry in
Full Disk Access can then be taken out with −. Below, "Rushes Helper" or "the
helper" is the part of this app that copies. **Rushes Watcher** is the
separate small app for editors' computers.

**Its window.** Once set up, the side panel is a menu of pages, like System
Settings: **Overview** (running or not, the archive, three numbers, what it is
doing, the last few things that happened), **Activity** (below), **Work** (its
switches), **Archive** (where the footage lives, Show in Finder, Change…, and
Remove at the bottom), **Other devices** (letting phones and computers in, the
editors' computers with when each was last heard from, and pairing, for a
Rushes on a server) and **Help** (updates, diagnostics, asking for help, no
support access). **Open Rushes** is the one coloured button, at the top of the
side panel, and waits ("Starting Rushes …") until Rushes answers. There is no
row of buttons at the bottom: the window is closed with its red dot. While
setting up, the side panel shows the steps instead. Rushes Watcher's window
has the same shape, with Overview, Activity, Work and Help.

**One copy, one window.** It comes as a disk image (`Rushes 0.12.2.dmg`): drag
Rushes onto Applications. It runs from wherever it was put (Applications, or
Applications in the home folder); opened from somewhere else (the disk image,
Downloads), it puts itself in Applications, or in the home folder's
Applications on a Mac where the person cannot write to Applications
(`_home_app()`). A copy the background service ran before, anywhere else, goes
to the Trash when it is set up (`other_copy()`), so there are never two. Opened
again while its window is open (Finder, the Dock, the menu bar, another copy),
the open window comes to the front instead of a second one
(`window_already_open()` in the launcher).

Rushes Helper is a Mac app with its own Python inside. Opened from Finder, it
shows one window. Started by macOS in the background, it runs the helper, and
shows its icon in the menu bar. Rushes Watcher, for editors' computers, is
built the same way from the same launcher and window (`mac/build.py watcher`),
and carries only its own work (`rushes_watcher.py`); neither app carries the
other.

### Rushes on this Mac

When the archive is a drive this Mac sees (an external drive, a server share,
a folder on one), Rushes itself runs inside Rushes Helper: no NAS, no server.
In **Where Rushes is**, press **Choose the drive…** instead of typing an
address, and pick the drive or folder in the window macOS opens.
A Rushes Helper already set up against a Rushes elsewhere (a NAS) gets there
with **Where Rushes is…**, at the foot of its window: the same step, where
**Cancel** goes back. `pick_local()` in `mac/rushes_helper.py` then:

- refuses a system folder, the whole startup disk, or anything that is not a
  folder (`archive_ok()` in `app/runner.py`);
- puts the pages that came inside the app into
  `~/Library/Application Support/Rushes/web`, the web folder (`pages_in()`:
  only the pages; settings, the database and the lists there are never
  replaced, and the app puts its pages in again each time it starts, so an
  updated app brings its pages with it);
- writes `settings.json` there: the archive, the web folder, the address
  (`http://127.0.0.1:8642`), the helper built in, Recently Removed
  `_duplicates` on the archive;
- pairs the helper with this Rushes (`helper-id.php`): they are the same app;
- writes `local.json` beside it (the archive, the port, other devices on or
  off), and carries on to Installing as usual, without downloading anything:
  the helper's code is inside the app.

**What runs.** The background helper starts Rushes beside itself
(`rushes_helper.py --server`, its output in `~/Library/Logs/Rushes/server.log`)
and Rushes stops with it: Rushes runs while Run in the background is on.
`server()`:

- runs PHP's own web server (a copy of PHP inside the app, one for each kind of
  chip) on the web folder, eight requests at a time. Every request goes
  through `app/router.php` first, because that server does not read
  `.htaccess`: hidden files and the private files `.htaccess` lists are
  refused, and only what a browser needs is handed out (pages, styles,
  scripts, pictures), with the files the helper reads (`settings.json`,
  `rules.json`, the file list `manifest.tsv` and a tidy-up's plan
  `tidy-….tsv`); never the database, the other lists, the logs or code.
  PHP's errors go to `php-errors.log` in the web folder;
- starts it again within 10 s if it stops, and when other devices are turned on
  or off;
- once a minute, does the runner's everyday work (`Runner.minute()` in
  `app/runner.py`): it writes `runner-alive.txt`, the archive's free space
  (`disk.txt`), the load (`load.txt`); asks for the search update
  (`import.php?part=web`, then `?part=video`); copies the database onto the
  archive once a day (`_rushes/db-copies`, one per weekday); once a day asks
  its own web server for the private files (`exposed.txt`); trims logs past
  5 MB; and does the jobs asked for in Manage (below).
  A new archive's first file list is made by itself, so Search has it within
  a few minutes of setting up; after that, as on a NAS, the list is rebuilt
  when asked (Manage → Jobs and tools).
  The jobs: **Rebuild the file list**, **Try again**, the duplicates jobs
  (the scan is done here, in Python: see [Duplicates](#duplicates); the plan,
  the move, putting back and the check are `dedupe.sh` and `verify.sh`, as on
  a NAS, written so they run with a Mac's own tools too), and the editing
  caches ([Editing caches](#editing-caches)). After anything that moves files,
  the file list is made again and Recently Removed measured. Proxies are
  not made on a Mac yet, and the old layout's undo has nothing to undo there:
  each such job is written in the log as not done.
- Every touch of the archive has a time limit (20 s; the search update 2 min,
  the database copy 10 min). Three that do not answer in a row stop it
  touching the archive until **Try again** in Manage, as on a NAS. Walked away
  from is not finished: the operation may still happen when the drive answers.
  So until it does, the same thing is not started again beside it (which would
  pile work onto a stalled drive), and each time it would have been, that
  counts as one more time the archive did not answer (`v()` and `pending` in
  `runner.py`; the helper's own `within()` does the same).
- **The file list** (`manifest.tsv` and `index.txt`) is one walk of the archive.
  macOS's own folders on a drive (`.Trashes`, `.Spotlight-V100`, …) and
  `@Recycle` are left out; symbolic links are not followed; a name with a tab
  or a line break is left out and counted. A folder that cannot be read means
  the list is not replaced, and a list less than half the last one is kept
  aside (`manifest-rejected.tsv`) instead of replacing it.

**When the archive drive is unplugged.** Rushes itself (its pages, the
catalogue, the database) lives on the Mac, in the app's folder, not on the
drive. So Rushes keeps working: Overview says first that the drive is not
plugged in (and stops saying anything about its free space, which is from
before), Search answers from the last list and marks each of its files
**not plugged in**, and the minute's work, the helper, duplicates, caches and
tidy-ups touch nothing on it and wait. Plugged in again, everything carries
on by itself; nothing is lost and nothing half-done is left.

**Opening it.** **Open Rushes**, in the window and in the menu, opens Search in
the browser, at `http://127.0.0.1:8642`. Until Rushes answers there (it is
starting, or Run in the background is off), the button says **Starting Rushes …**
and does nothing (`local_up()`).

**What the pages say on a Mac** (`on_mac()` in `db/config.php`, from
`archive.runs_on` in `settings.json`): Overview names Rushes Helper, not a NAS
or its scheduler, when the minute's work goes quiet; Setup shows this Mac's
address and the helper as Rushes Helper itself, without the NAS's choices;
there is no looking for new versions in `_rushes` (pages and code come with
the app). Free space: a NAS's floor (5 TB) would stop a laptop drive at once,
so Setup writes the drive's own: copying stops below 2% free (20 GB to
500 GB) and Overview warns below 5% (50 GB to 1 TB), each changeable in
`settings.json` → `limits`.

**Other devices** (a phone, another computer, over the office network or
Tailscale) are off at first: Rushes answers this Mac only. **Let other devices
open Rushes**, a switch in the window and in the menu, opens it to the network
at `http://<this Mac's name>.local:8642`, and then:

- while Rushes' password is still the first one, other devices are refused
  and told to set a new one in Manage;
- after that, every page asks them for that password first (the Manage
  password: one password, as in [Who can do what](#who-can-do-what)). Signed in
  stays signed in for a month on that device;
- except the three doors editors' computers use (`db/helper.php`,
  `db/pair.php`, `db/watcher.php`), which check their own: Rushes Watcher's
  paired ID, the six-digit pairing code, or the password. So an editor's
  Rushes Watcher can pair with a Mac's Rushes and deliver to it, while other
  devices are let in. The helper is this Mac's own (paired at setup), so any
  other helper is refused;
- the private files stay private, signed in or not.

`tests/test_router.sh` checks this door.

### Drives that come and go

For a person whose footage lives on several drives that are not all plugged
in at once (Setup 01: **Leave media on its own drives**; 03 lists them).
Done by Rushes on a Mac (`runner.py`); a NAS's runner does not list drives
yet.

- **Known by its own ID**, not its name: on a Mac the volume's UUID
  (`diskutil`), kept in `drives.json`. A drive plugged in under another name
  (or as "Films 1 1") is found by its ID, and another drive that takes its
  name is not taken for it (`drives()`).
- **Listed where it is:** each **Rebuild the file list** walks the archive and
  every drive that is plugged in, and keeps each drive's own list
  (`drives/<ID>.tsv`). The file list is the archive's and every drive's
  together, so Search covers all of them at once.
- **While a drive is away,** its last list is used: its files stay in Search,
  marked **on <drive> · not plugged in**, and the file's details say to plug
  it in. Pulls are made of archive files only, for now.
- **When it comes back** (or is just added, or turns up under another name),
  the minute's work notices and lists everything again by itself.
- **Duplicates:** the scan reads only drives that are plugged in. Copies
  within one drive are moved aside within that drive, into its own
  `_duplicates`, never from one drive to another (Manage → Duplicates →
  **Where**: the archive or a drive, each with its own plan, moves, check and
  Recently Removed: `dedupe-plan-<ID>.tsv`, `dedupe-moves-<ID>.tsv`,
  `verify-result-<ID>.tsv`, `holding-kb-<ID>.txt`). The same file on two
  drives is often the only backup: it is counted (`dup-across.txt`,
  `dup-summary.json`), said in Duplicates, and left alone.
- **Caches** on a drive that is plugged in go into that drive's own holding
  folder, or are deleted on a person's own drives, as on the archive.

**In the code:** `runner.py` (`drives()`, `build_manifest()`, `scan()`,
`root()`, `volume_id()`), `db/config.php` (`drives_seen()`,
`catalogue_roots()`, `drive_of()`, `drive_key()`), `db/sync.php`,
`db/search.php` and `db/find.php` (the drive on each result), `setup.php`
(`drive_report()`), `db/dupfolders.php` and `db/admin.php` (Where).

### Always in sight: the menu bar icon

While either app works, its icon is in the menu bar, the way Tailscale's is.
**No icon, nothing running:** if the Mac cannot show the icon, the work is
stopped, and its log says why (it tries again in 10 minutes).

**One icon, however many Rushes apps a Mac runs.** A Mac that is both the
helper and an editor's computer runs Rushes Helper and Rushes Watcher, each
doing its own work, but the menu bar has one Rushes icon: the Helper's, with a
section for each app (RUSHES HELPER, RUSHES WATCHER), each with its own state
and switches, Open Rushes once, and **Quit Rushes Helper and Rushes Watcher**.
The Watcher hides its own icon while the Helper runs, and shows it again if the
Helper stops, so there is always exactly one. (Rushes itself on a Mac, later,
joins the same menu as a third section.)

- **The icon says the state at a glance:** a film strip when idle, turning
  arrows when the helper is working (an eye when the Watcher is watching, an
  arrow up while it sends to Rushes), a pause sign when paused, a warning
  when it needs you (stopped by itself, not paired), and a crossed signal when
  it cannot reach Rushes. Its tooltip says the same in words.
- **The menu** shows what it is doing now (with how far), the last few lines it
  wrote, and **every switch someone changes day to day**: for the helper, Copy
  footage, Describe footage, Check copies, Reconnect network drives (ticked when
  on; they are Rushes' switches, so they wait while Rushes cannot be reached);
  for the Watcher, Watch projects. A switch acts at once and is turned back the
  same way; what it did is said at the top of the menu.
- Then the Rushes it talks to, **Open Rushes**, **Show the log**, **Collect
  diagnostics**, **Ask for help…**, and **Open Rushes Helper…** (or Watcher):
  the window, for anything that cannot be undone. Remove, and pairing, are only
  there, and the window asks twice.
- **Quit** stops it, like turning off Run in the background: it stays off,
  also after a restart, until it is turned on in its window.
- The menu is drawn by the launcher (`launcher.c`) from what a second Python
  process says, on this Mac only (127.0.0.1, behind a random key). It asks that
  process every 3 seconds; that process asks Rushes only when the menu is
  opened, and otherwise every 30 seconds, for the icon. The Watcher's state is
  on the Mac itself (`now.json`), so its icon asks Rushes nothing.
- Opening the app from Finder while it runs opens its window, as an instance of
  its own.

### The window

**The window** is a page served on this Mac only, behind a random key. If the
app cannot open its own window, the page opens in the browser instead. What Rushes
says (pairing, switches, what the helper is doing) is asked in the background
every few seconds (`ask_rushes()`), so the window and the menu never wait on a
Rushes that does not answer; until the first answer they say "Asking Rushes …".

**Setting up**, the first time:

1. **Welcome**, then **Where Rushes is.**
   - It guesses the address from where the app was downloaded from, or from the
     clipboard if it holds one.
   - Its first try at the address makes macOS ask about Local Network. If that
     is refused, a step explains how to allow it.
2. **Installing:**
   - it copies itself into `~/Applications` (replacing an older copy, and removing
     macOS's "downloaded from the internet" mark);
   - it downloads the helper's code from Rushes, and installs it only if every
     file is the one listed in a release signed with the Rushes release key
     (checked with the `release.py` inside the app);
   - it sets up the background service.
3. **Full Disk Access.** It shows where to turn it on, and moves on by itself
   when it is on. **Later** leaves it for another time.
4. **All set.**

**Every day after that**, the window shows:

- **The state:** running, paused, stopped, or stopped by itself (with **Try
  again**).
- **What it is doing,** from Rushes. If an action did not happen, it says why.
- **Pairing,** with a box for the six numbers, when this Mac is not the paired
  one.
- **Switches:**
  - Run in the background. Off stays off, even after a restart.
  - Copy footage.
  - Describe footage.
  - Check copies.
  - Reconnect network drives by itself.
- **Diagnostics:** one text file on your Desktop, shown in Finder. It holds:
  versions, the Rushes address, the switches, whether Full Disk Access is on,
  the names of the drives the Mac sees, the fingerprints of the helper's code,
  what Rushes says the helper is doing, and the recent logs. It holds no footage,
  and no passwords are written to it on purpose; read it before sending it to
  anyone. Nothing is sent.
- **Ask for help:** saves the diagnostics, and opens a new GitHub issue in your
  browser.
- **Support access: None.** There is no way for anyone to connect to this Mac
  through Rushes Helper.
- **Show the log** (opens Console), **Open Rushes**, **What it is made of** (the
  credits) and **Remove…**.

**The background service** starts at login and starts the helper again if it
stops (macOS waits 30 seconds between tries). If the helper's code is missing, it
downloads it from Rushes first, checked as in setup; if Rushes cannot be reached,
it tries again after 1 minute, then 5, then every 15. The Mac is kept awake, though not its
screen, while a copy or describing runs.

Pairing, and Full Disk Access being turned on, restart the background helper at
once, so it picks up the change. A copy in progress stops, and resumes as after
any stop.

**In the code:** `mac/rushes_helper.py` (`Window`, `serve()`, `install_service()`,
`service()`, `guess_url()`, `fetch_files()`, `diagnostics()`, `ask_help()`,
`pair()`, `stop_service()`), `mac/launcher.c`.

---

## What runs by itself

Rushes follows these rules for anything that repeats:

1. **Nothing to do, do nothing new.** An idle minute starts no work, and copies,
   moves and describes nothing. (It still measures free space, says it is alive,
   and the helper glances at the top of each network share every 10 minutes.)
2. **Every step has a time limit.** A step that cannot finish is abandoned and said.
3. **Never two at once.** If the last run is still busy, the next does not start.
4. **Failing slows down, or stops.** There are two cases:
   - **Asking Rushes,** which harms nothing, backs off: 20 s, 1 min, 5 min, then
     every 15 min. It never stops, so a laptop that leaves the office carries on
     when it is back.
   - **Touching a share** that does not answer is abandoned. After three in a row
     it stops, says so, and waits for a person to press **Try again**.
5. **Visible.** Overview → What runs by itself, and Activity.
6. **Pages ask only while someone is looking,** and ask less when the archive is
   slow.

### The runner, every minute

In this order:

1. **STOP:** if a file called `STOP` is in the web folder, it does nothing at
   all.
2. It writes the time to `runner-alive.txt`.
3. If the last minute's run is still busy, it stops here.
4. It reads whether copying is paused, and whether VIDEO has stopped answering.
   Below, "if VIDEO may be read" means neither is true.
5. If VIDEO may be read, it measures free space (`disk.txt`).
6. If the machine's scratch space (`/tmp`) is smaller than 256 MB, it mounts it
   again at 256 MB. It notes how full it is.
7. It asks its own web server whether the helper is built in. If it is, and is
   not running, and VIDEO may be read, it starts it.
8. **Proxies:** if a prepared folder needs proxies, nothing else is being made,
   and VIDEO may be read, it starts making them.
9. **Updates:** if someone pressed **Check now** or **Try again**, an install
   just finished, or there is no list yet, and VIDEO may be read, it looks in
   `_rushes` for waiting updates.
10. **The jobs** you asked for, in order, if no job is running already (one at
    a time).
11. **The upkeep,** whether or not a job is running, so a job that takes hours
    (a duplicate scan) never holds it up. It has its own lock, so it never runs
    twice at once:
    1. logs that only grow are trimmed (see [What Rushes deletes or
       moves](#what-rushes-deletes-or-moves));
    2. **the catalogue update,** from the web folder;
    3. **then, if VIDEO may be read, the look at VIDEO:**
       - new descriptions into search;
       - the prepare list's proxy check (each folder's proxies are looked for at
         most every 10 minutes);
       - queueing the next folder for proxies or describing;
    4. **the database copy** onto VIDEO, if there is a new one;
    5. **once a day,** the private-file check;
    6. **once a day,** editors' project folders moved aside, and any **Bring it
       back** within a minute (see [Projects in and out](#projects-in-and-out)).

A lock left by a run that was killed is taken over, and said in the log.

**The time limit** works like this. Every automatic touch of VIDEO above (free
space, updates, the look at VIDEO, the database copy) is started in the
background and abandoned if it has not answered in time: 20 seconds, two
minutes for the look at VIDEO, ten for the database copy. It is never waited
on, because a process stuck on a dying disk cannot even be killed. After three
in a row (a minute with no stall at all, upkeep included, starts the count
again), the runner stops touching VIDEO by itself. Overview shows "Rushes
stopped reaching the VIDEO share by itself", with **Try again**.

Proxies, once started, and the jobs you ask for (duplicates, tests, rebuilds),
run without this limit, and still run while copying is paused. The one
exception is installing an update: its copies go through the limit.

### Rushes, while someone is looking

- The top bar asks every 5 seconds what is happening. Manage asks every 4
  seconds, plus every 10 seconds about describing. Ingest asks every 5 seconds.
- A hidden tab asks nothing. After a slow or failed answer, the wait doubles,
  up to a minute, and the top bar says "the archive is slow". A question with no
  answer in 20 seconds is given up.
- These repeating questions read only the web folder, never the archive share.
  Some things a person opens or presses do read the archive once:
  - opening Setup or Reorganize;
  - opening the tidy-up;
  - adding a folder to the prepare list, or reordering it;
  - Premiere relinking;
  - a still in search, or playing a proxy;
  - a zip download;
  - downloading the helper's code or Rushes Helper.

  And while the helper copies or tidies, Rushes checks each file it reports is
  really there (every 10 seconds at most), outside the runner's time limit.

### The helper

| What | How often | Touches the archive? |
|---|---|---|
| Asks Rushes for work, and for the transfer's saved progress | every 20 s; if Rushes does not answer, 20 s, 1, 5, then every 15 min | no |
| Asks for Pause, Try again now, skip | at most every 5 s while waiting | no |
| Says what is plugged in (its heartbeat): the computer's name, its system, its version, whether describing is installed, and every drive it sees with its size, free space and top folders | every 20 s | no |
| Looks at local drives and cards | every 20 s, not while paused or stopped | local drives only |
| Looks at network shares (the archive, servers) | every 10 min, within 10 s; never searched for cards | yes, briefly |
| Is the archive there? Are the sources there? | only when there is work, within 30 s | yes |
| Remembers where network shares live (for reconnecting) | only when there is work, at most every 5 min | yes |
| Checking copies | when there is nothing to copy, a few minutes at a time | yes, reading |
| Describing | when a folder is queued | yes |
| Sends its status | when it changes, at most every 2 s while working | no |
| Sends files it copied to search | every 10 s while working, backing off to 5 min | no (Rushes checks each file) |
| When it starts: sends Rushes its copy history and section list (not while paused or stopped) | once | yes, reading, within 30 s |
| Looks for a new version of its own code | when Rushes' answer about the queue says it changed, between jobs | no (asks Rushes) |
| Looks for a new address for Rushes | every 5 min | no |

**A new address:** if Setup gives Rushes a new address and that address answers,
the helper moves to it. It also moves after three failed tries, if another
address it knows answers. It takes its saved progress with it (renaming its
`transfer-*.sqlite` files), saves the new address for Rushes Helper, and
restarts itself.

**Stopped by itself:** if a share does not answer within 30 seconds three times
in a row, or a file being copied or checked gets no data for 10 minutes, the
helper stops touching shares. It says so in Rushes Helper
("Stopped by itself", with **Try again**) and on the pages ("helper stopped ·
press Try again"). **Try again now** in Manage also clears it.

**Rushes Helper's window,** while it is open, asks Rushes three questions 1.5
seconds after each answer: the switches, the state, and pairing.

**In the code:**

- the runner: `runner.sh`, top to bottom (`v()`, `may_v()`, `survey()`,
  `takelock()`, the job loop, `upkeep()`, `importv()`, `dbcopy()`);
- the server: `db/import.php` (its two halves), `db/prepare.php`
  (`prepare_advance()`);
- the pages: `head.php` (`every()`, the wrapped `fetch`);
- the helper, in `ingest.py`:
  - `watch()` (the main loop), `control()`, `wait()`, `backoff()`;
  - `report_forever()`, `look_forever()`, `volumes()`;
  - `within()`, `stall()`, `stopped()`, `check_due()`, `fed()`,
    `watch_feeding()`, `trim_own_log()`;
  - `update_self()`, `learn_where()`, `rushes_elsewhere()`, `move_to()`,
    `remember_shares()`;
- `transfer_state.py` (`flush()`);
- What runs by itself: `db/state.php` (`$repeats`).

---

## Stopping things

| You want to… | Do this | What it stops |
|---|---|---|
| pause copying | Manage → **Pause copying**, or Rushes Helper → Copy footage off | The helper starts nothing new and stops the current folder at its next safe point. While paused, copying touches no share, no drive is looked at, and the runner leaves VIDEO alone. Nothing is lost. |
| pause describing | **Pause describing** (Manage → Describe, the helper row, or Rushes Helper) | The describing lane only. Files already described are kept. |
| pause checking | **Pause checking** (Manage or Rushes Helper) | Checking copies only, after the file it is reading. |
| stop the helper on a Mac | Rushes Helper → **Run in the background** off | It stops now and does not start at the next login. |
| stop everything on the archive machine | Manage → Overview → **Stop Rushes on the server** (beside Pause copying; also on the runner's line in What runs by itself), or put a file called `STOP` in the web folder | The runner does nothing at all, every minute, until **Start** (or the file is removed). What it is in the middle of finishes; the pages keep answering. |
| skip one folder | Overview → **Skip** (offered when a folder has stopped for 3 minutes) | That folder is taken out of the transfer and the work list. |
| look again now | **Try again now** | The helper stops waiting and looks again. It also clears "stopped by itself". |

**How buttons ask.** Buttons that start work, move files or take something
away ask twice, on the button itself. The first press turns it into "Sure? …"
and, for most, says beside it what will happen. A second press within about
five seconds does it. Rushes never uses a browser pop-up.

The helper's switches in Manage ask twice too. Some buttons act on the first
press because they change only something you can change back: Pause describing
on the Describe pane, the proxy setting, the order of a list, and in Rushes
Helper its switches.

**In the code:** `db/helper.php` (the switches), `db/config.php`
(`helper_control()`), `head.php` (`sure()`), `db/admin.php` (`drawHelper`),
`ingest.py` (`control()`, `watch()`, `describe_lane()`, `check_some()`),
`runner.sh` (`STOP`, `PAUSED`), `mac/rushes_helper.py` (`stop_service()`).

---

## Updates

There are four kinds of update, and they work differently.

**Scripts the runner uses** (`runner.sh`, `proxy.sh`, …) run with full rights,
so they always wait for a person:

1. Put the new version in `_rushes/scripts/` on the archive.
2. Manage → What runs by itself → **Check now**. Within a minute, Overview lists
   what is waiting, with the start of each file's fingerprint, so you can check
   it is the file you put there.
3. Press **Install it**, then **Sure?**. Everything on the card is installed
   together. The runner installs exactly the files listed: it checks the
   fingerprint of each copy it made, and refuses a file changed since.

**Pages** work the same way, through `_rushes/deploy/`.

- Only `.php`, `.html`, `.js`, `.css` and `.json` files are accepted, plus
  `favicon.ico` and `apple-touch-icon.png`.
- They must be at the top level or in `db/`.
- An installed page leaves the drop folder.

Rushes does not look for updates on a timer. They only exist when a person puts
them in `_rushes`, so it looks only after **Check now**, Try again, an install,
or when it has no list yet.

**The helper's own code** (`ingest.py`, `transfer_state.py`, `analyze.py`,
`release.py`) does not wait for a person, because it is **signed** instead:

- A release is the four files plus `release.sig`: their fingerprints and when
  it was signed, signed with the Rushes release key (Ed25519). That key stays on its owner's computer;
  `release.py` holds its public half. A release is signed with
  `python3 release.py sign <folder> <key file>` (DEVELOPING.md).
- Put the four files and `release.sig` in `_rushes`, and press **Check now** so
  Rushes lists their fingerprints.
- When that list changes, between jobs and never while describing, the helper
  asks Rushes for it. It knows without asking: every answer to the question it
  asks anyway (what to do next) carries a mark of the list (`X-Rushes-Code`). If a file differs from its own, it downloads it from Rushes,
  checks it against the fingerprint and that it is valid Python, then fetches
  `release.sig` and checks, with the `release.py` it already has (never the
  one downloaded), that the whole set as it will be is exactly the signed
  release, and not older than the release it has now (it keeps that release's
  `release.sig`). Only then does it replace its files and restart. Otherwise it
  says "not a signed release" (or "an older release") and carries on with the
  version it has.
- It only ever asks your Rushes server, never the internet. The download is
  plain http, but a change made on the way, on the Rushes server or on the
  share, without the key, is refused, and so is putting back an older release.
- A helper without `release.py` beside it takes no update: it says to install
  the new Rushes Helper. That app puts its own `release.py` beside a helper
  installed before signed releases, when it starts it. (A helper older than
  that check, with no check at all, takes one more update unchecked: the one
  that brings the check.)
- **The built-in helper** runs with full rights, so the runner starts it only
  from a signed release: it copies the files from `_rushes` into the web
  folder, checks the copy with the `release.py` installed there (an approved
  script, like `runner.sh`), not older than the copy it ran before, and runs
  that copy. If the check fails, it does
  not start, and Setup and the job log say why.
- When Rushes Helper is first set up, it downloads the same files and checks
  them the same way, with the `release.py` inside the app.

**The apps themselves** (Rushes and Rushes Watcher: the window, Python, the
launcher) update from inside the app, since 0.9.2. Where a newer one is found:

- **Rushes running inside the app, on this Mac** (0.12.7): there is no other
  Rushes to ask, so it looks at the releases of Rushes on GitHub
  (`RELEASES` in `release.py`): the newest release's version, and its
  `Rushes 0.12.8.zip` (the version in its name). Before 0.12.7 it asked itself, and always said it was up to date.
- **A Rushes elsewhere** (a NAS, or the Mac an editor's Watcher reports to):
  Rushes has one version number for everything (`app/VERSION`), so the version
  that Rushes is, is the one offered. The app comes from Rushes
  (`_rushes/Rushes.zip`, `Rushes Watcher.zip` on the archive), or, when Rushes
  does not have it, from that same version's release on GitHub.

Downloaded by the app itself, the new app is not marked as from the internet,
so macOS does not stop it the way it stops a `.dmg` opened from a browser:
nothing to download, open or allow. (The first time an app replaces itself,
macOS may ask to allow it under Privacy & Security → App Management. If it
refuses, the menu says so and the version in place is kept.)

- **Only when the person says so.** An app never updates itself. Once a week
  it asks Rushes whether there is a newer version (remembered on the Mac, so a
  restart does not ask again), or at once with **Check for updates** in its
  menu. When there is one, the menu and the window offer **Update to …**;
  nothing changes until it is pressed.
- **How** (`release.py` → `app_update`): the new app is downloaded from Rushes
  and unpacked aside. It is taken only if it is that version, the same app, its
  signature is intact (`codesign --verify --deep --strict`) and it is signed with
  the Rushes author's certificate (its fingerprint is in `release.py`, inside
  the app already running, so a server cannot change it). Then a small script
  of its own puts it in place of the old one (the old one back if that fails)
  and starts it again. Settings, pairing and the macOS permissions stay: it is
  the same signed app. Each step is in the log, and the window and menu say it;
  after it starts again, the menu says "✓ Updated to …", or why not
  (`update-result.txt`, written by that script).
- Opened from Downloads, a new app is still set up as on the first day.

- The Terminal command in Setup (`curl … ?install | sh`) downloads the new app
  from Rushes and unpacks it aside. It checks that the app's signature is intact
  (`codesign --verify`) and that it was signed with the Rushes author's
  certificate (its fingerprint is written in `db/helper.php`). Only then does it
  delete the old app, put the new one in its place, save the Rushes address, and
  open it. It does not stop the helper already running.
- The Terminal command's check catches an app swapped on the share. It cannot catch a change to
  the command itself: the command comes from your Rushes server over plain
  http, like the pages, so whoever controls that server or your network could
  change it. On a network you do not trust, download the app from Setup in the
  browser instead: macOS checks its signature when it opens.
- An app signed with the same certificate keeps its Full Disk Access and Local
  Network permissions.

**In the code:** `runner.sh` (`survey()`, `page_ok()`, the `update-scripts`
job), `db/helper.php` (`check-updates`, `scripts`, `?hash`, `?code`,
`?install`), `db/config.php` (`waiting_read()`), `db/state.php` (the card),
`ingest.py` (`update_self()`), `mac/rushes_helper.py` (`fetch_files()`),
`release.py` (`check()`, `read_sig()`, `verify()`), `runner.sh` (the built-in
helper's start, `copyhelper()`), `db/helper.php` (`?install`, `APP_CERT_SHA256`).

---

## Pairing: one helper only

Two helpers working from the same list would copy over each other. So one
helper is **paired** with Rushes, and only it is given work.

1. Setup → 04 Helper → **Pair a helper** shows six numbers. They work once, for
   ten minutes. Five wrong tries cancel them.
2. Type them into Rushes Helper on the Mac. No password is needed: the six
   numbers are the secret. Rushes gives that Mac an ID, which
   it sends with every request from then on. The ID is kept on both sides in
   plain text, in a file the web server never hands out (`helper-id.php`).
3. Any other helper that asks for work, or reports anything, is refused, and
   Overview names it by computer name and address.

A refused helper is given no work and copies nothing. It asks less and less
often. It still looks at its own drives, and still asks for updates and
addresses.

Pairing another Mac takes the work away from the one paired before (the button
asks twice). Until a helper is paired, every helper is given work, and Setup
says so. When Setup says the helper is built in, it asks from the archive
machine itself, and any request from the machine itself counts as the paired
helper.

Separately, only one helper can run on one computer at a time.

**In the code:** `db/pair.php`, `db/config.php` (`helper_pairing()`,
`helper_gate()`, `helper_refused()`), `setup.php` (the box), the doors that call
`helper_gate()` (`db/report.php`, `db/status.php`, `db/landed.php`,
`db/moved.php`, `db/copies.php`, `db/transfer.php`, `db/helper.php?queue`), `ingest.py` (`HELPER_ID`,
`fetch_queue()`, `only_one()`), `mac/rushes_helper.py` (`pair()`).

---

## Who can do what

Rushes is built for a trusted office network. Here is exactly what each person
can do today.

**Anyone who can open Rushes, without a password, can:**

- search, and read descriptions and stills;
- see everything Overview's data holds: paths, progress, the runner's recent
  log, and the command that starts the helper;
- read every file in the web folder that `.htaccess` does not protect. That
  includes `settings.json`, `rules.json`, `manifest.tsv` and `index.txt` (every
  path in the archive), `job.log`, the built-in helper's log, `proxy.log`, the
  duplicate, cache and proxy lists, `waiting.tsv`, `helper-control.json` and the
  tidy-up plans;
- make, change and download pulls, including a zip of the files (up to 1 GB at a
  time);
- ingest a card, and add a department at Ingest if that is allowed;
- download the helper's code and Rushes Helper;
- while no helper is paired: press the helper's switches (Pause, Try again now,
  …), and act as a helper.

On a Mac running Rushes itself, "anyone who can open Rushes" is whoever uses
this Mac, plus, only when **Let other devices open Rushes** is on, whoever
signs in with the password from another device ([Rushes on this
Mac](#rushes-on-this-mac)).

**Someone signed in to Manage can also:**

- run jobs (duplicates, proxies, tests, rebuilds);
- install updates;
- choose folders to transfer;
- reorganize and relink;
- change settings;
- pair a helper;
- change the password;
- download everything Rushes knows (Take everything with you).

A script can do most of this by sending the password with its request
(`pass`) instead of signing in: jobs, transfers, updates, pairing, tidy-ups and
relinking. Settings, Reorganize's plan and the password itself need a signed-in
session. A wrong password, typed at sign-in or sent by a script, waits a
second.

**The paired helper** gets work and reports what it did. Its own window can press
its switches. What it reports is checked:

- a copied file must be in the archive, at the size it says;
- a moved file must have left its old place and be in its new one;
- reports have size limits and a fixed shape.

**How Rushes protects itself:**

- **The password.**
  - It starts as the installation's name in lower case (`rushes`). Until it is
    changed, the sign-in page says so, and Overview shows a warning.
  - A changed password is kept only as a hash, in `adminpass.php`, a file that
    prints nothing if someone asks the web server for it.
  - Signing in starts a new session, and **Sign out** ends it.
- **The pairing ID and the pairing code** are kept in `.php` files the same way.
- **Private files.** The catalogue, its daily copy, the helper's work list, the
  refused-helper list and a zip being built are protected by `.htaccess`, which
  tells the web server never to hand them out.
  - Not every web server obeys `.htaccess`, so once a day the runner asks its
    own web server for the catalogue, its copy, the work list and the
    refused-helper list.
  - If any comes back, Overview says in red which ones, and how to fix it.
- **Only Rushes' own pages can press its buttons.** A browser says which page a
  request comes from. Any POST that says it comes from another website is
  refused before anything reads it, with or without a password, so a page
  elsewhere cannot make your browser pause the helper, ingest a card or change a
  pull. Scripts and the helper, which say nothing, are not affected. Rebuild
  search, the one action a link could start, only answers a POST from a browser.
  Sessions use `SameSite=Lax` cookies.
- **The runner checks every job again,** whatever the page already checked. A
  folder that would climb out of the archive (`..`) is refused. The holding
  folder for duplicates may be any folder inside the archive.
- **Pages never copy, move or delete footage.** They write down what was asked.
  A few read it: stills, a zip download, and checking that a file the helper
  reports is there.

**Known limits:**

- The pages use plain http, not https, so passwords cross the network
  unencrypted.
- Rushes does not check that the address a browser used is one of its own: a
  website that makes its own name point at your Rushes (DNS rebinding) is not
  stopped by the same-site check above.
- Anyone who can write to the web share can change the runner, which runs with
  full rights. Give write access to the web share to the administrator only
  (INSTALL.md).

All of these are on the [roadmap](ROADMAP.md#known-problems). To use Rushes from
outside the office, connect through a VPN. Do not put it on the internet.

**In the code:** `db/auth.php`, `.htaccess`, `runner.sh` (the daily self-check,
job validation), `db/config.php` (the same-site check at the top,
`helper_gate()`), `db/import.php`, `db/landed.php`,
`db/moved.php`, `db/report.php`, `db/status.php`, `db/state.php` (the cards).

---

## What crosses the network

**To the internet:**

- **Nothing about your footage.** Footage, descriptions, transcripts and stills
  stay on your machines.
- **Models, the first time.** If the vision model or Whisper is not already on
  the helper's computer, the libraries download it from Hugging Face the first
  time describing runs. The default Whisper model is always fetched by name. No
  footage is sent.
- **Ask for help** (Rushes Helper) opens a new GitHub issue in your browser. The
  page's address carries the Rushes Helper version, the macOS version and the
  processor type. The diagnostics file is saved on your Desktop and is *not*
  sent. You read it and decide.
- **Docker:** Test the video chip asks Docker for the public ffmpeg container
  each time it runs. The proxy test downloads it if it is not there yet.

**Inside your network, between your machines** (plain http):

- **Rushes and the browsers that open it:** the pages, stills, proxies being
  played, and downloads (pulls, exports).
- **The helper and Rushes:**
  - work, switches, its heartbeat (see the table above), status, history, files
    copied, moves and copy counts;
  - its first look at the archive's file list;
  - the settings, rules, tidy-up plans and its own code.
- **Describing** reads the theme list from Rushes.
- **The runner** asks its own web server (`127.0.0.1`) every minute: whether the
  helper is built in, and the catalogue update.
- **Reconnecting a share** knocks on the file server's file-sharing port (445)
  before asking macOS to mount it.
- **Setup** may try the archive machine's own name (`<name>.local`) from your
  browser.

**Only on the Mac, never sent:** to guess the Rushes address during setup,
Rushes Helper reads the clipboard and where downloaded files came from.

**In the code:** `analyze.py` (`Vision`, `transcribe()`, `themes_from()`),
`ingest.py` (`report_forever()`, `load_manifest()`, `reachable()`),
`mac/rushes_helper.py` (`ask_help()`, `diagnostics()`, `guess_url()`),
`runner.sh` (`gpu-test`, `proxy-test`), `setup.php`.

---

## What Rushes deletes or moves

Rushes never deletes anything by itself. Duplicates and caches are **moved**
to Recently Removed, and deleted only when a person presses **Delete All**
there (after Sure?; Rushes suggests waiting a week). Then it deletes them, and
Activity says who did.

### What it moves

- **A file list that looks incomplete** (less than half the last one) is kept
  aside as `manifest-rejected.tsv`; the last list stays.
- **Duplicates and caches** into `_Recently Removed`, and back on **Recover**; deleted only on **Delete All**.
- **A tidy-up** moves copied footage onto the shelf, with its proxies, and back
  on Put back. Copy proofs whose footage all moved go to `_rushes/ascmhl-moved`.
- **`ingest.py --undo`** moves the last run's copies to `ARCHIVE/_rollback`.
- **The old layout's undo** puts back what it once moved.
- **Project folders** on the Projects share into `_Moved aside` on the same
  share, after 90 days without a save, and back on **Bring it back**.
- **Rushes Watcher** changes a project file in one way only: when the editing
  program is quit, the paths of files that are now in the archive, after a
  backup of the project beside it in `Rushes backups`.

### What it deletes, and when

**On the archive share:**

- **Proxies of one folder,** when you press **Remake proxies**. They are made
  again straight after. This is refused while proxies are being made or the
  folder is being described. It is the only folder Rushes deletes as a whole.
- **The previous proxy test,** when you run a new one (`_rushes/proxy-test`, and
  its stills in the web folder).
- **Unfinished pieces:** a `.part` copy that failed or was interrupted, and a
  proxy that failed or was stopped.
- **After a tidy-up:**
  - folders left empty (only `.DS_Store` inside), below `ARCHIVE` or, after a
    Put back, below the department folders;
  - a copied folder's `Where this came from.txt`, when the folder empties, is
    added to the same note where the files went, or moved there.
- **An approved page** in `_rushes/deploy`, once it is installed.

**In the archive:**

- **A database copy** in `_rushes/db-copies` is replaced by the one made a week
  later on the same weekday.

**In the web folder:**

- each job file, when it is picked up;
- the old `.adminpass`, once moved into `adminpass.php`;
- the pairing code once used, and the refused-helper list on pairing;
- a batch an editor's computer sent (`inbox/`), once every file in it is in
  the archive, checked against the fingerprint taken on the editor's computer,
  and recorded by Rushes; a piece of a file that turned out wrong;
- the cached plan, on Remake;
- a zip once sent, and the holding-folder check's scratch folder;
- logs that only grow, once past 5 MB, keep their newest 1 MB: the built-in
  helper's log, `proxy.log`, the proxy lists (`proxy-built.tsv` and its errors,
  `proxy-speed.tsv`, `proxy-failed.tsv`); `proxy-folders.tsv` keeps each
  folder's last run. The job log keeps its newest 500 KB once past 2 MB. Undo
  logs and the media ledger (`proxy-made.tsv`) are never trimmed.

**On the helper's computer:**

- a downloaded file list that looks incomplete;
- its notes of files it copied, once the archive's new file list has them;
- its own log, past 5 MB, down to the newest 1 MB;
- its list of originals after 7 days;
- `stopped.txt` on Try again.

**On a Mac:**

- Rushes Helper's **Remove** deletes its background service.
- Setting up replaces an older copy of the app in `~/Applications`, and removes
  macOS's "downloaded from the internet" mark from it.
- The Terminal install command deletes the old app before putting the new one
  in place. The remove command deletes the app and its service.

**In the code:** `runner.sh` (`proxy-remake`, `proxy-test`, `update-scripts`,
`dbcopy()`), `proxy.sh`, `ingest.py` (`bring()` inside `main()`, `clear_out()`,
`mhl_follow()`, `undo()`, `load_manifest()`), `db/auth.php`, `db/pair.php`,
`db/analyze.php`, `db/pull-export.php`, `verify.sh`, `db/helper.php`
(`?install`, `?remove`), `mac/rushes_helper.py` (`remove_service()`,
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

The risks are listed in the [roadmap](ROADMAP.md#risks), each with what was
decided and where it stands:

- a disk stalling;
- two helpers;
- no backup;
- ransomware;
- a mistaken delete;
- running out of space;
- a damaged database;
- the model inventing things;
- public records.

This document describes only what Rushes does about them, in the sections above.
