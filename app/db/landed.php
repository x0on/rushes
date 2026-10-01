<?php
// landed.php — the helper says which files it just copied, and they become
// searchable at once instead of at the next full rebuild. A card goes in, and
// a minute later someone else can find it.
//
// ponytail: no password, like report.php — the helper has none. It cannot add
// anything that is not really there: each path must sit inside the archive,
// on disk, at exactly the size the helper says. A forged list adds nothing.
require_once __DIR__ . '/schema.php';
helper_gate();                          // only the paired helper's word is taken (pair.php)
header('Content-Type: application/json');

$raw = (string)($_POST['files'] ?? '');
if (strlen($raw) > 4000000) { http_response_code(413); echo '{"error":"send fewer at a time"}'; exit; }

db_init();
$db    = db();
$db->enableExceptions(true);
$from  = helper_archive();          // how the helper names the archive
$to    = archive_dir();             // how this machine does
$shelf = shelf_dir();
$ins = $db->prepare('INSERT INTO files (path,name,ext,kind,bytes,dept,year,event,why,seen_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?) ON CONFLICT(path) DO UPDATE SET
                     bytes=excluded.bytes, dept=excluded.dept, seen_at=excluded.seen_at');
$accepted = []; $added = 0; $refused = 0; $now = time();

$db->exec('BEGIN IMMEDIATE');
foreach (explode("\n", $raw) as $l) {
    if ($l === '') continue;
    [$p, $size] = array_pad(explode("\t", $l, 2), 2, '');
    // The helper's name for the file, turned into this machine's — including a
    // Windows helper's backslashes.
    if ($from === '' || !str_starts_with(str_replace('\\', '/', $p), rtrim(str_replace('\\', '/', $from), '/') . '/') || !ctype_digit($size)) { $refused++; continue; }
    $local = $to . str_replace('\\', '/', substr($p, strlen($from)));
    if (!str_starts_with($local, $to . '/') || str_contains($local, '/../')
        || !str_starts_with((string)realpath($local), (string)realpath($to) . '/')
        || !is_file($local) || filesize($local) !== (int)$size) { $refused++; continue; }

    if (is_system_junk($local) || str_contains($local, '/_rushes/') || str_ends_with($local, '.part')) { $accepted[] = $p; continue; }
    $c = classify($local);
    // A card brought in through Ingest landed under its department's shelf, so
    // here the department is known, not guessed.
    $dept = null;
    if (str_starts_with($local, $shelf . '/')) {
        $f = explode('/', substr($local, strlen($shelf) + 1))[0];
        $dept = dept_of_folder($f) ?? $f;       // "Parks & Recreation", not PARKS
    }
    foreach ([$local, $c['name'], $c['ext'], $c['kind'], (int)$size, $dept, $c['year'], $c['event'],
              'added by the helper as it landed', $now] as $i => $v) {
        $ins->bindValue($i + 1, $v);
    }
    $ins->execute(); $ins->reset();
    $added++; $accepted[] = $p;
}
meta_set('files_imported', (string)$db->querySingle('SELECT COUNT(*) FROM files'));
if ($added) meta_set('search_updated_at', (string)$now);
$db->exec('COMMIT');
echo json_encode(['added' => $added, 'refused' => $refused, 'accepted' => $accepted]);
