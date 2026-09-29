<?php
// analyze.php — describing footage: the vision model and speech, run by the helper.
//
//   GET                     folders in the archive, what is waiting, what is done
//   POST path=<folder>      describe that folder (a path inside the archive, as
//                           this machine sees it); signed in
//
// This page only asks. The helper describes one file at a time, after any
// copies, and writes one description per file into _rushes/analysis.
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function out(array $a) { echo json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }
function bail(int $code, string $why) { http_response_code($code); out(['error' => $why]); }

$Q = web_dir() . '/ingest-queue.tsv';
$root = rtrim(archive_dir(), '/');
$helperRoot = rtrim(helper_archive(), '/');

// Folders worth offering: two levels into the archive, the house folders left out.
function folders(string $root): array {
    $out = [];
    foreach (@scandir($root) ?: [] as $a) {
        if ($a[0] === '.' || $a[0] === '_' || $a[0] === '@' || !is_dir("$root/$a")) continue;
        $out[] = $a;
        foreach (@scandir("$root/$a") ?: [] as $b)
            if ($b[0] !== '.' && $b[0] !== '@' && is_dir("$root/$a/$b")) $out[] = "$a/$b";
    }
    return $out;
}

require_once __DIR__ . '/prepare.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!may_act((string)($_POST['pass'] ?? ''))) bail(403, 'sign in first');
    $rel = trim(str_replace('\\', '/', (string)($_POST['path'] ?? '')), '/');
    // Prepare: proxies first, then descriptions; the order is kept by prepare_advance.
    if (($_POST['action'] ?? '') === 'prepare' || ($_POST['action'] ?? '') === 'forget') {
        if ($rel === '' || str_contains($rel, '..') || preg_match('/[\t\n]/', $rel) || !preg_match('#^[A-Za-z0-9 _./&(),+-]+$#', $rel))
            bail(400, 'Pick a folder inside the archive (letters, numbers, spaces and . _ - & ( ) , + only).');
        $list = prepare_list();
        if ($_POST['action'] === 'forget') { unset($list[$rel]); }
        else {
            if (!is_dir("$root/$rel")) bail(400, "There is no folder “{$rel}” in the archive.");
            unset($list[$rel]); $list[$rel] = time();      // asked again: to the end, and runs again
        }
        if (!prepare_save($list)) bail(500, 'Could not save — is the web folder writable?');
        prepare_advance();
        out(['ok' => $_POST['action'], 'folder' => $rel]);
    }
    bail(400, 'unknown action');
}

$waiting = []; $done = [];
foreach (@file($Q) ?: [] as $l)
    if (preg_match('/^analyze\t([^\t]+)/', rtrim($l), $m)) $waiting[] = ltrim(substr($m[1], strlen($helperRoot)), '/');
foreach (array_reverse(@file(web_dir() . '/ingest-history.tsv') ?: []) as $l) {
    $f = explode("\t", rtrim($l, "\n"));
    if (($f[1] ?? '') === 'analysed' && count($done) < 20)
        $done[] = ['path' => ltrim(substr($f[2], strlen($helperRoot)), '/'), 'files' => (int)$f[3], 'when' => $f[0], 'note' => $f[6] ?? ''];
}
// How far it has got, and the latest shots to check by eye.
require_once __DIR__ . '/analysis.php';
analysis_init(); $db = db();
$one = fn(string $sql) => (int)($db->querySingle($sql) ?? 0);
$stats = ['files' => count(glob("$root/_rushes/analysis/*/*.json") ?: []),
          'shots' => $one("SELECT COUNT(*) FROM moments WHERE kind = 'shot'"),
          'speech' => $one("SELECT COUNT(*) FROM moments WHERE kind = 'speech'"),
          'failed' => $one("SELECT COUNT(*) FROM moments WHERE kind = 'failed'")];
$latest = []; $r = $db->query("SELECT fp,path,kind,shot,start_s,end_s,what,on_screen,themes,tags,shot_size,people,light,
    language FROM moments WHERE kind != 'failed' ORDER BY rowid DESC LIMIT 24");
while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $latest[] = $x;
out(['table' => prepare_table(), 'folders' => folders($root), 'waiting' => $waiting, 'done' => $done, 'described' => $stats['files'],
     'stats' => $stats, 'latest' => $latest, 'helper' => helper_name()]);
