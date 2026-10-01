<?php
// run.php — the admin page's only way to ask for work. It writes a job file
// and nothing else: no exec(), no file moves, no deletes. runner.sh (root, via
// cron) is what actually does anything, and it re-validates every field.
//
// ponytail: shared password in a file, LAN-only. Fine while this lives on the
// office network; put it behind real auth before it is ever reachable outside.

header('Content-Type: application/json');

$QUEUE = '/share/Web/queue';
$PASSFILE = '/share/Web/.adminpass';   // create with: echo -n 'yourpassword' > /share/Web/.adminpass

$allowed = ['plan', 'apply', 'undo', 'reindex', 'cachescan', 'cacheclean', 'df',
            // 'organize-apply' is off: it sorts the whole archive by date, which is
            // no longer the plan. The read-only proposal and the undo stay.
            'organize', 'organize-undo', 'scan', 'manifest', 'holding',
            'proxy-plan', 'proxy-build', 'proxy-stop', 'verify', 'gpu-test', 'proxy-test', 'proxy-remake', 'reset-breaker'];

$action    = $_POST['action']    ?? '';
$pass      = $_POST['pass']      ?? '';
$keep_side = $_POST['keep_side'] ?? 'project';
$dest      = $_POST['dest']      ?? '/share/VIDEO/_duplicates';
$stills    = ($_POST['stills'] ?? '0') === '1' ? '1' : '0';
$exclude   = preg_replace('/[^A-Za-z0-9 ,_.\/-]/', '', $_POST['exclude'] ?? '');
$query     = substr(preg_replace('/[^A-Za-z0-9 _.\/&(),+-]/', '', $_POST['query'] ?? ''), 0, 200);

// A signed-in admin session is enough; a password in the request also works,
// so a script can still drive this without a browser.
require_once __DIR__ . '/db/auth.php';
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
if (strpos($dest, '/share/VIDEO/') !== 0 || strpos($dest, '..') !== false) {
    $dest = '/share/VIDEO/_duplicates';
}
$dest = rtrim($dest, '/');

if (!is_dir($QUEUE)) { @mkdir($QUEUE, 0777, true); }

$file = $QUEUE . '/' . date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6) . '.job';
$body = "ACTION=$action\nKEEP_SIDE=$keep_side\nDEST=$dest\nSTILLS=$stills\nEXCLUDE=$exclude\nQUERY=$query\n";

if (@file_put_contents($file, $body) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'could not write job file — is /share/Web/queue writable (chmod 777)?']);
    exit;
}

echo json_encode(['queued' => $action, 'runs_within' => '60 seconds']);
