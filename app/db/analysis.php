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
    // wmatch(hay, pattern): a whole word, as word_pattern() writes it (SQLite has no regular expressions)
    static $fn = false;
    if (!$fn) { db()->createFunction('wmatch', fn($hay, $re) => preg_match($re, (string)$hay) ? 1 : 0, 2, SQLITE3_DETERMINISTIC); $fn = true; }
    db()->exec('CREATE INDEX IF NOT EXISTS i_moments_path ON moments (path)');   // a file's still, beside its row in Search
}

// One searched word, as the words it stands for: itself whole (not "old" inside "holding"), its
// plural or singular, and every word of its group in rules.json analysis.related ("elder" finds
// "old", "senior", "abuelo" …). -> a pattern for wmatch().
function word_pattern(string $word, bool $related = true): string {
    $word = mb_strtolower(trim($word));
    $one = function (string $w): array {             // kids -> kid, babies -> baby, buses -> bus
        $f = [$w];
        if (preg_match('/ies$/u', $w)) $f[] = mb_substr($w, 0, -3) . 'y';
        elseif (preg_match('/(s|x|z|ch|sh)es$/u', $w)) $f[] = mb_substr($w, 0, -2);
        elseif (preg_match('/[^s]s$/u', $w)) $f[] = mb_substr($w, 0, -1);
        return $f;
    };
    $all = function (string $w): array {             // kid -> kids, baby -> babies, bus -> buses
        $f = [$w, $w . 's'];
        if (preg_match('/[^aeiou]y$/u', $w)) $f[] = mb_substr($w, 0, -1) . 'ies';
        if (preg_match('/(s|x|z|ch|sh)$/u', $w)) $f[] = $w . 'es';
        return $f;
    };
    $base = $one($word);
    $words = $base;
    foreach ($related ? (array)(rules()['analysis']['related'] ?? []) : [] as $group) {
        $g = array_map(fn($x) => mb_strtolower((string)$x), (array)$group);
        if (array_intersect($base, $g)) $words = array_merge($words, $g);
    }
    $forms = [];
    foreach (array_unique($words) as $w) foreach ($all($w) as $f) $forms[$f] = true;
    return '/(*UCP)\b(?:' . implode('|', array_map(fn($f) => preg_quote($f, '/'), array_keys($forms))) . ')\b/u';
}

