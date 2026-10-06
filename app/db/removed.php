<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// removed.php — Recently Removed, on the archive and on each drive kept where
// it is (HOW-IT-WORKS.md → Recently Removed): how much is in it, since when,
// and whether the week Rushes suggests waiting has passed. Remove (Duplicates,
// Cache) puts files there; nothing in it is deleted until a person presses
// Delete All (run.php action=empty), never by itself.
//
//   GET    {places: [{drive, name, bytes, files, at, ready}], wait_days}
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!signed_in()) { http_response_code(403); echo json_encode(['error' => 'sign in first']); exit; }

const REMOVED_WAIT = 7 * 86400;          // the week Rushes suggests keeping things before Delete All
$W = web_dir();
$place = function (string $drive, string $name, string $sfx) use ($W) {
    [$kb, $files] = array_pad(array_map('intval', preg_split('/\s+/', trim((string)@file_get_contents("$W/holding-kb$sfx.txt")))), 2, 0);
    $at = (int)trim((string)@file_get_contents("$W/removed-at$sfx.txt"));
    return ['drive' => $drive, 'name' => $name, 'bytes' => $kb * 1024, 'files' => $files, 'at' => $at,
            'ready' => $at > 0 && time() - $at >= REMOVED_WAIT];
};
$out = [$place('', basename(rtrim(archive_dir(), '/')) ?: 'The archive', '')];
foreach (drives_seen() as $d)
    if (!empty($d['connected'])) $out[] = $place((string)$d['source'], (string)$d['name'], '-' . drive_key($d));
echo json_encode(['places' => $out, 'wait_days' => REMOVED_WAIT / 86400, 'mac' => on_mac()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
