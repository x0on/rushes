<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// browse.php — Reorganize → Tidy up: the archive as Finder shows it, and changes made at once, as in Finder
// (HOW-IT-WORKS.md → Tidy up). A move inside the archive is a rename on the drive: instant, nothing copied.
// Each one is written down (_rushes/origin, for relinking Premiere projects), Search follows it
// at once (moved.php follow_moves), its previews move with it, and the last ones can be undone.
// Drives kept where they are can be looked at here; Rushes never changes anything on them.
//
//   GET  ?roots                         the places to look: the archive, each drive in Search
//   GET  ?dir=<full path>               what is in a folder: folders first, then files, A to Z
//   POST op=move items=[…] to=<folder>  each one into that folder (a folder of the same name: merge=1 joins them)
//   POST op=rename item= name=          the same place, a new name
//   POST op=mkdir dir= name=            a new folder
//   POST op=undo                        the last change made here, put back
//   POST op=reveal item=                shown in Finder, on the Mac Rushes runs on
require_once __DIR__ . '/auth.php';
define('MOVED_FUNCTION_ONLY', true);
require_once __DIR__ . '/moved.php';
require_once __DIR__ . '/activity.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function out(array $a) { echo json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function bail(int $code, string $why) { http_response_code($code); out(['error' => $why]); }
if (!may_act((string)($_REQUEST['pass'] ?? ''))) bail(403, 'sign in first');

const UNDO_KEEP = 50;
const HOLD = '_Recently Removed';       // runner.py's: on the archive, emptied only by Delete All
$A = rtrim(archive_dir(), '/');
// Rushes' own at the archive's top (and the system's): never shown here, never moved, never moved into, never a
// name given at the top. Any case: the Mac's drives do not tell PROXIES from proxies.
function own_name(string $name): bool {
    return $name === '' || strpbrk($name[0], '._@#$') !== false || strtolower($name) === 'proxies';
}
$own = 'own_name';
// The first folder below the archive a path is in ('' for the archive itself)
function top_of(string $p): string { $A = rtrim(archive_dir(), '/'); return $p === $A ? '' : explode('/', substr($p, strlen($A) + 1))[0]; }

// Which place a path is in: ['root' => …, 'archive' => bool], or null. Never a way out of it.
function place(string $p): ?array {
    $A = rtrim(archive_dir(), '/');
    // one spelling of a path only: no .., no ., no //, nothing invisible
    if ($p === '' || $p[0] !== '/' || preg_match('#/\.\.?(/|$)|//|[\x00-\x1f]#', $p)) return null;
    if ($p === $A || str_starts_with($p, "$A/")) return ['root' => $A, 'archive' => true];
    if ($d = kept_drive_of($p)) return ['root' => $d['root'], 'archive' => false, 'name' => (string)$d['name']];
    return null;
}
function in_archive(string $p): bool {
    $w = place($p);
    if (!$w || !$w['archive']) return false;
    // and really there: a link inside the archive that points elsewhere does not take a move out of it
    $A = realpath(archive_dir()); $d = realpath(dirname($p));
    return $A && $d && ($d === $A || str_starts_with($d, "$A/"));
}
// A name a folder or file can have on any share: no slash, no control characters, not hidden, not too long.
function good_name(string $n): bool {
    return $n !== '' && $n === trim($n) && strlen($n) <= 240 && $n[0] !== '.' && !preg_match('#[/\\\\:\x00-\x1f]#', $n);
}

