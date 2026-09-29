# Rushes — the app

**Status: in development.** Working, used daily, not finished. Expect changes.

## What's here

- `db/find.php` — search the archive, collect clips into Pulls
- `ingest.php` — copy camera cards into the archive, one folder per shooting day
- `pull.php`, `db/pulls.php`, `db/pull-export.php` — Pulls: shareable clip lists, downloadable as a Premiere XML, a list of paths, or a zip
- `db/admin.php`, `structure.php`, `setup.php` — Manage: activity, duplicates, cache, how media is organised, sources, helper
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

Tidy-up (moving archived folders into the organisation plan), Premiere relinking, thumbnails and preview copies, one-click installers for Mac and Windows.

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

1. Stop the existing helper before replacing its files. Keep the installation's
   `settings.json`, database, queue/history files, and the helper's `archive-pilot`
   directory. Back up the SQLite database with SQLite's backup facility or while
   its writers are stopped (do not copy just the main file while WAL writes run).
2. Deploy the changed PHP pages, including the new `db/transfers.php`,
   `db/transfer.php`, and `db/sync.php`, and replace `runner.sh`.
3. Put **both** `ingest.py` and **`transfer_state.py`** together in the helper's
   `_rushes` directory. Python needs its standard-library SQLite module.
4. Ensure the existing cron runner is active and can reach the app at
   `http://127.0.0.1`. If the NAS uses a different web address or port, set
   `RUSHES_URL` in the runner's cron environment. Automatic imports use curl
   (or wget). Keep this on the trusted local network as before.
5. Restart the helper with the command shown in Setup. An older queue is adopted
   automatically. Its historical percentage cannot be reconstructed from the
   old zero-file activity records; the helper checks the selected folders and
   establishes accurate progress as it resumes.

Changing an unfinished selection retains its completed folders in the same job;
removed unfinished folders leave that job's total. A new selection after a
completed job creates a new job. One watcher should operate an archive at a time.

### Regression checks

From the repository root:

```sh
python3 -m unittest discover -s tests -v
php tests/test_server.php
sh -n app/runner.sh
```

The tests use temporary media and an isolated database, never the live archive.
They cover interruption/restart, search retry, duplicate accounting, destination
conflicts, byte-weighted progress, stale helpers, and safe index reconciliation.
