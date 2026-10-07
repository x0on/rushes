<?php
// schema.php — the shape of everything Rushes knows.
//
// One SQLite file, read and written by Apache's PHP. No daemon, nothing to
// start on boot, nothing to keep alive. It is a file; back it up by copying it.
//
// Why a database at all, when TSV files worked: the search page was
// downloading a 102 MB index into the browser on every visit, and the archive
// is about to gain a million AI descriptions, per-file tags and proxy status.
// Flat files stop being viable at exactly the point this project gets useful.
//
// This SQLite has no FTS module (measured, not assumed), so search is a plain
// indexed LIKE. On this NAS that scans 50,000 rows in 0.01s — fast enough that
// adding a full-text engine would be ceremony.

require_once __DIR__ . '/config.php';

// Where the database lives comes from settings.json, like every other path:
// archive.database, or rushes.sqlite in the web folder. The web folder is
// served, so .htaccess there tells the web server never to hand it out; if
// the web server ignores that (Overview says so), set archive.database to a
// folder it does not serve (INSTALL.md → Keeping the database private).
define('DB_PATH', s_path('archive.database', web_dir() . '/rushes.sqlite'));

// HOW-IT-WORKS.md → Rushes' own backups: a power cut can damage the database, and pulls live only in it.
// Once a day it is checked, and only a good one is copied beside it
// (db-copy.sqlite, with SQLite's own backup, safe while in use). The runner
// then puts that copy on VIDEO, one per weekday: a week of copies, none deleted
// by anything but the same weekday a week later. A damaged one is said on
// Overview, once a day, and never copied over the good ones.
function db_daily_copy(): array {
    // the copy sits beside the database (never in a served folder it is not in
    // already); db-copy.path tells the runner where, so it can put it on VIDEO
    $w = web_dir(); $copy = dirname(DB_PATH) . '/db-copy.sqlite'; $bad = "$w/db-damaged.txt";
    @file_put_contents("$w/db-copy.path", "$copy\n");
    foreach ([$copy, $bad] as $f) if (is_file($f) && time() - filemtime($f) < 86400) return ['state' => 'current'];
    if (db()->querySingle('PRAGMA quick_check') !== 'ok') {
        @file_put_contents($bad, (string)time());
        return ['state' => 'damaged'];
    }
    @unlink($bad); @unlink("$copy.new");
    $to = new SQLite3("$copy.new");
    $ok = db()->backup($to); $to->close();
    if (!$ok || !@rename("$copy.new", $copy)) { @unlink("$copy.new"); return ['state' => 'not copied']; }
    return ['state' => 'copied'];
}

function db(): SQLite3 {
    static $db = null;
    if ($db) return $db;
    $db = new SQLite3(DB_PATH);
    $db->busyTimeout(30000);          // imports and searches can overlap
    $db->exec('PRAGMA journal_mode = WAL');   // readers never block on a writer
    $db->exec('PRAGMA synchronous = NORMAL');
    // Sorting (building an index over every file) needs scratch space. SQLite
    // puts it in /tmp by default, and on a QNAP /tmp is a small RAM disk that
    // fills long before the archive's disk does — "database or disk is full".
    // Keep that scratch space in memory instead.
    $db->exec('PRAGMA temp_store = MEMORY');
    return $db;
}

