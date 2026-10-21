<?php
// auth.php — the lock on the Admin door.
//
// Modelled on a home router: it ships with a default password so nothing is
// ever *un*protected on first boot, it guards the whole page rather than each
// button, and it will not stop telling you to change the default until you do.
//
// One password, no accounts. That matches who runs this: one person looking
// after one archive. Anyone who needs real user accounts needs something in
// front of this, not inside it.
//
// ponytail: a PHP session and a hashed word in a file. Right for a trusted
// local network and nothing more. Behind a public address this needs real
// authentication in front of it — say so out loud rather than pretending.

require_once __DIR__ . '/config.php';

// Kept as a .php file: if someone asks the web server for it, it runs and
// prints nothing, so the password can never be downloaded — whatever the web
// server's settings. (It used to be .adminpass, a plain file; moved once.)
function pass_file(): string { return web_dir() . '/adminpass.php'; }

// The default is the app's own name, lowercased. Written on first run so the
// door is never simply open, and remembered as "still the default" so the page
// can keep asking.
function default_pass(): string {
    return strtolower(preg_replace('/[^A-Za-z0-9]/', '', settings()['name'] ?? 'rushes')) ?: 'rushes';
}

function stored_pass(): string {
    $p = is_readable(pass_file()) ? @include pass_file() : null;
    if (is_array($p) && ($p['pass'] ?? '') !== '') return $p['pass'];
    $old = web_dir() . '/.adminpass';
    $v = is_readable($old) ? trim((string)file_get_contents($old)) : '';
    if ($v === '') $v = default_pass();
    if (php_keep(pass_file(), ['pass' => $v])) @unlink($old);
    return $v;
}

function pass_is_default(): bool {
    return hash_equals(stored_pass(), default_pass());
}

// Accepts either the hash written by set_pass(), or a plain word — because the
// file may have been created by hand before this existed, and locking someone
// out of their own archive over a storage detail would be absurd.
function pass_ok(string $given): bool {
    $stored = stored_pass();
    if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon')) {
        return password_verify($given, $stored);
    }
    return hash_equals($stored, trim($given));
}

// New passwords are at least this long. One already kept, shorter, still works.
const PASS_MIN = 8;

function set_pass(string $new): bool {
    $new = trim($new);
    if (strlen($new) < PASS_MIN) return false;
    return php_keep(pass_file(), ['pass' => password_hash($new, PASSWORD_DEFAULT)]);
}

function session_begin(): void {
    if (session_status() === PHP_SESSION_NONE) {
        @session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        @session_start();
    }
}

// Which password a session signed in with: a new password signs every other device out.
function pass_gen(): string { return substr(hash('sha256', stored_pass()), 0, 16); }

function signed_in(): bool {
    session_begin();
    return !empty($_SESSION['rushes_in']) && ($_SESSION['rushes_gen'] ?? '') === pass_gen();
}

// Someone at the Mac Rushes runs on (a request from the Mac itself, on a Mac). Like a
// router's reset button: they may set a new password without the old one. They can
// already open Rushes and every file on that Mac without it; the password only guards
// against other devices, which still need the current one to change it.
function at_this_mac(): bool {
    return on_mac() && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        && empty($_SERVER['HTTP_X_FORWARDED_FOR']);
}

// A new password: kept, this session signed in with it (every other one signed out), and said in Activity.
function new_pass(string $new, string $how): bool {
    if (!set_pass($new)) return false;
    session_begin();
    $_SESSION['rushes_in'] = true; $_SESSION['rushes_gen'] = pass_gen();
    require_once __DIR__ . '/activity.php';
    activity_add('changed', $how);
    return true;
}

// Endpoints (run.php, queue.php) accept a signed-in session OR the password in
// the request, so scripts and the page can both talk to them.
function may_act(string $given = ''): bool {
    if (signed_in()) return true;
    if ($given === '') return false;
    return pass_try($given);
}

// ── wrong passwords ────────────────────────────────────────────────────────
// After PASS_TRIES wrong passwords in a row, from any device and any door (sign-in,
// scripts, Setup), every password is refused for PASS_WAIT seconds, the right one
// too: a guessing loop gets ten tries every quarter of an hour, not thousands. The
// Mac itself is never locked out (it does not need the password, and can set a
// new one there). Counted in one file, under a lock, so tries sent all at once are
// all counted. A .php file: asked for, it runs and prints nothing.
const PASS_TRIES = 10;
const PASS_WAIT = 15 * 60;

function tries_file(): string { return web_dir() . '/signin-tries.php'; }

// [wrong tries in a row, locked until (a time, or 0)], changed by $change under the lock
function tries_with(?callable $change = null): array {
    $h = @fopen(tries_file(), 'c+');
    if (!$h) return [0, 0];
    flock($h, $change ? LOCK_EX : LOCK_SH);
    $raw = (string)stream_get_contents($h);
    $t = json_decode(substr($raw, (int)strpos($raw, "\n")), true);
    $t = [(int)($t['n'] ?? 0), (int)($t['until'] ?? 0)];
    if ($change) {
        $t = $change($t);
        ftruncate($h, 0); rewind($h);
        fwrite($h, "<?php exit; ?>\n" . json_encode(['n' => $t[0], 'until' => $t[1]]) . "\n");
        fflush($h);
    }
    flock($h, LOCK_UN); fclose($h);
    return $t;
}

// Seconds left before passwords are taken again (0: not locked out)
function locked_for(): int {
    if (at_this_mac()) return 0;
    return max(0, tries_with()[1] - time());
}

