<?php
// run.php — Manage asks the runner for a job here. It writes a job file and
// nothing else: no exec(), no file moves, no deletes. runner.sh (root, via
// cron) is what actually does anything, and it checks every field again.
// (analyze.php and helper.php also write job files, for proxies and updates.)
//
// ponytail: shared password in a file, LAN-only. Fine while this lives on the
// office network; put it behind real auth before it is ever reachable outside.

header('Content-Type: application/json');


$allowed = ['plan', 'apply', 'undo', 'reindex', 'cacheclean', 'df',
            // the old date-based layout: only its undo is left (see organize.sh)
            'organize-undo', 'scan', 'manifest', 'holding',
            'cache-undo', 'proxy-plan', 'proxy-build', 'proxy-stop', 'verify', 'gpu-test', 'proxy-test', 'proxy-remake', 'reset-breaker'];

// First: settings, and the check that this came from Rushes itself (config.php).
require_once __DIR__ . '/db/auth.php';

$action    = $_POST['action']    ?? '';
$pass      = $_POST['pass']      ?? '';
$keep_side = $_POST['keep_side'] ?? 'project';
$dest      = $_POST['dest']      ?? archive_dir() . '/_duplicates';
$stills    = ($_POST['stills'] ?? '0') === '1' ? '1' : '0';
$exclude   = preg_replace('/[^A-Za-z0-9 ,_.\/-]/', '', $_POST['exclude'] ?? '');
// letters of any language (Fútbol, Año), digits, and a few marks; the runner checks again
// a drive kept where it is (Setup 01 and 03), for the duplicates jobs: by its place in Setup
$drive     = (string)($_POST['drive'] ?? '');
if ($drive !== '' && !drive_by_source($drive)) $drive = '';
$query     = implode('', array_slice(preg_split('//u', preg_replace('/[^\p{L}\p{N} _.\/&(),+-]/u', '', (string)($_POST['query'] ?? '')), -1, PREG_SPLIT_NO_EMPTY), 0, 200));

// A signed-in admin session is enough; a password in the request also works,
// so a script can still drive this without a browser.
$QUEUE = web_dir() . '/queue';
if (!may_act($pass)) {
    http_response_code(403);
    echo json_encode(['error' => 'not signed in, and no password given']);
    exit;
}

if (!in_array($action, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
    exit;
}

if (!in_array($keep_side, ['project', 'card', 'short', 'oldest'], true)) {
    $keep_side = 'project';
}

// destination must stay inside the video share
if (strpos($dest, archive_dir() . '/') !== 0 || strpos($dest, '..') !== false) {
    $dest = archive_dir() . '/_duplicates';
}
$dest = rtrim($dest, '/');

if (!is_dir($QUEUE)) { @mkdir($QUEUE, 0777, true); }

// Which copy of a duplicate is never kept: written for dedupe.sh from
// rules.json and Manage → Duplicates now, so the plan follows what the settings say today.
if (in_array($action, ['plan', 'apply'], true) && dedupe_rules_write() === null) {
    http_response_code(500);
    echo json_encode(['error' => 'could not write dedupe-rules.tsv — is the web folder writable?']);
    exit;
}

$file = $QUEUE . '/' . date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6) . '.job';
$body = "ACTION=$action\nKEEP_SIDE=$keep_side\nDEST=$dest\nSTILLS=$stills\nEXCLUDE=$exclude\nQUERY=$query\n"
      . ($drive !== '' && preg_match('/^[^\r\n=]+$/', $drive) ? "DRIVE=$drive\n" : '');

if (@file_put_contents($file, $body) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'could not write job file — is the queue folder in the web folder writable?']);
    exit;
}

echo json_encode(['queued' => $action, 'runs_within' => '60 seconds']);
