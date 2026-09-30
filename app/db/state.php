<?php
// state.php — everything the admin page needs, in one answer.
//
// The old page made you go and look: seven panels, each holding one feature,
// none of them saying whether you needed it. This works out what is TRUE about
// the archive and returns the conditions that want attention. The page then
// has nothing to decide — it renders what this says.
//
// A condition appears only when it applies. No conditions is itself an answer.

require_once __DIR__ . '/transfers.php';
db_init();
$transfer = transfer_summary(transfer_current());
// An unfinished transfer tells its own story (its card says blocked, stopped,
// interrupted); a finished one must not hide what happens after it.
$transferOpen = $transfer && $transfer['phase'] !== 'done';
header('Content-Type: application/json');

$WEB = web_dir();
$now = time();
$c = [];        // conditions
$mtime = fn(string $f) => file_exists("$WEB/$f") ? filemtime("$WEB/$f") : 0;
$ago = function (int $t) use ($now): string {
    if (!$t) return 'never';
    $s = $now - $t;
    if ($s < 90)     return 'just now';
    if ($s < 5400)   return round($s / 60) . ' minutes ago';
    if ($s < 172800) return round($s / 3600) . ' hours ago';
    return round($s / 86400) . ' days ago';
};

// ── the archive, in a line ────────────────────────────────────────────────
$files = (int)meta_get('files_imported', '0');
$imported = (int)meta_get('imported_at', '0');
$bytes = $files ? (int)db()->querySingle('SELECT COALESCE(SUM(bytes),0) FROM files') : 0;

$disk = ['used' => 0, 'free' => 0, 'pct' => 0];
if (is_readable("$WEB/disk.txt")) {
    $f = preg_split('/\s+/', trim(file_get_contents("$WEB/disk.txt")));
    if (count($f) >= 5) $disk = ['used' => (int)$f[2] * 1024, 'free' => (int)$f[3] * 1024,
                                 'pct' => (int)$f[4]];
}

// ── is anything running? ──────────────────────────────────────────────────
$status = is_readable("$WEB/job-status.txt") ? trim(file_get_contents("$WEB/job-status.txt")) : '';
$running = ($status && $status !== 'idle') ? trim(str_replace('running:', '', $status)) : null;
$alive = (int)@file_get_contents("$WEB/runner-alive.txt");
$runner_ok = $alive && ($now - $alive) < limit('runner_silent_seconds', 180);

// progress, if the running job reports any
$progress = null;
if ($running && is_readable("$WEB/job.log")) {
    $tail = @file_get_contents("$WEB/job.log", false, null, max(0, filesize("$WEB/job.log") - 8000));
    if (preg_match_all('/progress: (\d+) of (\d+) \((\d+)%\)/', $tail, $m))
        $progress = ['done' => (int)end($m[1]), 'total' => (int)end($m[2]), 'pct' => (int)end($m[3])];
}

$mac = [];
foreach (@file("$WEB/ingest-status.tsv") ?: [] as $l) {
    $f = explode("\t", rtrim($l, "\n"));
    if (count($f) >= 2) $mac[$f[0]] = $f[1];
}
// 'ts' is seconds, and means the same everywhere. 'at' is the helper's own
// wall-clock time, which this machine reads in ITS time zone — twelve hours
// out on a NAS left on factory settings. Only helpers from before 'ts' lack it.
$mac_at   = isset($mac['ts']) ? (int)$mac['ts'] : (isset($mac['at']) ? strtotime($mac['at']) : 0);
$mac_stale = $mac_at && ($now - $mac_at) > limit('copy_stalled_seconds', 900);

// ── the conditions ────────────────────────────────────────────────────────
// Each is: is it true, what does it mean in plain words, what is the one
// button. Ordered by how much it matters, not by which panel it came from.

