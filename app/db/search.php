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
// facets=1: what the Filters panel can offer from this archive: its years, and the moods the AI used most
if (!empty($_GET['facets'])) {
    $years = []; $r = db()->query("SELECT year, COUNT(*) n FROM files WHERE year IS NOT NULL AND year != '' GROUP BY year ORDER BY year DESC");
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) $years[] = $x;
    $moods = []; $r = db()->query("SELECT mood FROM moments WHERE kind = 'shot' AND mood != ''");
    while ($x = $r->fetchArray(SQLITE3_NUM))
        foreach (preg_split('/\s*,\s*/', strtolower($x[0])) as $w) if ($w !== '') $moods[$w] = ($moods[$w] ?? 0) + 1;
    arsort($moods);
    echo json_encode(['years' => $years, 'moods' => array_slice(array_keys($moods), 0, 12)]); exit;
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
    // the camera and reel are looked up once in the media ledger (a small table), not joined to every file:
    // with "OR media.camera LIKE" in the same condition, each of the catalogue's rows waited for its media row
    $where[] = "(files.path LIKE ? ESCAPE '\\' OR files.id IN (SELECT file_id FROM media WHERE camera LIKE ? ESCAPE '\\' OR reel LIKE ? ESCAPE '\\'))";
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word) . '%';
    array_push($args, $like, $like, $like);
}
$words = count($where); $wargs = count($args);     // the conditions after these are filters: moments obey them too
// +kind: not by the kind index (a quarter of the catalogue fetched out of order is slower than reading it through)
if ($kind !== '' && $kind !== 'all') { $where[] = '+kind = ?'; $args[] = $kind; }
// where=ARCHIVE: only under a folder of that name (the Filters panel's Where)
if (($place = (string)($_GET['where'] ?? '')) !== '') {
    $where[] = "files.path LIKE ? ESCAPE '\\'"; $args[] = '%/' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $place) . '/%';
}
if ($dept !== '')                   { $where[] = 'dept = ?'; $args[] = $dept; }
// The Filters panel. Within a section any of the choices; between sections, all of them.
$pick = fn(string $k, array $known) => array_values(array_intersect(explode(',', (string)($_GET[$k] ?? '')), array_keys($known)));
$any  = function (array $chosen, array $known) { return $chosen ? '(' . implode(' OR ', array_map(fn($c) => $known[$c], $chosen)) . ')' : ''; };
// what the file is, from the media ledger: its shape, resolution and length
$big = 'MAX(media.width, media.height)'; $small = 'MIN(media.width, media.height)';
foreach ([
    'orient' => ['h' => 'media.width > media.height * 1.1', 'v' => 'media.height > media.width * 1.1',
                 's' => 'media.width BETWEEN media.height * 0.9 AND media.height * 1.1'],
    'res'    => ['4k' => "($big >= 3800 OR $small >= 2100)", 'hd' => "($big < 3800 AND $small < 2100 AND $small >= 1060)", 'sd' => "$small < 1060"],
    'len'    => ['s' => 'media.duration < 10', 'm' => 'media.duration >= 10 AND media.duration < 60',
                 'l' => 'media.duration >= 60 AND media.duration < 300', 'xl' => 'media.duration >= 300'],
] as $k => $known) if ($c = $any($pick($k, $known), $known)) $where[] = $c;
if ($years = array_filter(explode(',', (string)($_GET['year'] ?? '')), fn($y) => preg_match('/^\d{4}$/', $y))) {
    $where[] = 'files.year IN (' . implode(',', array_fill(0, count($years), '?')) . ')'; array_push($args, ...$years);
}
if (($ai = (string)($_GET['ai'] ?? '')) === 'only' || $ai === 'none')
    $where[] = ($ai === 'none' ? 'NOT ' : '') . "EXISTS (SELECT 1 FROM labels l WHERE l.path = files.path AND l.label = 'ai')";   // by the file's path (indexed), as the cache check
