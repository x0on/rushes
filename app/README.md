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
