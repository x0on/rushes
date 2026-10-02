<?php
// import.php — the catalogue brought up to date: the file list, descriptions,
// proxies' details, the next folder to prepare, the daily database copy.
// The runner asks for it every minute, from this machine; Jobs and tools →
// Rebuild search asks for it by hand, signed in. Nobody else may start it.
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
$from = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($from, ['127.0.0.1', '::1', $_SERVER['SERVER_ADDR'] ?? '-'], true) && !may_act()) {
    http_response_code(403); echo '{"error":"sign in first"}'; exit;
}
// Two halves. ?part=web: only what lives in the web folder (the file list, the
// proxies' details, the daily database copy), however long it takes. ?part=video:
// only what reads the VIDEO share (new descriptions, the prepare list's proxy
// check); the runner asks for it separately, within a time limit, and only when
// VIDEO may be read. No part: both. &force=1 (Jobs and tools → Rebuild search)
// rebuilds from the file list even when search already has it.
$part  = (string)($_GET['part'] ?? '');
$web   = $part !== 'video';
$video = $part !== 'web';
$busy  = false;
if ($video) {
    // never two at once: a look stuck on a dying disk is not joined by another
    $vl = fopen(web_dir() . '/import-video.lock', 'c');
    if (!$vl || !flock($vl, LOCK_EX | LOCK_NB)) { $video = false; $busy = true; }
}
set_time_limit(0);
require_once __DIR__ . '/analysis.php';
require_once __DIR__ . '/prepare.php';
$result = $web ? sync_search(($_GET['force'] ?? '') === '1') : ['state' => 'current'];
if ($busy) $result['video'] = 'busy: the last look at VIDEO has not finished';
// What the helper has described since last time, into search too.
if ($video) try { $result['analysis'] = analysis_import(); } catch (Throwable $e) { $result['analysis'] = ['error' => $e->getMessage()]; }
// Proxies made since last time: what each original is, into the media ledger.
if ($web) try { $result['media'] = media_import(); } catch (Throwable $e) { $result['media'] = ['error' => $e->getMessage()]; }
// Folders being prepared: start the next proxies, or queue the next describing.
if ($video) try { $result['prepare'] = prepare_advance(); } catch (Throwable $e) { $result['prepare'] = ['error' => $e->getMessage()]; }
// Once a day: the database checked, and a good one copied (HOW-IT-WORKS.md → Rushes' own backups).
if ($web) runner_paths();                       // where the archive is, for the runner (config.php)
if ($web) try { $result['db_copy'] = db_daily_copy(); } catch (Throwable $e) { $result['db_copy'] = ['error' => $e->getMessage()]; }
if (isset($result['error'])) http_response_code(503);
echo json_encode($result);
