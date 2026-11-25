<?php
// pulls.php — clips gathered for a job, kept in Rushes so the team shares them.
//
//   GET  ?list=1                       every pull, newest first
//   GET  ?p=<slug>                     one pull and its clips
//   GET  ?places=1                     where a pull can be copied to, and this Rushes' version
//   POST action=create name made_by    a new pull  → {slug}
//   POST action=request text           a pull from a request the Viewer wrote (an email's words, pasted whole) → {slug, n, missing}
//   POST action=add    p path          add a clip (a path search knows)
//   POST action=remove p rel           take one out
//   POST action=move   p rel dir=up|down
//   POST action=rename p name
//
// ponytail: no accounts and no password — everyone who can open Rushes is the
// team, and a pull is theirs to share. Every write is checked: a clip must be
// a file search knows, inside the archive; names are plain text.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/activity.php';
header('Content-Type: application/json');
db_init();
$db = db();

function out($x) { echo json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }
function bail(int $c, string $why) { http_response_code($c); out(['error' => $why]); }
function clean(string $s, int $max): string { return mb_substr(trim(preg_replace('/[\x00-\x1f]+/', ' ', $s)), 0, $max); }
// A pull's name as the start of its link: small letters and digits, a - for anything else. Accented letters lose
// their accent ("Música" → musica) by a list of its own, so this needs no library for it (it used iconv, which
// Rushes' own PHP no longer carries: one part less to ship and to keep patched).
function slug_base(string $name): string {
    static $plain = ['á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
                     'ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ø'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c','ß'=>'ss','æ'=>'ae','œ'=>'oe'];
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtr(mb_strtolower($name), $plain)), '-') ?: 'pull';
}
function pull_by_slug(string $slug) {
    $st = db()->prepare('SELECT * FROM pulls WHERE slug = ?');
    $st->bindValue(1, $slug);
    return $st->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
}
function touch_pull(int $id) { db()->exec('UPDATE pulls SET updated = ' . time() . ' WHERE id = ' . $id); }

$root = archive_dir() . '/';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['list'])) {
        $r = $db->query('SELECT p.slug, p.name, p.made_by, p.created, p.updated,
                                COUNT(i.rel) n, COALESCE(SUM(i.bytes),0) b
                         FROM pulls p LEFT JOIN pull_items i ON i.pull_id = p.id
                         GROUP BY p.id ORDER BY p.updated DESC LIMIT 200');
        $all = [];
        while ($x = $r->fetchArray(SQLITE3_ASSOC)) $all[] = $x;
        out(['pulls' => $all]);
    }
    // Copy to work on (0.14): where a pull can be copied to. Only where the Rushes app itself runs on a Mac:
    // its runner does the copying, onto a drive that Mac sees. ?places=1 asks for these alone (the Premiere panel).
    $places = [];
    if (can_preview_drives()) {
        foreach ((helper_volumes()['vols'] ?? []) as $v)
            if (empty($v['archive']) && empty($v['card'])) $places[] = ['to' => $v['path'], 'name' => $v['name'], 'free' => (int)$v['free']];
        $places[] = ['to' => 'desktop', 'name' => 'The Desktop of the Mac Rushes runs on', 'free' => 0];
    }
    if (isset($_GET['places'])) out(['places' => $places, 'version' => trim((string)@file_get_contents(__DIR__ . '/../VERSION'))]);
    $p = pull_by_slug((string)($_GET['p'] ?? ''));
    if (!$p) bail(404, 'There is no pull with that link.');
    $st = $db->prepare('SELECT i.rel, i.name, i.kind, i.bytes, f.path IS NOT NULL AS here
                        FROM pull_items i LEFT JOIN files f ON f.path = ? || i.rel
                        WHERE i.pull_id = ? ORDER BY i.pos, i.added');
    $st->bindValue(1, $root); $st->bindValue(2, $p['id']);
    $r = $st->execute(); $items = [];
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) { $x['here'] = (bool)$x['here']; $items[] = $x; }
    // how a copy asked for is going (runner.py writes it)
    $copy = json_decode((string)@file_get_contents(web_dir() . '/pull-copy-' . $p['slug'] . '.json'), true) ?: null;
    out(['pull' => $p, 'items' => $items, 'places' => $places, 'copy' => $copy]);
}

