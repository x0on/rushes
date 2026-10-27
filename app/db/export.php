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
//   ?what=folder&path=/abs/folder   JSON: one folder's clips (folders inside it too), each with its
//                   duration and every shot's start and end, in seconds and timecode, with what was
//                   described. One clip per line, so the page counts them as they arrive.
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
             'ages', 'light', 'part_of_day', 'mood', 'language', 'model', 'fingerprint', 'composition'],
        $db->query("SELECT path, kind, shot, start_s, end_s, what, on_screen, themes, tags, shot_size, people, ages, light,
                           part_of_day, mood, language, model, fp, comp FROM moments ORDER BY path, start_s"));
    exit;
}
// A frame rate as its exact fraction (60000/1001, not 59.94): the catalogue keeps it rounded, so it is
// matched to the standard rates; anything else is kept to the thousandth. [num, den] or null.
function fps_exact($fps): ?array {
    if (!$fps || $fps <= 0) return null;
    foreach ([24, 25, 30, 48, 50, 60, 96, 100, 120] as $n) {
        if (abs($fps - $n * 1000 / 1001) < 0.005) return [$n * 1000, 1001];
        if (abs($fps - $n) < 0.005) return [$n, 1];
    }
    return [(int)round($fps * 1000), 1000];
}
// Is this clip's timecode drop-frame? What the file says: a ; before the frames is drop-frame
// (29.97 and 59.94 only); otherwise non-drop. With no timecode, it is counted non-drop from zero.
function tc_mode(?string $start, ?array $r): ?string {
    if (!$r) return null;
    return $start && str_contains($start, ';') && $r[1] === 1001 && in_array($r[0], [30000, 60000], true) ? 'DF' : 'NDF';
}
// A time in a clip as timecode, from the clip's own starting timecode (what an editing app shows) or
// from 00:00:00:00; drop-frame counted as drop-frame. Seconds stay the main value: this is for reading.
function tc_at(float $s, ?string $start, ?array $r, ?string $mode): ?string {
    if (!$r) return null;
    $n = (int)round($r[0] / $r[1]); $D = $mode === 'DF' ? intdiv($n, 15) : 0;    // 2 frames a minute at 30, 4 at 60
    $f = (int)round($s * $r[0] / $r[1]);                                       // the frame, from the exact rate
    if ($start && preg_match('/^(\d+)[:;.](\d+)[:;.](\d+)[:;.](\d+)$/', trim($start), $m)) {
        $mins = (int)$m[1] * 60 + (int)$m[2];
        $f += (($mins * 60) + (int)$m[3]) * $n + (int)$m[4] - $D * ($mins - intdiv($mins, 10));
    }
    if ($D) {                                                                  // frame count → drop-frame labels
        $ten = $n * 600 - 9 * $D; $min = $n * 60 - $D;
        $d = intdiv($f, $ten); $m = $f % $ten;
        $f += 9 * $D * $d + ($m > $D ? $D * intdiv($m - $D, $min) : 0);
    }
    $t = intdiv($f, $n);
    return sprintf('%02d:%02d:%02d%s%02d', intdiv($t, 3600) % 24, intdiv($t, 60) % 60, $t % 60, $D ? ';' : ':', $f % $n);
}
function export_root_ok(string $p): bool {
    if ($p === '' || $p[0] !== '/' || preg_match('#/\.\.?(/|$)|[\x00-\x1f]#', $p)) return false;
    $A = rtrim(archive_dir(), '/');
    if ($p === $A || str_starts_with($p, "$A/")) return true;
    foreach (drives_seen() as $d) { $r = rtrim($d['path'], '/'); if ($r !== '' && ($p === $r || str_starts_with($p, "$r/"))) return true; }
    return false;
}
if ($what === 'folder') {
    $dir = rtrim((string)($_GET['path'] ?? ''), '/');
    if (!export_root_ok($dir)) { http_response_code(400); header('Content-Type: text/plain'); exit("Pick a folder in the archive or on a drive Rushes knows.\n"); }
    // everything under it, by a range (no LIKE, so _ and % in names mean themselves): '0' comes right after '/'
    $in = "path >= :a AND path < :b"; $bind = function ($q) use ($dir) { $q->bindValue(':a', "$dir/"); $q->bindValue(':b', "{$dir}0"); return $q; };
    $c = $bind($db->prepare("SELECT COUNT(*) FROM files WHERE $in AND kind IN ('video','image','audio')"))->execute()->fetchArray(SQLITE3_NUM)[0];
    $send(preg_replace('/[\/\\\\:"\x00-\x1f]+/', '-', basename($dir)) . " descriptions.json", 'application/json; charset=utf-8');
    while (ob_get_level()) ob_end_flush();
    $J = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    echo '{"folder":', json_encode($dir, $J), ',"exported":', json_encode(date('c')), ',"clips":', (int)$c,
         ',"times":"start_s and end_s (seconds from the start of the clip) are the exact values: frame = round(seconds x fps). fps is an exact fraction. start_tc and end_tc are for reading: timecode from the clip\'s own starting timecode, or from 00:00:00:00 when it has none, drop-frame (;) or not as timecode_mode says",'
         . '"composition":"per shot, when described with version 4: subject (what the eye goes to), tags (worked out by geometry from the marks), judged (what the model saw: silhouette, symmetry, frame within a frame…), marks (subject box, eyes, horizon and lines as fractions of the frame, x from the left, y from the top; facing)",', "\n\"files\":[\n";
    $files = $bind($db->prepare("SELECT f.path, f.kind, m.duration, m.fps, m.timecode FROM files f LEFT JOIN media m ON m.file_id = f.id
                                 WHERE f.$in AND f.kind IN ('video','image','audio') ORDER BY f.path"))->execute();
    $mo = $db->prepare('SELECT kind, shot, start_s, end_s, what, on_screen, themes, tags, shot_size, people, mood, language, comp
                        FROM moments WHERE path = ? ORDER BY kind, start_s');
    $list = fn($v) => $v === null || $v === '' ? [] : explode(' · ', $v);
    $i = 0;
    while ($f = $files->fetchArray(SQLITE3_ASSOC)) {
        $mo->bindValue(1, $f['path']); $r = $mo->execute(); $shots = []; $speech = []; $failed = 0;
        $rate = fps_exact($f['fps']); $mode = tc_mode($f['timecode'], $rate);
        while ($m = $r->fetchArray(SQLITE3_ASSOC)) {
            $t = ['start_s' => round((float)$m['start_s'], 3), 'end_s' => round((float)$m['end_s'], 3),
                  'start_tc' => tc_at((float)$m['start_s'], $f['timecode'], $rate, $mode), 'end_tc' => tc_at((float)$m['end_s'], $f['timecode'], $rate, $mode)];
            if ($m['kind'] === 'shot')
                $shots[] = ['shot' => (int)$m['shot']] + $t + ['description' => $m['what'], 'tags' => $list($m['tags']), 'themes' => $list($m['themes']),
                    'text_on_screen' => $list($m['on_screen']), 'shot_size' => $m['shot_size'], 'people' => $m['people'], 'mood' => $m['mood']]
                    + ($m['comp'] ? ['composition' => json_decode($m['comp'], true)] : []);
            elseif ($m['kind'] === 'speech') $speech[] = $t + ['text' => $m['what'], 'language' => $m['language']];
            else $failed++;
        }
        $r->finalize(); $mo->reset();
        echo $i++ ? ",\n" : '', json_encode(['path' => $f['path'], 'kind' => $f['kind'], 'duration_s' => $f['duration'] === null ? null : round((float)$f['duration'], 3),
            'fps' => $rate ? "$rate[0]/$rate[1]" : null, 'start_timecode' => $f['timecode'] ?: null, 'timecode_mode' => $mode,
            'described' => (bool)($shots || $speech || $failed)] + ($failed ? ['shots_not_read' => $failed] : []) + ['shots' => $shots, 'speech' => $speech], $J);
        if ($i % 50 === 0) flush();
    }
    echo "\n]}\n";
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
