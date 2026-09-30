<?php
// helper.php — everything about the helper that is not copying.
//
//   GET  ?code=ingest.py | transfer_state.py | analyze.py   the helper's own files, from _rushes
//   GET  ?hash                                  what the current files are (for updates)
//   GET  ?app                                   Rushes Helper for Mac, as a zip (from _rushes)
//   GET  ?install / ?remove                     a Mac, from Terminal: fetch the app and open it, or take it off
//   GET  ?where                                 the addresses Rushes can be reached at (helpers follow a change)
//   GET  ?control                               pause, "try again now", folders to skip
//   GET  ?builtin                               "yes" when this machine should run it (for runner.sh)
//   POST action=pause|resume|nudge|reconnect-off|reconnect-on   the helper's switches (Manage, or its own window)
//   POST action=skip path                        skip a folder (signed in)
//   POST action=scripts                         install the updated .sh scripts (signed in)
//
// ponytail: the GETs need no password, like report.php — the helper has none.
// They hand out only what is already on the share for anyone who can mount it,
// and the one thing a GET can change is nothing.
require_once __DIR__ . '/auth.php';

const HELPER_FILES = ['ingest.py', 'transfer_state.py', 'analyze.py'];
function helper_src(string $f): string { return archive_dir() . '/_rushes/' . $f; }
function helper_hashes(): array {
    $h = [];
    foreach (HELPER_FILES as $f) $h[$f] = is_readable(helper_src($f)) ? hash_file('sha256', helper_src($f)) : '';
    return $h;
}
function out($x, string $type = 'application/json') {
    header("Content-Type: $type"); header('Cache-Control: no-store');
    echo is_string($x) ? $x : json_encode($x, JSON_UNESCAPED_SLASHES); exit;
}
function bail(int $code, string $why) { http_response_code($code); out(['error' => $why]); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['code'])) {
        $f = (string)$_GET['code'];
        if (!in_array($f, HELPER_FILES, true) || !is_readable(helper_src($f))) bail(404, 'no such helper file');
        header('X-Hash: ' . hash_file('sha256', helper_src($f)));
        out(file_get_contents(helper_src($f)), 'text/x-python; charset=utf-8');
    }
    if (isset($_GET['hash']))    out(helper_hashes());
    if (isset($_GET['control'])) out(helper_control());
    if (isset($_GET['builtin'])) out(helper_mode() === 'built_in' ? 'yes' : 'no', 'text/plain');
    // Where helpers should find Rushes: the address in Setup, and this machine's
    // name, which keeps working when its number changes.
    if (isset($_GET['where'])) out(['url' => rtrim((string)(settings()['archive']['url'] ?? ''), '/'), 'name' => name_url()]);
    if (isset($_GET['app'])) {
        // Built by mac/build.py and put next to the helper on the archive.
        $z = archive_dir() . '/_rushes/Rushes Helper.zip';
        if (!is_readable($z)) bail(404, 'Rushes Helper for Mac is not on the archive yet (_rushes/Rushes Helper.zip).');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="Rushes Helper.zip"');
        header('Content-Length: ' . filesize($z));
        header('Cache-Control: no-store');
        readfile($z); exit;
    }

    if (isset($_GET['install']) || isset($_GET['remove'])) {
        // A shell script for a Mac, with this archive's address written in.
        // Shown on the Setup page as one line to paste into Terminal.
        $url = rtrim((string)(settings()['archive']['url'] ?? ''), '/');
        if ($url === '') bail(400, 'Setup does not know this archive\'s address yet (02 · Archive).');
        $q = fn($s) => "'" . str_replace("'", "'\\''", $s) . "'";
        $label = 'org.rushes.helper';
        $common = "set -e\nLABEL=$label\nPLIST=\"\$HOME/Library/LaunchAgents/\$LABEL.plist\"\nDIR=\"\$HOME/Library/Application Support/Rushes\"\n";
        if (isset($_GET['remove'])) out("#!/bin/sh\n$common" . <<<'SH'
APP="$HOME/Applications/Rushes Helper.app"
echo "Stopping the Rushes helper …"
launchctl bootout "gui/$(id -u)/$LABEL" 2>/dev/null || true
rm -f "$PLIST"
[ -d "$APP" ] && rm -rf "$APP" && echo "Removed $APP"
echo "✓ Removed. It will not start again at login."
echo "  Its files are still in: $DIR  (delete that folder too, if you like)"
echo "  Switch it off in System Settings → Privacy & Security → Full Disk Access too."
SH, 'text/plain; charset=utf-8');

        // The app does the setting up, with its own windows. This only fetches it —
        // downloaded by curl, macOS does not stop it the first time it opens.
        out("#!/bin/sh\n$common" . 'URL=' . $q($url) . "\n" . <<<'SH'
APPS="$HOME/Applications"; APP="$APPS/Rushes Helper.app"; TMP=$(mktemp -d)
echo "1/3  Downloading Rushes Helper from $URL (about 30 MB) …"
curl -fS --progress-bar "$URL/db/helper.php?app" -o "$TMP/rh.zip"
echo "2/3  Putting it in $APPS …"
mkdir -p "$APPS" "$DIR"
rm -rf "$APP"
ditto -x -k "$TMP/rh.zip" "$APPS"
rm -rf "$TMP"
printf '%s\n' "$URL" > "$DIR/url"
echo "3/3  Opening it …"
open "$APP"
echo ""
echo "✓ Rushes Helper is open. Its windows walk you through the rest: the background"
echo "  service, and one switch in System Settings (Full Disk Access)."
SH, 'text/plain; charset=utf-8');
    }
    bail(400, 'nothing asked');
}

