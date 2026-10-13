<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// years.php — dates of clips and folders, for Reorganize (tidy.php: which year a folder goes under) and
// Tidy up's info column (browse.php). Read from the search database and the files' own dates.
require_once __DIR__ . '/schema.php';

// ── the year a folder goes under (VIDEOS / department / year / the folder) ──────────────────
// Evidence, strongest first, and never a person's guess:
//   1. a year written in the folder's own path ("STOC 2025", "2019/Kite Fest"), the deepest one;
//   2. its clips' dates: for each clip the oldest believable date it carries. A copy can only make a
//      date newer (Finder's "created" is the day of the copy), so the oldest is the closest to the shoot.
//      What the catalogue holds is the camera's own date (media.recorded, read with the preview) and
//      the file's modified date (kept by every copy Rushes and rsync make). Before 2005 or in the
//      future is a clock never set, and is not believed.
// The folder's year is the one most of its clips agree on (4 in 5, at least). Clips that disagree
// mean no year: the folder goes straight under its department, whole, rather than in a wrong year.
const YEAR_AGREE = 0.8;                 // ponytail: a fixed share; a setting if 4 in 5 proves too strict
const YEAR_SAMPLE = 200;                // clips read per folder at most: the dates of a big folder agree long before
function year_in(string $s): ?string {
    // a year on its own ("STOC 2025") or the start of a date written as digits ("20260205BikeLanes")
    return preg_match_all('/(?<![0-9])(20[0-3][0-9]|199[0-9])(?:(?![0-9])|(?=[01][0-9][0-3][0-9](?![0-9])))/', $s, $m) ? end($m[1]) : null;
}
function clip_year(string $path): ?string {
    static $put = null, $get = null, $rec = null;
    $db = db();
    if ($put === null) {
        $db->exec('CREATE TABLE IF NOT EXISTS filedates (path TEXT PRIMARY KEY, t INTEGER)');
        $put = $db->prepare('INSERT OR REPLACE INTO filedates (path, t) VALUES (?, ?)');
        $get = $db->prepare('SELECT t FROM filedates WHERE path = ?');
        $rec = $db->prepare('SELECT m.recorded FROM files f JOIN media m ON m.file_id = f.id WHERE f.path = ?');
    }
    $get->bindValue(1, $path); $t = $get->execute()->fetchArray(SQLITE3_NUM)[0] ?? null; $get->reset();
    if ($t === null) {
        $ok = fn($x) => $x !== false && $x !== null && $x >= 1104537600 && $x <= time() + 86400;   // 2005-01-01 to now
        $c = []; $m = @filemtime($path); if ($ok($m)) $c[] = $m;
        $rec->bindValue(1, $path); $r = $rec->execute()->fetchArray(SQLITE3_NUM)[0] ?? ''; $rec->reset();
        if ($r !== '' && $ok($x = strtotime((string)$r))) $c[] = $x;
        $t = $c ? min($c) : 0;
        $put->bindValue(1, $path); $put->bindValue(2, $t); $put->execute(); $put->reset();
    }
    return $t ? date('Y', (int)$t) : null;
}
// -> [year or null, why]
function folder_year(string $key, string $root, array $clips): array {
    $own = basename($key);
    if (preg_match('/^(20[0-3][0-9]|199[0-9])$/', $own)) return [null, 'a year folder already'];
    if (($y = year_in(substr($key, strlen($root)))) !== null) return [$y, 'year in the folder\'s name'];
    if (!$clips) return [null, 'no clips to date it by'];
    $step = max(1, intdiv(count($clips), YEAR_SAMPLE)); $n = [];
    for ($i = 0; $i < count($clips); $i += $step) { if (($y = clip_year($clips[$i])) !== null) $n[$y] = ($n[$y] ?? 0) + 1; }
    if (!$n) return [null, 'its clips carry no believable date'];
    arsort($n); $top = array_key_first($n);
    $say = implode(', ', array_map(fn($y, $k) => "$y × $k", array_keys(array_slice($n, 0, 3, true)), array_slice($n, 0, 3, true)));
    // agreement among the clips that carry a date; the undated ones neither help nor stand in the way
    return $n[$top] >= YEAR_AGREE * array_sum($n) ? [(string)$top, "clips: $say"] : [null, "clips disagree ($say): no year"];
}