// ── looking ────────────────────────────────────────────────────────────────
if (isset($_GET['roots'])) {
    $r = [['path' => $A, 'name' => settings()['archive']['label'] ?? basename($A), 'archive' => true, 'here' => is_dir($A)]];
    foreach (drives_seen() as $d) $r[] = ['path' => rtrim($d['path'], '/'), 'name' => (string)$d['name'], 'archive' => false, 'here' => !empty($d['connected'])];
    out(['roots' => $r, 'finder' => on_mac() && PHP_OS_FAMILY === 'Darwin' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)]);
}
if (isset($_GET['dir'])) {
    $dir = rtrim((string)$_GET['dir'], '/');
    $w = place($dir);
    if (!$w || !is_dir($dir)) bail(404, 'That folder is not there now' . ($w && !$w['archive'] ? ' (is the drive plugged in?)' : '') . '.');
    $names = @scandir($dir);
    if ($names === false) bail(500, 'Could not read that folder.');
    $top = $dir === $w['root'];
    $list = [];
    foreach ($names as $n) {
        if ($n === '.' || $n === '..' || $n[0] === '.' || ($top && $w['archive'] && $own($n))) continue;
        $p = "$dir/$n"; $isDir = is_dir($p);
        $list[] = ['name' => $n, 'dir' => $isDir, 'size' => $isDir ? null : @filesize($p), 'at' => @filemtime($p)];
    }
    usort($list, fn($a, $b) => [!$a['dir'], strtolower($a['name'])] <=> [!$b['dir'], strtolower($b['name'])]);
    $more = max(0, count($list) - 5000);
    $list = array_slice($list, 0, 5000);
    // which videos play here (a preview made, or light enough as they are): one look in Search
    $st = db()->prepare("SELECT f.path FROM files f JOIN media m ON m.file_id = f.id WHERE f.path >= ? AND f.path < ? AND (m.proxy_at OR m.asis = '1')");
    $st->bindValue(1, "$dir/"); $st->bindValue(2, "$dir" . '0');
    $r = $st->execute(); $plays = [];
    while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) if (!str_contains(substr($x[0], strlen($dir) + 1), '/')) $plays[basename($x[0])] = 1;
    foreach ($list as &$e) if (!$e['dir'] && isset($plays[$e['name']])) $e['plays'] = true;
    out(['dir' => $dir, 'root' => $w['root'], 'archive' => $w['archive'], 'items' => $list, 'more' => $more]);
}

