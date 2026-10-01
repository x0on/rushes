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
set_time_limit(0);
$result = sync_search();
// What the helper has described since last time, into search too.
require_once __DIR__ . '/analysis.php';
// ?video=0: the runner says the VIDEO share is not answering (or paused).
// Then only what lives here is brought in; nothing that reads VIDEO runs.
$video = ($_GET['video'] ?? '1') !== '0';
if ($video) try { $result['analysis'] = analysis_import(); } catch (Throwable $e) { $result['analysis'] = ['error' => $e->getMessage()]; }
// Proxies made since last time: what each original is, into the media ledger.
require_once __DIR__ . '/prepare.php';
try { $result['media'] = media_import(); } catch (Throwable $e) { $result['media'] = ['error' => $e->getMessage()]; }
// Folders being prepared: start the next proxies, or queue the next describing.
if ($video) try { $result['prepare'] = prepare_advance(); } catch (Throwable $e) { $result['prepare'] = ['error' => $e->getMessage()]; }
// Once a day: the database checked, and a good one copied (RISKS.md #7).
try { $result['db_copy'] = db_daily_copy(); } catch (Throwable $e) { $result['db_copy'] = ['error' => $e->getMessage()]; }
if (isset($result['error'])) http_response_code(503);
echo json_encode($result);
