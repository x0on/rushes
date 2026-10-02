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
require __DIR__ . '/db/config.php';
require __DIR__ . '/db/auth.php';
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

    // Duplicates: this archive's own folders, one name per line. A name is a
    // folder anywhere in a path, so no slashes; Rushes adds them itself.
    $names = function (string $field) use (&$bad) {
        $out = [];
        foreach (preg_split('/\R/', (string)($_POST[$field] ?? '')) as $n) {
            $n = trim(preg_replace('/\s+/', ' ', $n));
            if ($n === '') continue;
            if (preg_match('#[/\\\\]#', $n) || mb_strlen($n) > 100) { $bad[] = "“{$n}” is not a folder name: no slashes, at most 100 letters."; continue; }
            if (!in_array($n, $out, true)) $out[] = $n;
        }
        return $out;
    };
    $s['duplicates'] = ['never_keep' => $names('d_never'), 'card_dumps' => $names('d_cards')];

    // Editors' work (06): the stock library's folder in the archive.
    $lib = trim(preg_replace('#[/\\\\:*?"<>|]+#', ' ', (string)($_POST['lib'] ?? '')));
    $s['library']['folder'] = $lib !== '' ? mb_substr($lib, 0, 80) : 'Stock Library';
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
$tb    = fn($b) => $b >= 1099511627776 ? number_format($b / 1099511627776, 1) . ' TB'
                                       : number_format($b / 1073741824) . ' GB';
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
      <div class="grp">
        <h2><span>01 /</span> How media is organised</h2>
        <p>The one decision that changes what every other page does.</p>
        <div class="pick">
          <label class="opt"><input type="radio" name="shape" value="one_place" <?= $shape !== 'in_place' ? 'checked' : '' ?>>
            <b>Bring everything to one place</b>
            <small>Media is copied into this archive and organised on its shelves.
                   One drive to back up, one place to look.</small></label>
          <label class="opt"><input type="radio" name="shape" value="in_place" <?= $shape === 'in_place' ? 'checked' : '' ?>>
            <b>Leave media on its own drives<span class="soon">next</span></b>
            <small>Each drive keeps its files and carries its own index. Rushes searches
                   across all of them at once, and catches a drive up whenever it comes back.</small></label>
        </div>
      </div>

      <!-- ══ 02 the archive ══ -->
      <div class="grp">
        <h2><span>02 /</span> This archive</h2>
        <p>The collection everything is measured against.</p>
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

        <?php $aUrl = rtrim((string)($s['archive']['url'] ?? ''), '/'); $here = here_url(); $byName = name_url();
              $aHost = (string)parse_url($aUrl ?: $here, PHP_URL_HOST); ?>
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
      </div>

      <!-- ══ 03 sources ══ -->
      <div class="grp">
        <h2><span>03 /</span> Where footage comes from</h2>
        <p>Drives and shares Rushes brings media in from. Cards do not need adding —
           Ingest finds them when they are plugged in.</p>

        <?php foreach (($s['sources'] ?? []) as $i => $r): ?>
          <div class="src">
            <input type="text" name="s_label[<?= $i ?>]" value="<?= $e($r['label'] ?? '') ?>" aria-label="Name">
            <div class="where"><?= $e($r['path']) ?>
              <small>seen by <?= ($r['seen_by'] ?? '') === 'archive' || $hmode !== 'external' ? 'this machine' : 'the ' . $e($hname) ?></small></div>
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
      <div class="grp">
        <h2><span>04 /</span> Helper</h2>
        <p>The part of Rushes that copies. It watches for cards and drives, and does
           whatever Ingest and Transfers ask for.</p>
        <div class="pick">
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
            <div class="how-h">Rushes keeps it running on this machine</div>
            <p class="note" style="margin:0">It starts by itself, and again within a minute if it ever stops.
               Nothing to open and no window to keep open.</p>
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
            <ol class="how">
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
            <details class="note" style="margin-top:8px"><summary>Install from Terminal instead, take it off, or run it in a window</summary>
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
          <p class="note" style="margin:6px 0 0">Changed the kind above? Save first &mdash; these instructions follow the saved setting.</p>
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
        </script>
      </div>

      <!-- ══ 05 duplicates ══ -->
      <?php $dup = $s['duplicates'] ?? []; ?>
      <div class="grp">
        <h2><span>05 /</span> Duplicates: which copy is kept</h2>
        <p>When the same file is in several places, Manage → Duplicates keeps one copy and moves the
           others to the holding folder. Some copies are never the one kept, in any archive: the
           recycle bin, <code>Copied_</code> folders, Premiere's Media Cache, files saved with a
           doubled extension (those are in <code>rules.json</code>). Here you add this archive's
           own folders. One folder name per line; it counts wherever it appears in a path.</p>
        <label class="f"><span>Folders whose copies are never kept</span>
          <textarea name="d_never" rows="3" placeholder="e.g. someone's desk folder"><?= $e(implode("\n", $dup['never_keep'] ?? [])) ?></textarea></label>
        <label class="f"><span>Card-dump folders</span>
          <textarea name="d_cards" rows="3" placeholder="e.g. CARD DUMPS"><?= $e(implode("\n", $dup['card_dumps'] ?? [])) ?></textarea>
          <small>Where whole cards were once copied as they were. The copy on your shelf
            (<?= shelf_name() !== '' ? '<b>' . $e(shelf_name()) . '</b>' : 'chosen in Reorganize' ?>) wins over these.
            Leave it empty and neither side is preferred.</small></label>
      </div>

      <!-- ══ 06 editors' work ══ -->
      <div class="grp">
        <h2><span>06 /</span> Editors' work</h2>
        <p>Rushes Watcher keeps each editor's Premiere projects in the archive: the files a project uses and what
           the editor exports. Install it once on each editor's computer. The editor keeps working as usual.</p>
        <?php $ws = watchers(); $wurl = rtrim((string)(settings()['archive']['url'] ?? ''), '/'); ?>
        <div class="how-h" style="margin-top:14px">Add an editor's computer</div>
        <ol class="how">
          <li>On the editor's computer, download Rushes Watcher (about 30 MB):
            <p style="margin:12px 0 6px"><a class="btn" href="<?= $e($wurl) ?>/db/helper.php?app=watcher">Download Rushes Watcher</a></p>
            <small>Not at that computer? Send the editor this link to download it:</small>
            <?= cmd_block($wurl . '/db/helper.php?app=watcher') ?></li>
          <li>Open <b>Rushes Watcher</b> from Downloads. The first time, macOS stops it with
            <i>“Apple could not verify Rushes Watcher…”</i>: press <b>Done</b>, then <b>System Settings → Privacy &amp; Security</b>,
            <i>“Rushes Watcher was blocked”</i>, <b>Open Anyway</b>. macOS asks this once. Its window explains the rest.</li>
          <li>Here, type the computer's name (the editor's, usually) and press the button:
            <p style="margin:10px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <input type="text" id="wName" placeholder="Name, e.g. Maria" maxlength="40" style="width:14em">
              <button type="button" class="btn" id="wPair">Add an editor's computer</button></p>
            <div id="wSaid"></div></li>
          <li>In Rushes Watcher, press <b>Paste the code from Rushes</b>. The computer appears in the list below.</li>
        </ol>
        <div class="how-h" style="margin-top:14px">Editors' computers (<span id="wN"><?= count($ws) ?></span>)</div>
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
          <div class="seen ok" style="display:flex;gap:10px;align-items:center">
            <span>✓ <b><?= $e($w['name'] ?? ($w['host'] ?: 'a computer')) ?></b> &mdash; <?= $e($w['host'] ?? '') ?>, paired <?= $e(date('j M Y', (int)$w['at'])) ?>
              <?= !empty($w['folder']) ? '· its folder: <code>Projects/' . $e($w['folder']) . '</code>' : '· <b>pair it again</b> to give it its folder' ?>
              <br><small class="muted">Last heard from <?= $heard ? $e($ago($heard)) : 'never yet' ?> ·
                <?= $last ? 'last project saved ' . $e($ago((int)$last['saved'])) . ': ' . $e($last['name']) : 'no project saved yet' ?></small></span>
            <button type="button" class="ghost wForget" data-key="<?= $e(substr($k, 0, 16)) ?>" data-host="<?= $e($w['name'] ?? $w['host']) ?>">Remove</button>
          </div>
        <?php endforeach; ?>
        </div>
        <details style="margin-top:14px"><summary class="note">More settings: the stock library's folder, and when a project is called resting</summary>
          <label class="f"><span>The stock library's folder, in the archive</span>
            <input type="text" name="lib" value="<?= $e(settings()['library']['folder'] ?? 'Stock Library') ?>">
            <small>Music, stock footage and sound effects that projects use are kept here once, however many projects use them.</small></label>
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
            b.disabled = false; b.textContent = 'Add an editor\'s computer';
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
      <button class="btn" type="submit">Save settings</button>
      <p class="note" style="margin:12px 0 0">Rules about media &mdash; what counts as cache,
         which files are never swept, when a disk is too full &mdash; are the same everywhere
         and live in <code>rules.json</code>, not here.</p>
    </form>

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
          is on another computer, start its helper first &mdash; see <b>04 Helper</b>.</p>
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
