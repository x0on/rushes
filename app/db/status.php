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
header('Content-Type: application/json');

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
