<?php
// folders.php: what is in a folder, read on the disk itself, the moment someone is about to use it.
// The source of truth for a folder on the Describe list (HOW-IT-WORKS.md → What is in a folder).
//
// Read in the background (its own php process, so a page never waits, and a share that hangs only
// hangs the reading), with how far it has got, every 200 files. Each reading keeps the list of media
// files it found; the next one says what changed since: files missing, files new.
//
//   folder_read_start($abs, $force)  start a reading (not twice at once; one at a time unless asked for)
//   folder_read($abs)                the last reading: state, counts, when, what changed
//   folder_changes($abs)             the files missing and new since the reading before
//   php folders.php /abs/folder      the reading itself (started by folder_read_start)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';

const FOLDER_VIDEO = '/\.(mxf|mov|mp4|avi|mts|m4v|braw|r3d)$/i';               // the same lists as prepare_plan and proxy.sh
const FOLDER_OTHER = '/\.(jpe?g|png|heic|dng|tiff?|wav|mp3|m4a|aiff?)$/i';
const FOLDER_STALE = 600;          // a reading that has not moved for 10 minutes is said to be stuck, and may start again

function folders_init(): void {
    static $done = false; if ($done) return; $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS folder_reads (folder TEXT PRIMARY KEY, state TEXT, started INTEGER, updated INTEGER,
        at INTEGER, seen INTEGER, videos INTEGER, other INTEGER, bytes INTEGER, missing INTEGER, added INTEGER, prev_at INTEGER, why TEXT)");
    db()->exec("CREATE TABLE IF NOT EXISTS folder_files (folder TEXT, rel TEXT, bytes INTEGER, PRIMARY KEY (folder, rel))");
    db()->exec("CREATE TABLE IF NOT EXISTS folder_changes (folder TEXT, rel TEXT, change TEXT, bytes INTEGER)");
    db()->exec("CREATE INDEX IF NOT EXISTS i_folder_changes ON folder_changes (folder)");
}

function folder_read(string $abs): ?array {
    folders_init();
    $q = db()->prepare('SELECT * FROM folder_reads WHERE folder = ?'); $q->bindValue(1, rtrim($abs, '/'));
    $r = $q->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
    if ($r && $r['state'] === 'reading' && time() - (int)$r['updated'] > FOLDER_STALE) $r['state'] = 'stuck';
    return $r;
}

function folder_changes(string $abs, int $limit = 500): array {
    folders_init();
    $q = db()->prepare('SELECT rel, change, bytes FROM folder_changes WHERE folder = ? ORDER BY change, rel LIMIT ?');
    $q->bindValue(1, rtrim($abs, '/')); $q->bindValue(2, $limit); $r = $q->execute(); $out = [];
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) $out[] = $x;
    return $out;
}

