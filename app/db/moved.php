<?php
// moved.php — the helper says which files a tidy-up (or its undo) moved, and
// search and every pull follow them there. The row keeps its id, so whatever
// was learned about the file (what the model saw in it) stays with it.
//
// ponytail: no password, like landed.php — the helper has none. It can only
// state what is true on disk: the old place empty, the new place holding a
// file, both inside the archive. A forged list changes nothing.
require_once __DIR__ . '/schema.php';
header('Content-Type: application/json');

$raw = (string)($_POST['moves'] ?? '');
if (strlen($raw) > 4000000) { http_response_code(413); echo '{"error":"send fewer at a time"}'; exit; }

db_init();
$db    = db();
$from  = helper_archive();
$to    = archive_dir();
$shelf = shelf_dir();
$local = function (string $p) use ($from, $to): ?string {
    if ($from === '' || !str_starts_with($p, $from)) return null;
    $l = $to . str_replace('\\', '/', substr($p, strlen($from)));
    return (str_starts_with($l, $to . '/') && !str_contains($l, '/../')) ? $l : null;
};
$find = $db->prepare('SELECT id FROM files WHERE path = ?');
$move = $db->prepare('UPDATE files SET path=?, dept=?, year=?, event=?, why=?, seen_at=? WHERE id=?');
$add  = $db->prepare('INSERT OR REPLACE INTO files (path,name,ext,kind,bytes,dept,year,event,why,seen_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?)');
$pull = $db->prepare('UPDATE OR IGNORE pull_items SET rel = ? WHERE rel = ?');
$bind = function ($st, array $v) { foreach ($v as $i => $x) $st->bindValue($i + 1, $x); $r = $st->execute(); $st->reset(); return $r; };

$updated = 0; $refused = 0; $now = time();
$db->exec('BEGIN');
foreach (explode("\n", $raw) as $l) {
    if ($l === '') continue;
    [$a, $b] = array_pad(explode("\t", $l, 2), 2, '');
    $la = $local($a); $lb = $local($b);
    if (!$la || !$lb || file_exists($la) || !is_file($lb)) { $refused++; continue; }

    $c = classify($lb);
    $dept = null;                            // on the shelf, the department is known, not guessed
    if (str_starts_with($lb, $shelf . '/')) {
        $f = explode('/', substr($lb, strlen($shelf) + 1))[0];
        $dept = dept_of_folder($f) ?? $f;
    }
    $find->bindValue(1, $la);
    $row = $find->execute()->fetchArray(SQLITE3_ASSOC);
    $find->reset();
    if ($row) {
        $db->exec('DELETE FROM files WHERE path = ' . "'" . SQLite3::escapeString($lb) . "'");   // a stale row there from an old rebuild
        $bind($move, [$lb, $dept, $c['year'], $c['event'], 'moved by a tidy-up', $now, $row['id']]);
    } else {
        $bind($add, [$lb, $c['name'], $c['ext'], $c['kind'], filesize($lb), $dept, $c['year'], $c['event'],
                     'moved by a tidy-up', $now]);
    }
    $bind($pull, [substr($lb, strlen($to) + 1), substr($la, strlen($to) + 1)]);
    $updated++;
}
$db->exec('COMMIT');
echo json_encode(['updated' => $updated, 'refused' => $refused]);
