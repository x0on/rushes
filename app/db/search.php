<?php
// search.php — the query endpoint. Returns JSON, not a 102 MB download.
//
//   search.php?q=DJI_0002&kind=video&limit=200&offset=0
//   search.php?in=library/music          a section by what files are (labels.php), with or without words
//
// Every word must match somewhere in the path, which is how the old page
// behaved and what people expect. Counts per kind come back with the results
// so the filter chips can show true numbers without a second query.

header('Content-Type: application/json');

// Never die silently. A PHP fatal prints nothing by default, so the page got
// a blank body and had nothing to show. This turns any fatal — including an
// uncaught exception — into JSON the page can print.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo json_encode(['error' => $e['message'] .
            ' (' . basename($e['file']) . ' line ' . $e['line'] . ')']);
    }
});

require_once __DIR__ . '/schema.php';
db_init();   // new tables (the media ledger) exist before the first search
require_once __DIR__ . '/analysis.php';
analysis_init();   // the moments table, so a file can show the first still the model looked at
require_once __DIR__ . '/labels.php';
// Labels (and the versions of each piece) are kept by sync.php; after an update that changed
// the rules they are made again here once, so the first search already groups versions
if (meta_get('label_rules', '') !== LABEL_RULES) labels_refresh();

// shots=<fingerprint>: every shot of one video, for the panel and the big view
if (preg_match('/^[0-9a-f]{24}$/', (string)($_GET['shots'] ?? ''))) {
    echo json_encode(['shots' => analysis_shots($_GET['shots'])], JSON_UNESCAPED_SLASHES); exit;
}
$q      = trim($_GET['q'] ?? '');
$kind   = $_GET['kind'] ?? '';
$dept   = $_GET['dept'] ?? '';
$limit  = min(max((int)($_GET['limit'] ?? 200), 1), 1000);
$offset = max((int)($_GET['offset'] ?? 0), 0);

$where = []; $args = [];
// Each word must appear somewhere in the path, or in the camera or reel the
// file itself names (a search for "FX6" or "A001"). ESCAPE goes on every LIKE, so
// a search for "50%" or "a_b" looks for those characters rather than acting
// as a wildcard.
foreach (preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) as $word) {
    $where[] = "(files.path LIKE ? ESCAPE '\\' OR media.camera LIKE ? ESCAPE '\\' OR media.reel LIKE ? ESCAPE '\\')";
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word) . '%';
    array_push($args, $like, $like, $like);
}
$words = count($where); $wargs = count($args);     // the conditions after these are filters: moments obey them too
if ($kind !== '' && $kind !== 'all') { $where[] = 'kind = ?'; $args[] = $kind; }
// where=ARCHIVE: only under a folder of that name (the Filters panel's Where)
if (($place = (string)($_GET['where'] ?? '')) !== '') {
    $where[] = "files.path LIKE ? ESCAPE '\\'"; $args[] = '%/' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $place) . '/%';
}
if ($dept !== '')                   { $where[] = 'dept = ?'; $args[] = $dept; }
// What each file is (labels.php): Deliverables, the stock library and its parts, AI-generated,
// Photos, Design … from names, folders and sizes, wherever the files are; nothing is moved.
// p=<path>: that one file (the panel, for a video found by what it shows)
if (($one = (string)($_GET['p'] ?? '')) !== '') { $where[] = 'files.path = ?'; $args[] = $one; }
// v=<key>: every version of one piece (labels.php version_of), for the panel beside the results
if (($v = (string)($_GET['v'] ?? '')) !== '') { $where[] = 'files.path IN (SELECT path FROM labels WHERE vkey = ?)'; $args[] = $v; }
$in = (string)($_GET['in'] ?? '');
if ($in !== '') {
    if (!isset(LABEL_SHOWN[$in])) { echo json_encode(['error' => 'unknown section: ' . $in]); exit; }
    labels_refresh();                                       // files new since the last look get theirs first
    $want = LABEL_SHOWN[$in];
    $where[] = 'files.path IN (SELECT path FROM labels WHERE label IN (' . implode(',', array_fill(0, count($want), '?')) . '))';
    array_push($args, ...$want);
}

$sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$bind = function (SQLite3Stmt $s, array $a) {
    foreach ($a as $i => $v) $s->bindValue($i + 1, $v);
};

$db = db();
$t0 = microtime(true);

if ($where === []) {
    echo json_encode(['q' => '', 'total' => 0, 'bytes' => 0,
        'counts' => ['all' => 0], 'rows' => [], 'offset' => 0, 'limit' => $limit,
        'ms' => 0, 'indexed' => (int)meta_get('files_imported', '0'),
        'imported' => (int)meta_get('imported_at', '0')]);
    exit;
}

