<?php
// setup.php — where this installation's settings are chosen (settings.json).
// Reorganize writes the department list into the same file, and Ingest may add
// a department to it when that is allowed.
//
// Everything here is "where things are on this installation". Nothing here
// decides anything about media: that is rules.json, the same everywhere.
//
// No path on this page is typed. Each is picked from what one of the two
// machines that copy actually reports it can see — the one serving this page,
// or the helper — so it is right for the machine that will use it. On a
// single-machine install those are the same computer, and this becomes the
// operating system's own folder window.
//
// ponytail: a form that rewrites one JSON file, behind the admin lock.
$NAV = 'admin';
require_once __DIR__ . '/db/config.php';
require_once __DIR__ . '/db/auth.php';
require_sign_in();

$said  = '';
$file  = __DIR__ . '/settings.json';
$hv    = helper_volumes();
$hpath = helper_paths();
$local = local_volumes();
$lpath = [];
foreach ($local as $v) {
    $lpath[$v['path']] = true;
    foreach ($v['top'] as $d) $lpath[$v['path'] . '/' . $d] = true;
}

// ── the archive's structure (was Reorganize's part 1): its own forms, saved on their own ──
// The department list lives here and only here. Ingest offers exactly this list; Reorganize
// moves older folders to match it. Each department is linked to the folder it already has
// on the shelf, so saving the plan renames nothing and breaks no Premiere project.
$folders = shelf_folders();
$pSaid = ''; $pBad = [];
$rows = departments();            // what the section shows; replaced by a paste or a failed save
$PRESETS = ['Departments' => 'Department', 'Clients' => 'Client', 'Projects' => 'Project'];
if (($_POST['_kind'] ?? '') === '1') {
    $pick = (string)($_POST['kind'] ?? 'Departments');
    if (isset($PRESETS[$pick])) { $one = $PRESETS[$pick]; $many = $pick; }
    else {
        $one  = trim((string)($_POST['kind_one'] ?? ''));
        $many = trim((string)($_POST['kind_many'] ?? '')) ?: ($one === '' ? '' : $one . 's');
    }
    $shelfPick = (string)($_POST['shelves'] ?? '');
    if ($one === '' || preg_match('#[<>/\\\\]#', $one . $many)) $pBad[] = 'Type the word you use — one and many, e.g. Show / Shows.';
    elseif ($shelfPick !== '/' && !in_array($shelfPick, shelf_choices(), true)) $pBad[] = 'Pick the folder they live in, from the list.';
    else {
        $s = settings();
        $s['organise']['kind'] = ['one' => $one, 'many' => $many];
        $s['organise']['shelves'] = $shelfPick;
        $s['organise']['add_at_ingest'] = ($_POST['open'] ?? '') === '1';
        if (save_settings($s)) { header('Location: /setup.php?plan=kind#plan'); exit; }
        $pBad[] = 'Could not write settings.json — is the web folder writable?';
    }
}
// a pasted list: suggest a folder for each, show it, save nothing yet
if (($_POST['_paste'] ?? '') === '1') {
    $names = [];
    foreach (preg_split('/\R/', (string)($_POST['list'] ?? '')) as $n) {
        $n = trim(preg_replace('/\s+/', ' ', $n));
        if ($n !== '' && !in_array(strtolower($n), array_map('strtolower', $names), true)) $names[] = $n;
    }
    $link = guess_links($names, $folders);
    $rows = array_map(fn($n) => ['name' => $n, 'folder' => $link[$n]], $names);
    $pSaid = 'check: ' . count(array_filter($link)) . ' of ' . count($names) . ' matched to a folder you already have.';
}
if (($_POST['_plan'] ?? '') === '1') {
    $rows = []; $seenFolder = [];
    $names = (array)($_POST['d_name'] ?? []); $links = (array)($_POST['d_folder'] ?? []);
    $names[] = (string)($_POST['add_name'] ?? ''); $links[] = (string)($_POST['add_folder'] ?? '');
    foreach ($names as $i => $n) {
        $n = trim(preg_replace('/\s+/', ' ', (string)$n));
        $f = (string)($links[$i] ?? '');
        if ($n === '' || !empty($_POST['d_drop'][$i])) continue;
        // A catch-all is where everything ends up when nobody is sure. There is
        // no such place: every shoot belongs to somebody. Same rule as Ingest.
        if ($why = shelf_name_problem($n, array_column($rows, 'name'))) $pBad[] = $why;
        $rows[] = ['name' => $n, 'folder' => $f];
        if ($f !== '' && !in_array($f, $folders, true)) $pBad[] = "The folder “{$f}” is not on the shelf any more.";
        if ($f !== '' && isset($seenFolder[$f])) $pBad[] = "$f is linked to both “{$seenFolder[$f]}” and “{$n}”. A folder belongs to one " . strtolower(shelf_word()) . '.';
        if ($f !== '') $seenFolder[$f] = $n;
    }
    if (!$rows) $pBad[] = 'The list is empty. Ingest needs at least one ' . strtolower(shelf_word()) . '.';
    if (!$pBad) {
        $s = settings();
        $s['organise']['departments'] = $rows;
        if (save_settings($s)) { header('Location: /setup.php?plan=1#plan'); exit; }
        $pBad[] = 'Could not write settings.json — is the web folder writable?';
    }
}
if (isset($_GET['plan'])) $pSaid = $_GET['plan'] === 'kind' ? 'kind' : 'ok';

// ── the admin password (was in Jobs and tools) ──
$pw_said = '';
if (isset($_POST['_newpass'])) {
    $new = (string)$_POST['_newpass'];
    // At the Mac itself the current one is not asked (auth.php at_this_mac); from anywhere else it is
    if (!at_this_mac() && !pass_ok((string)($_POST['_oldpass'] ?? ''))) {
        $pw_said = 'The current password is wrong.';
    } elseif (strlen(trim($new)) < 4) {
        $pw_said = 'Pick something at least four characters long.';
    } elseif (new_pass($new, 'Changed the password' . (at_this_mac() ? ' on this Mac' : '') . '; other devices sign in again with it')) {
        $pw_said = 'ok';
    } else {
        $pw_said = 'Could not write ' . pass_file() . ' — check it is writable.';
    }
}

if (($_POST['_save'] ?? '') === '1') {
    $s = settings(); $bad = [];

    $s['name']           = trim((string)($_POST['name'] ?? '')) ?: 'Rushes';
    $s['archive']['url'] = trim((string)($_POST['a_url'] ?? ''));

    // A picked path is kept only if it is still one of the choices, or is the
    // value already saved — a drive that is unplugged today is not an error.
    $pick = function (string $field, string $key, array $allowed) use (&$s, &$bad) {
        $v = (string)($_POST[$field] ?? '');
        $was = s_path($key, '');
        if ($v === '' || $v === $was) return;
        if (!isset($allowed[$v])) { $bad[] = "$v is not something either machine can see."; return; }
        [$a, $b] = explode('.', $key);
        $s[$a][$b] = $v;
    };
    $pick('a_local',  'archive.local',               $lpath);
    $pick('a_helper', 'archive.as_seen_from_helper', $hpath);
    $s['archive']['label'] = basename($s['archive']['local'] ?? '') ?: ($s['archive']['label'] ?? '');

    // Existing sources: renamed, or removed by ticking.
    $kept = [];
    foreach (($s['sources'] ?? []) as $i => $r) {
        if (!empty($_POST['s_drop'][$i])) continue;
        $n = trim((string)($_POST['s_label'][$i] ?? ''));
        if ($n !== '') $r['label'] = $n;
        $kept[] = $r;
    }
    // A new one, from the picker. Which machine sees it is decided by which
    // list it came from, so that can never be set wrong either.
    $add = (string)($_POST['add_src'] ?? '');
    if ($add !== '') {
        [$who, $p] = array_pad(explode('|', $add, 2), 2, '');
        $ok = ($who === 'helper' && isset($hpath[$p])) || ($who === 'archive' && isset($lpath[$p]));
        if (!$ok) $bad[] = 'That source is no longer plugged in. Pick it again.';
        elseif (!in_array($p, array_column($kept, 'path'), true)) {
            $kept[] = ['label' => trim((string)($_POST['add_label'] ?? '')) ?: basename(str_replace('\\', '/', rtrim($p, '/\\'))) ?: $p,
                       'path' => $p, 'seen_by' => $who];
        }
    }
    $s['sources'] = $kept;

    $s['helper']['mode']  = ($_POST['h_mode'] ?? '') === 'external' ? 'external' : 'built_in';
    $s['helper']['label'] = trim((string)($_POST['h_label'] ?? '')) ?: 'workstation';
    unset($s['helper']['enabled'], $s['helper']['command']);   // replaced by mode, and worked out live

    $s['organise']['_'] = 'How media is laid out. one_place: everything is copied into the archive. '
                        . 'in_place: each drive keeps its files and Rushes indexes them where they are.';
    $s['organise']['shape'] = ($_POST['shape'] ?? '') === 'in_place' ? 'in_place' : 'one_place';

    // Duplicates: this archive's own folders are chosen in Manage → Duplicates, where the copies are seen.

    // A project resting, and moved aside (projects.php): days without a save. 0 = never moved aside.
    $s['projects']['rest_days']  = max(1, min(365, (int)($_POST['rest_days'] ?? 10)));

    if ($bad) {
        $said = implode(' ', $bad);
    } else {
        // Beside, then rename: a half-written settings file takes every page down.
        $json = json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents("$file.new", $json . "\n") !== false && @rename("$file.new", $file)) {
            settings(true); runner_paths();             // the runner reads where the archive is from its own line
            header('Location: /setup.php?saved=1'); exit;
        }
        @unlink("$file.new");
        $said = 'Could not write settings.json — is the web folder writable?';
    }
}
if (isset($_GET['saved'])) $said = 'ok';

