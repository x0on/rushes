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
check(!new_pass('seven77', 'test') && new_pass('eight888', 'test') && pass_ok('eight888'), 'new passwords: 8 characters at least');
file_put_contents(pass_file(), "<?php return ['pass' => 'old4'];\n"); if (function_exists('opcache_invalidate')) @opcache_invalidate(pass_file(), true);
check(pass_ok('old4'), 'a shorter password kept from before still works');
set_pass('eight888');

// Wrong passwords: 10 in a row, and no password is taken for a while (pass_try sleeps 1 s each)
$_SERVER['REMOTE_ADDR'] = '192.168.1.20';
for ($i = 0; $i < 9; $i++) pass_try('wrong');
check(locked_for() === 0 && pass_try('eight888') && tries_with()[0] === 0, 'nine wrong, then the right one: let in, and the count starts again');
for ($i = 0; $i < 10; $i++) pass_try('wrong');
check(locked_for() > 14 * 60 && !pass_try('eight888') && !may_act('eight888'), 'ten wrong: even the right one is refused');
check(str_contains(locked_said(), 'minutes'), 'and the page says how long');
check(!str_contains((string)file_get_contents(tries_file()), 'eight888') && str_starts_with((string)file_get_contents(tries_file()), '<?php exit;'), 'the count file holds no password and prints nothing');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
check(locked_for() === 0 && pass_try('eight888'), 'the Mac itself is never locked out');
tries_with(fn($t) => [0, 0]);
for ($i = 0; $i < 10; $i++) pass_try('wrong');
$_SERVER['REMOTE_ADDR'] = '192.168.1.20';
check(locked_for() === 0 && tries_with()[0] === 0, 'wrong passwords from the Mac itself are not counted');
$_SERVER['REMOTE_ADDR'] = '192.168.1.20';
tries_with(fn($t) => [0, time() - 1]);
check(locked_for() === 0 && pass_try('eight888'), 'after the wait, the right one is taken again');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ob_end_flush();
echo "Password tests complete.\n";
