<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// rescue.php — Rescue (HOW-IT-WORKS.md → Rescue): broken copies in the archive (empty clips, clips too small
// or with another file's data inside, sidecars that are not XML), where each came from, and the good copy
// Rushes can see. The looking and the rescuing are the runner's (runner.py rescue_scan, rescue_apply);
// this page only asks, and says what was found.
//
//   GET                         {looked, running, rows: [{path, rel, size, why, came, good, gsize, other}], …}
//   POST action=scan            look again (a job for the runner)
//   POST action=rescue paths=[] the ticked ones, each replaced by the good copy the scan found
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/activity.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function out(array $a) { echo json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function bail(int $code, string $why) { http_response_code($code); out(['error' => $why]); }
if (!may_act((string)($_REQUEST['pass'] ?? ''))) bail(403, 'sign in first');

$W = web_dir(); $A = rtrim(archive_dir(), '/');
$found = function () use ($W): array {
    $rows = [];
    foreach (@file("$W/rescue-found.tsv") ?: [] as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if (count($f) >= 7) $rows[$f[0]] = ['path' => $f[0], 'size' => (int)$f[1], 'why' => $f[2], 'came' => $f[3],
                                            'good' => $f[4], 'gsize' => (int)$f[5], 'other' => (int)$f[6]];
    }
    return $rows;
};
$queue = function (string $what) use ($W) {
    @mkdir("$W/queue", 0777, true);
    if (@file_put_contents("$W/queue/" . date('Ymd-His') . "-$what.job", "ACTION=$what\nWHO=" . str_replace("\n", ' ', activity_who()) . "\n") === false)
        bail(500, 'Could not ask the runner: is the web folder writable?');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string)($_POST['action'] ?? '');
    if ($do === 'scan') { $queue('rescue-scan'); out(['ok' => 'scan']); }
    if ($do === 'rescue') {
        // only what the scan found, with the good copy it found: never a path or a source sent by the page
        $rows = $found(); $pick = '';
        foreach (array_slice(json_decode((string)($_POST['paths'] ?? '[]'), true) ?: [], 0, 20000) as $p)
            if (isset($rows[$p]) && $rows[$p]['good'] !== '') $pick .= "$p\t{$rows[$p]['good']}\n";
        if ($pick === '') bail(400, 'None of those has a good copy to rescue from.');
        if (@file_put_contents("$W/rescue-pick.tsv", $pick) === false) bail(500, 'Could not save the list.');
        $queue('rescue-apply');
        out(['ok' => 'rescue', 'n' => substr_count($pick, "\n")]);
    }
    bail(400, 'This page is from an older Rushes: reload it (⌘R).');
}

// What was rescued: Search takes each file's new size (the row keeps its id, so what is known stays with it)
db_init();
$done = @file("$W/rescue-done.tsv") ?: [];
$at = (int)meta_get('rescue_done_n', '0');
if (count($done) > $at) {
    $up = db()->prepare('UPDATE files SET bytes = ? WHERE path = ?');
    foreach (array_slice($done, $at) as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if (count($f) >= 3) { $up->bindValue(1, (int)$f[2]); $up->bindValue(2, $f[0]); $up->execute(); $up->reset(); }
    }
    meta_set('rescue_done_n', (string)count($done));
}
$st = trim((string)@file_get_contents("$W/job-status.txt"));
$asked = (bool)array_filter(glob("$W/queue/*.job") ?: [], fn($j) => preg_match('/^ACTION=rescue-/m', (string)@file_get_contents($j)));
[$lt, $ln] = array_pad(explode("\t", trim((string)@file_get_contents("$W/rescue-looked.txt"))), 2, 0);
// which place a good copy is in, in words: the archive itself, or a drive in Search by its name
$where = function (string $p) use ($A): string {
    if ($p === '') return '';
    if (str_starts_with($p, "$A/")) return 'the archive';
    $d = kept_drive_of($p);
    return $d ? (string)$d['name'] : dirname($p);
};
$rows = array_values($found());
$withGood = count(array_filter($rows, fn($r) => $r['good'] !== ''));
$rows = array_map(fn($r) => $r + ['rel' => str_starts_with($r['path'], "$A/") ? substr($r['path'], strlen($A) + 1) : $r['path'],
                                  'where' => $where($r['good'])], array_slice($rows, 0, 5000));
out(['looked' => (int)$lt, 'looked_n' => (int)$ln, 'asked' => $asked,
     'running' => str_contains($st, 'rescue-') ? (str_contains($st, 'apply') ? 'rescuing' : 'looking') : '',
     'total' => count($found()), 'with_good' => $withGood, 'rows' => $rows,
     'done' => array_map(fn($l) => array_slice(explode("\t", rtrim($l, "\n")), 0, 4), array_slice(array_reverse($done), 0, 50))]);
