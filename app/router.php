<?php
// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes
//
// router.php — the door, when Rushes is served by PHP's own small web server
// (php -S, which the Rushes app runs on a Mac: HOW-IT-WORKS.md → Rushes on a
// Mac). That server does not read .htaccess, so its rules are here, and more:
//
//   - nothing hidden, nothing private (.htaccess's list), and only what a
//     browser needs (pages, styles, scripts, pictures), plus the files the
//     helper reads (settings.json, rules.json, the file list manifest.tsv, a
//     tidy-up's plan tidy-….tsv): never the database, the other lists, the
//     logs or the code
//   - from this Mac: everything else as usual
//   - from any other device: only when "Let other devices open Rushes" is on
//     (RUSHES_OTHERS=1, set by the Rushes app), only once Rushes' password is
//     no longer the default, and only signed in with it; except the three
//     doors editors' computers use, which check their own (a paired ID, the
//     six-digit code, or the password)
//
// It is outside the folder it serves, so it can never be asked for itself.
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$base = basename($path);
function refuse(int $code, string $why): bool {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $why, "\n";
    return true;
}
if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || preg_match('#(^|/)\.#', $path))
    return refuse(404, 'Not found.');
if (preg_match('/^(\.adminpass|\.pull-.*|rushes\.sqlite.*|db-copy\.sqlite.*|ingest-queue\.tsv|helper-refused\.tsv|activity\.tsv)$/', $base))
    return refuse(403, 'Private.');
$ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
if (!is_dir($_SERVER['DOCUMENT_ROOT'] . $path) && !in_array($ext, ['php', 'html', 'css', 'js', 'png', 'ico', 'svg', 'jpg', 'webp'], true)
    && !in_array($path, ['/settings.json', '/rules.json', '/manifest.tsv'], true) && !preg_match('#^/tidy-\d{8}-\d{6}\.tsv$#', $path))
    return refuse(404, 'Not found.');

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    && !in_array($path, ['/tokens.css', '/icon.php', '/favicon.ico', '/apple-touch-icon.png'], true)) {   // what the sign-in page shows
    if (getenv('RUSHES_OTHERS') !== '1')
        return refuse(403, 'Rushes on this Mac is for this Mac only. To open it from other devices, turn on '
            . '"Let other devices open Rushes" in the Rushes app on the Mac (its window, or its menu bar icon).');
    require_once $_SERVER['DOCUMENT_ROOT'] . '/db/auth.php';
    if (pass_is_default())
        return refuse(403, 'Rushes still has its first password. On the Mac, open Rushes → Manage and set your own; '
            . 'then other devices can sign in with it.');
    // The doors editors' computers (Rushes Watcher) use check their own: a paired ID, the six-digit
    // code, or the password (helper.php, pair.php, watcher.php). A browser's pages need signing in.
    if (in_array($path, ['/db/helper.php', '/db/pair.php', '/db/watcher.php'], true)) return false;
    define('RUSHES_SIGN_IN_ALL', true);           // sign_in_page says it is for all of Rushes
    require_sign_in();                            // the sign-in page, until signed in
    session_write_close();                        // the page opens it again: never held across a long upload
}
return false;                                     // served as usual
