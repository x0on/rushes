<?php
// A forgotten password, set again at the Mac itself (auth.php at_this_mac, new_pass).
// Run: php tests/test_password.php. Self-contained fixture, like test_server.php.
ob_start();
$root = (getenv('RUSHES_TEST_TMP') ?: sys_get_temp_dir()) . '/rushes-pass-' . bin2hex(random_bytes(5));
mkdir($root); mkdir("$root/app"); mkdir("$root/app/db"); mkdir("$root/archive");
foreach (glob(__DIR__ . '/../app/db/*.php') as $f) copy($f, "$root/app/db/" . basename($f));
copy(__DIR__ . '/../app/rules.json', "$root/app/rules.json");
file_put_contents("$root/app/settings.json", json_encode(['name' => 'Rushes', 'archive' => [
    'web' => "$root/app", 'local' => "$root/archive", 'runs_on' => 'mac'], 'helper' => ['mode' => 'built_in']]));
require "$root/app/db/config.php";
require "$root/app/db/auth.php";
function check($ok, $what) { if (!$ok) throw new RuntimeException("FAIL $what"); echo "PASS $what\n"; }

$_SERVER['REMOTE_ADDR'] = '192.168.1.20';
check(!at_this_mac(), 'another device is never "at this Mac"');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
check(at_this_mac(), 'the Mac itself is');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.20';
check(!at_this_mac(), 'nor something passed on through this Mac from elsewhere');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);

set_pass('first one');
$old_gen = pass_gen();
check(new_pass('second one', 'test') && pass_ok('second one') && !pass_ok('first one'), 'a new password replaces the old');
check(pass_gen() !== $old_gen, 'sessions signed in with the old one no longer count');
check(signed_in(), 'the one who set it stays signed in');
check(str_contains((string)@file_get_contents(activity_file()), "\tchanged\t"), 'and Activity says so');
check(!new_pass('abc', 'test') && pass_ok('second one'), 'too short: nothing changes');
ob_end_flush();
echo "Password tests complete.\n";
