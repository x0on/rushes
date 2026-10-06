<?php
// Self-contained fixtures; never reads or writes installation settings or media.
ob_start();
$root = (getenv('RUSHES_TEST_TMP') ?: sys_get_temp_dir()) . '/rushes-test-' . bin2hex(random_bytes(5));
mkdir($root); mkdir("$root/app"); mkdir("$root/app/db"); mkdir("$root/archive");
foreach (glob(__DIR__ . '/../app/db/*.php') as $f) copy($f, "$root/app/db/" . basename($f));
copy(__DIR__ . '/../app/rules.json', "$root/app/rules.json");
file_put_contents("$root/app/settings.json", json_encode(['archive'=>[
    'web'=>"$root/app", 'local'=>"$root/archive", 'as_seen_from_helper'=>"$root/archive"],
    'helper'=>['mode'=>'built_in'], 'organise'=>['departments'=>[], 'shelves'=>'Library']]));
require "$root/app/db/transfers.php";
require "$root/app/db/sync.php";
db_init();
function check($ok, $what) { if (!$ok) throw new RuntimeException($what); echo "PASS $what\n"; }
function landed($path, $size) {
    global $root;
    $_POST = ['files'=>"$path\t$size"];
    ob_start(); include web_dir() . "/db/landed.php"; $r = json_decode(ob_get_clean(), true);
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
require_once "$root/app/db/analysis.php";
$fp = str_repeat('ab', 12);
@mkdir("$root/archive/_rushes/analysis/ab", 0777, true);
file_put_contents("$root/archive/_rushes/analysis/ab/$fp.json", json_encode(['fingerprint' => $fp,
    'file' => "$root/archive/park/loop.mp4", 'seen_at' => ["$root/archive/park/loop.mp4"], 'model' => 'qwen',
    'part_of_day' => 'afternoon', 'whisper' => 'w',
    'shots' => [['shot' => 0, 'start' => 0, 'end' => 7.6, 'description' => 'An aerial view of a water park',
                 'text_on_screen' => ['RIVERTON', 'CENTRAL PARK'], 'themes' => ['Parks & Recreation'], 'tags' => ['aerial'],
                 'shot_size' => 'wide', 'people' => 'few', 'light' => 'daylight', 'mood' => 'calm'],
                ['shot' => 1, 'parse_error' => true, 'description' => '']],
    'speech' => ['language' => 'es', 'segments' => [['start' => 3.2, 'end' => 5, 'text' => 'Bienvenidos al parque central']]]]));
touch("$root/archive/_rushes/analysis/ab/$fp.json", time() - 100);
@mkdir("$root/archive/_rushes/analysis/cd", 0777, true);
file_put_contents("$root/archive/_rushes/analysis/cd/" . str_repeat('cd', 12) . ".json",
    json_encode(['fingerprint' => str_repeat('cd', 12), 'file' => "$root/archive/x.mov", 'shots' => []]));
touch(web_dir() . '/describe-status.tsv');                  // the helper says it is describing
check(analysis_import()['described_files'] === 2, 'descriptions in _rushes/analysis are imported into search');
$m = analysis_search('central park');
check($m['count'] === 1 && $m['rows'][0]['kind'] === 'shot' && $m['rows'][0]['what'] === 'An aerial view of a water park',
      'words on screen find the shot');
check(analysis_search('parque bienvenidos')['rows'][0]['kind'] === 'speech', 'spoken words find the moment they were said');
check(analysis_search('parks recreation')['count'] === 1 && analysis_search('nothing here')['count'] === 0, 'themes are searchable; nothing invented');
check(analysis_import()['described_files'] === 1, 'importing again reads only the newest, not everything');
touch(web_dir() . '/describe-status.tsv', time() - 3600);    // describing ended an hour ago
check((analysis_import()['state'] ?? '') === 'quiet', 'idle: the descriptions folder on VIDEO is not looked through');
require_once "$root/app/db/prepare.php";
file_put_contents("$root/archive/b4k.mov", 'four'); landed("$root/archive/b4k.mov", 4);
file_put_contents("$root/app/proxy-made.tsv",
    "$root/archive/b4k.mov\t1700000000\t  Duration: 00:02:05.50, start: 0.000000, bitrate: 400000 kb/s     Stream #0:0: Video: h264 (High), yuv420p(tv), 3840x2160 [SAR 1:1 DAR 16:9], 100000 kb/s, 29.97 fps, 29.97 tbr \n" .
    "$root/archive/b4k.mov\t17000");                                   // a line still being written
check(media_import()['added'] === 1, 'proxies made are read into the media ledger');
$ledger = fn() => db()->querySingle("SELECT width||'x'||height||' '||fps||' '||codec||' '||duration FROM files JOIN media ON file_id=id WHERE name='b4k.mov'");
check($ledger() === '3840x2160 29.97 h264 125.5', 'the ledger knows the original is 4K, whatever size its proxy is');
check(media_import()['added'] === 0, 'a half-written line is left for next time, nothing read twice');
// What cameras write, as FFmpeg prints it (lines joined with spaces by proxy.sh).
$j = fn($t) => str_replace("\n", ' ', $t);
$fx6 = media_probe($j("  Metadata:\n    major_brand     : XAVC\n    creation_time   : 2024-05-03T14:22:10.000000Z\n    com.apple.quicktime.make: Sony\n    com.apple.quicktime.model: ILME-FX6\n  Duration: 00:01:02.03, start: 0.000000, bitrate: 50000 kb/s\n  Stream #0:0: Video: h264 (High 4:2:2 Intra) (avc1 / 0x31637661), yuv422p10le, 3840x2160 [SAR 1:1 DAR 16:9], 23.98 fps\n    Metadata:\n      timecode        : 14:22:09:12"));
check($fx6['camera'] === 'Sony ILME-FX6' && $fx6['recorded'] === '2024-05-03 14:22:10' && $fx6['timecode'] === '14:22:09:12' && $fx6['width'] === 3840,
      'a camera file gives its make and model, its clock and its timecode');
$mxf = media_probe($j("  Metadata:\n    company_name    : Sony\n    product_name    : PXW-Z750\n    modification_date: 1970-01-01T00:00:00.000000Z\n    reel_name       : A001\n    timecode        : 01:00:00;00\n  Duration: 00:00:10.00\n  Stream #0:0: Video: mpeg2video, yuv422p, 1920x1080, 29.97 fps"));
check($mxf['camera'] === 'Sony PXW-Z750' && $mxf['reel'] === 'A001' && $mxf['recorded'] === null && $mxf['timecode'] === '01:00:00;00',
      'an MXF gives its reel; a clock never set (1970) is left out, not shown as a date');
$dji = media_probe($j("  Metadata:\n    creation_time   : 2025-06-01T19:40:00.000000Z\n    encoder         : DJI Mini 4 Pro\n  Duration: 00:00:30.00\n  Stream #0:0: Video: hevc (Main 10), yuv420p10le, 3840x2160, 29.97 fps"));
check($dji['camera'] === 'DJI Mini 4 Pro' && media_probe($j("    encoder : Lavf58.76.100"))['camera'] === null, 'a drone names itself; a program that re-saved a file is not a camera');
// Copies: the helper says whether each file's original is still where it came from.
$copies = function (array $post) use ($root) { $_POST = $post; ob_start(); include "$root/app/db/copies.php"; return json_decode(ob_get_clean(), true); };
$r = $copies(['copies' => "$root/archive/b4k.mov\toldserver\t1\n$root/archive/a.mov\toldserver\t0\n$root/archive/nowhere.mov\toldserver\t1", 'done' => '1']);
$sum = json_decode(file_get_contents("$root/app/copies-summary.json"), true);
check($r['counted'] === 2 && $r['not_in_search'] === 1 && $sum['twice'][0] === 1 && $sum['lost'][0] === 1 && $sum['places']['oldserver'] === ['there' => 1, 'looked' => 2],
      'copies: a file whose original is still there is kept twice; one whose original went is only in the archive');
check(str_contains(json_encode((function () use ($root) { ob_start(); include "$root/app/db/state.php"; return ob_get_clean(); })()), 'now exist only in the archive'),
      'Overview warns when files lose their second copy');
// Relinking a Premiere project: every tidy-up (and undo) played forward, matched by the part inside the archive.
@mkdir("$root/archive/_rushes/origin", 0777, true);
$H = "$root/archive";      // the helper sees the archive at the same place in this fixture
file_put_contents("$root/archive/_rushes/origin/20260101-000000 archive tidy.tsv",
    "moved\t$H/ARCHIVE/pa/Parks/Kite/A001.MXF\t$H/Library/PARKS/Kite/A001.MXF\t5\t\n" .
    "moved\t$H/ARCHIVE/pa/Parks/Kite/A002.MXF\t$H/Library/PARKS/Kite/A002.MXF\t5\t\n");
file_put_contents("$root/archive/_rushes/origin/20260102-000000 archive untidy.tsv",
    "moved\t$H/Library/PARKS/Kite/A002.MXF\t$H/ARCHIVE/pa/Parks/Kite/A002.MXF\t5\tundo\n");
@mkdir("$root/archive/Library/PARKS/Kite", 0777, true); file_put_contents("$root/archive/Library/PARKS/Kite/A001.MXF", 'x');
$_POST = ['paths' => json_encode(['/Volumes/VIDEO/ARCHIVE/pa/Parks/Kite/A001.MXF', 'Z:\ARCHIVE\pa\Parks\Kite\A001.MXF',
                                  '/Volumes/VIDEO/ARCHIVE/pa/Parks/Kite/A002.MXF', '/Users/me/Music/song.wav', 'A001.MXF']), 'pass' => 'rushes'];   // signed in
ob_start(); include "$root/app/db/relink.php"; $rl = json_decode(ob_get_clean(), true);
check(($rl['map']['/Volumes/VIDEO/ARCHIVE/pa/Parks/Kite/A001.MXF'] ?? '') === '/Volumes/VIDEO/Library/PARKS/Kite/A001.MXF'
      && ($rl['map']['Z:\ARCHIVE\pa\Parks\Kite\A001.MXF'] ?? '') === 'Z:\Library\PARKS\Kite\A001.MXF',
      'relink: a moved clip is pointed at its new place, however the editor reaches the archive (Mac or Windows)');
check(count($rl['map']) === 2 && $rl['missing'] === [],
      'relink: a clip put back by an undo, a file outside the archive and a bare file name are left as they are');
mkdir("$root/archive/shelf"); rename("$root/archive/b4k.mov", "$root/archive/shelf/b4k.mov");
db()->exec("INSERT INTO moments (fp, path, kind, shot, start_s, end_s, what) VALUES ('ff', '$root/archive/b4k.mov', 'shot', 0, 0, 1, 'a kite')");
$_POST = ['moves' => "$root/archive/b4k.mov\t$root/archive/shelf/b4k.mov"];
ob_start(); include "$root/app/db/moved.php"; ob_end_clean();
check(db()->querySingle("SELECT path FROM files WHERE name='b4k.mov'") === "$root/archive/shelf/b4k.mov" && $ledger() === '3840x2160 29.97 h264 125.5',
      'after a tidy-up moves the original, the ledger still follows it');
check(db()->querySingle("SELECT path FROM moments WHERE fp='ff'") === "$root/archive/shelf/b4k.mov",
      'and so does what describing found in it (search moments, Premiere markers)');
db()->exec("DELETE FROM moments WHERE fp='ff'");
// A folder's plan comes from the catalogue at once: videos, what already has a proxy, what is left to read.
@mkdir("$root/archive/PARKS/day1", 0777, true); @mkdir("$root/archive/PROXIES/PARKS/day1", 0777, true);
foreach (['a.MOV' => 100, 'b.mxf' => 200, 'notes.txt' => 5] as $n => $size) {
    file_put_contents("$root/archive/PARKS/day1/$n", str_repeat('x', $size)); landed("$root/archive/PARKS/day1/$n", $size);
}
file_put_contents("$root/archive/PARKS0.mov", 'not in the folder'); landed("$root/archive/PARKS0.mov", 17);
file_put_contents("$root/archive/PROXIES/PARKS/day1/a.mp4", 'proxy');
$guess = prepare_plan('PARKS', false, false);
check($guess['videos'] === 2 && $guess['have'] === 0 && !is_file(web_dir() . '/prepare-plan.json'),
      'a page asking gets a first guess from search alone: no proxy looked up on VIDEO, nothing kept');
$plan = prepare_plan('PARKS', true);
check($plan['videos'] === 2 && $plan['have'] === 1 && $plan['bytes'] === 300 && $plan['to_read'] === 200,
      'a folder is planned at once from the catalogue: its videos, the ones with a proxy, what is left to read');
file_put_contents("$root/app/proxy-speed.tsv", "100000000\t10\tvideo chip\n300000000\t10\tvideo chip\n5\t0\tsoftware\n");
check(abs(proxy_rate() - 20000000) < 1, 'the speed is measured from the proxies really made (bytes per second)');
file_put_contents("$root/app/proxy-failed.tsv", "$root/archive/PARKS/day1/b.mxf\t1\tmoov atom not found\n$root/archive/PARKS/day1/a.MOV\t1\told failure\n$root/archive/OTHER/c.mov\t1\tx\n");
$f = proxy_failures('PARKS');
check(prepare_plan('PARKS', false, false)['failures'] === [] && count(prepare_plan('PARKS', true)['failures']) === 1
      && count(prepare_plan('PARKS', false, false)['failures']) === 1,
      'failures are looked up with the plan by the runner; a page gets what it found');
check(count($f) === 1 && $f[0]['file'] === 'day1/b.mxf' && $f[0]['why'] === 'moov atom not found',
      "a folder's failures say which file and why, and leave out ones made since and other folders");
// A pull for Premiere carries what describing found: a marker per shot and line spoken, at the right frame.
require_once "$root/app/db/analysis.php"; analysis_init();
db()->exec("INSERT INTO pulls (slug, name, created) VALUES ('p1', 'Kite day', 0)");
db()->exec("INSERT INTO pull_items (pull_id, rel, name, bytes, pos, added) VALUES (1, 'shelf/b4k.mov', 'b4k.mov', 3, 1, 0)");
db()->exec("INSERT INTO moments (fp, path, kind, shot, start_s, end_s, what, on_screen, themes) VALUES
    ('f', '$root/archive/shelf/b4k.mov', 'shot', 0, 2.0, 5.0, 'Children fly kites & laugh', 'KITE FEST 2024', 'Events · Parks & Recreation'),
    ('f', '$root/archive/shelf/b4k.mov', 'speech', null, 10.0, 12.0, 'Welcome everyone', '', '')");
$_GET = ['p' => 'p1', 'fmt' => 'premiere', 'base' => '/Volumes/VIDEO'];
ob_start(); include "$root/app/db/pull-export.php"; $xml = ob_get_clean();
$doc = simplexml_load_string(preg_replace('/<!DOCTYPE[^>]*>/', '', $xml));
$mk = $doc ? $doc->xpath('//clip/marker') : [];
check($doc && count($mk) === 2 && (string)$mk[0]->in === '60' && (string)$mk[1]->in === '300'
      && str_contains((string)$mk[0]->comment, 'On screen: KITE FEST 2024') && (string)$doc->xpath('//clip/rate/timebase')[0] === '30'
      && (string)$doc->xpath('//clip/logginginfo/description')[0] === 'Children fly kites & laugh',
      'a pull for Premiere carries a marker at each shot and line spoken, at its frame, and the description');
$_GET = ['p' => 'p1', 'fmt' => 'fcpxml', 'base' => '/Volumes/VIDEO'];
ob_start(); include web_dir() . "/db/pull-export.php"; $xml = ob_get_clean();
$doc = simplexml_load_string(preg_replace('/<!DOCTYPE[^>]*>/', '', $xml));
$mk = $doc ? $doc->xpath('//asset-clip/marker') : [];
check($doc && (string)$doc['version'] === '1.8' && count($mk) === 2 && (string)$mk[0]['start'] === '60060/30000s' && (string)$mk[1]['start'] === '300300/30000s'
      && (string)$doc->xpath('//asset')[0]['src'] === 'file:///Volumes/VIDEO/shelf/b4k.mov'
      && str_contains((string)$mk[0]['note'], 'KITE FEST 2024') && (string)$doc->xpath('//asset-clip/note')[0] === 'Children fly kites & laugh'
      && str_contains((string)$doc->xpath('//asset-clip/keyword')[0]['value'], 'Parks & Recreation'),
      'a pull for Final Cut and Resolve (FCPXML): the clip by its path, markers at their frames, description and themes');
$_GET = ['p' => 'p1', 'fmt' => 'fcpxml', 'base' => 'Z:\\'];
ob_start(); include web_dir() . "/db/pull-export.php"; $xml = ob_get_clean();
check(str_contains($xml, 'src="file:///Z:/shelf/b4k.mov"'), 'FCPXML for a PC points at the drive letter');
// HOW-IT-WORKS.md → Rushes' own backups: once a day a checked copy of the database, made safely while it is in use.
@unlink(web_dir() . '/db-copy.sqlite');
check(db_daily_copy()['state'] === 'copied' && (new SQLite3(web_dir() . '/db-copy.sqlite'))->querySingle("SELECT COUNT(*) FROM files") >= 1,
      'the database is checked and copied, whole');
check(db_daily_copy()['state'] === 'current', 'once a day, not every minute');
// The password is kept in a .php file that prints nothing if downloaded; an old .adminpass is moved into it once.
require_once web_dir() . "/db/auth.php";
@unlink(web_dir() . '/adminpass.php'); file_put_contents(web_dir() . '/.adminpass', 'oldword');   // an install from before
check(stored_pass() === 'oldword' && !file_exists(web_dir() . '/.adminpass') && is_file(web_dir() . '/adminpass.php'),
      'an old password file is moved into one that cannot be downloaded');
check(set_pass('new-word') && pass_ok('new-word') && !pass_ok('oldword') && !str_contains(file_get_contents(web_dir() . '/adminpass.php'), 'new-word'),
      'a changed password is kept only as a hash');
// What is waiting comes from the runner's list, never from reading VIDEO in a page request.
file_put_contents(web_dir() . "/waiting.tsv", "script\tproxy.sh\t" . str_repeat('a', 64) . "\t1\npage\tdb/x.php\t" . str_repeat('b', 64) . "\t2\nhelper\tabc123def456\nhelperfile\tingest.py\t" . str_repeat('c', 64) . "\n");
$sw = scripts_waiting();
check(count($sw) === 2 && $sw[1]['kind'] === 'page' && waiting_read()['helper'] === 'abc123def456',
      'updates waiting (scripts and pages) and the helper version are read from the runner\'s list');
check(waiting_read()['files'] === ['ingest.py' => str_repeat('c', 64)], "the helper's fingerprints, for its updates, come from the list too");
// The helper sends its history and section list; Rushes keeps only well-formed lines.
$_POST = ['file' => 'history', 'body' => "2026-10-01 10:00\tcopied\t/x\t1\t2\t3\t\nnot a history line\n"];
ob_start(); include web_dir() . "/db/status.php"; $r = json_decode(ob_get_clean(), true);
check($r['lines'] === 1 && str_starts_with(file_get_contents(web_dir() . "/ingest-history.tsv"), '2026-10-01 10:00'), 'the helper\'s history arrives over the network, junk left out');
// Which copy of a duplicate is kept: rules.json for every archive, Setup → 05
// for this one, the shelf for the project side. Nothing in dedupe.sh names a folder.
$s = settings(); $s['duplicates'] = ['never_keep' => ['Desk/Old'], 'card_dumps' => ['CARD DUMPS']]; save_settings($s); settings(true);
$lines = dedupe_rules_write(); $f = web_dir() . '/dedupe-rules.tsv';
check($lines !== null && in_array("1000\tcontains\t/@Recycle/", $lines, true) && in_array("500\tcontains\t/Desk Old/", $lines, true)
      && in_array("450\tcard\t/CARD DUMPS/", $lines, true) && in_array("450\tproject\t/Library/", $lines, true)
      && in_array("200\tmatches\t\\.(MXF|MOV|MP4)\\.(MXF|MOV|MP4)$", $lines, true) && file_get_contents($f) === implode("\n", $lines) . "\n",
      'duplicates: the rules come from rules.json, Setup and the shelf, as plain lines for dedupe.sh');
touch($f, 1000000000); clearstatcache(); dedupe_rules_write(); clearstatcache();
check(filemtime($f) === 1000000000, 'duplicates: rules that did not change are not rewritten, so the plan you saw is the one moved');
$s['organise']['shelves'] = ''; save_settings($s); settings(true);
check(shelf_name() === '' && !in_array("450\tproject\t/Library/", dedupe_rules_write(), true) && !is_dir(shelf_dir()),
      'no shelf chosen: no folder is guessed');
$s['organise']['shelves'] = 'Library'; save_settings($s); settings(true);
// The stock library is a section of Search: Projects/Stock Library on the shelf, browsed without words.
$arch = archive_dir();
@mkdir("$arch/Library/Projects/Stock Library/Music", 0777, true); @mkdir("$arch/Shoots/Library talk", 0777, true);
file_put_contents("$arch/Library/Projects/Stock Library/Music/song.wav", 'la'); landed("$arch/Library/Projects/Stock Library/Music/song.wav", 2);
file_put_contents("$arch/Shoots/Library talk/talk.mov", 'tk'); landed("$arch/Shoots/Library talk/talk.mov", 2);
$find = function (array $get) { $_GET = $get; ob_start(); include web_dir() . "/db/search.php"; return json_decode(ob_get_clean(), true); };
$r = $find(['in' => 'library']); $r2 = $find(['in' => 'library/sfx']); $r3 = $find(['q' => 'song', 'in' => 'library/music']);
check($r['total'] === 1 && $r['rows'][0]['name'] === 'song.wav' && $r2['total'] === 0 && $r3['total'] === 1,
      'Search: the stock library is its own section, and a shoot with "Library" in its name is not in it');
// What each file is (labels.php), on names from a real drive with every kind of media
require_once web_dir() . '/db/labels.php';
$out = ['mayor\'s message oct youtube.mp4' . "\t" . 900 => 'Mayor October/OUTPUT/Mayor\'s Message OCT youtube.mp4'];
foreach ([
    ['Mayor October/OUTPUT/Mayor\'s Message OCT youtube.mp4', 'deliverable'],
    ['Mayor October/pumpkins-carved-and-uncarved-at-autumn-pumpkin-pat-2026-10-02-21-34-17-utc.mov', 'stock'],
    ['MOSS MEDICAL/happy-upbeat-flow-2025-08-14-06-22-21-utc/File A 2-11_BearStockMusic - Happy Upbeat (Full Version).mp3', 'music'],
    ['MICROMOBILITY/02-VIDEO/Sound FX/camera-flash-2025-08-27-06-26-33-utc/Camera Flash.wav', 'sfx'],
    ['MOSS MEDICAL/classic-lower-thirds-for-after-effects-2025-07-16-06-35-04-utc/After Effects/Classic Lower Thirds.aep', 'template'],
    ['Mayor October/openart-download/openart-video_1791222979906_ac8e11e5_1791222980489_bf769783.mp4', 'ai'],
    ['Mayor October/youtube ENG.mp4', 'made_here'],
    ['Mayor October/A091C007_261001QK_CANON.mp4', 'camera'],
    ['Mayor October/DJI_0009.MOV', 'camera'],
    ['THIS IS WAY/Mayor\'s Message OCT youtube.mp4', 'deliverable_reused'],
    ['Doral__OpenGov Contracts Discussion_2026-07-24_shared_screen_with_speaker_view_rMCW1X+C.mp4', 'recording'],
    ['Mayor October/Mayor\'s Message OCT  youtube.00_00_04_14.Still002.jpg', 'frame_grab'],
    ['MILA/_9AR9023.NEF', 'photo'],
    ['MICROMOBILITY/Shoots/20260205MicroMobility-Fondos.psd', 'design_editable'],
    ['2015 Doral Logo - white.png', 'design'],
    ['LOGOS/Simple and Elegant Logo Reveal/frames/frame_0001.png', 'image'],      // a logo-reveal pack's frame is not a logo
    ['VOICE OVER/Ale1_mixdown.wav', 'voiceover'],
    ['MAYOR LOGO/._intro.mov', ''],
    ['canon-lut-201911/full-to-full-range/BT709.cube', ''],
] as [$p, $want]) {
    $got = label_of($p, $want === 'deliverable_reused' || $want === 'deliverable' ? 900 : 5, $out)[0];
    check($got === $want, "labels: $p is " . ($want ?: 'ignored') . ($got === $want ? '' : " (got $got)"));
}
// A light video used as it is (runner.py): no proxy, the original plays, and its folder counts it as done
require_once web_dir() . '/db/prepare.php';
$talk = "$arch/Shoots/Library talk/talk.mov";
file_put_contents(web_dir() . '/proxy-made.tsv', "$talk\t" . time() . "\tDuration: 00:00:05.00 Video: h264 (High), yuv420p, 1280x720, 25 fps\t1\n");
meta_set('proxy_made_at', '0'); media_import();
check(db()->querySingle("SELECT asis FROM media m JOIN files f ON f.id = m.file_id WHERE f.path = '" . SQLite3::escapeString($talk) . "'") === '1'
      && prepare_plan('Shoots/Library talk', true)['have'] === 1, 'a light video is used as it is: no proxy, and its folder counts it as done');
$_GET = ['p' => $talk]; ob_start(); include web_dir() . '/db/play.php'; $played = ob_get_clean();
check($played === 'tk', 'Search plays a light video from the original itself');
$_GET = [];
echo "Server tests complete. Fixture: $root\n";
