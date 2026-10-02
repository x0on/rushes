# Changes

One number for all of Rushes: the pages, the helper's code, Rushes Helper and
Rushes Watcher (`app/VERSION`; see [DEVELOPING.md](DEVELOPING.md#versions)).

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
