<?php
// report.php — where the helper machine says what it can see.
//
// The helper posts its list of drives and cards every twenty seconds. The page
// builds every picker from that list, so nobody types a path the copier cannot
// reach.
//
// ponytail: no password, because the helper has none. It can write exactly one
// file, in exactly one shape. A forged list gains nothing: the helper re-checks
// every path against its own drives before it copies a byte.
require_once __DIR__ . '/config.php';
helper_gate();                          // only the paired helper's word is taken (pair.php)
header('Content-Type: application/json');

$raw = (string)($_POST['volumes'] ?? '');
if (strlen($raw) > 400000) { http_response_code(413); echo '{"error":"too big"}'; exit; }

$keep = [];
foreach (explode("\n", $raw) as $l) {
    $f = explode("\t", $l);
    if (str_contains($l, '..')) continue;
    if ($f[0] === 'vol' && count($f) === 9 && $f[1] !== ''
        && ctype_digit($f[3]) && ctype_digit($f[4]) && ctype_digit($f[7]) && ctype_digit($f[8])) {
        $keep[] = $l;
    } elseif ($f[0] === 'day' && count($f) === 5 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[2])
              && ctype_digit($f[3]) && ctype_digit($f[4])) {
        $keep[] = $l;                         // how many files the card holds from each day
    } elseif ($f[0] === 'net' && count($f) === 2 && str_starts_with($f[1], '/')) {
        $keep[] = $l;                         // that volume is a network share (a NAS), not a drive
    } elseif ($f[0] === 'dir' && count($f) === 3 && $f[2] !== ''
              && !str_contains($f[2], '/') && !str_contains($f[2], '\\')) {
        $keep[] = $l;
    }
}
$os  = preg_replace('/[^a-z0-9]/', '', strtolower((string)($_POST['os'] ?? '')));
$ver  = preg_replace('/[^a-f0-9]/', '', (string)($_POST['ver'] ?? ''));
$how  = in_array($_POST['how'] ?? '', ['service', 'window'], true) ? $_POST['how'] : '';
$host = substr(preg_replace('/[^A-Za-z0-9 ._-]/', '', (string)($_POST['host'] ?? '')), 0, 60);
// a drive it has been waiting on for minutes, named, so the page can say which
$stuck = substr(str_replace(["\t", "\n"], ' ', (string)($_POST['stuck'] ?? '')), 0, 300);
// Whether it can describe footage, and with which models: shown on Describe.
$an = implode("\t", array_map(fn($x) => substr(preg_replace('/[^A-Za-z0-9 ._\/-]/', '', $x), 0, 120),
                               array_slice(explode("\t", (string)($_POST['an'] ?? '')), 0, 3)));
$out = "at\t" . time() . "\nos\t$os\nver\t$ver\nhow\t$how\nhost\t$host\nan\t$an\nstuck\t$stuck\n" . implode("\n", $keep) . "\n";

// Beside, then rename: a page reading half a list would offer half the drives.
$f = web_dir() . '/helper-volumes.tsv';
$ok = @file_put_contents("$f.new", $out) !== false && @rename("$f.new", $f);
echo json_encode(['ok' => $ok, 'lines' => count($keep)]);
