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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!may_act((string)($_POST['pass'] ?? ''))) bail(403, 'sign in first');
    $rel = trim(str_replace('\\', '/', (string)($_POST['path'] ?? '')), '/');
    if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\t") || str_contains($rel, "\n"))
        bail(400, 'Pick a folder inside the archive.');
    if (!file_exists("$root/$rel")) bail(400, "There is no “{$rel}” in the archive.");
    if ($helperRoot === '') bail(400, 'Setup does not know where the helper finds the archive yet (04 · Helper).');
    $line = "analyze\t$helperRoot/$rel";
    $lines = array_values(array_filter(array_map('rtrim', @file($Q) ?: []), fn($l) => $l !== '' && $l !== $line));
    $lines[] = $line;                                   // after everything already waiting
    if (@file_put_contents("$Q.new", implode("\n", $lines) . "\n") === false || !@rename("$Q.new", $Q))
        bail(500, 'Could not write the queue — is the web folder writable?');
    out(['queued' => $rel]);
}

$waiting = []; $done = [];
foreach (@file($Q) ?: [] as $l)
    if (preg_match('/^analyze\t(.+)$/', rtrim($l), $m)) $waiting[] = ltrim(substr($m[1], strlen($helperRoot)), '/');
foreach (array_reverse(@file(web_dir() . '/ingest-history.tsv') ?: []) as $l) {
    $f = explode("\t", rtrim($l, "\n"));
    if (($f[1] ?? '') === 'analysed' && count($done) < 20)
        $done[] = ['path' => ltrim(substr($f[2], strlen($helperRoot)), '/'), 'files' => (int)$f[3], 'when' => $f[0], 'note' => $f[6] ?? ''];
}
$described = count(glob("$root/_rushes/analysis/*/*.json") ?: []);
out(['folders' => folders($root), 'waiting' => $waiting, 'done' => $done, 'described' => $described,
     'helper' => helper_name()]);
