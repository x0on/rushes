<p align="center"><img src="docs/assets/logo.svg" width="90" alt="Rushes"></p>

# Rushes

**Less lost. More made.**

Rushes is a self-hosted media catalog and management tool for production archives containing video, stills, and audio. It combines search, card ingest, source-folder transfers, media selections, and archive maintenance around a shared index.

The practical question behind it is: **what is on this storage, where does it belong, and what needs attention?**

[Public website](https://x0on.github.io/rushes/) · [Application source and setup](app/README.md)

## Current status

In development and used in an existing NAS workflow. The current application is PHP with SQLite, plus a Python helper for transfers. The current deployment runs on a NAS with a Mac helper; the helper can also run on the archive host when its environment supports it.

Self-contained Mac and Windows applications are not available yet. The PHP/Python dual-build architecture described in the initial design is a direction, not two complete application builds in this repository.

## What is implemented

| Component | Current role | Entry points |
| --- | --- | --- |
| Search | Search indexed media and collect selections into Pulls | [`app/db/find.php`](app/db/find.php), [`app/db/search.php`](app/db/search.php) |
| Ingest | Queue camera cards into the chosen organization structure, with folders per shooting day | [`app/ingest.php`](app/ingest.php), [`app/queue.php`](app/queue.php) |
| Transfers | Bring existing source folders across using the helper | [`app/ingest.py`](app/ingest.py) |
| Pulls | Save and share selected media; export Premiere XML, paths, or a ZIP | [`app/pull.php`](app/pull.php), [`app/db/pull-export.php`](app/db/pull-export.php) |
| Manage | Inspect activity, duplicates, cache, organization settings, sources, and helper state | [`app/db/admin.php`](app/db/admin.php), [`app/structure.php`](app/structure.php), [`app/setup.php`](app/setup.php) |
| Maintenance | Run queued scans, verification, proxy, and cleanup jobs | [`app/runner.sh`](app/runner.sh) and its companion scripts |

Search currently matches indexed paths. Mood/vibe search in the public product direction should not be read as implemented semantic analysis. Thumbnails and preview copies are still listed as unfinished in the application documentation.

Pull XML output is `xmeml` version 4, intended for Premiere import. It is not a native project file for every editor or a promise of universal XML compatibility. ZIP export requires PHP's `ZipArchive` extension and has size limits; XML and path lists reference the original media instead of packaging it.

## How the pieces fit

```text
Browser → PHP pages / endpoints → SQLite catalog and job requests
                                      ↓
                         Python helper / scheduled shell runner
                                      ↓
                         Mounted sources and archive storage
```

The PHP application provides the interface, configuration, and catalog access. Transfer requests are written to a queue that the Python helper reads. The scheduled shell runner executes maintenance jobs and writes status that the interface can display. Closing the browser does not stop an independently running helper or scheduled job; stopping the helper does affect its transfers.

Configuration is split between [`settings.json`](app/settings.example.json), which describes one installation, and [`rules.json`](app/rules.json), which holds media classifications and policy. The intent is to keep media decisions shared rather than scattering them across platform implementations. Some scripts still contain deployment-specific paths and assumptions; changing settings alone does not yet make every component portable.

## Storage and organization

The organization plan starts with the user's own departments, clients, or projects, followed by year, date, and event. It is not a mandatory Archive / Projects / Library layout.

Camera-card ingest and migration are different operations: ingest assigns a card to a shooting-day destination; existing folder transfers preserve source structure. Planning organization does not mean existing media is automatically reorganized. Tidy-up moves and Premiere relinking remain unfinished. Older date-first descriptions remain in parts of `rules.json`; they should not be treated as the current product specification.

The helper works with storage already mounted by the operating system. Configure both the archive's host-side path and its path as seen from the helper. A Mac bridging two storage locations must be able to access both and remain available while copying.

## Duplicate checks, cleanup, and verification

Filenames are not duplicate identities: cameras reuse them. The ingest helper compares file sizes and, on collisions, samples the beginning and end of files. Its `--paranoid` option requests full-content comparison. Sample matches are not proof that every byte is identical; copy-size checks and full-content verification are different checks.

Cleanup is designed to move media into holding locations for review instead of permanently deleting it. Moving files on the same volume does not reclaim space. Review the applicable job and verification results before emptying a holding folder. The rules distinguish rebuildable editing cache from protected project backups; review these classifications for the software used in your workflow.

## Setup and operation

Requirements for the current app:

- PHP 8 with SQLite support.
- Python 3 on the helper machine.
- Mounted source/archive storage and appropriate read/write permissions.
- A scheduler for the maintenance runner; additional tools depend on the jobs enabled.

For a local interface smoke check, from the repository root:

```sh
cd app
cp settings.example.json settings.json
# Edit settings.json for this installation before using file operations.
php -S 127.0.0.1:8080
```

Open `http://127.0.0.1:8080` and use **Manage → Setup**. This starts the web interface only; it does not configure the scheduler, launch the transfer helper, or provision an archive index.

For NAS deployment, consult the [application README](app/README.md), the settings example, and the runner's installation comments. Review hard-coded `/share/Web` and `/share/VIDEO` paths before scheduling jobs on a different layout. Configure the helper through Setup and inspect `ingest.py --help` for its operating modes. A dry interface check is not validation of file-moving jobs against your archive.

## Access model

Rushes uses one local management password and PHP sessions, not per-user accounts. The initial default is derived from the application name; change it during setup. Changed passwords are hashed, while the authentication code also accepts legacy plaintext password files.

Mounted-share credentials are managed by the operating system or NAS, outside Rushes. The current access model is intended for a trusted local network. It is not a hardened public service: do not expose the app directly to the internet without reviewing authentication, permissions, and the deployment model. This applies to the PHP application, not the static public website.

## Known gaps

The application documentation currently lists organization tidy-up, Premiere relinking, thumbnails/preview copies, and one-click Mac/Windows installers as unfinished. Scripts and rules are still being generalized from the existing NAS installation. Confirm supported behavior in the app source and setup documentation before relying on a feature shown in website mockups.

## Repository layout

- `app/` — executable application source and setup notes.
- `docs/` — public website served by GitHub Pages.
- `website/` — matching website source; keep it synchronized with `docs/` when editing the site.

## Contributing and license

Issues and contributions are welcome. Include the host platform, storage layout, relevant job/status output, and steps to reproduce; remove credentials and private paths from shared logs.

Rushes is intended to be open source. A license has not yet been selected in this repository.
