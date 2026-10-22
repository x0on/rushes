<?php
// prepare.php — getting a folder ready to be found: its proxies, then its descriptions.
//
// Two machines, one order. The archive machine makes the proxies; the helper
// describes the footage, reading those proxies. This file decides what comes
// next, once a minute (the runner calls import.php, which calls prepare_advance):
//   · a prepared folder without its proxies  → proxy-next.txt, which the runner
//     starts when nothing else is being made;
//   · a prepared folder with its proxies     → one "analyze" line in the queue,
//     which the helper takes after any copies.
// It decides and writes; it never runs anything. Nothing is done twice: a
// folder whose proxies and descriptions are finished is left alone.
require_once __DIR__ . '/config.php';

function prep_file(): string { return web_dir() . '/prepare.tsv'; }

// One line of ffmpeg's own report -> what the original is.
function media_probe(string $s): array {
    preg_match('/Video: (\w+).*?, (\d{2,5})x(\d{2,5})/', $s, $v);
    preg_match('/([\d.]+) fps/', $s, $f);
    preg_match('/Duration: (\d+):(\d+):([\d.]+)/', $s, $d);
    // What the camera wrote, as FFmpeg shows it: "name   : value", one per
    // line (the lines arrive joined with spaces, so a value ends at a run of them).
    $tag = function (string $names) use ($s) {
        return preg_match('/(?:' . $names . ')\s*:\s*(.+?)(?:\s{2,}|$)/', $s, $m) ? trim($m[1]) : null;
    };
    $when  = $tag('creation_time|modification_date');
    $model = $tag('com\.apple\.quicktime\.model|product_name|\bmodel');
    $make  = $tag('com\.apple\.quicktime\.make|company_name|\bmake');
    $enc   = $tag('\bencoder');
    $camera = $model ? ($make && stripos($model, $make) !== 0 ? "$make $model" : $model)
            : ($enc && preg_match('/^(DJI|GoPro)/i', $enc) ? $enc : null);   // drones and action cameras name themselves here
    $recorded = $when && preg_match('/^(\d{4}-\d\d-\d\d)[T ](\d\d:\d\d:\d\d)/', $when, $w)
            && $w[1] > '1980' ? "$w[1] $w[2]" : null;                     // a camera that never had its clock set says 1970 or 1904
    $tc = preg_match('/timecode\s*:\s*(\d\d:\d\d:\d\d[:;.]\d\d)/', $s, $t) ? $t[1] : null;
    return ['codec' => $v[1] ?? null, 'width' => isset($v[2]) ? (int)$v[2] : null,
            'height' => isset($v[3]) ? (int)$v[3] : null, 'fps' => isset($f[1]) ? (float)$f[1] : null,
            'duration' => $d ? $d[1] * 3600 + $d[2] * 60 + (float)$d[3] : null,
            'recorded' => $recorded, 'timecode' => $tc, 'reel' => $tag('reel_name'), 'camera' => $camera];
}