$s     = settings(true);
$shape = $s['organise']['shape'] ?? 'one_place';
$h     = $s['helper'] ?? [];
$aLoc  = s_path('archive.local', '');
$aHlp  = s_path('archive.as_seen_from_helper', '');
$hmode = helper_mode();
$hname = $h['label'] ?? 'workstation';
$hwho  = helper_name();
$e     = fn($x) => htmlspecialchars((string)$x);

// What is on a drive kept where it is (Setup 01, in place), from the catalogue and the
// last duplicates scan: what is footage, what is editing cache, what is a copy, and what
// waits in its Recently Removed — what could go, before anything moves.
function drive_report(array $d): array {
    require_once __DIR__ . '/db/schema.php';
    $p = rtrim($d['path'], '/') . '/'; $L = strlen($p);
    $in = "substr(path, 1, $L) = '" . SQLite3::escapeString($p) . "'";
    $r = db()->querySingle("SELECT COUNT(*) n, COALESCE(SUM(bytes),0) b, COALESCE(SUM(CASE WHEN kind='video' THEN bytes END),0) v FROM files WHERE $in", true) ?: ['n' => 0, 'b' => 0, 'v' => 0];
    $sw = array_map('cache_sql', cache_groups('sweep')); $kp = array_map('cache_sql', cache_groups('keep'));
    $cache = $sw ? (int)db()->querySingle("SELECT COALESCE(SUM(bytes),0) FROM files WHERE $in AND (" . implode(' OR ', $sw) . ")"
                                          . ($kp ? " AND NOT (" . implode(' OR ', $kp) . ")" : '')) : 0;
    $dup = ((json_decode((string)@file_get_contents(web_dir() . '/dup-summary.json'), true) ?: [])['roots'] ?? [])['-' . drive_key($d)] ?? null;
    $hold = (int)@file_get_contents(web_dir() . '/holding-kb-' . drive_key($d) . '.txt') * 1024;
    $gb = fn($b) => $b >= 1e12 ? round($b / 1e12, 1) . ' TB' : ($b >= 1e9 ? round($b / 1e9, 1) . ' GB' : round($b / 1e6) . ' MB');
    $line = number_format($r['n']) . ' files, ' . $gb($r['b']) . ': footage ' . $gb($r['v']) . ', editing caches ' . $gb($cache)
          . ($dup ? ', copies ' . $gb($dup['bytes']) . ' (' . number_format($dup['copies']) . ($dup['copies'] === 1 ? ' file' : ' files') . ', from the last scan)' : ', copies: not scanned yet')
          . ($hold ? ' · in Recently Removed: ' . $gb($hold) : '');
    return ['files' => (int)$r['n'], 'line' => $line];
}

// What the window offers. Cards are left out — Ingest finds those on its own —
// and so is the archive itself, and the operating system's own clutter.
$noise = ['System Volume Information', 'RECYCLER', 'lost+found', 'Network Trash Folder', 'Temporary Items'];
$offer = [];
foreach ($hv['vols'] as $v) {
    if ($v['card'] || $v['archive']) continue;
    $offer[] = ['who' => 'helper', 'path' => $v['path'], 'name' => $v['name'], 'size' => $v['total'],
                'where' => $hmode === 'external' ? 'on the ' . ($h['label'] ?? 'workstation') : 'plugged in here',
                'folders' => array_values(array_map(fn($d) => ['name' => $d, 'path' => helper_join($v['path'], $d, $hv['os'])],
                              array_filter($v['top'], fn($d) => !in_array($d, $noise, true))))];
}
foreach ($local as $v) {
    if ($v['path'] === s_path('archive.local', '')) continue;
    $offer[] = ['who' => 'archive', 'path' => $v['path'], 'name' => $v['name'], 'size' => 0,
                'where' => 'a share on this machine',
                'folders' => array_map(fn($d) => ['name' => $d, 'path' => $v['path'] . '/' . $d],
                              array_values(array_filter($v['top'], fn($d) => !in_array($d, $noise, true))))];
}
// ⓘ: the explanation a section does not need to show all the time (tokens.css → .infotip)
$tip   = fn($t) => '<span class="infotip" tabindex="0" data-tip="' . $e($t) . '">i</span>';
$N     = 0;                                   // sections are numbered as they show: a Mac has no Helper section
$num   = function () use (&$N) { return sprintf('%02d', ++$N); };
$open  = $said !== '' && $said !== 'ok';      // nothing saved: every section open, to see what to fix
$tb    = fn($b) => $b >= 1099511627776 ? number_format($b / 1099511627776, 1) . ' TB'
                                       : number_format($b / 1073741824) . ' GB';