// Its properties, dates first: for a file what the camera wrote inside it (the date that survives copies),
// then modified and created as Finder shows them; for a folder its clips' dates and the year it would go under.
if (isset($_GET['info'])) {
    require_once __DIR__ . '/years.php';
    $p = rtrim((string)$_GET['info'], '/');
    if (!place($p) || !file_exists($p)) bail(404, 'That is not there now.');
    $born = function (string $p) {                       // created: the Mac keeps it; elsewhere it is not known
        if (PHP_OS_FAMILY !== 'Darwin') return null;
        $t = trim((string)@shell_exec('stat -f %B ' . escapeshellarg($p) . ' 2>/dev/null'));
        return ctype_digit($t) && (int)$t > 0 ? (int)$t : null;
    };
    $db = db();
    if (is_file($p)) {
        $q = $db->prepare('SELECT f.kind, m.recorded, m.duration, m.width, m.height, m.fps, m.codec, m.camera, m.timecode, m.reel, m.proxy_at, m.asis
                           FROM files f LEFT JOIN media m ON m.file_id = f.id WHERE f.path = ?');
        $q->bindValue(1, $p); $m = $q->execute()->fetchArray(SQLITE3_ASSOC) ?: [];
        out(['path' => $p, 'dir' => false, 'size' => @filesize($p), 'modified' => @filemtime($p), 'created' => $born($p),
             'shot' => $m['recorded'] ?? null, 'plays' => !empty($m['proxy_at']) || ($m['asis'] ?? '') === '1',
             'media' => array_intersect_key($m, array_flip(['duration', 'width', 'height', 'fps', 'codec', 'camera', 'timecode', 'reel'])),
             'year' => clip_year($p)]);
    }
    // a folder: counted from Search (instant, even for a big one); its clips' dates from what the camera
    // wrote, or the file's own date (clip_year, read once and kept), at most YEAR_SAMPLE clips
    $q = $db->prepare("SELECT f.path, f.bytes, f.kind, m.recorded FROM files f LEFT JOIN media m ON m.file_id = f.id WHERE f.path >= ? AND f.path < ?");
    $q->bindValue(1, "$p/"); $q->bindValue(2, "$p" . '0');
    $r = $q->execute(); $n = 0; $b = 0; $zero = 0; $clips = []; $shot = [];
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
        $n++; $b += (int)$x['bytes']; if ((int)$x['bytes'] === 0) $zero++;
        if ($x['kind'] === 'video') { $clips[] = $x['path']; if ($x['recorded']) $shot[] = substr((string)$x['recorded'], 0, 10); }
    }
    sort($shot);
    $w = place($p); [$year, $why] = folder_year($p, $w['root'], $clips);
    out(['path' => $p, 'dir' => true, 'files' => $n, 'bytes' => $b, 'zero' => $zero, 'videos' => count($clips),
         'shot' => $shot ? [$shot[0], end($shot), count($shot)] : null, 'year' => $year, 'year_why' => $why,
         'modified' => @filemtime($p), 'created' => $born($p)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') bail(400, 'ask for ?roots, ?dir= or ?info=');

// ── changing ───────────────────────────────────────────────────────────────
// Never beside a tidy-up the helper is doing: two things moving the same folders end where neither meant.
$st = [];
foreach (@file(web_dir() . '/ingest-status.tsv') ?: [] as $l) { $f = explode("\t", rtrim($l, "\n"), 2); $st[$f[0]] = $f[1] ?? ''; }
$op = (string)($_POST['op'] ?? '');
if ($op !== 'reveal' && ($st['phase'] ?? '') === 'tidying' && time() - (int)($st['ts'] ?? 0) < 900)
    bail(409, 'A tidy-up is moving files right now. Changes here wait until it is done (the top bar shows how far it is).');

// One change at a time, whoever asks (two windows, two people): the undo list and the drive agree
$lock = fopen(web_dir() . '/browse.lock', 'c'); flock($lock, LOCK_EX);
$undoF = web_dir() . '/browse-undo.json';
$undo = json_decode((string)@file_get_contents($undoF), true) ?: [];
$saveUndo = function () use (&$undo, $undoF) { @file_put_contents($undoF, json_encode(array_slice($undo, -UNDO_KEEP))); };
$record = null; $recordFailed = false;
// One file for this page's changes in _rushes/origin, as the helper's tidy-ups: "moved" lines, one per file,
// so relinking a Premiere project knows where each file went. Written before Search is told.
function rec(array $pairs, string $note): void {
    global $record, $recordFailed;
    if (!$pairs) return;
    $dir = rtrim(archive_dir(), '/') . '/_rushes/origin';
    if (!$record) {
        @mkdir($dir, 0777, true);
        $record = "$dir/" . date('Ymd-His') . ' archive moved here.tsv';
        $who = activity_who();
        if (@file_put_contents($record, "# rushes origin log\tone line per file; never edited\n# kind\tmoved in Reorganize → Tidy up\n"
            . ($who !== '' ? "# by\t$who\n" : '') . "what\tsource\tarchive\tbytes\tnote\n", FILE_APPEND) === false) $recordFailed = true;
    }
    $out = '';
    foreach ($pairs as [$a, $b, $n]) $out .= "moved\t$a\t$b\t$n\t$note\n";
    if (@file_put_contents($record, $out, FILE_APPEND) === false) $recordFailed = true;
}
// Every file under a path, from Search (a folder) or the file itself: [[old, new, bytes], …] once moved to $to.
// A Mac may spell an accented name either way (é as one letter or two): Search's spelling is looked for too.
function files_under(string $from, string $to): array {
    if (is_file($to)) return [[$from, $to, (int)@filesize($to)]];
    $q = db()->prepare('SELECT path, bytes FROM files WHERE path >= ? AND path < ?');
    $forms = [$from];
    if (class_exists('Normalizer')) foreach ([Normalizer::FORM_C, Normalizer::FORM_D] as $f) { $x = Normalizer::normalize($from, $f); if ($x !== false && !in_array($x, $forms, true)) $forms[] = $x; }
    foreach ($forms as $fr) {
        $q->bindValue(1, "$fr/"); $q->bindValue(2, "$fr" . '0');
        $r = $q->execute(); $out = [];
        while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $out[] = [$x[0], $to . substr($x[0], strlen($fr)), (int)$x[1]];
        $q->reset();
        if ($out) return $out;
    }
    return [];
}
// Whether a thing may go to a place: both in the archive, neither Rushes' own, never into itself.
function may_move(string $from, string $to): void {
    $A = rtrim(archive_dir(), '/');
    if (!in_archive($from) || !in_archive($to) || $from === $A || $to === $A) throw new RuntimeException('Only things inside the archive can be moved here.');
    if (own_name(top_of($from)) || own_name(top_of($to))) throw new RuntimeException('Rushes’ own folders stay as they are, and nothing goes into them (nor gets a name starting with _ . @ #).');
    if (str_starts_with("$to/", "$from/")) throw new RuntimeException(basename($from) . ' cannot go inside itself.');
}
// Its preview, on the archive (PROXIES), the same way; a missing one is fine, one already there is left
function move_preview(string $from, string $to, bool $dir): void {
    $A = rtrim(archive_dir(), '/');
    [$pa, $pb] = $dir ? ["$A/PROXIES/" . substr($from, strlen($A) + 1), "$A/PROXIES/" . substr($to, strlen($A) + 1)]
                      : [(string)preview_of($from), (string)preview_of($to)];
    if ($pa !== '' && file_exists($pa) && !file_exists($pb)) { @mkdir(dirname($pb), 0777, true); @rename($pa, $pb); }
}
// One thing to a new place, as Finder does it: a rename, never over anything. Written down and put on the
// undo list the moment it happened; then its previews; then Search follows.
function move_one(string $from, string $to, string $note = '', bool $remember = true, bool $fromHold = false): int {
    global $undo, $saveUndo;
    if ($from === $to) return 0;
    if ($fromHold) {                                   // Undo of a removal: out of Recently Removed, back where it was
        if (!str_starts_with($from, rtrim(archive_dir(), '/') . '/' . HOLD . '/') || !in_archive($from)) throw new RuntimeException('Not in Recently Removed.');
        may_move(dirname($to) . '/x', $to);            // the place it goes back to is checked as any
    } else may_move($from, $to);
    if (!file_exists($from)) throw new RuntimeException(basename($from) . ' is not there any more.');
    $caseOnly = dirname($from) === dirname($to) && strtolower(basename($from)) === strtolower(basename($to));
    if (file_exists($to) && !$caseOnly) throw new RuntimeException('There is already something called “' . basename($to) . '” there.');
    if ($caseOnly) {                                   // Kite → kite: the Mac sees one name, so by way of another
        $tmp = dirname($to) . '/.rushes-rename-' . bin2hex(random_bytes(4));
        if (!@rename($from, $tmp) || !@rename($tmp, $to)) { @rename($tmp, $from); throw new RuntimeException('The drive did not let ' . basename($from) . ' be renamed.'); }
    } elseif (!@rename($from, $to)) throw new RuntimeException('The drive did not let ' . basename($from) . ' move (is it read-only for you?).');
    if ($remember) { $undo[] = ['op' => 'move', 'from' => $from, 'to' => $to, 'at' => time()]; $saveUndo(); }
    $pairs = files_under($from, $to);
    rec($pairs, $note);
    move_preview($from, $to, is_dir($to));
    follow_moves(array_map(fn($x) => [$x[0], $x[1]], $pairs));
    return count($pairs);
}
// Two folders of the same name become one: each file into its place in the other, never over a file; a
// file whose name is taken there stays where it was (said). Folders left empty are removed.
// The list of files is made first, so nothing moved is met again.
// ponytail: done here, file by file; a merge of tens of thousands of files would belong to the helper's queue
function merge_into(string $from, string $to): array {
    may_move($from, $to);
    $all = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $all[] = [$f->getPathname(), $f->isDir() && !$f->isLink()];
    $n = 0; $kept = []; $pairs = [];
    foreach ($all as [$p, $isDir]) {
        if ($isDir) { @rmdir($p); continue; }                          // only when empty: a kept file keeps its folder
        $q = $to . substr($p, strlen($from));
        if (file_exists($q)) { $kept[] = substr($p, strlen($from) + 1); continue; }
        @mkdir(dirname($q), 0777, true);
        if (!@rename($p, $q)) { $kept[] = substr($p, strlen($from) + 1); continue; }
        $pairs[] = [$p, $q, (int)@filesize($q)]; $n++;
        move_preview($p, $q, false);
    }
    @rmdir($from);
    rec($pairs, 'merged');
    follow_moves(array_map(fn($x) => [$x[0], $x[1]], $pairs));
    return [$n, $kept, $pairs];
}

$notes = [];
try {
    if ($op === 'move') {
        $items = json_decode((string)($_POST['items'] ?? '[]'), true) ?: [];
        $to = rtrim((string)($_POST['to'] ?? ''), '/');
        if (!in_archive($to) || !is_dir($to)) bail(400, 'Drop onto a folder of the archive. Drives kept where they are are only looked at here: nothing on them changes.');
        if (count($items) > 500) $notes[] = 'Only the first 500 were moved: select fewer at a time.';
        $done = []; $files = 0; $taken = []; $merged = [];
        foreach (array_slice($items, 0, 500) as $from) {
            $from = rtrim((string)$from, '/');
            try {
                if (dirname($from) === $to) continue;                                     // already there
                if (!in_archive($from)) { $notes[] = basename($from) . ' is on a drive kept where it is: copying it into the archive is done in Copying.'; continue; }
                $dest = "$to/" . basename($from);
                if (file_exists($dest)) {
                    if (!empty($_POST['merge']) && is_dir($from) && is_dir($dest)) {
                        [$n, $kept, $pairs] = merge_into($from, $dest); $files += $n;
                        $merged[] = ['name' => basename($from), 'n' => $n, 'kept' => array_slice($kept, 0, 50), 'kept_n' => count($kept)];
                        // Undo takes each file back where it was: the list of them, kept with the change
                        if ($pairs) { $undo[] = ['op' => 'merge', 'to' => $dest, 'pairs' => array_map(fn($x) => [$x[0], $x[1]], array_slice($pairs, 0, 50000)), 'at' => time()]; $saveUndo(); }
                    } else $taken[] = ['name' => basename($from), 'dir' => is_dir($from) && is_dir($dest)];
                    continue;
                }
                $files += move_one($from, $dest); $done[] = basename($from);
            } catch (RuntimeException $e) { $notes[] = $e->getMessage(); }            // the next one is still tried
        }
        if ($done || $merged) activity_add('changed', 'Moved ' . implode(', ', array_merge($done, array_column($merged, 'name'))) . ' into ' . basename($to) . ' (Tidy up)');
        if ($recordFailed) $notes[] = 'The record of these moves could not be written in _rushes/origin: relinking Premiere projects will not know them. Is the archive full or read-only?';
        out(['ok' => 'move', 'moved' => $done, 'files' => $files, 'taken' => $taken, 'merged' => $merged, 'notes' => $notes]);
    }
    if ($op === 'rename') {
        $item = rtrim((string)($_POST['item'] ?? ''), '/'); $name = (string)($_POST['name'] ?? '');
        if (!good_name($name) || (dirname($item) === $A && own_name($name)))
            bail(400, 'Not a name it can have here: no / or :, not starting with a dot' . (dirname($item) === $A ? ', and at the top not _ @ # or PROXIES (those are Rushes’ own)' : '') . '.');
        $n = move_one($item, dirname($item) . "/$name", 'renamed');
        activity_add('changed', 'Renamed ' . basename($item) . " to $name (Tidy up)");
        out(['ok' => 'rename', 'files' => $n, 'notes' => $recordFailed ? ['The record of this rename could not be written in _rushes/origin.'] : []]);
    }
    if ($op === 'mkdir') {
        $dir = rtrim((string)($_POST['dir'] ?? ''), '/'); $name = (string)($_POST['name'] ?? '');
        if (!in_archive("$dir/x") || !is_dir($dir) || own_name(top_of("$dir/$name"))) bail(400, 'New folders are made in the archive, not in Rushes’ own folders' . ($dir === $A ? ' (and at its top, not starting with _ @ #)' : '') . '.');
        if (!good_name($name)) bail(400, 'Not a name a folder can have here: no / or :, not starting with a dot.');
        if (file_exists("$dir/$name")) bail(409, "There is already something called “{$name}” there.");
        if (!@mkdir("$dir/$name")) bail(500, 'The drive did not let the folder be made.');
        $undo[] = ['op' => 'mkdir', 'dir' => "$dir/$name", 'at' => time()]; $saveUndo();
        out(['ok' => 'mkdir', 'path' => "$dir/$name"]);
    }
    if ($op === 'undo') {
        // the last change, put back; it leaves the list only once it is back
        $u = end($undo);
        if (!$u) bail(400, 'Nothing to undo here.');
        if ($u['op'] === 'mkdir') { if (!@rmdir($u['dir'])) bail(409, 'The new folder ' . basename($u['dir']) . ' has something in it now, so it stays.'); $said = 'Removed the new folder ' . basename($u['dir']); }
        elseif ($u['op'] === 'rmdir') { if (!@mkdir($u['dir'])) bail(409, 'Could not make ' . basename($u['dir']) . ' again.'); $said = basename($u['dir']) . ' is back (it was empty)'; }
        elseif ($u['op'] === 'remove') {
            try { move_one($u['to'], $u['from'], 'recovered', false, true); }
            catch (RuntimeException $e) { bail(409, 'Could not undo: ' . $e->getMessage() . ' It stays on the list.'); }
            $said = basename($u['from']) . ' is back from Recently Removed';
        }
        elseif ($u['op'] === 'merge') {
            $back = []; $stay = 0;
            foreach (array_reverse($u['pairs']) as [$a, $b]) {
                if (!is_file($b) || file_exists($a) || !in_archive($a) || !in_archive($b)) { $stay++; continue; }
                @mkdir(dirname($a), 0777, true);
                if (@rename($b, $a)) { $back[] = [$b, $a, (int)@filesize($a)]; move_preview($b, $a, false); } else $stay++;
            }
            rec($back, 'undone'); follow_moves(array_map(fn($x) => [$x[0], $x[1]], $back));
            $said = count($back) . ' files went back out of ' . basename($u['to']) . ($stay ? " ($stay could not: moved or changed since, or a file is in the way)" : '');
        } else {
            try { move_one($u['to'], $u['from'], 'undone', false); }
            catch (RuntimeException $e) { bail(409, 'Could not undo: ' . $e->getMessage() . ' It stays on the list.'); }
            $said = basename($u['to']) . ' is back in ' . basename(dirname($u['from']));
        }
        array_pop($undo); $saveUndo();
        activity_add('changed', "Undone in Tidy up: $said");
        out(['ok' => 'undo', 'said' => $said, 'left' => count($undo)]);
    }
    // Removing, as Finder's Trash: into Recently Removed on the archive, its folders kept, so it can come back
    // (Undo here); space comes back only with Delete All, asked twice. "check" first: how much, and how much of
    // it has no other copy Rushes knows of (a file of the same name and size elsewhere); empty files never count.
    if ($op === 'check-remove' || $op === 'remove') {
        $items = array_slice(json_decode((string)($_POST['items'] ?? '[]'), true) ?: [], 0, 500);
        $db = db(); $n = 0; $b = 0; $alone = 0; $eg = [];
        $other = $db->prepare('SELECT 1 FROM files WHERE name = ? AND bytes = ? AND (path < ? OR path >= ?) LIMIT 1');
        foreach ($items as $i => $it) {
            $it = rtrim((string)$it, '/'); $items[$i] = $it;
            if (!in_archive($it) || own_name(top_of($it))) bail(400, basename($it) . ' cannot be removed here: only things in the archive, never Rushes’ own folders.');
            $rows = is_file($it) ? [[$it, (int)@filesize($it)]] : array_map(fn($x) => [$x[0], $x[2]], files_under($it, $it));
            foreach ($rows as [$path, $bytes]) {
                $n++; $b += $bytes;
                if ($bytes > 0 && $op === 'check-remove') {
                    $other->bindValue(1, basename($path)); $other->bindValue(2, $bytes); $other->bindValue(3, "$it/"); $other->bindValue(4, "$it" . '0');
                    if (is_file($it)) { $other->bindValue(3, $it); $other->bindValue(4, $it . "\0"); }
                    if (!$other->execute()->fetchArray()) { $alone++; if (count($eg) < 5) $eg[] = basename($path); }
                    $other->reset();
                }
            }
        }
        if ($op === 'check-remove') out(['files' => $n, 'bytes' => $b, 'alone' => $alone, 'eg' => $eg]);
        $done = [];
        foreach ($items as $it) {
            try {
                if (is_dir($it) && !(new FilesystemIterator($it))->valid()) {          // an empty folder: nothing to keep
                    if (!@rmdir($it)) throw new RuntimeException('The drive did not let ' . basename($it) . ' be removed.');
                    $undo[] = ['op' => 'rmdir', 'dir' => $it, 'at' => time()]; $saveUndo(); $done[] = basename($it); continue;
                }
                $held = rtrim(archive_dir(), '/') . '/' . HOLD . '/' . substr($it, strlen(rtrim(archive_dir(), '/')) + 1);
                if (file_exists($held)) $held .= ' (' . date('Y-m-d His') . ')';      // removed before: both kept
                @mkdir(dirname($held), 0777, true);
                $pairs = files_under($it, $it);
                if (!@rename($it, $held)) throw new RuntimeException('The drive did not let ' . basename($it) . ' be moved to Recently Removed.');
                $undo[] = ['op' => 'remove', 'from' => $it, 'to' => $held, 'at' => time()]; $saveUndo();
                $moved = array_map(fn($x) => [$x[0], $held . substr($x[0], strlen($it)), $x[2]], is_file($held) ? [[$it, $held, (int)@filesize($held)]] : $pairs);
                // Delete All deletes what has a record of being put there (runner.py empty())
                @file_put_contents(web_dir() . '/removed-moves.tsv', implode('', array_map(fn($x) => "$x[0]\t$x[1]\n", $moved)), FILE_APPEND);
                @file_put_contents(web_dir() . '/removed-at.txt', time() . "\n");
                rec($moved, 'removed');
                follow_moves(array_map(fn($x) => [$x[0], $x[1]], $moved));       // in Search still, marked "in Recently Removed"
                $done[] = basename($it);
            } catch (RuntimeException $e) { $notes[] = $e->getMessage(); }
        }
        if ($done) activity_add('changed', 'Moved to Recently Removed: ' . implode(', ', $done) . ' (Tidy up)');
        out(['ok' => 'remove', 'removed' => $done, 'files' => $n, 'bytes' => $b, 'notes' => $notes]);
    }
    if ($op === 'reveal') {
        $item = rtrim((string)($_POST['item'] ?? ''), '/');
        if (!(on_mac() && PHP_OS_FAMILY === 'Darwin' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)))
            bail(400, 'Show in Finder works on the Mac Rushes runs on, opened from it.');
        if (!place($item) || !file_exists($item)) bail(404, 'That is not there now.');
        exec('open -R ' . escapeshellarg($item));
        out(['ok' => 'reveal']);
    }
} catch (RuntimeException $e) {
    bail(409, $e->getMessage());
}
bail(400, 'unknown op');