// what is in the footage, from the descriptions: a shot that is all of these
$cond = []; $cargs = [];
foreach ([
    'shot'   => ['close' => "shot_size IN ('close-up','extreme-close-up')", 'medium' => "shot_size = 'medium'",
                 'full' => "shot_size = 'full'", 'wide' => "shot_size IN ('wide','extreme-wide')"],
    'people' => ['none' => "people = 'none'", 'one' => "people = 'one'", 'two' => "people = 'two'", 'few' => "people IN ('few','crowd','many')"],
    'light'  => ['day' => "light = 'daylight'", 'artificial' => "light = 'artificial'", 'golden' => "light = 'sunrise-sunset'",
                 'night' => "light IN ('dusk','night')"],
] as $k => $known) if ($c = $any($pick($k, $known), $known)) $cond[] = $c;
if ($moods = array_filter(explode(',', strtolower((string)($_GET['mood'] ?? ''))), fn($m) => preg_match('/^[a-z -]{2,30}$/', $m))) {
    $cond[] = '(' . implode(' OR ', array_fill(0, count($moods), "mood LIKE ?")) . ')';
    foreach ($moods as $m) $cargs[] = "%$m%";
}
$cond = implode(' AND ', $cond);
$condAt = -1; $condArg = 0;                                  // where that filter sits, so the videos' own check can leave it out
if ($cond !== '') { $condAt = count($where); $condArg = count($args); $where[] = "files.path IN (SELECT path FROM moments WHERE kind = 'shot' AND $cond)"; array_push($args, ...$cargs); }
// What each file is (labels.php): Deliverables, the stock library and its parts, AI-generated,
// Photos, Design … from names, folders and sizes, wherever the files are; nothing is moved.
// p=<path>: that one file (the panel, for a video found by what it shows)
if (($one = (string)($_GET['p'] ?? '')) !== '') { $where[] = 'files.path = ?'; $args[] = $one; }
// v=<key>: every version of one piece (labels.php version_of), for the panel beside the results
if (($v = (string)($_GET['v'] ?? '')) !== '') { $where[] = 'EXISTS (SELECT 1 FROM labels l WHERE l.path = files.path AND l.vkey = ?)'; $args[] = $v; }
$in = (string)($_GET['in'] ?? ''); $inAt = -1;
if ($in !== '') {
    if (!isset(LABEL_SHOWN[$in])) { echo json_encode(['error' => 'unknown section: ' . $in]); exit; }
    labels_refresh();                                       // files new since the last look get theirs first
    $want = LABEL_SHOWN[$in];
    $inAt = count($where);                                   // where the section's filter sits (the videos' check asks it per file)
    $where[] = 'files.path IN (SELECT path FROM labels WHERE label IN (' . implode(',', array_fill(0, count($want), '?')) . '))';   // a list made once: a section is often opened with no words, and asking per file was slower then
    array_push($args, ...$want);
}

$nothing = $where === [];
// A cache (an app's previews and renders, rebuilt by itself: labels.php) is not footage: never part of Search
if (!$nothing) $where[] = "NOT EXISTS (SELECT 1 FROM labels c WHERE c.path = files.path AND c.label = 'cache')";   // asked per file by its path (indexed): NOT IN made the whole list of cache files again each time it ran
$sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
// Sort by: as found (the folder order for files, best match first for videos), newest, oldest, largest, longest, name
$sort  = (string)($_GET['sort'] ?? '');
$order = ['new' => 'files.year IS NULL, files.year DESC, media.recorded DESC, files.path', 'old' => 'files.year IS NULL, files.year, media.recorded, files.path',
          'big' => 'files.bytes DESC', 'long' => 'media.duration IS NULL, media.duration DESC', 'name' => 'files.name COLLATE NOCASE'][$sort] ?? 'files.path';

$bind = function (SQLite3Stmt $s, array $a) {
    foreach ($a as $i => $v) $s->bindValue($i + 1, $v);
};

$db = db();
$t0 = microtime(true);

