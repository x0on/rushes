<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// dupfolders.php — Manage → Duplicates: where the duplicate copies are, by the
// archive's top folders, and what each of those folders is. Asked when it
// matters, about the folders really there (HOW-IT-WORKS.md → Duplicates):
//   normal    the usual rules decide which copy stays
//   stopover  files only sit there for a while: its copy goes when the clip is also elsewhere
//   cards     whole cards copied as they were: the shelf's copy stays over it
//
//   GET                                          {"built", "shelf", "folders": [{"name", "move", "bytes", "stay", "kind"}]}
//   POST folder=<a top folder> kind=normal|stopover|cards     signed in; the rules are written again
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/schema.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function dup_said(int $code, array $a) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
if (!signed_in()) dup_said(403, ['error' => 'sign in first']);

$s = settings(); $d = $s['duplicates'] ?? [];
$kind_of = fn(string $n) => in_array($n, $d['never_keep'] ?? [], true) ? 'stopover'
                         : (in_array($n, $d['card_dumps'] ?? [], true) ? 'cards' : 'normal');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $n = trim((string)($_POST['folder'] ?? '')); $k = (string)($_POST['kind'] ?? '');
    if ($n === '' || preg_match('#[/\\\\]#', $n) || mb_strlen($n) > 100 || !in_array($k, ['normal', 'stopover', 'cards'], true))
        dup_said(400, ['error' => 'Not a folder and what it is.']);
    if ($n === shelf_name() || (shelf_is_top() && ($n === 'Projects' || dept_of_folder($n) !== null))) dup_said(400, ['error' => 'That is your shelf: its copies are the ones that stay.']);
    $nk = array_values(array_diff($d['never_keep'] ?? [], [$n])); $cd = array_values(array_diff($d['card_dumps'] ?? [], [$n]));
    if ($k === 'stopover') $nk[] = $n; elseif ($k === 'cards') $cd[] = $n;
    $s['duplicates'] = ['never_keep' => $nk, 'card_dumps' => $cd];
    if (!save_settings($s)) dup_said(500, ['error' => 'Could not save — is the web folder writable?']);
    settings(true);
    if (dedupe_rules_write() === null) dup_said(500, ['error' => 'Saved, but the rules could not be written — is the web folder writable?']);
    dup_said(200, ['ok' => true, 'folder' => $n, 'kind' => $k]);
}

// Counted from the plan the last "Look for duplicates" made (size, copy that
// moves, copy that stays), once per plan: it can be a few hundred thousand lines.
// The archive's plan, or one drive's (kept where it is: ?drive=<its place in Setup>)
$drv = drive_by_source((string)($_GET['drive'] ?? ''));
$sfx = $drv ? '-' . drive_key($drv) : '';
$plan = web_dir() . "/dedupe-plan$sfx.tsv";
$at = (int)@filemtime($plan);
$c = json_decode(meta_get("dupfolders$sfx", ''), true);
if (!is_array($c) || ($c['at'] ?? -1) !== $at) {
    $pre = rtrim($drv ? $drv['path'] : archive_dir(), '/') . '/'; $L = strlen($pre); $f = [];
    $top = function (string $p) use ($pre, $L) {
        if (strncmp($p, $pre, $L) !== 0) return '';
        $x = explode('/', substr($p, $L), 2);
        if (count($x) < 2) return '';                                        // a file loose at the top: not a folder
        return ($x[0] === '' || strpbrk($x[0][0], '@_.') !== false) ? '' : $x[0];   // the recycle bin, Rushes' own folders
    };
    if ($h = @fopen($plan, 'r')) {
        while (($l = fgets($h)) !== false) {
            $x = explode("\t", rtrim($l, "\n"));
            if (count($x) < 3) continue;
            if (($m = $top($x[1])) !== '') { $f[$m]['move'] = ($f[$m]['move'] ?? 0) + 1; $f[$m]['bytes'] = ($f[$m]['bytes'] ?? 0) + (int)$x[0]; }
            if (($k = $top($x[2])) !== '') $f[$k]['stay'] = ($f[$k]['stay'] ?? 0) + 1;
        }
        fclose($h);
    }
    $rows = [];
    foreach ($f as $n => $v) $rows[] = ['name' => (string)$n, 'move' => $v['move'] ?? 0, 'bytes' => $v['bytes'] ?? 0, 'stay' => $v['stay'] ?? 0];
    usort($rows, fn($a, $b) => ($b['move'] + $b['stay']) <=> ($a['move'] + $a['stay']));
    $c = ['at' => $at, 'rows' => array_slice($rows, 0, 20)];
    meta_set("dupfolders$sfx", json_encode($c, JSON_UNESCAPED_UNICODE));
}
// Folders already chosen stay listed even when the last plan had nothing in them.
$rows = $c['rows']; $have = array_column($rows, 'name');
foreach (array_merge($d['never_keep'] ?? [], $d['card_dumps'] ?? []) as $n)
    if (!in_array($n, $have, true)) $rows[] = ['name' => $n, 'move' => 0, 'bytes' => 0, 'stay' => 0];
foreach ($rows as &$r) $r['kind'] = ($r['name'] === shelf_name() || (shelf_is_top() && ($r['name'] === 'Projects' || dept_of_folder($r['name']) !== null))) ? 'shelf' : $kind_of($r['name']);
// when that look was made: the plan's own note says (the file's date changes when it is copied)
$built = (string)(json_decode((string)@file_get_contents("$plan.meta"), true)['built'] ?? '');
// That plan already carried out (moved, with Move the copies aside): the numbers are what moved, not what would.
$done = is_file(web_dir() . "/dedupe-moves$sfx.tsv") && (int)@filemtime(web_dir() . "/dedupe-moves$sfx.tsv") >= $at && $at > 0;
// the same file on more than one drive (the last scan): shown, never moved
$across = (json_decode((string)@file_get_contents(web_dir() . '/dup-summary.json'), true) ?: [])['across'] ?? null;
dup_said(200, ['across' => $across, 'drives' => array_map(fn($d) => ['source' => $d['source'], 'name' => $d['name'], 'connected' => !empty($d['connected'])], drives_seen()),
               'done' => $done, 'built' => $built !== '' ? str_replace('T', ' ', substr($built, 0, 16)) : ($at ? date('Y-m-d H:i', $at) : ''), 'shelf' => shelf_name(), 'folders' => $rows]);