// the rows
$st = $db->prepare("SELECT files.path, name, ext, kind, bytes, year, event, dept,
                           width, height, fps, codec, duration, proxy_at, recorded, timecode, reel, camera,
                           (SELECT group_concat(place || '|' || present || '|' || checked, ';') FROM copies WHERE file_id = files.id) AS copies,
                           (SELECT fp || ':' || shot FROM moments WHERE moments.path = files.path AND kind = 'shot' ORDER BY shot LIMIT 1) AS still,
                           lb.vkey, lb.vrank, (SELECT COUNT(*) FROM labels x WHERE x.vkey = lb.vkey) AS versions
                    FROM files LEFT JOIN media ON media.file_id = files.id LEFT JOIN labels lb ON lb.path = files.path$sql ORDER BY files.path LIMIT ? OFFSET ?");
$bind($st, $args);
$st->bindValue(count($args) + 1, $limit, SQLITE3_INTEGER);
$st->bindValue(count($args) + 2, $offset, SQLITE3_INTEGER);
$res = $st->execute();
if ($res === false) { echo json_encode(['error' => 'rows query: ' . $db->lastErrorMsg()]); exit; }
$rows = [];
// On a Mac, the archive itself is a drive that can be unplugged
$arch_away = on_mac() && !is_dir(archive_dir());
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    // a file on a drive kept where it is: which drive, and whether it is plugged in now
    if ($d = drive_of($r['path'])) { $r['drive'] = (string)$d['name']; $r['away'] = empty($d['connected']); }
    elseif ($arch_away && str_starts_with($r['path'], archive_dir() . '/')) { $r['drive'] = basename(archive_dir()); $r['away'] = true; }
    $rows[] = $r;
}

// true totals, and per-kind counts for the chips — one scan, not five
$counts = ['all' => 0];
$cs = $db->prepare("SELECT kind, COUNT(*) c, SUM(bytes) b FROM files LEFT JOIN media ON media.file_id = files.id$sql GROUP BY kind");
$bind($cs, $args);
$cr = $cs->execute();
if ($cr === false) { echo json_encode(['error' => 'counts query: ' . $db->lastErrorMsg()]); exit; }
$bytes = 0;
while ($r = $cr->fetchArray(SQLITE3_ASSOC)) {
    $counts[$r['kind']] = (int)$r['c'];
    $counts['all'] += (int)$r['c'];
    $bytes += (int)$r['b'];
}

// What the footage shows and what was said, when it has been described.
$moments = ['count' => 0, 'rows' => []];
if ($q !== '' && ($kind === '' || $kind === 'all' || $kind === 'video')) {
    try {
        $moments = analysis_videos($q);
        // which piece each moment's file is a version of, so a shot found in v2 and v3 shows once
        $vs = $db->prepare('SELECT vkey, vrank, (SELECT COUNT(*) FROM labels x WHERE x.vkey = labels.vkey) n FROM labels WHERE path = ?');
        foreach ($moments['rows'] as &$m) {
            $vs->bindValue(1, $m['path']); $r = $vs->execute()->fetchArray(SQLITE3_ASSOC) ?: []; $vs->reset();
            $m['vkey'] = $r['vkey'] ?? null; $m['vrank'] = (int)($r['vrank'] ?? 0); $m['versions'] = (int)($r['n'] ?? 0);
        }
        unset($m);
        // Filters on (a kind, where, what it is): only moments of files that pass them
        $fw = array_slice($where, $words); $fa = array_slice($args, $wargs);
        if ($fw) {
            $ok = $db->prepare('SELECT 1 FROM files LEFT JOIN media ON media.file_id = files.id WHERE files.path = ? AND ' . implode(' AND ', $fw));
            $moments['rows'] = array_values(array_filter($moments['rows'], function ($m) use ($ok, $fa) {
                $ok->bindValue(1, $m['path']); foreach ($fa as $i => $v) $ok->bindValue($i + 2, $v);
                $r = $ok->execute()->fetchArray(); $ok->reset(); return (bool)$r;
            }));
            $moments['count'] = count($moments['rows']);
            $moments['moments'] = array_sum(array_map(fn($m) => count($m['found']), $moments['rows']));
        }
    } catch (Throwable $e) { $moments['error'] = $e->getMessage(); }
}

echo json_encode([
    'moments'  => $moments,
    'q'        => $q,
    'total'    => $counts['all'],
    'bytes'    => $bytes,
    'counts'   => $counts,
    'rows'     => $rows,
    'offset'   => $offset,
    'limit'    => $limit,
    'ms'       => round((microtime(true) - $t0) * 1000, 1),
    'indexed'  => (int)meta_get('files_imported', '0'),
    'imported' => (int)meta_get('imported_at', '0'),
], JSON_UNESCAPED_SLASHES);
