# What could go wrong — the risk register

Written after a disk failed and Rushes' own every-minute job made
a sick archive sicker. A pre-mortem: imagine Rushes has failed badly a year from
now, and ask why. Each risk says what would happen, what was decided, and where
it stands. Newest decisions are dated.

## How Rushes is built (the rules every part that repeats must follow)

1. **Nothing to do, do nothing.** An idle check touches no share and writes nothing.
2. **Every step has a time limit.** A step that cannot finish is abandoned and said.
3. **Never two at once.** If the last run is still busy, the next does not start.
4. **Failing slows down, then stops.** 20 s, 1 min, 5 min, 15 min — then it stops
   and says so on the page (a circuit breaker), and a person resets it.
5. **Visible.** The page shows what repeats, when it last ran, and whether it stopped.
6. **Pages ask only while someone is looking**, and ask less when the archive is slow.

Each rule gets a test that breaks things on purpose: a share that stops answering,
a NAS that does not respond, a second run on top of the first.

## Could lose footage

| # | Risk | Decided | Status |
|---|---|---|---|
| 1 | No backup: RAID is not a backup (fire, theft, a second disk during a rebuild, a mistaken delete). | A second server. Rushes writes to one place; a **one-way copy with versions** (deleted or changed files kept 30–90 days) goes to the second, which nobody mounts, ideally in another room. Rushes shows "last copied to the second server" and counts it as a copy. | When the second server arrives |
| 2 | Ransomware: one infected computer encrypts every share it has mounted with write access. Some families attack QNAPs on the internet directly (DeadBolt, Qlocker). | QNAP snapshots (read-only, a computer cannot change them); few accounts with write access; the second server with versions; the NAS never reachable from the internet; QTS kept updated. | After the rebuild |
| 3 | A mistaken delete or move in Finder. | The archive becomes **read-only for people**; only Rushes writes, with its own account. Projects, renders and caches go to a separate **WORK** share. Network recycle bin on, long retention. Ingest goes through Rushes. | After the rebuild |
| 4 | Running out of space: logs, records, descriptions and thumbnails grow for ever; the NAS's small /tmp fills. | Rotate logs, show growth in Overview, warn early. | To do |

## Could stop Rushes

| # | Risk | Decided | Status |
|---|---|---|---|
| 5 | A disk stalls and Rushes' own repeating jobs pile up on it. | The six rules above. Runner: STOP switch, no overlapping minutes, nothing on VIDEO while paused (done, not installed); time limits and circuit breaker (to do). | In progress |
| 6 | A QTS update removes the runner from the schedule or changes PHP. | Overview already says "not picking up jobs"; add the steps to restore it; check after every update. | To do |
| 7 | The database is damaged (power cut). Search rebuilds from disk; **pulls exist only in the database**. | A daily copy of the database in `_rushes`; a check at start. | To do |
| 8 | One Mac is the only helper; its local state and **the signing key** live only there. | Keep the helper's state on the archive; back up the signing key somewhere safe (encrypted). | Key: this week |
| 9 | A macOS update or IT security software blocks the helper. | The helper says "blocked" plainly; the app goes through IT's approval once. | Partly done |
| 10 | Two helpers at once (a second Mac, or two copies on one) copy over each other. | **Pairing**: Setup shows a one-time code, the helper and Rushes exchange an ID, only the paired helper gets work, others are refused and named on the page. Every helper door checks the ID. The one-per-Mac lock stays. | To do |
| 11 | Python packages or models change and describing breaks after an update. | Pin exact versions in the installer; keep model files locally. | With the installer |

## Could mislead people

| # | Risk | Decided | Status |
|---|---|---|---|
| 12 | The vision model invents things and someone trusts them. | Label descriptions as machine-made; show how sure it was. | To do |
| 13 | Footage, transcripts and AI descriptions may be public records (Florida, Chapter 119); some footage shows minors or sensitive meetings. | Ask the city's records custodian before descriptions are shared widely. | To ask |

## Security

| # | Risk | Decided | Status |
|---|---|---|---|
| 14 | **The deploy folder**: anyone who can write to VIDEO can publish a page on the NAS's web server within a minute. | Pages need the same "Install it — Sure?" approval as scripts. | First, with the hardening |
| 15 | Default admin password; plain http; anyone on the network can open Manage. | A real password required; pages served to the local network only. | To do |

## People and age

| # | Risk | Decided | Status |
|---|---|---|---|
| 16 | Only one person knows how it fits together. | The app explains itself; a one-page "if Alejandro is away" sheet. | To do |
| 17 | The archive machine and its disks age; security updates will end. | Plan its replacement within a year or two; keep the archive portable (the Mac and server packages). | To plan |

## The single-computer version (Package A)

Rushes running on one Mac that reaches the archive by network or cable: no
runner on the NAS, but "a share stops answering" stays the first failure, so
the six rules apply there just the same.
