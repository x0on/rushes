<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// pair.php — one helper is paired with Rushes, and only it is given work
// (HOW-IT-WORKS.md → Pairing; the doors check it with helper_gate() in config.php).
//
//   POST action=start          signed in (Setup): a one-time code, good for ten minutes
//   POST code=<6 digits> host  Rushes Helper: the code Setup shows → this helper's ID
//   GET                        a helper, with its ID: {"pairing": "none" | "this" | "other"}
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function said(int $code, array $a) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
$C = web_dir() . '/pair-code.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $p = helper_paired();
    said(200, ['pairing' => helper_pairing()] + (signed_in() ? ['host' => $p['host'] ?? '', 'at' => $p['at'] ?? 0, 'refused' => helper_refused()] : []));
}

$act = (string)($_POST['action'] ?? '');
if ($act === 'start') {
    if (!may_act((string)($_POST['pass'] ?? ''))) said(403, ['error' => 'sign in first']);
    $code = sprintf('%06d', random_int(0, 999999));
    if (!php_keep($C, ['hash' => hash('sha256', $code), 'until' => time() + 600, 'tries' => 0]))
        said(500, ['error' => 'Could not save the code — is the web folder writable?']);
    said(200, ['code' => $code, 'until' => time() + 600]);
}

// A helper sends the code
$c = is_readable($C) ? @include $C : null;
$given = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
if (!is_array($c) || time() > (int)$c['until'])
    said(403, ['error' => 'No code is waiting, or it is more than ten minutes old. Get a new one in Rushes → Setup → Pair a helper.']);
if (!hash_equals((string)$c['hash'], hash('sha256', $given))) {
    if (++$c['tries'] >= 5) { @unlink($C); said(403, ['error' => 'Five wrong codes: that code is cancelled. Get a new one in Rushes → Setup.']); }
    php_keep($C, $c);
    said(403, ['error' => 'That is not the code Rushes shows. Try again.']);
}
@unlink($C);
$host = substr(preg_replace('/[^\p{L}\p{N} ._\'’-]/u', '', (string)($_POST['host'] ?? '')), 0, 80);
$id = bin2hex(random_bytes(16));
if (!php_keep(helper_id_file(), ['id' => $id, 'host' => $host, 'at' => time()]))
    said(500, ['error' => 'Could not save the pairing — is the web folder writable?']);
@unlink(web_dir() . '/helper-refused.tsv');
said(200, ['id' => $id, 'host' => $host]);
