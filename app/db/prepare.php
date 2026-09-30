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
    return ['codec' => $v[1] ?? null, 'width' => isset($v[2]) ? (int)$v[2] : null,
            'height' => isset($v[3]) ? (int)$v[3] : null, 'fps' => isset($f[1]) ? (float)$f[1] : null,
            'duration' => $d ? $d[1] * 3600 + $d[2] * 60 + (float)$d[3] : null];
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
    $put = $db->prepare('INSERT OR REPLACE INTO media (file_id,width,height,fps,codec,duration,proxy_at) VALUES (?,?,?,?,?,?,?)');
    $n = 0; $lost = 0;
    $db->exec('BEGIN');
    while (($l = fgets($h)) !== false) {
        if (!str_ends_with($l, "\n")) break;          // still being written: next time
        $at += strlen($l);
        [$path, $ts, $probe] = array_pad(explode("\t", rtrim($l, "\n"), 3), 3, '');
        $id->bindValue(1, $path); $r = $id->execute()->fetchArray(SQLITE3_NUM); $id->reset();
        if (!$r) { $lost++; continue; }                // ponytail: not in search yet; its proxy still exists, only the details are missing
        $p = media_probe($probe);
        foreach ([$r[0], $p['width'], $p['height'], $p['fps'], $p['codec'], $p['duration'], (int)$ts] as $i => $v)
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
function prepare_plan(string $rel, bool $fresh = false): array {
    $f = web_dir() . '/prepare-plan.json';
    $cache = json_decode((string)@file_get_contents($f), true) ?: [];
    if (!$fresh && isset($cache[$rel]) && time() - $cache[$rel]['at'] < 600) return $cache[$rel];
    require_once __DIR__ . '/schema.php';
    $root = rtrim(archive_dir(), '/'); $px = "$root/PROXIES";
    // a range, not LIKE, so the path index answers it
    $st = db()->prepare('SELECT path, bytes FROM files WHERE path >= ? AND path < ?');
    $st->bindValue(1, "$root/$rel/"); $st->bindValue(2, "$root/$rel" . '0');     // '0' sorts right after '/'
    $r = $st->execute();
    $p = ['videos' => 0, 'have' => 0, 'bytes' => 0, 'to_read' => 0, 'at' => time()];
    while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) {
        if (!preg_match('/\.(mxf|mov|mp4|avi|mts|m4v|braw|r3d)$/i', $x[0])) continue;   // the same list as proxy.sh
        $p['videos']++; $p['bytes'] += (int)$x[1];
        if (is_file(preg_replace('/\.[^.\/]*$/', '.mp4', $px . substr($x[0], strlen($root))))) $p['have']++;
        else $p['to_read'] += (int)$x[1];
    }
    $cache[$rel] = $p;
    @file_put_contents("$f.new", json_encode($cache)) && @rename("$f.new", $f);
    return $p;
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

// The proxy build going on now, if any (proxy.sh writes it as it goes).
function proxy_now(): array {
    $p = [];
    foreach (@file(web_dir() . '/proxy-status.txt') ?: [] as $l) { $f = explode("\t", rtrim($l, "\n"), 2); $p[$f[0]] = $f[1] ?? ''; }
    $pid = (int)@file_get_contents(web_dir() . '/proxy.pid');
    $p['running'] = $pid > 0 && @file_exists("/proc/$pid");
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
    foreach (@file(web_dir() . '/ingest-status.tsv') ?: [] as $l) { $f = explode("\t", rtrim($l, "\n"), 2); $s[$f[0]] = $f[1] ?? ''; }
    $fresh = isset($s['ts']) && time() - (int)$s['ts'] < 300;
    return ($fresh && ($s['phase'] ?? '') === 'analysing') ? $s : [];
}

// Where each prepared folder stands, step by step. The one source of truth for
// both the page and prepare_advance, so they can never disagree.
function prepare_table(): array {
    $h = rtrim(helper_archive(), '/');
    $runs = proxy_runs(); $now = proxy_now(); $desc = described_runs(); $dnow = describing_now();
    $queue = array_map('rtrim', @file(web_dir() . '/ingest-queue.tsv') ?: []);
    $rows = [];
    foreach (prepare_list() as $rel => $asked) {
        $run = $runs[$rel] ?? null;
        $mine = $run && $run['at'] >= $asked;                      // a run since it was asked for
        if (!empty($now['running']) && ($now['only'] ?? '') === $rel && ($now['state'] ?? '') === 'building')
            $px = ['step' => 'making', 'done' => (int)$now['done'], 'total' => (int)$now['total'], 'ok' => (int)$now['ok']];
        elseif (($now['state'] ?? '') === 'no-ffmpeg')
            $px = ['step' => 'no-ffmpeg'];
        elseif ($mine && $run['state'] === 'stopped')
            $px = ['step' => 'stopped'] + $run;
        elseif ($mine && $run['state'] === 'done')
            // files that were still arriving get their proxies on a run two hours later
            $px = ['step' => ($run['later'] > 0 && time() - $run['at'] > 7200) ? 'again' : 'done'] + $run;
        else
            $px = ['step' => 'waiting'];

        $path = "$h/$rel";
        $d = $desc[$path] ?? null;
        if ($dnow && ($dnow['source'] ?? '') === $path)
            $ds = ['step' => 'describing', 'n' => (int)($dnow['n'] ?? 0), 'of' => (int)($dnow['of'] ?? 0)];
        elseif ($d && $d['asked'] === $asked)            // described for this very request
            $ds = ['step' => 'done'] + $d;
        elseif (in_array("analyze\t$path\t$asked", $queue, true))
            $ds = ['step' => 'queued'];
        else
            $ds = ['step' => in_array($px['step'], ['done', 'again'], true) ? 'next' : 'waiting'];
        $rows[] = ['folder' => $rel, 'asked' => $asked, 'plan' => prepare_plan($rel), 'proxies' => $px, 'describe' => $ds];
    }
    return $rows;
}

// Once a minute: start what is next. Writes proxy-next.txt and queue lines only.
function prepare_advance(): array {
    $h = rtrim(helper_archive(), '/');
    $next = ''; $queued = [];
    $building = !empty(proxy_now()['running']);
    $Q = web_dir() . '/ingest-queue.tsv';
    $lines = array_values(array_filter(array_map('rtrim', @file($Q) ?: []), fn($l) => $l !== ''));
    foreach (prepare_table() as $r) {
        $p = $r['proxies']['step'];
        if (in_array($p, ['waiting', 'again'], true) && !$building && $next === '') $next = $r['folder'];
        if (in_array($p, ['done', 'again'], true) && $r['describe']['step'] === 'next' && $h !== '') {
            $line = "analyze\t$h/{$r['folder']}\t{$r['asked']}";
            if (!in_array($line, $lines, true)) { $lines[] = $line; $queued[] = $r['folder']; }
        }
    }
    if ($queued) @file_put_contents("$Q.new", implode("\n", $lines) . "\n") !== false && @rename("$Q.new", $Q);
    $nf = web_dir() . '/proxy-next.txt';
    $next !== '' ? @file_put_contents($nf, "$next\n") : @unlink($nf);
    return ['proxies_next' => $next, 'describe_queued' => $queued];
}
