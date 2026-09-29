<?php
// Manual repair and the scheduled runner use the same atomic reconciliation.
require_once __DIR__ . '/sync.php';
header('Content-Type: application/json');
set_time_limit(0);
$result = sync_search();
// What the helper has described since last time, into search too.
require_once __DIR__ . '/analysis.php';
try { $result['analysis'] = analysis_import(); } catch (Throwable $e) { $result['analysis'] = ['error' => $e->getMessage()]; }
if (isset($result['error'])) http_response_code(503);
echo json_encode($result);
