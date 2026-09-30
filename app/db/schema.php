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

// Where the database lives comes from settings.json, like every other path.
define('DB_PATH', web_dir() . '/rushes.sqlite');

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

    // ── one row per job, whatever the job is ──────────────────────────────
    // This is the thing the old design lacked: dedupe, ingest, proxy and
    // reorganise each invented their own progress, history and undo. One
    // table means one panel, one history, one place to look.
    $db->exec("
    CREATE TABLE IF NOT EXISTS jobs (
        id        INTEGER PRIMARY KEY,
        action    TEXT NOT NULL,       -- reindex | dedupe | ingest | proxy | ...
        target    TEXT,                -- what it was pointed at
        state     TEXT NOT NULL,       -- queued | running | done | failed | cancelled
        started   INTEGER,
        finished  INTEGER,
        done_n    INTEGER DEFAULT 0,   -- for progress
        total_n   INTEGER DEFAULT 0,
        bytes     INTEGER DEFAULT 0,
        note      TEXT,                -- the one-line human result
        log       TEXT                 -- the detail, if any
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS i_jobs_state ON jobs (state, id DESC)');

    // ── what a job did to a file, so anything can be undone ───────────────
    $db->exec("
    CREATE TABLE IF NOT EXISTS moves (
        id        INTEGER PRIMARY KEY,
        job_id    INTEGER NOT NULL,
        src       TEXT NOT NULL,
        dst       TEXT,
        bytes     INTEGER,
        kept      TEXT,                -- for dedupe: which copy survived
        undone    INTEGER DEFAULT 0
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS i_moves_job ON moves (job_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS i_moves_src ON moves (src)');

    // ── what the model saw, when that exists ──────────────────────────────
    $db->exec("
    CREATE TABLE IF NOT EXISTS shots (
        id         INTEGER PRIMARY KEY,
        file_id    INTEGER NOT NULL,
        start_s    REAL,
        end_s      REAL,
        text       TEXT,               -- the description
        on_screen  TEXT,               -- text visible in frame
        model      TEXT,
        prompt     TEXT,               -- hash of the prompt used
        made_at    INTEGER
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS i_shots_file ON shots (file_id)');

    // ── the media ledger: what each original is, and what has been made from it ──
    // Keyed by the file's row, which keeps its id when a tidy-up moves the file
    // (moved.php), so this follows the file wherever it goes. The proxy itself
    // mirrors the original's path under PROXIES and is moved with it.
    $db->exec("CREATE TABLE IF NOT EXISTS media (
        file_id  INTEGER PRIMARY KEY,
        width    INTEGER, height INTEGER, fps REAL, codec TEXT, duration REAL,
        proxy_at INTEGER                -- when its 1080p proxy was made
    )");
    // What the camera wrote inside the file (read with its proxy): its clock
    // as written, timecode, reel or clip name, make and model.
    $have = [];
    $cols = $db->query('PRAGMA table_info(media)');
    while ($c = $cols->fetchArray(SQLITE3_ASSOC)) $have[] = $c['name'];
    foreach (['recorded', 'timecode', 'reel', 'camera'] as $c)
        if (!in_array($c, $have, true)) $db->exec("ALTER TABLE media ADD COLUMN $c TEXT");

    $db->exec("CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)");

    // ── pulls: clips gathered for a job ───────────────────────────────────
    // Made by anyone on the team (on a phone, say), opened by the editor on
    // their own computer. A clip is kept by where it sits INSIDE the archive,
    // never by anyone's computer path — so the editor's download is written
    // for their machine, and a pull still resolves after files move.
    $db->exec("CREATE TABLE IF NOT EXISTS pulls (
        id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
        made_by TEXT, created INTEGER, updated INTEGER)");
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
