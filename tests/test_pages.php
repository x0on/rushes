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
    'helper'=>['mode'=>'built_in'], 'organise'=>['shelves'=>'Library', 'departments'=>[['name'=>'Parks','folder'=>'PARKS']]]]));
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
check(count($s['repeats'] ?? []) === 5 && $s['repeats'][0][0] === 'The runner on the archive machine', 'Overview lists what runs by itself (rule 5)');
check($s['copy']['phase'] === 'copying' && $s['copy']['rate'] === 100 && $s['copy']['file'] === 'A001.MXF', 'live helper detail comes through');
check($s['transfer'] && $s['transfer']['folders'] === 1, 'the saved transfer comes through beside it');
check(!array_filter($s['recent'], fn($r) => $r['files'] === 0 && $r['what'] === 'brought over'), 'empty retries stay out of Activity');
check((bool)array_filter($s['recent'], fn($r) => $r['what'] === 'moved onto the shelf'), 'tidy-ups keep their own words');
check(count($s['ingests']) === 2 && $s['ingests'][0]['state'] === 'done' && $s['ingests'][0]['failed'] === 0 && $s['ingests'][1]['failed'] === 1,
      'a landed card says how many files could not be copied (Ingest says safe to format only at none)');

ini_set('session.save_path', "$root/app"); session_start(); $_SESSION['rushes_in'] = true;
ob_start(); include "$root/app/db/admin.php"; $html = ob_get_clean();
check(str_contains($html, 'id="now"') && str_contains($html, 'id="transferSummary"'), 'Overview has both the live card and the transfer card');
check(str_contains($html, 'helperNow') && str_contains($html, 'tokens.css?v='), 'shared top bar with live words and a fresh stylesheet');
echo "Page tests complete.\n";
