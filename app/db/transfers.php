<?php
// Persistent selections and per-folder checkpoints. Browser refreshes and helper
// restarts must not turn an unfinished transfer into an idle, empty screen.
require_once __DIR__ . '/schema.php';

function transfer_init(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS transfer_jobs (
        id TEXT PRIMARY KEY, created INTEGER NOT NULL)");
    db()->exec("CREATE TABLE IF NOT EXISTS transfer_items (
        job_id TEXT NOT NULL, source TEXT NOT NULL, total_bytes INTEGER DEFAULT 0,
        total_files INTEGER DEFAULT 0, done_bytes INTEGER DEFAULT 0,
        done_files INTEGER DEFAULT 0, copied_bytes INTEGER DEFAULT 0,
        already_bytes INTEGER DEFAULT 0, failed INTEGER DEFAULT 0,
        measured INTEGER DEFAULT 0, phase TEXT DEFAULT 'queued', updated INTEGER DEFAULT 0,
        PRIMARY KEY(job_id, source))");
}

function transfer_latest(): ?array {
    transfer_init();
    $j = db()->querySingle('SELECT * FROM transfer_jobs ORDER BY created DESC, rowid DESC LIMIT 1', true);
    if (!$j) return null;
    $s = db()->prepare('SELECT * FROM transfer_items WHERE job_id = ? ORDER BY rowid');
    $s->bindValue(1, $j['id']);
    $r = $s->execute(); $j['items'] = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $j['items'][] = $row;
    return $j;
}

function transfer_select(array $paths, array $sizes): void {
    transfer_init();
    $db = db(); $db->exec('BEGIN IMMEDIATE');
    try {
        $job = transfer_latest();
        $active = $job && count(array_filter($job['items'], fn($i) => !in_array($i['phase'], ['done', 'removed'], true)));
        if (!$active) {
            if (!$paths) { $db->exec('COMMIT'); return; }
            $id = bin2hex(random_bytes(16));
            $s = $db->prepare('INSERT INTO transfer_jobs VALUES (?,?)');
            $s->bindValue(1, $id); $s->bindValue(2, time(), SQLITE3_INTEGER); $s->execute();
        } else {
            $id = $job['id'];
            foreach ($job['items'] as $i) {
                if ($i['phase'] === 'done' || in_array($i['source'], $paths, true)) continue;
                $s = $db->prepare("UPDATE transfer_items SET phase='removed' WHERE job_id=? AND source=?");
                $s->bindValue(1, $id); $s->bindValue(2, $i['source']); $s->execute();
            }
        }
        foreach ($paths as $p) {
            $s = $db->prepare("INSERT INTO transfer_items (job_id,source,total_files,total_bytes)
                VALUES (?,?,?,?) ON CONFLICT(job_id,source) DO UPDATE SET
                phase=CASE WHEN phase='removed' THEN 'queued' ELSE phase END");
            foreach ([$id, $p, $sizes[$p][0] ?? 0, $sizes[$p][1] ?? 0] as $k => $v)
                $s->bindValue($k+1, $v, $k < 2 ? SQLITE3_TEXT : SQLITE3_INTEGER);
            $s->execute();
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}

function transfer_summary(?array $j): ?array {
    if (!$j) return null;
    $items = array_values(array_filter($j['items'], fn($i) => $i['phase'] !== 'removed'));
    if (!$items) return null;
    $sum = fn($k) => array_sum(array_column($items, $k));
    $done = count(array_filter($items, fn($i) => $i['phase'] === 'done'));
    $last = max(array_column($items, 'updated'));
    $active = array_values(array_filter($items, fn($i) => $i['phase'] !== 'done'));
    usort($active, fn($a, $b) => $b['updated'] <=> $a['updated']);
    $current = $active[0] ?? null;
    $phase = $current['phase'] ?? 'done';
    if ($current && $current['updated'] && time() - $current['updated'] > 90) $phase = 'interrupted';
    $total = $sum('total_bytes'); $accounted = min($total, $sum('done_bytes'));
    return ['id' => $j['id'], 'phase' => $phase, 'reported' => $current['phase'] ?? 'done', 'updated' => $last,
        'source' => $current['source'] ?? '', 'folders' => count($items), 'folders_done' => $done,
        'total_bytes' => $total, 'done_bytes' => $accounted,
        'copied_bytes' => $sum('copied_bytes'), 'already_bytes' => $sum('already_bytes'),
        'failed' => $sum('failed'), 'estimated' => $sum('measured') < count($items),
        'pct' => $done === count($items) ? 100 : ($total ? min(99, (int)round(100*$accounted/$total)) : null)];
}

function transfer_current(): ?array {
    $job = transfer_latest();
    // Adopt a queue created by the previous version, without inventing past progress.
    if (!$job) {
        $paths = []; $sizes = [];
        foreach (@file(web_dir() . '/ingest-queue.tsv') ?: [] as $l) {
            $f = explode("\t", rtrim($l, "\n"));
            if (($f[0] ?? '') === 'copy' && isset($f[1])) $paths[] = $f[1];
        }
        foreach (@file(web_dir() . '/ingest-sections.tsv') ?: [] as $l) {
            $f = explode("\t", rtrim($l, "\n"));
            if (($f[0] ?? '') === 'section') $sizes[$f[1]] = [(int)($f[2] ?? 0), (int)($f[3] ?? 0)];
        }
        if ($paths) { transfer_select($paths, $sizes); $job = transfer_latest(); }
    }
    return $job;
}