// The source going away is the failure that cost a whole weekend in September:
// the Mac kept saying "not mounted, skipping" into a terminal nobody was
// watching, and this page sat there looking content.
if (!$transferOpen && ($mac['phase'] ?? '') === 'blocked') {
    // a tile: the headline number, and the tile itself is the button
    $c[] = ['level' => 'bad',
        'tile' => ['lab' => 'Copying stopped', 'big' => 'Source gone',
                   'sub' => 'waiting for it to come back'],
        'title' => 'The helper machine cannot see the footage any more',
        'body' => 'Copying stopped ' . $ago($mac_at) . '. ' . ($mac['note'] ?? '')
                . ' Re-mount it and copying carries on by itself. '
                . 'Nothing is lost — a part-copied folder picks up where it stopped.',
        'act' => null];
} elseif (!$transferOpen && ($mac['phase'] ?? '') === 'copying' && $mac_stale) {
    $done_n = (int)($mac['copied'] ?? 0); $of = (int)($mac['of'] ?? 0);
    $c[] = ['level' => 'bad',
        'tile' => ['lab' => 'Needs you', 'big' => 'A copy stopped',
                   'sub' => basename($mac['source'] ?? 'a folder')],
        'title' => 'A copy stopped part-way',
        'body' => basename($mac['source'] ?? 'A folder') . ' reached '
                . number_format($done_n) . ($of ? ' of ' . number_format($of) : '')
                . ' files, then went quiet ' . $ago($mac_at) . '. Those files are on the archive, '
                . 'but the folder was never marked done. Queue it again — everything already '
                . 'here is skipped, so only the remainder copies.',
        'act' => null, 'help' => 'Is the watcher still running on the Mac?'];
}

if (!$runner_ok) {
    $c[] = ['level' => 'bad', 'title' => 'The NAS is not picking up jobs',
        'body' => 'Nothing queued here will run. The scheduled task that does the work has been silent for '
                  . ($alive ? round(($now - $alive) / 60) . ' minutes' : 'as long as this page can tell') . '.',
        'act' => null, 'help' => 'Check it with: crontab -l | grep runner'];
}

// Two lines, both in free space rather than percent, because free space is what
// actually runs out. 5 TB is where ingest.py stops copying (FLOOR_GB); 8 TB is
// far enough above it to give about a day's warning at the speed this link runs.
$FLOOR = limit('disk_stop_free');
$WARN  = limit('disk_warn_free');
if ($disk['free'] && $disk['free'] < $FLOOR) {
    $c[] = ['level' => 'bad', 'title' => 'The archive is full enough to stop copying',
        'body' => sprintf('%s free, %d%% used. Bringing footage over has stopped by itself '
                        . 'and will start again once there is room. Nothing was lost.',
                  tb($disk['free']), $disk['pct']), 'act' => null];
} elseif ($disk['free'] && $disk['free'] < $WARN) {
    $c[] = ['level' => 'warn', 'title' => 'Running low on space',
        'body' => sprintf('%s free, %d%% used. Copying stops on its own at %s free, '
                        . 'which at this link speed is about a day away.',
                  tb($disk['free']), $disk['pct'], tb($FLOOR)), 'act' => null];
}

// Routine indexing belongs to the runner, not to the person using Search.
$sync = meta_get('search_sync_state', 'current');
$manifest = $mtime('manifest.tsv');
$pendingSearch = $manifest > (int)meta_get('source_written', '0');
$searchAt = max($imported, (int)meta_get('search_updated_at', '0'));
if ($sync === 'retrying' && (int)meta_get('search_sync_failures', '0') >= 3) {
    $c[] = ['level' => 'warn', 'title' => 'Search needs a check',
        'body' => 'Several automatic updates could not finish. Existing results are still available. Check the job log in Activity; automatic retries will continue.',
        'act' => null];
} elseif ($sync === 'retrying') {
    $c[] = ['level' => 'info', 'title' => 'Search will update again automatically',
        'body' => 'The last update could not finish. Your existing search results are still available; Rushes will retry.',
        'act' => null];
} elseif ($pendingSearch) {
    $c[] = ['level' => 'info', 'title' => $sync === 'updating' ? 'Updating search' : 'Search update queued',
        'body' => 'Rushes is catching up with the latest file information. You can keep using the archive.',
        'act' => null];
} elseif (!$files) {
    $c[] = ['level' => 'info', 'title' => 'Ready to index your archive',
        'body' => 'Build the first file list to get started. After that, Rushes keeps search updated automatically.',
        'act' => ['manifest', 'Build the first file list']];
}

