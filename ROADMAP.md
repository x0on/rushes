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

- The Rushes address for any installation: Setup fills it in from the address
  the page was opened at, warns when it is a number that can change and offers
  the machine's name when that works, and Rushes Helper learns every address
  Rushes has and follows it to a new one by itself, taking its saved progress along.
- Tidy-up: moving copied footage onto the organised shelf, recorded and undoable.
- Skip and "Try again now" during a real failure.
- Matching earlier copies to their originals: keeps the originals it has
  listed folder by folder, so a stop part-way costs only the folder it was in;
  the page shows step 1 of 2 and 2 of 2 and how much is left.
- Transfer history: patched to show copied and already-present for older
  folders; not yet one consistent history.

### Not built yet

- Premiere relinking after tidy-up.
- Proxies, then vision-model descriptions of the footage (the final stage; the
  descriptions also replace separately built previews).
- Rushes Helper for Windows.

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

