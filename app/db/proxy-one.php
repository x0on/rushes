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
if (str_contains($rel, '..') || str_contains($rel, "\n")) $no(400, 'Not a path Rushes can ask for.');
// the runner reads a job's path with the same characters only (runner.py): say so rather than ask for nothing
if (preg_match('/[^A-Za-z0-9 _.\/&(),+\x{80}-\x{10FFFF}-]/u', $rel, $m))
    $no(400, "Its name or folder has a “{$m[0]}”, which the proxy maker cannot be asked for yet. Add its folder in Manage → Describe instead.");
$q = web_dir() . '/queue';
if (!is_dir($q)) @mkdir($q, 0777, true);
if (@file_put_contents("$q/" . date('Ymd-His') . '-one.job', "ACTION=proxy-build\nQUERY=$rel\n") === false)
    $no(500, 'Could not ask the archive machine: its queue folder is not writable.');
echo json_encode(['ok' => 'asked', 'file' => basename($path)]);
