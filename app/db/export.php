<?php
// export.php — everything Rushes knows, in open formats, to take anywhere
// (HOW-IT-WORKS.md → Taking everything with you). Behind the password: these
// are whole lists. Each is streamed as it is read, so a big archive does not
// fill the server's memory.
//
//   ?what=files     CSV: every file in the catalogue, with what each one is
//   ?what=moments   CSV: every described shot and line spoken, with its time
//   ?what=pulls     JSON: every pull, with its clips, in order
//   ?what=copies    CSV: where else each file exists, as last counted
//
// What already lives on the archive as open files is not repeated here: the
// descriptions (_rushes/analysis, JSON), the records of every copy and move
// (_rushes/origin, TSV) and the copy proofs (ascmhl/, ASC MHL).
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/analysis.php';
if (!signed_in()) { http_response_code(403); header('Content-Type: text/plain'); exit("Sign in to Manage first.\n"); }
db_init(); analysis_init(); $db = db();

$what = (string)($_GET['what'] ?? '');
$day  = date('Y-m-d');
$name = preg_replace('/[^\w-]+/', '-', strtolower(settings()['name'] ?? 'rushes'));

// One CSV row at a time, straight out. Text that a spreadsheet would read as a
// formula (=, +, -, @ first) is written with a quote mark before it.
function csv_out(array $head, SQLite3Result $r): void {
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                          // so a spreadsheet reads accents right
    fputcsv($out, $head, ',', '"', '');
    while ($row = $r->fetchArray(SQLITE3_NUM)) {
        foreach ($row as &$v) if (is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false) $v = "'" . $v;
        fputcsv($out, $row, ',', '"', '');
    }
    fclose($out);
}
$send = function (string $file, string $type) {
    header("Content-Type: $type");
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Cache-Control: no-store');
};

if ($what === 'files') {
    $send("$name-files-$day.csv", 'text/csv; charset=utf-8');
    csv_out(['path', 'name', 'kind', 'bytes', 'department', 'year', 'event', 'width', 'height', 'fps', 'codec',
             'duration_s', 'recorded', 'timecode', 'reel', 'camera', 'proxy_made'],
        $db->query('SELECT f.path, f.name, f.kind, f.bytes, f.dept, f.year, f.event, m.width, m.height, m.fps, m.codec,
                           m.duration, m.recorded, m.timecode, m.reel, m.camera,
                           CASE WHEN m.proxy_at THEN strftime(\'%Y-%m-%dT%H:%M:%S\', m.proxy_at, \'unixepoch\') END
                    FROM files f LEFT JOIN media m ON m.file_id = f.id ORDER BY f.path'));
    exit;
}
if ($what === 'moments') {
    $send("$name-described-$day.csv", 'text/csv; charset=utf-8');
    csv_out(['path', 'kind', 'shot', 'start_s', 'end_s', 'what', 'on_screen', 'themes', 'tags', 'shot_size', 'people',
             'ages', 'light', 'part_of_day', 'mood', 'language', 'model', 'fingerprint'],
        $db->query('SELECT path, kind, shot, start_s, end_s, what, on_screen, themes, tags, shot_size, people, ages, light,
                           part_of_day, mood, language, model, fp FROM moments ORDER BY path, start_s'));
    exit;
}
if ($what === 'copies') {
    $send("$name-copies-$day.csv", 'text/csv; charset=utf-8');
    csv_out(['path', 'place', 'there', 'last_looked'],
        $db->query('SELECT f.path, c.place, c.present, strftime(\'%Y-%m-%dT%H:%M:%S\', c.checked, \'unixepoch\')
                    FROM copies c JOIN files f ON f.id = c.file_id ORDER BY f.path, c.place'));
    exit;
}
if ($what === 'pulls') {
    $send("$name-pulls-$day.json", 'application/json; charset=utf-8');
    $items = $db->prepare('SELECT rel, name, kind, bytes FROM pull_items WHERE pull_id = ? ORDER BY pos, added');
    $r = $db->query('SELECT id, slug, name, made_by, created, updated FROM pulls ORDER BY created');
    echo "{\n  \"archive\": " . json_encode(archive_dir(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
       . ",\n  \"note\": \"Each clip's path is inside the archive (rel).\",\n  \"pulls\": [";
    $first = true;
    while ($p = $r->fetchArray(SQLITE3_ASSOC)) {
        $items->bindValue(1, $p['id']); $ri = $items->execute(); $clips = [];
        while ($c = $ri->fetchArray(SQLITE3_ASSOC)) $clips[] = $c;
        $items->reset();
        unset($p['id']); $p['clips'] = $clips;
        echo ($first ? "\n    " : ",\n    ") . json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $first = false;
    }
    echo "\n  ]\n}\n";
    exit;
}
http_response_code(400); header('Content-Type: text/plain');
echo "Ask for files, moments, copies or pulls.\n";
