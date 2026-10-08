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
// A page that ends the script (a sign-in page, a redirect) must not end the test as a pass.
$done = false;
register_shutdown_function(function () { global $done; if (!$done) { echo "FAIL the test ended early: a page stopped it\n"; exit(1); } });
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
check(dept_folder('Parks') === 'Parks', 'a link to a folder not on the shelf is not used: the new folder takes the proper name');
mkdir("$root/archive/Library/PARKS", 0777, true);
check(dept_folder('Parks') === 'PARKS', 'a link to a folder on the shelf is used as it is');
rmdir("$root/archive/Library/PARKS");
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
check($s['recent'] === [], 'Activity (with people\'s names) is not handed to anyone who is not signed in');
$act = activity_list();                                         // db/activity.php: what Manage and the app show
check(!array_filter($act, fn($r) => str_contains($r['text'], 'from Parks into')), 'empty retries stay out of Activity');
check((bool)array_filter($act, fn($r) => $r['kind'] === 'changed' && str_starts_with($r['text'], 'Tidy-up moved 3 files onto the shelf')), 'tidy-ups keep their own words');
check(count($s['ingests']) === 2 && $s['ingests'][0]['state'] === 'done' && $s['ingests'][0]['failed'] === 0 && $s['ingests'][1]['failed'] === 1,
      'a landed card says how many files could not be copied (Ingest says safe to format only at none)');

if (session_status() !== PHP_SESSION_ACTIVE) { ini_set('session.save_path', "$root/app"); session_start(); } $_SESSION['rushes_in'] = true; $_SESSION['rushes_gen'] = pass_gen();
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
check(str_contains($html, 'id="rlGo"') && !str_contains($html, 'id="pane-tools"') && str_contains($html, "'/setup.php#tools'"),
      "relinking after a tidy-up is in Editors' projects; Jobs and tools is in Setup, and an old link lands there");
check(str_contains($html, '<h2>Transfer</h2>') && str_contains($html, '<h2>Footage</h2>') && str_contains($html, '> Copying') && str_contains($html, "pane !== 'overview'"),
      'the menu: Overview, then Transfer, Footage and System; the switches only on Overview');
check(str_contains($html, 'id="dBoxes"') && !str_contains($html, 'id="dFolders"') && str_contains($html, "action: 'pick'"),
      'Duplicates: three kinds with their own Remove, no row of folder chips');
check(str_contains($html, 'id="cpFrom"') && str_contains($html, 'id="cpTo"') && str_contains($html, "fetch('backup.php") && str_contains($html, 'data-go-copy'),
      'Copying: From and To chosen on the page, the backup, and its tile on Overview');
check(str_contains($html, "classList.toggle('tip-l'"), 'an ⓘ on the right opens to the left');
check(str_contains($html, 'id="drivesNow"') && str_contains($html, 'DRV_ICON') && str_contains($html, 'running_said') && str_contains($html, 'data-addsrc')
      && str_contains($html, 'id="worth"') && !str_contains($html, "'Free space'"), 'Overview: drives in Rushes and the rest with Add to Rushes, Worth a look, no lone free-space tile; what is running in words');
check(str_contains($html, 'id="pane-drive"') && str_contains($html, 'function openDrive') && str_contains($html, 'drive.php?path=') && str_contains($html, 'id="rrDrive"'),
      'a drive card opens its own page: levels, folders, actions, its Recently Removed');
check(!str_contains($html, 'Back to search') && substr_count($html, 'href="/setup.php"') >= 1, 'the menu has no second way back to Search');
ob_start(); include "$root/app/setup.php"; $html = ob_get_clean();
check(str_contains($html, 'id="plan"') && str_contains($html, '1 department, in Library') && str_contains($html, 'form="pP" type="text" name="d_name[0]" value="Parks"'),
      'Setup has the archive structure: the word, the shelf, the list, each control in its own form');
check(substr_count($html, 'Start here') === 1 && str_contains($html, '<details class="grp sec" id="shape" open') && substr_count($html, '<details class="grp sec" id="') - substr_count($html, '" open>') >= 7,
      'Setup is a tree of closed lines: only the first thing still to do opens, marked Start here (here: how media is organised)');
check(str_contains($html, 'id="plan" data-done') && str_contains($html, 'id="plan-list"') && str_contains($html, 'id="plan-name"') && !str_contains($html, '.no-shelf-chosen'),
      'the structure folds into its parts, and never shows the placeholder for a shelf not chosen');
check(substr_count($html, '<div class="btns sec-save">') >= 3 && !str_contains($html, 'Save settings</button>'), 'each section saves itself: no Save at the very bottom');
check(str_contains($html, 'id="password"') && str_contains($html, 'id="exports"') && str_contains($html, 'data-t="manifest"'),
      'Setup has the password, the exports and Jobs and tools');
ob_start(); include "$root/app/structure.php"; $html = ob_get_clean();
check(str_contains($html, 'id="tidy"') && !str_contains($html, 'name="list"') && !str_contains($html, 'id="rlGo"'), 'Reorganize is only the tidy-up');
check(str_contains($html, 'class="side"') && str_contains($html, "fetch('/db/state.php") && str_contains($html, 'tops.map('), 'Reorganize has the Activity column, and its list folds by top folder');
$done = true;
echo "Page tests complete.\n";