// proxy-made.tsv (appended by proxy.sh, never emptied) -> the media ledger.
// Reads only what is new since last time.
function media_import(): array {
    require_once __DIR__ . '/schema.php';
    db_init(); $db = db();
    $src = web_dir() . '/proxy-made.tsv';
    if (!is_readable($src)) return ['state' => 'waiting'];
    $at = (int)meta_get('proxy_made_at', '0');
    if ($at > filesize($src)) $at = 0;                // the file was started again
    $h = fopen($src, 'r'); fseek($h, $at);
    $id  = $db->prepare('SELECT id FROM files WHERE path = ?');
    $put = $db->prepare('INSERT OR REPLACE INTO media (file_id,width,height,fps,codec,duration,proxy_at,recorded,timecode,reel,camera,asis)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $n = 0; $lost = 0;
    $db->exec('BEGIN');
    while (($l = fgets($h)) !== false) {
        if (!str_ends_with($l, "\n")) break;          // still being written: next time
        $at += strlen($l);
        [$path, $ts, $probe, $asis] = array_pad(explode("\t", rtrim($l, "\n"), 4), 4, '');
        $id->bindValue(1, $path); $r = $id->execute()->fetchArray(SQLITE3_NUM); $id->reset();
        if (!$r) { $lost++; continue; }                // ponytail: not in search yet; its proxy still exists, only the details are missing
        $p = media_probe($probe);
        foreach ([$r[0], $p['width'], $p['height'], $p['fps'], $p['codec'], $p['duration'], (int)$ts,
                  $p['recorded'], $p['timecode'], $p['reel'], $p['camera'], $asis === '1' ? '1' : null] as $i => $v)
            $put->bindValue($i + 1, $v);
        $put->execute(); $put->reset(); $n++;
    }
    fclose($h);
    meta_set('proxy_made_at', (string)$at);
    $db->exec('COMMIT');
    return ['added' => $n, 'not_in_search' => $lost];
}

// What a folder holds, from the search catalogue: instant, no job to wait for.
// When its turn comes, proxy.sh reads the folder itself again, so footage that
// arrived since still gets its proxy. Kept 10 minutes, so the list stays quick.
// Whether a proxy exists is looked up on the VIDEO share, so only the runner
// ($look, once a minute, when VIDEO answers) and a person's own click do that.
// A page asking every few seconds gets the last answer, however old, or a
// first guess from search alone (have = 0) until the runner has looked.
function prepare_plan(string $rel, bool $fresh = false, bool $look = true): array {
    $f = web_dir() . '/prepare-plan.json';
    $cache = json_decode((string)@file_get_contents($f), true) ?: [];
    // what could not be made is read as it is now, every time (a file Removed leaves the list at once); the
    // counts may be up to 10 minutes old
    if (isset($cache[$rel]['media']) && (!$look || (!$fresh && time() - $cache[$rel]['at'] < 600)))   // (one counted before 0.13.22 is counted again)
        return ['failures' => array_slice(proxy_failures($rel), 0, 100)] + $cache[$rel];
    require_once __DIR__ . '/schema.php';
    $dir = prep_abs($rel);
    // a range, not LIKE, so the path index answers it
    $st = db()->prepare('SELECT path, bytes, m.asis FROM files LEFT JOIN media m ON m.file_id = files.id WHERE path >= ? AND path < ?');
    $st->bindValue(1, "$dir/"); $st->bindValue(2, "$dir" . '0');     // '0' sorts right after '/'
    $r = $st->execute();
    // counted from what is there, not from the last run: every video that plays (its preview, or as it is), and
    // every file the AI describes (videos, photos, sound), so a folder's row says "40 of 40", never "25 made"
    $p = ['videos' => 0, 'have' => 0, 'previews' => 0, 'asis' => 0, 'media' => 0, 'bytes' => 0, 'to_read' => 0, 'at' => time(), 'failures' => []];
    while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) {
        if (preg_match('/\/\._[^\/]*$/', $x[0])) continue;                                 // macOS leftovers
        if (preg_match('/\.(jpe?g|png|heic|dng|tiff?|wav|mp3|m4a|aiff?)$/i', $x[0])) { $p['media']++; continue; }
        if (!preg_match('/\.(mxf|mov|mp4|avi|mts|m4v|braw|r3d)$/i', $x[0])) continue;   // the same list as proxy.sh
        $p['videos']++; $p['media']++; $p['bytes'] += (int)$x[1];
        if ($x[2] === '1') { $p['have']++; $p['asis']++; }                                    // light enough to play as it is
        elseif ($look && is_file((string)preview_of($x[0]))) { $p['have']++; $p['previews']++; }
        else $p['to_read'] += (int)$x[1];
    }
    $p['failures'] = array_slice(proxy_failures($rel), 0, 100);
    if (!$look) return $p;
    $cache[$rel] = $p;
    @file_put_contents("$f.new", json_encode($cache)) && @rename("$f.new", $f);
    return $p;
}

// How fast proxies are really made here: bytes per second over the last 200
// made (proxy.sh writes one line per proxy). 0 until the first ones exist.
function proxy_rate(): float {
    $b = 0; $s = 0;
    foreach (array_slice(@file(web_dir() . '/proxy-speed.tsv') ?: [], -200) as $l) {
        [$bytes, $secs] = array_pad(explode("\t", rtrim($l)), 2, 0);
        if ((int)$secs > 0) { $b += (int)$bytes; $s += (int)$secs; }
    }
    return $s > 0 ? $b / $s : 0.0;
}

