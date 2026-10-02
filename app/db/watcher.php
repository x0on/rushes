<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// watcher.php — the door for Rushes Watcher, on each editor's computer
// (HOW-IT-WORKS.md → Projects in and out). Only a paired Watcher (its ID in
// X-Rushes-Watcher) gets through, and a Watcher can only: say what it is
// doing, send the files its editor's projects use (into this computer's own
// inbox, in the web folder), say a batch is complete, say what a project
// uses, and ask where its files went. It never writes into the archive, and
// needs no share it can write to: the helper places every file, after
// checking it, in Projects/<this computer>/ on the shelf.
//
//   GET  ?hello                                   who it is, its folder, the archive's name
//   GET  ?upload&batch=&name=                     how much of a file is here already (to carry on)
//   POST ?upload&batch=&name=&offset=&size=&fp=   the next piece of a file (the body); the last one is checked
//   POST action=delivered batch project shoot lines   the batch is complete: its list, for the helper
//   POST action=report  state, now, log           what it is doing (for Setup and the project pages)
//   POST action=project name saved files outside missing (names, separated by ;) shoot
//   GET  ?where&project=<name>                    where its delivered files are now, inside the archive
require_once __DIR__ . '/schema.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function said(int $code, array $a) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }
$me = watcher_gate();
$clean = fn($s, int $n = 300) => mb_substr(trim(preg_replace('/[\t\r\n]+/', ' ', (string)$s)), 0, $n);

if (isset($_GET['hello'])) {
    $s = settings();
    // Its own projects that are resting or moved aside, so its editor hears it there too.
    db_init(); $rest = (int)($s['projects']['rest_days'] ?? 10); $quiet = ['resting' => [], 'aside' => []];
    $st = db()->prepare('SELECT path, saved, state FROM projects WHERE watcher = ?'); $st->bindValue(1, $me['key']);
    $r = $st->execute();
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) {
        if ($x['state'] === 'aside') $quiet['aside'][] = $x['path'];
        elseif ((int)$x['saved'] && time() - (int)$x['saved'] > $rest * 86400) $quiet['resting'][] = $x['path'];
    }
    said(200, $quiet + [
        'name'       => $s['name'] ?? 'Rushes',
        'key'        => $me['key'],
        'editor'     => $me['name'] ?? ($me['host'] ?? ''),
        'folder'     => $me['folder'] ?? '',
        // The archive as each editor's computer mounts it (on a Mac, /Volumes/<name>):
        // files already in it are never sent again, and archived projects point there.
        'archive'    => basename(archive_dir()),
        'shelf'      => shelf_name(),
        'cache'      => rules()['cache'] ?? [],
    ]);
}

// A project is named by the Watcher (its file's name); Rushes keeps it as
// <this computer's folder>/<name>, so one computer never names another's.
$plain = fn($n) => is_string($n) && $n !== '' && $n[0] !== '.' && mb_strlen($n) <= 200 && !preg_match('/[\\/\\\\:\x00-\x1f]/u', $n);
$project = function () use ($clean, $plain, $me): string {
    $n = $clean($_GET['project'] ?? ($_POST['name'] ?? ''), 200);
    if (!$plain($n) || empty($me['folder'])) said(400, ['error' => 'not a project name (or this computer has no folder: pair it again)']);
    return $me['folder'] . '/' . $n;
};
$inbox = function (string $batch) use ($me): string {
    if (!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]{0,79}$/', $batch)) said(400, ['error' => 'not a batch name']);
    $top = web_dir() . '/inbox';
    if (!is_dir($top)) { @mkdir($top, 0775); @file_put_contents("$top/.htaccess", "Require all denied\n"); }   // never handed out
    return "$top/{$me['key']}/$batch";
};

