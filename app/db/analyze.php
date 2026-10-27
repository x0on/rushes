<?php
// analyze.php — describing footage: the vision model and speech, run by the helper.
//
//   GET                     folders in the archive, what is waiting, what is done
//   POST path=<folder>      describe that folder (a path inside the archive, as
//                           this machine sees it); signed in
//
// This page only asks. The helper describes one file at a time, after any
// copies, and writes one description per file into _rushes/analysis.
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function out(array $a) { echo json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }
function bail(int $code, string $why) { http_response_code($code); out(['error' => $why]); }

$Q = web_dir() . '/ingest-queue.tsv';
// A folder on a drive kept where it is: its full path, on such a drive, nothing odd in it. '' if not.
function drive_folder(string $raw): string {
    $p = rtrim(str_replace('\\', '/', $raw), '/');
    if (!str_starts_with($p, '/') || str_contains("$p/", '/../') || preg_match('/[\t\n\r]/', $p)) return '';
    return kept_drive_of($p) ? $p : '';
}
$root = rtrim(archive_dir(), '/');
$helperRoot = rtrim(helper_archive(), '/');

// A folder picked in Finder: a browser only ever tells a page the names inside
// it ("PARK COLLECTION/2019/a.mov"), never where it is. Those names are enough:
// the archive file whose path ends in one of them is in that folder.
//   GET ?locate[]=<folder>/<...>/<file>   up to 5  ->  the archive folders it can be
// GET ?plan=<folder>  what it holds and how much is left to make (instant)
if (isset($_GET['plan'])) {
    require_once __DIR__ . '/prepare.php';
    $rel = drive_folder((string)$_GET['plan']) ?: trim(str_replace('\\', '/', (string)$_GET['plan']), '/');
    if ($rel === '' || str_contains($rel, '..') || !is_dir(prep_abs($rel))) out(['error' => "There is no folder “{$rel}” in the archive."]);
    out(['folder' => $rel] + prepare_plan($rel, true));
}
// A clip picked in Finder: its name and size are all a browser tells (nothing
// is uploaded). The archive file with that name and exactly that size is it.
//   GET ?file=<name>&size=<bytes>  ->  where it is in the archive
if (isset($_GET['file'])) {
    require_once __DIR__ . '/schema.php';
    $find = db()->prepare('SELECT path FROM files WHERE name = ? AND bytes = ? LIMIT 20');
    $find->bindValue(1, basename((string)$_GET['file'])); $find->bindValue(2, (int)($_GET['size'] ?? 0));
    $r = $find->execute(); $files = [];
    while ($x = $r->fetchArray(SQLITE3_NUM))
        if (str_starts_with($x[0], "$root/") && !str_starts_with($x[0], "$root/PROXIES/")) $files[] = substr($x[0], strlen($root) + 1);
    out(['files' => $files]);
}
// What changed in a folder since its reading before: the files missing and the new ones (Describe, the badge)
if (isset($_GET['changes'])) {
    require_once __DIR__ . '/folders.php';
    $rel = drive_folder((string)$_GET['changes']) ?: trim(str_replace('\\', '/', (string)$_GET['changes']), '/');
    if ($rel === '' || str_contains($rel, '..')) out(['error' => 'Pick a folder on the list.']);
    out(['folder' => $rel, 'read' => folder_read(prep_abs($rel)), 'changes' => folder_changes(prep_abs($rel))]);
}
if (isset($_GET['locate'])) {
    require_once __DIR__ . '/schema.php';
    $find = db()->prepare('SELECT path FROM files WHERE name = ?');
    $found = [];
    foreach (array_slice((array)$_GET['locate'], 0, 5) as $rel) {
        $rel = trim(str_replace('\\', '/', (string)$rel), '/');
        $top = explode('/', $rel)[0];
        if (!str_contains($rel, '/') || str_contains($rel, '..')) continue;
        $find->bindValue(1, basename($rel)); $r = $find->execute();
        while ($x = $r->fetchArray(SQLITE3_NUM))
            if (!str_ends_with($x[0], "/$rel")) continue;
            elseif (str_starts_with($x[0], "$root/"))
                $found[substr($x[0], strlen($root) + 1, strlen($top) - strlen($rel)) ?: $top] = 1;
            // a folder of a drive kept where it is (added to Rushes): its whole path, as the drive page gives it
            elseif (can_preview_drives() && ($dir = substr($x[0], 0, strlen($x[0]) - strlen($rel) + strlen($top))) && drive_folder($dir) !== '')
                $found[$dir] = 1;
        $find->reset();
        if ($found) break;
    }
    // Not in Search yet (a drive just added, still being listed): looked for on the drives kept where they
    // are and plugged in, by the folder's name and the files the browser named inside it. Folders only,
    // hidden ones skipped, at most 8 deep and 20,000 folders a drive, so it answers in seconds.
    if (!$found && can_preview_drives()) {
        $rels = array_values(array_filter(array_map(fn($x) => trim(str_replace('\\', '/', (string)$x), '/'), array_slice((array)$_GET['locate'], 0, 5)),
            fn($x) => str_contains($x, '/') && !str_contains($x, '..')));
        $top = $rels ? explode('/', $rels[0])[0] : '';
        foreach ($top === '' ? [] : drives_seen() as $d) {
            if (empty($d['connected']) || !is_dir($root2 = rtrim((string)$d['path'], '/'))) continue;
            $stack = [[$root2, 0]]; $seen = 0;
            while ($stack && $seen < 20000) {
                [$dir, $depth] = array_pop($stack); $seen++;
                foreach (@scandir($dir) ?: [] as $n) {
                    if ($n[0] === '.' || $n[0] === '$' || !is_dir("$dir/$n") || is_link("$dir/$n")) continue;
                    if ($n === $top) {
                        $all = true;
                        foreach ($rels as $r) if (!file_exists("$dir/$r")) { $all = false; break; }
                        if ($all && drive_folder("$dir/$n") !== '') $found["$dir/$n"] = 1;
                    }
                    if ($depth < 8) $stack[] = ["$dir/$n", $depth + 1];
                }
            }
        }
    }
    out(['folders' => array_keys($found)]);
}