// Files in a folder whose proxy could not be made, and why (ffmpeg's words),
// leaving out any made since.
function proxy_failures(string $rel): array {
    $dir = prep_abs($rel); $out = [];
    foreach (@file(web_dir() . '/proxy-failed.tsv') ?: [] as $l) {
        [$src, $at, $why] = array_pad(explode("\t", rtrim($l, "\n"), 3), 3, '');
        if (!str_starts_with($src, "$dir/")) continue;
        $out[$src] = ['file' => substr($src, strlen("$dir/")), 'at' => (int)$at, 'why' => $why, 'src' => $src];
    }
    // a file made since, or not there any more (moved to Recently Removed, or elsewhere) leaves the list
    return array_values(array_filter($out, fn($f) => is_file($f['src']) && !is_file((string)preview_of($f['src']))));
}

// rel folder => when it was (last) asked for
function prepare_list(): array {
    $out = [];
    foreach (@file(prep_file()) ?: [] as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if (($f[0] ?? '') !== '') $out[$f[0]] = (int)($f[1] ?? 0);
    }
    return $out;
}

function prepare_save(array $list): bool {
    $body = '';
    foreach ($list as $rel => $t) $body .= "$rel\t$t\n";
    $f = prep_file();
    return @file_put_contents("$f.new", $body) !== false && @rename("$f.new", $f);
}

// The Describe list follows moves (Tidy up, File in: every move goes through follow_moves). A folder on the list
// whose files moved is put where they went: the same place inside it (old/x/y.mov → new/x/y.mov gives new), its
// place and history kept (when it was asked, its proxies' runs, its line for the helper). Only when the folder is
// not there any more: a subfolder moved out of it leaves it where it is. [[old, new], …] absolute, in the archive.
function prepare_follow(array $pairs): array {
    $A = rtrim(archive_dir(), '/'); $list = prepare_list(); $moved = [];
    foreach (array_keys($list) as $rel) {
        if ($rel === '' || str_starts_with($rel, '/') || is_dir("$A/$rel")) continue;
        $votes = [];
        foreach ($pairs as [$a, $b]) {
            if (!str_starts_with($a, "$A/$rel/")) continue;
            $tail = substr($a, strlen("$A/$rel"));                       // /x/y.mov
            if (str_ends_with($b, $tail) && str_starts_with($b, "$A/")) { $n = substr($b, strlen($A) + 1, -strlen($tail)); $votes[$n] = ($votes[$n] ?? 0) + 1; }
        }
        if (!$votes) continue;
        arsort($votes); $to = array_key_first($votes);
        if ($to !== '' && !isset($list[$to])) $moved[$rel] = $to;
    }
    if ($moved) prepare_rename($moved);
    return $moved;
}

// Each folder [old rel => new rel] renamed on the list, in place, with what belongs to it.
function prepare_rename(array $moved, string $how = 'it was moved'): void {
    $W = web_dir(); $h = rtrim(helper_archive(), '/');
    $list = []; foreach (prepare_list() as $rel => $t) $list[$moved[$rel] ?? $rel] = $t;
    prepare_save($list);
    // its proxies' runs (so "✓ 73 made" stays), the helper's queue line, what is next for proxies
    $runs = ''; foreach (@file("$W/proxy-folders.tsv") ?: [] as $l) { $f = explode("\t", rtrim($l, "\n")); if (isset($moved[$f[0]])) { $f[0] = $moved[$f[0]]; $runs .= implode("\t", $f) . "\n"; } }
    if ($runs !== '') @file_put_contents("$W/proxy-folders.tsv", $runs, FILE_APPEND);
    $q = @file("$W/ingest-queue.tsv") ?: [];
    if ($q && $h !== '') {
        $q2 = array_map(function ($l) use ($moved, $h) { foreach ($moved as $a => $b) if (str_starts_with($l, "analyze\t$h/$a\t")) return "analyze\t$h/$b\t" . substr($l, strlen("analyze\t$h/$a\t")); return $l; }, $q);
        if ($q2 !== $q) { @file_put_contents("$W/ingest-queue.tsv.new", implode('', $q2)); @rename("$W/ingest-queue.tsv.new", "$W/ingest-queue.tsv"); }
    }
    $nx = trim((string)@file_get_contents("$W/proxy-next.txt"));
    if (isset($moved[$nx])) @file_put_contents("$W/proxy-next.txt", $moved[$nx] . "\n");
    require_once __DIR__ . '/activity.php';
    foreach ($moved as $a => $b) activity_add('changed', "Describe list: $a is now $b ($how)");
}

