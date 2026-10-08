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
//   POST action=save from=<drive> drive=… folder=… nightly=…   a copy from one drive onto another (copies[])
//   POST action=now [id=]                                      a run now, besides the nightly one
//   POST action=off [id=]                                      no more runs (nothing on any drive changes)
//   POST action=source drive=<path>                            that drive becomes a source (as Setup 03 would): Copy once, Add to Rushes
//   POST action=unsource drive=<path>                          Overview → Remove from Rushes (nothing on it changes)
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';

const BACKUP_NIGHT = [22, 7];       // ponytail: 10 pm to 7 am, as describing; a setting if anyone needs other hours

function backup_set(): array { return settings()['backup'] ?? []; }

// The copies from one drive onto another (Copy onto another drive): each its own id, from, drive, folder, when
function drive_copies(): array { return array_values(array_filter(settings()['copies'] ?? [], fn($c) => ($c['drive'] ?? '') !== '' && ($c['from'] ?? '') !== '')); }

// Every job: the archive's backup (id "archive", from the archive) and each drive copy
function backup_jobs(): array {
    $b = backup_set();
    return array_merge(($b['drive'] ?? '') !== '' ? [['id' => 'archive', 'from' => helper_archive()] + $b] : [], drive_copies());
}

// Where on the other drive: <drive>/<folder>, as the helper sees it
function backup_into(array $b): string { return rtrim($b['drive'] ?? '', '/') . '/' . ($b['folder'] ?? 'Rushes backup'); }

// The helper's runs, newest first: [when, kind, stamp, into, files, bytes, note]; one job's when $into is given
function backup_runs(int $n = 8, ?string $into = null): array {
    $out = [];
    foreach (array_reverse(@file(web_dir() . '/ingest-history.tsv') ?: []) as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if (count($f) < 5 || !str_starts_with($f[1], 'backup') && $f[1] !== 'backed-up') continue;
        [$stamp, $at] = array_pad(explode(' ', $f[2], 2), 2, '');
        if ($into !== null && $at !== $into) continue;
        $out[] = ['when' => $f[0], 'kind' => $f[1], 'stamp' => $stamp, 'into' => $at, 'files' => (int)$f[3],
                  'bytes' => (int)$f[4], 'note' => $f[6] ?? ''];
        if (count($out) >= $n) break;
    }
    return $out;
}

// The run of one job due now, if any: one asked for, or tonight's. -> its stamp, or ''
// A drive copy's stamps end in ~<id>, so two jobs on the same night are never taken for one (the helper's done list).
function backup_due(?int $now = null, ?array $job = null): string {
    $b = $job ?? (backup_set() ? ['id' => 'archive'] + backup_set() : []); $now ??= time();
    if (($b['drive'] ?? '') === '') return '';
    $done = [];
    foreach (backup_runs(400, backup_into($b)) as $r) if ($r['kind'] === 'backed-up') $done[$r['stamp']] = true;
    if (($b['asked'] ?? '') !== '' && !isset($done[$b['asked']])) return $b['asked'];
    if (empty($b['nightly'])) return '';
    $h = (int)date('G', $now);
    if ($h >= BACKUP_NIGHT[1] && $h < BACKUP_NIGHT[0]) return '';
    $night = 'night-' . date('Y-m-d', $h < BACKUP_NIGHT[1] ? strtotime('-1 day', $now) : $now)   // the evening it started
           . (($b['id'] ?? 'archive') === 'archive' ? '' : '~' . $b['id']);
    return isset($done[$night]) ? '' : $night;
}

// For the helper's queue: one "backup <from> <into> <stamp>" line per job due, or ''
function backup_line(): string {
    $out = '';
    foreach (backup_jobs() as $j) {
        $s = backup_due(null, $j);
        if ($s !== '' && ($j['from'] ?? '') !== '') $out .= "backup\t{$j['from']}\t" . backup_into($j) . "\t$s\n";
    }
    return $out;
}

