<?php
// status.php — the helper says what it is doing, as it does it.
//
// The same few lines it writes to _rushes/ingest-status.tsv on the share,
// sent here too so every page shows them live, instead of when the archive
// next copies that file over (once a minute).
//
// ponytail: no password, like report.php — the helper has none. It writes one
// small file of plain key/value lines that pages only display; nothing acts
// on it.
require_once __DIR__ . '/config.php';
helper_gate();                          // only the paired helper's word is taken (pair.php)
header('Content-Type: application/json');

// Its history and its list of sections, whole, whenever they change (they used
// to be copied off the VIDEO share by the runner every minute, which is a read
// of the archive every minute for ever). Small files; replaced, never merged.
$which = (string)($_POST['file'] ?? '');
if ($which !== '') {
    $body = (string)($_POST['body'] ?? '');
    $want = ['history' => '/^\d{4}-\d\d-\d\d \d\d:\d\d\t/', 'sections' => '/^section\t/'][$which] ?? null;
    if (!$want || strlen($body) > 8000000) { http_response_code(400); echo '{"error":"not a file Rushes keeps"}'; exit; }
    $lines = array_filter(explode("\n", $body), fn($l) => $l !== '' && preg_match($want, $l));
    $f = web_dir() . "/ingest-$which.tsv";
    $ok = @file_put_contents("$f.new", $lines ? implode("\n", $lines) . "\n" : '') !== false && @rename("$f.new", $f);
    echo json_encode(['ok' => $ok, 'lines' => count($lines)]); return;
}

$raw = (string)($_POST['status'] ?? '');
if ($raw === '' || strlen($raw) > 8000) { http_response_code(400); echo '{"error":"no status"}'; exit; }

$keep = [];
foreach (explode("\n", $raw) as $l) {
    if ($l === '') continue;
    $f = explode("\t", $l, 2);
    if (count($f) === 2 && preg_match('/^[a-z_]{1,20}$/', $f[0]) && strlen($f[1]) <= 1000) $keep[] = $l;
    if (count($keep) >= 40) break;
}
if (!$keep) { http_response_code(400); echo '{"error":"no status"}'; exit; }

// Two lanes, two files: copying (ingest-status) and describing (describe-status).
$f = web_dir() . (($_POST['lane'] ?? '') === 'describe' ? '/describe-status.tsv' : '/ingest-status.tsv');
$ok = @file_put_contents("$f.new", implode("\n", $keep) . "\n") !== false && @rename("$f.new", $f);
echo json_encode(['ok' => $ok]);
