# Security log

Known security problems in the open-source parts Rushes uses: each one looked
at, and what was done. Kept under the [open-source policy](OPEN-SOURCE-POLICY.md),
section 9. Newest first.

| Date | Part and version | What was known | Does it affect Rushes? | What was done |
|---|---|---|---|---|
| 2026-11-25 | SQLite 3.45.2 (inside PHP) | Later SQLite releases fix problems that need a crafted database or crafted queries | Not reachable: Rushes opens only its own catalogue, with its own queries | Nothing yet. To be moved forward in the rushes-parts recipe |
| 2026-11-25 | PHP 8.5.8 | Five later releases, 8.5.9 to 8.5.13, several of them security releases | Yes: PHP serves Rushes' pages | 8.5.13 since Rushes 0.15.5, compiled from php.net's source with only the extensions Rushes uses |
| 2026-11-25 | FFmpeg 6.0 | A 2023 release; public trackers list problems affecting 6.0 and earlier | Yes: FFmpeg reads every clip and every upload | 9.1 since Rushes 0.15.2; since 0.15.5 compiled from ffmpeg.org's signed source |
| 2026-11-25 | CPython 3.12.11 | Five later security releases, 3.12.12 to 3.12.16 | Yes: the helper and the apps run on it | 3.12.16 since Rushes 0.15.2 |
