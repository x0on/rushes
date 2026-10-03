<?php
ob_start();
// The pages still render and agree after the merge: live helper status and the
// saved transfer side by side. Self-contained fixture, like test_server.php.
$root = (getenv('RUSHES_TEST_TMP') ?: sys_get_temp_dir()) . '/rushes-pages-' . bin2hex(random_bytes(5));
mkdir($root); mkdir("$root/app"); mkdir("$root/app/db"); mkdir("$root/archive");
foreach (glob(__DIR__ . '/../app/*.{php,css,json}', GLOB_BRACE) as $f) copy($f, "$root/app/" . basename($f));
foreach (glob(__DIR__ . '/../app/db/*.php') as $f) copy($f, "$root/app/db/" . basename($f));
file_put_contents("$root/app/settings.json", json_encode(['archive'=>[
    'web'=>"$root/app", 'local'=>"$root/archive", 'as_seen_from_helper'=>"$root/archive", 'label'=>'VIDEO'],
    'helper'=>['mode'=>'built_in'], 'projects'=>['aside_days'=>90], 'organise'=>['shelves'=>'Library', 'departments'=>[['name'=>'Parks','folder'=>'PARKS']]]]));
function check($ok, $what) { if (!$ok) throw new RuntimeException("FAIL $what"); echo "PASS $what\n"; }
$now = time();
file_put_contents("$root/app/ingest-status.tsv", "ts\t$now\nphase\tcopying\nsource\t/src/Parks\ncopied\t2\nof\t4\nnew_bytes\t400\ndone_bytes\t200\nrate\t100\neta\t2\nfile\tA001.MXF\n");
file_put_contents("$root/app/ingest-history.tsv", "2026-09-29 10:00\tcopied\t/src/Parks\t0\t0\t0\t\n2026-09-29 10:01\ttidied\ttidy 1\t3\t30\t0\t\n"
    . "2026-09-29 11:00\tcopied\t/Volumes/VIDEO/Library/PARKS/2026/20260929 Kite\t40\t4000\t60\t\n"
    . "2026-09-29 11:05\tcopied\t/Volumes/VIDEO/Library/PARKS/2026/20260930 Kite\t39\t3900\t60\t1 could not be copied\n");
file_put_contents("$root/app/ingest-queue.tsv", "ingest\t/Volumes/CARD\t/Volumes/VIDEO/Library/PARKS/2026/20260929 Kite\n"
    . "ingest\t/Volumes/CARD\t/Volumes/VIDEO/Library/PARKS/2026/20260930 Kite\n");
file_put_contents("$root/app/runner-alive.txt", (string)$now);
require "$root/app/db/transfers.php";
db_init();
transfer_select(['/src/Parks'], ['/src/Parks'=>[4,400]]);

$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start(); include "$root/app/db/state.php"; $s = json_decode(ob_get_clean(), true);
check(is_array($s), 'state.php answers with JSON');
// The NAS's disks, from the runner's copy of /proc/mdstat: a rebuild is said at the top, in its own numbers.
$sys13 = "md13 : active raid1 sdh4[32] sda4[0]\n      458880 blocks super 1.0 [32/8] [UUUUUUUU________________________]\n";
$r = raid_said("md1 : active raid5 sdh3[8] sda3[0]\n      54613148160 blocks super 1.0 level 5, 512k chunk, algorithm 2 [8/7] [UUUUUU_U]\n"
    . "      [====>......]  recovery = 23.4% (1827001234/7801878272) finish=536.2min speed=186000K/sec\n" . $sys13);
check($r && str_contains($r['title'], 'rebuilding its disks: 23.4% done, about 8.9 hours left') && str_contains($r['body'], '182 MB/s'), 'a rebuilding RAID is said at the top, in the system\'s own numbers');
$r = raid_said("md1 : active raid5 sdh3[8] sda3[0]\n      54613148160 blocks [8/7] [UUUUUU_U]\n" . $sys13);
check($r && $r['title'] === 'A disk is missing from the NAS (md1)', 'a data array missing a disk is said');
check(raid_said("md1 : active raid5 sdh3[8] sda3[0]\n      54613148160 blocks [8/8] [UUUUUUUU]\n" . $sys13) === null, 'a healthy one says nothing, and the system arrays\' empty slots are not a missing disk');
check(count($s['repeats'] ?? []) === 5 && $s['repeats'][0][0] === 'The runner on the archive machine', 'Overview lists what runs by itself (rule 5)');
check($s['copy']['phase'] === 'copying' && $s['copy']['rate'] === 100 && $s['copy']['file'] === 'A001.MXF', 'live helper detail comes through');
check($s['transfer'] && $s['transfer']['folders'] === 1, 'the saved transfer comes through beside it');
check(!array_filter($s['recent'], fn($r) => $r['files'] === 0 && $r['what'] === 'brought over'), 'empty retries stay out of Activity');
check((bool)array_filter($s['recent'], fn($r) => $r['what'] === 'moved onto the shelf'), 'tidy-ups keep their own words');
check(count($s['ingests']) === 2 && $s['ingests'][0]['state'] === 'done' && $s['ingests'][0]['failed'] === 0 && $s['ingests'][1]['failed'] === 1,
      'a landed card says how many files could not be copied (Ingest says safe to format only at none)');

ini_set('session.save_path', "$root/app"); session_start(); $_SESSION['rushes_in'] = true;
db()->exec("INSERT INTO projects (path, name, host, watcher, saved, seen, files, outside, missing, shoot, state)
            VALUES ('Parks/Kite.prproj', 'Kite', 'edit-1', 'ab12cd34ef567890', " . ($now - 86400 * 12) . ", $now, 12, 2, 'a.wav;b.mov', '', 'active')");
db()->exec("INSERT INTO projects (path, name, saved, archived, missing, state, aside_at)
            VALUES ('Parks/Old/Old.prproj', 'Old', " . ($now - 86400 * 200) . ", 'x', '', 'aside', $now)");
ob_start(); include "$root/app/db/admin.php"; $html = ob_get_clean();
check(str_contains($html, 'id="pane-projects"') && str_contains($html, 'Parks/Kite.prproj') && str_contains($html, '>resting'),
      "Editors' projects: each one the Watchers reported, and one not saved for 10 days is resting (nothing moves)");
check(str_contains($html, 'data-back="Parks/Old"') && str_contains($html, 'stays: no copy of the project in the archive yet'),
      "a folder moved aside has Bring it back; a resting one says why it will not be moved aside");
check(str_contains($html, 'id="now"') && str_contains($html, 'id="transferSummary"'), 'Overview has both the live card and the transfer card');
check(str_contains($html, 'helperNow') && str_contains($html, 'tokens.css?v='), 'shared top bar with live words and a fresh stylesheet');
echo "Page tests complete.\n";
