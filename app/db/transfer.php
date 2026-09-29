<?php
require_once __DIR__ . '/transfers.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
transfer_init();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $job = transfer_current();
    echo json_encode($job, JSON_UNESCAPED_SLASHES); exit;
}
// The helper can report only an existing selection, never create a job or
// instruct file operations. Like landed.php, this is a local-network endpoint.
$j = (string)($_POST['job'] ?? ''); $p = (string)($_POST['source'] ?? '');
$phase = (string)($_POST['phase'] ?? '');
if (!in_array($phase, ['checking','copying','done','interrupted','blocked','stopped'], true)) {
    http_response_code(400); echo '{"error":"invalid phase"}'; exit;
}
$sets = ['phase = ?', 'updated = ?']; $args = [$phase, time()];
foreach (['total_bytes','total_files','done_bytes','done_files','copied_bytes','already_bytes','failed','measured'] as $k) {
    if (!isset($_POST[$k])) continue;
    if (!ctype_digit((string)$_POST[$k])) { http_response_code(400); echo '{"error":"invalid count"}'; exit; }
    $sets[] = "$k = ?"; $args[] = (int)$_POST[$k];
}
$s = db()->prepare('UPDATE transfer_items SET ' . implode(',', $sets) . " WHERE job_id=? AND source=? AND phase NOT IN ('removed','done')");
$args[] = $j; $args[] = $p;
foreach ($args as $i => $v) $s->bindValue($i+1, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
$s->execute();
echo json_encode(['ok' => true]);
