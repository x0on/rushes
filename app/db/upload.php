<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// upload.php — the door for Upload (upload.php, the page): photos and video
// from a phone, or any computer, into a shoot folder, the way a card goes in
// (HOW-IT-WORKS.md → Upload from a phone). Open like Ingest: anyone who can
// reach Rushes. Narrow like Ingest too: only a department in the plan, a date
// that can be real, a folder name worked out here, never over an existing file.
//
// The files arrive whole and unchanged, in pieces of 4 MB, each checked
// against its SHA-256 taken in the browser; a file that stopped carries on
// from what arrived. They wait in the web folder's inbox (inbox/phone/<batch>,
// never handed out by the web server), and the helper puts them in the
// archive like a card: checked again, with an origin record and a copy proof.
//
//   POST action=start    uploader dept event date files=[{name,size,mtime}]   -> {batch, into}
//   GET  ?have&batch=&name=                          how much of a file is here (to carry on)
//   POST ?piece&batch=&name=&offset=   (X-Piece-SHA256: the piece's)          the next piece (the body)
//   POST action=finish   batch                       every file whole: listed and queued for the helper
//   GET  ?state&batch=                                waiting | paused | placed | refused, for the receipt
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function up_said(int $code, array $a) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }
$clean = fn($s, int $n = 120) => trim(preg_replace('/\s+/', ' ', preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]/', ' ', (string)$s)));
$plain = fn($n) => is_string($n) && $n !== '' && $n[0] !== '.' && mb_strlen($n) <= 200 && !preg_match('/[\\/\\\\:\x00-\x1f]/u', $n);
$dir = function (string $batch): string {
    if (!preg_match('/^[0-9]{14}-[0-9a-f]{8}$/', $batch)) up_said(400, ['error' => 'not an upload']);
    return web_dir() . "/inbox/phone/$batch";
};
$meta = function (string $d): array {
    $m = json_decode((string)@file_get_contents("$d/meta.json"), true);
    if (!is_array($m)) up_said(404, ['error' => 'no such upload (it was taken in, or never started)']);
    return $m;
};
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── how much of a file is here ──────────────────────────────────────────────
if (isset($_GET['have'])) {
    $d = $dir((string)($_GET['batch'] ?? '')); $name = (string)($_GET['name'] ?? '');
    if (!$plain($name)) up_said(400, ['error' => 'not a file name']);
    clearstatcache();
    if (is_file("$d/files/$name")) up_said(200, ['have' => filesize("$d/files/$name"), 'done' => true]);
    up_said(200, ['have' => is_file("$d/files/$name.part") ? filesize("$d/files/$name.part") : 0, 'done' => false]);
}

// ── the next piece of a file ────────────────────────────────────────────────
if (isset($_GET['piece']) && $method === 'POST') {
    $d = $dir((string)($_GET['batch'] ?? '')); $m = $meta($d); $name = (string)($_GET['name'] ?? '');
    $want = null; foreach ($m['files'] as $x) if ($x['name'] === $name) $want = $x;
    if (!$want) up_said(400, ['error' => 'that file is not in this upload']);
    $f = "$d/files/$name"; $offset = (int)($_GET['offset'] ?? -1);
    if (!preg_match('/^[0-9a-f]{64}$/', $sum = strtolower((string)($_SERVER['HTTP_X_PIECE_SHA256'] ?? ''))))
        up_said(400, ['error' => 'each piece comes with its fingerprint']);
    clearstatcache();
    if (is_file($f)) up_said(200, ['have' => filesize($f), 'done' => true]);
    $have = is_file("$f.part") ? filesize("$f.part") : 0;
    if ($offset !== $have) up_said(409, ['error' => 'not the next piece', 'have' => $have]);
    $body = (string)file_get_contents($_SERVER['RUSHES_TEST_BODY'] ?? 'php://input');
    if ($body === '' || strlen($body) > 8 * 1024 * 1024 || $have + strlen($body) > (int)$want['size'])
        up_said(400, ['error' => 'that piece is not the right size']);
    if (hash('sha256', $body) !== $sum) up_said(422, ['error' => 'that piece arrived different from the one sent; it is sent again', 'have' => $have]);
    // The whole file's fingerprint, kept up to date piece by piece (where PHP can
    // keep it between requests; otherwise read once at the end).
    $ctx = null;
    if ($have === 0) { try { $ctx = hash_init('sha256'); } catch (Throwable $e) {} }
    elseif (is_file("$f.sum")) { try { $ctx = unserialize((string)file_get_contents("$f.sum")); } catch (Throwable $e) { $ctx = null; } }
    if (@file_put_contents("$f.part", $body, FILE_APPEND) !== strlen($body)) up_said(500, ['error' => 'could not write in the web folder']);
    if ($ctx instanceof HashContext) { hash_update($ctx, $body); try { @file_put_contents("$f.sum", serialize($ctx)); } catch (Throwable $e) { @unlink("$f.sum"); $ctx = null; } }
    else @unlink("$f.sum");
    $have += strlen($body);
    if ($have < (int)$want['size']) up_said(200, ['have' => $have, 'done' => false]);
    // Whole: its fingerprint, kept beside it for the helper, and the time it was shot.
    if (!($ctx instanceof HashContext)) { @set_time_limit(0); $fp = hash_file('sha256', "$f.part"); } else $fp = hash_final($ctx);
    rename("$f.part", $f); @unlink("$f.sum");
    if ((int)($want['mtime'] ?? 0) > 0) @touch($f, (int)$want['mtime']);
    @mkdir("$d/fp", 0775); @file_put_contents("$d/fp/$name", "sha256:$fp\n");
    up_said(200, ['have' => $have, 'done' => true]);
}

// ── where an upload is now, for the receipt ─────────────────────────────────
if (isset($_GET['state'])) {
    $batch = (string)($_GET['batch'] ?? ''); $d = $dir($batch); $rel = "phone/$batch";
    foreach (@file(web_dir() . '/ingest-history.tsv') ?: [] as $l) {
        $x = explode("\t", rtrim($l, "\n"));
        if (($x[2] ?? '') !== $rel) continue;
        if ($x[1] === 'uploaded') up_said(200, ['state' => 'placed', 'files' => (int)$x[3], 'bytes' => (int)$x[4], 'note' => $x[6] ?? '']);
        if ($x[1] === 'refused') up_said(200, ['state' => 'refused', 'note' => $x[6] ?? '']);
    }
    $m = $meta($d);
    up_said(200, ['state' => helper_control()['paused'] ? 'paused' : 'waiting', 'into' => $m['rel'] ?? '']);
}

if ($method !== 'POST') up_said(400, ['error' => 'nothing asked']);
$act = (string)($_POST['action'] ?? '');

// ── a new upload: what, for which shoot, by whom ────────────────────────────
if ($act === 'start') {
    // the name the page sends, or the one this browser was given (head.php)
    $who = mb_substr($clean($_POST['uploader'] ?? ''), 0, 60) ?: mb_substr($clean($_COOKIE['rushes_who'] ?? ''), 0, 60);
    if ($who === '') up_said(400, ['error' => 'Say who you are first (Who? at the top of the page): your name goes with the files.']);
    if (!shelf_chosen()) up_said(400, ['error' => 'Choose the folder ' . strtolower(shelf_word(true)) . ' live in first: Manage → Reorganize → 00.']);
    $folder = dept_folder((string)($_POST['dept'] ?? ''));
    if ($folder === null) up_said(400, ['error' => 'Pick a ' . strtolower(shelf_word()) . ' from the list.']);
    $date = (string)($_POST['date'] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dm) || !checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])
        || $date < '2005-01-01' || $date > date('Y-m-d', time() + 86400))
        up_said(400, ['error' => 'That date cannot be right.']);
    $event = ltrim(mb_substr($clean($_POST['event'] ?? ''), 0, 80), '. ');
    if ($event === '') up_said(400, ['error' => 'Say what the shoot was.']);
    $files = json_decode((string)($_POST['files'] ?? ''), true);
    if (!is_array($files) || !$files || count($files) > 2000) up_said(400, ['error' => 'Pick the photos and videos first.']);
    $list = []; $total = 0;
    foreach ($files as $x) {
        $n = (string)($x['name'] ?? ''); $sz = (int)($x['size'] ?? -1);
        if (!$plain($n) || $sz <= 0 || isset($list[$n])) up_said(400, ['error' => "“{$n}” cannot be taken (an empty file, or a name twice)."]);
        $list[$n] = ['name' => $n, 'size' => $sz, 'mtime' => max(0, (int)(($x['mtime'] ?? 0)))];
        $total += $sz;
    }
    if (($free = @disk_free_space(web_dir())) !== false && $free < $total + limit('inbox_free_min', 20 * 1024 ** 3))
        up_said(507, ['error' => 'Not enough room on the server for these now. Fewer at a time, or ask the person who looks after Rushes.']);
    $seen = helper_archive();
    if ($seen === '') up_said(400, ['error' => 'Setup does not know where the helper finds the archive yet.']);
    $rel = substr(shelf_dir(), strlen(archive_dir())) . "/$folder/{$dm[1]}/{$dm[1]}{$dm[2]}{$dm[3]} $event";
    $batch = date('YmdHis') . '-' . bin2hex(random_bytes(4));
    $top = web_dir() . '/inbox';
    if (!is_dir($top)) { @mkdir($top, 0775); @file_put_contents("$top/.htaccess", "Require all denied\n"); }   // never handed out
    $d = "$top/phone/$batch";
    if (!@mkdir("$d/files", 0775, true)) up_said(500, ['error' => 'Could not make room in the web folder.']);
    $m = ['uploader' => $who, 'device' => mb_substr($clean($_SERVER['HTTP_USER_AGENT'] ?? '', 200), 0, 200),
          'from' => $_SERVER['REMOTE_ADDR'] ?? '', 'dept' => (string)$_POST['dept'], 'event' => $event, 'date' => $date,
          'into' => $seen . $rel, 'rel' => ltrim($rel, '/'), 'files' => array_values($list), 'started' => time()];
    if (@file_put_contents("$d/meta.json", json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false)
        up_said(500, ['error' => 'Could not write in the web folder.']);
    up_said(200, ['batch' => $batch, 'into' => $m['rel']]);
}

