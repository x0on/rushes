<?php
// helper.php — everything about the helper that is not copying.
//
//   GET  ?code=ingest.py | transfer_state.py   the helper's own files, from _rushes
//   GET  ?hash                                  what the current files are (for updates)
//   GET  ?install / ?remove                     a Mac: set it up as a background service, or take it off
//   GET  ?control                               pause, "try again now", folders to skip
//   GET  ?builtin                               "yes" when this machine should run it (for runner.sh)
//   POST action=pause|resume|nudge|skip [path]  the buttons in Manage (signed in)
//   POST action=scripts                         install the updated .sh scripts (signed in)
//
// ponytail: the GETs need no password, like report.php — the helper has none.
// They hand out only what is already on the share for anyone who can mount it,
// and the one thing a GET can change is nothing.
require_once __DIR__ . '/auth.php';

const HELPER_FILES = ['ingest.py', 'transfer_state.py'];
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

    if (isset($_GET['install']) || isset($_GET['remove'])) {
        // A shell script for a Mac, with this archive's address written in.
        // Shown on the Setup page as one line to paste into Terminal.
        $url = rtrim((string)(settings()['archive']['url'] ?? ''), '/');
        if ($url === '') bail(400, 'Setup does not know this archive\'s address yet (02 · Archive).');
        $q = fn($s) => "'" . str_replace("'", "'\\''", $s) . "'";
        $label = 'org.rushes.helper';
        $common = "set -e\nLABEL=$label\nPLIST=\"\$HOME/Library/LaunchAgents/\$LABEL.plist\"\nDIR=\"\$HOME/Library/Application Support/Rushes\"\n";
        if (isset($_GET['remove'])) out("#!/bin/sh\n$common" . <<<'SH'
echo "Stopping the Rushes helper …"
launchctl bootout "gui/$(id -u)/$LABEL" 2>/dev/null || true
rm -f "$PLIST"
echo "✓ Removed. It will not start again at login."
echo "  Its files are still in: $DIR  (delete that folder too, if you like)"
SH, 'text/plain; charset=utf-8');

        out("#!/bin/sh\n$common" . 'URL=' . $q($url) . "\n" . <<<'SH'
LOGS="$HOME/Library/Logs/Rushes"
mkdir -p "$DIR" "$LOGS" "$HOME/Library/LaunchAgents"
PY=$(command -v python3 || true)
if [ -z "$PY" ]; then
  echo "Python 3 is not on this Mac yet. Type python3 in Terminal once and let the Mac"
  echo "install its developer tools (a few minutes), then run this again."; exit 1
fi
echo "1/3  Downloading the helper from Rushes at $URL …"
for f in ingest.py transfer_state.py; do
  curl -fsS "$URL/db/helper.php?code=$f" -o "$DIR/$f.new"
  mv "$DIR/$f.new" "$DIR/$f"
done
"$PY" -c "import sqlite3" && "$PY" -m py_compile "$DIR/ingest.py"
echo "2/3  Setting it to start by itself …"
cat > "$PLIST" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>$LABEL</string>
  <key>ProgramArguments</key><array>
    <string>$PY</string><string>-u</string><string>$DIR/ingest.py</string>
    <string>--watch</string><string>--url</string><string>$URL</string><string>--service</string>
  </array>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><true/>
  <key>ThrottleInterval</key><integer>30</integer>
  <key>StandardOutPath</key><string>$LOGS/helper.log</string>
  <key>StandardErrorPath</key><string>$LOGS/helper.log</string>
</dict></plist>
EOF
echo "3/3  Starting it …"
launchctl bootout "gui/$(id -u)/$LABEL" 2>/dev/null || true
launchctl bootstrap "gui/$(id -u)" "$PLIST"
echo ""
echo "✓ The Rushes helper is installed and running in the background."
echo "  It starts when you log in, restarts if it stops, and updates itself from Rushes."
echo "  Manage in Rushes shows what it is doing. Its log: $LOGS/helper.log"
echo "  If the Mac asks whether python3 may use network or removable volumes: Allow."
SH, 'text/plain; charset=utf-8');
    }
    bail(400, 'nothing asked');
}

// ── the buttons ─────────────────────────────────────────────────────────────
if (!may_act((string)($_POST['pass'] ?? ''))) bail(403, 'sign in first');
$act = (string)($_POST['action'] ?? '');
$c = helper_control();
if ($act === 'pause' || $act === 'resume') {
    $c['paused'] = $act === 'pause';
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
