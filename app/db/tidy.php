<?php
// tidy.php — the tidy-up. What the copies brought into ARCHIVE, and where each
// part of it goes on the shelf.
//
// And what is already in the archive, outside the shelf and outside Rushes'
// own folders (an old server's layout, a drive's own folders): grouped the same
// way from the catalogue, and moved as they are ("here" rows).
//
//   GET                           the proposal, as JSON
//   POST go=1 picks={<folder>: <department>}   approve: writes the plan, queues it
//   POST undo=<record file>       queue putting one tidy-up back
//
// Where a file goes is read from the ORIGIN record, never from where it sits
// now: footage copied before the records existed was sorted by date and lost
// its folder, but its record still says where it came from. Everything below
// the department keeps the layout it had on the source —
//   share/Departments/Parks & Recreation/2024/Kite Fest/A001.MXF
//   → Library/PARKS/2024/Kite Fest/A001.MXF
// Only the part above the department changes. Nothing is renamed or guessed.
//
// This page only decides. The helper moves, one file at a time, never over
// another file and never while its folder is still being copied, and writes
// every move into a record of its own.
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
if (!may_act((string)($_POST['pass'] ?? ''))) { http_response_code(403); echo '{"error":"sign in first"}'; exit; }
@ini_set('memory_limit', '512M');           // ponytail: every record in memory; split by source if it outgrows this

