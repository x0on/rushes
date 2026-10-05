<?php
// Called by the scheduled runner, independently of any open browser.
require_once __DIR__ . '/schema.php';

function sync_search(bool $force = false): array {
    db_init(); $db = db();
    $src = web_dir() . '/manifest.tsv';
    if (!is_readable($src)) return ['state' => 'waiting'];
    $stamp = (int)filemtime($src);
    if (!$force && $stamp <= (int)meta_get('source_written', '0')) return ['state' => 'current'];
    $lock = fopen(web_dir() . '/search-sync.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['state' => 'updating'];
    $fh = null;
    try {
        $db->enableExceptions(true);
        meta_set('search_sync_state', 'updating');
        meta_set('search_sync_at', (string)time());
        // Anything reported by the helper during the storage scan must survive
        // the table swap, even when that scan missed a newly landed file.
        $cutoff = (int)@file_get_contents(web_dir() . '/manifest-started.txt') ?: $stamp;
        $fh = fopen($src, 'r');
        $db->exec('DROP TABLE IF EXISTS files_new');
        $db->exec('CREATE TABLE files_new (id INTEGER PRIMARY KEY, path TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL, ext TEXT, kind TEXT, bytes INTEGER, dept TEXT, year TEXT,
            event TEXT, why TEXT, seen_at INTEGER)');
        $ins = $db->prepare('INSERT OR IGNORE INTO files_new (path,name,ext,kind,bytes,dept,year,event,why,seen_at)
            VALUES (?,?,?,?,?,?,?,?,?,?)');
        $n = 0;
        $roots = array_map(fn($r) => rtrim($r, '/') . '/', catalogue_roots());     // the archive, and drives kept where they are
        $inside = function (string $p) use ($roots) { foreach ($roots as $r) if (str_starts_with($p, $r)) return true; return false; };
        $db->exec('BEGIN IMMEDIATE');
        while (($line = fgets($fh)) !== false) {
            [$size, $path] = array_pad(explode("\t", rtrim($line, "\r\n"), 2), 2, '');
            if (!ctype_digit($size) || !$inside($path) || is_system_junk($path)
                || str_contains($path, '/_rushes/') || str_ends_with($path, '.part')) continue;
            $c = classify($path); $dept = null;
            if (str_starts_with($path, shelf_dir() . '/')) {
                $folder = explode('/', substr($path, strlen(shelf_dir())+1))[0];
                $dept = dept_of_folder($folder) ?? (shelf_is_top() ? null : $folder);   // at the top, only the plan's folders are departments
            }
            foreach ([$path,$c['name'],$c['ext'],$c['kind'],(int)$size,$dept,$c['year'],$c['event'],$c['why'],$cutoff] as $i=>$v)
                $ins->bindValue($i+1, $v, is_int($v) ? SQLITE3_INTEGER : ($v === null ? SQLITE3_NULL : SQLITE3_TEXT));
            $ins->execute(); $ins->reset(); $n++;
            if ($n % 10000 === 0) { $db->exec('COMMIT'); $db->exec('BEGIN IMMEDIATE'); }
        }
        fclose($fh); $fh = null;
        // A partial or unexpectedly empty inventory must not erase a working catalog.
        $old = (int)$db->querySingle('SELECT COUNT(*) FROM files');
        if (!$n || ($old > 1000 && $n < $old * 0.5)) throw new RuntimeException('The file list is incomplete. The existing search catalog was kept.');
        $merge = $db->prepare('INSERT OR REPLACE INTO files_new (path,name,ext,kind,bytes,dept,year,event,why,seen_at)
            SELECT path,name,ext,kind,bytes,dept,year,event,why,seen_at FROM files WHERE seen_at >= ?');
        $merge->bindValue(1, $cutoff, SQLITE3_INTEGER); $merge->execute();
        // Preserve stable IDs for any other records referring to catalog files.
        $db->exec('ALTER TABLE files RENAME TO files_old');
        $db->exec('CREATE TABLE files (id INTEGER PRIMARY KEY, path TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL, ext TEXT, kind TEXT, bytes INTEGER, dept TEXT, year TEXT,
            event TEXT, why TEXT, seen_at INTEGER)');
        $db->exec('INSERT INTO files SELECT o.id,n.path,n.name,n.ext,n.kind,n.bytes,n.dept,n.year,n.event,n.why,n.seen_at
            FROM files_new n JOIN files_old o ON o.path=n.path');
        $db->exec('INSERT INTO files (path,name,ext,kind,bytes,dept,year,event,why,seen_at)
            SELECT n.path,n.name,n.ext,n.kind,n.bytes,n.dept,n.year,n.event,n.why,n.seen_at
            FROM files_new n LEFT JOIN files_old o ON o.path=n.path WHERE o.id IS NULL');
        $db->exec('DROP TABLE files_old'); $db->exec('DROP TABLE files_new');
        foreach (['name','kind','dept','bytes'] as $col) $db->exec("CREATE INDEX i_files_$col ON files ($col)");
        meta_set('files_imported', (string)$db->querySingle('SELECT COUNT(*) FROM files'));
        meta_set('imported_at', (string)time());
        meta_set('source_written', (string)$stamp);
        meta_set('search_sync_state', 'current'); meta_set('search_sync_error', '');
        meta_set('search_sync_failures', '0');
        $db->exec('COMMIT');
        return ['state' => 'updated'];
    } catch (Throwable $e) {
        try { $db->exec('ROLLBACK'); } catch (Throwable $ignored) {}
        meta_set('search_sync_state', 'retrying');
        meta_set('search_sync_failures', (string)((int)meta_get('search_sync_failures', '0') + 1));
        meta_set('search_sync_error', $e->getMessage());
        return ['state' => 'retrying', 'error' => $e->getMessage()];
    } finally {
        if (is_resource($fh)) fclose($fh);
        flock($lock, LOCK_UN); fclose($lock);
    }
}
