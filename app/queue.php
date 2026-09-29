<?php
// queue.php — what the helper machine should do next.
//
// It writes one file and nothing else. The helper (ingest.py --watch) reads it
// and works through it. It is a WANT, not a task list: nothing here claims or
// clears anything, so pressing a button twice is harmless.
//
// Two kinds of request, with two different doors:
//   ingest      — a card into its shoot folder. The everyday action, so it is
//                 open to anyone who can reach this page. It is also narrow:
//                 only a drive the helper reported, only into a folder this
//                 file works out itself, never over an existing file.
//   copy, list  — whole source folders. Admin work, behind the lock.

header('Content-Type: application/json');
require_once __DIR__ . '/db/auth.php';
require_once __DIR__ . '/db/transfers.php';

$OUT = web_dir() . '/ingest-queue.tsv';
function bail(int $code, string $why) { http_response_code($code); echo json_encode(['error' => $why]); exit; }

// What is already asked for, split by kind, so each door only rewrites its own.
$landed = [];
foreach (@file(web_dir() . '/ingest-history.tsv') ?: [] as $l) {
    $f = explode("\t", rtrim($l, "\n"));
    if (count($f) >= 3 && in_array($f[1], ['copied', 'refused'], true)) $landed[$f[2]] = true;
    if (count($f) >= 3 && in_array($f[1], ['tidied', 'untidied', 'refused'], true)) $landed[preg_replace('/ /', "\t", $f[2], 1)] = true;
}
// A tidy-up (Structure → Tidy-up) is its own door, db/tidy.php. Kept here
// untouched, and dropped once the helper has done it.
$ingests = []; $tidies = []; $others = [];
foreach (@file($OUT) ?: [] as $l) {
    $l = rtrim($l, "\n");
    if ($l === '') continue;
    if (preg_match('/^(un)?tidy\t/', $l)) { if (!isset($landed[$l])) $tidies[] = $l; continue; }
    if (!str_starts_with($l, "ingest\t")) { $others[] = $l; continue; }
    // A card that has landed leaves the list, or the list grows for ever.
    if (!isset($landed[explode("\t", $l)[2] ?? ''])) $ingests[] = $l;
}

if (isset($_POST['ingest_src'])) {
    // ── a card ─────────────────────────────────────────────────────────────
    $src = (string)$_POST['ingest_src'];
    // Ingest is for cards. A drive or a folder comes in through Transfers, as
    // an exact copy — so only something the helper reported as a card is taken.
    $card = null;
    foreach (helper_volumes()['vols'] as $v) if ($v['path'] === $src) $card = $v;
    if (!$card) bail(400, 'That card is not one the helper can see right now.');
    if (!$card['card']) bail(400, 'Ingest is for cards. A drive or folder comes in through Manage → Transfers.');

    // Only a department in the plan, and it lands in that department's own
    // folder — PARKS for Parks & Recreation — never a name typed in a browser.
    $added = '';
    $new = trim(preg_replace('/\s+/', ' ', (string)($_POST['new_dept'] ?? '')));
    if ($new !== '') {
        // Adding to the plan from Ingest is allowed only where Structure says so,
        // and under the same no-catch-all rule. It becomes a real entry,
        // visible in Structure, with its own folder.
        if (!shelf_open()) bail(403, 'New ' . strtolower(shelf_word(true)) . ' are added in Structure, by the admin.');
        $names = array_column(departments(), 'name');
        if ($why = shelf_name_problem($new, $names)) bail(400, $why);
        $s = settings();
        $s['organise']['departments'][] = ['name' => $new, 'folder' => ''];
        if (!save_settings($s)) bail(500, 'Could not add it — is the web folder writable?');
        settings(true);
        $_POST['dept'] = $added = $new;
    }
    $folder = dept_folder((string)($_POST['dept'] ?? ''));
    if ($folder === null) bail(400, 'Pick a ' . strtolower(shelf_word()) . ' from the list.');

    $date = (string)($_POST['date'] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]))
        bail(400, 'The date is not a date.');

    // What it was becomes a folder name, so it loses anything a folder name
    // cannot hold on a Mac, on Windows, or on the NAS.
    $event = trim(preg_replace('/\s+/', ' ', preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]/', ' ', (string)($_POST['event'] ?? ''))));
    $event = ltrim(mb_substr($event, 0, 80), '. ');
    if ($event === '') bail(400, 'Say what the shoot was.');

    $seen = helper_archive();
    if ($seen === '') bail(400, 'Setup does not know where the helper finds the archive yet.');

    $into = $seen . substr(shelf_dir(), strlen(archive_dir())) . "/$folder/{$m[1]}/{$m[1]}{$m[2]}{$m[3]} $event";
    // Which recorded day of the card goes in this folder. Usually the same as
    // the folder's date; different only when the camera's clock was wrong and
    // someone corrected the date the folder is named with.
    $day = (string)($_POST['day'] ?? '');
    if ($day !== '') {
        $onCard = [];
        foreach (helper_volumes()['vols'] as $v) if ($v['path'] === $src) $onCard = array_column($v['days'], 'day');
        if (!in_array($day, $onCard, true)) bail(400, 'That is not a day on the card.');
    }
    $line = "ingest\t$src\t$into" . ($day !== '' ? "\t$day" : '');
    if (!in_array($line, $ingests, true)) $ingests[] = $line;
    $said = ['queued' => 'ingest', 'into' => substr($into, strlen($seen) + 1)] + ($added !== '' ? ['added' => $added] : []);
} else {
    // ── whole folders: admin only ──────────────────────────────────────────
    if (!may_act((string)($_POST['pass'] ?? ''))) bail(403, 'not signed in, and no password given');

    // Paths come from the section list the helper itself wrote, so they are
    // already known-good. Checked anyway: a path is the one thing this hands
    // to another machine.
    $known = []; $sizes = [];
    foreach (@file(web_dir() . '/ingest-sections.tsv') ?: [] as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if (($f[0] ?? '') === 'section' && str_starts_with($f[1] ?? '', '/')) { $known[$f[1]] = true; $sizes[$f[1]] = [(int)($f[2] ?? 0), (int)($f[3] ?? 0)]; }
    }
    $others = [];
    foreach ((array)($_POST['copy'] ?? []) as $p) if (isset($known[$p])) $others[] = "copy\t$p";
    $list = trim((string)($_POST['list'] ?? ''));
    if ($list !== '' && str_starts_with($list, '/') && !str_contains($list, '..')) $others[] = "list\t$list";
    transfer_select(array_values(array_filter((array)($_POST['copy'] ?? []), fn($p) => isset($known[$p]))), $sizes);
    $said = ['queued' => count($others)];
}

// Cards first. Someone standing there with a card should not wait behind a
// week-long migration; whatever is already copying still finishes first.
$all = array_merge($ingests, $tidies, $others);
if (@file_put_contents("$OUT.new", $all ? implode("\n", $all) . "\n" : '') === false || !@rename("$OUT.new", $OUT))
    bail(500, "could not write $OUT — is the web folder writable?");
echo json_encode($said);
