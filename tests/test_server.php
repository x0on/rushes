<?php
// Self-contained fixtures; never reads or writes installation settings or media.
ob_start();
$root = (getenv('RUSHES_TEST_TMP') ?: sys_get_temp_dir()) . '/rushes-test-' . bin2hex(random_bytes(5));
mkdir($root); mkdir("$root/app"); mkdir("$root/app/db"); mkdir("$root/archive");
foreach (glob(__DIR__ . '/../app/db/*.php') as $f) copy($f, "$root/app/db/" . basename($f));
copy(__DIR__ . '/../app/rules.json', "$root/app/rules.json");
file_put_contents("$root/app/settings.json", json_encode(['archive'=>[
    'web'=>"$root/app", 'local'=>"$root/archive", 'as_seen_from_helper'=>"$root/archive"],
    'helper'=>['mode'=>'built_in'], 'organise'=>['departments'=>[]]]));
require "$root/app/db/transfers.php";
require "$root/app/db/sync.php";
db_init();
function check($ok, $what) { if (!$ok) throw new RuntimeException($what); echo "PASS $what\n"; }
function landed($path, $size) {
    global $root;
    $_POST = ['files'=>"$path\t$size"];
    ob_start(); include "$root/app/db/landed.php"; $r = json_decode(ob_get_clean(), true);
    return $r;
}
file_put_contents("$root/archive/a.mov", 'abc');
$r = landed("$root/archive/a.mov", 3);
check($r['accepted'] === ["$root/archive/a.mov"], 'incremental upload is acknowledged');
$id = db()->querySingle('SELECT id FROM files');
landed("$root/archive/a.mov", 3);
check((int)db()->querySingle('SELECT COUNT(*) FROM files') === 1 && (int)meta_get('files_imported') === 1, 'retry does not duplicate rows or counts');
check((int)db()->querySingle('SELECT id FROM files') === (int)$id, 'incremental updates preserve file identity');
$r = landed("$root/archive/a.mov", 4);
check($r['refused'] === 1 && !$r['accepted'], 'size mismatch stays unacknowledged');
// A storage snapshot predates an incremental arrival.
file_put_contents("$root/app/manifest.tsv", "3\t$root/archive/a.mov\n");
touch("$root/app/manifest.tsv", time()-10);
file_put_contents("$root/app/manifest-started.txt", (string)(time()-20));
file_put_contents("$root/archive/new.mov", 'new!'); landed("$root/archive/new.mov", 4);
$r = sync_search();
check($r['state'] === 'updated', 'automatic reconciliation succeeds');
check((int)db()->querySingle('SELECT COUNT(*) FROM files') === 2, 'snapshot reconciliation preserves incremental arrivals');
check((int)db()->querySingle("SELECT id FROM files WHERE name='a.mov'") === (int)$id, 'reconciliation preserves file identity');
check(sync_search()['state'] === 'current', 'unchanged inventory is a no-op');
file_put_contents("$root/app/manifest.tsv", ''); clearstatcache();
$r = sync_search(true);
check($r['state'] === 'retrying' && (int)db()->querySingle('SELECT COUNT(*) FROM files') === 2, 'empty inventory keeps the working search catalog');
file_put_contents("$root/app/ingest-queue.tsv", "copy\t/source/legacy\n");
file_put_contents("$root/app/ingest-sections.tsv", "section\t/source/legacy\t2\t100\n");
$adopted = transfer_current();
check($adopted['items'][0]['source'] === '/source/legacy', 'existing queue is adopted without requiring a running helper');
check(transfer_summary($adopted)['done_bytes'] === 0, 'legacy history does not fabricate completed bytes');
transfer_select(['/source/A','/source/B'], ['/source/A'=>[1,30], '/source/B'=>[1,70]]);
$j = transfer_latest(); $job = $j['id'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['job'=>$job,'source'=>'/source/A','phase'=>'done','total_bytes'=>'30','total_files'=>'1',
    'done_bytes'=>'30','done_files'=>'1','copied_bytes'=>'30','already_bytes'=>'0','failed'=>'0','measured'=>'1'];
ob_start(); include "$root/app/db/transfer.php"; ob_end_clean();
$s = transfer_summary(transfer_latest());
check($s['pct'] === 30 && $s['folders_done'] === 1, 'overall progress is weighted by bytes across the selection');
transfer_select(['/source/B'], []);
check(transfer_latest()['id'] === $job && transfer_summary(transfer_latest())['pct'] === 30, 'editing the remaining queue retains finished work');
db()->exec("UPDATE transfer_items SET phase='copying',updated=" . (time()-100) . " WHERE source='/source/B'");
$s = transfer_summary(transfer_latest());
check($s['phase'] === 'interrupted' && $s['pct'] === 30, 'stale helper retains progress and becomes interrupted');
db()->exec("UPDATE transfer_items SET phase='done',done_bytes=70,copied_bytes=70,measured=1 WHERE source='/source/B'");
check(transfer_summary(transfer_latest())['pct'] === 100, 'only fully completed selection reaches 100 percent');
transfer_select(['/source/C'], ['/source/C'=>[0,0]]);
check(transfer_latest()['id'] !== $job && transfer_summary(transfer_latest())['pct'] === null, 'new unknown-size job does not inherit a completed percentage');
db()->exec("INSERT INTO transfer_items (job_id,source,total_bytes,done_bytes,phase) VALUES ('old','/source/D',100,100,'done')");
db()->exec("INSERT INTO transfer_jobs VALUES ('old'," . (time()+10) . ")");
file_put_contents("$root/app/ingest-history.tsv", "2026-09-20 10:00\tcopied\t/source/D\t3\t40\t9\t\n2026-09-21 10:00\tcopied\t/source/D\t1\t20\t9\t\n");
$s = transfer_summary(transfer_latest());
check($s['copied_bytes'] === 60 && $s['already_bytes'] === 40, 'folders finished before this transfer show what was copied and what was already there');
echo "Server tests complete. Fixture: $root\n";