// Folders on the list that were moved before the list followed moves: found again from the moves themselves,
// never from a name (a folder of the same name elsewhere, a cache that copies a drive's folder names, is not it).
// Every move is written down in _rushes/origin ("moved  old  new", by Tidy up and by File in); the folder's old
// files are followed there, through every later move, and the folder is where they are now. Not found that way:
// its row says it is gone, with the folders of that name as a hint only. -> [old rel => [hints]]
function prepare_find_moved(): array {
    $A = rtrim(archive_dir(), '/'); $h = rtrim(helper_archive(), '/');
    $gone = array_values(array_filter(array_keys(prepare_list()), fn($r) => $r !== '' && !str_starts_with($r, '/') && !is_dir("$A/$r")));
    if (!$gone) return [];
    // asked every few seconds while Describe is open: the records are read again only when they or the list change
    $recs = glob("$A/_rushes/origin/*.tsv") ?: []; sort($recs);
    $sig = md5(implode('|', $gone) . '#' . implode('|', array_map(fn($f) => basename($f) . ':' . @filesize($f), $recs)));
    $memo = json_decode((string)@file_get_contents(web_dir() . '/prepare-gone.json'), true);
    if (($memo['sig'] ?? '') === $sig) return $memo['lost'] ?? [];
    $local = fn(string $p) => $h !== '' && $h !== $A && str_starts_with($p, "$h/") ? $A . substr($p, strlen($h)) : $p;
    $moved = []; $lost = [];
    // the records, oldest first; only the lines under a folder looked for (or under where it went) count
    foreach ($gone as $rel) {
        $at = $rel;
        for ($hop = 0; $hop < 6 && !is_dir("$A/$at"); $hop++) {
            $pre = "$A/$at/"; $votes = [];
            foreach ($recs as $f) {
                $fh = @fopen($f, 'r'); if (!$fh) continue;
                while (($l = fgets($fh)) !== false) {
                    if (strncmp($l, "moved\t", 6) !== 0) continue;
                    $x = explode("\t", rtrim($l, "\n"));
                    if (count($x) < 3) continue;
                    $o = $local($x[1]); if (!str_starts_with($o, $pre)) continue;
                    $n = $local($x[2]); $tail = substr($o, strlen($pre) - 1);        // /x/y.mov
                    if (str_ends_with($n, $tail) && str_starts_with($n, "$A/")) { $k = substr($n, strlen($A) + 1, -strlen($tail)); $votes[$k] = ($votes[$k] ?? 0) + 1; }
                }
                fclose($fh);
            }
            if (!$votes) break;
            arsort($votes); $at = array_key_first($votes);
        }
        if ($at !== $rel && is_dir("$A/$at")) $moved[$rel] = $at;
        else $lost[$rel] = prepare_name_hints($rel);
    }
    if ($moved) prepare_rename($moved, 'its files were followed through the moves written down');
    else @file_put_contents(web_dir() . '/prepare-gone.json', json_encode(['sig' => $sig, 'lost' => $lost], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    return $lost;
}

// Folders of the same name, from Search: shown on a gone folder's row as a hint, never taken by themselves
function prepare_name_hints(string $rel): array {
    require_once __DIR__ . '/schema.php'; db_init();
    $A = rtrim(archive_dir(), '/'); $name = basename($rel);
    $st = db()->prepare("SELECT path FROM files WHERE path LIKE ? ESCAPE '\\' LIMIT 5000");
    $st->bindValue(1, '%/' . addcslashes($name, '%_\\') . '/%');
    $r = $st->execute(); $at = [];
    while ($row = $r->fetchArray(SQLITE3_NUM)) {
        $p = $row[0]; if (!str_starts_with($p, "$A/")) continue;
        $i = strpos($p, "/$name/"); $f = substr($p, strlen($A) + 1, $i + strlen($name) - strlen($A));
        if (is_dir("$A/$f")) $at[$f] = 1;
    }
    return array_keys($at);
}

// The helper's line for a folder 0.13.18 took by its name stayed in its queue when the list was put back: the
// helper went on describing that folder (a cache, no videos). Lines for those folders are taken out. Once.
function prepare_drop_guessed_lines(): void {
    $W = web_dir(); $h = rtrim(helper_archive(), '/');
    if (file_exists("$W/prepare-undo-lines.done") || $h === '') return;
    $wrong = [];
    foreach (@file("$W/activity.tsv") ?: [] as $l)
        if (preg_match('/Describe list: .+? had been taken for (.+?) by its name alone/', $l, $m)) $wrong[] = "analyze\t$h/$m[1]\t";
    if ($wrong) {
        $q = @file("$W/ingest-queue.tsv") ?: [];
        $q2 = array_values(array_filter($q, function ($l) use ($wrong) { foreach ($wrong as $w) if (str_starts_with($l, $w)) return false; return true; }));
        if ($q2 !== $q) { @file_put_contents("$W/ingest-queue.tsv.new", implode('', $q2)); @rename("$W/ingest-queue.tsv.new", "$W/ingest-queue.tsv"); }
    }
    @touch("$W/prepare-undo-lines.done");
}

// 0.13.18 found two folders again by their name alone, and one of those was a cache's copy of a drive's folder
// names. Each folder put on the list that way, now with no videos in it, goes back to the name it had, to be
// found again from the moves (prepare_find_moved). Once; Activity says so.
function prepare_undo_name_guesses(): void {
    $W = web_dir(); $A = rtrim(archive_dir(), '/');
    if (file_exists("$W/prepare-undo-names.done")) return;
    $list = prepare_list(); $back = [];
    foreach (@file("$W/activity.tsv") ?: [] as $l)
        if (preg_match('/Describe list: (.+?) moved to (.+?), and the list follows it/', $l, $m) && isset($list[$m[2]]) && !isset($list[$m[1]])) {
            $videos = 0;
            if (is_dir("$A/$m[2]")) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$A/$m[2]", FilesystemIterator::SKIP_DOTS)) as $f)
                if (preg_match('/\.(mxf|mov|mp4|avi|mts|m4v|braw|r3d)$/i', $f->getFilename())) { $videos++; break; }
            if (!$videos) $back[$m[2]] = $m[1];
        }
    if ($back) {
        $new = []; foreach ($list as $rel => $t) $new[$back[$rel] ?? $rel] = $t;
        prepare_save($new);
        require_once __DIR__ . '/activity.php';
        foreach ($back as $wrong => $was) activity_add('changed', "Describe list: $was had been taken for $wrong by its name alone (a mistake); it is looked for again from the moves written down");
    }
    @touch("$W/prepare-undo-names.done");
}

// The proxy build going on now, if any (proxy.sh writes it as it goes).
// A proxy build is running only if the process in proxy.pid really is proxy.sh:
// the number can outlive its job and belong to another program later.
function proxy_alive(): bool {
    // On a Mac the runner makes them (runner.py) and leaves a heartbeat while it does: there is no /proc
    if (on_mac()) return time() - (int)@file_get_contents(web_dir() . '/proxy-alive.txt') < 60;
    $pid = (int)@file_get_contents(web_dir() . '/proxy.pid');
    return $pid > 0 && str_contains((string)@file_get_contents("/proc/$pid/cmdline"), 'proxy.sh');
}

// Why the list is where it is, in words, for the page.
function prepare_why(): array {
    $now = proxy_now(); $nf = web_dir() . '/proxy-next.txt';
    if (!empty($now['running']))
        return ['state' => 'making', 'text' => 'Proxies are being made now' . (($now['only'] ?? '') !== '' ? ' for ' . $now['only'] : '') . '.'];
    // asked with Start now (or Manage's tools), not started yet
    foreach (glob(web_dir() . '/queue/*.job') ?: [] as $j)
        if (preg_match('/^ACTION=proxy-build$/m', (string)@file_get_contents($j))) {
            preg_match('/^QUERY=(.*)$/m', (string)@file_get_contents($j), $m);
            $age = time() - (int)filemtime($j);
            return $age < 180
                ? ['state' => 'asked', 'text' => 'Asked ' . $age . ' s ago: the archive machine starts ' . ($m[1] ?? 'the proxies') . ' at its next turn, within a minute.']
                : ['state' => 'stuck', 'text' => 'Asked ' . (int)round($age / 60) . ' minutes ago and the archive machine has not taken the job. Is its runner going? Overview says when it was last seen.'];
        }
    if (is_file($nf)) {
        $age = time() - (int)filemtime($nf); $f = trim((string)@file_get_contents($nf));
        return $age < 180
            ? ['state' => 'starting', 'text' => "$f starts at the archive machine's next turn, within a minute."]
            : ['state' => 'stuck', 'text' => "$f was due to start " . max(3, (int)round($age / 60)) . " minutes ago and has not. Press Start now; if it still does not start, the raw log in Overview says why."];
    }
    return ['state' => 'idle', 'text' => ''];
}

function proxy_now(): array {
    $p = [];
    foreach (@file(web_dir() . '/proxy-status.txt') ?: [] as $l) { $f = explode("\t", rtrim($l, "\n"), 2); $p[$f[0]] = $f[1] ?? ''; }
    $p['running'] = proxy_alive();
    return $p;
}

// Each folder's last finished (or stopped) proxy run.
function proxy_runs(): array {
    $out = [];
    foreach (@file(web_dir() . '/proxy-folders.tsv') ?: [] as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if (count($f) >= 7) $out[$f[0]] = ['state' => $f[1], 'ok' => (int)$f[2], 'failed' => (int)$f[3],
                                            'later' => (int)$f[4], 'total' => (int)$f[5], 'at' => (int)$f[6]];
    }
    return $out;
}

// Describing, per helper path: the last finished run, and the one going on now.
function described_runs(): array {
    $out = [];
    foreach (@file(web_dir() . '/ingest-history.tsv') ?: [] as $l) {
        $f = explode("\t", rtrim($l, "\n"));
        if (($f[1] ?? '') === 'analysed')
            $out[$f[2]] = ['files' => (int)$f[3], 'when' => $f[0], 'note' => $f[6] ?? '',
                           'asked' => preg_match('/asked=(\d+)/', $f[6] ?? '', $m) ? (int)$m[1] : 0];
    }
    return $out;
}

function describing_now(): array {
    $s = [];
    foreach (['describe-status.tsv', 'ingest-status.tsv'] as $file) {      // its own lane; an older helper says it in the copy status
        $s = [];
        foreach (@file(web_dir() . "/$file") ?: [] as $l) { $f = explode("\t", rtrim($l, "\n"), 2); $s[$f[0]] = $f[1] ?? ''; }
        // Listening to a long file says nothing for minutes (a newer helper repeats itself
        // every minute); the lane says idle or paused the moment it stops, so "analysing"
        // within half an hour is still at it.
        if (isset($s['ts']) && time() - (int)$s['ts'] < 1800 && ($s['phase'] ?? '') === 'analysing') return $s;
    }
    return [];
}

// Where each prepared folder stands, step by step. The one source of truth for
// both the page and prepare_advance, so they can never disagree.
function prepare_table(bool $look = false): array {
    $h = rtrim(helper_archive(), '/');
    $runs = proxy_runs(); $now = proxy_now(); $desc = described_runs(); $dnow = describing_now(); $rate = proxy_rate();
    $queue = array_map('rtrim', @file(web_dir() . '/ingest-queue.tsv') ?: []);
    $rows = [];
    foreach (prepare_list() as $rel => $asked) {
        $run = $runs[$rel] ?? null;
        $mine = $run && $run['at'] >= $asked;                      // a run since it was asked for
        if (($now['state'] ?? '') === 'no-room' && ($now['only'] ?? '') === $rel && (int)($now['at'] ?? 0) >= $asked)
            $px = ['step' => 'no-room', 'need' => (int)$now['need'], 'free' => (int)$now['free']];
        elseif (!empty($now['running']) && ($now['only'] ?? '') === $rel && ($now['state'] ?? '') === 'building')
            $px = ['step' => 'making', 'done' => (int)$now['done'], 'total' => (int)$now['total'], 'ok' => (int)$now['ok']];
        elseif (str_starts_with($rel, '/') && !(kept_drive_of($rel)['connected'] ?? false))
            $px = ['step' => 'unplugged'];                         // a kept drive: its previews are made while it is plugged in
        elseif (($now['state'] ?? '') === 'no-ffmpeg')
            $px = ['step' => 'no-ffmpeg'];
        elseif ($mine && $run['state'] === 'stopped')
            $px = ['step' => 'stopped', 'why' => ($now['only'] ?? '') === $rel ? ($now['why'] ?? '') : ''] + $run;
        elseif ($mine && $run['state'] === 'done')
            // files that were still arriving get their proxies on a run two hours later
            $px = ['step' => ($run['later'] > 0 && time() - $run['at'] > 7200) ? 'again' : 'done'] + $run;
        else
            $px = ['step' => 'waiting'];

        if (!str_starts_with($rel, '/') && !is_dir(rtrim(archive_dir(), '/') . "/$rel"))   // moved away, and not found again
            $px = ['step' => 'gone'];
        $path = str_starts_with($rel, '/') ? $rel : "$h/$rel";     // a kept drive's folder is where it is
        $d = $desc[$path] ?? null;
        if ($dnow && ($dnow['source'] ?? '') === $path)
            $ds = ['step' => 'describing', 'n' => (int)($dnow['n'] ?? 0), 'of' => (int)($dnow['of'] ?? 0),
                   'file' => basename((string)($dnow['file'] ?? '')), 'doing' => (string)($dnow['step'] ?? '')];
        elseif ($d && $d['asked'] === $asked)            // described for this very request
            $ds = ['step' => 'done'] + $d;
        elseif ($px['step'] === 'gone')
            $ds = ['step' => 'gone'];
        elseif (in_array("analyze\t$path\t$asked", $queue, true))
            $ds = ['step' => 'queued'];
        else
            $ds = ['step' => in_array($px['step'], ['done', 'again'], true) ? 'next' : 'waiting'];
        // time left for its proxies, from the speed measured here
        $plan = prepare_plan($rel, false, $look); $left = null;
        if ($rate > 0 && !in_array($px['step'], ['done'], true)) {
            $bytes = $plan['to_read'];
            if ($px['step'] === 'making' && $px['total'] > 0) $bytes = $plan['bytes'] * (1 - $px['done'] / $px['total']);
            $left = (int)round($bytes / $rate);
        }
        $rows[] = ['folder' => $rel, 'asked' => $asked, 'plan' => $plan, 'left' => $left,
                   'failures' => $plan['failures'] ?? [], 'proxies' => $px, 'describe' => $ds];
    }
    return $rows;
}

// Folders whose proxies were stopped for one started first (analyze.php action=first): they carry on
// by themselves, in their place, instead of waiting for Try again.
function prepare_resume(?string $add = null, bool $on = true): array {
    $f = web_dir() . '/prepare-resume.json';
    $r = json_decode((string)@file_get_contents($f), true) ?: [];
    if ($add !== null) { if ($on) $r[$add] = time(); else unset($r[$add]); @file_put_contents($f, json_encode($r, JSON_UNESCAPED_SLASHES)); }
    return $r;
}

// Once a minute: start what is next. Writes proxy-next.txt and queue lines only.
function prepare_advance(): array {
    $h = rtrim(helper_archive(), '/');
    $next = ''; $queued = [];
    $building = !empty(proxy_now()['running']);
    $Q = web_dir() . '/ingest-queue.tsv';
    $lines = array_values(array_filter(array_map('rtrim', @file($Q) ?: []), fn($l) => $l !== ''));
    $resume = prepare_resume();
    foreach (prepare_table(true) as $r) {
        $p = $r['proxies']['step'];
        if ((in_array($p, ['waiting', 'again'], true) || ($p === 'stopped' && isset($resume[$r['folder']]))) && !$building && $next === '') {
            $next = $r['folder'];
        }
        if (isset($resume[$r['folder']]) && in_array($p, ['done', 'again', 'gone'], true)) prepare_resume($r['folder'], false);   // finished: nothing left to carry on
        if (in_array($p, ['done', 'again'], true) && $r['describe']['step'] === 'next' && $h !== '') {
            $line = "analyze\t" . (str_starts_with($r['folder'], '/') ? $r['folder'] : "$h/{$r['folder']}") . "\t{$r['asked']}";
            if (!in_array($line, $lines, true)) { $lines[] = $line; $queued[] = $r['folder']; }
        }
    }
    if ($queued) @file_put_contents("$Q.new", implode("\n", $lines) . "\n") !== false && @rename("$Q.new", $Q);
    $nf = web_dir() . '/proxy-next.txt';
    $next !== '' ? @file_put_contents($nf, "$next\n") : @unlink($nf);
    return ['proxies_next' => $next, 'describe_queued' => $queued];
}
