<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// projects.php — resting and moving aside (HOW-IT-WORKS.md → Projects in and out).
//
// A project not saved for 10 days (a setting) is resting: nothing moves. Its
// folder on the Projects share is moved aside after 90 days (a setting, 0 for
// never), into "_Moved aside" on the same share, only when every project in it
// has a copy kept in the archive and nothing it uses is missing. Bring it back
// puts the folder where it was. This page decides; the runner moves (as root,
// on the Projects share, which this page cannot write) and says what it did.
//
//   GET  ?plan                              the runner, from this machine: "aside|back \t <folder>" lines
//   POST action=moved  lines                the runner: "aside|back \t <folder> \t ok|why" lines
//   POST action=back   folder               signed in (Manage): bring that folder back, within a minute
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/auth.php';
db_init();
$from  = $_SERVER['REMOTE_ADDR'] ?? '';
$local = in_array($from, ['127.0.0.1', '::1', $_SERVER['SERVER_ADDR'] ?? '-'], true);
$BACK  = web_dir() . '/projects-back.txt';
$LOG   = web_dir() . '/projects-moves.tsv';

// Which folders may be moved aside now: every project inside each one is
// asleep long enough, kept in the archive, and missing nothing.
function projects_plan(): array {
    $s = settings()['projects'] ?? [];
    $days = (int)($s['aside_days'] ?? 90);
    if ($days <= 0) return [];
    $old = time() - $days * 86400;
    $all = []; $r = db()->query("SELECT path, saved, archived, missing, state, aside_at FROM projects");
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) $all[] = $x;
    // aside_at: when it was last moved, either way, so a folder brought back
    // is left in its place for as long again before it can be moved aside
    $ready = fn($p) => (int)$p['saved'] > 0 && (int)$p['saved'] < $old && (int)$p['aside_at'] < $old
                       && (string)$p['archived'] !== '' && (string)$p['missing'] === '';
    $folders = [];
    foreach ($all as $p) {
        $d = dirname($p['path']);
        if ($p['state'] === 'aside' || $d === '.' || $d === '' || str_starts_with($d, '_Moved aside')) continue;
        $folders[$d] = true;
    }
    $out = [];
    foreach (array_keys($folders) as $d) {
        $in = array_filter($all, fn($p) => str_starts_with($p['path'], "$d/") && $p['state'] !== 'aside');
        if ($in && count(array_filter($in, $ready)) === count($in)) $out[] = $d;
    }
    sort($out);
    // a folder inside one already being moved goes with it
    return array_values(array_filter($out, function ($d) use ($out) {
        foreach ($out as $o) if ($o !== $d && str_starts_with($d, "$o/")) return false;
        return true;
    }));
}

$plain = fn($f) => $f !== '' && !str_starts_with($f, '/') && !str_contains($f, '..') && !preg_match('/[\t\r\n\\\\]/', $f);

if (isset($_GET['plan'])) {
    if (!$local) { http_response_code(403); exit("Only the runner asks for this.\n"); }
    header('Content-Type: text/plain; charset=utf-8');
    foreach (projects_plan() as $d) echo "aside\t$d\n";
    foreach (array_unique(array_filter(array_map('trim', @file($BACK) ?: []), $plain)) as $d) echo "back\t$d\n";
    exit;
}

header('Content-Type: application/json');
$act = (string)($_POST['action'] ?? '');
if ($act === 'moved') {
    if (!$local) { http_response_code(403); echo '{"error":"only the runner says this"}'; exit; }
    $set = db()->prepare("UPDATE projects SET state = ?, aside_at = ? WHERE path LIKE ? ESCAPE '\\'");
    $done = [];
    foreach (explode("\n", (string)($_POST['lines'] ?? '')) as $l) {
        [$what, $d, $why] = array_pad(explode("\t", rtrim($l, "\r")), 3, '');
        if (!in_array($what, ['aside', 'back'], true) || !$plain($d)) continue;
        @file_put_contents($LOG, time() . "\t$what\t$d\t$why\n", FILE_APPEND);
        if ($what === 'back') $done[] = $d;               // asked once: said in the log either way, never retried for ever
        if ($why !== 'ok') continue;
        foreach ([$what === 'aside' ? 'aside' : 'active', time(),
                  str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $d) . '/%'] as $i => $v) $set->bindValue($i + 1, $v);
        $set->execute(); $set->reset();
    }
    if ($done) {
        $left = array_diff(array_map('trim', @file($BACK) ?: []), $done, ['']);
        @file_put_contents("$BACK.new", $left ? implode("\n", $left) . "\n" : '') !== false && @rename("$BACK.new", $BACK);
    }
    echo json_encode(['ok' => true]); exit;
}
if ($act === 'back') {
    if (!signed_in()) { http_response_code(403); echo '{"error":"Sign in to Manage first."}'; exit; }
    $d = trim((string)($_POST['folder'] ?? ''));
    $st = db()->prepare("SELECT COUNT(*) FROM projects WHERE state = 'aside' AND path LIKE ? ESCAPE '\\'");
    $st->bindValue(1, str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $d) . '/%');
    if (!$plain($d) || !(int)$st->execute()->fetchArray()[0]) { http_response_code(400); echo '{"error":"That folder is not moved aside."}'; exit; }
    @file_put_contents($BACK, "$d\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['ok' => true, 'said' => "Asked ✓ The runner puts $d back on the Projects share within a minute."]); exit;
}
http_response_code(400); echo '{"error":"nothing asked"}';