// A file, sent in pieces (each well under what the web server takes at once),
// in order, carried on from where it stopped. The last piece completes it, and
// it is kept only if it is the size and the fingerprint the Watcher took.
if (isset($_GET['upload'])) {
    $dir = $inbox((string)($_GET['batch'] ?? '')) . '/files'; $name = (string)($_GET['name'] ?? '');
    if (!$plain($name)) said(400, ['error' => 'not a file name']);
    $f = "$dir/$name";
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        clearstatcache();
        said(200, is_file($f) ? ['have' => filesize($f), 'done' => true] : ['have' => is_file("$f.part") ? filesize("$f.part") : 0, 'done' => false]);
    }
    $size = (int)($_GET['size'] ?? -1); $offset = (int)($_GET['offset'] ?? -1);
    if (!preg_match('/^sha256:([0-9a-f]{64})$/', (string)($_GET['fp'] ?? ''), $fp) || $size < 0) said(400, ['error' => 'size and fingerprint first']);
    if ($offset === 0 && ($free = @disk_free_space(web_dir())) !== false && $free < $size + limit('inbox_free_min', 20 * 1024 ** 3))
        said(507, ['error' => 'not enough room on the server for this file now; it is sent again later']);
    @mkdir($dir, 0775, true);
    clearstatcache(); $have = is_file("$f.part") ? filesize("$f.part") : 0;
    if (is_file($f)) said(200, ['have' => filesize($f), 'done' => true]);
    if ($offset !== $have) said(409, ['error' => 'not the next piece', 'have' => $have]);
    $in = fopen($_SERVER['RUSHES_TEST_BODY'] ?? 'php://input', 'rb'); $out = fopen("$f.part", 'ab');   // (the tests hand the body in a file)
    if (!$in || !$out) said(500, ['error' => 'could not write in the web folder']);
    $n = stream_copy_to_stream($in, $out, max(0, $size - $offset)); fclose($out);
    if ($have + $n < $size) said(200, ['have' => $have + $n, 'done' => false]);
    if (hash_file('sha256', "$f.part") !== $fp[1]) { @unlink("$f.part"); said(422, ['error' => 'the file arrived different from the one on the editor\'s computer; it is sent again']); }
    rename("$f.part", $f); @mkdir(dirname($dir) . '/fp', 0775); @file_put_contents(dirname($dir) . "/fp/$name", "sha256:{$fp[1]}\n");
    said(200, ['have' => $size, 'done' => true]);
}

