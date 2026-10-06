# Developing Rushes

For anyone changing the code. Read [HOW-IT-WORKS.md](HOW-IT-WORKS.md) first:
it is the description of everything Rushes does, and this repository keeps it
true.

## The rule that keeps this project honest

**Every change to what Rushes does updates HOW-IT-WORKS.md in the same
commit.**

- A new button, a new thing that repeats, a new file written, a new check, a
  new deletion: each goes into HOW-IT-WORKS, in plain words, with the file and
  function that does it.
- A removed behaviour is removed from HOW-IT-WORKS.
- Anything not right yet goes in [ROADMAP.md](ROADMAP.md#known-problems)
  under Known problems.

A pull request that changes behaviour without the document is not finished.

## Where the code is

```text
app/                     everything that is installed
  *.php                  pages: Search's neighbours (ingest, pull, setup, structure),
                         the top bar (head.php), and run.php / queue.php, which
                         only write down what was asked
  db/*.php               the catalogue and the doors the pages and the helper use
    config.php           settings, rules, paths, pairing, helper control (read this first)
    schema.php           the database
    state.php            everything Overview shows, and why
    admin.php            Manage
  runner.sh              runs as root every minute: jobs, updates, catalogue, backups
  runner.py              the same everyday work, inside Rushes Helper, when the archive is a drive on that Mac
  router.php             the door of PHP's own web server there: what may be handed out, and to whom
  proxy.sh dedupe.sh verify.sh organize.sh   what the runner's jobs call
  ingest.py              the helper: copying, proof, checking, describing lane, reporting
  transfer_state.py      the helper's saved progress (SQLite on its computer)
  analyze.py             describing one folder (run by the helper)
  release.py             signed releases of the helper's code (Ed25519, written out)
  rules.json             what is the same everywhere: kinds of media, caches, limits, themes
  settings.example.json  what changes per installation
  .htaccess              files the web server must never hand out
mac/                     Rushes Helper and Rushes Watcher for Mac
  rushes_helper.py       each app's window, setup, menu bar answers and background service
  rushes_watcher.py      Rushes Watcher's work: projects in and out
  launcher.c             the apps' program: the window, the menu bar icon, starting Python
  build.py               builds and signs either app
tests/                   all the checks (below)
docs/                    the public website (GitHub Pages)
```

## How Rushes is built: six rules

Anything that repeats by itself follows these. Each has a test that breaks
things on purpose.

1. **Nothing to do, do nothing new.** An idle minute starts no work and copies,
   moves or describes nothing. What it still does (free space, a heartbeat, a
   glance at the shares) is listed in HOW-IT-WORKS.
2. **Every step has a time limit.** A step that cannot finish is abandoned and
   said. On a dying disk a process cannot even be killed, so it is never waited
   on: `v()` in `runner.sh`, `within()` in `ingest.py`.
3. **Never two at once.** A tick lock and a job lock in the runner, the
   one-helper lock in the helper. A question still stuck is not asked again.
4. **Failing slows down, or stops.** Asking Rushes' pages harms nothing, so it
   backs off (20 s, 1 min, 5 min, then every 15 min) and never stops. Touching a
   share that does not answer is abandoned, and after three in a row it stops
   and says so until a person presses Try again (the breaker: `video-stalls.txt`
   in the runner, `stall()` and `stopped.txt` in the helper).
5. **Visible.** Overview → What runs by itself (`$repeats` in `state.php`).
6. **Pages ask only while someone is looking** (`every()` in `head.php`), never
   read the archive share on a timer, and ask less when it is slow.

And three that are older than these:

- **The pages decide nothing about media.** What a file *is* comes from
  `rules.json`, through `config.php`.
- **Pages never move files.** They write a job file or a queue line. The runner
  and the helper do the work, and check every field again.
- **Ask twice on the button.** Anything that starts work, moves files or takes
  something away asks "Sure?" on the button itself, and acts on a second press
  within about five seconds (`sure()` in `head.php`). Never a browser pop-up.

## Style

- Comments say **why**, in plain words. The code says what.
- Shortcuts are marked `ponytail:` and say their limit and what would replace
  them.
- Messages a person reads say what happened, what it means, and what to do.
  Not codes.
- Nothing happens invisibly: everything that runs shows its progress, and every
  button says what it did.

## Running the tests

From the repository root. They use temporary folders and never touch a real
archive.

```sh
cd tests
python3 -m unittest test_transfer          # the helper: copying, proof, pause, stalls, pairing
python3 -m unittest test_watcher           # Rushes Watcher and the helper, end to end: save, deliver, quit, repoint
python3 -m unittest test_menu              # the menu bar icon's answers, for both apps
busybox sh test_runner.sh                  # the runner, on a pretend archive, on its bad days
sh test_router.sh                          # Rushes on a Mac: private files, other devices, the password
python3 -m unittest test_mac_runner        # Rushes on a Mac: duplicates and caches, with a Mac's stat and awk
sh test_tidy_here.sh                       # the tidy-up of folders already in the archive: what is offered, the plan
php test_server.php                        # the catalogue, imports, plans, pulls, passwords
php test_pages.php                         # what Overview says
PHPBIN=php sh test_helper.sh               # the helper's doors and install script
sh test_pair.sh                            # pairing (needs PHPBIN or the php-wasm runner)
node test_every.js                         # pages asking only while looked at; Sure?
node test_relink.js                        # relinking FCPXML and XML addresses
cd .. && python3 app/analyze.py --selftest  # describing's rules, without a model
python3 app/ingest.py --selftest
python3 app/release.py --selftest
python3 app/runner.py                       # Rushes on a Mac: the minute's work, on a pretend drive
```

`test_runner.sh` rewrites `/share/` to a temporary folder, so it runs anywhere
with BusyBox. The PHP tests run with any PHP 8 command line.

## Signing a release of the helper's code

The helper's code updates itself, so it is signed instead of approved
(HOW-IT-WORKS → Updates). With the four files ready in a folder:

```sh
python3 app/release.py sign <folder> <release key file>
python3 app/release.py verify <folder>
```

`sign` writes `release.sig` beside them. The key file holds the private half of
the release key; it never goes in the repository or anywhere but its owner's
computer. `release.py keygen <file>` makes a new key; a fork that signs its own
releases puts the public half printed by `keygen` in `PUBLIC`, in `release.py`.

`app/release.sig` in the repository is the signature of the last release. Any
change to one of the four files needs a new signature before it is released.

## Versions

Rushes has one version number for everything: the pages, the helper's code,
Rushes Helper and Rushes Watcher. It is in `app/VERSION`. It is shown at the
foot of every Manage page, in each app's menu (and its Get Info in Finder), and
in the Watcher's reports. Raise it when a version is handed out, and say what
changed in [CHANGES.md](CHANGES.md): the middle number for something new, the
last for a fix. 1.0 is when everything listed under Version 1 in the
[roadmap](ROADMAP.md) is built.

## Building Rushes and Rushes Watcher

`mac/build.py` builds `mac/out/Rushes.app` and its zip, from Linux or a
Mac; `mac/build.py watcher` builds `Rushes Watcher.app` the same way. Put each
zip on the archive in `_rushes`, where Setup offers them for download. It needs:

- the two `python-build-standalone` archives (Python 3.12 for arm64 and x86_64)
  next to `build.py`;
- for Rushes Helper, the two static PHP archives named in `build.py` (`PHP`,
  from static-php.dev's "common" build, arm64 and x86_64) next to `build.py`;
- `python3.12` and the `ziglang` pip package, to compile `launcher.c` for both
  processors;
- `rcodesign` (apple-codesign);
- optionally, the `xxhash` wheels for both processors in `mac/`. Without them,
  copies are fingerprinted with BLAKE2 instead of XXH3.

**For people, the app comes as a disk image:** `mac/dmg.py "mac/out/Rushes.app"
"Rushes 0.12.1.dmg"` packs the signed app with an Applications shortcut, an arrow
and one line saying to drag it there. Run it after signing: the app goes in as it
is. It runs on Linux too (macOS's `hdiutil` does not): the volume is an ISO 9660
image with Rock Ridge, which keeps names, permissions and symlinks, made a
compressed `.dmg` by `dmg` from libdmg-hfsplus (built with zlib; on the PATH or
beside `dmg.py` as `dmg-<arch>`). It needs `pip install pycdlib ds-store mac-alias
pillow`. **Every build gets a new number** (`app/VERSION`) and carries it in its
file name, so two builds are never mistaken for each other.

It signs with the certificate in `RUSHES_SIGN_KEY` and `RUSHES_SIGN_CERT` when
those are set, ad hoc otherwise. Keep the signing key out of the repository
(`.gitignore` refuses `*.pem` and `*.p12`). macOS keeps a Mac's permissions
(Full Disk Access, Local Network) across versions only if they are signed with
the same certificate.

## Publishing a release

The apps update from the releases of this repository on GitHub (`RELEASES` in
`app/release.py`). For each version handed out, after the pull request is merged:

1. Build both apps (`mac/build.py`, `mac/build.py watcher`), sign them with the
   Rushes certificate, and zip each signed app: `Rushes 0.12.8.zip` (holding
   `Rushes.app`) and `Rushes Watcher 0.12.8.zip` (holding `Rushes Watcher.app`):
   every build carries its version in its name. Make the
   disk image for new installs (`mac/dmg.py`).
2. On GitHub: Releases → Draft a new release. Tag `v` and the version
   (`v0.12.8`), on `main`. Title "Rushes 0.12.8"; the notes are that version's
   part of CHANGES.md.
3. Attach the two zips and `Rushes 0.12.8.dmg`, and
   Publish. GitHub shows the spaces in names as dots; the apps look for them so.

An app only takes a release signed with the certificate whose fingerprint is
`APP_CERT_SHA256` in `release.py`: a release with anything else attached is
refused, whoever published it.

## Proposing a change

Open an issue first for anything large. Say what problem it solves for someone
looking after an archive. Keep pull requests to one change. Include the test
that would have failed without it, and the HOW-IT-WORKS update.