// A password given at a door: checked, and counted when wrong
function pass_try(string $given): bool {
    if (at_this_mac()) return pass_ok($given);      // the Mac's own tries (a script with an old password) never lock others out
    if (locked_for() > 0) return false;
    if (pass_ok($given)) {
        if (tries_with()[0] > 0) tries_with(fn($t) => [0, 0]);
        return true;
    }
    tries_with(function ($t) {
        $n = $t[0] + 1;
        return $n >= PASS_TRIES ? [0, time() + PASS_WAIT] : [$n, $t[1]];
    });
    sleep(1);                                       // slow a guessing loop down
    return false;
}

function locked_said(): string {
    return 'Too many wrong passwords. Rushes takes passwords again in ' . max(1, (int)ceil(locked_for() / 60))
        . ' minute' . (locked_for() > 60 ? 's' : '') . '. On the Mac Rushes runs on, it opens without one.';
}

// ── the gate ───────────────────────────────────────────────────────────────
// Call at the top of any page that should be behind the lock.
function require_sign_in(): void {
    session_begin();

    if (($_POST['_signout'] ?? '') === '1') {
        $_SESSION = []; @session_destroy();
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
    }

    $tried = false; $why = '';
    if (isset($_POST['_pass'])) {
        $tried = true;
        if (locked_for() > 0) $why = locked_said();
        elseif (pass_try((string)$_POST['_pass'])) {
            $_SESSION['rushes_in'] = true; $_SESSION['rushes_gen'] = pass_gen();
            session_regenerate_id(true);           // a fresh id once signed in
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
        }
        else $why = locked_for() > 0 ? locked_said() : 'That is not the password.';
    }

    // Forgot it, at the Mac itself: a new one, no old one asked
    if (isset($_POST['_reset']) && at_this_mac()) {
        $tried = true;
        if (strlen(trim((string)$_POST['_reset'])) < PASS_MIN) $why = 'Pick something at least ' . PASS_MIN . ' characters long.';
        elseif (new_pass((string)$_POST['_reset'], 'Set a new password on this Mac (the old one was not asked); other devices sign in again with it')) {
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
        } else $why = 'Could not keep the new password: ' . pass_file() . ' is not writable.';
    }

    if (signed_in()) return;
    sign_in_page($tried ? $why : '');
    exit;
}

function sign_in_page(string $why): void {
    $default = pass_is_default();
    http_response_code($why ? 401 : 200);
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manage &middot; <?= htmlspecialchars(settings()['name'] ?? 'Rushes') ?></title>
<script>try{var t=localStorage.getItem('theme');if(t)document.documentElement.dataset.theme=t}catch(e){}</script>
<link rel="stylesheet" href="/tokens.css?v=<?= @filemtime(__DIR__ . '/../tokens.css') ?>">
<?php $iv = substr(md5(favicon_href()), 0, 8); ?>
<link rel="icon" type="image/png" sizes="64x64" href="/icon.php?v=<?= $iv ?>">
<link rel="apple-touch-icon" href="/icon.php?s=180&amp;v=<?= $iv ?>">
<style>
  body { display: grid; place-items: center; min-height: 100vh }
  .card { width: 340px; background: var(--surface); border: 1px solid var(--line);
          border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow) }
  .card .mk { width: 34px; height: 34px; color: var(--accent-text); display: block; margin-bottom: 16px }
  .card h1 { font-size: 17px; margin: 0 0 4px; font-weight: 650 }
  .card p { color: var(--muted); font-size: 13px; margin: 0 0 16px }
  .card input { width: 100%; padding: 9px 11px; font: 14px var(--font); margin: 0 0 10px;
                border: 1px solid var(--line); border-radius: var(--radius-sm);
                background: var(--bg); color: var(--fg) }
  .card .btn { width: 100% }
  .bad-note { color: var(--bad); font-size: 13px; margin: 0 0 12px }
</style>
<form class="card" method="post">
  <?= mark() ?>
  <?php if (defined('RUSHES_SIGN_IN_ALL')): ?>
  <h1><?= htmlspecialchars(settings()['name'] ?? 'Rushes') ?></h1>
  <p>From another device, Rushes asks for its password first.</p>
  <?php else: ?>
  <h1>Manage</h1>
  <p>Search and Ingest are open to anyone here. This part changes files, so it asks.</p>
  <?php endif; ?>
  <?php if ($why): ?><p class="bad-note"><?= htmlspecialchars($why) ?></p><?php endif; ?>
  <input type="password" name="_pass" placeholder="password" autofocus autocomplete="current-password">
  <button class="btn" type="submit">Unlock</button>
  <?php if ($default): ?>
    <p style="margin:14px 0 0;font-size:12.5px;color:var(--muted)">
      Not set yet &mdash; it is <code><?= htmlspecialchars(default_pass()) ?></code>.
      Change it once you are in.</p>
  <?php elseif (at_this_mac()): ?>
    <details style="margin:16px 0 0;font-size:13px" <?= isset($_POST['_reset']) ? 'open' : '' ?>>
      <summary style="cursor:pointer;color:var(--accent-text)">Forgot it? Set a new one</summary>
      <p style="margin:10px 0">You are at the Mac Rushes runs on, so the old one is not needed.
        Phones and other computers sign in again with the new one.</p>
      <input type="password" name="_reset" placeholder="new password" autocomplete="new-password" minlength="<?= PASS_MIN ?>">
      <button class="btn" type="submit" formnovalidate>Set it and unlock</button>
    </details>
  <?php endif; ?>
</form>
<?php
}
