<?php
// thumb.php?fp=<fingerprint>&shot=<n> — the small picture kept for one shot.
require_once __DIR__ . '/config.php';
$fp = (string)($_GET['fp'] ?? ''); $shot = (int)($_GET['shot'] ?? -1);
if (!preg_match('/^[0-9a-f]{24}$/', $fp) || $shot < 0) { http_response_code(400); exit; }
$f = archive_dir() . '/_rushes/analysis/' . substr($fp, 0, 2) . "/$fp/" . sprintf('%04d', $shot) . '.jpg';
if (!is_readable($f)) { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400');
readfile($f);
