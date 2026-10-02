<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// watcher.php — the door for Rushes Watcher, on each editor's computer
// (HOW-IT-WORKS.md → Projects in and out). Only a paired Watcher (its ID in
// X-Rushes-Watcher) gets through, and a Watcher can only: say where things
// are, report what it is doing, tell Rushes a delivery is ready in its own
// folder of the Deliveries share, say what a project uses, and ask where its
// delivered files went. It never writes into the archive: the helper does,
// after checking every file.
//
//   GET  ?hello                         the shares and folders it needs
//   POST action=report  state, now, log what it is doing (for Setup and the project pages)
//   POST action=delivered batch=<name>  a batch is complete in Deliveries/<its key>/<name>
//   POST action=project path, name, saved, files, outside, missing (names, separated by ;), shoot
//   GET  ?where&project=<path>          where its delivered files are now, inside the archive
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
        // Each editor's computer mounts the shares by their names (on a Mac, /Volumes/<name>).
        'archive'    => basename(archive_dir()),
        'projects'   => ($p = s_path('shares.projects')) !== '' ? basename($p) : '',
        'deliveries' => ($d = s_path('shares.deliveries')) !== '' ? basename($d) : '',
        'shelf'      => shelf_name(),
        'cache'      => rules()['cache'] ?? [],
    ]);
}

// A project's path, inside the Projects share: plain, relative, no climbing out.
$project = function () use ($clean): string {
    $p = $clean($_GET['project'] ?? ($_POST['path'] ?? ''), 400);
    if ($p === '' || str_starts_with($p, '/') || str_contains($p, '..') || str_contains($p, '\\')) said(400, ['error' => 'not a project path']);
    return $p;
};

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
    // Only in its own folder of Deliveries, and only a plain batch name.
    $batch = (string)($_POST['batch'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $batch) || $batch[0] === '.') said(400, ['error' => 'not a batch name']);
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
    foreach ([$p, $clean($_POST['name'] ?? basename($p), 200), $clean($me['host'] ?? '', 80), $me['key'], (int)($_POST['saved'] ?? 0), time(),
              (int)($_POST['files'] ?? 0), (int)($_POST['outside'] ?? 0), $clean($_POST['missing'] ?? '', 2000), $shoot, 'active'] as $i => $v)
        $st->bindValue($i + 1, $v);
    $st->execute();
    said(200, ['ok' => true]);
}

said(400, ['error' => 'nothing asked']);
