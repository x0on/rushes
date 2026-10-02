# Installing and running Rushes

This is for the person who sets Rushes up and looks after it. What each part
does is in [HOW-IT-WORKS.md](HOW-IT-WORKS.md). This page is how to put it in
place, keep it up to date, and what to do when something looks wrong.

Today Rushes is installed by hand on a NAS (it was built on a QNAP), with Rushes
Helper on a Mac. One-step installers for a Mac, a server and Windows are on the
[roadmap](ROADMAP.md).

## What you need

**The archive machine** (the NAS or server that holds the footage):

- a web server with **PHP 8** and its **SQLite** extension;
- a scheduler that can run a script **every minute as root** (cron);
- `sh` (BusyBox is enough), `curl`, `find`, `stat`, `df`;
- for proxies: **ffmpeg**. An Intel video chip is used if the NAS has one.
  Docker with the `linuxserver/ffmpeg` image works too;
- for duplicates: **Czkawka** in a container (optional);
- only if the helper runs here ("built in"): **Python 3**.

**The helper's computer** (usually a Mac that can see both the footage and the
archive):

- **Rushes Helper**, which brings its own Python. Nothing else is installed by
  hand;
- the archive share, and any server you copy from, mounted in Finder.

**For describing footage** (on the same Mac):

- an Apple chip;
- a Python with `mlx-vlm`, `mlx-whisper` and `scenedetect`;
- `ffmpeg`.

The models download the first time they are used, unless they are already on
the Mac. Point Rushes at them in `settings.json` (`analysis.python`,
`analysis.model`, `analysis.whisper`).

## First install

1. **Copy the pages.** Put everything in `app/` into the web folder of the
   archive machine (on a QNAP, the `Web` share). Leave out `ingest.py`,
   `transfer_state.py`, `analyze.py` and `README.md`.
2. **Settings.** Copy `settings.example.json` to `settings.json` in the same
   folder and set at least:
   - `archive.local`: where the archive is on this machine (e.g. `/share/VIDEO`);
   - `archive.web`: the web folder (e.g. `/share/Web`).

   Setup in Manage fills in the rest.
3. **The helper's files.** Make a folder `_rushes` at the top of the archive
   share, and put `ingest.py`, `transfer_state.py` and `analyze.py` in it. Also
   put the runner's scripts in `_rushes/scripts/` (`runner.sh`, `proxy.sh`,
   `dedupe.sh`, `verify.sh`, `organize.sh`). That way the copies on the archive
   always match what is installed.
4. **The runner.** On the archive machine, as root:
   ```sh
   mkdir -p /share/Web/queue && chmod 777 /share/Web/queue
   echo "* * * * * sh /share/Web/runner.sh" >> /etc/config/crontab
   crontab /etc/config/crontab && /etc/init.d/crond.sh restart
   ```
   This is the QNAP form; on another system, add the same line to root's
   crontab. Within a minute, Manage → Overview → **What runs by itself** shows
   the runner ✓.
5. **Open Rushes** in a browser at the archive machine's address and go to
   **Manage**. The password starts as the app's name (`rushes`): **change it
   first** (Jobs and tools → Admin password).
6. **Setup** (Manage → Setup):
   - **02** This archive: its name, where it lives, and the address people open
     Rushes at (use the machine's name if it has one, not a number that can
     change).
   - **03** Where footage comes from: servers or drives you will copy from.
   - **04** Helper: *Built in* (Python on the archive machine) or *External*
     (a Mac). For a Mac, choose where the Mac sees the archive.
   - **05** Duplicates: your own folders whose copies are never kept, and where
     whole cards were once copied. Can wait until you use Duplicates.
7. **Reorganize** (Manage → Reorganize): what your top folders are, which folder
   in the archive they live in, and the list of departments, clients or
   projects. Ingest waits until this is done.
8. **Rushes Helper on the Mac.**
   - Setup → 04 → **Download Rushes Helper**. Open it, and give it the Rushes
     address.
   - Allow **Local Network** when macOS asks.
   - Turn on **Full Disk Access** when it shows you where. The window moves on
     by itself.
9. **Pair it.** Setup → 04 → **Pair a helper** shows six numbers. Type them
   into Rushes Helper. From now on only this Mac is given work.
10. **Build the first file list.** Overview offers it: **Build the first file
    list**. It takes a few minutes. After that, search works.

## Keeping it safe

- **Change the default password.** Overview keeps saying so until you do.
- **Only the administrator may write to the web share.** The runner lives there
  and runs as root. Anyone who can change it, or drop a job into `queue/`, can
  do anything on the machine.
- **Keep Rushes on your own network.** It uses plain http and one password.
  To work from elsewhere, use a VPN (on a QNAP, QVPN; or Tailscale). Do not
  forward its port to the internet, and do not expose the NAS's own admin page
  either.
- **Watch for the red card "Anyone on your network can download …".** Once a
  day the runner checks that the database and other private files cannot be
  downloaded. If they can, your web server ignores `.htaccess`. Either turn
  `.htaccess` on in its settings, or move the database (below).