if ($nothing) {
    echo json_encode(['q' => '', 'total' => 0, 'bytes' => 0,
        'counts' => ['all' => 0], 'rows' => [], 'offset' => 0, 'limit' => $limit,
        'ms' => 0, 'indexed' => (int)meta_get('files_imported', '0'),
        'imported' => (int)meta_get('imported_at', '0')]);
    exit;
}

// only=videos: the next page of videos found by what they show (the files are on the page already);
// only=files: the next page of files (the videos are). Each asks for its own part alone.
$only = (string)($_GET['only'] ?? '');
$rows = []; $counts = ['all' => 0]; $bytes = 0;
if ($only !== 'videos') {
    // the rows
    $st = $db->prepare("SELECT files.path, name, ext, kind, bytes, year, event, dept,
                               width, height, fps, codec, duration, proxy_at, recorded, timecode, reel, camera, asis,
                               (SELECT group_concat(place || '|' || present || '|' || checked, ';') FROM copies WHERE file_id = files.id) AS copies,
                               (SELECT fp || ':' || shot FROM moments WHERE moments.path = files.path AND kind = 'shot' ORDER BY shot LIMIT 1) AS still,
                               lb.vkey, lb.vrank, (SELECT COUNT(*) FROM labels x WHERE x.vkey = lb.vkey) AS versions
                        FROM files LEFT JOIN media ON media.file_id = files.id LEFT JOIN labels lb ON lb.path = files.path$sql ORDER BY $order LIMIT ? OFFSET ?");
    $bind($st, $args);
    $st->bindValue(count($args) + 1, $limit, SQLITE3_INTEGER);
    $st->bindValue(count($args) + 2, $offset, SQLITE3_INTEGER);
    $res = $st->execute();
    if ($res === false) { echo json_encode(['error' => 'rows query: ' . $db->lastErrorMsg()]); exit; }
    // On a Mac, the archive itself is a drive that can be unplugged
    $arch_away = on_mac() && !is_dir(archive_dir());
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        // a file on a drive kept where it is: which drive, and whether it is plugged in now
        if ($d = drive_of($r['path'])) { $r['drive'] = (string)$d['name']; $r['away'] = empty($d['connected']); }
        elseif ($arch_away && str_starts_with($r['path'], archive_dir() . '/')) { $r['drive'] = basename(archive_dir()); $r['away'] = true; }
        $rows[] = $r;
    }

    // true totals, and per-kind counts for the chips — one scan, not five
    $cs = $db->prepare("SELECT kind, COUNT(*) c, SUM(bytes) b FROM files LEFT JOIN media ON media.file_id = files.id$sql GROUP BY +kind");   // +kind: read the files in their own order; by the kind index, each row was fetched out of order (four times slower)
    $bind($cs, $args);
    $cr = $cs->execute();
    if ($cr === false) { echo json_encode(['error' => 'counts query: ' . $db->lastErrorMsg()]); exit; }
    while ($r = $cr->fetchArray(SQLITE3_ASSOC)) {
        $counts[$r['kind']] = (int)$r['c'];
        $counts['all'] += (int)$r['c'];
        $bytes += (int)$r['b'];
    }

}