function db_init(): void {
    $db = db();

    // ── every file on the share ───────────────────────────────────────────
    // path is the truth; the rest is derived from it and may be wrong, which
    // is why 'why' records how each guess was made.
    $db->exec("
    CREATE TABLE IF NOT EXISTS files (
        id        INTEGER PRIMARY KEY,
        path      TEXT NOT NULL UNIQUE,
        name      TEXT NOT NULL,       -- filename alone, for name searches
        ext       TEXT,                -- lowercase, no dot
        kind      TEXT,                -- video | image | audio | project | sidecar | other
        bytes     INTEGER,
        dept      TEXT,                -- derived from the path
        year      TEXT,
        event     TEXT,
        why       TEXT,                -- how dept/event were decided
        seen_at   INTEGER              -- when this row was last confirmed on disk
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS i_files_name  ON files (name)');
    $db->exec('CREATE INDEX IF NOT EXISTS i_files_kind  ON files (kind)');
    $db->exec('CREATE INDEX IF NOT EXISTS i_files_dept  ON files (dept)');
    $db->exec('CREATE INDEX IF NOT EXISTS i_files_bytes ON files (bytes)');

    // ── the media ledger: what each original is, and what has been made from it ──
    // Keyed by the file's row, which keeps its id when a tidy-up moves the file
    // (moved.php), so this follows the file wherever it goes. The proxy itself
    // mirrors the original's path under PROXIES and is moved with it.
    $db->exec("CREATE TABLE IF NOT EXISTS media (
        file_id  INTEGER PRIMARY KEY,
        width    INTEGER, height INTEGER, fps REAL, codec TEXT, duration REAL,
        proxy_at INTEGER                -- when its proxy (720p) was made
    )");
    // What the camera wrote inside the file (read with its proxy): its clock
    // as written, timecode, reel or clip name, make and model.
    $have = [];
    $cols = $db->query('PRAGMA table_info(media)');
    while ($c = $cols->fetchArray(SQLITE3_ASSOC)) $have[] = $c['name'];
    // asis: no proxy made, the original is light enough to play as it is (runner.py's light())
    foreach (['recorded', 'timecode', 'reel', 'camera', 'asis'] as $c)
        if (!in_array($c, $have, true)) $db->exec("ALTER TABLE media ADD COLUMN $c TEXT");

    // ── copies: where else each file exists (git-annex's idea) ──
    // One row per file and place: the place a file came from (a source server,
    // by its name in Settings), whether the same file was still there when the
    // helper last looked, and when. The archive itself is always one copy.
    $db->exec("CREATE TABLE IF NOT EXISTS copies (
        file_id INTEGER, place TEXT, present INTEGER, checked INTEGER,
        PRIMARY KEY (file_id, place))");

    $db->exec("CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)");

    // ── pulls: clips gathered for a job ───────────────────────────────────
    // Made by anyone on the team (on a phone, say), opened by the editor on
    // their own computer. A clip is kept by where it sits INSIDE the archive,
    // never by anyone's computer path — so the editor's download is written
    // for their machine, and a pull still resolves after files move.
    $db->exec("CREATE TABLE IF NOT EXISTS pulls (
        id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
        made_by TEXT, created INTEGER, updated INTEGER)");
    // ── projects in and out (HOW-IT-WORKS.md → Projects in and out) ──────────
    // Each project a Watcher has seen, by its path inside the Projects share;
    // and each outside file a Watcher delivered, with where it now is in the
    // archive (rel, inside the archive), so the project can be pointed there.
    $db->exec("CREATE TABLE IF NOT EXISTS projects (
        path TEXT PRIMARY KEY, name TEXT, host TEXT, watcher TEXT, saved INTEGER, seen INTEGER,
        files INTEGER, outside INTEGER, missing TEXT, shoot TEXT, archived TEXT, state TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS delivered (
        batch TEXT NOT NULL, original TEXT NOT NULL, rel TEXT NOT NULL, fp TEXT, kind TEXT, project TEXT,
        at INTEGER, PRIMARY KEY (batch, original))");
    $db->exec('CREATE INDEX IF NOT EXISTS i_delivered_project ON delivered (project)');
    // when its folder was last moved aside or brought back (projects.php)
    $have = [];
    $cols = $db->query('PRAGMA table_info(projects)');
    while ($c = $cols->fetchArray(SQLITE3_ASSOC)) $have[] = $c['name'];
    if (!in_array('aside_at', $have, true)) $db->exec('ALTER TABLE projects ADD COLUMN aside_at INTEGER DEFAULT 0');
    $db->exec("CREATE TABLE IF NOT EXISTS pull_items (
        pull_id INTEGER NOT NULL, rel TEXT NOT NULL, name TEXT, kind TEXT, bytes INTEGER,
        pos INTEGER, added INTEGER, PRIMARY KEY (pull_id, rel))");
}

function meta_set(string $k, string $v): void {
    $s = db()->prepare('INSERT INTO meta (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v');
    $s->bindValue(1, $k); $s->bindValue(2, $v); $s->execute();
}
function meta_get(string $k, string $default = ''): string {
    $s = db()->prepare('SELECT v FROM meta WHERE k = ?');
    $s->bindValue(1, $k);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ? $r['v'] : $default;
}

// ── classifying a path ────────────────────────────────────────────────────
// Deliberately dumb and readable. Every guess is recorded in 'why' so a wrong
// rule can be found and fixed rather than silently shaping the archive.

// Camera cards name their folders in families, not fixed strings: DCIM, DCIM I,
// DCIM II, 100MEDIA, 101MEDIA, A-CAM, CAM_B. Matching exact names missed
// "DCIM I" and made it the event — patterns, not a list of literals.

// what a file is, and what folder names are camera plumbing, both live in
// rules.json now and are answered by config.php
function kind_of(string $ext): string { return kind_for($ext); }

function classify(string $path): array {
    $parts = array_values(array_filter(explode('/', $path), 'strlen'));
    $name  = array_pop($parts);
    $ext   = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    // The event is the nearest folder above the file that is not card
    // plumbing and is not a year.
    //
    // ponytail: this walks all the way to the share root, so a file sitting
    // directly under a department gets the department as its event. Harmless
    // now, wrong once departments are mapped — at that point the walk must
    // stop at the department folder. Needs the mapping sheet first, so it is
    // written down rather than guessed at.
    $event = null; $why = 'no event folder found';
    for ($i = count($parts) - 1; $i >= 0; $i--) {
        $p = $parts[$i];
        if (is_card_junk($p)) continue;
        if (is_year($p)) continue;                    // a year is not an event
        if (preg_match('/^(outputs?|exports?|renders?|deliverables?|finals?|old|versions?|backups?)$/i', trim($p))) continue;   // where an edit is saved, not the shoot
        $event = $p; $why = 'event = nearest meaningful folder'; break;
    }

    $year = null;
    foreach ($parts as $p) if (preg_match('/^(19|20)\d{2}$/', $p)) $year = $p;
    if (!$year && preg_match('/(19|20)(\d{2})(\d{2})(\d{2})/', $name, $m)) $year = $m[1] . $m[2];

    return [
        'name'  => $name,
        'ext'   => $ext,
        'kind'  => kind_of($ext),
        'event' => $event,
        'year'  => $year,
        'why'   => $why,
    ];
}
