<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// delivered.php — the helper says where each file of a Watcher's delivery now
// is in the archive (HOW-IT-WORKS.md → Projects in and out). Only the paired
// helper's word is taken, and only for files it has put inside the archive
// and that are there, at the size it says.
//
//   POST batch=<key>/<name>  files=<original \t rel \t fingerprint \t kind \t project \t bytes>, one per line
require_once __DIR__ . '/schema.php';
header('Content-Type: application/json');
helper_gate();
function said(int $code, array $a) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }
$batch = (string)($_POST['batch'] ?? '');
if (!preg_match('#^[a-f0-9]{16}/[A-Za-z0-9_.-]{1,80}$#', $batch)) said(400, ['error' => 'not a batch']);
db_init(); $db = db();
$root = rtrim(archive_dir(), '/');
$put = $db->prepare('INSERT OR REPLACE INTO delivered (batch, original, rel, fp, kind, project, at) VALUES (?,?,?,?,?,?,?)');
$proj = $db->prepare('UPDATE projects SET archived = ? WHERE path = ?');
$n = 0; $refused = [];
$db->exec('BEGIN');
foreach (explode("\n", (string)($_POST['files'] ?? '')) as $l) {
    $f = explode("\t", rtrim($l, "\r"));
    if (count($f) < 6) continue;
    [$orig, $rel, $fp, $kind, $project, $bytes] = $f;
    // inside the archive, really there, at the size the helper says
    if ($rel === '' || str_starts_with($rel, '/') || str_contains($rel, '..') || !is_file("$root/$rel") || filesize("$root/$rel") !== (int)$bytes
        || !in_array($kind, ['music', 'stock', 'sfx', 'project', 'output', 'projectfile'], true)) { $refused[] = $rel; continue; }
    foreach ([$batch, mb_substr($orig, 0, 1000), $rel, mb_substr($fp, 0, 80), $kind, mb_substr($project, 0, 400), time()] as $i => $v) $put->bindValue($i + 1, $v);
    $put->execute(); $put->reset(); $n++;
    if ($kind === 'projectfile') { $proj->bindValue(1, $rel); $proj->bindValue(2, $project); $proj->execute(); $proj->reset(); }
}
$db->exec('COMMIT');
said(200, ['recorded' => $n, 'refused' => $refused]);