require_once __DIR__ . '/prepare.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!may_act((string)($_POST['pass'] ?? ''))) bail(403, 'sign in first');
    // The proxy setting chosen after "Test proxy settings": proxy.sh reads it
    // for every proxy made from now on. Existing proxies stay as they are.
    if (($_POST['action'] ?? '') === 'proxy-setting') {
        $h = (int)($_POST['height'] ?? 0); $b = (string)($_POST['mbits'] ?? '');     // 4 or 6 Mbit/s on the chip, or sw: software
        if (!in_array($h, [720, 1080], true) || !in_array($b, ['4', '6', 'sw'], true)) bail(400, 'not one of the settings offered');
        if (file_put_contents(web_dir() . '/proxy-setting.txt', "$h $b\n") === false) bail(500, 'could not save it');
        out(['ok' => 'proxy-setting', 'height' => $h, 'mbits' => $b]);
    }
    $rel = drive_folder((string)($_POST['path'] ?? '')) ?: trim(str_replace('\\', '/', (string)($_POST['path'] ?? '')), '/');
    // Prepare: proxies first, then descriptions; the order is kept by prepare_advance.
    // Order and "try again": the list keeps each folder's place and when it was asked.
    // Start now: the proxies for this folder, through the runner's own job queue.
    if (($_POST['action'] ?? '') === 'start') {
        if (!isset(prepare_list()[$rel])) bail(400, "“{$rel}” is not on the list.");
        $q = web_dir() . '/queue';
        if (!is_dir($q)) @mkdir($q, 0777, true);
        if (@file_put_contents("$q/" . date('Ymd-His') . '-start.job', "ACTION=proxy-build\nQUERY=$rel\n") === false)
            bail(500, 'Could not ask the archive machine — is its queue folder writable?');
        out(['ok' => 'start', 'folder' => $rel]);
    }
    // Start on this one now: it goes to the top of the list. Proxies being made for another folder stop
    // (the one being made is made again later; every one finished is kept) and carry on by themselves once this one's are
    // made; the helper's describing steps aside between two files once this one is ready to describe
    // (ingest.py, describe_lane: control "first"), and goes back to where it was after.
    if (($_POST['action'] ?? '') === 'first') {
        $list = prepare_list();
        if (!isset($list[$rel])) bail(400, "“{$rel}” is not on the list.");
        $asked = $list[$rel]; unset($list[$rel]); $list = [$rel => $asked] + $list;
        if (!prepare_save($list)) bail(500, 'Could not save — is the web folder writable?');
        $now = proxy_now(); $other = (string)($now['only'] ?? '');
        $stopped = '';
        if (!empty($now['running']) && $other !== '' && $other !== $rel) {
            $row = array_values(array_filter(prepare_table(), fn($r) => $r['folder'] === $rel))[0] ?? null;
            if ($row && !in_array($row['proxies']['step'], ['done', 'again', 'skipped'], true)) {
                prepare_resume($other, true);               // it carries on by itself once this one's are made
                $q = web_dir() . '/queue'; if (!is_dir($q)) @mkdir($q, 0777, true);
                @file_put_contents("$q/" . date('Ymd-His') . '-first.job', "ACTION=proxy-stop\nQUERY=\n");
                $stopped = $other;
            }
        }
        $c = helper_control(); $c['first'] = str_starts_with($rel, '/') ? $rel : rtrim(helper_archive(), '/') . "/$rel";
        $c['by'] = 'first'; $c['at'] = time();
        if (!helper_control_save($c)) bail(500, 'Could not tell the helper — is the web folder writable?');
        prepare_advance();
        out(['ok' => 'first', 'folder' => $rel, 'stopped' => $stopped, 'nopreview' => isset(prepare_nopreview()[$rel])]);
    }
    // Describe again: a folder described with an older version of describing (before the composition, or before moments inside long takes)
    // is described again, only the files described with an older version, in its place on the list. Never by
    // itself: describing improves, and a person says which folders are worth the time (ingest.py, --again).
    if (($_POST['action'] ?? '') === 'again') {
        $list = prepare_list();
        if (!isset($list[$rel])) bail(400, "“{$rel}” is not on the list.");
        $list[$rel] = time();
        if (!prepare_save($list)) bail(500, 'Could not save — is the web folder writable?');
        $c = helper_control(); $p = str_starts_with($rel, '/') ? $rel : rtrim(helper_archive(), '/') . "/$rel";
        $c['again'] = array_values(array_unique(array_merge((array)($c['again'] ?? []), [$p])));
        if (!helper_control_save($c)) bail(500, 'Could not tell the helper — is the web folder writable?');
        prepare_advance();
        out(['ok' => 'again', 'folder' => $rel]);
    }
    // Describe without previews (or with them again): for footage that only needs describing. Previews being
    // made for it stop (the ones finished are kept and used); describing reads the originals for the rest.
    if (in_array($_POST['action'] ?? '', ['nopreview', 'withpreview'], true)) {
        if (!isset(prepare_list()[$rel])) bail(400, "“{$rel}” is not on the list.");
        $on = $_POST['action'] === 'nopreview';
        prepare_nopreview($rel, $on);
        $now = proxy_now();
        if ($on && !empty($now['running']) && ($now['only'] ?? '') === $rel) {
            $q = web_dir() . '/queue'; if (!is_dir($q)) @mkdir($q, 0777, true);
            @file_put_contents("$q/" . date('Ymd-His') . '-nopreview.job', "ACTION=proxy-stop
QUERY=
");
        }
        prepare_advance();
        out(['ok' => $_POST['action'], 'folder' => $rel]);
    }
    // Remake: its proxies thrown away and made again (a new Proxy setting). On the
    // archive machine itself, which may delete them; never while they are being
    // made, or while describing is reading them.
    if (($_POST['action'] ?? '') === 'remake') {
        if (!isset(prepare_list()[$rel])) bail(400, "“{$rel}” is not on the list.");
        if (proxy_alive()) bail(409, 'Proxies are being made right now — press Stop proxies first, or wait until they are done.');
        $d = describing_now();
        if ($d && str_ends_with(rtrim($d['source'] ?? '', '/'), '/' . $rel))
            bail(409, 'The helper is describing ' . $rel . ' now, from these proxies. Remake them when it is done, or pause describing first.');
        $q = web_dir() . '/queue';
        if (!is_dir($q)) @mkdir($q, 0777, true);
        if (@file_put_contents("$q/" . date('Ymd-His') . '-remake.job', "ACTION=proxy-remake\nQUERY=$rel\n") === false)
            bail(500, 'Could not ask the archive machine — is its queue folder writable?');
        @unlink(web_dir() . '/prepare-plan.json');          // its plan is out of date the moment they go
        out(['ok' => 'remake', 'folder' => $rel]);
    }
    if (in_array($_POST['action'] ?? '', ['up', 'down', 'retry'], true)) {
        $list = prepare_list();
        if (!isset($list[$rel])) bail(400, "“{$rel}” is not on the list.");
        if ($_POST['action'] === 'retry') { $list[$rel] = time(); }
        else {
            $keys = array_keys($list); $i = array_search($rel, $keys, true); $j = $_POST['action'] === 'up' ? $i - 1 : $i + 1;
            if ($j >= 0 && $j < count($keys)) { [$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]]; }
            $list = array_combine($keys, array_map(fn($k) => $list[$k], $keys));
        }
        if (!prepare_save($list)) bail(500, 'Could not save — is the web folder writable?');
        prepare_advance();
        out(['ok' => $_POST['action'], 'folder' => $rel]);
    }
    if (($_POST['action'] ?? '') === 'prepare' || ($_POST['action'] ?? '') === 'forget') {
        $drive = str_starts_with($rel, '/');
        if ($drive && !can_preview_drives())
            bail(400, 'Previews of a drive kept where it is are made by the Rushes app on a Mac, with its own helper.');
        // any name a folder has on the archive (City Manager's Office, #2, [B-roll]): never .., control characters,
        // a backslash, or what a shell reads twice (` $), though no shell ever sees it
        if (!$drive && ($rel === '' || in_array('..', explode('/', $rel), true) || preg_match('/[\x00-\x1f\\\\`$]/', $rel) || str_starts_with($rel, '/')))
            bail(400, 'Pick a folder inside the archive.');
        $list = prepare_list();
        if ($_POST['action'] === 'forget') { unset($list[$rel]); }
        else {
            if (!is_dir(prep_abs($rel))) bail(400, $drive ? "“{$rel}” is not there now: is the drive plugged in?" : "There is no folder “{$rel}” in the archive.");
            unset($list[$rel]); $list[$rel] = time();      // asked again: to the end, and runs again
            require_once __DIR__ . '/folders.php';
            folder_read_start(prep_abs($rel), true);       // what is in it, read on the disk now, in the background
        }
        if (!prepare_save($list)) bail(500, 'Could not save — is the web folder writable?');
        prepare_advance();
        out(['ok' => $_POST['action'], 'folder' => $rel]);
    }
    bail(400, 'unknown action');
}

