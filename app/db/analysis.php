<?php
// analysis.php — what the vision model saw and what was said, made searchable.
//
// The helper writes one description per media file into _rushes/analysis
// (named by the file's content fingerprint). Those files are the record; this
// copies them into the search database, one row per shot and one per line of
// speech, and is safe to run as often as you like: only files written since
// the last run are read again.
require_once __DIR__ . '/schema.php';

function analysis_init(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS moments (
        fp TEXT NOT NULL, path TEXT NOT NULL, kind TEXT NOT NULL,   -- kind: shot or speech
        shot INTEGER, start_s REAL, end_s REAL,
        what TEXT,            -- the description, or the words spoken
        on_screen TEXT, themes TEXT, tags TEXT,
        shot_size TEXT, people TEXT, ages TEXT, light TEXT, part_of_day TEXT, mood TEXT,
        language TEXT, model TEXT,
        hay TEXT              -- everything searchable, lower case
    )");
    db()->exec('CREATE INDEX IF NOT EXISTS i_moments_fp ON moments (fp)');
    db()->exec('CREATE INDEX IF NOT EXISTS i_moments_path ON moments (path)');   // a file's still, beside its row in Search
}

function analysis_dir(): string { return archive_dir() . '/_rushes/analysis'; }

// The helper names files as it sees them; search shows them as this machine does.
function as_here(string $p): string {
    $h = rtrim(helper_archive(), '/');
    return ($h !== '' && str_starts_with($p, "$h/")) ? archive_dir() . substr($p, strlen($h)) : $p;
}

function analysis_import(): array {
    analysis_init(); $db = db();
    $since = (int)meta_get('analysis_at', '0'); $newest = $since; $n = 0;
    // Idle is free: the descriptions folder is only looked through while the
    // helper is describing (it says so every minute) and ten minutes after,
    // plus once a day in case one came in another way.
    $said = (int)@filemtime(web_dir() . '/describe-status.tsv');
    if (time() - $said > 600 && time() - (int)meta_get('analysis_looked', '0') < 86400) return ['added' => 0, 'state' => 'quiet'];
    meta_set('analysis_looked', (string)time());
    $ins = $db->prepare('INSERT INTO moments (fp,path,kind,shot,start_s,end_s,what,on_screen,themes,tags,
        shot_size,people,ages,light,part_of_day,mood,language,model,hay) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $del = $db->prepare('DELETE FROM moments WHERE fp = ?');
    $db->exec('BEGIN');
    foreach (glob(analysis_dir() . '/*/*.json') ?: [] as $f) {
        $t = (int)filemtime($f);
        if ($t < $since) continue;          // the newest second again too: a file written in it is never missed
        $r = json_decode((string)@file_get_contents($f), true);
        if (!is_array($r) || empty($r['fingerprint'])) continue;
        $newest = max($newest, $t); $n++;
        $fp = $r['fingerprint'];
        // where the file was seen last: after a tidy-up, its new place
        $seen = (array)($r['seen_at'] ?? []);
        $path = as_here((string)($seen ? end($seen) : ($r['file'] ?? '')));
        $del->bindValue(1, $fp); $del->execute(); $del->reset();
        $row = function (array $v) use ($ins) {
            foreach (array_values($v) as $i => $x) $ins->bindValue($i + 1, $x);
            $ins->execute(); $ins->reset();
        };
        foreach ($r['shots'] ?? [] as $s) {
            if (!empty($s['parse_error'])) {                  // counted, never searchable; the raw answer stays in the file
                $row([$fp, $path, 'failed', (int)$s['shot'], (float)($s['start'] ?? 0), (float)($s['end'] ?? 0),
                      '', '', '', '', '', '', '', '', '', '', '', $r['model'] ?? '', '']);
                continue;
            }
            $j = fn($k) => implode(' · ', (array)($s[$k] ?? []));
            $row([$fp, $path, 'shot', (int)$s['shot'], (float)$s['start'], (float)$s['end'],
                  (string)($s['description'] ?? ''), $j('text_on_screen'), $j('themes'), $j('tags'),
                  $s['shot_size'] ?? '', $s['people'] ?? '', $j('ages'), $s['light'] ?? '',
                  $r['part_of_day'] ?? '', $s['mood'] ?? '', '', $r['model'] ?? '',
                  mb_strtolower(implode(' ', [$s['description'] ?? '', $j('text_on_screen'), $j('themes'),
                      $j('tags'), $s['shot_size'] ?? '', $s['mood'] ?? '', basename($path)]))]);
        }
        foreach (($r['speech']['segments'] ?? []) as $g)
            $row([$fp, $path, 'speech', null, (float)$g['start'], (float)$g['end'], (string)$g['text'],
                  '', '', '', '', '', '', '', $r['part_of_day'] ?? '', '', $r['speech']['language'] ?? '',
                  $r['whisper'] ?? '', mb_strtolower($g['text'] . ' ' . basename($path))]);
    }
    $db->exec('COMMIT');
    meta_set('analysis_at', (string)$newest);
    return ['described_files' => $n];
}

