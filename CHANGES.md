# Changes

One number for all of Rushes: the pages, the helper's code, Rushes Helper and
Rushes Watcher (`app/VERSION`; see [DEVELOPING.md](DEVELOPING.md#versions)).

## 0.12.7 — October 2026

- **The menu bar menu, cleaner:** what it is doing now and the last thing that
  happened (from Activity, not its log); **Open Rushes** once, and **Show the
  Rushes window** for the app's own window; the switches; Show the log,
  Collect diagnostics and Ask for help in a **Help** submenu; the version with
  **Check for updates**; **Quit**, which says when it stops a copy.
- **Updates from inside the app, from GitHub:** Rushes running on a Mac looks
  at Rushes's releases on GitHub for a newer version, and **Update to …**
  downloads it, checks it is signed by the Rushes author, puts it in place and
  starts it again: no .dmg to download, no "Apple could not verify". A Watcher
  takes its Rushes's version from GitHub when its Rushes does not have the app.
  Before, Rushes on a Mac asked itself and always said it was up to date.
- After an update, the menu says it worked, or why not.

## 0.12.6 — October 2026 (the review of 0.12.5, and a workload review)

- **A failed copy takes no space off the count:** near the free-space floor, a
  retry was stopped as "full" when it was not.
- **Delete All and Find duplicates watch their long reads:** as long as data
  keeps coming, however big the file; no data for 2 minutes and the read is
  walked away from, the file stays, and Pause stops it within seconds.
- **The checker saves its place at most every 30 seconds**, not after every
  file: 1,000 files checked wrote 90 MB of progress before, 91 KB now.
- **Remembered fingerprints stay bounded,** and those read while copying are
  kept, so a card checked once is not read again.

## 0.12.5 — October 2026 (a safety round, from an outside review)

Each change has a test that does what the review did to show the problem.

- **"Already here" means every byte.** The size and the first and last
  megabyte only find which archive files to compare; a file is left out of a
  copy only when every byte matches. The same check is asked again when the
  copy starts, and before trusting an earlier record of where a file went.
  `--paranoid` is no longer needed.
- **A copy never replaces a file.** Its temporary file is its own (a random
  name, opened only if new), and the real name is given only if nothing has
  it by then (the system's own no-replace rename). Tidy-ups, Recover and cache
  moves use the same rename.
- **Only inside the archive, where it really leads.** A folder that is a
  shortcut to somewhere else does not count as inside: nothing is copied or
  moved through it.
- **Delete All compares first.** A duplicate is deleted only if it is still the
  same, byte for byte, as the copy that stays; if that copy changed, the one
  in Recently Removed may be the last good one, and it stays. Recover forgets
  only what was deleted. Check before deleting (`verify.sh`) compares contents,
  not sizes.
- **A stuck job is not started twice.** What did not answer in time may still
  happen; until it does, it is not started again beside it.
- **Copying goes first at the disk.** Describing starts no folder while the
  helper copies, and holds one already started until the copy is done. Find
  duplicates and Delete All wait the same way.
- **Free space is checked before each file**, not only when a folder starts.
- **A remembered fingerprint is tied to the file itself** (its date to the
  nanosecond and its own number on the drive). The first Find duplicates
  after this update reads the duplicates again, once.

## 0.12.4 — October 2026 (being tried on a Mac)

- **Duplicates, as in Photos:** one button, **Find duplicates**; then what it
  found, biggest first: the copy Rushes keeps and why, and **Keep this one**
  beside any other. **Remove**, **Recover**. No rule to choose first.
- **Cache in the same shape:** how much, **Remove**, **Recover**; what is never
  touched folded away. The "my own drives" switch is gone: nothing is
  deleted by itself.
- **Recently Removed:** where Remove puts things (the folder `_duplicates` is
  renamed by itself, and Recover still finds everything). How much, since
  when, a week suggested, then **Delete All**, with Sure?; Activity says who
  deleted what.
- **One scroll bar** on Manage's pages: what sits above a page makes it
  shorter, never the window longer.
- **Setup, simpler:** a finished section is one line with **Change**; This
  archive is name · drive · address, with Copy. No Helper section on a Mac
  (the app is the helper). Editors' computers is a list, with **Add an
  editor's computer** showing the steps only when pressed. The explanations
  are behind ⓘ.
- **The same words everywhere:** Remove, Recover, Recently Removed, Delete All.

## 0.12.3 — October 2026

- **The name is asked before anything else,** over the page, until it is given
  (no Not now): Activity can then say who did everything. Asked once per
  browser.

## 0.12.2 — October 2026

- **One copy, one window:** Rushes runs from where it was dragged, and the copy
  it made in the home folder's Applications before goes to the Trash. Opened
  again, its window comes to the front instead of a second one.

## 0.12.1 — October 2026

- **Rushes comes as a disk image** (`Rushes 0.12.1.dmg`): open it, and drag
  Rushes onto Applications, as Mac apps usually come. Each build carries its
  number in its name.
- **Upload from a phone asks what Ingest asks:** the department and what it
  was. The name is the one the phone's browser was given; the day comes from
  the files, shown, and can be changed.

## 0.12.0 — October 2026

- **Rushes on a Mac, with the archive on a drive it sees** (the first step of
  the Mac app, ROADMAP.md → Order 1): in Rushes Helper's setup, **Choose the
  drive…** instead of an address. Rushes then runs inside the app, with its own
  PHP; the minute's work is done by the app (`runner.py`): the search update,
  the file list, free space, the daily database copy. **Open Rushes** opens
  Search. Editors' computers (Rushes Watcher) can pair with it while other
  devices are let in.
- **Duplicates and caches on a Mac:** the scan is done by Rushes itself (no
  container): files of the same size, read in full, remembered so a scan
  again reads only what changed. The plan, the move, putting back and the
  check work as on a NAS. On a person's own drives, caches that rebuild are
  deleted (Manage → Cache → These are my own drives); otherwise moved aside.
- **Capture One's adjustments and Resolve's gallery stills are left alone:**
  they were on the cache list, but they are someone's work.
- **Overview and Setup speak of the Mac** when Rushes runs on one, with free
  space limits that fit a laptop's drive.
- **The tidy-up takes folders already in the archive,** not only what Rushes
  copied in: an old server's layout or a drive's own folders move onto the
  shelf into their department's folder (a misspelt old name is matched),
  recorded, undoable and relinkable as before; their old top folder goes once
  empty.
- **Drives that come and go** (Setup 01: Leave media on its own drives): each
  drive known by its own ID and listed where it is; an unplugged drive stays
  in Search and says to plug it in; it is listed again when it comes back,
  under any name. Setup says what is on each drive (footage, caches, copies).
  Duplicates move aside within one drive only; the same file on two drives is
  shown and left alone.
- **Rushes Helper is called Rushes** now: it holds Rushes itself, with an ID of
  its own (`org.rushes.app`), so macOS lists it as Rushes; it asks for Full
  Disk Access once more. Rushes Helper's background service and old copies go
  to the Trash when it is set up.
- **The Rushes app's window is a side panel of pages:** Overview, Activity,
  Work, Archive, Other devices, Help. **Open Rushes** is the one coloured
  button, and says **Starting Rushes …** until Rushes answers; there is no row
  of buttons at the bottom any more.
- **Activity says what happened and who did it,** in plain sentences: what
  came in, what went out (a pull made, a pull downloaded), what changed, copies
  checked, problems. Manage and the app tell the same story. A name is asked
  once in each browser (never an account); editors' computers are known by
  their pairing.
- **Rushes Helper's window opens at once,** whatever Rushes is doing: what
  Rushes says is asked in the background (a Rushes that did not answer made
  each look take 20 seconds). **Where Rushes is…** moves a Helper set up for a
  NAS to a drive on this Mac.
- **Other devices, if you want:** a switch lets phones and computers on the
  network (or Tailscale) open it, only after Rushes' first password is changed,
  and only signed in with it.

## 0.11.0 — October 2026

- **Upload from a phone:** photos and video straight into a shoot folder, the way
  a card goes in: your name, the department, what it was and the day; the
  files whole and unchanged, in checked pieces that carry on after a dropped
  connection; then the helper puts them in the archive like a card. A receipt
  at the end, to send to yourself.
- **The archive itself can be the shelf** (Reorganize 00): departments and
  Projects straight at its top. A department's link to a folder counts only
  while that folder is on the shelf, so a new folder takes the department's
  proper name, never an old shelf's misspelt one.
- **A file already at the destination is read in full** before a copy counts
  it as done: it may not be Rushes' own copy.
- **Upload after a Safari restart** comes back filled in and says how to carry
  on; departments are listed A to Z in Upload and Ingest.
- **The NAS's disks on Overview:** a RAID rebuilding (how far, how long left,
  from the system's own numbers) or missing a disk is said at the top; the
  runner reads it once a minute (/proc/mdstat), with what each turn costs.

## 0.10.2 — October 2026

- **Duplicates asks about folders where it matters:** Manage → Duplicates lists
  the folders holding the most copies, with how many would move and stay, and
  each is Normal, Stopover or Whole cards with one press. Setup no longer asks
  for folder names.
- The panel says when that look was already carried out (the numbers are
  then what moved, not what would), and fits a phone: names above the buttons.
- **Rushes on a phone** (on the office network, or from anywhere through a VPN
  such as Tailscale): the Mac apps' downloads and install steps step aside on a
  phone or tablet, with a line saying they are installed from a Mac; the top
  bar's slow note is short; the search hint fits.
- **One menu on a phone:** ☰ opens every page of Rushes, grouped (Find and
  bring in, Manage the archive, System), the current one marked; the bar says
  which page this is. Search's filter row fades at its edge to show there is more.

## 0.10.1 — October 2026

- **One Rushes icon on a Mac** that runs both apps: the Helper's menu has a
  section for each, and the Watcher hides its own icon while the Helper runs.
- **Setup 06, plain:** a Download Rushes Watcher button (or a link to send),
  name and pair, and every editor's computer in a list, with when it was last
  heard from and its last saved project.
- **The stock library lives in Projects** (`Projects/Stock Library`), beside the
  editors' folders, shared and stored once; nothing to set up.

## 0.10.0 — October 2026

- **Editors' work, kept per computer.** Editors save Premiere projects
  wherever they like. Rushes Watcher finds them (Spotlight) and sends Rushes
  the files they use, over the network, in checked pieces; when Premiere is
  quit, a dated copy of each project, pointed at the archive's copies, and
  what is new in its **Output** folder (made beside the project). Rushes keeps
  them in `Projects/<computer>/<project>/` beside the departments. Nothing on
  the editor's computer is moved or changed.
- **Only a name is chosen:** Setup → 06 asks the computer's name when pairing.
  The Projects and Deliveries shares are gone; editors write nowhere in the
  archive.
- **Deliverables in Search:** everything in Output folders, as its own section.
- Duplicates never moves anything in Projects or the stock library.
- **Switches in Manage, as in Rushes Helper:** Copy footage, Describe footage,
  Check copies, Reconnect network drives by itself, and Rushes on the server,
  each on or off with one line saying what off means. They act at once.
- **A share that goes away is written down:** Rushes Helper notes when a network
  share disappears from the Mac, whether it had touched it lately and what it
  was doing, whether the server still answers, and macOS's own messages from
  that minute (`~/archive-pilot/drops/`), so a drop can be traced.
- **Stop Rushes on the server from Manage:** beside Pause copying on Overview
  (and on the runner's line in What runs by itself), the same as putting
  `STOP` in the web folder.

## 0.9.3 — October 2026

- **Updates of the apps only when you say so.** No app updates by itself any
  more: once a week it asks Rushes whether there is a newer one (or at once,
  **Check for updates** in its menu), and offers **Update to …**; nothing
  changes until it is pressed.
- **No asking on a timer for the helper's code:** every answer Rushes gives to
  the helper's queue question carries a mark of the helper's code, and the
  helper looks for new code only when that mark changes.

## 0.9.2 — October 2026

- **The apps update themselves from Rushes.** When Rushes is a newer version,
  Rushes Helper and Rushes Watcher take the same signed app from it, check it
  (its version, and the Rushes author's certificate), put it in place and
  start again: settings, pairing and macOS permissions stay. By themselves
  between jobs (once an hour), or at once with **Update to …** in the menu
  bar or the window. No more downloading and replacing.
- **Pairing by copy and paste:** Setup shows the code in a box with a Copy
  button; the apps have **Paste the code from Rushes**. Setup says
  "Paired ✓" the moment it happens.
- **The menu bar icon is the Rushes mark,** dimmed when paused or cut off
  from Rushes, with "!" when it needs you.

## 0.9.1 — October 2026

- **Rushes Helper:** the box to type the pairing code is always in its window
  until this Mac is paired, even when Rushes is slow to answer; pairing waits
  up to 30 seconds for Rushes.
- **Rushes Helper brings the helper's code with it** (a signed release),
  installed when newer than the one in place: a new app works at once, even
  before Rushes can be asked.
- **Manage answers fast on a busy archive:** the archive's totals and the
  holding-folder check are worked out once per catalogue update, not on every
  look (a rebuilding disk made Overview and the Helper's window time out).

## 0.9.0 — October 2026

The first numbered version: everything installed on install day.

- **Projects in and out:** Rushes Watcher on editors' computers keeps every
  project and what it uses; the helper takes deliveries in; the stock library
  in Search; Manage → Editors' projects; resting, moved aside, Bring it back.
- **Always in sight:** Rushes Helper and Rushes Watcher show their state and
  switches in the menu bar; no icon, nothing running.
- **Trust:** the helper's code only updates to signed releases; pairing (one
  helper, any number of Watchers); pages refuse buttons pressed from other
  websites.
- **The archive's own folders are settings,** not code: the shelf
  (Reorganize → 00) and the duplicate rules (Setup → 05).
- Taking everything with you (open formats), playing proxies in Search,
  FCPXML pulls, relinking projects after a tidy-up.

Before 0.9.0, versions had no number; the apps said 1.0.