$act = (string)($_POST['action'] ?? '');

if ($act === 'create') {
    $name = clean((string)($_POST['name'] ?? ''), 80);
    if ($name === '') bail(400, 'Give the pull a name.');
    // A short readable link: the name, then a few random letters so two pulls
    // called "Council" never collide.
    $base = slug_base($name);
    $slug = substr($base, 0, 40) . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
    $by = clean((string)($_POST['made_by'] ?? ''), 60) ?: activity_who();     // the name this browser was given, if none is typed
    $st = $db->prepare('INSERT INTO pulls (slug, name, made_by, created, updated) VALUES (?,?,?,?,?)');
    foreach ([$slug, $name, $by, time(), time()] as $i => $v) $st->bindValue($i + 1, $v);
    $st->execute();
    activity_add('out', "Started the pull “{$name}”", $by);
    out(['slug' => $slug, 'name' => $name]);
}

// A request from the Viewer (db/viewer.js request()): someone without Rushes gathered clips there and sent them in an
// email. The email is pasted whole; only what is between the two lines of = is read, so a greeting, a signature
// and the mail program's own marks (> before a quoted line, a long line folded) do no harm. Each clip is named by
// its number in the catalogue and its name: both must agree, or the clip is said to be missing, never guessed.
if ($act === 'request') {
    $text = preg_replace('/^[ \t>|]+/m', '', str_replace(["\r\n", "\r", "\u{a0}"], ["\n", "\n", ' '], (string)($_POST['text'] ?? '')));
    if (!preg_match('/=+\s*RUSHES REQUEST\s*=+(.*?)=+\s*END OF REQUEST\s*=+/s', $text, $m))
        bail(400, 'No request found in that text. Copy the whole email, from the line “===== RUSHES REQUEST =====” to “===== END OF REQUEST =====”.');
    $body = $m[1]; $head = [];
    foreach (['From', 'For', 'Pull', 'Note', 'Clips'] as $k)
        if (preg_match('/^' . $k . ':[ \t]*(.*)$/m', $body, $x)) $head[$k] = clean($x[1], 200);
    $flat = preg_replace('/\s+/', ' ', $body);                 // a name folded over two lines is one name again
    preg_match_all('/(?<![\w#])(\d+)\. (.+?) \[#(\d+)\]/u', $flat, $clips, PREG_SET_ORDER);
    $said = (int)($head['Clips'] ?? 0);
    if (!$clips || $said !== count($clips))
        bail(400, 'The request says ' . $said . ' clip' . ($said === 1 ? '' : 's') . ', and ' . count($clips) . ' could be read: part of it is missing. Copy the whole email again.');
    $same = fn(string $a, string $b) => mb_strtolower(preg_replace('/\s+/', '', $a)) === mb_strtolower(preg_replace('/\s+/', '', $b));
    $st = $db->prepare('SELECT path, name, kind, bytes FROM files WHERE id = ?'); $found = []; $missing = [];
    foreach ($clips as $c) {
        $st->bindValue(1, (int)$c[3]); $f = $st->execute()->fetchArray(SQLITE3_ASSOC); $st->reset();
        if ($f && str_starts_with($f['path'], $root) && $same($f['name'], $c[2])) $found[] = $f; else $missing[] = clean($c[2], 200);
    }
    if (!$found) bail(400, 'None of its ' . count($clips) . ' clips is in this catalogue any more (they may have been moved or renamed since the request was written): ' . implode(', ', array_slice($missing, 0, 5)) . '.');
    $from = $head['From'] ?? ''; $what = ($head['Pull'] ?? '') ?: 'clips';
    $name = clean('Request' . ($from !== '' ? ' from ' . $from : '') . ': ' . $what, 80);
    $base = slug_base($name);
    $slug = substr($base, 0, 40) . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
    $db->exec('BEGIN');
    $in = $db->prepare('INSERT INTO pulls (slug, name, made_by, created, updated) VALUES (?,?,?,?,?)');
    foreach ([$slug, $name, clean($from, 60), time(), time()] as $i => $v) $in->bindValue($i + 1, $v);
    $in->execute(); $id = $db->lastInsertRowID();
    $it = $db->prepare('INSERT OR IGNORE INTO pull_items (pull_id, rel, name, kind, bytes, pos, added) VALUES (?,?,?,?,?,?,?)');
    foreach ($found as $n => $f) {
        foreach ([$id, substr($f['path'], strlen($root)), $f['name'], $f['kind'], $f['bytes'], $n + 1, time()] as $i => $v) $it->bindValue($i + 1, $v);
        $it->execute(); $it->reset();
    }
    $db->exec('COMMIT');
    activity_add('out', "Made the pull “{$name}” from a request" . (($head['Note'] ?? '') !== '' ? ' (' . $head['Note'] . ')' : '') . ': ' . count($found) . ' clip' . (count($found) === 1 ? '' : 's')
        . ($missing ? ', ' . count($missing) . ' not found' : ''), activity_who());
    out(['slug' => $slug, 'name' => $name, 'n' => count($found), 'missing' => $missing, 'from' => $from, 'note' => $head['Note'] ?? '']);
}