if (isset($_GET['where'])) {
    db_init(); $p = $project();
    $st = db()->prepare('SELECT original, rel, kind FROM delivered WHERE project = ?');
    $st->bindValue(1, $p); $r = $st->execute(); $out = [];
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) $out[$x['original']] = ['rel' => $x['rel'], 'kind' => $x['kind']];
    said(200, ['project' => $p, 'archive' => basename(archive_dir()), 'files' => $out]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') said(400, ['error' => 'nothing asked']);
$act = (string)($_POST['action'] ?? '');

if ($act === 'report') {
    // What it is doing and the end of its log, as it says them: shown in Setup and on project pages.
    $dir = web_dir() . '/watchers';
    if (!is_dir($dir)) { @mkdir($dir, 0775); @file_put_contents("$dir/.htaccess", "Require all denied\n"); }   // read by Rushes, not handed out
    $log = implode("\n", array_slice(array_map(fn($l) => $clean($l, 200), explode("\n", (string)($_POST['log'] ?? ''))), -40));
    $body = "at\t" . time() . "\nhost\t" . $clean($me['host'] ?? '', 80) . "\nstate\t" . $clean($_POST['state'] ?? '', 40)
          . "\nnow\t" . $clean($_POST['now'] ?? '') . "\nver\t" . $clean($_POST['ver'] ?? '', 20) . "\n--\n$log\n";
    @file_put_contents("$dir/{$me['key']}.txt.new", $body) !== false && @rename("$dir/{$me['key']}.txt.new", "$dir/{$me['key']}.txt");
    said(200, ['ok' => true]);
}

if ($act === 'delivered') {
    // Every file it lists is here, complete and checked; Rushes writes the
    // batch's list itself (who sent it, and where it goes, are its own words).
    $batch = (string)($_POST['batch'] ?? ''); $dir = $inbox($batch);
    $proj = $project(); $shoot = $clean($_POST['shoot'] ?? '', 400);
    if (str_starts_with($shoot, '/') || str_contains($shoot, '..')) $shoot = '';
    $out = ["rushes-delivery 2", "watcher\t{$me['key']}", "host\t" . $clean($me['host'] ?? '', 80), "folder\t{$me['folder']}",
            "project\t$proj", "shoot\t$shoot"];
    foreach (explode("\n", (string)($_POST['lines'] ?? '')) as $l) {
        $f = explode("\t", rtrim($l, "\r"));
        if ($f[0] === '') continue;
        $ok = ($f[0] === 'file' && count($f) === 6 && in_array($f[4], ['music', 'stock', 'sfx', 'project', 'output'], true))
           || ($f[0] === 'projectfile' && count($f) === 5);
        $file = "$dir/files/" . ($f[1] ?? '');
        if (!$ok || !$plain($f[1]) || !is_file($file) || filesize($file) !== (int)$f[3] || trim((string)@file_get_contents("$dir/fp/" . $f[1])) !== $f[2])
            said(400, ['error' => 'the batch lists a file that has not arrived whole: ' . mb_substr($f[1] ?? '', 0, 80)]);
        $out[] = implode("\t", array_map(fn($x) => $clean($x, 1000), $f));
    }
    if (count($out) === 6) said(400, ['error' => 'an empty batch']);
    $out[] = 'end';
    if (@file_put_contents("$dir/batch.tsv.new", implode("\n", $out) . "\n") === false || !@rename("$dir/batch.tsv.new", "$dir/batch.tsv"))
        said(500, ['error' => 'could not write in the web folder']);
    $line = "deliver\t{$me['key']}/$batch";
    $q = web_dir() . '/ingest-queue.tsv';
    $lock = fopen(web_dir() . '/ingest-queue.lock', 'c'); if ($lock) flock($lock, LOCK_EX);
    $all = array_values(array_filter(array_map(fn($l) => rtrim($l, "\n"), @file($q) ?: []), 'strlen'));
    if (!in_array($line, $all, true)) {
        // after the cards and tidy-ups, before whole old-server folders: an editor is waiting on this
        $at = 0; foreach ($all as $i => $l) if (preg_match('/^(ingest|tidy|untidy|deliver)\t/', $l)) $at = $i + 1;
        array_splice($all, $at, 0, [$line]);
        $ok = @file_put_contents("$q.new", implode("\n", $all) . "\n") !== false && @rename("$q.new", $q);
        if (!$ok) said(500, ['error' => 'could not write the queue — is the web folder writable?']);
    }
    said(200, ['queued' => "{$me['key']}/$batch"]);
}

if ($act === 'project') {
    db_init(); $p = $project();
    $shoot = $clean($_POST['shoot'] ?? '', 400);
    if ($shoot !== '' && (str_starts_with($shoot, '/') || str_contains($shoot, '..'))) $shoot = '';
    $st = db()->prepare('INSERT INTO projects (path, name, host, watcher, saved, seen, files, outside, missing, shoot, state)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(path) DO UPDATE SET name=excluded.name, host=excluded.host, watcher=excluded.watcher,
            saved=MAX(projects.saved, excluded.saved), seen=excluded.seen, files=excluded.files, outside=excluded.outside,
            missing=excluded.missing, shoot=excluded.shoot, state=CASE WHEN projects.state = \'aside\' THEN \'aside\' ELSE \'active\' END');
    foreach ([$p, basename($p), $clean($me['host'] ?? '', 80), $me['key'], (int)($_POST['saved'] ?? 0), time(),
              (int)($_POST['files'] ?? 0), (int)($_POST['outside'] ?? 0), $clean($_POST['missing'] ?? '', 2000), $shoot, 'active'] as $i => $v)
        $st->bindValue($i + 1, $v);
    $st->execute();
    said(200, ['ok' => true]);
}

said(400, ['error' => 'nothing asked']);
