<?php
// Manual repair and the scheduled runner use the same atomic reconciliation.
require_once __DIR__ . '/sync.php';
header('Content-Type: application/json');
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
require_once __DIR__ . '/prepare.php';
if ($video) try { $result['prepare'] = prepare_advance(); } catch (Throwable $e) { $result['prepare'] = ['error' => $e->getMessage()]; }
if (isset($result['error'])) http_response_code(503);
echo json_encode($result);
