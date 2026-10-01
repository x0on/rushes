<?php
// junk.php — what is on the share that regenerates itself?
//
//   junk.php            show it
//   junk.php?write=1    write the list the cleanup job consumes
//   junk.php?json=1     the same numbers for Manage → Cache
//
// Editing apps scatter caches through the archive: Premiere writes .pek and
// .cfa, Capture One writes whole CaptureOne/Cache/Proxies/Settings trees.
// None of it is footage. All of it is rebuilt on demand from the originals.
// It is only here because it was made beside the media rather than on a
// local disk where it belongs.
//
// This reads the database rather than sweeping 36 TB again — the question is
// "which paths look like cache", and the database already knows every path.

require __DIR__ . '/schema.php';
header('Content-Type: text/plain');

// sweep: regenerated from the originals, safe to move out.
// What counts as rebuildable scratch, and what is crash recovery that must never
// be swept, both come from rules.json. Somebody using different editing software
// edits that file; nobody edits this one.
$GROUPS = [];
foreach (cache_groups('sweep') as $g) $GROUPS[$g['label']] = cache_sql($g);

// keep: looks like clutter, is not. Reported so the size is visible, never
// written to the move list.
//
// Premiere's Auto-Save folder is what you open when a project will not —
// exactly the rescue path that mattered this morning. It is the only copy of
// an edit that crashed before it was saved. Sweeping it would be tidying away
// the fire extinguisher.
$KEEP = [];
foreach (cache_groups('keep') as $g) $KEEP[$g['label']] = cache_sql($g);

$db = db();
$total_n = 0; $total_b = 0;
$sqls = [];

if (($_GET['json'] ?? '') === '1') {
    header('Content-Type: application/json');
    $sweep = []; $keep = []; $ex = [];
    foreach ($GROUPS as $label => $cond) {
        $r = $db->querySingle("SELECT COUNT(*) n, COALESCE(SUM(bytes),0) b FROM files WHERE $cond", true);
        $sweep[] = ['label' => $label, 'files' => (int)$r['n'], 'bytes' => (int)$r['b']];
        if ($r['n']) $sqls[] = $cond;
    }
    foreach ($KEEP as $label => $cond) {
        $r = $db->querySingle("SELECT COUNT(*) n, COALESCE(SUM(bytes),0) b FROM files WHERE $cond", true);
        $why = '';
        foreach (cache_groups('keep') as $g) if ($g['label'] === $label) $why = $g['why'] ?? '';
        $keep[] = ['label' => $label, 'files' => (int)$r['n'], 'bytes' => (int)$r['b'], 'why' => $why];
    }
    if ($sqls) {
        $q = $db->query("SELECT path FROM files WHERE " . implode(' OR ', $sqls) . " LIMIT 6");
        while ($r = $q->fetchArray(SQLITE3_ASSOC)) $ex[] = substr($r['path'], strlen(archive_dir()) + 1);
    }
    echo json_encode(['sweep' => $sweep, 'keep' => $keep, 'examples' => $ex], JSON_UNESCAPED_SLASHES);
    exit;
}

printf("%-22s %10s  %s\n", 'what', 'files', 'size');
echo str_repeat('-', 52) . "\n";
foreach ($GROUPS as $label => $cond) {
    $r = $db->querySingle("SELECT COUNT(*) n, COALESCE(SUM(bytes),0) b FROM files WHERE $cond", true);
    printf("%-22s %10s  %8.1f GB\n", $label, number_format($r['n']), $r['b'] / 1073741824);
    $total_n += $r['n']; $total_b += $r['b'];
    if ($r['n']) $sqls[] = $cond;
}
echo str_repeat('-', 52) . "\n";
printf("%-22s %10s  %8.1f GB\n", 'to move out', number_format($total_n), $total_b / 1073741824);

echo "\nleft alone:\n";
foreach ($KEEP as $label => $cond) {
    $r = $db->querySingle("SELECT COUNT(*) n, COALESCE(SUM(bytes),0) b FROM files WHERE $cond", true);
    printf("  %-20s %10s  %8.1f GB   (crash recovery \u{2014} never swept)\n",
        $label, number_format($r['n']), $r['b'] / 1073741824);
}

if (!$sqls) exit("\nNothing to clean.\n");

echo "\na few examples:\n";
$q = $db->query("SELECT path FROM files WHERE " . implode(' OR ', $sqls) . " LIMIT 8");
while ($r = $q->fetchArray(SQLITE3_ASSOC)) echo '  ' . $r['path'] . "\n";

if (($_GET['write'] ?? '') !== '1') {
    echo "\nNothing written. Add ?write=1 to prepare the list,\n";
    echo "then press \"Move them out\" in Manage → Cache to move it aside.\n";
    exit;
}

// The cleanup job already reads this file and moves each line into the
// holding folder. Reuse it rather than inventing a second way to move things.
// Writing the list is the first half of "Move them out": signed in only.
require_once __DIR__ . '/auth.php';
if (!may_act()) { http_response_code(403); exit("Sign in first.\n"); }
$out = web_dir() . '/cache-files.txt';
$fh = fopen($out, 'w');
$n = 0;
$q = $db->query("SELECT path FROM files WHERE " . implode(' OR ', $sqls) . " ORDER BY path");
while ($r = $q->fetchArray(SQLITE3_ASSOC)) { fwrite($fh, $r['path'] . "\n"); $n++; }
fclose($fh);

printf("\nwrote %s paths to %s\n", number_format($n), $out);
echo "Nothing has moved yet. Manage -> Cache -> \"Move them out\".\n";
echo "They go to the holding folder first, same as everything else.\n";