// the structure section's words and lists
$ONE = shelf_word(); $MANY = shelf_word(true); $one = strtolower($ONE); $many = strtolower($MANY);
$kindNow = $MANY;
$loose  = array_values(array_diff($folders, array_filter(array_column($rows, 'folder'))));
$shelf  = basename(shelf_dir());
$opts = function (string $cur) use ($folders, $e) {
    $o = '<option value="">— a new folder, made the first time</option>';
    foreach ($folders as $f) $o .= '<option value="' . $e($f) . '"' . ($f === $cur ? ' selected' : '') . '>' . $e($f) . '</option>';
    return $o;
};
$planOpen = $open || !shelf_chosen() || !$rows || $pSaid !== '' || $pBad;
?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup &middot; <?= $e($s['name'] ?? 'Rushes') ?></title>
<?php require __DIR__ . '/head.php'; ?>
<style>
  .pick { display: grid; grid-template-columns: 1fr 1fr; gap: 10px }
  @media (max-width: 640px) { .pick { grid-template-columns: 1fr } }
  .opt { border: 1px solid var(--line); border-radius: var(--radius); padding: 14px 16px;
         background: var(--bg); cursor: pointer; display: block; position: relative }
  .opt:has(input:checked) { border-color: var(--accent); background: var(--sel-bg); color: var(--sel-fg) }
  .opt b { display: block; font-size: 14px; margin: 0 0 5px }
  .opt small { color: var(--muted); font-size: 12.5px; line-height: 1.5; display: block }
  .opt:has(input:checked) small { color: var(--sel-fg); opacity: .8 }
  .opt input { position: absolute; opacity: 0; pointer-events: none }
  .soon { font-size: 10.5px; font-weight: 650; border-radius: 9px; padding: 1px 8px;
          background: var(--raised); color: var(--muted); margin-left: 6px }
  .src { display: grid; grid-template-columns: 1fr 1.6fr auto; gap: 10px; align-items: center;
         padding: 10px 0; border-top: 1px solid var(--line-soft) }
  .src:first-of-type { border-top: 0; padding-top: 0 }
  .src .where { font-family: var(--mono); font-size: 12px; color: var(--muted); word-break: break-all }
  .src .where small { display: block; font-family: var(--font); color: var(--faint); font-size: 11px }
  .src label.x { font-size: 12.5px; color: var(--muted); white-space: nowrap; cursor: pointer }
  .add { display: grid; grid-template-columns: 1.6fr 1fr; gap: 10px; margin-top: 14px;
         padding-top: 14px; border-top: 1px solid var(--line) }
  @media (max-width: 640px) { .src, .add { grid-template-columns: 1fr } }
  .seen { font-size: 12.5px; margin: 6px 0 0; color: var(--muted) }
  dialog#addDlg { width: min(620px, calc(100vw - 32px)); max-height: min(640px, calc(100vh - 48px));
    border: 1px solid var(--line); border-radius: 14px; padding: 18px 20px 20px;
    background: var(--surface); color: var(--fg); overflow: auto }
  dialog#addDlg::backdrop { background: rgba(10, 22, 21, .6) }
  .dh { display: flex; align-items: center; margin: 0 0 14px }
  .dh b { font-size: 15px; flex: 1 }
  .drives { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px }
  .drv { border: 1px solid var(--line); border-radius: 10px; background: var(--bg); color: var(--fg);
         padding: 12px 13px; text-align: left; cursor: pointer; font: inherit; display: grid;
         grid-template-columns: 26px 1fr; gap: 10px; align-items: start }
  .drv:hover { border-color: var(--accent) }
  .drv svg { width: 22px; height: 22px; color: var(--accent-text) }
  .drv b { display: block; font-size: 13.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
  .drv small { color: var(--muted); font-size: 11.5px }
  .folders { border: 1px solid var(--line); border-radius: 10px; max-height: 280px; overflow-y: auto }
  .fo { display: flex; gap: 10px; align-items: center; width: 100%; padding: 9px 12px; border: 0;
        border-top: 1px solid var(--line-soft); background: transparent; color: var(--fg);
        font: 13.5px var(--font); text-align: left; cursor: pointer }
  .fo:first-child { border-top: 0; font-weight: 600 }
  .fo:hover { background: var(--raised) }
  .fo[aria-pressed="true"] { background: var(--sel-bg); color: var(--sel-fg) }
  .fo svg { width: 16px; height: 16px; flex: none; opacity: .8 }
  .seen.ok { color: var(--ok) } .seen.bad { color: var(--warn) }
  .how-h { font-size: 11px; text-transform: uppercase; letter-spacing: .07em; color: var(--faint);
           font-weight: 650; margin: 18px 0 8px }
  .how { margin: 14px 0 0; padding-left: 22px; font-size: 13.5px; line-height: 1.6 }
  .how li { margin: 0 0 22px; padding-left: 6px }
  .how li > .note { display: block; margin-top: 6px }          /* the why, under the step */
  .how li .cmd { margin-top: 10px }
  .how + .seen { margin-top: 4px }
  .how li::marker { color: var(--accent-text); font-weight: 650 }
  /* A section that is done is one line: what it is set to, and Change */
  details.sec > summary { list-style: none; cursor: pointer; display: flex; align-items: center; gap: 10px; flex-wrap: wrap }
  details.sec > summary::-webkit-details-marker { display: none }
  details.sec > summary h2 { font-size: 14px; font-weight: 650; margin: 0 }
  details.sec > summary h2 span { color: var(--muted); font-weight: 500; margin-right: 4px }
  details.sec > summary .val { color: var(--muted); font-size: 13px; flex: 1; min-width: 0; overflow-wrap: anywhere }
  details.sec > summary .chg { color: var(--accent-text); font-size: 13px }
  details.sec[open] > summary .val, details.sec[open] > summary .chg { display: none }
  details.sec[open] > summary { margin: 0 0 14px }
  details.add-w > summary { list-style: none; display: inline-flex; margin-top: 14px }
  details.add-w > summary::-webkit-details-marker { display: none }
  details.add-w[open] > summary { display: none }
  /* the structure section */
  h3.sub-h { font-size: 13px; font-weight: 650; margin: 22px 0 6px; padding-top: 16px; border-top: 1px solid var(--line-soft) }
  .kinds { display: flex; gap: 8px; flex-wrap: wrap }
  .opt.sm { padding: 8px 14px } .opt.sm b { margin: 0 }
  .two-in { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 12px; max-width: 420px }
  .dep, .dep-h { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: center }
  .dep { padding: 7px 0; border-top: 1px solid var(--line-soft) }
  .dep.new select { color: var(--muted) }
  .dep label.x { font-size: 12.5px; color: var(--muted); white-space: nowrap; cursor: pointer }
  .dep-h { font-size: 11px; text-transform: uppercase; letter-spacing: .07em; color: var(--faint); font-weight: 650; margin: 0 0 6px }
  .dep-h span:last-child { width: 62px }
  @media (max-width: 640px) { .dep { grid-template-columns: 1fr } .dep-h { display: none } }
  textarea { width: 100%; min-height: 160px; padding: 10px 12px; font: 13.5px/1.5 var(--font); color: var(--fg);
             background: var(--bg); border: 1px solid var(--line); border-radius: var(--radius-sm); resize: vertical }
  .loose { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 }
  .loose span { font-size: 12.5px; padding: 4px 10px; border-radius: 999px; border: 1px dashed var(--line); color: var(--muted) }
  .path { font-family: var(--mono); font-size: 13px; background: var(--bg); padding: 10px 12px;
          border-radius: var(--radius-sm); border: 1px solid var(--line) }
  .path b { color: var(--accent-text); font-weight: 600 }
  .narrow { max-width: 380px }
  .spin { display:inline-block; width:10px; height:10px; border:2px solid var(--line); border-top-color:var(--accent);
          border-radius:50%; animation: spin .8s linear infinite; margin-right: 6px; vertical-align: -1px }
  @keyframes spin { to { transform: rotate(360deg) } }
</style>

<div class="app">
<div class="with-rail">
<?php $RAIL = 'setup'; require __DIR__ . '/db/rail.php'; ?>

  <main class="work">
    <form class="pad form" method="post">
      <div class="head"><h1>Setup</h1>
        <span class="sub">Where things are on this installation.</span></div>

      <?php if ($said === 'ok'): ?>
        <div class="banner ok"><div class="txt">Saved. Every page reads the new settings on its next load.</div></div>
      <?php elseif ($said): ?>
        <div class="banner bad"><div class="txt"><b>Nothing was saved.</b><?= $e($said) ?></div></div>
      <?php endif; ?>

      <!-- ══ 01 the decision everything else follows ══ -->
      <details class="grp sec"<?= $open || !isset($s['organise']['shape']) ? ' open' : '' ?>>
        <summary><h2><span><?= $num() ?> /</span> How media is organised<?= $tip('The one decision that changes what every other page does.') ?></h2>
          <span class="val"><?= $shape === 'in_place' ? 'Left on its own drives' : 'Brought to one place' ?></span>
          <span class="chg">Change</span></summary>
        <div class="pick">
          <label class="opt"><input type="radio" name="shape" value="one_place" <?= $shape !== 'in_place' ? 'checked' : '' ?>>
            <b>Bring everything to one place<?= $tip('Media is copied into this archive and organised on its shelves. One drive to back up, one place to look.') ?></b>
            <small>Copied into this archive.</small></label>
          <label class="opt"><input type="radio" name="shape" value="in_place" <?= $shape === 'in_place' ? 'checked' : '' ?>>
            <b>Leave media on its own drives<?= $tip('The drives in 03 keep their files where they are. Rushes lists each one, searches across all of them at once (an unplugged drive too, and says which to plug in), and lists a drive again whenever it comes back.') ?></b>
            <small>Rushes lists them where they are.</small></label>
        </div>
      </details>

      <!-- ══ 02 the archive ══ -->
      <?php // what the archive is follows 01 (the page's script swaps it when 01 changes)
            $archTip = ['The drive your footage is copied to. Rushes keeps its records here too.',
                        'Where Rushes keeps its records. Your footage stays on the drives in 03.']; ?>
      <?php $aUrl = rtrim((string)($s['archive']['url'] ?? ''), '/'); $here = here_url(); $byName = name_url();
            $aHost = (string)parse_url($aUrl ?: $here, PHP_URL_HOST);
            $aDrive = ''; foreach ($local as $v) if ($v['path'] === $aLoc) $aDrive = $v['name'];
            $aNum = !on_mac() && filter_var(trim($aHost, '[]'), FILTER_VALIDATE_IP);     // a number: the warning shows, so open
            $aDone = $aLoc !== '' && (on_mac() || $aUrl !== '') && !$aNum; ?>
      <details class="grp sec"<?= $open || !$aDone ? ' open' : '' ?>>
        <summary><h2><span><?= $num() ?> /</span> This archive<span id="archTip"><?= $tip($shape === 'in_place' ? $archTip[1] : $archTip[0]) ?></span></h2>
          <span class="val"><?= $e($s['name'] ?? 'Rushes') ?> &middot; <?= $e($aDrive ?: basename($aLoc)) ?> &middot; <?= $e($aUrl ?: $here) ?>
            <button type="button" class="ghost" data-copy="<?= $e($aUrl ?: $here) ?>">Copy</button></span>
          <span class="chg">Change</span></summary>
        <label class="f"><span>What to call it</span>
          <input type="text" name="name" value="<?= $e($s['name'] ?? 'Rushes') ?>"></label>

        <label class="f"><span>Where it lives, on this machine</span>
          <select name="a_local">
            <?php $seen = false; foreach ($local as $v): $seen = $seen || $v['path'] === $aLoc; ?>
              <option value="<?= $e($v['path']) ?>" <?= $v['path'] === $aLoc ? 'selected' : '' ?>><?= $e($v['name']) ?> &nbsp;&mdash;&nbsp; <?= $e($v['path']) ?></option>
            <?php endforeach; if (!$seen && $aLoc): ?>
              <option value="<?= $e($aLoc) ?>" selected><?= $e($aLoc) ?> (not visible right now)</option>
            <?php endif; ?>
          </select></label>

        <?php if (on_mac()): ?>
          <!-- Rushes on this Mac (HOW-IT-WORKS.md → Rushes on this Mac): its address is this Mac's own -->
          <input type="hidden" name="a_url" value="<?= $e($aUrl ?: $here) ?>">
          <label class="f"><span>Address<?= $tip('Other devices open it only when Let other devices open Rushes is on in the Rushes app, at this Mac\'s name on the network or its Tailscale address, and only with the password.') ?></span></label>
          <div class="seen"><b><?= $e($aUrl ?: $here) ?></b> <button type="button" class="ghost" data-copy="<?= $e($aUrl ?: $here) ?>">Copy</button></div>
        <?php else: ?>
        <label class="f"><span>Address people open Rushes at</span>
          <input type="text" name="a_url" id="aUrl" value="<?= $e($aUrl ?: $here) ?>"></label>
        <?php if ($aUrl === '' && $here !== ''): ?>
          <div class="seen">Filled in from the address you opened this page at — press <b>Save settings</b> to keep it.</div>
        <?php endif; ?>
        <?php if (filter_var(trim($aHost, '[]'), FILTER_VALIDATE_IP)): ?>
          <!-- A number can change under everyone's feet; a name usually does not. -->
          <div class="seen bad" id="addrWarn">This address is a number, and numbers can change.
            <?= str_starts_with($aHost, '169.254.')
              ? 'One that starts with 169.254 is one a machine gives itself when nothing on the network hands out addresses — it can come back different after a restart.'
              : 'Unless it is fixed on the router or on this machine, the network can hand out a different one.' ?>
            Rushes Helper follows a change by itself, but people's bookmarks do not.
            <?php if ($byName !== ''): ?>
              <span id="nameTry" data-name="<?= $e($byName) ?>">Checking whether this machine opens by its name, <b><?= $e($byName) ?></b> …</span>
            <?php else: ?>
              To stop it changing, give this machine a fixed address in its network settings.
            <?php endif; ?>
          </div>
          <script>
            (function () {
              const el = document.getElementById('nameTry'); if (!el) return;
              const name = el.dataset.name, ctl = new AbortController();
              setTimeout(function () { ctl.abort(); }, 4000);
              fetch(name + '/db/helper.php?hash', { mode: 'no-cors', cache: 'no-store', signal: ctl.signal })
                .then(function () {
                  el.innerHTML = '✓ Its name works from this computer: <b></b>. A name keeps working when the number changes. ' +
                    '<button type="button" class="ghost">Use the name</button>';
                  el.querySelector('b').textContent = name;
                  el.querySelector('button').onclick = function () {
                    document.getElementById('aUrl').value = name;
                    this.outerHTML = '<b>Filled in ✓ — press Save settings to keep it.</b>';
                  };
                })
                .catch(function () {
                  el.textContent = 'Its name (' + name + ') does not open from this computer, so the number stays. ' +
                    'To stop it changing, give this machine a fixed address in its network settings.';
                });
            })();
          </script>
        <?php endif; ?>
        <?php endif; /* not on a Mac */ ?>
      </details>

      <!-- ══ the archive's structure: what the top folders are, and how a shoot is filed ══
           Its controls belong to their own forms (form="pK", "pL", "pP", declared after this
           page's form): saving the plan never saves, or needs, the rest of Setup. -->
      <details class="grp sec" id="plan"<?= $planOpen ? ' open' : '' ?>>
        <summary><h2><span><?= $num() ?> /</span> Archive structure<?= $tip('One shape for the archive: a shelf per ' . $one . ', and inside it every shoot filed by year and date. Ingest files every new card straight into it. Saving it moves nothing; Reorganize moves older folders into it.') ?></h2>
          <span class="val"><?= !shelf_chosen() ? '<b style="color:var(--warn)">Not set yet: Ingest waits for this</b>'
              : $e(count($rows) . ' ' . (count($rows) === 1 ? $one : $many) . ', in ' . (shelf_is_top() ? ($s['archive']['label'] ?? 'the archive') . ' itself' : shelf_name())) ?></span>
          <span class="chg">Change</span></summary>

        <?php if ($pSaid === 'kind'): ?>
          <div class="banner ok"><div class="txt">Saved. Rushes now says &ldquo;<?= $e($MANY) ?>&rdquo;, they live in <?= $e(shelf_is_top() ? ($s['archive']['label'] ?? 'the archive') . ' itself' : shelf_name()) ?>, and <?= shelf_open() ? 'anyone can add one at Ingest' : 'only the admin adds them, here' ?>.</div></div>
        <?php elseif ($pSaid === 'ok'): ?>
          <div class="banner ok"><div class="txt">Saved. Ingest offers exactly this list from now on. Nothing on disk was moved.</div></div>
        <?php elseif ($pBad): ?>
          <div class="banner bad"><div class="txt"><b>Nothing was saved.</b><?= implode('<br>', array_map($e, $pBad)) ?></div></div>
        <?php elseif ($pSaid): ?>
          <div class="banner warn"><div class="txt"><b>Not saved yet &mdash; <?= $e($pSaid) ?></b>
            Check each link below, fix any that are wrong, then press Save.</div></div>
        <?php endif; ?>

        <h3 class="sub-h">What your top folders are</h3>
        <p class="note" style="margin:0 0 10px">The same shape whatever you call them &mdash; only the word changes, everywhere Rushes says it.</p>
        <div class="kinds">
          <?php foreach ($PRESETS as $m => $o): ?>
            <label class="opt sm"><input form="pK" type="radio" name="kind" value="<?= $e($m) ?>" <?= $kindNow === $m ? 'checked' : '' ?>><b><?= $e($m) ?></b></label>
          <?php endforeach; $custom = !isset($PRESETS[$kindNow]); ?>
          <label class="opt sm"><input form="pK" type="radio" name="kind" value="custom" id="kCustom" <?= $custom ? 'checked' : '' ?>><b>Another word</b></label>
        </div>
        <div class="two-in" id="kWords" <?= $custom ? '' : 'hidden' ?>>
          <label class="f"><span>One</span><input form="pK" type="text" name="kind_one" value="<?= $custom ? $e($ONE) : '' ?>" placeholder="Show"></label>
          <label class="f"><span>Many</span><input form="pK" type="text" name="kind_many" value="<?= $custom ? $e($MANY) : '' ?>" placeholder="Shows"></label>
        </div>

        <label class="f" style="margin-top:16px"><span>Which folder in <?= $e($s['archive']['label'] ?? 'the archive') ?> they live in</span>
          <select form="pK" name="shelves">
          <?php if (!shelf_chosen()): ?><option value="">— pick one (Ingest and Reorganize wait for this)</option><?php endif; ?>
          <option value="/" <?= shelf_is_top() ? 'selected' : '' ?>><?= $e($s['archive']['label'] ?? 'The archive') ?> itself: <?= $e($many) ?> straight at the top</option>
          <?php foreach (shelf_choices() as $f): ?>
            <option value="<?= $e($f) ?>" <?= $f === shelf_name() ? 'selected' : '' ?>><?= $e($f) ?></option>
          <?php endforeach; ?>
        </select>
        <small>The shelf: every <?= $e($one) ?> has its own folder inside it, and every shoot is filed there.
          Make the folder in the archive first if it is not in the list.</small></label>

        <p style="margin:16px 0 8px;font-size:13px"><b>Who can add a new <?= $e($one) ?>?</b></p>
        <div class="pick">
          <label class="opt"><input form="pK" type="radio" name="open" value="0" <?= !shelf_open() ? 'checked' : '' ?>>
            <b>Only here, by the admin</b>
            <small>For a list that hardly changes &mdash; departments, regular clients. Someone at Ingest can only pick from it.</small></label>
          <label class="opt"><input form="pK" type="radio" name="open" value="1" <?= shelf_open() ? 'checked' : '' ?>>
            <b>Anyone, while bringing a shoot in</b>
            <small>For a list that grows every week &mdash; projects. Ingest gets &ldquo;add a new one&rdquo;; the no-catch-all rule still applies.</small></label>
        </div>
        <div class="btns" style="margin-top:14px"><button form="pK" class="btn quiet" type="submit">Save these three</button></div>

        <h3 class="sub-h">Your <?= $e($many) ?></h3>
        <?php if (!$rows): ?>
          <p class="note" style="margin:0 0 10px">Every shoot goes on one of these shelves, and Ingest offers exactly this list &mdash;
             nothing else, and no &ldquo;Others&rdquo;. Paste them one per line. Rushes suggests
             which folder you already have for each one; you check it before anything is saved.</p>
          <textarea form="pL" name="list" placeholder="Parks &amp; Recreation&#10;Police Department&#10;Public Works Department&#10;…"></textarea>
          <div class="btns" style="margin-top:12px">
            <button form="pL" class="btn" type="submit">Suggest folders</button>
            <span class="note">Saves nothing yet.</span></div>
        <?php else: ?>
          <p class="note" style="margin:0 0 10px">Every shoot goes on one of these shelves, and Ingest offers exactly this list.
             A linked folder is used as it is &mdash; nothing is renamed, so no Premiere project breaks.</p>
          <div class="dep-h"><span><?= $e($ONE) ?></span><span>Its folder in <?= $e($shelf) ?></span><span></span></div>
          <?php foreach ($rows as $i => $r): ?>
            <div class="dep<?= ($r['folder'] ?? '') === '' ? ' new' : '' ?>">
              <input form="pP" type="text" name="d_name[<?= $i ?>]" value="<?= $e($r['name']) ?>" aria-label="<?= $e($ONE) ?>">
              <select form="pP" name="d_folder[<?= $i ?>]" aria-label="Its folder"><?= $opts($r['folder'] ?? '') ?></select>
              <label class="x"><input form="pP" type="checkbox" name="d_drop[<?= $i ?>]" value="1"> remove</label>
            </div>
          <?php endforeach; ?>
          <div class="dep" style="border-top:1px solid var(--line);margin-top:6px;padding-top:12px">
            <input form="pP" type="text" name="add_name" placeholder="add a <?= $e($one) ?>" aria-label="New <?= $e($one) ?>">
            <select form="pP" name="add_folder" aria-label="Its folder"><?= $opts('') ?></select>
            <span></span>
          </div>
          <div class="btns" style="margin-top:14px">
            <button form="pP" class="btn" type="submit">Save</button>
            <span class="note">Writes the plan. Moves nothing.</span></div>
        <?php endif; ?>

        <h3 class="sub-h">Folders not in the plan</h3>
        <?php if ($loose): ?>
          <p class="note" style="margin:0 0 10px">Folders already on the shelf that no <?= $e($one) ?> in the plan uses. They stay exactly where
             they are and stay searchable; Ingest never offers them. <a href="/structure.php">Reorganize</a> is where each one gets a home.</p>
          <div class="loose"><?php foreach ($loose as $f): ?><span><?= $e($f) ?></span><?php endforeach; ?></div>
        <?php else: ?>
          <p class="note" style="margin:0">None &mdash; every folder on the shelf belongs to a <?= $e($one) ?>.</p>
        <?php endif; ?>

        <h3 class="sub-h">How a shoot's folder is named</h3>
        <p class="note" style="margin:0 0 10px">Every shoot that comes through Ingest lands like this. The date comes from the camera,
           not from whoever brings the card in.</p>
        <div class="path"><?= $e($shelf) ?> / <b><?= $e($one) ?></b> / <b>year</b> / <b>date</b> <b>what it was</b>
          <div class="note" style="margin-top:6px;font-family:var(--font)">e.g. <?= $e($shelf) ?> / PARKS / 2026 / 20260926 Spring Festival</div></div>
      </details>

      <!-- ══ 03 sources ══ -->
      <div class="grp">
        <h2 style="margin-bottom:14px"><span><?= $num() ?> /</span> <?= $shape === 'in_place' ? 'The drives' : 'Where footage comes from' ?><?= $tip($shape === 'in_place'
            ? 'The drives Rushes looks after where they are. Each is known by its own ID, so it is found again under another name; while it is unplugged its files stay in Search. Cards do not need adding: Ingest finds them when they are plugged in.'
            : 'Drives and shares Rushes brings media in from. Cards do not need adding: Ingest finds them when they are plugged in.') ?></h2>

        <?php foreach (($s['sources'] ?? []) as $i => $r): ?>
          <div class="src">
            <input type="text" name="s_label[<?= $i ?>]" value="<?= $e($r['label'] ?? '') ?>" aria-label="Name">
            <div class="where"><?= $e($r['path']) ?>
              <small>seen by <?= ($r['seen_by'] ?? '') === 'archive' || $hmode !== 'external' ? 'this machine' : 'the ' . $e($hname) ?>
              <?php foreach (drives_seen() as $d): if ($d['source'] !== rtrim($r['path'], '/')) continue; $rp = drive_report($d); ?>
                &middot; <?= !empty($d['connected']) ? 'plugged in' . ($d['path'] !== $d['source'] ? ', now at ' . $e($d['path']) : '')
                                                      : ($d['seen'] ? 'not plugged in since ' . $e(ago_words((int)$d['seen'])) : 'not plugged in yet') ?>
                <?php if ($rp['files']): ?><br><?= $e($rp['line']) ?><?php endif; ?>
              <?php endforeach; ?></small></div>
            <label class="x"><input type="checkbox" name="s_drop[<?= $i ?>]" value="1"> remove</label>
          </div>
        <?php endforeach; ?>
        <?php if (!($s['sources'] ?? [])): ?><p class="note" style="margin:0">None yet.</p><?php endif; ?>

        <div class="btns" style="margin-top:14px">
          <button type="button" class="btn quiet tab-i" id="addOpen"><?= icon('plus', 2) ?> Add a drive or folder</button>
        </div>
        <input type="hidden" name="add_src" id="addSrc">
        <input type="hidden" name="add_label" id="addLabel">
      </div>

      <!-- ══ 04 helper ══ -->
      <!-- On a Mac the helper is the Rushes app itself: nothing to set, so no section -->
      <?php if (on_mac()): ?><input type="hidden" name="h_mode" value="built_in"><?php endif; ?>
      <details class="grp sec"<?= on_mac() ? ' hidden' : '' ?><?= $open || !$hv['fresh'] || ($hmode === 'external' && !helper_paired()) ? ' open' : '' ?>>
        <summary><h2><span><?= on_mac() ? '' : $num() . ' /' ?></span> Helper<?= $tip('The part of Rushes that copies. It watches for cards and drives, and does whatever Ingest and Transfers ask for.') ?></h2>
          <span class="val"><?= $hmode === 'external' ? 'On the ' . $e($hname) : 'Built in' ?> &middot; running</span>
          <span class="chg">Change</span></summary>
        <div class="pick"<?= on_mac() ? ' hidden' : '' ?>>
          <label class="opt"><input type="radio" name="h_mode" value="built_in" <?= $hmode !== 'external' ? 'checked' : '' ?>>
            <b>Built in</b>
            <small>Runs on this machine. Cards and drives plugged in here are what it sees.
                   What almost every installation wants.</small></label>
          <label class="opt"><input type="radio" name="h_mode" value="external" <?= $hmode === 'external' ? 'checked' : '' ?>>
            <b>External</b>
            <small>Runs on another computer that can see something this one cannot &mdash;
                   cards in a workstation, or a server on another network.</small></label>
        </div>

        <!-- Only an external helper has a name and its own view of the archive. -->
        <div id="hExt" style="margin-top:16px" <?= $hmode !== 'external' ? 'hidden' : '' ?>>
          <label class="f"><span>What to call that computer</span>
            <input type="text" name="h_label" value="<?= $e($hname) ?>"></label>
        <label class="f"><span>Where it finds the archive</span>
          <select name="a_helper">
            <?php $seen = false; foreach ($hv['vols'] as $v): $seen = $seen || $v['path'] === $aHlp; ?>
              <option value="<?= $e($v['path']) ?>" <?= $v['path'] === $aHlp ? 'selected' : '' ?>><?= $e($v['name']) ?><?= $v['archive'] ? ' — this is the archive ✓' : '' ?></option>
            <?php endforeach; if (!$seen && $aHlp): ?>
              <option value="<?= $e($aHlp) ?>" selected><?= $e($aHlp) ?> (not reported right now)</option>
            <?php endif; ?>
          </select>
          <?php $found = array_values(array_filter($hv['vols'], fn($v) => $v['archive'])); ?>
          <?php if ($found && $found[0]['path'] === $aHlp): ?>
            <div class="seen ok">✓ The <?= $e($hname) ?> can see the archive there.</div>
          <?php elseif ($found): ?>
            <div class="seen bad">The <?= $e($hname) ?> finds the archive at <b><?= $e($found[0]['path']) ?></b> — pick that.</div>
          <?php endif; ?>
        </label>

        </div>

        <div style="margin-top:14px">
          <?php if ($hv['fresh']): ?>
            <div class="seen ok">✓ The helper is running on <?= $e($hwho) ?><?= $hv['how'] === 'service' ? ' in the background' : ($hv['how'] === 'window' ? ' in a Terminal window' : '') ?> &mdash; <?= count($hv['vols']) ?> drive<?= count($hv['vols']) === 1 ? '' : 's' ?> visible.</div>
          <?php else: ?>
            <div class="seen bad"><?= $hv['at'] ? 'The helper stopped — last heard from ' . $e(ago_words($hv['at'])) . '.' : 'The helper is not running yet.' ?>
              Drives are missing from the lists above until it is.</div>
          <?php endif; ?>

          <?php if ($hmode === 'external'): $pp = helper_paired(); ?>
            <!-- Pairing (HOW-IT-WORKS.md → Pairing): only one helper is given work. -->
            <div class="seen <?= $pp ? 'ok' : 'bad' ?>" style="margin-top:8px" id="pairState">
              <?= $pp ? '✓ Paired with ' . $e($pp['host'] ?: 'a helper') . ' since ' . $e(date('j M Y', (int)$pp['at'])) . ' — only it is given work.'
                      : 'No helper is paired: any helper on the network is given work, and two at once would copy over each other.' ?>
              <?php foreach (helper_refused() as $r): ?>
                <br>Refused: <?= $e($r['host'] ?: $r['ip']) ?> asked for work <?= $e(ago_words($r['at'])) ?> — it is not the paired helper.
              <?php endforeach; ?>
            </div>
            <p style="margin:8px 0"><button type="button" class="btn" id="pairBtn">Pair a helper</button> <span id="pairSaid"></span></p>
            <script>
            (function () {
              var b = document.getElementById('pairBtn'), said = document.getElementById('pairSaid');
              var paired = <?= $pp ? 'true' : 'false' ?>;
              b.onclick = async function () {
                // Pairing another Mac takes work away from the one paired now: asked twice.
                if (paired && !b.dataset.sure) {
                  b.dataset.sure = '1'; b.textContent = 'Sure? The Mac paired now gets no more work';
                  setTimeout(function () { delete b.dataset.sure; b.textContent = 'Pair a helper'; }, 6000); return;
                }
                delete b.dataset.sure; b.disabled = true; b.textContent = 'Asking …';
                try {
                  var r = await (await fetch('/db/pair.php', {method: 'POST', body: new URLSearchParams({action: 'start'})})).json();
                  if (r.error) throw new Error(r.error);
                  // The code in a box with its own Copy button: Rushes Helper pastes it in one press.
                  said.innerHTML = '<span class="code" style="font-size:20px;letter-spacing:3px;padding:4px 10px">' + r.code.slice(0, 3) + ' ' + r.code.slice(3) +
                    '</span> <button type="button" class="ghost" id="pairCopy">Copy</button> — then in Rushes Helper on the Mac (its window): ' +
                    '<b>Paste the code from Rushes</b>. Within ten minutes; it works once.';
                  document.getElementById('pairCopy').onclick = function () { copyText(r.code, this); };
                  // Said here the moment the helper is paired (asked every 3 s, only while this page shows the code).
                  var asked = r.until - 605, until = Date.now() + 600000;      // the server's own clock: the code was made 600 s before it ends
                  (async function look() {
                    if (Date.now() > until || document.hidden) { if (Date.now() <= until) setTimeout(look, 3000); return; }
                    try {
                      var p = await (await fetch('/db/pair.php?t=' + Date.now())).json();
                      if (p.at && p.at >= asked) {
                        var st = document.getElementById('pairState');
                        st.className = 'seen ok'; st.textContent = '✓ Paired with ' + (p.host || 'a helper') + ' just now — only it is given work.';
                        said.textContent = ''; paired = true; return;
                      }
                    } catch (e) {}
                    setTimeout(look, 3000);
                  })();
                } catch (e) { said.textContent = 'Could not make a code: ' + e.message; }
                b.disabled = false; b.textContent = 'Pair a helper';
              };
            })();
            </script>
          <?php endif; ?>

          <?php $win = helper_windows(); $url = rtrim((string)(settings()['archive']['url'] ?? ''), '/'); ?>
          <?php if ($hmode !== 'external'): $bi = trim((string)@file_get_contents(web_dir() . '/helper-builtin.txt')); ?>
            <!-- Built in: the runner starts it and starts it again. Nothing to open. -->
            <?php if (!on_mac()): ?>
            <div class="how-h">Rushes keeps it running on this machine</div>
            <p class="note" style="margin:0">It starts by itself, and again within a minute if it ever stops.
               Nothing to open and no window to keep open.</p>
            <?php endif; ?>
            <?php if ($bi === 'no-python'): ?>
              <div class="seen bad">Python 3 is not installed on this machine yet. Install <b>Python 3</b> from the App
                Center once; the helper starts by itself a minute later.</div>
            <?php elseif ($bi === 'unsigned'): ?>
              <div class="seen bad">Not started: the helper's code in <code>_rushes</code> is not a signed release
                (<code>release.sig</code> missing, or not matching), or <code>release.py</code> is not installed yet.
                It runs with full rights on this machine, so only signed code runs. The job log says which.</div>
            <?php endif; ?>
          <?php elseif (!$win && $url !== ''): ?>
            <!-- A Mac: installed once as a background service. It starts at login,
                 restarts itself, stays awake only while copying, and updates
                 itself from here — the terminal is needed exactly once. -->
            <div class="how-h">Install Rushes Helper on <?= $e($hwho) ?> — once</div>
            <p class="note" style="margin:0 0 8px">After that it runs in the background: it starts when <?= $e($hwho) ?> is on
               and logged in, starts again if it stops, keeps the Mac awake only while it copies. No window to keep open. When Rushes
               has a newer version, it offers <b>Update to …</b> in its menu; nothing changes until someone presses it.</p>
            <?php if (!is_readable(archive_dir() . '/_rushes/Rushes Helper.zip')): ?>
              <div class="seen bad">Rushes Helper for Mac is not on the archive yet: it goes in <b>_rushes/Rushes Helper.zip</b>.</div>
            <?php endif; ?>
            <p class="phone-only note">Rushes Helper is a Mac app: install it from that Mac, on this page. From a phone you
              can pair it, and see and switch what it does (Manage).</p>
            <ol class="how mac-only">
              <li>On <b><?= $e($hwho) ?></b>, open this page and download it.
                <span class="note">About 30 MB. It carries its own copy of Python — the free, open-source language the
                helper is written in — so nothing else has to be installed or updated on the Mac. Safari unpacks it into
                Downloads.</span>
                <p style="margin:12px 0 6px"><a class="btn" href="<?= $e($url) ?>/db/helper.php?app">Download Rushes Helper</a></p></li>
              <li>Copy this address. Rushes Helper asks for it, and usually fills it in by itself:
                <?= cmd_block($url) ?></li>
              <li>Open <b>Rushes Helper</b> from Downloads. The first time, macOS stops it with
                <i>“Apple could not verify Rushes Helper…”</i> — press <b>Done</b>. That is because it is not signed with a
                paid Apple developer account, not because anything is wrong with it. Then open <b>System Settings →
                Privacy &amp; Security</b>, scroll down to <i>“Rushes Helper was blocked”</i>, press <b>Open Anyway</b> and
                confirm. macOS asks this once.</li>
              <li>From there it explains itself: it moves into Applications, asks where Rushes is, starts in the
                background, and walks you through the two permissions macOS needs a person for. When macOS asks whether
                Rushes Helper may <i>“find and connect to devices on your local network”</i>, press <b>Allow</b> — that is
                how it talks to Rushes. Then <b>Full Disk Access</b>, one switch, and it says ✓ when it is on.</li>
            </ol>
            <?php if ($hv['fresh'] && $hv['how'] === 'window'): ?>
              <div class="seen">Right now it runs in a Terminal window. Install it as above, then close that window:
                the background one takes over. Only one ever runs at a time.</div>
            <?php elseif ($hv['fresh'] && $hv['how'] === 'service'): ?>
              <div class="seen ok">✓ Installed: it runs in the background on <?= $e($hwho) ?>.</div>
            <?php endif; ?>
            <details class="note mac-only" style="margin-top:8px"><summary>Install from Terminal instead, take it off, or run it in a window</summary>
              <p>From Terminal — the same app, and macOS does not stop it the first time:
                <?= cmd_block("curl -fsS \"" . $url . "/db/helper.php?install\" | sh") ?></p>
              <p>To take it off: open Rushes Helper from Applications and press <b>Remove…</b>, or
                <?= cmd_block("curl -fsS \"" . $url . "/db/helper.php?remove\" | sh") ?></p>
              <p>To run it in a Terminal window instead — it stops when the window closes: <?= cmd_block(helper_command()) ?></p>
            </details>
          <?php elseif (!$win): ?>
            <div class="seen bad">Save the archive's web address in <b>02 · Archive</b> first — the install command is built from it.</div>
          <?php else: ?>
          <div class="how-h"><?= $hv['fresh'] ? 'To start it again, after a restart' : 'How to start it' ?></div>
          <ol class="how">
            <li>On <b><?= $e($hwho) ?></b>, open <b>Command Prompt</b>: press the <b>Windows</b> key, type <b>cmd</b>, press <b>Enter</b>.</li>
            <li>Press <b>Copy</b>, click inside that window, paste with <b>Ctrl V</b> and press <b>Enter</b>.
              <?= cmd_block(helper_command()) ?></li>
            <li>Leave the window open. That window <i>is</i> the helper: it shows what it is doing
              as it works, and closing it stops the copying. Anything half-copied carries on
              when it is started again.</li>
          </ol>
          <p class="note" style="margin:6px 0 0">If it says Python was not found, install it from python.org first — once.
            On Windows it runs in a window for now; starting by itself comes later.</p>
          <?php endif; ?>
          <p class="note" style="margin:6px 0 0"<?= on_mac() ? " hidden" : "" ?>>Changed the kind above? Save first &mdash; these instructions follow the saved setting.</p>
        </div>
        <script>
          document.addEventListener('DOMContentLoaded', function () {
            const OFFER = <?= json_encode($offer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
            const ICON  = <?= json_encode(['drive' => icon('server', 1.6), 'folder' => icon('projects', 1.7)]) ?>;
            const $ = function (i) { return document.getElementById(i); };
            const esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) {
              return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
            const size = function (b) { return b >= 1099511627776 ? (b / 1099511627776).toFixed(1) + ' TB'
                                             : b ? Math.round(b / 1073741824) + ' GB' : ''; };
            let drive = null, pick = null;

            function drives() {
              $('d1').hidden = false; $('d2').hidden = true;
              $('dNone').hidden = OFFER.length > 0;
              $('dDrives').innerHTML = OFFER.map(function (d, i) {
                return '<button type="button" class="drv" data-i="' + i + '">' + ICON.drive +
                  '<span><b>' + esc(d.name) + '</b><small>' + esc([size(d.size), d.where].filter(Boolean).join(' · ')) +
                  '</small></span></button>';
              }).join('');
              $('dDrives').querySelectorAll('.drv').forEach(function (b) {
                b.onclick = function () { folders(OFFER[+b.dataset.i]); };
              });
            }
            function folders(d) {
              drive = d; pick = null;
              $('d1').hidden = true; $('d2').hidden = false;
              $('dName').textContent = d.name;
              const rows = [{ name: 'The whole drive', path: d.path, whole: true }].concat(d.folders);
              $('dFolders').innerHTML = rows.map(function (f, i) {
                return '<button type="button" class="fo" data-i="' + i + '" aria-pressed="false">' +
                  (f.whole ? ICON.drive : ICON.folder) + esc(f.name) + '</button>';
              }).join('');
              $('dFolders').querySelectorAll('.fo').forEach(function (b) {
                b.onclick = function () {
                  $('dFolders').querySelectorAll('.fo').forEach(function (x) { x.setAttribute('aria-pressed', 'false'); });
                  b.setAttribute('aria-pressed', 'true');
                  const f = rows[+b.dataset.i];
                  pick = f.path;
                  $('dLabel').value = f.whole ? d.name : f.name;
                  $('dPicked').textContent = f.whole ? d.name + ', all of it' : d.name + ' › ' + f.name;
                  $('dAdd').disabled = false;
                };
              });
              $('dLabel').value = ''; $('dPicked').textContent = 'Pick one.'; $('dAdd').disabled = true;
            }
            $('addOpen').onclick = function () { drives(); $('addDlg').showModal(); };
            $('dClose').onclick = function () { $('addDlg').close(); };
            $('dBack').onclick = drives;
            // Adding saves straight away, and the page comes back saying so —
            // with the new source in the list above.
            $('dAdd').onclick = function () {
              $('addSrc').value = drive.who + '|' + pick;
              $('addLabel').value = $('dLabel').value.trim();
              this.disabled = true; this.textContent = 'Saving…';
              document.querySelector('form.form').submit();
            };
          });
          document.querySelectorAll('[name=h_mode]').forEach(function (r) {
            r.onchange = function () {
              document.getElementById('hExt').hidden = r.value !== 'external' || !r.checked; }; });
          // Copy and ⓘ in a section's one line do their own thing, not open the section
          document.querySelectorAll('details.sec > summary button, details.sec > summary .infotip, label.opt .infotip').forEach(function (x) {
            x.addEventListener('click', function (ev) { ev.preventDefault(); }); });
          // What "This archive" is follows the choice in 01, as it is picked
          const ARCH = <?= json_encode($archTip) ?>;
          document.querySelectorAll('[name=shape]').forEach(function (r) {
            r.addEventListener('change', function () {
              document.querySelector('#archTip .infotip').dataset.tip = ARCH[r.value === 'in_place' ? 1 : 0]; }); });
        </script>
      </details>

      <!-- ══ editors' computers ══ -->
      <div class="grp">
        <?php $ws = watchers(); $wurl = rtrim((string)(settings()['archive']['url'] ?? ''), '/');
              // On a Mac, editors' computers reach it by this Mac's name, once other devices are let in
              if (on_mac() && name_url() !== '') $wurl = name_url(); ?>
        <h2 style="margin-bottom:14px"><span><?= $num() ?> /</span> Editors' computers (<span id="wN" style="margin:0"><?= count($ws) ?></span>)<?= $tip('Rushes Watcher keeps each editor\'s Premiere projects in the archive: the files a project uses and what the editor exports. Install it once on each editor\'s computer. The editor keeps working as usual.') ?></h2>
        <div id="wList">
        <?php if (!$ws): ?><p class="note" style="margin:0">None yet. Each computer you add appears here.</p><?php endif; ?>
        <?php
          require_once __DIR__ . '/db/schema.php';
          // When each computer was last heard from (its Watcher reports every few minutes while it runs),
          // and its last saved project: when that editor last worked.
          $ago = function (int $t): string { $d = time() - $t;
              return $d < 120 ? 'just now' : ($d < 7200 ? round($d / 60) . ' min ago' : ($d < 172800 ? round($d / 3600) . ' h ago' : date('j M Y', $t))); };
          foreach ($ws as $k => $w):
            $key = substr($k, 0, 16);
            $heard = 0; foreach (explode("\n", explode("\n--\n", (string)@file_get_contents(web_dir() . "/watchers/$key.txt"))[0]) as $l)
                if (str_starts_with($l, "at\t")) $heard = (int)substr($l, 3);
            $last = null; try { $st = db()->prepare('SELECT name, saved FROM projects WHERE watcher = :k ORDER BY saved DESC LIMIT 1');
                $st->bindValue(':k', $key); $last = $st->execute()->fetchArray(SQLITE3_ASSOC) ?: null; } catch (Throwable $x) {} ?>
          <div class="seen" style="display:flex;gap:10px;align-items:center;padding:8px 0;border-top:1px solid var(--line-soft)">
            <span style="flex:1"><b style="color:var(--fg)"><?= $e($w['name'] ?? ($w['host'] ?: 'a computer')) ?></b> &mdash; <?= $e($w['host'] ?? '') ?>, paired <?= $e(date('j M Y', (int)$w['at'])) ?>
              <?= !empty($w['folder']) ? '· its folder: <code>Projects/' . $e($w['folder']) . '</code>' : '· <b>pair it again</b> to give it its folder' ?>
              <br><small>Last heard from <?= $heard ? $e($ago($heard)) : 'never yet' ?> ·
                <?= $last ? 'last project saved ' . $e($ago((int)$last['saved'])) . ': ' . $e($last['name']) : 'no project saved yet' ?></small></span>
            <button type="button" class="ghost wForget" data-key="<?= $e(substr($k, 0, 16)) ?>" data-host="<?= $e($w['name'] ?? $w['host']) ?>">Remove</button>
          </div>
        <?php endforeach; ?>
        </div>
        <details class="add-w"<?= $ws ? '' : ' open' ?>><summary class="btn quiet tab-i"><?= icon('plus', 2) ?> Add an editor's computer</summary>
        <?php if (on_mac()): ?>
          <div class="seen" style="margin-top:14px">Editors' computers reach Rushes on this Mac only while <b>Let other devices open Rushes</b>
            is on in the Rushes app, and the password is your own (not the first one).</div>
        <?php endif; ?>
        <ol class="how">
          <li>On the editor's computer, download Rushes Watcher (about 30 MB):
            <p class="mac-only" style="margin:12px 0 6px"><a class="btn" href="<?= $e($wurl) ?>/db/helper.php?app=watcher">Download Rushes Watcher</a></p>
            <small class="mac-only">Not at that computer? Send the editor this link to download it:</small>
            <small class="phone-only">Rushes Watcher is a Mac app. Send the editor this link, to open on their Mac:</small>
            <?= cmd_block($wurl . '/db/helper.php?app=watcher') ?></li>
          <li>Open <b>Rushes Watcher</b> from Downloads. The first time, macOS stops it with
            <i>“Apple could not verify Rushes Watcher…”</i>: press <b>Done</b>, then <b>System Settings → Privacy &amp; Security</b>,
            <i>“Rushes Watcher was blocked”</i>, <b>Open Anyway</b>. macOS asks this once. Its window explains the rest.</li>
          <li>Here, type the computer's name (the editor's, usually) and press the button:
            <p style="margin:10px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <input type="text" id="wName" placeholder="Name, e.g. Maria" maxlength="40" style="width:14em">
              <button type="button" class="btn" id="wPair">Make a code</button></p>
            <div id="wSaid"></div></li>
          <li>In Rushes Watcher, press <b>Paste the code from Rushes</b>. The computer appears in the list above.</li>
        </ol>
        </details>
        <details style="margin-top:14px"><summary class="note">More settings: when a project is called resting</summary>
          <label class="f"><span>Resting after</span>
            <input type="number" name="rest_days" min="1" max="365" style="width:7em" value="<?= (int)(settings()['projects']['rest_days'] ?? 10) ?>"> days without a save
            <small>Nothing moves: Manage and the editor's Watcher say it, so everyone knows the project is quiet.</small></label>
        </details>
        <script>
        (function () {
          var b = document.getElementById('wPair'), said = document.getElementById('wSaid'), nameIn = document.getElementById('wName');
          var esc = function (t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; };
          b.onclick = async function () {
            var nm = nameIn.value.trim();
            if (!nm) { said.textContent = 'Type the computer\'s name first: its folder in Projects carries it.'; nameIn.focus(); return; }
            b.disabled = true; b.textContent = 'Asking …';
            try {
              var r = await (await fetch('/db/pair.php', {method: 'POST', body: new URLSearchParams({action: 'start', role: 'watcher', name: nm})})).json();
              if (r.error) throw new Error(r.error);
              said.innerHTML = '<p style="margin:6px 0">The code for <b>' + esc(nm) + '</b>: <span class="code" style="font-size:20px;letter-spacing:3px;padding:4px 10px">' +
                r.code.slice(0, 3) + ' ' + r.code.slice(3) + '</span> <button type="button" class="ghost" data-copy="' + r.code + '">Copy</button></p>' +
                '<p class="note" style="margin:0">Within ten minutes; it works once.</p>';
              // Said here the moment that computer pairs (asked every 3 s, only while this page shows the code).
              var asked = r.until - 605, until = Date.now() + 600000;
              (async function look() {
                if (Date.now() > until) return;
                if (document.hidden) { setTimeout(look, 3000); return; }
                try {
                  var p = await (await fetch('/db/pair.php?watchers&t=' + Date.now())).json(), ws = p.watchers || [];
                  var got = ws.filter(function (w) { return w.at >= asked; })[0];
                  if (got) {
                    document.getElementById('wN').textContent = ws.length;
                    document.getElementById('wList').innerHTML = ws.map(function (w) {
                      return '<div class="seen ok">✓ <b>' + esc(w.name || w.host) + '</b> &mdash; ' + esc(w.host) + (w.folder ? ' · its folder: <code>Projects/' + esc(w.folder) + '</code>' : '') + '</div>';
                    }).join('');
                    said.innerHTML = '<p class="seen ok" style="margin:6px 0">✓ ' + esc(got.name) + ' is paired. To add the next editor, type their computer\'s name.</p>';
                    nameIn.value = ''; nameIn.focus(); return;
                  }
                } catch (e) {}
                setTimeout(look, 3000);
              })();
            } catch (e) { said.textContent = 'Could not make a code: ' + e.message; }
            b.disabled = false; b.textContent = 'Make a code';
          };
          document.querySelectorAll('.wForget').forEach(function (x) {
            x.onclick = async function () {
              // That computer stops being able to deliver: asked twice, on the button.
              if (!sure(x, 'Sure? ' + (x.dataset.host || 'It') + ' can no longer deliver files', 'forget:' + x.dataset.key)) return;
              x.disabled = true;
              try {
                var r = await (await fetch('/db/pair.php', {method: 'POST', body: new URLSearchParams({action: 'forget', key: x.dataset.key})})).json();
                if (r.error) throw new Error(r.error);
                x.textContent = 'Removed ✓';
              } catch (e) { x.textContent = 'Did not happen: ' + e.message; x.disabled = false; }
            };
          });
        })();
        </script>
      </div>

      <input type="hidden" name="_save" value="1">
      <button class="btn" type="submit">Save settings</button><?= $tip('Rules about media (what counts as cache, which files are never swept, when a disk is too full) are the same everywhere and live in rules.json, not here.') ?>
    </form>
    <!-- the structure section's own forms: its controls name them (form="…") -->
    <form id="pK" method="post" action="/setup.php#plan" hidden><input type="hidden" name="_kind" value="1"></form>
    <form id="pL" method="post" action="/setup.php#plan" hidden><input type="hidden" name="_paste" value="1"></form>
    <form id="pP" method="post" action="/setup.php#plan" hidden><input type="hidden" name="_plan" value="1"></form>
    <script>
      document.querySelectorAll('[name=kind]').forEach(function (r) {
        r.onchange = function () { document.getElementById('kWords').hidden = !document.getElementById('kCustom').checked; }; });
    </script>

    <div class="pad form" style="padding-top:0">
      <!-- ══ the admin password ══ -->
      <div class="grp" id="password">
        <h2 style="margin-bottom:12px">Admin password<?= $tip('One password for this archive, no accounts. It guards Manage and anything that moves files. Search and Ingest stay open to anyone who can reach this address.') ?></h2>
        <form method="post" action="/setup.php#password" class="narrow">
          <?php if ($pw_said === 'ok'): ?>
            <div class="banner ok" style="margin-bottom:12px"><div class="txt">Changed. Phones and other computers sign in again with the new one.</div></div>
          <?php elseif ($pw_said): ?>
            <div class="banner bad" style="margin-bottom:12px"><div class="txt"><?= $e($pw_said) ?></div></div>
          <?php endif; ?>
          <?php if (!at_this_mac()): ?>
            <label class="f"><span>Current</span><input id="op" name="_oldpass" type="password" autocomplete="current-password"></label>
          <?php else: ?>
            <p class="note" style="margin:0 0 12px">You are at the Mac Rushes runs on, so the current one is not asked.</p>
          <?php endif; ?>
          <label class="f"><span>New</span><input id="np" name="_newpass" type="password" autocomplete="new-password"></label>
          <button class="btn" type="submit">Change it</button>
        </form>
      </div>

      <!-- ══ no lock-in: everything Rushes knows, in formats any other program reads ══ -->
      <div class="grp" id="exports-sec">
        <h2 style="margin-bottom:8px">Take everything with you</h2>
        <p class="note" style="margin:0 0 10px">What Rushes knows, in open formats, to keep or to move to
          another program. Downloaded to this computer; nothing on the archive changes.</p>
        <div class="btns" id="exports">
          <a class="btn quiet" href="/db/export.php?what=files" download>Every file, and what it is (CSV)</a>
          <a class="btn quiet" href="/db/export.php?what=moments" download>What describing found (CSV)</a>
          <a class="btn quiet" href="/db/export.php?what=pulls" download>Every pull (JSON)</a>
          <a class="btn quiet" href="/db/export.php?what=copies" download>Where else each file exists (CSV)</a>
        </div>
        <p class="note" style="margin:10px 0 0">Already open files on the archive, readable without Rushes:
          the descriptions (<code>_rushes/analysis</code>, JSON), the record of every copy and move
          (<code>_rushes/origin</code>, text), and the copy proofs (the <code>ascmhl</code> folder in each
          copied folder, ASC MHL).</p>
      </div>

      <!-- ══ jobs and tools: run by hand, for when something looks wrong ══ -->
      <details class="grp sec" id="tools">
        <summary><h2>Jobs and tools</h2>
          <span class="val">Run a job by hand &mdash; for when something looks wrong</span>
          <span class="chg">Open</span></summary>
        <div class="btns" id="toolBtns">
          <?php foreach ([['manifest', 'Rebuild the file list', 'Write down every file and its size, then rebuild search. A few minutes.'],
                          ['import', 'Rebuild search', 'Rebuild search from the file list. Seconds to minutes.'],
                          ['verify', 'Check Recently Removed', 'Check every file in Recently Removed still has a twin in the archive. Moves nothing.'],
                          ['df', 'Measure free space', ''],
                          ['proxy-plan', 'Plan proxies (changes nothing)', 'Count the videos that have no proxy yet, and how much there is to read. Makes nothing.'],
                          ['gpu-test', 'Test the video chip (changes nothing, about a minute)', 'Measure what the video chip can do: test encodes and one real clip. Writes a report; makes no video files.']] as [$k, $l, $ask]): ?>
            <button class="btn quiet" type="button" data-t="<?= $k ?>" data-ask="<?= $e($ask) ?>"><?= $e($l) ?></button>
          <?php endforeach; ?>
        </div>
        <div id="toolState" class="note" style="margin-top:10px"></div>
        <p class="note" style="margin:12px 0 0">These run whether or not anything in Manage says you need them. Each step shows in the raw log (Activity).</p>
      </details>
    </div>
    <script>
    (function () {
      var $ = function (i) { return document.getElementById(i); };
      var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
      var TOOL = {manifest: 'Rebuild the file list', import: 'Rebuild search', verify: 'Check Recently Removed', df: 'Measure free space',
        'proxy-plan': 'Plan proxies', 'proxy-build': 'Make proxies', 'gpu-test': 'Test the video chip', 'proxy-test': 'Test proxy settings', scan: 'Find duplicates'};
      // Each download says so on itself, then goes back.
      document.querySelectorAll('#exports a').forEach(function (a) {
        a.addEventListener('click', function () { var was = a.textContent; a.textContent = 'Downloading ✓';
          setTimeout(function () { a.textContent = was; }, 2500); }); });
      // Where each job is: asked, running, finished (from the same state Manage reads), while this section is open
      async function state() {
        if (!$('tools').open || document.hidden) return;
        try {
          var d = await (await fetch('/db/state.php?t=' + Date.now())).json(), tq = d.queued || [];
          $('toolState').innerHTML =
            (d.running ? '<p><span class="spin"></span>Running: <b>' + esc(TOOL[d.running] || d.running) + '</b>' + (d.progress ? ' · ' + d.progress.pct + '%' : '') + '</p>' : '') +
            (tq.length ? '<p>Asked: <b>' + tq.map(function (x) { return esc(TOOL[x] || x); }).join(', ') + '</b> — it starts at the next turn, within a minute</p>' : '') +
            (!d.running && !tq.length ? '<p>Nothing running ✓</p>' : '') +
            (d.gpu_test ? '<div style="margin-top:10px;padding:10px 12px;border:1px solid var(--line);border-radius:8px"><b>Video chip test</b> · ' +
              (d.running === 'gpu-test' ? 'running now' : 'finished ' + new Date(d.gpu_test.at * 1000).toLocaleString()) +
              '<pre style="white-space:pre-wrap;margin:8px 0 0;font:12px/1.5 var(--mono)">' + esc(d.gpu_test.text) + '</pre></div>' : '');
        } catch (e) { $('toolState').textContent = 'Could not reach the archive: ' + e.message; }
      }
      $('tools').addEventListener('toggle', state);
      setInterval(state, 4000);
      document.querySelectorAll('#toolBtns [data-t]').forEach(function (b) {
        b.onclick = async function () {
          var t = b.dataset.t, was = b.textContent.replace(/^Sure\? /, '');
          if (b.dataset.ask && !sure(b, b.dataset.ask, 'act:' + t)) return;     // asked on the button itself
          b.disabled = true; b.textContent = t === 'import' ? 'Rebuilding search…' : 'Asking…';
          try {
            if (t === 'import') {       // the same rebuild the runner does; the old search keeps working until the new one is complete
              var r = await (await fetch('/db/import.php?part=web&force=1', {method: 'POST', signal: AbortSignal.timeout(900000)})).json();
              b.textContent = r.state === 'retrying' ? 'Kept the old search — ' + (r.error || 'try again')
                            : r.state === 'updating' ? 'Already rebuilding — give it a minute' : 'Search is up to date ✓';
            } else {
              var j = await (await fetch('/run.php', {method: 'POST', body: new URLSearchParams({action: t})})).json();
              b.textContent = j.error ? 'Did not happen: ' + j.error : 'Asked ✓';
            }
          } catch (e) { b.textContent = 'Could not reach the archive'; }
          state();
          setTimeout(function () { b.textContent = was; b.disabled = false; }, 3000);
        };
      });
      if (location.hash === '#tools') $('tools').open = true;
    })();
    </script>

    <!-- One question at a time: which drive, then the whole of it or one
         folder, then what to call it. Built from what the machines report, so
         there is nothing to type and nothing that can be mistyped. -->
    <dialog id="addDlg" aria-labelledby="dT">
      <div class="dh"><b id="dT">Add a source</b>
        <button type="button" class="ghost" id="dClose" aria-label="Close">Close</button></div>

      <div id="d1">
        <p class="note" style="margin:0 0 12px">Which drive is the footage on?</p>
        <div class="drives" id="dDrives"></div>
        <p class="note" id="dNone" hidden style="margin:0">No drives are showing. If the footage
          is on another computer, start its helper first &mdash; see <b>Helper</b>.</p>
      </div>

      <div id="d2" hidden>
        <button type="button" class="ghost" id="dBack">&larr; All drives</button>
        <p class="note" style="margin:12px 0 8px">The whole of <b id="dName"></b>, or one folder in it?</p>
        <div class="folders" id="dFolders"></div>
        <label class="f" style="margin:14px 0 0"><span>Call it</span>
          <input type="text" id="dLabel"></label>
        <div class="btns" style="margin-top:14px">
          <button type="button" class="btn" id="dAdd" disabled>Add and save</button>
          <span class="note" id="dPicked"></span>
        </div>
      </div>
    </dialog>
  </main>
</div>
</div>
