<?php
// Manual repair and the scheduled runner use the same atomic reconciliation.
require_once __DIR__ . '/sync.php';
header('Content-Type: application/json');
set_time_limit(0);
$result = sync_search();
if (isset($result['error'])) http_response_code(503);
echo json_encode($result);