function out(array $a) { echo json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function bail(int $code, string $why) { http_response_code($code); out(['error' => $why]); }

function origin_dir(): string { return archive_dir() . '/_rushes/origin'; }
// The helper's name for the shelf, which is what the plan is written in.
function shelf_as_helper(): string { return helper_archive() . substr(shelf_dir(), strlen(archive_dir())); }

// Which department a folder name is, or null. An exact name or linked folder
// first, then the same fuzzy match that linked the folders in Structure.
function dept_named(string $c): ?string {
    static $memo = [];
    if (array_key_exists($c, $memo)) return $memo[$c];
    $best = null; $score = 0.75;             // below this it is a guess, and guesses are not made here
    foreach (departments() as $d) {
        $f = ($d['folder'] ?? '') ?: $d['name'];
        if (strcasecmp($c, $d['name']) === 0 || strcasecmp($c, $f) === 0) return $memo[$c] = $d['name'];
        $s = max(dept_match($d['name'], $c), dept_match($f, $c));
        if ($s >= $score && $s > 0) { $best = $d['name']; $score = $s + 1e-9; }
    }
    return $memo[$c] = $best;
}

// Source folders a copy is still working through: queued, not yet copied.
function still_copying(): array {
    $done = [];
    foreach (@file(web_dir() . '/ingest-history.tsv') ?: [] as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if (($f[1] ?? '') === 'copied') $done[$f[2] ?? ''] = true;
    }
    $busy = [];
    foreach (@file(web_dir() . '/ingest-queue.tsv') ?: [] as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if ($f[0] === 'copy' && ($f[1] ?? '') !== '' && !isset($done[$f[1]])) $busy[] = $f[1];
    }
    return $busy;
}

function proposal(): array {
    $area = helper_archive() . '/ARCHIVE/';
    // Every record played forward, oldest first: where each file is now, and
    // where it came from. The same replay the helper does before it moves.
    $at = []; $roots = [];
    if (is_dir(origin_dir()) && !is_readable(origin_dir()))
        bail(500, 'This machine cannot read ' . origin_dir() . ' — give the web server read access to _rushes.');
    $recs = glob(origin_dir() . '/*.tsv') ?: []; sort($recs);
    foreach ($recs as $rec) {
        $h = @fopen($rec, 'r'); if (!$h) continue;
        $root = count($roots);
        while (($l = fgets($h)) !== false) {
            $p = explode("\t", rtrim($l, "\n"));
            if ($p[0] === '# source root') { $roots[$root] = $p[1] ?? ''; continue; }
            if (count($p) < 4 || $p[1] === '' || $p[2] === '') continue;
            if ($p[0] === 'moved') {
                if (isset($at[$p[1]])) { $at[$p[2]] = $at[$p[1]]; unset($at[$p[1]]); }
            } elseif (in_array($p[0], ['copied', 'traced', 'already'], true) && str_starts_with($p[2], $area)) {
                // a file's first real origin wins; "already" only when there is no other
                if (!isset($at[$p[2]]) || ($p[0] !== 'already' && $at[$p[2]][3] === 'already'))
                    $at[$p[2]] = [$p[1], $root, (int)$p[3], $p[0]];
            }
        }
        fclose($h);
    }

    $shelf = shelf_as_helper();
    $busy  = still_copying();
    $g = [];
    foreach ($at as $now => [$src, $ri, $bytes]) {
        if (!str_starts_with($now, $area)) continue;          // on the shelf already
        $root = $roots[$ri] ?? '';
        if ($root === '' || !str_starts_with($src, $root . '/')) $root = dirname($src);
        $parts = explode('/', substr($src, strlen($root) + 1));
        array_pop($parts);                                   // the file itself
        $dept = null; $at_i = -1;
        foreach ($parts as $i => $c) if (($dept = dept_named($c)) !== null) { $at_i = $i; break; }
        if ($dept !== null) {
            // one row per department folder and the folder under it: Parks & Recreation / 2024
            $base = $root . '/' . implode('/', array_slice($parts, 0, $at_i + 1));
            $key  = $base . (isset($parts[$at_i + 1]) ? '/' . $parts[$at_i + 1] : '');
        } else {
            $base = '';
            $key  = $root . '/' . implode('/', array_slice($parts, 0, 2));
            $key  = rtrim($key, '/');
        }
        if (!isset($g[$key])) {
            $g[$key] = ['key' => $key, 'root' => $root, 'base' => $base, 'dept' => $dept,
                        'via' => $dept !== null ? $parts[$at_i] : '', 'n' => 0, 'bytes' => 0,
                        'busy' => false, 'eg' => ''];
            foreach ($busy as $b) if ($b === $key || str_starts_with($key, $b . '/') || str_starts_with($b, $key . '/')) $g[$key]['busy'] = true;
        }
        $g[$key]['n']++; $g[$key]['bytes'] += $bytes;
        if ($g[$key]['eg'] === '') $g[$key]['eg'] = substr($src, strlen($key));
    }
    ksort($g, SORT_NATURAL | SORT_FLAG_CASE);
    $h = here_groups();
    return ['groups' => array_merge(array_values($g), $h), 'shelf' => $shelf, 'records' => count($recs), 'here' => count($h)];
}

// Folders already in the archive that are not filed yet: from the catalogue. Two places:
//   at the top of the archive, outside the shelf (an old server's layout, a drive's own folders);
//   inside a named shelf, its own top folders that are not a department ("VIDEOS/VIDEO from QNAP":
//   whole drives copied onto the shelf, to be filed from there). Never Rushes' own folders, and never
//   a folder Setup says to leave as it is (kept_folders(): a photo library, say).
// Grouped as the records are: a department in the path, that folder and the one under it; none, the
// first two levels at the top (three inside the shelf: the drive, then its folders). here_files()
// calls $each(path, bytes, key, base, dept, via, kind, root) for every such file, so the plan can
// name exactly the files a picked row counted, and no others.
function here_files(callable $each): void {
    require_once __DIR__ . '/schema.php';
    $a = rtrim(archive_dir(), '/'); $L = strlen($a) + 1;
    $kept = array_map('strtolower', kept_folders());
    $names = array_map(fn($d) => strtolower($d['name']), departments());
    $is_dept = fn(string $f) => $f === 'Projects' || dept_of_folder($f) !== null || in_array(strtolower($f), $names, true);
    $own = fn(string $f) => $f === '' || strpbrk($f[0], '_@.#') !== false || in_array($f, ['ARCHIVE', 'PROXIES'], true);
    $off_shelf = function (string $top) use ($kept, $is_dept, $own) {
        if ($own($top) || in_array(strtolower($top), $kept, true)) return false;   // Rushes' own, hidden, the bin, kept as it is
        if (!shelf_is_top()) return $top !== shelf_name();
        return !$is_dept($top);
    };
    $sh = shelf_is_top() ? '' : shelf_name();
    $tops = []; $inside = [];
    $q = db()->query("SELECT path, bytes, kind FROM files WHERE substr(path, 1, " . $L . ") = '" . SQLite3::escapeString("$a/") . "'");
    while ($r = $q->fetchArray(SQLITE3_ASSOC)) {
        $parts = explode('/', substr($r['path'], $L));
        $root = $a; $levels = 2;
        if ($sh !== '' && $parts[0] === $sh) {
            array_shift($parts); $root = "$a/$sh"; $levels = 3;
            if (count($parts) < 2) continue;                  // a file loose on the shelf: left where it is
            $inside[$parts[0]] ??= !$own($parts[0]) && !$is_dept($parts[0]) && !in_array(strtolower($parts[0]), $kept, true);
            if (!$inside[$parts[0]]) continue;                // a department's folder: filed already
        } else {
            if (count($parts) < 2) continue;                  // a file loose at the top: left where it is
            $tops[$parts[0]] ??= $off_shelf($parts[0]);
            if (!$tops[$parts[0]]) continue;
        }
        array_pop($parts);
        $dept = null; $at_i = -1;
        foreach ($parts as $i => $c) if (($dept = dept_named($c)) !== null) { $at_i = $i; break; }
        if ($dept !== null) {
            $base = "$root/" . implode('/', array_slice($parts, 0, $at_i + 1));
            $key  = $base . (isset($parts[$at_i + 1]) ? '/' . $parts[$at_i + 1] : '');
        } else {
            $base = ''; $key = "$root/" . implode('/', array_slice($parts, 0, $levels));
        }
        $each($r['path'], (int)$r['bytes'], $key, $base, $dept, $dept !== null ? $parts[$at_i] : '', (string)$r['kind'], $root);
    }
}

// ── the year a folder goes under (VIDEOS / department / year / the folder) ──────────────────
// Evidence, strongest first, and never a person's guess:
//   1. a year written in the folder's own path ("STOC 2025", "2019/Kite Fest"), the deepest one;
//   2. its clips' dates: for each clip the oldest believable date it carries. A copy can only make a
//      date newer (Finder's "created" is the day of the copy), so the oldest is the closest to the shoot.
//      What the catalogue holds is the camera's own date (media.recorded, read with the preview) and
//      the file's modified date (kept by every copy Rushes and rsync make). Before 2005 or in the
//      future is a clock never set, and is not believed.
// The folder's year is the one most of its clips agree on (4 in 5, at least). Clips that disagree
// mean no year: the folder goes straight under its department, whole, rather than in a wrong year.
const YEAR_AGREE = 0.8;                 // ponytail: a fixed share; a setting if 4 in 5 proves too strict
const YEAR_SAMPLE = 200;                // clips read per folder at most: the dates of a big folder agree long before
function year_in(string $s): ?string {
    // a year on its own ("STOC 2025") or the start of a date written as digits ("20260205BikeLanes")
    return preg_match_all('/(?<![0-9])(20[0-3][0-9]|199[0-9])(?:(?![0-9])|(?=[01][0-9][0-3][0-9](?![0-9])))/', $s, $m) ? end($m[1]) : null;
}
function clip_year(string $path): ?string {
    static $put = null, $get = null, $rec = null;
    $db = db();
    if ($put === null) {
        $db->exec('CREATE TABLE IF NOT EXISTS filedates (path TEXT PRIMARY KEY, t INTEGER)');
        $put = $db->prepare('INSERT OR REPLACE INTO filedates (path, t) VALUES (?, ?)');
        $get = $db->prepare('SELECT t FROM filedates WHERE path = ?');
        $rec = $db->prepare('SELECT m.recorded FROM files f JOIN media m ON m.file_id = f.id WHERE f.path = ?');
    }
    $get->bindValue(1, $path); $t = $get->execute()->fetchArray(SQLITE3_NUM)[0] ?? null; $get->reset();
    if ($t === null) {
        $ok = fn($x) => $x !== false && $x !== null && $x >= 1104537600 && $x <= time() + 86400;   // 2005-01-01 to now
        $c = []; $m = @filemtime($path); if ($ok($m)) $c[] = $m;
        $rec->bindValue(1, $path); $r = $rec->execute()->fetchArray(SQLITE3_NUM)[0] ?? ''; $rec->reset();
        if ($r !== '' && $ok($x = strtotime((string)$r))) $c[] = $x;
        $t = $c ? min($c) : 0;
        $put->bindValue(1, $path); $put->bindValue(2, $t); $put->execute(); $put->reset();
    }
    return $t ? date('Y', (int)$t) : null;
}
// -> [year or null, why]
function folder_year(string $key, string $root, array $clips): array {
    $own = basename($key);
    if (preg_match('/^(20[0-3][0-9]|199[0-9])$/', $own)) return [null, 'a year folder already'];
    if (($y = year_in(substr($key, strlen($root)))) !== null) return [$y, 'year in the folder\'s name'];
    if (!$clips) return [null, 'no clips to date it by'];
    $step = max(1, intdiv(count($clips), YEAR_SAMPLE)); $n = [];
    for ($i = 0; $i < count($clips); $i += $step) { if (($y = clip_year($clips[$i])) !== null) $n[$y] = ($n[$y] ?? 0) + 1; }
    if (!$n) return [null, 'its clips carry no believable date'];
    arsort($n); $top = array_key_first($n);
    $say = implode(', ', array_map(fn($y, $k) => "$y × $k", array_keys(array_slice($n, 0, 3, true)), array_slice($n, 0, 3, true)));
    // agreement among the clips that carry a date; the undated ones neither help nor stand in the way
    return $n[$top] >= YEAR_AGREE * array_sum($n) ? [(string)$top, "clips: $say"] : [null, "clips disagree ($say): no year"];
}

function here_groups(): array {
    $g = []; $clips = [];
    here_files(function ($path, $bytes, $key, $base, $dept, $via, $kind, $root) use (&$g, &$clips) {
        if (!isset($g[$key])) $g[$key] = ['key' => $key, 'root' => $root, 'base' => $base, 'dept' => $dept,
            'via' => $via, 'n' => 0, 'bytes' => 0, 'busy' => false, 'eg' => '', 'here' => true,
            'flat' => shelf_name() !== '' && $root === rtrim(archive_dir(), '/') . '/' . shelf_name()];
        $g[$key]['n']++; $g[$key]['bytes'] += $bytes;
        if ($g[$key]['eg'] === '') $g[$key]['eg'] = substr($path, strlen($key));
        if ($kind === 'video') $clips[$key][] = $path;
    });
    foreach ($g as $k => $row) [$g[$k]['year'], $g[$k]['year_why']] = folder_year($k, $row['root'], $clips[$k] ?? []);
    ksort($g, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($g);
}

// Where a row goes for a given department — worked out here, never sent by the page.
function dest_for(array $row, string $dept, string $shelf): ?string {
    $folder = dept_folder($dept);
    if ($folder === null) return null;
    $inShelf = !empty($row['flat']);
    $tail = $row['base'] !== ''
        ? substr($row['key'], strlen($row['base']))                        // below the old department folder
        : ($inShelf ? '/' . basename($row['key'])                          // a drive copied onto the shelf: its folder, not the drive's name
                    : '/' . (substr($row['key'], strlen($row['root']) + 1) ?: basename($row['root'])));   // the whole path, kept
    return $shelf . '/' . $folder . (!empty($row['year']) ? '/' . $row['year'] : '') . $tail;
}

// The tidy-ups done so far, newest first, and whether each has been put back.
function runs(): array {
    $out = []; $undone = [];
    foreach (glob(origin_dir() . '/* untidy.tsv') ?: [] as $f)
        if (preg_match('/^# source folder\tundo of (.+)$/m', (string)@file_get_contents($f, false, null, 0, 4000), $m)) $undone[$m[1]] = true;
    foreach (glob(origin_dir() . '/* tidy.tsv') ?: [] as $f) {
        $n = ['moved' => 0, 'skipped' => 0, 'failed' => 0]; $b = 0;
        foreach (new SplFileObject($f) as $l) {
            $p = explode("\t", (string)$l);
            if (($p[4] ?? '') === "the folder's note\n") continue;      // a note is not footage
            if (isset($n[$p[0]])) { $n[$p[0]]++; if ($p[0] === 'moved') $b += (int)($p[3] ?? 0); }
        }
        if (!$n['moved'] && !$n['skipped'] && !$n['failed']) continue;    // found nothing to move: not worth a line
        $name = basename($f);
        $out[] = ['record' => $name, 'when' => filemtime($f), 'ago' => ago_words(filemtime($f)),
                  'moved' => $n['moved'], 'left' => $n['skipped'] + $n['failed'], 'bytes' => $b,
                  'undone' => isset($undone[$name])];
    }
    usort($out, fn($a, $b) => $b['when'] <=> $a['when']);
    return $out;
}

// Tidy-ups the helper has finished with (done, or refused), as queue lines.
function tidies_finished(): array {
    $out = [];
    foreach (@file(web_dir() . '/ingest-history.tsv') ?: [] as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if (in_array($f[1] ?? '', ['tidied', 'untidied', 'refused'], true) && preg_match('/^(un)?tidy /', $f[2] ?? ''))
            $out[preg_replace('/ /', "\t", $f[2], 1)] = true;
    }
    return $out;
}

// Add a line to what the helper should do, after any card waiting at Ingest
// and before the long copies — a tidy-up is renames, it takes minutes.
function queue_add(string $line): void {
    $OUT = web_dir() . '/ingest-queue.tsv';
    $lock = fopen(web_dir() . '/ingest-queue.lock', 'c'); if ($lock) flock($lock, LOCK_EX);   // one writer at a time
    $first = []; $rest = []; $gone = tidies_finished();
    foreach (@file($OUT) ?: [] as $l) {
        $l = rtrim($l, "\n");
        if ($l === '' || $l === $line || isset($gone[$l])) continue;
        if (str_starts_with($l, "ingest\t") || str_starts_with($l, "upload\t") || str_starts_with($l, "tidy\t") || str_starts_with($l, "untidy\t")) $first[] = $l;
        else $rest[] = $l;
    }
    $all = array_merge($first, [$line], $rest);
    if (@file_put_contents("$OUT.new", implode("\n", $all) . "\n") === false || !@rename("$OUT.new", $OUT))
        bail(500, 'Could not write the queue — is the web folder writable?');
}

if (($_POST['go'] ?? '') === '1') {
    if (helper_archive() === '') bail(400, 'Setup does not know where the helper finds the archive yet.');
    if (!shelf_chosen()) bail(400, 'Choose the folder ' . strtolower(shelf_word(true)) . ' live in first: Setup → Archive structure.');
    $p = proposal(); $lines = []; $files = 0; $here = [];
    // As JSON, not pick[...] fields: a folder called "Gala [2024]" would break those.
    $pick = json_decode((string)($_POST['picks'] ?? ''), true);
    if (!is_array($pick)) bail(400, 'Nothing is picked to move.');
    foreach ($p['groups'] as $row) {
        $d = (string)($pick[$row['key']] ?? '');
        if ($d === '') continue;                              // left where it is
        $to = dest_for($row, $d, $p['shelf']);
        if ($to === null) bail(400, "“{$d}” is not in the plan any more — reload the page.");
        if (!empty($row['here'])) $here[$row['key']] = $to;      // its files, one line each, below
        else $lines[] = "map\t{$row['key']}\t$to";
        $files += $row['n'];
    }
    // Folders already in the archive: exactly the files the picked rows counted,
    // each named as the helper sees it, with where it goes.
    if ($here) {
        $A = rtrim(archive_dir(), '/'); $H = rtrim(helper_archive(), '/\\');
        here_files(function ($path, $bytes, $key) use (&$lines, $here, $A, $H) {
            if (!isset($here[$key]) || str_contains($path, "\t") || str_contains($path, "\n")) return;
            $lines[] = "file\t" . $H . substr($path, strlen($A)) . "\t" . $here[$key] . substr($path, strlen($key));
        });
    }
    if (!$lines) bail(400, 'Nothing is picked to move.');
    $id = date('Ymd-His');
    $plan = web_dir() . "/tidy-$id.tsv";
    if (@file_put_contents("$plan.new", implode("\n", $lines) . "\n") === false || !@rename("$plan.new", $plan))
        bail(500, 'Could not write the plan — is the web folder writable?');
    queue_add("tidy\t$id");
    out(['queued' => $id, 'rows' => count($lines), 'files' => $files]);
}

if (isset($_POST['undo'])) {
    $name = basename((string)$_POST['undo']);
    if (!str_ends_with($name, ' tidy.tsv') || !is_file(origin_dir() . "/$name")) bail(400, 'There is no such tidy-up.');
    queue_add("untidy\t$name");
    out(['queued' => 'undo', 'record' => $name]);
}

$p = proposal();
$waiting = []; $gone = tidies_finished();
foreach (@file(web_dir() . '/ingest-queue.tsv') ?: [] as $l)
    if (preg_match('/^(un)?tidy\t(.+)$/', rtrim($l, "\n"), $m) && !isset($gone[$m[0]])) $waiting[] = $m[0];
out($p + [
    'depts'   => array_map(fn($d) => ['name' => $d['name'], 'folder' => dept_folder($d['name'])], departments()),
    'runs'    => runs(),
    'waiting' => $waiting,
    'helper'  => helper_name(),
]);