// When the last good run was, plainly: the archive's backup (the Overview tile), or one drive copy
function backup_said(?array $job = null): ?array {
    $b = $job ?? (backup_set() ? ['id' => 'archive'] + backup_set() : []);
    if (($b['drive'] ?? '') === '') return null;
    $last = null;
    foreach (backup_runs(400, backup_into($b)) as $r) if ($r['kind'] === 'backed-up') { $last = $r; break; }
    $at = $last ? (int)strtotime($last['when']) : 0;
    $days = $at ? (int)floor((time() - $at) / 86400) : null;
    // late: a nightly one with no good run for two days (or none since it was set, a day and a half ago)
    $late = !empty($b['nightly']) && ($days !== null ? $days >= 2 : time() - (int)($b['since'] ?? 0) > 36 * 3600);
    return ['at' => $at, 'days' => $days, 'late' => $late, 'nightly' => !empty($b['nightly']),
            'drive' => basename($b['drive']), 'note' => $last['note'] ?? '', 'files' => $last['files'] ?? 0, 'bytes' => $last['bytes'] ?? 0];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;   // included: the functions only

header('Content-Type: application/json');
header('Cache-Control: no-store');
function bk_said(int $code, array $a) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
if (!signed_in()) bk_said(403, ['error' => 'sign in first']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'source') {
    // Copy once, From a drive picked on Copying: added to the sources here, so nobody has to go to Setup.
    // Only a drive the helper reported (never a path typed in a browser), never a card (Ingest) or the archive.
    $drive = (string)($_POST['drive'] ?? '');
    $v = array_column(helper_volumes()['vols'], null, 'path')[$drive] ?? null;
    if (!$v) bk_said(400, ['error' => 'That drive is not one the helper can see right now.']);
    if ($v['card']) bk_said(400, ['error' => 'That is a card: cards come in through Ingest.']);
    if ($v['archive'] || str_starts_with(helper_archive() . '/', rtrim($drive, '/') . '/')) bk_said(400, ['error' => 'That is the archive itself.']);
    $s = settings();
    if (in_array($drive, array_column($s['sources'] ?? [], 'path'), true)) bk_said(200, ['ok' => true, 'said' => '']);
    $s['sources'][] = ['label' => $v['name'], 'path' => $drive, 'seen_by' => 'helper'];
    if (!save_settings($s)) bk_said(500, ['error' => 'Could not save — is the web folder writable?']);
    settings(true);
    $said = ($s['organise']['shape'] ?? '') === 'in_place'
        ? 'Added ' . $v['name'] . ' to Rushes: its files are listed now, then found in Search by name'
        : 'Added ' . $v['name'] . ' as a drive to copy from';
    activity_add('changed', $said);
    bk_said(200, ['ok' => true, 'said' => $said]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unsource') {
    // Overview → Drives: Remove from Rushes. Only takes it off the list (its files leave Search at the next
    // listing, asked for now); nothing on the drive is touched. Never the archive.
    $drive = rtrim((string)($_POST['drive'] ?? ''), '/');
    $s = settings(); $kept = []; $gone = null;
    foreach ($s['sources'] ?? [] as $r) { if (rtrim((string)($r['path'] ?? ''), '/') === $drive) $gone = $r; else $kept[] = $r; }
    if (!$gone) bk_said(400, ['error' => 'That drive is not in Rushes.']);
    $s['sources'] = $kept;
    if (!save_settings($s)) bk_said(500, ['error' => 'Could not save — is the web folder writable?']);
    settings(true);
    @file_put_contents(web_dir() . '/queue/' . date('Ymd-His') . '-unsource.job', "ACTION=reindex\n");
    $name = $gone['label'] ?? basename($drive);
    activity_add('changed', "Took $name out of Rushes: its files leave Search; nothing on it was changed");
    bk_said(200, ['ok' => true, 'said' => "$name taken out of Rushes"]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // One job at a time: the archive's backup (id "archive", or no id), or a copy from one drive onto another
    $s = settings(); $do = (string)($_POST['action'] ?? ''); $seen = array_column(helper_volumes()['vols'], null, 'path');
    $id = (string)($_POST['id'] ?? ''); $from = (string)($_POST['from'] ?? '');
    $onArchive = fn($p) => str_starts_with(helper_archive() . '/', rtrim($p, '/') . '/') || str_starts_with(rtrim($p, '/') . '/', helper_archive() . '/');
    if ($do === 'save') {
        $drive = (string)($_POST['drive'] ?? '');
        // Only drives the helper reported, never a path typed in a browser; never onto the archive's own drive
        if (!isset($seen[$drive])) bk_said(400, ['error' => 'Choose a drive the helper can see right now.']);
        if ($seen[$drive]['archive'] || $onArchive($drive))
            bk_said(400, ['error' => 'The archive is on that drive: copies go on another one.']);
        if ($from !== '') {
            if (!isset($seen[$from]) || $seen[$from]['card']) bk_said(400, ['error' => 'Copy from a drive the helper can see right now (a card comes in through Ingest).']);
            if ($from === $drive) bk_said(400, ['error' => 'From and To are the same drive.']);
            if ($seen[$from]['archive'] || $onArchive($from)) $from = '';       // the archive: that is the backup
        }
        $folder = trim(preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/', ' ', (string)($_POST['folder'] ?? '')));
        $folder = ltrim(mb_substr($folder, 0, 80), '. ');
        if ($folder === '') $folder = $from === '' ? 'Rushes backup' : $seen[$from]['name'] . ' copy';
        $nightly = ($_POST['nightly'] ?? '') === '1';
        if ($from === '') {
            $b = $s['backup'] ?? [];
            $s['backup'] = ['drive' => $drive, 'folder' => $folder, 'nightly' => $nightly, 'since' => (int)($b['since'] ?? time())]
                         + array_intersect_key($b, ['asked' => 1]);
            $said = 'Backup set: the archive onto ' . $seen[$drive]['name'] . ' / ' . $folder . ($nightly ? ', every night' : ', when asked');
        } else {
            // a drive onto another: one job per pair; "once" runs now, "every night" from tonight
            $id = substr(md5("$from|$drive"), 0, 6); $kept = [];
            foreach ($s['copies'] ?? [] as $c) if (($c['id'] ?? '') !== $id) $kept[] = $c;
            $kept[] = ['id' => $id, 'from' => $from, 'from_name' => $seen[$from]['name'], 'drive' => $drive, 'folder' => $folder,
                       'nightly' => $nightly, 'since' => time()] + ($nightly ? [] : ['asked' => 'now-' . date('Ymd-His') . "~$id"]);
            $s['copies'] = $kept;
            $said = 'Copy set: ' . $seen[$from]['name'] . ' onto ' . $seen[$drive]['name'] . ' / ' . $folder . ($nightly ? ', every night' : ', now');
        }
    } elseif ($do === 'now' || $do === 'off') {
        if ($id === '' || $id === 'archive') {
            if (($s['backup']['drive'] ?? '') === '') bk_said(400, ['error' => 'Choose where the backup goes first.']);
            $name = basename($s['backup']['drive']);
            if ($do === 'now') { $s['backup']['asked'] = 'now-' . date('Ymd-His'); $said = "Asked for a backup now, onto $name"; }
            else { $s['backup'] = []; $said = 'Backup turned off (nothing on the backup drive changes)'; }
        } else {
            $i = array_search($id, array_column($s['copies'] ?? [], 'id'), true);
            if ($i === false) bk_said(400, ['error' => 'That copy is not on the list any more.']);
            $c = $s['copies'][$i];
            if ($do === 'now') { $s['copies'][$i]['asked'] = 'now-' . date('Ymd-His') . "~$id"; $said = "Asked to copy {$c['from_name']} onto " . basename($c['drive']) . ' now'; }
            else { array_splice($s['copies'], $i, 1); $said = "Copy of {$c['from_name']} onto " . basename($c['drive']) . ' taken off the list (nothing on either drive changes)'; }
        }
    } else bk_said(400, ['error' => 'unknown action']);
    if (!save_settings($s)) bk_said(500, ['error' => 'Could not save — is the web folder writable?']);
    settings(true);
    activity_add('changed', $said);
    bk_said(200, ['ok' => true, 'said' => $said]);
}

$hv = helper_volumes();
bk_said(200, [
    'backup' => backup_set() ?: null, 'into' => backup_set() ? backup_into(backup_set()) : '',
    'said' => backup_said(), 'runs' => backup_set() ? backup_runs(8, backup_into(backup_set())) : [], 'due' => backup_due(),
    // copies from one drive onto another, each with how it went
    'copies' => array_map(fn($c) => $c + ['into' => backup_into($c), 'said' => backup_said($c), 'runs' => backup_runs(8, backup_into($c)),
                                           'due' => backup_due(null, $c)], drive_copies()),
    'archive' => helper_archive(), 'helper_fresh' => $hv['fresh'],
    // every drive and share the helper sees, but the archive's own: To for the backup, From for Copy once (cards greyed there)
    'drives' => array_values(array_map(fn($v) => ['path' => $v['path'], 'name' => $v['name'], 'free' => $v['free'], 'total' => $v['total']],
                  array_filter($hv['vols'], fn($v) => !$v['card'] && !$v['archive'] && !str_starts_with(helper_archive() . '/', rtrim($v['path'], '/') . '/')))),
    'cards' => array_values(array_map(fn($v) => ['path' => $v['path'], 'name' => $v['name']], array_filter($hv['vols'], fn($v) => $v['card']))),
    'night' => BACKUP_NIGHT,
    // what can be brought in (Setup → Where footage comes from); with drives kept where they are, nothing is
    'sources' => array_map(fn($r) => ['label' => $r['label'] ?? basename($r['path']), 'path' => $r['path']], settings()['sources'] ?? []),
    'in_place' => (settings()['organise']['shape'] ?? '') === 'in_place',
    'archive_name' => settings()['archive']['label'] ?? basename(archive_dir()),
    'archive_free' => (int)(@disk_free_space(archive_dir()) ?: 0),
]);