// Moments close in meaning to $q (meaning.py, beside Rushes on this Mac): [[fp, kind, start_s, score], …],
// best first. null when it is not running here (not installed, starting, or Rushes runs on a NAS):
// Search then finds by words alone, with the related-word groups.
const MEANING_PORT = 18652;
function meaning_moments(string $q): ?array {
    $min = (float)(rules()['analysis']['meaning']['min'] ?? 0.3);
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $r = @file_get_contents('http://127.0.0.1:' . MEANING_PORT . '/search?k=400&min=' . $min . '&q=' . rawurlencode($q), false, $ctx);
    $j = $r === false ? null : json_decode($r, true);
    return (is_array($j) && array_is_list($j)) ? $j : null;
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
        $w[] = 'wmatch(hay, ?)';
        $a[] = word_pattern($word);
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
// ponytail: a pattern per word over all moments, like analysis_search; FTS5 when it passes ~300 ms.
// $cond: what a shot must be (search.php's content filters: shot size, people, light, mood).
// With it, a video counts when one of its shots is all of that; words may still be anywhere in it.
function analysis_videos(string $q, int $limit = 120, string $cond = '', array $cargs = []): array {
    analysis_init();
    // With meaning search here, words are only themselves (and their plurals): meaning finds the rest,
    // and a shared word ("old" building, "old" man) no longer joins things that mean something else.
    $mean = trim($q) === '' ? null : meaning_moments($q);
    $likes = [];
    foreach (preg_split('/\s+/', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) as $word)
        $likes[] = word_pattern($word, $mean === null);
    if (!$likes && $cond === '') return ['count' => 0, 'moments' => 0, 'rows' => []];
    $words = analysis_by_words($likes, $cond, $cargs);
    if (!$mean) {
        $rows = $words['rows'];
        usort($rows, fn($a, $b) => [$b['hits'], count($b['found']), $a['path']] <=> [$a['hits'], count($a['found']), $b['path']]);
        return ['count' => count($rows), 'moments' => $words['n'], 'rows' => array_slice($rows, 0, $limit), 'meaning' => $mean !== null];
    }
    // Meaning: each video's closest moment, and when every close moment is. A video found by its
    // words too comes first (+0.3: the words were asked for); with content filters, only videos
    // with a shot that is all of them, as for words.
    $by = [];
    foreach ($mean as [$fp, $kind, $start, $score]) $by[$fp][] = [(float)$start, (float)$score, $kind];
    if ($cond !== '' && $by) {
        $in = implode(',', array_fill(0, count($by), '?'));
        $st = db()->prepare("SELECT DISTINCT fp FROM moments WHERE fp IN ($in) AND kind = 'shot' AND $cond");
        foreach (array_merge(array_keys($by), $cargs) as $i => $v) $st->bindValue($i + 1, $v);
        $r = $st->execute(); $ok = [];
        while ($x = $r->fetchArray(SQLITE3_NUM)) $ok[$x[0]] = true;
        $by = array_intersect_key($by, $ok);
    }
    $videos = $words['rows']; $n = $words['n'];
    foreach ($videos as $fp => &$v) { $v['score'] = 0.3 + ($by[$fp][0][1] ?? 0); } unset($v);
    $new = array_diff_key($by, $videos);
    if ($new) {
        $in = implode(',', array_fill(0, count($new), '?'));
        $st = db()->prepare("SELECT fp,path,kind,shot,start_s,end_s,what,on_screen,themes,tags,shot_size,people,
            light,part_of_day,mood,language FROM moments WHERE kind != 'failed' AND fp IN ($in)");
        foreach (array_keys($new) as $i => $v) $st->bindValue($i + 1, $v);
        $r = $st->execute(); $all = [];
        while ($m = $r->fetchArray(SQLITE3_ASSOC)) $all[$m['fp']][] = $m;
        foreach ($new as $fp => $close) {
            [$t, $score, $kind] = $close[0];            // the closest: the video's picture and its first line
            foreach ($all[$fp] ?? [] as $m) {
                if ($m['kind'] === $kind && abs((float)$m['start_s'] - $t) < 0.01) { $videos[$fp] = $m + ['hits' => 0, 'score' => $score]; break; }
            }
        }
    }
    foreach ($by as $fp => $close) {                    // every close moment shows on the video's line
        if (!isset($videos[$fp])) continue;
        foreach ($close as [$t]) $videos[$fp]['found'][] = $t;
    }
    foreach ($videos as &$v) { $v['found'] = array_values(array_unique($v['found'] ?? [])); sort($v['found']); } unset($v);
    $rows = array_values($videos);
    usort($rows, fn($a, $b) => [$b['score'], $b['hits'], $a['path']] <=> [$a['score'], $a['hits'], $b['path']]);
    $n = array_sum(array_map(fn($v) => count($v['found']), $rows));
    return ['count' => count($rows), 'moments' => $n, 'rows' => array_slice($rows, 0, $limit), 'meaning' => true];
}

// Videos where every word appears somewhere (any shot or line), as fp => the best-matching
// moment with its hits and when every matching moment is. $cond: as for analysis_videos.
function analysis_by_words(array $likes, string $cond, array $cargs): array {
    $shot = $cond === '' ? '' : "kind = 'shot' AND $cond";
    $any = $likes ? implode(' + ', array_fill(0, count($likes), 'wmatch(hay, ?)'))
        : '0';
    $all = implode(' AND ', array_fill(0, count($likes), 'SUM(wmatch(hay, ?)) > 0'));
    $bind = function (SQLite3Stmt $s, array $vals) { foreach ($vals as $i => $v) $s->bindValue($i + 1, $v); };
    $st = $likes
        ? db()->prepare("SELECT fp FROM moments WHERE kind != 'failed'" . ($shot ? " AND fp IN (SELECT fp FROM moments WHERE $shot)" : '') . " GROUP BY fp HAVING $all")
        : db()->prepare("SELECT DISTINCT fp FROM moments WHERE $shot LIMIT 2000");
    $bind($st, $likes ? ($shot ? array_merge($cargs, $likes) : $likes) : $cargs); $r = $st->execute(); $fps = [];
    while ($x = $r->fetchArray(SQLITE3_NUM)) $fps[] = $x[0];
    if (!$fps) return ['rows' => [], 'n' => 0];
    $in = implode(',', array_fill(0, count($fps), '?'));
    $st = db()->prepare("SELECT fp,path,kind,shot,start_s,end_s,what,on_screen,themes,tags,shot_size,people,
        light,part_of_day,mood,language, ($any) AS hits FROM moments
        WHERE kind != 'failed' AND fp IN ($in) AND " . ($shot ?: "($any) > 0") . " ORDER BY fp, hits DESC, kind, start_s");
    $bind($st, array_merge($likes, $fps, $shot ? $cargs : $likes)); $r = $st->execute();
    $videos = []; $n = 0;
    while ($m = $r->fetchArray(SQLITE3_ASSOC)) {
        $n++;
        if (!isset($videos[$m['fp']])) { $m['found'] = []; $videos[$m['fp']] = $m; }
        $videos[$m['fp']]['found'][] = (float)$m['start_s'];
    }
    foreach ($videos as &$v) { sort($v['found']); } unset($v);
    return ['rows' => $videos, 'n' => $n];
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