// What the footage shows and what was said, when it has been described. Every video found is counted and
// can be reached: a page of VPAGE at a time (voffset), best match first or in the order asked for. Nothing
// found is left out: a count that stopped at the first page read as "only 119 videos show police".
// ponytail: every video found is listed and checked again for each page (about a second for 16,000 videos);
// kept between pages if a search ever finds so many that it shows.
defined('VPAGE') || define('VPAGE', 120);
$voffset = max((int)($_GET['voffset'] ?? 0), 0);
$moments = ['count' => 0, 'moments' => 0, 'rows' => [], 'offset' => $voffset, 'page' => VPAGE];
if ($only !== 'files' && ($q !== '' || $cond !== '') && ($kind === '' || $kind === 'all' || $kind === 'video')) {
    try {
        $found = analysis_videos($q, PHP_INT_MAX, $cond, $cargs)['rows'];
        // the file each video is (its length, shape, size and dates: the tile says them, and they sort), and the
        // filters that are on (where, what it is, never a cache file): asked for 500 videos at a time, once
        // (not "what is in the footage" again: the videos were found by it already, and its list was made anew for every 500)
        $fw = $where; $fa = $args;
        if ($inAt >= 0) $fw[$inAt] = 'EXISTS (SELECT 1 FROM labels l WHERE l.path = files.path AND l.label IN (' . implode(',', array_fill(0, count(LABEL_SHOWN[$in]), '?')) . '))';
        if ($condAt >= 0) { array_splice($fw, $condAt, 1); array_splice($fa, $condArg, count($cargs)); }
        $fw = array_slice($fw, $words); $fa = array_slice($fa, $wargs); $file = [];
        foreach (array_chunk(array_values(array_unique(array_column($found, 'path'))), 500) as $some) {
            $st = $db->prepare('SELECT files.path, files.year, files.bytes, media.duration, media.width, media.height, media.recorded, media.proxy_at
                FROM files LEFT JOIN media ON media.file_id = files.id WHERE files.path IN (' . implode(',', array_fill(0, count($some), '?')) . ')'
                . ($fw ? ' AND ' . implode(' AND ', $fw) : ''));
            $bind($st, array_merge($some, $fa));
            $r = $st->execute();
            while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $file[$x['path']] = $x;
        }
        $vrows = [];
        foreach ($found as $m) if (isset($file[$m['path']])) $vrows[] = $m + ['name' => basename($m['path'])] + $file[$m['path']];
        $moments['count'] = count($vrows);
        $moments['moments'] = array_sum(array_map(fn($m) => count($m['found']), $vrows));
        $by = ['new' => fn($m) => [-(int)($m['year'] ?? 0), $m['recorded'] ?? ''], 'old' => fn($m) => [(int)($m['year'] ?? 9999), $m['recorded'] ?? ''],
               'big' => fn($m) => -(int)($m['bytes'] ?? 0), 'long' => fn($m) => -(float)($m['duration'] ?? 0), 'name' => fn($m) => strtolower($m['name'])][$sort] ?? null;
        if ($by) usort($vrows, fn($a, $b) => $by($a) <=> $by($b));
        // Words to narrow it with: the tags and themes these videos have most, not the words already typed.
        // Only offered; a click adds the word to the search, and taking it out of the box widens it again.
        if ($voffset === 0) {
            $typed = array_flip(preg_split('/\s+/', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY)); $n = [];
            foreach ($vrows as $m)
                // single words only ("parks & recreation" offers parks, and recreation): a chip adds one word to the search,
                // and a sign like & is not a word anything contains
                foreach (array_unique(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($m['tags'] . ' ' . $m['themes']), -1, PREG_SPLIT_NO_EMPTY)) as $w)
                    if (!isset($typed[$w]) && mb_strlen($w) > 2 && !in_array($w, ['and', 'the', 'for', 'with'], true)) $n[$w] = ($n[$w] ?? 0) + 1;
            arsort($n);
            // a word every video has narrows nothing; one only a single video has is not a direction
            $n = array_filter($n, fn($c) => $c > 1 && $c < count($vrows));
            $moments['suggest'] = array_map(fn($w, $c) => [(string)$w, $c], array_keys(array_slice($n, 0, 12, true)), array_slice($n, 0, 12));
        }
        $page = array_slice($vrows, $voffset, VPAGE);
        // which piece each video is a version of, so a shot found in v2 and v3 shows once (this page's only)
        $vs = $db->prepare('SELECT vkey, vrank, (SELECT COUNT(*) FROM labels x WHERE x.vkey = labels.vkey) n FROM labels WHERE path = ?');
        foreach ($page as &$m) {
            $vs->bindValue(1, $m['path']); $r = $vs->execute()->fetchArray(SQLITE3_ASSOC) ?: []; $vs->reset();
            $m['vkey'] = $r['vkey'] ?? null; $m['vrank'] = (int)($r['vrank'] ?? 0); $m['versions'] = (int)($r['n'] ?? 0);
        }
        unset($m);
        $moments['rows'] = $page;
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