// regenerable junk sitting in the archive
$sweep = array_map('cache_sql', cache_groups('sweep'));
$junk = $sweep ? db()->querySingle("SELECT COUNT(*) n, COALESCE(SUM(bytes),0) b FROM files
    WHERE " . implode(' OR ', $sweep), true) : ['n' => 0, 'b' => 0];
if ($junk && $junk['n'] > limit('cache_min_files', 100)) {
    $c[] = ['level' => 'info',
        'tile' => ['lab' => 'Rebuildable cache', 'big' => tb($junk['b']),
                   'sub' => number_format($junk['n']) . ' files'],
        'title' => number_format($junk['n']) . ' cache files are taking up ' . tb($junk['b']),
        'body' => 'Premiere and Capture One scratch files, sitting in the archive instead of on an editing machine. '
                . 'They rebuild themselves from the originals, so nothing is lost by removing them.',
        'act' => ['cachejunk', 'Move them out']];
}

// the holding folder
$hold = (int)@file_get_contents("$WEB/holding-kb.txt") * 1024;
if ($hold > limit('holding_min_bytes', 1073741824)) {
    $verdict = null;
    if (is_readable("$WEB/verify-result.tsv"))
        foreach (file("$WEB/verify-result.tsv") as $l)
            if (str_starts_with($l, 'VERDICT')) $verdict = trim(explode("\t", $l)[1] ?? '');
    $c[] = ['level' => $verdict === 'SAFE' ? 'good' : 'info',
        'title' => tb($hold) . ' is waiting in the holding folder',
        'body' => $verdict === 'SAFE'
            ? 'Checked: every file in there has a surviving twin on the archive. Deleting it is how you get the space back.'
            : 'Files moved aside by the cleanup. Check them before deleting — the space only comes back once they are gone.',
        'act' => ['verify', $verdict === 'SAFE' ? 'Check again' : 'Check it is safe']];
}

// bringing footage in
$secs = []; $done = 0; $left_b = 0;
if (is_readable("$WEB/ingest-sections.tsv")) {
    $hist = [];
    if (is_readable("$WEB/ingest-history.tsv"))
        foreach (file("$WEB/ingest-history.tsv") as $l) {
            $f = explode("\t", rtrim($l, "\n"));
            if (count($f) >= 5) $hist[$f[2]] = $f[1];
        }
    foreach (file("$WEB/ingest-sections.tsv") as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if (($f[0] ?? '') !== 'section' || !str_starts_with($f[1] ?? '', '/')) continue;
        $secs[] = $f[1];
        if (($hist[$f[1]] ?? '') === 'copied' || ($f[4] ?? '') === 'done') $done++;
        else $left_b += (int)($f[3] ?? 0);
    }
    if (!$transferOpen && $secs && $done < count($secs))
        $c[] = ['level' => 'info',
            'tile' => ['lab' => 'Still to bring over', 'big' => (string)(count($secs) - $done),
                       'sub' => 'folders · ' . tb($left_b)],
            'act' => ['#transfers', 'Pick folders'],
            'title' => 'Moving the old server over: ' . $done . ' of ' . count($secs) . ' folders done',
            'body' => tb($left_b) . ' of the source still to look at. Pick the folders below; '
                      . 'they copy on the helper machine, one after another, and report back here.'];
}

$queued = []; $splitting = [];
foreach (@file("$WEB/ingest-queue.tsv") ?: [] as $l) {
    $f = explode("\t", rtrim($l, "\n"));
    if (($f[0] ?? '') === 'copy')      $queued[$f[1]] = true;
    elseif (($f[0] ?? '') === 'list')  $splitting[$f[1]] = true;
}

$sections = [];
$hist2 = [];          // what has been copied, by where it came from (or went, for a card)
if (is_readable("$WEB/ingest-sections.tsv")) {
    if (is_readable("$WEB/ingest-history.tsv"))
        foreach (file("$WEB/ingest-history.tsv") as $l) {
            $f = explode("\t", rtrim($l, "\n"));
            if (count($f) >= 5 && $f[1] === 'copied') $hist2[$f[2]] = $f[0];
        }
    foreach (file("$WEB/ingest-sections.tsv") as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if (($f[0] ?? '') !== 'section' || !str_starts_with($f[1] ?? '', '/')) continue;
        $p = $f[1];
        // 'splitting' beats 'queued': if a folder is about to be broken up,
        // that is the thing you are waiting on, not the copy.
        $state = isset($hist2[$p]) || ($f[4] ?? '') === 'done' ? 'done'
               : (isset($splitting[$p]) ? 'splitting'
               : (isset($queued[$p]) ? 'queued' : 'todo'));
        $sections[] = ['path' => $p, 'name' => basename($p),
                       'where' => basename(dirname($p)),
                       'files' => (int)($f[2] ?? 0), 'bytes' => (int)($f[3] ?? 0),
                       'state' => $state, 'when' => $hist2[$p] ?? ''];
    }
}

$latestJob = transfer_latest();
$checkpoints = [];
foreach ($latestJob['items'] ?? [] as $item) $checkpoints[$item['source']] = $item;
foreach ($sections as &$section) {
    $item = $checkpoints[$section['path']] ?? null;
    if ($item && $item['phase'] !== 'removed') $section['state'] = $item['phase'] === 'done' ? 'done' : 'queued';
}
unset($section);

$paths = array_column($sections, 'path');
$sections = array_values(array_filter($sections, function ($s) use ($paths) {
    foreach ($paths as $p) if ($p !== $s['path'] && str_starts_with($p, $s['path'] . '/')) return false;
    return true;
}));

if (!$hist2 && is_readable("$WEB/ingest-history.tsv"))
    foreach (file("$WEB/ingest-history.tsv") as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if (count($f) >= 5 && $f[1] === 'copied') $hist2[$f[2]] = $f[0];
    }

// Cards asked for, and whether each has landed. A card is known by where it is
// going, because the same card path comes back every week with a new shoot.
$ingests = [];
foreach (@file("$WEB/ingest-queue.tsv") ?: [] as $l) {
    $f = explode("\t", rtrim($l, "\n"));
    if (($f[0] ?? '') !== 'ingest' || count($f) < 3) continue;
    $ingests[] = ['src' => $f[1], 'into' => $f[2], 'name' => basename($f[2]),
                  'state' => isset($hist2[$f[2]]) ? 'done' : 'queued'];
}

// what has landed, one row per folder, newest first — the right-hand column
$landed = [];
if (is_readable("$WEB/ingest-history.tsv")) {
    foreach (array_reverse(file("$WEB/ingest-history.tsv")) as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if (count($f) < 6 || $f[1] !== 'copied' || ((int)$f[3] === 0 && (int)$f[4] === 0)) continue;
        if (isset($landed[$f[2]])) continue;                 // a folder lands once
        $landed[$f[2]] = ['name' => basename($f[2]), 'path' => $f[2],
                          'files' => (int)$f[3], 'bytes' => (int)$f[4], 'when' => $f[0]];
    }
}
foreach ($checkpoints as $path => $item) {
    if ($item['phase'] !== 'done') continue;
    $landed[$path] = ['name' => basename($path), 'path' => $path,
        'files' => (int)$item['total_files'], 'bytes' => (int)$item['total_bytes'],
        'when' => date('Y-m-d H:i', (int)$item['updated'])];
}
$landed = array_values($landed);

// where it is coming from and going to. Derived, not configured — the day this
// becomes an app for other people, these two lines are what turn into settings.
$from = $secs ? explode('/', ltrim(dirname($secs[0]), '/'))[1] ?? '' : '';
$to   = settings()['archive']['label'] ?? basename(archive_dir());

// The helper stopped while there is work for it. A background helper comes
// back by itself; one in a Terminal window needs starting again.
// Alive if either of its two reports is recent: the drive report, or its live
// status (a drive that stops answering can hold up the first while copying goes on).
$hvNow = helper_volumes();
if ($transferOpen && !$hvNow['fresh'] && !($mac && !$mac_stale)) {
    $c[] = ['level' => 'bad', 'title' => 'The helper is not running',
        'body' => ($hvNow['how'] === 'service'
            ? 'It runs in the background on ' . helper_name() . ' and restarts by itself within a minute. If it does not come back: is that computer on, awake and logged in?'
            : 'The transfer waits until it is started again: Setup → 04 Helper.')
            . ($hvNow['at'] ? ' Last heard from ' . $ago($hvNow['at']) . '.' : ''),
        'act' => null];
}
// Updated scripts wait for a yes: they run with full rights on this machine.
// Once you said yes, it says what happens next instead of asking again.
if ($sw = scripts_waiting()) {
    $names = implode(', ', array_column($sw, 'name'));
    $asked = glob("$WEB/queue/*-scripts.job") ?: [];
    if ($asked) {
        $c[] = ['level' => 'warn', 'title' => 'Installing ' . $names . ' — you said yes ' . $ago(max(array_map('filemtime', $asked))),
            'body' => !$runner_ok ? 'But the runner has not checked in' . ($alive ? ' since ' . $ago($alive) : '') . ', so nothing is being installed. Is its cron entry there?'
                    : ($running ? "The runner is busy with “{$running}” and installs them as soon as that finishes. Nothing to press."
                                : 'The runner installs them at its next turn, within a minute. Nothing to press.'),
            'act' => null];
    } else {
        // the last time it was asked and said no, and why
        $tail = @file_get_contents("$WEB/job.log", false, null, max(0, (int)@filesize("$WEB/job.log") - 4000)) ?: '';
        $why = preg_match_all('/^  refused .*$/m', $tail, $m) ? ' Last try: ' . trim(end($m[0])) . '.' : '';
        $c[] = ['level' => 'warn', 'title' => count($sw) . ' updated script' . (count($sw) > 1 ? 's are' : ' is') . ' waiting to be installed',
            'body' => $names . '. They run with full rights on this machine, so they are only installed when you say so.' . $why,
            'act' => ['scripts', 'Install ' . (count($sw) > 1 ? 'them' : 'it')]];
    }
}
// The QNAP's scratch space (/tmp) is small and shared with the system.
if (preg_match('/(\d+)%/', (string)@file_get_contents("$WEB/tmp-disk.txt"), $tm) && (int)$tm[1] >= 80)
    $c[] = ['level' => 'warn', 'title' => 'The system scratch space is ' . $tm[1] . '% full',
        'body' => 'That is /tmp on the archive machine, not the archive. Rushes no longer uses it, but the system does; if it fills, odd errors follow.',
        'act' => null];

// How many copies each file has (the helper looks once a week; copies.php).
// Worth a card only when files that had a second copy have lost it.
$copies = json_decode((string)@file_get_contents("$WEB/copies-summary.json"), true) ?: null;
if ($copies && $copies['lost'][0] > 0) {
    $places = implode(', ', array_keys(array_filter($copies['places'] ?? [], fn($p) => $p['there'] < $p['looked'])));
    $c[] = ['level' => 'warn', 'title' => number_format($copies['lost'][0]) . ' files now exist only in the archive',
        'body' => 'Each one was also where it came from (' . ($places ?: 'its source') . '), and on the last look, '
            . date('j M', $copies['at']) . ', it was not there any more or had changed. If those originals were removed on purpose, '
            . 'the archive copy is now the only one: make sure a backup of the archive exists.', 'act' => null];
}

// nothing wrong is worth saying out loud
if (!$c) $c[] = ['level' => 'good', 'title' => 'Everything is in order',
    'body' => 'Search is current, nothing is waiting, no job needs you.', 'act' => null];

// ── what has been done lately ─────────────────────────────────────────────
$recent = [];
if (is_readable("$WEB/ingest-history.tsv")) {
    $lines = array_slice(array_filter(file("$WEB/ingest-history.tsv"), function ($l) {
        $f = explode("\t", $l);
        return !(($f[1] ?? '') === 'copied' && (int)($f[3] ?? 0) === 0 && (int)($f[4] ?? 0) === 0);
    }), -12);
    foreach (array_reverse($lines) as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if (count($f) < 6) continue;
        // a tidy-up is named for what it did, not for its plan's number
        $tidy = preg_match('/^(un)?tidy /', $f[2]);
        $what = ['copied' => 'brought over', 'tidied' => 'moved onto the shelf', 'untidied' => 'put back',
                 'refused' => 'refused', 'traced' => 'traced', 'interrupted' => 'interrupted',
                 'dropped' => 'dropped', 'analysed' => 'described',
                 'proven' => 'checked against its originals', 'checked' => 'checked for damage', 'counted' => 'counted'][$f[1]] ?? 'looked at';
        $recent[] = ['when' => $f[0], 'what' => $what,
                     'target' => $tidy ? ($f[1] === 'untidied' ? 'A tidy-up' : 'Tidy-up') : basename($f[2]), 'files' => (int)$f[3], 'bytes' => (int)$f[4],
                     'secs' => (int)$f[5], 'note' => $f[6] ?? ''];
    }
}

// the log, so the admin page can show what actually happened
$log = '';
if (is_readable("$WEB/job.log")) {
    $sz = filesize("$WEB/job.log");
    // Older runners wrote the search check's "nothing changed" answer every
    // minute; left in, it fills the view. Removed here, not from the file.
    $log = str_replace('{"state":"current"}', '', (string)@file_get_contents("$WEB/job.log", false, null, max(0, $sz - 200000)));
    $log = substr($log, -14000);
}

function tb(int $b): string {
    return $b >= 1099511627776 ? number_format($b / 1099511627776, 2) . ' TB'
         : ($b >= 1073741824   ? number_format($b / 1073741824, 1) . ' GB'
         : number_format($b / 1048576) . ' MB');
}

echo json_encode([
    'transfer' => $transfer,
    'search' => ['state' => $pendingSearch ? ($sync === 'retrying' ? 'retrying' : 'updating') : 'current', 'updated' => $searchAt],
    'archive'  => ['files' => $files, 'bytes' => $bytes,
                   'imported' => $imported, 'imported_ago' => $ago($searchAt)],
    'disk'     => $disk,
    'runner'   => ['ok' => $runner_ok, 'seen' => $alive, 'ago' => $ago($alive)],
    'running'  => $running, 'progress' => $progress,
    // asked from Manage, waiting for the runner's next turn
    'queued'   => array_values(array_filter(array_map(fn($j) => preg_match('/^ACTION=(.+)$/m', (string)@file_get_contents($j), $m) ? trim($m[1]) : '',
                    glob("$WEB/queue/*.job") ?: []))),
    'proxy_test' => is_readable("$WEB/proxy-test.txt") ? ['text' => (string)file_get_contents("$WEB/proxy-test.txt"), 'at' => filemtime("$WEB/proxy-test.txt"),
                     'stills' => array_map('basename', glob("$WEB/proxy-test/*.jpg") ?: [])] : null,
    // the proxy setting in use: height and Mbit/s (chosen after the test; 720p at 4 until then)
    'proxy_setting' => preg_match('/^(720|1080) (4|6|sw)$/', trim((string)@file_get_contents("$WEB/proxy-setting.txt")), $ps) ? [(int)$ps[1], $ps[2]] : [720, '4'],
    'gpu_test' => is_readable("$WEB/gpu-test.txt") ? ['text' => (string)file_get_contents("$WEB/gpu-test.txt"), 'at' => filemtime("$WEB/gpu-test.txt")] : null,
    'conditions' => $c,
    'copies'   => $copies,
    'recent'   => $recent,
    'sections' => $sections,
    'landed'   => $landed,
    'route'    => ['from' => $from, 'to' => $to],
    'log'      => $log,
    'ingests'  => $ingests,
    'volumes'  => (function () {
        // The picker lists without each drive's folder list: Ingest offers
        // drives and cards, and Setup reads the full list itself.
        $h = helper_volumes();
        $h['vols'] = array_map(function ($v) { unset($v['top']); return $v; }, $h['vols']);
        return $h;
    })(),
    'copy'     => $mac ? [
        'phase'  => $mac['phase'] ?? '', 'source' => $mac['source'] ?? '',
        'label'  => $mac['label'] ?? '',
        'copied' => (int)($mac['copied'] ?? 0), 'of' => (int)($mac['of'] ?? 0),
        'failed' => (int)($mac['failed'] ?? 0), 'bytes' => (int)($mac['new_bytes'] ?? 0),
        'note'   => $mac['note'] ?? '', 'ago' => $ago($mac_at), 'stale' => $mac_stale,
        // live detail: how far, how fast, what file — whichever the phase has
        'done_bytes' => (int)($mac['done_bytes'] ?? 0), 'rate' => (int)($mac['rate'] ?? 0),
        'eta'    => ($mac['eta'] ?? '') === '' ? null : (int)$mac['eta'], 'file' => $mac['file'] ?? '',
        'checked' => (int)($mac['checked'] ?? 0), 'new' => (int)($mac['new'] ?? 0),
        'already' => (int)($mac['already'] ?? 0), 'step' => $mac['step'] ?? '',
        'traced' => (int)($mac['traced'] ?? 0), 'untraced' => (int)($mac['untraced'] ?? 0),
        'originals' => (int)($mac['originals'] ?? 0), 'secs' => $mac_at ? $now - $mac_at : null,
        // matching earlier copies: how many folders of originals, and copies to check
        'folders' => (int)($mac['folders'] ?? 0), 'folders_read' => (int)($mac['folders_read'] ?? 0),
        'copies' => (int)($mac['copies'] ?? 0),
        // describing footage: which file of how many, which shot of how many
        'n' => (int)($mac['n'] ?? 0), 'shot' => (int)($mac['shot'] ?? 0), 'shots' => (int)($mac['shots'] ?? 0),
        'per_shot' => $mac['per_shot'] ?? '',
    ] : null,
    // Proxies being made on this machine (proxy.sh writes this as it goes).
    'proxies'  => (function () use ($WEB) {
        $p = [];
        foreach (@file("$WEB/proxy-status.txt") ?: [] as $l) { $f = explode("\t", rtrim($l, "\n"), 2); $p[$f[0]] = $f[1] ?? ''; }
        // asked from Manage, waiting for the runner's next turn (within a minute)
        foreach (glob("$WEB/queue/*.job") ?: [] as $j)
            if (preg_match('/^ACTION=(proxy-plan|proxy-build)$/m', (string)@file_get_contents($j), $m)) {
                preg_match('/^QUERY=(.*)$/m', (string)@file_get_contents($j), $q);
                $p['asked'] = ['what' => $m[1], 'only' => $q[1] ?? '', 'ago' => time() - filemtime($j)];
            }
        if (!$p) return null;
        require_once __DIR__ . '/prepare.php';
        $p['running'] = proxy_alive();
        $p['ago'] = isset($p['at']) ? time() - (int)$p['at'] : null;
        return $p;
    })(),
    'helper'   => (function () use ($ago, $WEB, $mac, $mac_stale, $mac_at) {
        $hv = helper_volumes(); $ctl = helper_control();
        return [
            'mode'    => helper_mode(),
            'label'   => helper_name(),
            'command' => helper_command(),
            // Alive if it reported its drives lately OR its live status is fresh:
            // a slow network share can hold up the drive report for a while.
            'seen'    => max($hv['at'], $mac_at), 'seen_ago' => $ago(max($hv['at'], $mac_at)), 'fresh' => $hv['fresh'] || ($mac && !$mac_stale),
            // its drive report is late while it works: a drive not answering (said on the page, not hidden)
            'drives_late' => ($hv['at'] && !$hv['fresh'] && $mac && !$mac_stale) || $hv['stuck'] !== '',
            'drive_stuck' => $hv['stuck'],
            'how'     => $hv['how'], 'ver' => $hv['ver'],
            // the version on the archive; a helper with another one updates itself
            'current' => substr((string)@hash_file('sha256', archive_dir() . '/_rushes/ingest.py'), 0, 12),
            'paused'  => (bool)$ctl['paused'],
            'no_reconnect' => !empty($ctl['no_reconnect']),
            'describe_paused' => !empty($ctl['describe_paused']),
            'check_paused' => !empty($ctl['check_paused']),
            // the describing lane, live: what it is on
            'describe' => (function () use ($WEB) {
                $s = [];
                foreach (@file("$WEB/describe-status.tsv") ?: [] as $l) { $f = explode("\t", rtrim($l, "\n"), 2); $s[$f[0]] = $f[1] ?? ''; }
                // long listening says nothing for minutes; the lane says so the moment it stops (see describing_now)
                return $s && time() - (int)($s['ts'] ?? 0) < (($s['phase'] ?? '') === 'analysing' ? 1800 : 300) ? $s : null;
            })(),
            'analysis' => $hv['analysis'],          // can it describe footage, and with what
            'builtin' => trim((string)@file_get_contents("$WEB/helper-builtin.txt")),
        ];
    })(),
    'scripts'  => scripts_waiting(),
    'now'      => $now,
], JSON_UNESCAPED_SLASHES);
