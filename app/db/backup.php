<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// backup.php — Manage → Copying, "Back up": the archive copied onto another drive, once or
// every night (HOW-IT-WORKS.md → Backup). The copy itself is the helper's (ingest.py --backup):
// it only adds and checks, never replaces a different file and never deletes, and lands outside
// the archive and outside search. This file keeps the choice, says how the last run went, and
// hands the helper the run when one is due (backup_line(), read by db/helper.php?queue).
//
//   GET                                           {backup, drives, last, runs, due, archive}
//   POST action=save drive=<path> folder=<name> nightly=0|1    (signed in)
//   POST action=now                                            a run now, besides the nightly one
//   POST action=off                                            no backup (nothing on the drive changes)
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';

const BACKUP_NIGHT = [22, 7];       // ponytail: 10 pm to 7 am, as describing; a setting if anyone needs other hours

function backup_set(): array { return settings()['backup'] ?? []; }

// Where on the backup drive: <drive>/<folder>, as the helper sees it
function backup_into(array $b): string { return rtrim($b['drive'] ?? '', '/') . '/' . ($b['folder'] ?? 'Rushes backup'); }

// The helper's runs of it, newest first: [when, kind, stamp, into, files, bytes, note]
function backup_runs(int $n = 8): array {
    $out = [];
    foreach (array_reverse(@file(web_dir() . '/ingest-history.tsv') ?: []) as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if (count($f) < 5 || !str_starts_with($f[1], 'backup') && $f[1] !== 'backed-up') continue;
        [$stamp, $into] = array_pad(explode(' ', $f[2], 2), 2, '');
        $out[] = ['when' => $f[0], 'kind' => $f[1], 'stamp' => $stamp, 'into' => $into, 'files' => (int)$f[3],
                  'bytes' => (int)$f[4], 'note' => $f[6] ?? ''];
        if (count($out) >= $n) break;
    }
    return $out;
}

// The run due now, if any: one asked with Back up now, or tonight's. -> its stamp, or ''
function backup_due(?int $now = null): string {
    $b = backup_set(); $now ??= time();
    if (($b['drive'] ?? '') === '') return '';
    $done = [];
    foreach (backup_runs(200) as $r) if ($r['kind'] === 'backed-up') $done[$r['stamp']] = true;
    if (($b['asked'] ?? '') !== '' && !isset($done[$b['asked']])) return $b['asked'];
    if (empty($b['nightly'])) return '';
    $h = (int)date('G', $now);
    if ($h >= BACKUP_NIGHT[1] && $h < BACKUP_NIGHT[0]) return '';
    $night = 'night-' . date('Y-m-d', $h < BACKUP_NIGHT[1] ? $now - 86400 : $now);   // the evening it started
    return isset($done[$night]) ? '' : $night;
}

// For the helper's queue: "backup <archive> <into> <stamp>", or ''
function backup_line(): string {
    $s = backup_due(); $from = helper_archive();
    return $s !== '' && $from !== '' ? "backup\t$from\t" . backup_into(backup_set()) . "\t$s\n" : '';
}

// The Overview tile and the line on Copying: when the last good backup was, plainly
function backup_said(): ?array {
    $b = backup_set();
    if (($b['drive'] ?? '') === '') return null;
    $last = null;
    foreach (backup_runs(200) as $r) if ($r['kind'] === 'backed-up') { $last = $r; break; }
    $at = $last ? (int)strtotime($last['when']) : 0;
    $days = $at ? (int)floor((time() - $at) / 86400) : null;
    // late: a nightly backup with no good run for two days (or none since it was set, a day and a half ago)
    $late = !empty($b['nightly']) && ($days !== null ? $days >= 2 : time() - (int)($b['since'] ?? 0) > 36 * 3600);
    return ['at' => $at, 'days' => $days, 'late' => $late, 'nightly' => !empty($b['nightly']),
            'drive' => basename($b['drive']), 'note' => $last['note'] ?? '', 'files' => $last['files'] ?? 0, 'bytes' => $last['bytes'] ?? 0];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;   // included: the functions only

header('Content-Type: application/json');
header('Cache-Control: no-store');
function bk_said(int $code, array $a) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
if (!signed_in()) bk_said(403, ['error' => 'sign in first']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $s = settings(); $b = $s['backup'] ?? []; $do = (string)($_POST['action'] ?? '');
    if ($do === 'save') {
        $drive = (string)($_POST['drive'] ?? '');
        $seen = array_column(helper_volumes()['vols'], null, 'path');
        // Only a drive the helper reported, never a path typed in a browser; never the archive's own
        if (!isset($seen[$drive])) bk_said(400, ['error' => 'Choose a drive the helper can see right now.']);
        if ($seen[$drive]['archive'] || str_starts_with(helper_archive() . '/', rtrim($drive, '/') . '/'))
            bk_said(400, ['error' => 'The archive is on that drive: a backup goes on another one.']);
        $folder = trim(preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/', ' ', (string)($_POST['folder'] ?? '')));
        $folder = ltrim(mb_substr($folder, 0, 80), '. ');
        if ($folder === '') $folder = 'Rushes backup';
        $b = ['drive' => $drive, 'folder' => $folder, 'nightly' => ($_POST['nightly'] ?? '') === '1', 'since' => (int)($b['since'] ?? time())]
           + array_intersect_key($b, ['asked' => 1]);
        $said = 'Backup set: the archive onto ' . $seen[$drive]['name'] . ' / ' . $folder . ($b['nightly'] ? ', every night' : ', when asked');
    } elseif ($do === 'now') {
        if (($b['drive'] ?? '') === '') bk_said(400, ['error' => 'Choose where the backup goes first.']);
        $b['asked'] = 'now-' . date('Ymd-His');
        $said = 'Asked for a backup now, onto ' . basename($b['drive']);
    } elseif ($do === 'off') {
        $b = []; $said = 'Backup turned off (nothing on the backup drive changes)';
    } else bk_said(400, ['error' => 'unknown action']);
    $s['backup'] = $b;
    if (!save_settings($s)) bk_said(500, ['error' => 'Could not save — is the web folder writable?']);
    settings(true);
    activity_add('changed', $said);
    bk_said(200, ['ok' => true, 'said' => $said]);
}

$hv = helper_volumes();
bk_said(200, [
    'backup' => backup_set() ?: null, 'into' => backup_set() ? backup_into(backup_set()) : '',
    'said' => backup_said(), 'runs' => backup_runs(), 'due' => backup_due(),
    'archive' => helper_archive(), 'helper_fresh' => $hv['fresh'],
    'drives' => array_values(array_map(fn($v) => ['path' => $v['path'], 'name' => $v['name'], 'free' => $v['free'], 'total' => $v['total']],
                  array_filter($hv['vols'], fn($v) => !$v['card'] && !$v['archive'] && rtrim($v['path'], '/') !== helper_archive()))),
    'night' => BACKUP_NIGHT,
    // what can be brought in (Setup → Where footage comes from); with drives kept where they are, nothing is
    'sources' => array_map(fn($r) => ['label' => $r['label'] ?? basename($r['path']), 'path' => $r['path']], settings()['sources'] ?? []),
    'in_place' => (settings()['organise']['shape'] ?? '') === 'in_place',
    'archive_name' => settings()['archive']['label'] ?? basename(archive_dir()),
]);
