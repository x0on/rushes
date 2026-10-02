<?php
// search.php — the query endpoint. Returns JSON, not a 102 MB download.
//
//   search.php?q=DJI_0002&kind=video&limit=200&offset=0
//   search.php?in=library/music          the stock library, or one part of it, with or without words
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
// What editors exported into a project's Output folder (Rushes Watcher): the deliverables.
if (($_GET['in'] ?? '') === 'deliverables' && shelf_name() !== '') {
    $where[] = "path LIKE ? ESCAPE '\\' AND path LIKE ? ESCAPE '\\'";
    $esc = fn($s) => str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    array_push($args, $esc(rtrim(archive_dir(), '/') . '/' . shelf_name() . '/Projects/') . '%', '%/Output/%');
}
// The shared stock library (HOW-IT-WORKS.md → Projects in and out): its own
// folder of the archive, wherever Setup says it is, so a section of its own.
if (preg_match('#^library(?:/(music|stock|sfx))?$#', (string)($_GET['in'] ?? ''), $m)) {
    $sub = ['music' => 'Music/', 'stock' => 'Stock footage/', 'sfx' => 'Sound effects/'][$m[1] ?? ''] ?? '';
    $pre = rtrim(archive_dir(), '/') . '/' . trim((string)(settings()['library']['folder'] ?? 'Stock Library'), '/') . "/$sub";
    $where[] = "path LIKE ? ESCAPE '\\'"; $args[] = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $pre) . '%';
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
                           (SELECT group_concat(place || '|' || present || '|' || checked, ';') FROM copies WHERE file_id = files.id) AS copies
                    FROM files LEFT JOIN media ON media.file_id = files.id$sql ORDER BY path LIMIT ? OFFSET ?");
$bind($st, $args);
$st->bindValue(count($args) + 1, $limit, SQLITE3_INTEGER);
$st->bindValue(count($args) + 2, $offset, SQLITE3_INTEGER);
$res = $st->execute();
if ($res === false) { echo json_encode(['error' => 'rows query: ' . $db->lastErrorMsg()]); exit; }
$rows = [];
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;

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
    require_once __DIR__ . '/analysis.php';
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