$waiting = []; $done = [];
foreach (@file($Q) ?: [] as $l)
    if (preg_match('/^analyze\t([^\t]+)/', rtrim($l), $m)) $waiting[] = ltrim(substr($m[1], strlen($helperRoot)), '/');
foreach (array_reverse(@file(web_dir() . '/ingest-history.tsv') ?: []) as $l) {
    $f = explode("\t", rtrim($l, "\n"));
    if (($f[1] ?? '') === 'analysed' && count($done) < 20)
        $done[] = ['path' => ltrim(substr($f[2], strlen($helperRoot)), '/'), 'files' => (int)$f[3], 'when' => $f[0], 'note' => $f[6] ?? ''];
}
// How far it has got, and the latest shots to check by eye.
require_once __DIR__ . '/analysis.php';
analysis_init(); $db = db();
$one = fn(string $sql) => (int)($db->querySingle($sql) ?? 0);
// counted in search, not on the VIDEO share: this answers a page every ten seconds
$stats = ['files' => $one("SELECT COUNT(DISTINCT fp) FROM moments"),
          'shots' => $one("SELECT COUNT(*) FROM moments WHERE kind = 'shot'"),
          'speech' => $one("SELECT COUNT(*) FROM moments WHERE kind = 'speech'"),
          'failed' => $one("SELECT COUNT(*) FROM moments WHERE kind = 'failed'")];
