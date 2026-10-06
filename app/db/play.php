<?php
// play.php — a file's proxy, played in Search (HOW-IT-WORKS.md → Finding footage).
//
//   ?p=<the file's path in the archive>
//
// Only a file the catalogue knows, and only its proxy (PROXIES/<same path>.mp4),
// or the file itself when it is light enough to play as it is (no proxy made:
// media.asis, runner.py's light()): nothing else on the archive can be asked for here. Sent in pieces as the
// player asks for them (HTTP Range), so a long clip starts at once and a jump to
// a described moment reads only from there. It reads the archive only while
// someone is playing.
require_once __DIR__ . '/schema.php';
db_init();

function nope(int $code, string $why) { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); exit($why . "\n"); }

$path = (string)($_GET['p'] ?? '');
$st = db()->prepare('SELECT f.path, m.asis FROM files f JOIN media m ON m.file_id = f.id WHERE f.path = ? AND m.proxy_at');
$st->bindValue(1, $path);
$row = $st->execute()->fetchArray(SQLITE3_ASSOC);
if (!$row) nope(404, 'No proxy for this file yet.');

$root = rtrim(archive_dir(), '/');
if (!str_starts_with($row['path'], "$root/")) nope(404, 'Not in the archive.');
$rel = substr($row['path'], strlen($root) + 1);
$proxy = "$root/PROXIES/" . preg_replace('/\.[^.\/]*$/', '', $rel) . '.mp4';
$real = realpath($proxy);
if ($real === false && $row['asis'] === '1') {                 // the original plays as it is
    $real = realpath($row['path']);
    if ($real === false || !str_starts_with($real, realpath($root) . '/') || !is_file($real)) nope(404, 'The file is not on the archive.');
} elseif ($real === false || !str_starts_with($real, realpath("$root/PROXIES") . '/') || !is_file($real)) nope(404, 'The proxy is not on the archive.');

$size = filesize($real);
$from = 0; $to = $size - 1;
if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m)) {
    if ($m[1] === '' && $m[2] !== '') { $from = max(0, $size - (int)$m[2]); }                 // the last N bytes
    else { $from = (int)$m[1]; if ($m[2] !== '') $to = min((int)$m[2], $size - 1); }
    if ($from > $to || $from >= $size) { header("Content-Range: bytes */$size"); nope(416, 'Outside the file.'); }
    http_response_code(206);
    header("Content-Range: bytes $from-$to/$size");
}
header('Content-Type: video/mp4');
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($to - $from + 1));
header('Cache-Control: private, max-age=3600');
$f = fopen($real, 'rb'); fseek($f, $from);
for ($left = $to - $from + 1; $left > 0 && !feof($f) && !connection_aborted(); $left -= strlen($chunk)) {
    $chunk = fread($f, min(1 << 20, $left));
    if ($chunk === '' || $chunk === false) break;
    echo $chunk; flush();
}
fclose($f);
