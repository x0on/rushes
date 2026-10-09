<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// activity.php — what happened, and who did it, in plain sentences
// (HOW-IT-WORKS.md → Activity). One story for Manage → Activity and for the
// Rushes app's Activity page.
//
// Two records, read together, newest first:
//   - ingest-history.tsv: what the copier did (copied, delivered, uploaded,
//     tidied, checked …). The helper writes it and sends it whole.
//   - activity.tsv: what people did here (a pull made or downloaded, an
//     editor's computer added, a switch turned) and drives coming and going.
//     Appended, one line each: time, kind, who, what.
//
// Who: a person's name, asked once in each browser and kept there (the
// rushes_who cookie: head.php asks), or the editor's computer for a Watcher.
// ponytail: a name typed once, not an account — anyone can type any name.
// Editors' computers are the ones that need more (they are paired); personal
// sign-ins can come with Watchers on a server (ROADMAP step 4).
//
// The kinds: in (came into the archive), out (went out of it), changed (moved,
// set, added), check (copies checked), problem (something to look at), people.
//
//   GET                  {events: [{at, kind, who, text}], watchers: [...]}
//                        signed in, or the paired helper (the Rushes app on a Mac)
//   GET ?n=500           more of them (200 by default)
//   POST action=hello    a browser was given a name (head.php): said once
require_once __DIR__ . '/auth.php';

function activity_file(): string { return web_dir() . '/activity.tsv'; }

// Who is asking: an editor's computer by its paired name, or the name this browser was given.
function activity_who(): string {
    if (function_exists('watcher_me') && ($w = watcher_me())) return (string)($w['name'] ?? 'An editor\'s computer');
    // The Rushes app on the Mac it is paired with says who is signed in to that Mac
    if (($h = (string)($_SERVER['HTTP_X_RUSHES_WHO'] ?? '')) !== '' && helper_pairing() === 'this') return activity_clean(rawurldecode($h), 40);
    return activity_clean((string)($_COOKIE['rushes_who'] ?? ''), 40);
}
function activity_clean(string $s, int $max): string {
    return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $s)), 0, $max);
}
// One line, appended; a failed write loses only that line.
function activity_add(string $kind, string $text, ?string $who = null): void {
    $who ??= activity_who();
    $l = date('Y-m-d H:i:s') . "\t$kind\t" . activity_clean($who, 40) . "\t" . activity_clean($text, 300) . "\n";
    @file_put_contents(activity_file(), $l, FILE_APPEND | LOCK_EX);
    // ponytail: kept to its last 5,000 lines, trimmed now and then; a database table if it ever needs searching
    if (random_int(1, 200) === 1 && ($a = @file(activity_file())) && count($a) > 5000)
        @file_put_contents(activity_file() . '.new', implode('', array_slice($a, -5000))) !== false && @rename(activity_file() . '.new', activity_file());
}

function activity_size(int $b): string {
    return $b >= 1e12 ? number_format($b / 1e12, 2) . ' TB' : ($b >= 1e9 ? number_format($b / 1e9, 1) . ' GB'
         : ($b >= 1e6 ? number_format($b / 1e6) . ' MB' : ''));       // under a megabyte: not worth saying
}