// ── every file whole: listed for the helper, and queued ─────────────────────
if ($act === 'finish') {
    $batch = (string)($_POST['batch'] ?? ''); $d = $dir($batch); $m = $meta($d);
    $lines = ["rushes-upload 1", "uploader\t{$m['uploader']}", "device\t" . str_replace("\t", ' ', $m['device']), "into\t{$m['into']}"];
    clearstatcache(); $missing = [];
    foreach ($m['files'] as $x) {
        $f = "$d/files/{$x['name']}"; $fp = trim((string)@file_get_contents("$d/fp/{$x['name']}"));
        if (!is_file($f) || filesize($f) !== $x['size'] || !preg_match('/^sha256:[0-9a-f]{64}$/', $fp)) { $missing[] = $x['name']; continue; }
        $lines[] = "file\t{$x['name']}\t$fp\t{$x['size']}\t{$x['mtime']}";
    }
    if ($missing) up_said(409, ['error' => 'Not every file has arrived yet.', 'missing' => $missing]);
    $lines[] = 'end';
    if (@file_put_contents("$d/batch.tsv", implode("\n", $lines) . "\n") === false) up_said(500, ['error' => 'Could not write in the web folder.']);
    // Into the helper's list, after cards (one writer at a time: queue.php and the others take this lock too).
    $q = web_dir() . '/ingest-queue.tsv'; $lk = fopen(web_dir() . '/ingest-queue.lock', 'c'); if ($lk) flock($lk, LOCK_EX);
    $have = @file($q, FILE_IGNORE_NEW_LINES) ?: []; $line = "upload\tphone/$batch";
    if (!in_array($line, $have, true) && @file_put_contents($q, $line . "\n", FILE_APPEND) === false) up_said(500, ['error' => 'Could not tell the helper.']);
    up_said(200, ['queued' => true, 'into' => $m['rel'], 'files' => count($m['files']), 'bytes' => array_sum(array_column($m['files'], 'size'))]);
}

up_said(400, ['error' => 'unknown action']);
