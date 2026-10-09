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
//   GET  ?drive=&kind=&folder=&offset=&limit=  {built, done, total, boxes, groups: [{keep, size, moves, why, kind}]}
//   POST action=keep keep=<path> pick=<path> [drive=]   this copy stays instead (signed in)
//   POST action=pick kind=folder|job [folder=] [drive=] the copies Remove takes this time (dedupe-pick.txt)
//
// Three kinds, so a person decides three things, not one per file:
//   folder  every other copy is in the same folder as the one kept ("IMG_3241 2.HEIC"): safe
//   job     the others are in other folders of the same job (top folder), or in folders that only
//           pass files through (card dumps, Copied_…): a project may use one, so looked at per job
//   across  in different jobs: left alone by dedupe.sh (dedupe-left.tsv), shown, never removed
//
// ponytail: the plan is read whole on each look (a few hundred thousand lines
// at most, well under a second); kept in the database if that ever grows slow.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function grp_said(int $code, array $a) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
if (!signed_in()) grp_said(403, ['error' => 'sign in first']);

// The archive's plan, or one drive's (kept where it is: drive=<its place in Setup>)
$drv = drive_by_source((string)($_REQUEST['drive'] ?? ''));
$sfx = $drv ? '-' . drive_key($drv) : '';
$plan = web_dir() . "/dedupe-plan$sfx.tsv";
$chosen_f = web_dir() . "/dedupe-chosen$sfx.txt";
$left = web_dir() . "/dedupe-left$sfx.tsv";           // copies in different jobs: left alone
$pick_f = web_dir() . "/dedupe-pick$sfx.txt";          // what this Remove takes (read by dedupe.sh, then gone)      // the copies a person chose, said as "your choice"
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

$top = function (string $p) use ($root) {                     // the job a path is in: its top folder in the archive (or drive)
    if (strncmp($p, $root, strlen($root)) !== 0) return '';
    $x = explode('/', substr($p, strlen($root)), 2);
    return count($x) < 2 ? '' : $x[0];
};
// The plan as groups: the copy kept -> its size, the copies that go, and which kind
function plan_groups(array $lines, callable $top): array {
    $g = [];
    foreach ($lines as $x) { $g[$x[2]]['size'] = (int)$x[0]; $g[$x[2]]['moves'][] = $x[1]; }
    foreach ($g as $keep => &$v) {
        $same = fn($m) => dirname($m) === dirname($keep);
        $v['kind'] = count(array_filter($v['moves'], $same)) === count($v['moves']) ? 'folder' : 'job';
        $v['job'] = $top($v['moves'][0]);
    }
    return $g;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pick') {
    $kind = (string)($_POST['kind'] ?? ''); $job = (string)($_POST['folder'] ?? '');
    if (!in_array($kind, ['folder', 'job'], true)) grp_said(400, ['error' => 'which copies?']);
    $out = [];
    foreach (plan_groups(plan_lines($plan), $top) as $v)
        if ($v['kind'] === $kind && ($job === '' || $v['job'] === $job)) array_push($out, ...$v['moves']);
    if (!$out) grp_said(400, ['error' => 'Nothing of that kind in the plan now: Find duplicates again.']);
    if (@file_put_contents($pick_f, implode("\n", $out) . "\n") === false) grp_said(500, ['error' => 'Could not save — is the web folder writable?']);
    grp_said(200, ['ok' => true, 'files' => count($out)]);
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
$all = plan_groups($lines, $top);
foreach ($all as $k => &$v) $v['keep'] = $k;
unset($v);
// a file kept for both: its copy in the same job is planned, the one in another job left alone, shown apart
foreach (plan_groups(plan_lines($left), $top) as $k => $v) $all[isset($all[$k]) ? "$k\0across" : $k] = ['kind' => 'across', 'keep' => $k] + $v;
$boxes = []; $files = 0; $bytes = 0;
foreach (['folder', 'job', 'across'] as $k) $boxes[$k] = ['files' => 0, 'bytes' => 0, 'groups' => 0, 'jobs' => []];
foreach ($all as $v) {
    $b = &$boxes[$v['kind']]; $n = count($v['moves']); $sz = $v['size'] * $n;
    $b['files'] += $n; $b['bytes'] += $sz; $b['groups']++;
    $j = &$b['jobs'][$v['job']]; $j['files'] = ($j['files'] ?? 0) + $n; $j['bytes'] = ($j['bytes'] ?? 0) + $sz;
    if ($v['kind'] !== 'across') { $files += $n; $bytes += $sz; }
    unset($b, $j);
}
foreach ($boxes as &$b) {
    $jl = [];
    foreach ($b['jobs'] as $n => $v) $jl[] = ['name' => (string)$n] + $v;
    usort($jl, fn($a, $c) => $c['bytes'] <=> $a['bytes']);
    $b['jobs'] = $jl;
}
unset($b);
// Why that copy stays, in words: the rule that chose it, or the person who did
$shelf = shelf_chosen() ? rtrim(shelf_dir(), '/') . '/' : null;
$why = function (string $keep, array $moves, string $kind) use ($chosen, $shelf, $passing, $top) {
    if ($kind === 'across') return 'Left alone: these copies are in different jobs, and each job\'s project may use its own. Nothing here is removed.';
    if (isset($chosen[$keep])) return 'Your choice';
    if ($shelf && str_starts_with($keep, $shelf)) return 'It is on the shelf, where it belongs';
    foreach ($moves as $m) if (in_array($top($m), $passing, true)) return 'The other copies are in folders that only pass files through, or copies of whole cards';
    return 'The shortest path: the copy that is least likely to be a stray';
};
$kind = (string)($_GET['kind'] ?? ''); $want = (string)($_GET['folder'] ?? '');
$rows = [];
foreach ($all as $k => $g)
    if (($kind === '' || $g['kind'] === $kind) && ($want === '' || $g['job'] === $want))
        $rows[] = ['keep' => $g['keep'], 'size' => $g['size'], 'moves' => $g['moves'], 'kind' => $g['kind'], 'bytes' => $g['size'] * count($g['moves'])];
usort($rows, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
$off = max(0, (int)($_GET['offset'] ?? 0)); $lim = min(200, max(1, (int)($_GET['limit'] ?? 50)));
$page = array_slice($rows, $off, $lim);
foreach ($page as &$r) $r['why'] = $why($r['keep'], $r['moves'], $r['kind']);
unset($r);
$built = (string)(json_decode((string)@file_get_contents("$plan.meta"), true)['built'] ?? '');
$at = (int)@filemtime($plan);
// That plan carried out in full (Remove; dedupe.sh writes .done): what it shows went to Recently Removed
$done = $at > 0 && is_file("$plan.done");
grp_said(200, [
    'built' => $built !== '' ? str_replace('T', ' ', substr($built, 0, 16)) : ($at ? date('Y-m-d H:i', $at) : ''),
    'done' => $done, 'root' => $root,
    'total' => ['files' => $files, 'bytes' => $bytes, 'groups' => $boxes['folder']['groups'] + $boxes['job']['groups']],
    'boxes' => $boxes, 'shown' => count($rows), 'offset' => $off, 'groups' => $page,
    'drives' => array_map(fn($d) => ['source' => $d['source'], 'name' => $d['name'], 'connected' => !empty($d['connected'])], drives_seen()),
]);
