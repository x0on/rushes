<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// drive.php — one drive, as Overview's card and the drive's own page show it (HOW-IT-WORKS.md → Drives):
// how far along it is at three levels, for the whole drive and for each folder in it.
//   Searchable  its files are in Search by name (every file the catalogue has under it)
//   Playable    its videos play in Search: a preview (proxy) was made, or it is light enough as it is
//   Described   the AI wrote down what is in its videos (a row in moments)
// Counted from the search database only: nothing on the drive is read, so an unplugged drive still
// shows where it was. Kept for 15 minutes; a new file list counts again at once.
//
//   GET ?path=<drive root> [&in=<folder inside it>]
//     -> {root, in, at, total: {n, b, v, p, d, wait?}, folders: [{name, n, b, v, p, d, is?}]}
//   is: filed (on the archive's shelf) · waiting (Tidy up files it) · own (Rushes' own, not counted)
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/analysis.php';

const LEVELS_KEEP = 900;      // ponytail: 15 minutes; a trigger on media/moments if it has to be live

// What a top folder of the archive is: on the shelf, waiting to be filed, or Rushes' own (tidy.php's rules).
function archive_folder_is(string $top): string {
    if ($top === '' || strpbrk($top[0], '_@.#') !== false || $top === 'PROXIES') return 'own';
    if (in_array(strtolower($top), array_map('strtolower', kept_folders()), true)) return 'kept';   // in Search, never filed
    if ($top === 'ARCHIVE') return 'waiting';                 // where copies land before a tidy-up
    if (!shelf_chosen()) return '';                            // no structure yet: nothing is "waiting"
    if (!shelf_is_top()) return $top === shelf_name() ? 'filed' : 'waiting';
    // the archive itself is the shelf: a department's folder is filed; any other folder at the top (a whole
    // drive copied in, "VIDEO from QNAP") waits, as tidy.php here_files() offers it
    return $top === 'Projects' || dept_of_folder($top) !== null || in_array(strtolower($top), array_map(fn($d) => strtolower($d['name']), departments()), true)
        ? 'filed' : 'waiting';
}

function drive_levels(string $root, string $in = '', bool $fresh = false): array {
    $root = rtrim($root, '/'); $in = trim($in, '/');
    $key = 'lv:' . md5("$root|$in"); $for = meta_get('imported_at', '0') . ':' . meta_get('files_imported', '0');
    $was = json_decode((string)meta_get($key, ''), true);
    if (!$fresh && is_array($was) && ($was['for'] ?? '') === $for && time() - (int)$was['at'] < LEVELS_KEEP) return $was;

    analysis_init(); $db = db();
    $pre = $root . '/' . ($in === '' ? '' : "$in/"); $L = strlen($pre);
    $hi = substr($pre, 0, -1) . '0';                           // every path that starts with $pre sorts below this ('0' follows '/')
    $q = $db->prepare("SELECT seg, COUNT(*) n, COALESCE(SUM(bytes),0) b, SUM(kind = 'video') v,
            SUM(kind = 'video' AND (m.proxy_at OR m.asis = '1')) p,
            SUM(kind = 'video' AND EXISTS (SELECT 1 FROM moments WHERE moments.path = f.path)) d
        FROM (SELECT id, path, bytes, kind, CASE instr(substr(path, :s), '/') WHEN 0 THEN ''
                ELSE substr(path, :s, instr(substr(path, :s), '/') - 1) END seg
              FROM files WHERE path >= :pre AND path < :hi) f
        LEFT JOIN media m ON m.file_id = f.id GROUP BY seg ORDER BY seg COLLATE NOCASE");
    $q->bindValue(':s', $L + 1); $q->bindValue(':pre', $pre); $q->bindValue(':hi', $hi);
    $r = $q->execute(); $folders = []; $t = ['n' => 0, 'b' => 0, 'v' => 0, 'p' => 0, 'd' => 0];
    $isArch = $root === rtrim(archive_dir(), '/');
    if ($isArch && $in === '' && shelf_chosen()) $t['wait'] = ['n' => 0, 'b' => 0];
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) {
        $one = ['name' => $x['seg']] + array_map('intval', ['n' => $x['n'], 'b' => $x['b'], 'v' => $x['v'], 'p' => $x['p'], 'd' => $x['d']]);
        if ($isArch && $in === '') {
            $one['is'] = archive_folder_is($x['seg']);
            if ($one['is'] === 'own') continue;                    // proxies, the bin, Rushes' records: not footage
            if ($one['is'] === 'waiting' && isset($t['wait'])) { $t['wait']['n'] += $one['n']; $t['wait']['b'] += $one['b']; }
        }
        foreach (['n', 'b', 'v', 'p', 'd'] as $k) $t[$k] += $one[$k];
        $folders[] = $one;
    }
    // a named shelf holds whole drives copied onto it too ("VIDEOS/VIDEO from QNAP"): its own folders that
    // are not a department wait to be filed, as tidy.php here_files() offers them
    if (isset($t['wait']) && !shelf_is_top()) {
        $pre2 = $root . '/' . shelf_name() . '/'; $L2 = strlen($pre2);
        $q2 = $db->prepare("SELECT substr(path, :s, instr(substr(path, :s), '/') - 1) seg, COUNT(*) n, COALESCE(SUM(bytes),0) b
            FROM files WHERE path >= :pre AND path < :hi AND instr(substr(path, :s), '/') > 0 GROUP BY seg");
        $q2->bindValue(':s', $L2 + 1); $q2->bindValue(':pre', $pre2); $q2->bindValue(':hi', substr($pre2, 0, -1) . '0');
        $names = array_map(fn($d) => strtolower($d['name']), departments());
        $keep = array_map('strtolower', kept_folders());
        $r2 = $q2->execute();
        while ($x = $r2->fetchArray(SQLITE3_ASSOC)) {
            $f = (string)$x['seg'];
            if ($f === '' || strpbrk($f[0], '_@.#') !== false || $f === 'Projects' || dept_of_folder($f) !== null
                || in_array(strtolower($f), $names, true) || in_array(strtolower($f), $keep, true)) continue;
            $t['wait']['n'] += (int)$x['n']; $t['wait']['b'] += (int)$x['b'];
        }
    }
    $out = ['for' => $for, 'at' => time(), 'root' => $root, 'in' => $in, 'total' => $t, 'folders' => $folders];
    meta_set($key, json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE));
    return $out;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;   // included: the functions only

header('Content-Type: application/json');
if (!signed_in()) { http_response_code(403); echo '{"error":"sign in first"}'; exit; }
$root = rtrim((string)($_GET['path'] ?? ''), '/'); $in = trim(str_replace('\\', '/', (string)($_GET['in'] ?? '')), '/');
// only a place Search covers, and never a way out of it
if (!in_array($root, array_map(fn($r) => rtrim($r, '/'), catalogue_roots()), true) || str_contains("/$in/", '/../')) {
    http_response_code(400); echo json_encode(['error' => 'That drive is not in Search.']); exit;
}
echo json_encode(drive_levels($root, $in, isset($_GET['fresh'])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
