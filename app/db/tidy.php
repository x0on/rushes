<?php
// tidy.php — the tidy-up. What the copies brought into ARCHIVE, and where each
// part of it goes on the shelf.
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

function out(array $a) { echo json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }
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
    return ['groups' => array_values($g), 'shelf' => $shelf, 'records' => count($recs)];
}

// Where a row goes for a given department — worked out here, never sent by the page.
function dest_for(array $row, string $dept, string $shelf): ?string {
    $folder = dept_folder($dept);
    if ($folder === null) return null;
    $tail = $row['base'] !== ''
        ? substr($row['key'], strlen($row['base']))                        // below the old department folder
        : '/' . (substr($row['key'], strlen($row['root']) + 1) ?: basename($row['root']));   // the whole path, kept
    return $shelf . '/' . $folder . $tail;
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
    if (shelf_name() === '') bail(400, 'Choose the folder ' . strtolower(shelf_word(true)) . ' live in first: Reorganize → 00.');
    $p = proposal(); $lines = []; $files = 0;
    // As JSON, not pick[...] fields: a folder called "Gala [2024]" would break those.
    $pick = json_decode((string)($_POST['picks'] ?? ''), true);
    if (!is_array($pick)) bail(400, 'Nothing is picked to move.');
    foreach ($p['groups'] as $row) {
        $d = (string)($pick[$row['key']] ?? '');
        if ($d === '') continue;                              // left where it is
        $to = dest_for($row, $d, $p['shelf']);
        if ($to === null) bail(400, "“{$d}” is not in the plan any more — reload the page.");
        $lines[] = "map\t{$row['key']}\t$to"; $files += $row['n'];
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