// Start a reading in the background. $force: asked for (a folder just added), even if read a moment ago and
// even while another folder is being read; otherwise only when it was never read or not for $age seconds,
// and one folder at a time, so opening a page never sets the share reading ten folders at once.
function folder_read_start(string $abs, bool $force = false, int $age = 1800): bool {
    $abs = rtrim($abs, '/');
    if ($abs === '' || $abs[0] !== '/' || !is_dir($abs)) return false;         // a drive unplugged: its last reading stays
    $was = folder_read($abs);
    if ($was && $was['state'] === 'reading') return false;                     // already being read
    if (!$force) {
        if ($was && $was['state'] !== 'stuck' && time() - (int)$was['at'] < $age) return false;
        $busy = db()->querySingle("SELECT COUNT(*) FROM folder_reads WHERE state = 'reading' AND updated > " . (time() - FOLDER_STALE));
        if ($busy) return false;
    }
    $st = db()->prepare("INSERT INTO folder_reads (folder, state, started, updated, seen, at, videos, other, bytes, missing, added)
        VALUES (?, 'reading', ?, ?, 0, 0, 0, 0, 0, 0, 0)
        ON CONFLICT(folder) DO UPDATE SET state = 'reading', started = excluded.started, updated = excluded.updated, seen = 0, why = NULL");
    $st->bindValue(1, $abs); $st->bindValue(2, time()); $st->bindValue(3, time()); $st->execute();
    $php = PHP_BINARY ?: 'php';
    if (str_contains(basename($php), 'fpm')) $php = 'php';                     // a server's FastCGI: its command line is beside it
    exec(escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($abs) . ' > /dev/null 2>&1 &');
    return true;
}

// The reading itself: every media file under the folder, its size; hidden files, macOS leftovers (._),
// recycle bins and Rushes' own folders left out. Then what changed since the last reading.
function folder_read_now(string $abs): void {
    folders_init(); $db = db();
    $abs = rtrim($abs, '/');
    $lock = fopen(web_dir() . '/folder-read-' . substr(sha1($abs), 0, 12) . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;                  // being read already
    $set = function (array $kv) use ($db, $abs) {
        $cols = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($kv)));
        $q = $db->prepare("UPDATE folder_reads SET $cols WHERE folder = :f");
        foreach ($kv as $k => $v) $q->bindValue(":$k", $v);
        $q->bindValue(':f', $abs); $q->execute();
    };
    $skip = ['@Recycle', '$RECYCLE.BIN', '.Trashes', 'PROXIES', '_rushes', '_Recently Removed', '_duplicates', 'node_modules'];
    $files = []; $videos = 0; $other = 0; $bytes = 0; $n = 0;
    $stack = [$abs];
    try {
        while ($stack) {
            $dir = array_pop($stack);
            $h = @opendir($dir);
            if (!$h) { if ($dir === $abs) throw new RuntimeException('the folder could not be opened'); continue; }
            while (($e = readdir($h)) !== false) {
                if ($e === '.' || $e === '..' || $e[0] === '.' || in_array($e, $skip, true)) continue;
                $p = "$dir/$e";
                if (is_link($p)) continue;
                if (is_dir($p)) { $stack[] = $p; continue; }
                $video = preg_match(FOLDER_VIDEO, $e); $oth = !$video && preg_match(FOLDER_OTHER, $e);
                if (!$video && !$oth) continue;
                $b = (int)@filesize($p);
                $files[substr($p, strlen($abs) + 1)] = $b; $bytes += $b; $video ? $videos++ : $other++;
                if (++$n % 200 === 0) $set(['seen' => $n, 'updated' => time()]);
            }
            closedir($h);
        }
    } catch (Throwable $e) {
        $set(['state' => 'failed', 'updated' => time(), 'why' => $e->getMessage()]);
        return;
    }
    // what changed since the last reading
    $prev = []; $had = (int)$db->querySingle("SELECT COUNT(*) FROM folder_reads WHERE folder = '" . SQLite3::escapeString($abs) . "' AND at > 0");
    $q = $db->prepare('SELECT rel, bytes FROM folder_files WHERE folder = ?'); $q->bindValue(1, $abs); $r = $q->execute();
    while ($x = $r->fetchArray(SQLITE3_NUM)) $prev[$x[0]] = (int)$x[1];
    $missing = $had ? array_diff_key($prev, $files) : []; $added = $had ? array_diff_key($files, $prev) : [];
    $prevAt = (int)$db->querySingle("SELECT at FROM folder_reads WHERE folder = '" . SQLite3::escapeString($abs) . "'");
    $db->exec('BEGIN');
    foreach (['folder_files', 'folder_changes'] as $t) { $d = $db->prepare("DELETE FROM $t WHERE folder = ?"); $d->bindValue(1, $abs); $d->execute(); }
    $ins = $db->prepare('INSERT INTO folder_files (folder, rel, bytes) VALUES (?, ?, ?)');
    foreach ($files as $rel => $b) { $ins->bindValue(1, $abs); $ins->bindValue(2, (string)$rel); $ins->bindValue(3, $b); $ins->execute(); $ins->reset(); }
    $ch = $db->prepare('INSERT INTO folder_changes (folder, rel, change, bytes) VALUES (?, ?, ?, ?)');
    foreach (['missing' => $missing, 'new' => $added] as $kind => $list)
        foreach ($list as $rel => $b) { $ch->bindValue(1, $abs); $ch->bindValue(2, (string)$rel); $ch->bindValue(3, $kind); $ch->bindValue(4, $b); $ch->execute(); $ch->reset(); }
    $db->exec('COMMIT');
    $set(['state' => 'done', 'at' => time(), 'updated' => time(), 'seen' => $n, 'videos' => $videos, 'other' => $other, 'bytes' => $bytes,
          'missing' => count($missing), 'added' => count($added), 'prev_at' => $had ? $prevAt : null, 'why' => null]);
    @unlink(web_dir() . '/prepare-plan.json');                                 // the list's counts come from this reading now
    flock($lock, LOCK_UN); fclose($lock);
}

if (PHP_SAPI === 'cli' && isset($argv[1]) && realpath($argv[0]) === __FILE__) folder_read_now($argv[1]);
