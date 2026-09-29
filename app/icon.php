<?php
// icon.php — the app icon as a real image address. Safari will not use an
// icon written into the page itself, and keeps whatever it saw first for a
// long time; an address with the icon's own fingerprint in it (?v=) gives it
// a new one to fetch the moment the icon changes.
require __DIR__ . '/db/config.php';
$data = (($_GET['s'] ?? '') === '180') ? home_icon_href() : favicon_href();
header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000, immutable');
echo base64_decode(substr($data, strpos($data, ',') + 1));