// What the copier did, said the way a person would say it
function activity_from_history(array $f, array $editors): ?array {
    [$at, $what, $src] = [$f[0], $f[1], $f[2] ?? ''];
    $n = (int)($f[3] ?? 0); $b = (int)($f[4] ?? 0); $secs = (int)($f[5] ?? 0); $note = trim($f[6] ?? '');
    $files = number_format($n) . ($n === 1 ? ' file' : ' files') . (($s = activity_size($b)) !== '' ? " ($s)" : '');
    $name = basename(rtrim($src, '/')) ?: $src;
    $bad = (bool)preg_match('/differ|missing|could not/i', $note);
    $who = ''; $kind = 'in';
    switch ($what) {
        case 'copied':
            if ($n === 0 && $b === 0) return null;                 // nothing new on it: not an event
            $text = "Copied $files from $name into the archive" . ($note ? " · $note" : ''); $kind = $bad ? 'problem' : 'in'; break;
        case 'delivered':                                          // <the computer's key>/<batch>
            $who = $editors[explode('/', $src)[0]] ?? 'An editor\'s computer';
            $text = "Project files taken into the archive: $files" . ($note ? " · $note" : ''); break;
        case 'uploaded':
            if (preg_match('/^by (.+?) into ([^;]+)/', $note, $m)) { $who = $m[1]; $name = $m[2]; }
            $text = "Uploaded $files into $name" . (str_contains($note, ';') ? ' · ' . trim(explode(';', $note, 2)[1]) : ''); break;
        case 'tidied':   $kind = 'changed'; $text = "Tidy-up moved $files onto the shelf" . ($note ? " · $note" : ''); break;
        case 'untidied': $kind = 'changed'; $text = "A tidy-up was undone: $files put back where they were"; break;
        case 'proven':   $kind = $bad ? 'problem' : 'check'; $text = "Checked $files in $name against the originals they came from · $note"; break;
        case 'checked':  $kind = $bad ? 'problem' : 'check'; $text = "Checked $files in $name against their fingerprints · $note"; break;
        case 'counted':  $kind = 'check'; $text = 'Looked for the original of every file, where it came from'; break;
        case 'traced':   $kind = 'check'; $text = "Matched earlier copies in $name to their originals: $files"; break;
        case 'analysed': $kind = 'changed'; $text = "Described $files in $name, so Search finds what is in them"; break;
        case 'interrupted': $kind = 'problem'; $text = "Copying $name stopped part-way after $files" . ($note ? ": $note" : '') . '. It carries on from there.'; break;
        case 'dropped':  $kind = 'problem'; $text = "$name dropped off the network" . ($note ? " ($note)" : '') . ', back after ' . max(1, round($secs / 60)) . ' min'; break;
        case 'refused':  $kind = 'problem'; $text = "Did not do $src" . ($note ? ": $note" : ''); break;
        default: return null;                                      // looked (a plan only) and anything newer: not said
    }
    return ['at' => strlen($at) === 16 ? "$at:00" : $at, 'kind' => $kind, 'who' => $who, 'text' => $text];
}

function activity_list(int $n = 200): array {
    $editors = [];
    foreach (watchers() as $k => $w) $editors[substr((string)$k, 0, 16)] = (string)($w['name'] ?? '');
    $ev = [];
    foreach (array_slice(@file(web_dir() . '/ingest-history.tsv', FILE_IGNORE_NEW_LINES) ?: [], -$n * 2) as $l)
        if (($e = activity_from_history(explode("\t", $l), $editors))) $ev[] = $e;
    foreach (array_slice(@file(activity_file(), FILE_IGNORE_NEW_LINES) ?: [], -$n) as $l) {
        $f = explode("\t", $l, 4);
        if (count($f) === 4) $ev[] = ['at' => $f[0], 'kind' => $f[1], 'who' => $f[2], 'text' => $f[3]];
    }
    usort($ev, fn($a, $b) => strcmp($b['at'], $a['at']));
    return array_slice($ev, 0, $n);
}

// The editors' computers, as they last said what they were doing (watcher.php → report)
function activity_watchers(): array {
    $out = [];
    foreach (watchers() as $k => $w) {
        $r = [];
        foreach (@file(web_dir() . '/watchers/' . substr((string)$k, 0, 16) . '.txt', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if ($l === '--') break;
            [$a, $v] = array_pad(explode("\t", $l, 2), 2, ''); $r[$a] = $v;
        }
        $out[] = ['name' => (string)($w['name'] ?? ''), 'host' => (string)($w['host'] ?? ''), 'added' => (int)($w['at'] ?? 0),
                  'seen' => (int)($r['at'] ?? 0), 'state' => (string)($r['state'] ?? ''), 'ver' => (string)($r['ver'] ?? '')];
    }
    return $out;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $say = function (int $code, array $a) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
    if (($_POST['action'] ?? '') === 'hello') {
        // A browser given a name: said once, so Activity shows who started using Rushes where
        $name = activity_clean((string)($_POST['name'] ?? ''), 40);
        if ($name === '') $say(400, ['error' => 'Type a name.']);
        $was = activity_clean((string)($_COOKIE['rushes_who'] ?? ''), 40);
        if ($was !== $name) activity_add('people', $was === '' ? 'Started using Rushes on ' . activity_device() : "Changed the name on this " . activity_device(true) . " (it was $was)", $name);
        $say(200, ['ok' => true]);
    }
    if (!signed_in() && helper_pairing() !== 'this') $say(403, ['error' => 'sign in first']);
    $say(200, ['events' => activity_list(max(1, min(2000, (int)($_GET['n'] ?? 200)))), 'watchers' => activity_watchers()]);
}

// "a phone", "a Mac" …: what the browser says it is, roughly, never more
function activity_device(bool $short = false): string {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $d = preg_match('/iPhone|Android.+Mobile/', $ua) ? 'phone' : (preg_match('/iPad|Android/', $ua) ? 'tablet'
       : (str_contains($ua, 'Mac') ? 'Mac' : (str_contains($ua, 'Windows') ? 'Windows computer' : 'computer')));
    return $short ? $d : "a $d";
}
