<?php
// proxy-one.php — Search's "Make its proxy now": one video's proxy, made by the
// runner in the background (the same job as a folder's, for that file alone),
// so it can be played without adding its whole folder in Describe.
//   POST p=<the file's path in the archive>
// Only a video the catalogue knows, inside the archive. Nothing is changed but
// the proxy that is made under PROXIES.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', $_SERVER['SERVER_ADDR'] ?? '-'], true);
$no = function (int $code, string $why) { http_response_code($code); echo json_encode(['error' => $why]); exit; };
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $no(405, 'ask from Search');
if (!$local && !may_act()) $no(403, 'sign in first');
db_init();
$path = (string)($_POST['p'] ?? '');
$st = db()->prepare("SELECT path FROM files WHERE path = ? AND kind = 'video'");
$st->bindValue(1, $path);
if (!$st->execute()->fetchArray()) $no(404, 'Not a video Rushes knows.');
$root = rtrim(archive_dir(), '/');
if (!str_starts_with($path, "$root/")) $no(400, 'Only videos in the archive have proxies.');
$rel = substr($path, strlen($root) + 1);
if (in_array('..', explode('/', $rel), true) || preg_match('/[\t\r\n\0]/', $rel)) $no(400, 'Not a path Rushes can ask for.');   // any other name is asked for as it is (runner.py rel_folder)
$q = web_dir() . '/queue';
if (!is_dir($q)) @mkdir($q, 0777, true);
if (@file_put_contents("$q/" . date('Ymd-His') . '-one.job', "ACTION=proxy-build\nQUERY=$rel\n") === false)
    $no(500, 'Could not ask the archive machine: its queue folder is not writable.');
echo json_encode(['ok' => 'asked', 'file' => basename($path)]);