$latest = []; $r = $db->query("SELECT fp,path,kind,shot,start_s,end_s,what,on_screen,themes,tags,shot_size,people,light,
    language FROM moments WHERE kind != 'failed' ORDER BY rowid DESC LIMIT 24");
while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $latest[] = $x;
// what the proxy maker last said, in its own words (its log on the archive machine)
$plog = @file(web_dir() . '/proxy.log', FILE_IGNORE_NEW_LINES) ?: [];
$perr = @file(web_dir() . '/proxy-built.tsv.err', FILE_IGNORE_NEW_LINES) ?: [];
// folders moved before the list followed moves: found again where their files are (or said on their row)
prepare_undo_name_guesses(); prepare_drop_guessed_lines();
$lost = prepare_find_moved();
$table = array_map(fn($r) => isset($lost[$r['folder']]) && $r['describe']['step'] === 'gone' ? array_replace_recursive($r, ['describe' => ['at' => $lost[$r['folder']]]]) : $r, prepare_table());
// Describe open: the folders still being worked on are read again on the disk, one at a time, when their
// last reading is more than half an hour old (folders.php), so what the list says is what is there
foreach ($table as $r)
    if (!(in_array($r['proxies']['step'], ['done', 'skipped'], true) && $r['describe']['step'] === 'done') && folder_read_start(prep_abs($r['folder']))) break;
out(['table' => $table, 'why' => prepare_why(),
     'proxy_log' => ['lines' => array_slice($plog, -25), 'at' => (int)@filemtime(web_dir() . '/proxy.log'),
                     'errors' => array_slice($perr, -8)], 'rate' => proxy_rate(), 'helper_fresh' => (bool)(helper_volumes()['fresh'] ?? false), 'waiting' => $waiting, 'done' => $done, 'described' => $stats['files'],
     'stats' => $stats, 'latest' => $latest, 'helper' => helper_name()]);
