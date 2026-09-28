<?php
// import.php — build the database from the manifest the NAS already writes.
//
//   http://your-archive/db/import.php          normal
//   .../import.php?src=/share/Web/manifest.tsv  a different source
//
// Streams the file line by line: the manifest is 120 MB and PHP has a 128 MB
// memory limit, so reading it whole would die. Prints as it goes, because a
// silent page is indistinguishable from a hung one.

require __DIR__ . '/schema.php';
header('Content-Type: text/plain');
set_time_limit(0);
while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

$src = $_GET['src'] ?? web_dir() . '/manifest.tsv';
if (!is_readable($src)) exit("cannot read $src\nRun Rebuild the file list in Manage → Jobs and tools first.\n");

printf("reading %s (%.1f MB, written %s)\n\n",
    $src, filesize($src) / 1048576, date('Y-m-d H:i', filemtime($src)));

db_init();
$db = db();
$t0 = time();

// Build into a fresh table and swap at the end. A half-finished import must
// never become the live index — that is the empty-manifest mistake again.
$db->exec('DROP TABLE IF EXISTS files_new');
$db->exec("CREATE TABLE files_new (
    id INTEGER PRIMARY KEY, path TEXT NOT NULL, name TEXT NOT NULL, ext TEXT,
    kind TEXT, bytes INTEGER, dept TEXT, year TEXT, event TEXT, why TEXT, seen_at INTEGER)");

$ins = $db->prepare('INSERT INTO files_new (path,name,ext,kind,bytes,dept,year,event,why,seen_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?)');
$now = time();
$n = 0; $bytes = 0; $skipped = 0;
$byKind = [];

$fh = fopen($src, 'r');
$db->exec('BEGIN');
while (($line = fgets($fh)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($line === '') continue;
    [$size, $path] = array_pad(explode("\t", $line, 2), 2, null);
    if ($path === null || $path === '') { $skipped++; continue; }

    // the NAS's own bookkeeping is not archive content
    if (str_contains($path, '/.@__thumb/') || str_contains($path, '/@Recycle/')
        || str_contains($path, '/@eaDir/') || str_ends_with($path, '/.DS_Store')) {
        $skipped++; continue;
    }

    $c = classify($path);
    $ins->bindValue(1, $path);
    $ins->bindValue(2, $c['name']);
    $ins->bindValue(3, $c['ext']);
    $ins->bindValue(4, $c['kind']);
    $ins->bindValue(5, (int)$size, SQLITE3_INTEGER);
    $ins->bindValue(6, null);                 // dept comes later, from the mapping sheet
    $ins->bindValue(7, $c['year']);
    $ins->bindValue(8, $c['event']);
    $ins->bindValue(9, $c['why']);
    $ins->bindValue(10, $now, SQLITE3_INTEGER);
    $ins->execute();
    $ins->reset();

    $n++; $bytes += (int)$size;
    $byKind[$c['kind']] = ($byKind[$c['kind']] ?? 0) + 1;
    if ($n % 100000 === 0) {
        $db->exec('COMMIT'); $db->exec('BEGIN');
        printf("  %s files  (%.0fs)\n", number_format($n), time() - $t0);
    }
}
$db->exec('COMMIT');
fclose($fh);

printf("\n  %s files read, %s skipped as system junk\n", number_format($n), number_format($skipped));

if ($n < 1000) {
    $db->exec('DROP TABLE files_new');
    exit("\nFAR TOO FEW. The old index is untouched.\nCheck the file list on the NAS before trying again.\n");
}

echo "\nindexing…\n";
$db->exec('BEGIN');
$db->exec('DROP TABLE IF EXISTS files');
$db->exec('ALTER TABLE files_new RENAME TO files');
$db->exec('COMMIT');
$db->exec('CREATE INDEX IF NOT EXISTS i_files_name  ON files (name)');
$db->exec('CREATE INDEX IF NOT EXISTS i_files_kind  ON files (kind)');
$db->exec('CREATE INDEX IF NOT EXISTS i_files_dept  ON files (dept)');
$db->exec('CREATE INDEX IF NOT EXISTS i_files_bytes ON files (bytes)');
$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS i_files_path ON files (path)');

meta_set('files_imported', (string)$n);
meta_set('imported_at', (string)time());
meta_set('source_written', (string)filemtime($src));

printf("\ndone in %ds\n", time() - $t0);
printf("  %s files, %.2f TB\n", number_format($n), $bytes / 1099511627776);
arsort($byKind);
foreach ($byKind as $k => $c) printf("  %-9s %s\n", $k, number_format($c));
printf("\ndatabase: %.0f MB at %s\n", filesize(DB_PATH) / 1048576, DB_PATH);
