<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// dupgroups.php — Manage → Duplicates: what Find duplicates found, to look at
// before anything moves (HOW-IT-WORKS.md → Duplicates). Each group is one
// file that exists more than once: the copy Rushes keeps, and the copies
// Remove would put in Recently Removed. Rushes chooses which copy stays (the
// shelf's copy over a card dump's, then the shortest path: dedupe.sh); a
// person changes it per group here, in the plan itself, which Remove carries out.
//
//   GET  ?drive=&folder=&offset=&limit=      {built, done, total, folders, groups: [{keep, size, moves, why}]}
//   POST action=keep keep=<path> pick=<path> [drive=]   this copy stays instead (signed in)
//
// ponytail: the plan is read whole on each look (a few hundred thousand lines
// at most, well under a second); kept in the database if that ever grows slow.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function grp_said(int $code, array $a) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
if (!signed_in()) grp_said(403, ['error' => 'sign in first']);

// The archive's plan, or one drive's (kept where it is: drive=<its place in Setup>)
$drv = drive_by_source((string)($_REQUEST['drive'] ?? ''));
$sfx = $drv ? '-' . drive_key($drv) : '';
$plan = web_dir() . "/dedupe-plan$sfx.tsv";
$chosen_f = web_dir() . "/dedupe-chosen$sfx.txt";      // the copies a person chose, said as "your choice"
$root = rtrim($drv ? $drv['path'] : archive_dir(), '/') . '/';

// The plan's lines: size, the copy that moves, the copy that stays
function plan_lines(string $plan): array {
    $out = [];
    if ($h = @fopen($plan, 'r')) {
        while (($l = fgets($h)) !== false) {
            $x = explode("\t", rtrim($l, "\n"));
            if (count($x) >= 3 && $x[1] !== '' && $x[2] !== '') $out[] = $x;
        }
        fclose($h);
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') !== 'keep') grp_said(400, ['error' => 'unknown action']);
    $keep = (string)($_POST['keep'] ?? ''); $pick = (string)($_POST['pick'] ?? '');
    $lines = plan_lines($plan); $hit = false;
    foreach ($lines as $x) if ($x[2] === $keep && $x[1] === $pick) $hit = true;
    if (!$hit) grp_said(400, ['error' => 'That copy is not in this group any more: Find duplicates again.']);
    if (!is_file($pick)) grp_said(400, ['error' => 'That copy is not on the drive now.']);
    // The group again, with the picked copy staying: every other copy (the one kept before too) moves
    $out = [];
    foreach ($lines as $x) {
        if ($x[2] === $keep) $x = [$x[0], $x[1] === $pick ? $keep : $x[1], $pick];
        $out[] = implode("\t", $x);
    }
    $ok = @file_put_contents("$plan.new", implode("\n", $out) . "\n") !== false && @rename("$plan.new", $plan);
    if (!$ok) grp_said(500, ['error' => 'Could not save — is the web folder writable?']);
    $ch = array_values(array_diff(@file($chosen_f, FILE_IGNORE_NEW_LINES) ?: [], [$keep]));
    $ch[] = $pick;
    @file_put_contents($chosen_f, implode("\n", array_slice($ch, -5000)) . "\n");
    grp_said(200, ['ok' => true, 'keep' => $pick]);
}

$lines = plan_lines($plan);
$chosen = array_flip(@file($chosen_f, FILE_IGNORE_NEW_LINES) ?: []);
$d = settings()['duplicates'] ?? [];
$passing = array_merge($d['never_keep'] ?? [], $d['card_dumps'] ?? []);
$top = function (string $p) use ($root) {                     // the archive's (or drive's) top folder of a path
    if (strncmp($p, $root, strlen($root)) !== 0) return '';
    $x = explode('/', substr($p, strlen($root)), 2);
    return count($x) < 2 ? '' : $x[0];
};
$want = (string)($_GET['folder'] ?? '');
$groups = []; $folders = []; $files = 0; $bytes = 0;
foreach ($lines as $x) {
    [$size, $move, $keep] = [(int)$x[0], $x[1], $x[2]];
    $f = $top($move);
    if ($f !== '') { $folders[$f]['files'] = ($folders[$f]['files'] ?? 0) + 1; $folders[$f]['bytes'] = ($folders[$f]['bytes'] ?? 0) + $size; }
    $files++; $bytes += $size;
    if ($want !== '' && $f !== $want) continue;
    $groups[$keep]['size'] = $size;
    $groups[$keep]['moves'][] = $move;
}
// Why that copy stays, in words: the rule that chose it, or the person who did
$shelf = shelf_chosen() ? rtrim(shelf_dir(), '/') . '/' : null;
$why = function (string $keep, array $moves) use ($chosen, $shelf, $passing, $top) {
    if (isset($chosen[$keep])) return 'Your choice';
    if ($shelf && str_starts_with($keep, $shelf)) return 'It is on the shelf, where it belongs';
    foreach ($moves as $m) if (in_array($top($m), $passing, true)) return 'The other copies are in folders that only pass files through, or copies of whole cards';
    return 'The shortest path: the copy that is least likely to be a stray';
};
$rows = [];
foreach ($groups as $k => $g) $rows[] = ['keep' => $k, 'size' => $g['size'], 'moves' => $g['moves'], 'bytes' => $g['size'] * count($g['moves'])];
usort($rows, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
$off = max(0, (int)($_GET['offset'] ?? 0)); $lim = min(200, max(1, (int)($_GET['limit'] ?? 50)));
$page = array_slice($rows, $off, $lim);
foreach ($page as &$r) $r['why'] = $why($r['keep'], $r['moves']);
unset($r);
$fl = [];
foreach ($folders as $n => $v) $fl[] = ['name' => (string)$n, 'files' => $v['files'], 'bytes' => $v['bytes']];
usort($fl, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
$built = (string)(json_decode((string)@file_get_contents("$plan.meta"), true)['built'] ?? '');
$at = (int)@filemtime($plan);
// That plan already carried out (Remove): what it shows is what went to Recently Removed
$done = $at > 0 && (int)@filemtime(web_dir() . "/dedupe-moves$sfx.tsv") >= $at;
grp_said(200, [
    'built' => $built !== '' ? str_replace('T', ' ', substr($built, 0, 16)) : ($at ? date('Y-m-d H:i', $at) : ''),
    'done' => $done, 'root' => $root,
    'total' => ['files' => $files, 'bytes' => $bytes, 'groups' => count($rows)],
    'offset' => $off,
    'folders' => array_slice($fl, 0, 30), 'groups' => $page,
    'drives' => array_map(fn($d) => ['source' => $d['source'], 'name' => $d['name'], 'connected' => !empty($d['connected'])], drives_seen()),
]);