$p = pull_by_slug((string)($_POST['p'] ?? ''));
if (!$p) bail(404, 'There is no pull with that link.');

if ($act === 'add') {
    // Only a file search already knows — the path comes from a search result,
    // and is checked against the index rather than trusted.
    $path = (string)($_POST['path'] ?? '');
    $st = $db->prepare('SELECT name, kind, bytes FROM files WHERE path = ?');
    $st->bindValue(1, $path);
    $f = $st->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$f || !str_starts_with($path, $root)) bail(400, 'Search does not know that file.');
    $rel = substr($path, strlen($root));
    $pos = (int)$db->querySingle('SELECT COALESCE(MAX(pos),0) + 1 FROM pull_items WHERE pull_id = ' . (int)$p['id']);
    $st = $db->prepare('INSERT OR IGNORE INTO pull_items (pull_id, rel, name, kind, bytes, pos, added) VALUES (?,?,?,?,?,?,?)');
    foreach ([$p['id'], $rel, $f['name'], $f['kind'], $f['bytes'], $pos, time()] as $i => $v) $st->bindValue($i + 1, $v);
    $st->execute(); touch_pull($p['id']);
} elseif ($act === 'remove') {
    $st = $db->prepare('DELETE FROM pull_items WHERE pull_id = ? AND rel = ?');
    $st->bindValue(1, $p['id']); $st->bindValue(2, (string)($_POST['rel'] ?? ''));
    $st->execute(); touch_pull($p['id']);
} elseif ($act === 'move') {
    // Swap with the neighbour above or below. Order is what a timeline will
    // follow once there are timelines.
    $r = $db->query('SELECT rel, pos FROM pull_items WHERE pull_id = ' . (int)$p['id'] . ' ORDER BY pos, added');
    $list = []; while ($x = $r->fetchArray(SQLITE3_ASSOC)) $list[] = $x['rel'];
    $i = array_search((string)($_POST['rel'] ?? ''), $list, true);
    $j = $i === false ? false : (($_POST['dir'] ?? '') === 'up' ? $i - 1 : $i + 1);
    if ($i !== false && $j >= 0 && $j < count($list)) {
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        $st = $db->prepare('UPDATE pull_items SET pos = ? WHERE pull_id = ? AND rel = ?');
        foreach ($list as $k => $rel) { $st->bindValue(1, $k + 1); $st->bindValue(2, $p['id']); $st->bindValue(3, $rel); $st->execute(); $st->reset(); }
        touch_pull($p['id']);
    }
} elseif ($act === 'copy' || $act === 'copy-stop') {
    // Copy the pull's clips to a place to work from (a faster drive than the archive). Copies only: the originals
    // are read, never moved. The Mac's runner does it (runner.py, pull_copy) and writes how it goes beside the list.
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', $_SERVER['SERVER_ADDR'] ?? '-'], true);
    require_once __DIR__ . '/auth.php';
    if (!$local && !may_act()) bail(403, 'Copying is asked for on the Mac Rushes runs on, or signed in to Manage.');
    if (!can_preview_drives()) bail(400, 'Copying a pull is done by the Rushes app on a Mac; this Rushes has no such helper.');
    $q = web_dir() . '/queue'; if (!is_dir($q)) @mkdir($q, 0777, true);
    $slug = $p['slug'];
    if ($act === 'copy-stop') {
        if (@file_put_contents("$q/" . date('Ymd-His') . '-pullstop.job', "ACTION=pull-copy-stop\nSLUG=$slug\n") === false) bail(500, 'Could not ask: the queue folder is not writable.');
        out(['ok' => 'stop asked']);
    }
    $to = (string)($_POST['to'] ?? ''); $ok = $to === 'desktop';
    foreach ((helper_volumes()['vols'] ?? []) as $v) if ($v['path'] === $to && empty($v['archive']) && empty($v['card'])) $ok = true;
    if (!$ok) bail(400, 'Pick one of the drives this Mac sees now.');
    $st = $db->prepare('SELECT i.rel, i.bytes FROM pull_items i JOIN files f ON f.path = ? || i.rel WHERE i.pull_id = ? ORDER BY i.pos, i.added');
    $st->bindValue(1, $root); $st->bindValue(2, $p['id']); $r = $st->execute(); $list = ''; $n = 0; $b = 0;
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) { if (strpbrk($x['rel'], "\n\r\t") === false) { $list .= $root . $x['rel'] . "\n"; $n++; $b += (int)$x['bytes']; } }
    if (!$n) bail(400, 'This pull has no clips that are in the archive.');
    $folder = trim(preg_replace('/[^\p{L}\p{N} ._-]+/u', ' ', $p['name'])) ?: $slug;
    if (@file_put_contents(web_dir() . "/pull-copy-$slug.txt", $list) === false
        || @file_put_contents(web_dir() . "/pull-copy-$slug.json", json_encode(['state' => 'asked', 'n' => 0, 'total' => $n, 'bytes' => 0, 'total_bytes' => $b, 'at' => time()])) === false
        || @file_put_contents("$q/" . date('Ymd-His') . '-pullcopy.job', "ACTION=pull-copy\nSLUG=$slug\nTO=$to\nNAME=$folder\n") === false)
        bail(500, 'Could not ask: the web folder is not writable.');
    activity_add('out', "Copying the pull “{$p['name']}” ($n clips) to work on", activity_who());
    out(['ok' => 'asked', 'n' => $n, 'b' => $b]);
} elseif ($act === 'rename') {
    $name = clean((string)($_POST['name'] ?? ''), 80);
    if ($name === '') bail(400, 'Give the pull a name.');
    $st = $db->prepare('UPDATE pulls SET name = ?, updated = ? WHERE id = ?');
    $st->bindValue(1, $name); $st->bindValue(2, time()); $st->bindValue(3, $p['id']); $st->execute();
} else {
    bail(400, 'Unknown request.');
}

$n = $db->querySingle('SELECT COUNT(*) n, COALESCE(SUM(bytes),0) b FROM pull_items WHERE pull_id = ' . (int)$p['id'], true);
out(['ok' => true, 'slug' => $p['slug'], 'n' => (int)$n['n'], 'b' => (int)$n['b']]);
