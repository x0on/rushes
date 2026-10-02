<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// copies.php — how many copies of each file exist, as git-annex counts them.
// The archive is one. The helper looks, once a week, whether the original each
// file came from is still where it was, at the same size, and says so here:
//
//   POST copies="<archive path>\t<place>\t<1|0>\n…"   what it saw
//   POST done=1                                       the week's look is complete:
//                                                     the summary Overview shows is redone
//
// ponytail: no password, like landed.php — the helper has none. What it can
// change is only this count; nothing is moved or deleted because of it.
require_once __DIR__ . '/schema.php';
helper_gate();                          // only the paired helper's word is taken (pair.php)
header('Content-Type: application/json');

$raw = (string)($_POST['copies'] ?? '');
if (strlen($raw) > 4000000) { http_response_code(413); echo '{"error":"send fewer at a time"}'; exit; }

db_init();
$db   = db();
$from = helper_archive(); $to = archive_dir(); $now = time();
$find = $db->prepare('SELECT id FROM files WHERE path = ?');
$put  = $db->prepare('INSERT OR REPLACE INTO copies (file_id, place, present, checked) VALUES (?,?,?,?)');
$n = 0; $unknown = 0;
$db->exec('BEGIN');
foreach (explode("\n", $raw) as $l) {
    [$p, $place, $ok] = array_pad(explode("\t", $l, 3), 3, '');
    if ($p === '' || $place === '' || $from === '' || !str_starts_with($p, $from)) continue;
    $find->bindValue(1, $to . str_replace('\\', '/', substr($p, strlen($from))));
    $r = $find->execute()->fetchArray(SQLITE3_NUM); $find->reset();
    if (!$r) { $unknown++; continue; }                 // not in search yet: counted on the next look
    foreach ([$r[0], mb_substr($place, 0, 80), $ok === '1' ? 1 : 0, $now] as $i => $v) $put->bindValue($i + 1, $v);
    $put->execute(); $put->reset(); $n++;
}
$db->exec('COMMIT');

if (!empty($_POST['done'])) {
    // For Overview: how much is kept twice, how much only here, and where.
    // Rushes' own folders and proxies are not footage, so they are not counted.
    $root = SQLite3::escapeString(rtrim($to, '/'));
    $not = "path NOT LIKE '$root/_rushes/%' AND path NOT LIKE '$root/PROXIES/%' AND path NOT LIKE '$root/_duplicates/%'
            AND path NOT LIKE '%/@Recycle/%' AND path NOT LIKE '%/ascmhl/%'";
    $q = $db->query("SELECT CASE WHEN c.n > 0 THEN 'twice' WHEN c.n = 0 THEN 'lost' ELSE 'never' END s,
                            COUNT(*), COALESCE(SUM(f.bytes), 0), COALESCE(f.dept, '')
                     FROM files f LEFT JOIN (SELECT file_id, SUM(present) n FROM copies GROUP BY file_id) c ON c.file_id = f.id
                     WHERE $not GROUP BY s, f.dept");
    $sum = ['at' => $now, 'twice' => [0, 0], 'lost' => [0, 0], 'never' => [0, 0], 'depts' => []];
    while ($r = $q->fetchArray(SQLITE3_NUM)) {
        $sum[$r[0]][0] += $r[1]; $sum[$r[0]][1] += $r[2];
        if ($r[0] !== 'twice') $sum['depts'][$r[3] ?: 'not sorted yet'] = ($sum['depts'][$r[3] ?: 'not sorted yet'] ?? 0) + $r[2];
    }
    arsort($sum['depts']); $sum['depts'] = array_slice($sum['depts'], 0, 6, true);
    $sum['places'] = [];
    $q = $db->query('SELECT place, SUM(present), COUNT(*) FROM copies GROUP BY place');
    while ($r = $q->fetchArray(SQLITE3_NUM)) $sum['places'][$r[0]] = ['there' => $r[1], 'looked' => $r[2]];
    file_put_contents(web_dir() . '/copies-summary.json', json_encode($sum));
}
echo json_encode(['counted' => $n, 'not_in_search' => $unknown]);
