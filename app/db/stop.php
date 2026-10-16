<?php
// stop.php — Stop, from the top bar: the job running now stops at its next safe point (between two files),
// and what it did not reach stays as it was (runner.py stop_asked). Only jobs that stop between files offer it.
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !may_act((string)($_POST['pass'] ?? ''))) { http_response_code(403); echo '{"error":"sign in first"}'; exit; }
$W = web_dir();
$st = trim((string)@file_get_contents("$W/job-status.txt"));
$job = str_starts_with($st, 'running:') ? trim(substr($st, 8)) : '';
if (!isset(STOPPABLE[$job])) { http_response_code(409); echo json_encode(['error' => $job === '' ? 'Nothing is running now.' : 'This one cannot be stopped part-way; it finishes by itself.']); exit; }
if (@file_put_contents("$W/job-stop.txt", "$job\n") === false) { http_response_code(500); echo '{"error":"Could not ask the runner: is the web folder writable?"}'; exit; }
require_once __DIR__ . '/activity.php';
activity_add('changed', 'Asked to stop: ' . STOPPABLE[$job]);
echo json_encode(['ok' => 'stop', 'job' => $job]);
