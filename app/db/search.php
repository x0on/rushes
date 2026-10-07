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
    $where[] = "(path LIKE ? ESCAPE '\\' OR media.camera LIKE ? ESCAPE '\\' OR media.reel LIKE ? ESCAPE '\\')";
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word) . '%';
    array_push($args, $like, $like, $like);
}
if ($kind !== '' && $kind !== 'all') { $where[] = 'kind = ?'; $args[] = $kind; }
if ($dept !== '')                   { $where[] = 'dept = ?'; $args[] = $dept; }
// What each file is (labels.php): Deliverables, the stock library and its parts, AI-generated,
// Photos, Design … from names, folders and sizes, wherever the files are; nothing is moved.
$in = (string)($_GET['in'] ?? '');
if ($in !== '') {
    require_once __DIR__ . '/labels.php';
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
$st = $db->prepare("SELECT path, name, ext, kind, bytes, year, event, dept,
                           width, height, fps, codec, duration, proxy_at, recorded, timecode, reel, camera,
                           (SELECT group_concat(place || '|' || present || '|' || checked, ';') FROM copies WHERE file_id = files.id) AS copies,
                           (SELECT fp || ':' || shot FROM moments WHERE moments.path = files.path AND kind = 'shot' ORDER BY shot LIMIT 1) AS still
                    FROM files LEFT JOIN media ON media.file_id = files.id$sql ORDER BY path LIMIT ? OFFSET ?");
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
    try { $moments = analysis_search($q); } catch (Throwable $e) { $moments['error'] = $e->getMessage(); }
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
