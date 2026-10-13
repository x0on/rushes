<?php
// play.php — a file's proxy, played in Search (HOW-IT-WORKS.md → Finding footage).
//
//   ?p=<the file's path in the archive>
//
// Only a file the catalogue knows, and only its proxy (config.php preview_of: PROXIES/<same path>.mp4,
// or _rushes/previews/<drive ID>/… for a drive kept where it is),
// or the file itself when it is light enough to play as it is (no proxy made:
// media.asis, runner.py's light()), or a sound or a picture a browser shows as it
// is (MP3, WAV, M4A … JPG, PNG …): nothing else on the archive can be asked for here. Sent in pieces as the
// player asks for them (HTTP Range), so a long clip starts at once and a jump to
// a described moment reads only from there. It reads the archive only while
// someone is playing.
require_once __DIR__ . '/schema.php';
db_init();

function nope(int $code, string $why) { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); exit($why . "\n"); }

$path = (string)($_GET['p'] ?? '');
$root = rtrim(archive_dir(), '/');
// A sound or a picture: the file itself, read only. Small, and nothing to make first.
const AS_IS = ['mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'aif' => 'audio/aiff', 'aiff' => 'audio/aiff',
               'flac' => 'audio/flac', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
               'webp' => 'image/webp', 'heic' => 'image/heic'];
$type = 'video/mp4';
$st = db()->prepare("SELECT path, ext FROM files WHERE path = ? AND kind IN ('audio', 'image')");
$st->bindValue(1, $path);
$one = $st->execute()->fetchArray(SQLITE3_ASSOC);
if ($one && isset(AS_IS[strtolower((string)$one['ext'])])) {
    $real = realpath($one['path']);
    $roots = array_filter(array_merge([realpath($root)], array_map(fn($r) => realpath(rtrim($r, '/')), catalogue_roots())));
    $inside = $real !== false && is_file($real) && array_filter($roots, fn($r) => str_starts_with($real, $r . '/'));
    if (!$inside) nope(404, 'The file is not there (is its drive plugged in?).');
    $type = AS_IS[strtolower($one['ext'])];
    goto send;
}
$st = db()->prepare('SELECT f.path, m.asis FROM files f JOIN media m ON m.file_id = f.id WHERE f.path = ? AND m.proxy_at');
$st->bindValue(1, $path);
$row = $st->execute()->fetchArray(SQLITE3_ASSOC);
if (!$row) nope(404, 'No proxy for this file yet.');

// the archive's own under PROXIES; a kept drive's on the archive too (_rushes/previews), so it plays unplugged
$proxy = preview_of($row['path']);
if ($proxy === null) nope(404, 'Not in the archive, nor on a drive in Search.');
$drive = !str_starts_with($row['path'], "$root/");
$home = realpath($drive ? "$root/_rushes/previews" : "$root/PROXIES");
$real = realpath($proxy);
if ($real === false && $row['asis'] === '1') {                 // the original plays as it is (its drive plugged in)
    $real = realpath($row['path']);
    $top = $drive ? realpath(kept_drive_of($row['path'])['root']) : realpath($root);
    if ($real === false || !$top || !str_starts_with($real, $top . '/') || !is_file($real)) nope(404, 'The file is not there (is its drive plugged in?).');
} elseif ($real === false || !$home || !str_starts_with($real, $home . '/') || !is_file($real)) nope(404, 'The proxy is not on the archive.');

send:
$size = filesize($real);
$from = 0; $to = $size - 1;
if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m)) {
    if ($m[1] === '' && $m[2] !== '') { $from = max(0, $size - (int)$m[2]); }                 // the last N bytes
    else { $from = (int)$m[1]; if ($m[2] !== '') $to = min((int)$m[2], $size - 1); }
    if ($from > $to || $from >= $size) { header("Content-Range: bytes */$size"); nope(416, 'Outside the file.'); }
    http_response_code(206);
    header("Content-Range: bytes $from-$to/$size");
}
header('Content-Type: ' . $type);
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
