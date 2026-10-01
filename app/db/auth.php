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

function set_pass(string $new): bool {
    $new = trim($new);
    if (strlen($new) < 4) return false;
    return php_keep(pass_file(), ['pass' => password_hash($new, PASSWORD_DEFAULT)]);
}

function session_begin(): void {
    if (session_status() === PHP_SESSION_NONE) {
        @session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        @session_start();
    }
}

function signed_in(): bool {
    session_begin();
    return !empty($_SESSION['rushes_in']);
}

// Endpoints (run.php, queue.php) accept a signed-in session OR the password in
// the request, so scripts and the page can both talk to them.
function may_act(string $given = ''): bool {
    return signed_in() || ($given !== '' && pass_ok($given));
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
        if (pass_ok((string)$_POST['_pass'])) {
            $_SESSION['rushes_in'] = true;
            session_regenerate_id(true);           // a fresh id once signed in
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
        }
        $why = 'That is not the password.';
        sleep(1);                                   // slow a guessing loop down
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
  <h1>Manage</h1>
  <p>Search and Ingest are open to anyone here. This part changes files, so it asks.</p>
  <?php if ($why): ?><p class="bad-note"><?= htmlspecialchars($why) ?></p><?php endif; ?>
  <input type="password" name="_pass" placeholder="password" autofocus autocomplete="current-password">
  <button class="btn" type="submit">Unlock</button>
  <?php if ($default): ?>
    <p style="margin:14px 0 0;font-size:12.5px;color:var(--muted)">
      Not set yet &mdash; it is <code><?= htmlspecialchars(default_pass()) ?></code>.
      Change it once you are in.</p>
  <?php endif; ?>
</form>
<?php
}
