<?php
// thumb.php?fp=<fingerprint>&shot=<n> — the small picture kept for one shot.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/analysis.php';
$fp = (string)($_GET['fp'] ?? ''); $shot = (int)($_GET['shot'] ?? -1);
if (!preg_match('/^[0-9a-f]{24}$/', $fp) || $shot < 0) { http_response_code(400); exit; }
$f = analysis_still($fp, $shot);                                   // this Mac's copy first, else the archive's
if ($f === '') { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400');
readfile($f);