// ── the buttons ─────────────────────────────────────────────────────────────
// Pause, resume, reconnecting and "look again" only start or stop the helper's
// own work — nothing is moved or deleted — so its own window on the Mac may
// press them without a password, like Manage. Everything else needs sign-in.
$act = (string)($_POST['action'] ?? '');
if (!in_array($act, ['pause', 'resume', 'describe-pause', 'describe-resume', 'reconnect-off', 'reconnect-on', 'nudge'], true)
    && !may_act((string)($_POST['pass'] ?? ''))) bail(403, 'sign in first');
$c = helper_control();
if ($act === 'pause' || $act === 'resume') {
    $c['paused'] = $act === 'pause';
} elseif ($act === 'describe-pause' || $act === 'describe-resume') {
    $c['describe_paused'] = $act === 'describe-pause';  // the describing lane only; copying carries on
} elseif ($act === 'reconnect-off' || $act === 'reconnect-on') {
    $c['no_reconnect'] = $act === 'reconnect-off';     // the helper stops (or starts) connecting dropped shares by itself
} elseif ($act === 'nudge') {
    $c['nudge'] = time();                           // the helper stops waiting and looks again
} elseif ($act === 'skip') {
    $p = (string)($_POST['path'] ?? '');
    require_once __DIR__ . '/transfers.php';
    $job = transfer_latest();
    $in = array_filter($job['items'] ?? [], fn($i) => $i['source'] === $p && !in_array($i['phase'], ['done', 'removed'], true));
    if ($p === '' || !$in) bail(400, 'That folder is not waiting in the current transfer.');
    // Out of the selection (it can be ticked again later) and out of the queue.
    $keep = array_values(array_map(fn($i) => $i['source'], array_filter($job['items'],
        fn($i) => $i['source'] !== $p && !in_array($i['phase'], ['done', 'removed'], true))));
    transfer_select($keep, []);
    $q = web_dir() . '/ingest-queue.tsv';
    $lines = array_filter(@file($q, FILE_IGNORE_NEW_LINES) ?: [], fn($l) => $l !== "copy\t$p");
    if (@file_put_contents("$q.new", $lines ? implode("\n", $lines) . "\n" : '') === false || !@rename("$q.new", $q))
        bail(500, 'Could not write the queue — is the web folder writable?');
    $c['skip'] = array_values(array_unique(array_merge($c['skip'], [$p])));
} elseif ($act === 'scripts') {
    $want = scripts_waiting();
    if (!$want) bail(400, 'Every script is already up to date.');
    $body = "ACTION=update-scripts\n";
    foreach ($want as $w) $body .= "SCRIPT={$w['name']}:{$w['hash']}\n";
    $q = '/share/Web/queue';
    if (!is_dir($q)) @mkdir($q, 0777, true);
    if (@file_put_contents("$q/" . date('Ymd-His') . '-scripts.job', $body) === false)
        bail(500, 'Could not queue it — is /share/Web/queue writable?');
    out(['queued' => array_column($want, 'name')]);
} else {
    bail(400, 'unknown action');
}
$c['by'] = $act; $c['at'] = time();
if (!helper_control_save($c)) bail(500, 'Could not save — is the web folder writable?');
out(['ok' => true, 'control' => $c]);