// Moments matching every word: shots before speech, then by file and time.
// ponytail: LIKE over one text column — fine to a few hundred thousand rows;
// switch to SQLite FTS5 if searches pass ~300 ms.
function analysis_search(string $q, int $limit = 60): array {
    analysis_init();
    $w = []; $a = [];
    foreach (preg_split('/\s+/', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) as $word) {
        $w[] = "hay LIKE ? ESCAPE '\\'";
        $a[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word) . '%';
    }
    if (!$w) return ['count' => 0, 'rows' => []];
    $where = implode(' AND ', $w);
    $c = db()->prepare("SELECT COUNT(*) FROM moments WHERE $where");
    foreach ($a as $i => $v) $c->bindValue($i + 1, $v);
    $count = (int)$c->execute()->fetchArray(SQLITE3_NUM)[0];
    $st = db()->prepare("SELECT fp,path,kind,shot,start_s,end_s,what,on_screen,themes,tags,shot_size,people,
        light,part_of_day,mood,language FROM moments WHERE $where ORDER BY kind, path, start_s LIMIT ?");
    foreach ($a as $i => $v) $st->bindValue($i + 1, $v);
    $st->bindValue(count($a) + 1, $limit, SQLITE3_INTEGER);
    $res = $st->execute(); $rows = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    return ['count' => $count, 'rows' => $rows];
}

// A video is one container: it is found when every word appears somewhere in it,
// in any of its shots or in what is said, not only all in one shot. Each video
// comes back once, with the shot that matches best (most words; the earliest of
// those) and when every matching moment is.
// ponytail: LIKE per word over all moments, like analysis_search; FTS5 when it passes ~300 ms.
function analysis_videos(string $q, int $limit = 120): array {
    analysis_init();
    $likes = [];
    foreach (preg_split('/\s+/', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) as $word)
        $likes[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word) . '%';
    if (!$likes) return ['count' => 0, 'moments' => 0, 'rows' => []];
    $any = implode(' + ', array_fill(0, count($likes), "(hay LIKE ? ESCAPE '\\')"));
    $all = implode(' AND ', array_fill(0, count($likes), "SUM(hay LIKE ? ESCAPE '\\') > 0"));
    $bind = function (SQLite3Stmt $s, array $vals) { foreach ($vals as $i => $v) $s->bindValue($i + 1, $v); };
    $st = db()->prepare("SELECT fp FROM moments WHERE kind != 'failed' GROUP BY fp HAVING $all");
    $bind($st, $likes); $r = $st->execute(); $fps = [];
    while ($x = $r->fetchArray(SQLITE3_NUM)) $fps[] = $x[0];
    if (!$fps) return ['count' => 0, 'moments' => 0, 'rows' => []];
    $in = implode(',', array_fill(0, count($fps), '?'));
    $st = db()->prepare("SELECT fp,path,kind,shot,start_s,end_s,what,on_screen,themes,tags,shot_size,people,
        light,part_of_day,mood,language, ($any) AS hits FROM moments
        WHERE kind != 'failed' AND fp IN ($in) AND ($any) > 0 ORDER BY fp, hits DESC, kind, start_s");
    $bind($st, array_merge($likes, $fps, $likes)); $r = $st->execute();
    $videos = []; $n = 0;
    while ($m = $r->fetchArray(SQLITE3_ASSOC)) {
        $n++;
        if (!isset($videos[$m['fp']])) { $m['found'] = []; $videos[$m['fp']] = $m; }
        $videos[$m['fp']]['found'][] = (float)$m['start_s'];
    }
    $rows = array_values($videos);
    usort($rows, fn($a, $b) => [$b['hits'], count($b['found']), $a['path']] <=> [$a['hits'], count($a['found']), $b['path']]);
    foreach ($rows as &$v) { sort($v['found']); } unset($v);
    return ['count' => count($rows), 'moments' => $n, 'rows' => array_slice($rows, 0, $limit)];
}

// Every shot of one video, and what is said in it, in order: the video as a whole.
function analysis_shots(string $fp): array {
    analysis_init();
    $st = db()->prepare("SELECT fp,path,kind,shot,start_s,end_s,what,on_screen,themes,tags,shot_size,people,light,part_of_day,mood,language
        FROM moments WHERE fp = ? AND kind != 'failed' ORDER BY start_s, kind");
    $st->bindValue(1, $fp); $r = $st->execute(); $rows = [];
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) $rows[] = $x;
    return $rows;
}

