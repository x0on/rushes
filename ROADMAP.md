# Rushes roadmap

What is proven, what is not, what is wrong today, and the order Rushes is being
finished in. What Rushes does now is in [HOW-IT-WORKS.md](HOW-IT-WORKS.md).

## Status

**October 2026: the first installation's NAS lost its RAID** (a second disk
failed during a rebuild; the volume went read-only). The footage was copied off
to another server, and Rushes is now built **Mac app first**: one app on a Mac,
with the archive on any drive that Mac can see. "Proven" below means proven on
that NAS; the Mac app has to prove each part again.

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
- Playing proxies in search, and a described moment playing from its time.
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

- Faces (see [Decided](#decided-and-why)).
- The describing tools in an installer (today they are set up by hand on the Mac).
- Rushes Helper for Windows.
- Live support for organisations with a commercial license (see
  [Decided](#decided-and-why)).
- **The safety bench** (agreed October 2026, next). Proof that copying loses nothing, before
  anyone is asked to trust it:
  - scripted torture tests, each repeatable: the destination unplugged mid-file, the app killed
    mid-copy, a full disk, one byte flipped in a finished copy, the original changed while it is
    read, a different file with the same name at the destination, accents written two ways, files
    over 4 GB, 100,000 small files, a share that drops. Every time: nothing lost, nothing falsely
    "done", and the problem said;
  - an outside referee on real footage: after Rushes copies, `rsync -rcn` (every byte of both
    sides) and an independent ASC MHL verify both report no difference;
  - every file left out on purpose (dot names, Thumbs.db, recycle bins) counted and shown;
  - the gaps already listed below (power cuts, path races, stalled reads) closed or said plainly;
  - one page of every test and its result, to show people.
- **Copying from a server over SSH** (designed October 2026, after the safety bench). During the
  NAS rescue, rsync over SSH was about twice as fast as the share and kept working when the share
  died. Rushes does not write its own network copier: **rsync moves the data**, Rushes does the
  checks around it.
  - **Off unless turned on:** Setup → Where footage comes from → "Allow copying over SSH" shows a
    third kind of source, "A server, over SSH". Its folders then appear in Copying like any other.
  - **Connected once, without a terminal:** the server's address, admin name and password typed
    into Rushes by the person; its fingerprint shown first ("is this your server?"); a key made
    on the Mac and installed on the server; the password forgotten. "Forget this server" removes it.
  - **rsync with the safe options:** `--whole-file` (no reading the old copy back to compare: the
    silent minutes that timed out on the rescue's section 25), `--ignore-existing` (never
    replaces), one shared connection, a dead line noticed in two minutes, waits and tries again.
    It only reads from the server.
  - **Rushes' checks after:** the server works out each file's fingerprint on its own disk
    (sha256sum or md5sum, built into QNAP), Rushes reads the copy back and compares; then the
    origin record and the ASC MHL, as for every copy.
  - Tell IT first (Cortex XDR may notice an app opening SSH connections). Signed helper code.
- Drives and cards stay with Rushes' own copier: there rsync has no advantage (both ends on one
  machine), and Rushes fingerprints while it reads, so a card is read once.
- **Send out, to approved destinations** (designed October 2026). For footage
  that has to leave fast, such as a shoot for the press that a station needs tonight.
  - **Destinations, set once by the admin** in Setup: a station's FTP or SFTP,
    a shared Google Drive or Dropbox folder. The address, login and folder are
    kept on the server, behind the Manage password, never shown again.
  - **One tick where the footage comes in** (Ingest or Upload): "Also send to…
    Channel 10 newsroom". The files go into the archive as always, and a copy
    goes out to that destination. The same **Send to…** sits on a receipt, a
    pull, and a folder in Search, with **Sure?** on the button: it leaves the
    building.
  - **The server sends, not a person's computer:** rclone (free, open source;
    FTP, SFTP, Google Drive, Dropbox, OneDrive, S3…), with progress shown and a
    line in the ledger's "Out": what, to where, by whom, when.
  - **Who hears about it:** the person who sent it gets an email with where the
    files are (on a Drive or Dropbox, a share link; on an FTP, its folder), to
    forward to the station, sent from one mail account Rushes uses (set in
    Setup). With no mail account set, the same message is shown with Copy and
    Send to myself. People give their email the way they give their name:
    typed, remembered on their phone, no accounts.
  - **IT sees the design first:** it is the one place footage leaves the
    network by itself.

## Known problems

- **The NAS's Setup pages and its install script still call the Mac app
  Rushes Helper** (renamed Rushes in 0.12), and an update of the app from a
  NAS looks for `Rushes Helper.app` (`release.py`, signed code: changed with
  the next signed release). It also takes an update only with the same app ID,
  so a Rushes 0.12 tried before the app had its own ID (`org.rushes.helper`)
  is replaced by hand, once.
- **A NAS still calls Recently Removed `_duplicates`**, and has no Delete All
  (`runner.sh`); describing on a NAS skips only the old name (`analyze.py`,
  signed code: changed with the next signed release).
- **From the safety review (0.12.5), still to do:**
  - a record of each move written before the move, not after (a power cut
    between a move and its record leaves the file moved and Recover not
    knowing it);
  - the containment check is made just before a write, not on the folder the
    write uses (a folder swapped for a shortcut in that instant is not caught);
  - a NAS (`runner.sh`, `dedupe.sh`) still has its own copies of these
    decisions; they should come from the same code as a Mac's;
  - without `xxhash` (an older app), copies are still checked, but no ASC MHL
    proof is written and the background checking of older copies does not run;
  - `ingest.py` does too many things in one file.
- **Activity's names are typed, not proven:** a name asked once in each
  browser. Personal sign-ins can come with Watchers on a server (step 4).

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
| No backup of the footage (RAID is not a backup) | A second machine with a one-way copy that keeps versions, mounted by nobody; Rushes shows when it last ran and counts it as a copy | Begun in 0.12.26: Copying backs the archive up onto another drive every night, adding only, never deleting, its last good run on Overview. Still to come: versions, and a machine nobody mounts |
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
| The archive machine ages out of security updates | Plan its replacement; keep the archive portable (the packages below) | Happened (October 2026): the NAS lost its RAID; the footage was copied off; Rushes is now Mac app first, with the archive on any drive |

## Version 1: what makes it a product for filmmakers

In this order of importance:

1. **A simple install, and updates you can trust.** One installer that checks
   the machine and sets everything up, with no Terminal. Updates are signed,
   say what changed, and can be rolled back.
2. **No lock-in.** Everything Rushes knows (descriptions, records, copy proofs)
   can be exported in open formats: CSV, JSON, XMP, ASC MHL. *Built: CSV and
   JSON in Manage → Setup → Take everything with you; XMP sidecars to come.*
3. **Round trip with every editor:** Premiere, DaVinci Resolve and Final Cut Pro
   (FCPXML). Pulls, markers and relinking for all three. *Built for all three;
   to be tried in Final Cut and Resolve.*
4. **Card offload you can trust:** a clear "verified — safe to format this
   card", and copying to two places at once. *Built: safe to format. To come:
   two places at once.*

5. **Projects in and out** (designed October 2026). *Built: Rushes' side
   (pairing editors' computers, their door, the projects list in Manage, the
   stock library in Search), the helper taking deliveries in, and the core of
   Rushes Watcher (Premiere, on a Mac), Rushes Watcher as its own app, the
   menu bar icon for both apps (built and signed; to be tried on a Mac), and
   resting; editors' work kept per computer (0.10). To come: one combined app for a
   single computer, Final Cut and Resolve.* Editors
   work as usual; Rushes keeps every project and everything it uses, without
   anyone pressing anything:
   - **One folder per editor's computer.** VIDEO, the archive, stays read
     only for people; only Rushes writes it. Beside the departments, Rushes
     keeps `Projects/<computer> (<id>)/<project>/` with dated copies of the
     project, `Media/` and `Output/`. The only thing chosen is the computer's
     name, when it is paired. No shares to pick, none editors write to.
   - **Rushes Watcher** on each editor's computer finds Premiere projects
     wherever they are saved (Spotlight), and, once a project has been quiet a
     few minutes, sends Rushes the outside files it uses, over the network.
     When Premiere is quit, it sends what is new in the project's Output
     folder (made beside each project) and a dated copy of the project
     pointed at the archive's copies. The editor's own files are only read,
     never moved or changed. It says what it did, every time, and keeps a log
     the editor can read in it (and Rushes shows the same, per project).
   - **The project file decides what is kept**, not the folder: only files the
     project uses are taken in. Previews, renders and caches are left; so is
     anything else in Downloads. Missing files are said at once.
   - **Where things go.** Reusable material (music, stock footage, sound
     effects) into a shared library, stored once however many projects use it,
     and found in Search as the Library. Things made for the project into its
     `Media/`; exports into `Output/`, found in Search as Deliverables.
   - **No Finished button.** A project is ongoing until it is not: after some
     days without a save (10, a setting) it is *resting*, said in Rushes and in
     the editor's Watcher. Nothing is moved or deleted.
   - **Always in sight:** the Watcher, and Rushes Helper too, live as a small
     icon in the menu bar (Mac) or the notification area (Windows). The icon
     says the state at a glance: idle, working, needs you, cannot reach Rushes.
     Its menu, like Tailscale's, holds everything a person changes day to day:
     the state and what it is doing now (with progress), the last few things it
     did, every switch (copy, describe, check, reconnect drives, run in the
     background; for the Watcher, pause watching), the pairing and the Rushes
     it talks to, Open Rushes, Show the log, Diagnostics, Ask for help, and
     Quit. Switches act at once and can be changed back; anything that cannot
     be undone (Remove, pairing another computer) opens the window and asks
     twice there. Nothing works without the icon being there.
   - Like every Rushes program, it uses little: it only watches and copies, so
     it runs beside Premiere or Resolve on any computer that runs them.
   - Rushes makes each project's folder (New project: department and shoot), in
     the same shape as the archive. Windows editors when Rushes Watcher exists
     for Windows.
   - Later, a Premiere panel (Adobe's UXP): search the archive from inside
     Premiere, and see what the Watcher brought in.
   - Later: two editors in one project at once (Premiere's shared projects and
     project locking). For now, a project is pointed at the archive when the
     editing program is quit on the computer that saved it: one project, one
     editor at a time. Resolve keeps projects in a database, which needs its
     own way in.

Later versions:

- camera card structures and RAW as one clip;
- rights and releases;
- selects, ratings and notes;
- teams, roles and safe remote access;
- long-term storage (LTO, cold cloud storage).

## Where Rushes will run

One Rushes, packaged three ways. **The Mac app comes first, and is the one most
people install.**

| | Mac (first) | Server (later) | Windows (later) |
|---|---|---|---|
| For | anyone: alone, or as a small team's main Rushes | a team's main Rushes, on a virtual machine or server | a Windows PC or server |
| The archive | any drive the Mac sees: a NAS share, a server, external drives; one main drive or many that come and go | a share the machine mounts | a share or drives |
| Pages | in the app's own window (in the browser until then); this Mac only, or also the person's other devices if they turn it on | in a container | bundled in the installer |
| Jobs | inside the app | in the container | a Windows service |

**Main and connected.** Each archive has **one main Rushes**: it keeps the
catalogue and is the only one that moves, renames or tidies anything in the
archive. Every other copy of Rushes on the team is **connected** to it: it
searches, brings in cards and phone uploads, and watches its editor's projects
(what Rushes Helper and Rushes Watcher do today), and hands everything to the
main one, never writing the archive itself. One app; which it is, is chosen
when it is set up. A NAS stops being where Rushes runs and becomes a drive
like any other.

## Order

1. **The Mac app, reading:** Rushes inside the app (its own PHP), the runner's
   work moved into the app, every path from settings; the archive is a drive
   the Mac sees. Catalogue and search, and Overview: nothing in the archive is
   changed. *Built (October 2026), not yet tried on a real Mac:
   Choose the drive… in Rushes Helper's setup, PHP and runner.py inside the
   app, other devices behind the password. Next: try it on a small folder,
   then on the whole copied archive once its copy is finished.*
2. **Organizing a main drive:** the plan (departments, years, shoots), the
   tidy-up for any existing folders (not only ones Rushes copied in), every
   move recorded and undoable, then relinking editors' projects. *Built
   (October 2026), not yet tried on a real Mac: the tidy-up takes folders
   already in the archive; duplicates (the scan done by the app itself) and
   caches (deleted on a person's own drives) work on a Mac.*
3. **Drives that come and go,** the report of what is on each (footage,
   caches, copies), duplicates into a holding folder. *Built (October 2026), not yet
   tried on a real Mac: drives known by their ID, listed where they are,
   searchable while unplugged; the report per drive in Setup; duplicates per
   drive, copies on two drives left alone. To come: pulls from a drive kept
   where it is, and a drive's own index on the drive itself.*
4. **Connected Rushes:** the same app set up as connected to a main one, for
   cards, phone uploads and editors' projects (today's Helper and Watcher). *Decided (October 2026): it is
   Rushes Watcher's job, grown: on each other computer it brings cards and
   drives in and keeps the editor's projects, and who brought what in is said.
   It never writes the archive: what it brings is sent to the main Rushes'
   inbox, as Watcher deliveries and phone uploads already are, and the main
   Rushes' own helper places it. So there is still one copier writing the
   archive, and the one-helper rule stays.*
5. **Its own window,** proxies and describing inside the app, the installer.
6. **The packages after the Mac:** server (a container, for a team's virtual
   machine), then Windows. The installer carries the
   whole describing stage (ffmpeg, shot detection, the models). It checks the
   machine first and shows progress while the models download. On managed
   computers nothing is a loose script: the app is signed and notarized, so IT
   approves one publisher once.
   On a NAS, the server package is also an app of the NAS's own (a QNAP
   package; Synology's later): installed and updated from App Center, with
   **Start** and **Stop** there like any other app, and its own line, name and
   icon in Resource Monitor, so what Rushes costs the machine is plain to see.
   Not before the editors' projects are tried end to end.
   Reaching Rushes from elsewhere, on a phone too, is any VPN's job: Rushes
   names one easy option in its guide (INSTALL.md) and bundles none. The Mac
   app answers only its own Mac until the person turns on **other devices**;
   then it answers the network too, and asks for a password on anything that
   is not that Mac.
   On a Mac, Rushes and Rushes Watcher come as a disk image (.dmg)
   with the app beside an Applications folder to drag it onto, as Mac apps
   usually do, not a zip (*built, 0.12.1: `mac/dmg.py`; the zip stays for
   updates from Rushes*). Installing is once per computer, by the person or by
   IT (a signed, notarized app can be pushed by the office's device
   management); Rushes never installs anything on a computer by itself.
   After that, updates come from Rushes, when the person presses Update.

## Set aside (October 2026), to come back to

Each needs its own discussion before it is built.

- **Search in both languages, from the describer:** Qwen writes each shot's tags in English and Spanish, with the obvious other words (drone → aerial, dron, vista aérea; abuelo → elderly, senior), and a words-only pass gives already described files the same. Whole-word search then finds them exactly; the related-word groups stay as the backup. Changes analyze.py (signed helper code).
- **Search by meaning (parked on the `wip-meaning` branch):** a small multilingual model (paraphrase-multilingual-MiniLM-L12-v2, 118 MB, from Rushes' own release) beside Rushes on the Mac. Tried on the pilot's 856 real shots (October 2026): learnt in 6 s, 2–3 ms a search; right for "abuelos", "elder", "old building", "sunset", "reunión", wrong for "drone" (badminton shuttlecocks; the 15 aerial shots missed) and "patineta eléctrica". bge-m3 (570 MB) fixed some and broke others. Not worth a new engine and process until the tags above are tried.

- **Versions found by what they show:** every version is described, so files whose shots match one for one (stills, descriptions, length) can be joined even when their names differ, and a name-based join whose footage differs can be flagged.
- **Long takes:** a take longer than 30 s is described as one shot today; it should get a still every so often.
- **Speech:** the speech model cannot be downloaded on some networks; let it be installed from a folder the person already has.
- **Backups, the next steps** (the first is in 0.12.26, Manage → Copying): a network share's login kept in the Keychain and the share found by its address, never by a fixed folder name; backing up one drive kept in place, not only the archive; a "Kept twice" count that includes the backup; a file changed in the archive kept as a new version on the backup rather than reported.
- **The working set:** copy chosen years or departments from the full archive onto a faster working drive (the QNAP after its rebuild), with Search saying where each file is.
- **The copy script from an old server:** what it learnt (a share coming back under another name, a file security software blocks, a folder that hangs) belongs in Rushes' own transfers.
- **A Making proxies switch** of its own beside Describing.
- **The folder table's counts:** the proxies column shows the last run only, and "could not be described" counts files whose speech failed.
- **Image sequences:** 24 or more frames in a row with the same name shown as one entry.
- **Projects:** the Projects section lists every project file, wherever it is.

## Decided, and why

- **On a Mac, Rushes is a Mac app, not a web service** (October 2026). One
  person, one laptop, drives that come and go: an icon in the Dock and a
  window, with the same pages inside it (in the browser until the window is
  built). By default it answers only that Mac: no address anyone else can
  open. **Other devices** is a setting the person turns on, for their phone
  through a VPN such as Tailscale: then it asks for a password on anything
  that is not that Mac, and works while the Mac is awake. The same pages as the server's, never a second set
  of screens, so a fix to one is a fix to both. Built in the order that risks
  least: catalogue and search (reads only), then describing, then a
  duplicates report, then reorganizing (the only part that moves files).
  The first thing it shows, once a drive is catalogued, is what is on it:
  footage, editing caches and render files, and copies of the same clip,
  each with its size, so a person sees what can go before anything moves. It
  looks in the laptop's own cache folders too (Premiere's Media Cache lives
  there by default), and adds the render folders of Final Cut and Resolve to
  `rules.json` once real drives show their names.
  What it does with them, on the Mac app:
  - **Caches on a person's own drives are deleted**, after the list and sizes
    are shown and one press of a button; what went is recorded. They rebuild
    from the originals, and moving them aside would free nothing. On an
    archive a team shares, the main Rushes moves them aside instead, as on a
    server. The `keep` list (auto-saves, project backups) is never touched.
  - **Duplicates are moved, never deleted**: into a holding folder on the same
    drive (a move within a drive is instant), only after both copies are read
    in full and match. They stay there until the person is sure and empties
    it; Rushes reminds them how much is waiting, and since when.
  - **Copies on different drives are not clutter**: often they are the only
    backup. Rushes shows them as "on 2 drives" and leaves them alone.
  - **Footage itself is never deleted**, here or anywhere.
  Drives are known by their own ID, not their name: an unplugged drive stays
  searchable, and Rushes says which drive to plug in. Between drives, a move
  is a copy that is checked; the original stays for the person to delete.

- **One main Rushes per archive, the rest connected** (October 2026): see
  [Where Rushes will run](#where-rushes-will-run). Two copies of Rushes
  tidying the same archive at once would undo each other's work; one in
  charge, the others handing it what they bring in, cannot.
- **Three programs, each with only what its job needs** (October 2026; now
  the roles of one app, main or connected):
  - **Rushes**, on the server: the pages, the catalogue, the runner.
  - **Rushes Helper**, on one computer: the archive work. Copies cards and old
    servers in, describes footage, checks copies. One per archive (pairing).
  - **Rushes Watcher**, on each editor's computer: watches that editor's
    projects and sends the outside files they use to Rushes, never writing
    the archive. No archive code, no describing, no models: what is not
    installed cannot be misused, and it stays small.

  One repository: the copying and checking code is written once and built into
  each program that needs it. On a single computer, one build holds all three.

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
