# Rushes — the app

**Status: in development.** Working, used daily, not finished. Expect changes.

## What's here

- `db/find.php` — search the archive, collect clips into Pulls
- `ingest.php` — copy camera cards into the archive, one folder per shooting day
- `pull.php`, `db/pulls.php`, `db/pull-export.php` — Pulls: shareable clip lists, downloadable as a Premiere XML, a list of paths, or a zip
- `db/admin.php`, `structure.php`, `setup.php` — Manage: activity, duplicates, cache, how media is organised (and the tidy-up that moves copied footage into it), sources, helper
- `ingest.py` — the helper that does the copying. Runs on the archive machine itself (built in) or on another computer (external).
- `runner.sh` and the scripts it calls — background jobs (dedupe, proxies, verify)
- `rules.json` — rules that are the same everywhere; `settings.example.json` — what changes per installation

## Try it

Needs PHP 8 with SQLite, and Python 3 for the helper.

```
cp settings.example.json settings.json   # then edit
php -S 0.0.0.0:8080
```

Open http://localhost:8080 and go to Manage → Setup.

## Not built yet

Premiere relinking, thumbnails and preview copies, one-click installers for Mac and Windows.

## Transfer progress and automatic search updates

Manage → Overview and Transfers show the latest selected transfer, including
its percentage, copied/already-present bytes, remaining bytes, finished folders,
and last report. Progress survives a browser refresh or helper restart. Totals
start with the source inventory (labelled estimated) and are refined as each
folder is checked. Completion means files are accounted for by size; it is not
a claim of full-content verification. A file in progress counts after it lands.

The helper records search updates in a local SQLite outbox and sends batches of
up to 200 files about every ten seconds while working, and on its watcher polls
while idle. Updates are removed only after the archive acknowledges them. An
unavailable network or temporarily locked index leaves them queued for retry.
The watcher needs to remain running for these retries. Interrupted files restart
from the beginning of that file; previously finished files are checked and kept.

The scheduled runner now imports newer manifests automatically. A full import
keeps existing search available until the replacement is ready and preserves
incremental arrivals during the scan. The browser never has to trigger routine
index maintenance. Repeated import failures surface a request to check Activity.

### Upgrading an existing NAS installation

1. Stop the helper (Ctrl-C). Keep `settings.json`, the database, the queue and
   history files, and the helper's `archive-pilot` folder.
2. Pages: drop the changed `.php`/`.css`/`.json` files into `_rushes/deploy` on the
   archive share; the runner publishes them within a minute.
3. Helper: put **both** `ingest.py` and **`transfer_state.py`** in `_rushes`.
4. Runner: `runner.sh` is never published from the share, on purpose (it runs as
   root). Copy it by hand on the NAS: `cp /share/VIDEO/_rushes/runner.sh /share/Web/runner.sh`.
   Until then search still updates as files land, and Manage → Jobs and tools →
   Rebuild search does a full rebuild on demand.
5. Start the helper again with the command from Setup. The existing queue is
   adopted as a transfer; folders this computer had already finished are marked
   done without being walked again.

### What happens when something goes wrong

- A file that fails is tried once more at the end of its folder. If it fails
  again it is written in the origin record and shown as "could not be copied";
  the rest of the folder carries on. Select the folder again to retry it.
- If the source or the archive disconnects, the folder stops, is marked
  interrupted, and resumes from where it was once both are back.
- A copy aimed anywhere outside the archive is refused before anything is copied.

### Regression checks

From the repository root:

```sh
python3 -m unittest discover -s tests -v
php tests/test_server.php
php tests/test_pages.php
sh -n app/runner.sh
```

The tests use temporary media and an isolated database, never the live archive.
They cover interruption/restart, search retry, duplicate accounting, destination
conflicts, byte-weighted progress, stale helpers, and safe index reconciliation.