- **Make the archive read-only for people.** Only Rushes (and the helper's
  account) should write to it. Projects, renders and caches belong on a
  separate share. This protects against mistakes in Finder and against
  ransomware on someone's computer.
- **Turn on snapshots** on the NAS, if it has them.
- **Keep a real backup.** RAID is not a backup. Use a second machine that
  receives a one-way copy with old versions kept, and that nobody mounts.

### Keeping the database private

If Overview says the database can be downloaded:

1. Put a file called `STOP` in the web folder. The runner pauses within a minute.
2. Move `rushes.sqlite` (and `rushes.sqlite-wal` and `rushes.sqlite-shm`, if
   they are there) to a folder the web server does not serve, and that the web
   server's user can write to.
3. In `settings.json`, set `"archive": { …, "database": "/that/folder/rushes.sqlite" }`.
4. Delete `STOP`. The next day's check should come back clean.

## Updating

| What | How |
|---|---|
| The runner and its scripts | Put the new file in `_rushes/scripts/`. Manage → What runs by itself → **Check now**. Then **Install it** → **Sure?** on the card that appears. |
| Pages | Put the new files in `_rushes/deploy/` (sub-folder `db/` for files in `db/`), **Check now**, **Install it** → **Sure?**. |
| The helper's code | Put `ingest.py`, `transfer_state.py` and `analyze.py` in `_rushes/`. The helper picks them up by itself within the hour, between jobs. |
| Rushes Helper (the Mac app) | Put the new `Rushes Helper.zip` in `_rushes/`. On the Mac, download it from Setup → 04 and open it. Its permissions stay. |

**An install that predates update approval** cannot show new pages or scripts
as waiting. Update it once by hand:

1. Put `STOP` in the web folder.
2. Copy the new `app/` into the web folder, and the helper's files and scripts
   into `_rushes`.
3. Delete `STOP`.

## Stopping things

| To stop… | Do this |
|---|---|
| everything on the archive machine | Put a file called `STOP` in the web folder. Delete it to carry on. |
| copying | Manage → **Pause copying** (or Rushes Helper → Copy footage off). |
| describing, or checking | Their own Pause buttons. |
| the helper on a Mac | Rushes Helper → **Run in the background** off. It stays off after a restart. |

## Restoring the database

The last seven days of database copies are in `_rushes/db-copies/` on the
archive, one per weekday.

1. Put `STOP` in the web folder.
2. Keep the damaged `rushes.sqlite` somewhere, in case it is needed.
3. Copy the newest good `rushes-<weekday>.sqlite` into its place, named
   `rushes.sqlite`. Delete `rushes.sqlite-wal` and `rushes.sqlite-shm` if they
   are there.
4. Delete `STOP`.

Search catches up from the archive by itself. Pulls and transfer progress are
as they were on that day.

## When something looks wrong

Overview says what is wrong in plain words. What to do about each message:

| Overview says | What to do |
|---|---|
| **not picking up jobs** | The runner is not running. On the archive machine, `crontab -l \| grep runner` should show the line from step 4. System updates sometimes remove it: add it again. Also check that there is no `STOP` file. |
| **Rushes stopped reaching the VIDEO share by itself** | A disk or the share stopped answering three times. Check the storage first (on a QNAP, Storage & Snapshots should say Ready). Then press **Try again**. |
| **The helper is not running** | Open Rushes Helper on the Mac. Is "Run in the background" on? Is the Mac awake and on the network? |
| **helper stopped · press Try again** | A share did not answer the helper three times. Check the drive or the network, then **Try again** (Rushes Helper, or Try again now in Manage). |
| **A helper that is not the paired one asked for work** | Another computer is running Rushes Helper. If that one should be the helper now, pair it (Setup → 04). Otherwise turn it off there. |
| **Anyone on your network can download …** | See [Keeping the database private](#keeping-the-database-private). |
| **The database did not pass its daily check** | Do not replace anything yet. Ask for help (Rushes Helper → Ask for help), then see [Restoring the database](#restoring-the-database). |
| **The archive is full enough to stop copying** | Free space, or lower the floor (`limits.disk_stop_free` in `settings.json`). |
| **The system scratch space is N% full** | `/tmp` on the archive machine. A restart empties it. |
| **the archive is slow** (top bar) | The pages are asking less often until it answers quickly again. Nothing to do unless it stays. |

**Asking for help:** Rushes Helper → **Ask for help** opens a GitHub issue and
saves a diagnostics file on your Desktop. Read the file before you attach it,
because issues are public.

## Removing Rushes

1. On the Mac: Rushes Helper → **Remove…**, then drag the app to the Trash.
   Its notes are in `~/archive-pilot`, `~/Library/Application Support/Rushes`
   and `~/Library/Logs/Rushes`.
2. On the archive machine: remove the cron line, then the pages from the web
   folder.
3. Rushes' records stay in `_rushes` on the archive, and the copy proofs in
   each `ascmhl/` folder. They are plain files, readable without Rushes.
