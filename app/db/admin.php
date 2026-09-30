<?php
// admin.php — the workspace for keeping the archive healthy.
//
// Everything here follows one rule: a tool appears when something is true, not
// because it exists. The rail says what the app can do; the tiles and cards say
// what it should do right now. A tile with a number on it IS the button.
//
// state.php works all of that out. This file renders it and sends instructions
// back. It decides nothing about media.
$NAV = 'admin';
require __DIR__ . '/auth.php';
require_sign_in();   // the whole page is behind the lock, not each button

$pw_said = '';
if (isset($_POST['_newpass'])) {
    $new = (string)$_POST['_newpass'];
    if (!pass_ok((string)($_POST['_oldpass'] ?? ''))) {
        $pw_said = 'The current password is wrong.';
    } elseif (strlen(trim($new)) < 4) {
        $pw_said = 'Pick something at least four characters long.';
    } elseif (set_pass($new)) {
        $pw_said = 'ok';
    } else {
        $pw_said = 'Could not write ' . pass_file() . ' — check it is writable.';
    }
}
?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manage &middot; Rushes</title>
<?php require __DIR__ . '/../head.php'; ?>
<style>
  .with-side { grid-template-columns: var(--rail-w) minmax(0,1fr) 296px }
  .side { background: var(--surface); border-left: 1px solid var(--line);
          display: flex; flex-direction: column; min-height: 0 }
  .side-h { display: flex; align-items: center; gap: 8px; padding: 12px 14px;
            border-bottom: 1px solid var(--line); font-size: 11px; font-weight: 650;
            letter-spacing: .09em; text-transform: uppercase; color: var(--faint) }
  .side-h .ghost { margin-left: auto; text-transform: none; letter-spacing: 0 }
  .side-body { flex: 1; overflow-y: auto; min-height: 0 }
  .side-f { border-top: 1px solid var(--line); padding: 10px 14px; font-size: 11.5px;
            color: var(--muted); flex: none }
  .transfer-summary { margin: 16px 0; padding: 20px; border: 1px solid var(--line);
    border-radius: var(--radius); background: var(--surface) }
  .transfer-summary .job-top { display:flex; align-items:baseline; justify-content:space-between; gap:16px; flex-wrap:wrap }
  .transfer-summary h2 { margin:0; font-size:15px }
  .transfer-summary .job-percent { font-size:28px; font-weight:650; font-variant-numeric:tabular-nums }
  .transfer-summary progress { display:block; width:100%; height:8px; margin:14px 0;
    border:0; border-radius:4px; overflow:hidden; appearance:none; background:var(--line); color:var(--accent) }
  .transfer-summary progress::-webkit-progress-bar { background:var(--line); border-radius:4px }
  .transfer-summary progress::-webkit-progress-value { background:var(--accent); border-radius:4px }
  .transfer-summary progress::-moz-progress-bar { background:var(--accent); border-radius:4px }
  .transfer-summary .job-percent small { font-size:14px; font-weight:500; color:var(--muted) }
  .transfer-summary p { margin:5px 0; line-height:1.5 }
  .side .ev { padding: 9px 14px }
  .hctl { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 12px 0; padding: 10px 14px;
          border: 1px solid var(--line); border-radius: var(--radius); font-size: 13px; background: var(--surface) }
  .hctl .t { flex: 1; min-width: 220px; color: var(--muted) }
  .hctl .t b { color: var(--fg); font-weight: 600 }
  .hctl .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--faint); flex: none }
  .hctl .dot.ok { background: var(--ok) } .hctl .dot.off { background: var(--bad) }
  .hctl .btn { padding: 6px 12px; font-size: 12.5px }
  .hctl .note { flex-basis: 100%; margin: 0; font-size: 12.5px; color: var(--muted) }
  .transfer-summary .now { border: 0; padding: 0; margin: 16px 0 10px; background: none }
  .transfer-summary .hctl { border: 0; border-top: 1px solid var(--line); border-radius: 0; background: none;
                            margin: 16px 0 0; padding: 14px 0 0 }
  .hctl .alarm { flex-basis: 100%; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 10px 12px;
                 border: 1px solid var(--bad); background: var(--bad-bg); border-radius: 8px; color: var(--fg) }
  .hctl .alarm p { margin: 0; flex: 1; min-width: 240px; line-height: 1.5 }
  .mgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px }
  .mo { border: 1px solid var(--line); border-radius: 8px; overflow: hidden; background: var(--bg); font-size: 12.5px }
  .mo img, .mo .said { display: block; width: 100%; aspect-ratio: 16 / 9; object-fit: cover; background: var(--line) }
  .mo .said { display: flex; align-items: center; justify-content: center; font-size: 24px; color: var(--muted) }
  .mo .b { padding: 8px 10px 10px; line-height: 1.45 }
  .mo .tc { font-size: 11px; color: var(--accent-text) }
  .mo .ons { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px }
  .mo .ons span { font-size: 10.5px; padding: 1px 7px; border-radius: 99px; border: 1px solid var(--line); color: var(--muted) }
  .mo .ons span.txt { background: var(--warn-bg); border-color: var(--warn) }
  .mo small { display: block; margin-top: 6px; color: var(--faint); font-size: 11px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
  .steps2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px }
  .steps2 > div { border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; background: var(--bg) }
  .steps2 p { margin: 6px 0 0; font-size: 13px; line-height: 1.5 }
  table.prep { width: 100%; border-collapse: collapse; font-size: 13px }
  table.prep th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--faint);
                  font-weight: 650; padding: 6px 8px; border-bottom: 1px solid var(--line) }
  table.prep td { padding: 8px; border-bottom: 1px solid var(--line); vertical-align: top }
  table.prep .ok { color: var(--ok) } table.prep .busy { color: var(--accent-text); font-weight: 600 }
  table.prep .bad { color: var(--warn) } table.prep .dim { color: var(--faint) }
  .warnline { margin: 12px 0 0; padding: 10px 12px; border: 1px solid var(--warn); background: var(--warn-bg); border-radius: 8px; font-size: 13px }
  @media (max-width: 1200px) { .with-side { grid-template-columns: var(--rail-w) 1fr }
                               .side { display: none } }
  @media (max-width: 900px)  { .with-side { grid-template-columns: 1fr } }
  .spin { display:inline-block; width:10px; height:10px; border:2px solid var(--line); border-top-color:var(--accent);
          border-radius:50%; animation:spin .9s linear infinite; vertical-align:-1px }
  @keyframes spin { to { transform: rotate(360deg) } }
</style>

<div class="app" style="grid-template-rows:1fr">
<div class="with-rail with-side">

<?php $RAIL = 'overview'; require __DIR__ . '/rail.php'; ?>

  <main class="work">
    <div class="pad">
      <div class="head">
        <h1 id="title">Overview</h1>
        <span class="sub" id="built"></span>
      </div>

      <div id="err" class="banner bad" hidden></div>
      <?php if (pass_is_default()): ?>
      <div class="banner warn">
        <div class="txt"><b>The admin password is still the default</b>
          Anyone on this network can open this page and move your files.
          Change it below &mdash; it takes ten seconds.</div>
      </div>
      <?php endif; ?>

      <!-- always true, then only what is -->
      <div class="tiles" id="tiles"></div>

      <!-- what the helper is doing this second -->
      <div class="now" id="now" hidden></div>
      <!-- the helper itself: is it there, which version, and its buttons -->
      <div class="hctl" id="hctl" hidden></div>

      <section id="transferSummary" class="transfer-summary" aria-label="Transfer job" hidden></section>
      <div id="cards"></div>

      <!-- ══ transfers ══ -->
      <section id="pane-transfers" hidden>
        <div class="head" style="margin:26px 0 12px">
          <h1 style="font-size:14px">Copying from <span id="from">&mdash;</span>
            &rarr; <span id="to">&mdash;</span></h1>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px" id="panes">
          <div class="panel">
            <header><b>Still to come</b><span class="n" id="leftSum"></span></header>
            <div class="scroll" id="todo"></div>
          </div>
          <div class="panel">
            <header><b>Already in the archive</b><span class="n" id="rightSum"></span></header>
            <div class="scroll" id="landed"></div>
          </div>
        </div>
        <p class="note" id="queuenote" style="margin:12px 0 0"></p>
        <div class="btns" style="margin-top:12px">
          <button class="btn" id="goCopy" disabled>Copy the ticked ones</button>
          <button class="btn quiet" id="allTodo">Tick everything</button>
          <span class="note" id="selnote">Nothing ticked.</span>
        </div>
        <p class="note" id="watchhint" style="margin:16px 0 0"></p>
      </section>

      <!-- ══ duplicates ══ -->
      <!-- Two steps, never one: look first, then move. Nothing is ever deleted,
           so 'put them back' is always available. -->
      <section id="pane-duplicates" hidden>
        <div class="panel" style="margin-top:8px">
          <header><b>Files that are the same file</b></header>
          <div style="padding:14px">
            <p class="note" style="margin:0 0 12px">Same content, not same name. Size first,
              then the ends of the file where two sizes collide. Copies move to the holding
              folder &mdash; the space comes back when you empty it, not before.</p>
            <div class="btns">
              <button class="btn" data-t="plan">Look for duplicates</button>
              <button class="btn quiet" data-t="apply">Move the copies aside</button>
              <button class="btn quiet" data-t="undo">Put them back</button>
            </div>
          </div>
        </div>
      </section>

      <!-- ══ cache ══ -->
      <!-- Files editing software rebuilds by itself. Counted from the search
           database, so it takes a second, not a sweep of the whole share. -->
      <section id="pane-cache" hidden>
        <div class="panel" style="margin-top:8px">
          <header><b>Rebuildable cache</b><span class="n" id="cTotal"></span></header>
          <div id="cRows"><div class="empty">Counting&hellip;</div></div>
        </div>
        <div class="btns" style="margin-top:12px">
          <button class="btn" id="cMove" disabled>Move them out</button>
          <span class="note">They go to the holding folder, not the bin. Editing software rebuilds
            them from the originals, so nothing is lost; the space comes back when you empty that folder.</span>
        </div>

        <div class="panel" style="margin-top:18px">
          <header><b>Left alone</b><span class="n">never moved</span></header>
          <div id="cKeep"></div>
        </div>
        <details style="margin-top:14px">
          <summary class="note" style="cursor:pointer">A few examples of what would move</summary>
          <div id="cEx" class="code block" style="white-space:pre;overflow-x:auto"></div>
        </details>
      </section>

      <!-- ══ activity ══ -->
      <section id="pane-activity" hidden>
        <div class="panel" style="margin-top:8px">
          <header><b>What has happened</b></header>
          <div id="events"></div>
        </div>
        <details style="margin-top:16px">
          <summary class="note" style="cursor:pointer">Show the raw log — the jobs this machine ran, in the order they ran:
            newest at the bottom, the last few screens only</summary>
          <pre id="log" class="code block" style="max-height:420px;overflow:auto;
               white-space:pre-wrap;margin-top:10px">&nbsp;</pre>
        </details>
      </section>

      <!-- ══ jobs and tools ══ -->
      <section id="pane-describe" hidden>
        <!-- One job per folder, two steps in a fixed order, on two machines. -->
        <div class="panel" style="margin-top:8px">
          <header><b>Prepare folders</b> <span class="note">· so their footage plays in search and can be found by what is in it</span></header>
          <div style="padding:14px">
            <div class="steps2">
              <div><b>1 · Proxies</b> <span class="note">on the archive machine</span>
                <p>A small, light copy of each video (1080p, H.264) that plays in any browser and reads much faster
                  than the camera original. Kept in their own folder, <code>PROXIES</code>, with the same paths as the
                  originals, so nothing mixes with the footage. Made at low priority; a file that arrived in the last
                  two hours waits for a later run, so nothing still being copied is touched.</p></div>
              <div><b>2 · Descriptions</b> <span class="note">on the helper</span>
                <p>Every shot, read from its proxy: a sentence, the text on screen, shot size, people, light, themes
                  and tags, and everything said, in the language it was said. Kept in <code>_rushes/analysis</code>;
                  nothing in the archive is changed. It starts by itself when a folder's proxies are done.</p></div>
            </div>
            <p class="note" style="margin:12px 0 8px">Choose a folder, press Plan to see what it would take (nothing changes), then Prepare. Folders go one after another, in the
              order you add them; nothing is ever done twice, so preparing a folder again later only does what is new.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
              <button class="btn quiet" id="prepChoose" type="button">Choose in Finder…</button>
              <input id="prepPick" type="file" webkitdirectory hidden>
              <input id="prepPath" placeholder="or type it: a folder in the archive, e.g. PARK COLLECTION"
                     style="flex:1;min-width:260px;padding:8px 10px;font:13.5px var(--font);border:1px solid var(--line);
                            border-radius:var(--radius-sm);background:var(--bg);color:var(--fg)">
              <button class="btn quiet" data-px="proxy-plan" type="button">1 · Plan (changes nothing)</button>
              <button class="btn" id="prepGo" type="button">2 · Prepare this folder</button>
              <button class="btn quiet" data-px="proxy-stop" type="button">Stop proxies</button>
            </div>
            <p class="note" id="prepSaid" style="margin:10px 0 0"></p>
            <div id="pxState" class="note" style="margin-top:6px"></div>
            <div id="prepTable" style="margin-top:12px"></div>
          </div>
        </div>

        <!-- What the helper can describe with, and how far the archive has got. -->
        <div class="panel" style="margin-top:14px">
          <header><b>Describing</b></header>
          <div style="padding:14px">
            <div id="anTools" class="note"></div>
            <div class="tiles" id="anTiles" style="margin-top:12px"></div>
          </div>
        </div>

        <div class="panel" style="margin-top:14px">
          <header><b>Latest described</b> <span class="note">· to check the quality, shot by shot</span></header>
          <div id="anLatest" class="mgrid" style="padding:14px"><div class="note">Nothing described yet.</div></div>
        </div>
      </section>

      <section id="pane-tools" hidden>
        <div class="panel" style="margin-top:8px">
          <header><b>Run something by hand</b></header>
          <div style="padding:14px">
            <div class="btns" id="tools"></div>
            <div id="toolState" class="note" style="margin-top:10px"></div>
            <p class="note" style="margin:12px 0 0">
              These run whether or not anything above says you need them.</p>
          </div>
        </div>

        <div class="panel" style="margin-top:14px">
          <header><b>Admin password</b></header>
          <form method="post" style="padding:14px;max-width:360px">
            <?php if ($pw_said === 'ok'): ?>
              <div class="banner ok" style="margin-bottom:12px">
                <div class="txt">Changed. It applies to the next sign-in.</div></div>
            <?php elseif ($pw_said): ?>
              <div class="banner bad" style="margin-bottom:12px">
                <div class="txt"><?= htmlspecialchars($pw_said) ?></div></div>
            <?php endif; ?>
            <label class="note" for="op">Current</label>
            <input id="op" name="_oldpass" type="password" autocomplete="current-password"
                   style="width:100%;padding:8px 10px;margin:4px 0 12px;font:13.5px var(--font);
                          border:1px solid var(--line);border-radius:var(--radius-sm);
                          background:var(--bg);color:var(--fg)">
            <label class="note" for="np">New</label>
            <input id="np" name="_newpass" type="password" autocomplete="new-password"
                   style="width:100%;padding:8px 10px;margin:4px 0 12px;font:13.5px var(--font);
                          border:1px solid var(--line);border-radius:var(--radius-sm);
                          background:var(--bg);color:var(--fg)">
            <button class="btn" type="submit">Change it</button>
            <p class="note" style="margin:12px 0 0">One password for this archive, no accounts.
              It guards this page and anything that moves files. Search and Ingest stay open
              to anyone who can reach this address.</p>
          </form>
        </div>
      </section>
    </div>
  </main>

  <!-- The log lives beside the work, not behind a tab. Half the point of this
       page is being able to see that something is still moving. -->
  <aside class="side" aria-label="Recent activity">
    <div class="side-h">Activity
      <button class="ghost" id="sideMore" type="button">all</button></div>
    <div class="side-body" id="sideLog"><div class="empty">Nothing yet.</div></div>
    <div class="side-f" id="sideNow">&mdash;</div>
  </aside>
</div>
</div>

<script>
// A page that dies quietly looks exactly like a NAS that has stopped answering.
// Say which it is.
window.addEventListener('error', function (e) {
  var b = document.getElementById('err');
  if (!b) return;
  b.hidden = false;
  b.innerHTML = '<div class="txt"><b>This page has a bug</b>' +
    (e.message || 'script error') + (e.lineno ? ' (line ' + e.lineno + ')' : '') +
    ' — the archive itself is probably fine.</div>';
});

const $   = function (i) { return document.getElementById(i); };
const esc = function (s) { return (s || '').replace(/[&<>"]/g, function (c) {
  return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
const tb  = function (b) {
  return b >= 1099511627776 ? (b / 1099511627776).toFixed(2) + ' TB'
       : b >= 1073741824    ? (b / 1073741824).toFixed(1) + ' GB'
       : Math.round(b / 1048576) + ' MB';
};

let busy = null, ticked = new Set(), seeded = false, sig = '', secs = [], BIG = 1099511627776;
let pane = 'overview', latestTransfer = null, quiet = 0;

// ── moving between sections ────────────────────────────────────────────────
const TITLES = { overview: 'Overview', transfers: 'Transfers', cache: 'Cache',
                 duplicates: 'Duplicates', describe: 'Describe',
                 activity: 'Activity', tools: 'Jobs and tools' };

function show(which) {
  pane = which;
  $('title').textContent = TITLES[which] || 'Overview';
  document.querySelectorAll('.rail .nav').forEach(function (b) {
    if (b.dataset.go === which) b.setAttribute('aria-current', 'page');
    else b.removeAttribute('aria-current');
  });
  ['transfers', 'duplicates', 'cache', 'describe', 'activity', 'tools'].forEach(function (p) {
    $('pane-' + p).hidden = (p !== which);
  });
  // Overview shows the tiles and the cards; a section shows its own thing.
  $('tiles').hidden = (which !== 'overview');
  $('cards').hidden = (which !== 'overview');
  $('transferSummary').hidden = !latestTransfer || !['overview','transfers'].includes(which);
  if (which === 'cache') loadCache();
  try { history.replaceState(null, '', '#' + which); } catch (e) {}
}
document.querySelectorAll('.rail .nav[data-go]').forEach(function (b) {
  b.onclick = function (e) { e.preventDefault(); show(b.dataset.go); };
});
$('sideMore').onclick = function () { show('activity'); };
// The fixed buttons in Duplicates and Structure use the same path as everything
// else: confirm, ask, watch it happen in the side column.
document.querySelectorAll('#pane-duplicates [data-t]')
  .forEach(function (b) { b.onclick = function () { act(b.dataset.t, b); }; });

// ── asking for work ────────────────────────────────────────────────────────
const ASK = {
  plan:     'Look through the archive for files that are the same file. Moves nothing.',
  apply:    'Move every duplicate copy to the holding folder. Nothing is deleted, and this can be undone.',
  undo:     'Put everything in the holding folder back where it came from.',
  organize: 'Work out a tidier layout and show it to you. Moves nothing.',
  'organize-apply': 'Carry out the layout you were shown. Every move can be undone.',
  'organize-undo':  'Put the moved folders back where they were.',
  import:   'Rebuild search from the file list. About ten seconds.',
  'proxy-plan':  'Count the videos that have no proxy yet, and how much there is to read. Makes nothing.',
  'proxy-build': 'Make the missing proxies, in the background on the archive machine. It takes hours to days; stopping and starting again loses nothing.',
  'proxy-stop':  'Stop making proxies. The one being made is thrown away; everything finished is kept.',
  manifest: 'Write down every file and its size, then rebuild search. A few minutes.',
  verify:   'Check every file in the holding folder still has a twin in the archive. Moves nothing.',
  df:       'Measure free space.'
};

async function act(name, btn) {
  if (name === 'import') {
    // Same rebuild the scheduled runner does; the old search keeps working
    // until the new one is complete. Say how it went, on the button itself.
    const was = btn.textContent; btn.disabled = true; btn.textContent = 'Rebuilding search…';
    try {
      const r = await (await fetch('import.php')).json();
      btn.textContent = r.state === 'retrying' ? 'Kept the old search — ' + (r.error || 'try again')
                      : r.state === 'updating' ? 'Already rebuilding — give it a minute' : 'Search is up to date ✓';
    } catch (e) { btn.textContent = 'Could not reach the archive'; }
    setTimeout(function () { btn.textContent = was; btn.disabled = false; load(); }, 3000);
    return;
  }
  if (name === 'cachejunk') { return moveCache(btn); }
  if (name === 'scripts') {
    // Asked on the button itself, no pop-up: press again within 5 seconds.
    if (!btn.dataset.sure) {
      const was = btn.textContent; btn.dataset.sure = '1'; btn.textContent = 'Sure? ' + was;
      setTimeout(function () { if (btn.dataset.sure) { delete btn.dataset.sure; btn.textContent = was; } }, 5000);
      return;
    }
    delete btn.dataset.sure;
    btn.disabled = true; btn.textContent = 'Asking…';
    try {
      const r = await (await fetch('helper.php', { method: 'POST', body: new URLSearchParams({ action: 'scripts' }) })).json();
      btn.textContent = r.error ? 'Did not happen: ' + r.error : 'Queued ✓ installed within a minute';
    } catch (e) { btn.textContent = 'Could not reach the archive'; }
    setTimeout(load, 3000); return;
  }
  if (name === '#transfers'){ show('transfers'); return; }
  if (ASK[name] && !confirm(ASK[name])) return;
  const was = btn.textContent;
  btn.disabled = true; btn.textContent = 'asked…';
  try {
    const j = await (await fetch('../run.php', { method: 'POST',
      body: new URLSearchParams({ action: name }) })).json();
    if (j.error) { oops(j.error); btn.disabled = false; btn.textContent = was; return; }
    busy = name;
  } catch (e) {
    oops('could not reach the archive: ' + e.message);
    btn.disabled = false; btn.textContent = was;
  }
  setTimeout(load, 1200);
}

// ── cache ──────────────────────────────────────────────────────────────────
async function loadCache() {
  try {
    const d = await (await fetch('junk.php?json=1&t=' + Date.now())).json();
    const n = d.sweep.reduce(function (a, x) { return a + x.files; }, 0);
    const b = d.sweep.reduce(function (a, x) { return a + x.bytes; }, 0);
    $('cTotal').textContent = n.toLocaleString() + ' files · ' + tb(b);
    $('cRows').innerHTML = d.sweep.map(function (x) {
      return '<div class="row' + (x.files ? '' : ' dim') + '"><span class="nm">' + esc(x.label) + '</span>' +
        '<span class="n">' + x.files.toLocaleString() + '</span><span class="sz">' + tb(x.bytes) + '</span></div>';
    }).join('');
    $('cKeep').innerHTML = d.keep.map(function (x) {
      return '<div class="row"><span class="nm">' + esc(x.label) +
        (x.why ? '<small>' + esc(x.why) + '</small>' : '') + '</span>' +
        '<span class="n">' + x.files.toLocaleString() + '</span><span class="sz">' + tb(x.bytes) + '</span></div>';
    }).join('');
    $('cEx').textContent = d.examples.join('\n') || 'Nothing to move.';
    $('cMove').disabled = !n;
    $('nCache').textContent = n ? tb(b) : '';
  } catch (e) {
    $('cRows').innerHTML = '<div class="empty">Could not count the cache: ' + esc(e.message) + '</div>';
  }
}
$('cMove').onclick = function () { moveCache(this); };

// Two calls behind one button: work out which files are rebuildable scratch,
// then move them. They go to the holding folder, not the bin.
async function moveCache(btn) {
  if (!confirm('Move the cache files out of the archive?\n\n' +
      'They go to the holding folder, not the bin, and the space only comes back ' +
      'when you empty that folder. Editing software rebuilds these from the ' +
      'originals, so nothing is lost.')) return;
  const was = btn.textContent;
  btn.disabled = true; btn.textContent = 'listing them…';
  try {
    const r = await fetch('junk.php?write=1&t=' + Date.now());
    if (!r.ok) throw new Error('junk.php said ' + r.status);
    btn.textContent = 'asked…';
    const j = await (await fetch('../run.php', { method: 'POST',
      body: new URLSearchParams({ action: 'cacheclean' }) })).json();
    if (j.error) { oops(j.error); btn.disabled = false; btn.textContent = was; return; }
    busy = 'cacheclean';
  } catch (e) {
    oops('could not do that: ' + e.message);
    btn.disabled = false; btn.textContent = was;
  }
  setTimeout(load, 1200);
}

function oops(msg) {
  $('err').hidden = false;
  $('err').innerHTML = '<div class="txt">' + esc(msg) + '</div>';
}

// ── the transfer ───────────────────────────────────────────────────────────
// One box for the transfer: where it stands, what the helper is doing for it
// this second, and the helper's own row. The live box on top is only for work
// that is not part of the transfer — a card at Ingest, a tidy-up.

// The live status, when it is work on this transfer's folders; otherwise null.
function liveFor(j, c) {
  if (!j || j.phase === 'done' || !c || !j.source || !c.source) return null;
  if (!['copying', 'looking', 'tracing'].includes(c.phase)) return null;
  const h = helperNow(c), a = j.source, b = c.source;
  return h && h.busy && (a === b || a.startsWith(b + '/') || b.startsWith(a + '/')) ? h : null;
}
function clock(t) { return t ? new Date(t*1000).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'}) : ''; }

function drawTransfer(j, c, paused) {
  latestTransfer = j;
  const el = $('transferSummary'), hc = $('hctl');
  el.hidden = !j || !['overview', 'transfers'].includes(pane);
  if (!j) { el.before(hc); return; }                 // no transfer: the helper row stands on its own
  const labels = {queued:'Waiting for the helper', checking:'Checking selected files', copying:'Transferring',
    done:'Transfer complete', interrupted:'Transfer interrupted', blocked:'Waiting for the source drive', stopped:'Waiting for space',
    paused:'Transfer paused'};
  const pct = j.pct === null ? 'Preparing…' : j.pct + '%';
  let title = labels[j.phase] || 'Transfer';
  let say = j.phase === 'paused'
    ? 'Paused from Manage. Press Resume below and it carries on from where it stopped.'
    : j.phase === 'interrupted'
    ? 'Progress is saved. Check that the source and archive are connected and the helper is running. It will retry automatically.'
    : j.phase === 'blocked' ? 'Reconnect the source drive. The helper will continue automatically.'
    : j.phase === 'stopped' ? 'Make room on the archive. The helper will check again automatically.'
    : j.phase === 'queued' ? 'Your selection is saved. Start the helper to begin or continue.'
    : j.phase === 'done' ? 'All selected files are accounted for. Any pending search updates will catch up automatically.'
    : 'New files become searchable as the transfer progresses.';
  // The last report can be older than what the helper is doing now. The live
  // status wins; a real stop before it is told as history, with its time.
  const live = liveFor(j, c);
  if (live) {
    title = c.phase === 'tracing' ? 'Transfer getting ready' : c.phase === 'looking' ? 'Transfer checking a folder' : 'Transferring';
    say = c.phase === 'tracing'
      ? 'Before copying the rest, the helper matches footage copied earlier to its originals, so nothing is copied twice. ' +
        'It only reads. Copying carries on by itself when it is done.'
      : 'New files become searchable as the transfer progresses.';
    if (j.reported === 'interrupted' && j.updated) say = 'It stopped at ' + clock(j.updated) + ' and is back at it. ' + say;
    if (paused) {
      title = 'Transfer pausing';
      say = 'Paused from Manage. The step running now stops at its next safe point' +
        (c.phase === 'tracing' ? ' (it only reads, so nothing is half-done)' : ' — between files, never in the middle of one') +
        ', and nothing new starts until Resume.';
    }
  }
  hc.remove();                                        // kept across the redraw, put back at the bottom
  el.innerHTML = '<div class="job-top"><div><h2>' + esc(title) + '</h2>' +
    '<p class="note">Selected transfer · ' + j.folders + ' folders</p></div>' +
    '<strong class="job-percent">' + pct + (j.pct === null ? '' : ' <small>complete</small>') + '</strong></div>' +
    '<progress max="100"' + (j.pct === null ? '' : ' value="' + j.pct + '"') + ' aria-label="Overall transfer completion"></progress>' +
    '<p>' + tb(j.done_bytes) + ' of ' + tb(j.total_bytes) + ' accounted for · ' +
    tb(Math.max(0,j.total_bytes-j.done_bytes)) + ' remaining' + (j.estimated ? ' (estimated)' : '') + '</p>' +
    '<p class="note">' + tb(j.copied_bytes) + ' copied · ' + tb(j.already_bytes) + ' already present · ' +
    j.folders_done + ' of ' + j.folders + ' folders finished</p>' +
    (j.source ? '<p class="note">Current folder: ' + esc(j.source.split('/').pop()) + '</p>' : '') +
    (live ? '<div class="now">' + nowHTML(live, c) + '</div>' : '') +
    '<p>' + esc(say) + '</p>' +
    (j.updated && !live ? '<p class="note">Last report: ' + esc(new Date(j.updated*1000).toLocaleString()) + '</p>' : '');
  el.appendChild(hc);
}

function drawMove(d) {
  const landed = d.landed || [];
  $('nTransfers').textContent = secs.length ? secs.length : '';

  const s = JSON.stringify([secs, landed]);
  if (s === sig) return;                 // no redraw, so your ticks survive
  sig = s;

  $('from').textContent = (d.route && d.route.from) || 'the source';
  $('to').textContent   = (d.route && d.route.to)   || 'the archive';

  $('todo').innerHTML = secs.length ? secs.map(function (x) {
    return '<div class="row pick' + (ticked.has(x.path) ? ' on' : '') +
      '" data-p="' + esc(x.path) + '">' +
      '<span class="tick">✓</span>' +
      '<span class="nm">' + esc(x.name) + '<small>' + esc(x.where) + '</small></span>' +
      (x.state === 'splitting' ? '<span class="pill wait">splitting…</span>'
        : x.state === 'queued' ? '<span class="pill">queued</span>' : '') +
      (x.bytes >= BIG && x.state !== 'splitting'
        ? '<button class="ghost" data-s="' + esc(x.path) + '">split</button>' : '') +
      '<span class="n">' + x.files.toLocaleString() + '</span>' +
      '<span class="sz">' + tb(x.bytes) + '</span></div>';
  }).join('') : '<div class="empty">Nothing left to bring over.</div>';

  $('landed').innerHTML = landed.length ? landed.map(function (x) {
    return '<div class="row"><span class="nm">' + esc(x.name) + '</span>' +
      '<span class="n">' + x.files.toLocaleString() + '</span>' +
      '<span class="sz">' + tb(x.bytes) + '</span>' +
      '<span class="when">' + esc(String(x.when).slice(5, 16)) + '</span></div>';
  }).join('') : '<div class="empty">Nothing has landed yet.</div>';

  $('leftSum').textContent  = secs.length
    ? secs.length + ' folders · ' + tb(secs.reduce(function (n, x) { return n + x.bytes; }, 0)) : '';
  $('rightSum').textContent = landed.length
    ? landed.length + ' folders · ' + tb(landed.reduce(function (n, x) { return n + x.bytes; }, 0)) : '';

  $('todo').querySelectorAll('.row').forEach(function (r) {
    r.onclick = function (e) {
      if (e.target.dataset.s) return;
      const p = r.dataset.p;
      ticked.has(p) ? ticked.delete(p) : ticked.add(p);
      r.classList.toggle('on');
      count();
    };
  });
  $('todo').querySelectorAll('[data-s]').forEach(function (b) {
    b.onclick = async function (e) {
      e.stopPropagation();
      if (!confirm('Break this folder into its subfolders, so you can do it in pieces?\n\n' +
        'It gets measured, which takes a few minutes for something this size, ' +
        'and copies nothing.')) return;
      ticked.delete(b.dataset.s);      // a tick on a folder being split means nothing
      b.textContent = 'splitting…';
      await sendQueue([...ticked], b.dataset.s, b, 'split');
    };
  });
  count();
}

function count() {
  const b   = secs.filter(function (x) { return ticked.has(x.path); })
                  .reduce(function (n, x) { return n + x.bytes; }, 0);
  const off = secs.filter(function (x) { return x.state === 'queued' && !ticked.has(x.path); }).length;
  $('goCopy').disabled = !ticked.size && !off;
  $('goCopy').textContent = ticked.size ? 'Copy the ' + ticked.size + ' ticked'
                          : (off ? 'Clear the list' : 'Copy the ticked ones');
  $('selnote').textContent = ticked.size
    ? tb(b) + ' selected — less whatever is already here.'
    : 'Nothing ticked.';
}

$('allTodo').onclick = function () {
  secs.forEach(function (x) { ticked.add(x.path); });
  sig = ''; load();
};

$('goCopy').onclick = async function () {
  const paths   = [...ticked];
  const dropped = secs.filter(function (x) { return x.state === 'queued' && !ticked.has(x.path); }).length;
  if (!paths.length && !dropped) return;
  if (!confirm('Hand over ' + paths.length + ' folder(s)?' +
      (dropped ? '\n\n' + dropped + ' that were queued are unticked, so they come off the list. ' +
                 'Anything already copied stays copied.' : '') +
      '\n\nThey run one after another, in this order. Every file is checked first — ' +
      'anything already in the archive is skipped.')) return;
  await sendQueue(paths, '', $('goCopy'), 'Copy the ticked ones');
};

// What is ticked IS the want: press Copy and the list becomes exactly that,
// which is what makes taking something off the list possible at all.
async function sendQueue(paths, list, btn, was) {
  btn.disabled = true; btn.textContent = 'sending…';
  const body = new URLSearchParams();
  paths.forEach(function (p) { body.append('copy[]', p); });
  if (list) body.append('list', list);
  try {
    const j = await (await fetch('/queue.php', { method: 'POST', body })).json();
    if (j.error) { oops(j.error); btn.textContent = was; btn.disabled = false; return; }
    btn.textContent = 'sent ✓';
  } catch (e) {
    oops('could not reach the archive: ' + e.message);
    btn.textContent = was; btn.disabled = false; return;
  }
  sig = '';
  setTimeout(function () { btn.textContent = was; btn.disabled = false; load(); }, 1400);
}

// ── tiles: two that are always true, then only what is ─────────────────────
function drawTiles(d) {
  const t = [];
  t.push('<div class="tile"><div class="lab">In the archive</div>' +
    '<div class="big">' + d.archive.files.toLocaleString() + '</div>' +
    '<div class="sub">' + tb(d.archive.bytes) + '</div></div>');

  const pct  = d.disk.pct || 0;
  const tone = pct >= 90 ? ' bad' : pct >= 80 ? ' warn' : '';
  t.push('<div class="tile' + tone + '"><div class="lab">Free space</div>' +
    '<div class="big">' + tb(d.disk.free) + '</div>' +
    '<div class="sub">' + pct + '% used</div></div>');

  // Everything past here is conditional. Someone with a tidy archive and nothing
  // to bring over sees two tiles and no buttons, which is the correct screen.
  (d.conditions || []).forEach(function (c, i) {
    if (!c.tile) return;
    const cls = c.level === 'bad' ? ' bad' : c.level === 'warn' ? ' warn' : ' act';
    t.push('<button class="tile' + cls + '" data-c="' + i + '">' +
      '<div class="lab">' + esc(c.tile.lab) + '</div>' +
      '<div class="big">' + esc(c.tile.big) + '</div>' +
      '<div class="sub">' + esc(c.tile.sub) + '</div>' +
      (c.act ? '<div class="go">' + esc(c.act[1]) + ' →</div>' : '') + '</button>');
  });

  $('tiles').innerHTML = t.join('');
  $('tiles').querySelectorAll('[data-c]').forEach(function (b) {
    b.onclick = function () {
      const c = d.conditions[+b.dataset.c];
      if (c && c.act) act(c.act[0], b);
    };
  });
}

// ── live: what the helper is doing, with its numbers ─────────────────────────
function nowHTML(h, c) {
  const secs = c.secs == null ? '' : c.secs < 10 ? 'live' : 'updated ' + c.secs + ' s ago';
  return '<div class="now-h"><span class="dot busy"></span><b>' + esc(h.title) + '</b><span>' + esc(secs) + '</span></div>' +
    (h.pct != null ? '<div class="bar"><i style="width:' + h.pct + '%"></i><em>' + h.pct + '%</em></div>' : '') +
    '<div class="facts">' + (h.facts || []).map(function (f) {
      return '<div><b>' + esc(f[0]) + '</b><span>' + esc(f[1]) + '</span></div>'; }).join('') + '</div>' +
    (h.file ? '<div class="file">now: ' + esc(h.file) + '</div>' : '');
}
function drawNow(d) {
  // Work on the transfer is shown inside the transfer box instead.
  const h = pane === 'overview' && !liveFor(d.transfer, d.copy) ? helperNow(d.copy) : null;
  $('now').hidden = !h || h.bad;
  if (!h || h.bad) return;
  $('now').innerHTML = nowHTML(h, d.copy);
}

// ── the helper and its buttons ─────────────────────────────────────────────
// Every button asks once more on the button itself ("Sure?"), then says what
// happened. The answer is read back from the helper, never assumed.
let armed = { what: '', until: 0 }, said = { text: '', until: 0 };
function drawHelper(d) {
  const h = d.helper || {}, el = $('hctl');
  el.hidden = !['overview', 'transfers'].includes(pane) || !h.label;   // Pause wherever the transfer shows
  if (el.hidden) return;
  const how = h.how === 'service' ? 'runs in the background' : h.how === 'window' ? 'runs in a Terminal window' : '';
  const seen = h.seen ? (h.fresh ? 'seen ' + h.seen_ago : 'not heard from since ' + h.seen_ago) : 'not started yet';
  const updating = h.fresh && h.ver && h.current && h.ver !== h.current ? ' · updating itself to the new version' : '';
  const cur = d.transfer && d.transfer.phase !== 'done' ? d.transfer.source : '';
  // Something is wrong only when a folder stopped and the helper has not been
  // back on it for three minutes (a retry is normally seconds away).
  const stuck = cur && h.fresh && !h.paused && d.transfer.phase === 'interrupted' && !liveFor(d.transfer, d.copy)
    && Date.now() / 1000 - d.transfer.updated > 180;
  const btns = [];
  if (h.fresh) btns.push(h.paused ? ['resume', 'Resume'] : ['pause', 'Pause']);
  btns.push(['nudge', 'Try again now']);
  btns.push(h.no_reconnect ? ['reconnect-on', 'Reconnect shares by itself'] : ['reconnect-off', 'Stop reconnecting shares']);
  const now = Date.now();
  el.innerHTML = '<span class="dot ' + (h.fresh ? (h.paused ? '' : 'ok') : 'off') + '"></span>' +
    '<span class="t"><b>Helper on ' + esc(h.label) + '</b> · ' + esc([how, seen].filter(Boolean).join(' · ')) +
    esc(updating) + (h.paused ? ' · <b>paused</b>' : '') + (h.no_reconnect ? ' · <b>not reconnecting shares</b>' : '') + '</span>' +
    btns.map(function (b) {
      const sure = armed.what === b[0] && now < armed.until;
      return '<button class="btn quiet" data-h="' + b[0] + '">' + esc(sure ? 'Sure? ' + b[1] : b[1]) + '</button>'; }).join('') +
    (stuck ? '<div class="alarm"><p><b>' + esc(cur.split('/').pop()) + ' stopped at ' + esc(clock(d.transfer.updated)) +
      ' and has not started again.</b> The helper keeps retrying by itself. If it keeps stopping — a file it cannot read, ' +
      'a source that drops — skip this folder for now: nothing already copied is lost, and ticking it again in Transfers brings it back.</p>' +
      (function () { const sure = armed.what === 'skip' && now < armed.until, n = 'Skip ' + cur.split('/').pop();
        return '<button class="btn quiet" data-h="skip">' + esc(sure ? 'Sure? ' + n : n) + '</button>'; })() + '</div>' : '') +
    (now < said.until ? '<p class="note">' + esc(said.text) + '</p>' : '') +
    (!h.fresh && h.how !== 'service' && h.mode === 'external'
      ? '<p class="note">Start it from Setup → 04 Helper, or install it there once so it starts by itself.</p>' : '');
  el.querySelectorAll('[data-h]').forEach(function (b) {
    b.onclick = async function () {
      const what = b.dataset.h;
      if (armed.what !== what || Date.now() > armed.until) { armed = { what: what, until: Date.now() + 5000 }; drawHelper(d); return; }
      armed = { what: '', until: 0 }; b.disabled = true; b.textContent = 'Asking…';
      const body = new URLSearchParams({ action: what }); if (what === 'skip') body.set('path', cur);
      try {
        const r = await (await fetch('helper.php', { method: 'POST', body: body })).json();
        said = { until: Date.now() + 8000, text: r.error ? 'Did not happen: ' + r.error
          : { pause: 'Paused ✓ What is running stops at its next safe point; nothing new starts until Resume.',
              resume: 'Resumed ✓ It carries on within a few seconds.',
              nudge: 'Asked ✓ It stops waiting and looks again now.',
              skip: 'Skipped ✓ That folder is out of this transfer. Tick it again in Transfers to bring it back.',
              'reconnect-off': 'Off ✓ The helper no longer connects dropped shares by itself (no more "problem connecting" windows). Connect them in Finder; copying carries on once they are back.',
              'reconnect-on': 'On ✓ The helper connects dropped shares again by itself, only when the server answers.' }[what] };
      } catch (e) { said = { until: Date.now() + 8000, text: 'Could not reach the archive: ' + e.message }; }
      load();
    };
  });
}

// ── what the page shows ────────────────────────────────────────────────────
async function load() {
  let d;
  try { d = await (await fetch('state.php?t=' + Date.now())).json(); }
  catch (e) { oops('The archive is not answering.'); return; }
  if (d.error) { oops(d.error); return; }
  $('err').hidden = true;

  secs = (d.sections || []).filter(x => x.state !== 'done');
  ticked = new Set([...ticked].filter(p => secs.some(x => x.path === p)));
  drawTransfer(d.transfer, d.copy, (d.helper || {}).paused);
  if (!seeded) {                        // the tick starts as whatever is queued
    secs.forEach(function (x) { if (x.state === 'queued') ticked.add(x.path); });
    seeded = true;
  }

  $('built').textContent = d.search && d.search.state !== 'current' ? 'Search updating automatically' : 'Search updated ' + (d.archive.imported_ago || 'never');
  $('fFiles').textContent = d.archive.files.toLocaleString() + ' files · ' + tb(d.archive.bytes);
  $('fFree').textContent  = tb(d.disk.free) + ' free · ' + (d.disk.pct || 0) + '% used';
  const m = $('fMeter');
  m.style.width = (d.disk.pct || 0) + '%';
  m.className = (d.disk.pct >= 90 ? 'full' : d.disk.pct >= 80 ? 'hot' : '');

  const bad = (d.conditions || []).filter(function (c) { return c.level === 'bad'; }).length;
  $('nOverview').hidden = !bad; $('nOverview').textContent = bad || '';

  drawTiles(d);
  drawNow(d);
  drawHelper(d);
  drawDescribeTools(d.helper);
  drawProxies(d.proxies);

  // Cards carry the detail the tiles cannot. Only what is true, in order.
  $('cards').innerHTML = (d.conditions || []).map(function (c, i) {
    return '<div class="banner ' + esc(c.level === 'good' ? 'ok' : c.level) + '">' +
      '<div class="txt"><b>' + esc(c.title) + '</b>' + esc(c.body) +
      (c.help ? '<div class="code block">' + esc(c.help) + '</div>' : '') + '</div>' +
      (c.act ? '<button class="btn" data-a="' + i + '">' + esc(c.act[1]) + '</button>' : '') +
      '</div>';
  }).join('');
  $('cards').querySelectorAll('[data-a]').forEach(function (b) {
    b.onclick = function () { act(d.conditions[+b.dataset.a].act[0], b); };
  });

  // Activity: what happened, as events. Same markup in the pane and in the
  // side column, so the two can never tell different stories.
  const evs = (d.recent || []).map(function (r) {
    const drop = r.what === 'dropped';          // a share that went away, and came back
    return '<div class="ev"><span class="ico">' + (r.what === 'interrupted' ? 'Ⅱ' : drop ? '↯' : '✓') + '</span><div class="t">' +
      esc(r.target) + ' ' + esc(r.what) +
      '<small>' + (drop ? esc(r.note) + ' after ' + Math.max(1, Math.round(r.secs / 60)) + ' min'
        : r.files.toLocaleString() + ' files · ' + tb(r.bytes) + (r.secs ? ' · ' + Math.round(r.secs / 60) + ' min' : '')) +
      '</small></div>' +
      '<span class="when">' + esc(String(r.when).slice(5, 16)) + '</span></div>';
  });
  $('events').innerHTML = evs.length ? evs.join('') : '<div class="empty">Nothing yet.</div>';
  $('sideLog').innerHTML = (d.running
      ? '<div class="ev"><span class="ico">•</span><div class="t">' + esc(d.running) +
        '<small>running now' + (d.progress ? ' · ' + d.progress.pct + '%' : '') + '</small>' +
        '<div class="bar' + (d.progress ? '' : ' wait') + '"><i style="width:' +
        ((d.progress && d.progress.pct) || 0) + '%"></i></div></div></div>'
      : '') + (evs.length ? evs.slice(0, 12).join('') : '<div class="empty">Nothing yet.</div>');
  $('sideNow').textContent = d.runner && d.runner.ok
    ? 'Picking up jobs · checked ' + (d.runner.ago || 'just now')
    : 'Not picking up jobs';
  const lg = $('log'), stuck = lg.scrollTop + lg.clientHeight >= lg.scrollHeight - 30;
  lg.textContent = d.log || 'nothing logged yet';
  if (stuck) lg.scrollTop = lg.scrollHeight;
  // Opened: start at the newest, which is at the bottom.
  lg.parentElement.ontoggle = function () { if (this.open) lg.scrollTop = lg.scrollHeight; };

  $('queuenote').textContent = (function () {
    const w = secs.filter(function (x) { return x.state === 'queued' || x.state === 'splitting'; }).length;
    return w ? w + ' on the list. One at a time, in this order — anything you add waits.' : '';
  })();

  const h = d.helper || {};
  // Only when something is wrong: the helper runs by itself now, so a start
  // command here was a leftover. If it has gone quiet, say so and where to fix it.
  quiet = h.label && !h.fresh ? quiet + 1 : 0;        // twice in a row: never a blip while loading
  $('watchhint').innerHTML = quiet >= 2
    ? '<div class="warnline">The helper on <b>' + esc(h.label) + '</b> is not running, so nothing copies. ' +
      '<a href="/setup.php">Setup → 04 Helper</a> shows how to install or start it.</div>'
    : '';

  drawMove(d);

  $('tools').innerHTML = [['manifest', 'Rebuild the file list'], ['import', 'Rebuild search'],
    ['verify', 'Check the holding folder'], ['df', 'Measure free space'], ['proxy-plan', 'Plan proxies (changes nothing)'],
    ['gpu-test', 'Test the video chip (changes nothing, about a minute)']]
    .map(function (a) { return '<button class="btn quiet" data-t="' + a[0] + '">' + a[1] + '</button>'; })
    .join('');
  $('tools').querySelectorAll('[data-t]').forEach(function (b) {
    b.onclick = function () { act(b.dataset.t, b); };
  });

  // Every tool says where it is: asked, running, finished — and the video
  // chip test shows its result right here.
  const TOOL = {manifest: 'Rebuild the file list', import: 'Rebuild search', verify: 'Check the holding folder', df: 'Measure free space',
    'proxy-plan': 'Plan proxies', 'proxy-build': 'Make proxies', 'gpu-test': 'Test the video chip', scan: 'Find duplicates'};
  const tq = d.queued || [];
  $('toolState').innerHTML =
    (d.running ? '<p><span class="spin"></span>Running: <b>' + esc(TOOL[d.running] || d.running) + '</b>' +
        (d.progress ? ' · ' + d.progress.pct + '%' : '') + ' — each step shows in the raw log (Overview)</p>' : '') +
    (tq.length ? '<p><span class="spin"></span>Asked: <b>' + tq.map(function (x) { return esc(TOOL[x] || x); }).join(', ') +
        '</b> — the archive machine starts it at its next turn, within a minute</p>' : '') +
    (d.gpu_test ? '<div style="margin-top:10px;padding:10px 12px;border:1px solid var(--line);border-radius:8px">' +
        '<b>Video chip test</b> · ' + (d.running === 'gpu-test' ? 'running now' : 'finished ' + esc(clock(d.gpu_test.at))) +
        '<pre style="white-space:pre-wrap;margin:8px 0 0;font:12px/1.5 ui-monospace,Menlo,monospace">' + esc(d.gpu_test.text) + '</pre></div>' : '');
  if (busy && !d.running && !tq.length) busy = null;
}

// ── describing footage ─────────────────────────────────────────────────────
// Asks, and says what happened; the helper does the work and the live box shows it.
let anArmed = 0;
function tcode(v) { v = Math.floor(v || 0); return Math.floor(v / 60) + ':' + String(v % 60).padStart(2, '0'); }
function drawProxies(p) {
  const el = $('pxState');
  if (!p) { el.textContent = 'Not planned yet. Press Plan to see how many videos need one.'; return; }
  const n = function (x) { return (+x || 0).toLocaleString(); };
  const size = function (gb) { gb = +gb || 0; return gb >= 1000 ? (gb / 1024).toFixed(1) + ' TB' : gb + ' GB'; };
  const when = p.ago == null ? '' : ' <span class="note">(' + (p.ago < 90 ? 'just now' : Math.round(p.ago / 60) + ' min ago') + ')</span>';
  // Asked, not started: the runner looks at its list once a minute.
  if (p.asked) {
    el.innerHTML = '<span class="spin"></span> <b>' + (p.asked.what === 'proxy-plan' ? 'Plan' : 'Make proxies') + '</b>' +
      (p.asked.only ? ' for ' + esc(p.asked.only) : ' for the whole archive') + ' · asked ' + p.asked.ago + ' s ago; the archive machine starts it at its next turn (within a minute).';
    return;
  }
  el.innerHTML = p.state === 'planning'
      ? '<span class="spin"></span> <b>Planning' + (p.only ? ' ' + esc(p.only) : ' the whole archive') + '</b> · ' + esc(p.step || '') +
        (p.videos ? ' · ' + n(p.seen) + ' of ' + n(p.videos) + ' videos' : '')
    : p.state === 'no-folder'
      ? '<span class="warnline" style="display:block">The archive machine found no folder “' + esc(p.only) + '”.</span>'
    : p.state === 'no-ffmpeg'
      ? '<span class="warnline" style="display:block">This machine has no ffmpeg, the tool that makes video, so it cannot make proxies yet.</span>'
    : p.state === 'planned'
      ? (p.only ? '<b>' + esc(p.only) + '</b>: ' : 'Whole archive: ') +
        '<b>' + n(p.videos) + ' videos</b> · ' + n(p.have) + ' already have a proxy · <b>' + n(p.missing) + ' to make</b>' +
        (+p.missing ? ', about ' + size(p.source_gb) + ' to read' : '') + ' · ' +
        (p.hw === '1' ? 'with the hardware encoder (QuickSync), fast' : 'in software, which is slow') + when +
        (p.hw !== '1' && p.hw_why ? '<br><span class="note">Why not the video chip: ' + esc(p.hw_why) + '.' +
          (p.cpu ? ' Processor: ' + esc(p.cpu) + '.' : '') + '</span>' : '')
    : p.state === 'building' && p.running
      ? '<b>Making proxies</b>' + (p.only ? ' for ' + esc(p.only) : '') + ' · ' + n(p.done) + ' of ' + n(p.total) + ' · ' + n(p.ok) + ' made · ' + n(p.failed) + ' failed · ' +
        n(p.later) + ' left for later (still arriving)<br>now: ' + esc(p.file || '')
    : p.state === 'building'
      ? '<span class="warnline" style="display:block">The proxy build stopped without finishing (the machine restarted?) at ' +
        n(p.done) + ' of ' + n(p.total) + '. Press Make proxies to carry on; finished ones are kept.</span>'
    : p.state === 'stopped'
      ? 'Stopped from Manage at ' + n(p.done) + ' of ' + n(p.total) + ' · ' + n(p.ok) + ' made. Make proxies carries on from there.'
    : p.state === 'done'
      ? '✓ Finished · ' + n(p.ok) + ' made · ' + n(p.failed) + ' failed' + (+p.later ? ' · ' + n(p.later) + ' were still arriving: press Make proxies again later for those' : '')
    : esc(p.state || '');
}
// Proxy buttons: the same runner jobs, for the folder typed above (or all of it).
document.querySelectorAll('[data-px]').forEach(function (b) {
  b.onclick = async function () {
    const what = b.dataset.px, folder = what === 'proxy-stop' ? '' : $('prepPath').value.trim().replace(/\/+$/, '');
    if (what === 'proxy-plan' && !folder) { $('prepSaid').textContent = 'Choose a folder first — Plan looks at one folder.'; return; }
    if (what === 'proxy-stop' && !b.dataset.sure) {
      const w = b.textContent; b.dataset.sure = '1'; b.textContent = 'Sure? Stop';
      setTimeout(function () { if (b.dataset.sure) { delete b.dataset.sure; b.textContent = w; } }, 5000);
      return;
    }
    delete b.dataset.sure;
    const was = what === 'proxy-stop' ? 'Stop proxies' : b.textContent; b.disabled = true; b.textContent = 'Asking…';
    try {
      const j = await (await fetch('../run.php', { method: 'POST',
        body: new URLSearchParams({ action: what, query: folder }) })).json();
      b.textContent = j.error ? 'Did not happen: ' + j.error : 'Asked ✓';
      if (!j.error) $('pxState').innerHTML = '<span class="spin"></span> Asked — the archive machine starts it within a minute.';
    } catch (e) { b.textContent = 'Could not reach the archive'; }
    setTimeout(function () { b.textContent = was; b.disabled = false; load(); }, 3000);
  };
});
function drawDescribeTools(h) {
  const an = (h && h.analysis) || {};
  $('anTools').innerHTML = !h || !h.label ? 'No helper is set up yet (Setup → 04 Helper).'
    : !an.model ? 'The helper on <b>' + esc(h.label) + '</b> has not said yet whether it can describe footage.'
    : an.ready ? '✓ The helper on <b>' + esc(h.label) + '</b> can describe footage · vision model <b>' + esc(an.model) +
                 '</b> · speech <b>' + esc(an.speech ? an.speech.split('/').pop() : 'off') + '</b>'
    : '<span class="warnline" style="display:block">The helper on <b>' + esc(h.label) + '</b> does not have the analysis ' +
      'tools installed, so nothing can be described there yet.</span>';
}
async function loadAnalysis() {
  try {
    const a = await (await fetch('analyze.php?t=' + Date.now())).json();
    const st = a.stats || {};
    $('anTiles').innerHTML = [[st.files, 'files described'], [st.shots, 'shots'], [st.speech, 'lines of speech'],
                              [st.failed, 'shots it could not read']].map(function (t) {
      return '<div class="tile"><div class="big">' + (t[0] || 0).toLocaleString() + '</div><div class="sub">' + t[1] + '</div></div>'; }).join('');
    $('anLatest').innerHTML = (a.latest || []).length ? a.latest.map(function (m) {
      const speech = m.kind === 'speech';
      const ons = (m.on_screen ? m.on_screen.split(' · ').map(function (t) { return '<span class="txt">' + esc(t) + '</span>'; }) : [])
        .concat(m.themes ? m.themes.split(' · ').map(function (t) { return '<span>' + esc(t) + '</span>'; }) : []);
      return '<div class="mo">' + (speech ? '<div class="said">“ ”</div>'
          : '<img loading="lazy" alt="" src="thumb.php?fp=' + encodeURIComponent(m.fp) + '&shot=' + m.shot + '">') +
        '<div class="b"><div class="tc">' + tcode(m.start_s) + ' → ' + tcode(m.end_s) +
        (speech ? ' · said' + (m.language ? ' (' + esc(m.language) + ')' : '') : ' · ' + esc(m.shot_size || '') + (m.people ? ' · people: ' + esc(m.people) : '') +
        (m.light ? ' · ' + esc(m.light) : '')) + '</div>' +
        (speech ? '“' + esc(m.what) + '”' : esc(m.what)) +
        (ons.length ? '<div class="ons">' + ons.join('') + '</div>' : '') +
        (m.tags ? '<small>' + esc(m.tags) + '</small>' : '') +
        '<small title="' + esc(m.path) + '">' + esc(m.path.split('/').pop()) + '</small></div></div>';
    }).join('') : '<div class="note">Nothing described yet.</div>';
    drawPrepare(a.table);
  } catch (e) { $('prepTable').textContent = 'Could not read the list: ' + e.message; }
}
// Choose in Finder: open the archive share there and pick the folder. The browser
// hands over the names inside it, never where it is, so Rushes looks those names
// up in the archive to know which folder it is. Nothing is uploaded.
$('prepChoose').onclick = function () { $('prepPick').value = ''; $('prepPick').click(); };
$('prepPick').onchange = async function () {
  const files = Array.from(this.files || []).filter(function (f) { return !/(^|\/)[._]/.test(f.webkitRelativePath); });
  const said = $('prepSaid');
  if (!files.length) { said.textContent = 'That folder has no footage in it that Rushes could see. Pick another.'; return; }
  const top = files[0].webkitRelativePath.split('/')[0];
  said.textContent = 'Finding “' + top + '” in the archive…';
  const q = new URLSearchParams();
  files.slice(0, 5).forEach(function (f) { q.append('locate[]', f.webkitRelativePath); });
  try {
    const r = await (await fetch('analyze.php?' + q)).json();
    const f = r.folders || [];
    if (f.length === 1) { $('prepPath').value = f[0]; said.textContent = 'Found it ✓ ' + f[0] + ' — now Plan, then Prepare.'; }
    else if (f.length > 1) {
      said.innerHTML = 'There are ' + f.length + ' folders like that in the archive — which one? ' +
        f.map(function (p) { return '<button class="btn quiet" data-pick="' + esc(p) + '" type="button">' + esc(p) + '</button>'; }).join(' ');
      said.querySelectorAll('[data-pick]').forEach(function (b) {
        b.onclick = function () { $('prepPath').value = b.dataset.pick; said.textContent = 'Picked ✓ ' + b.dataset.pick + ' — now Plan, then Prepare.'; };
      });
    } else said.textContent = '“' + top + '” is not in the archive (or search has not seen it yet). Pick it from the archive share in Finder.';
  } catch (e) { said.textContent = 'Could not reach the archive: ' + e.message; }
};

// Prepare: one confirmation on the button itself, then say what happened.
let prepArmed = 0;
$('prepGo').onclick = async function () {
  const path = $('prepPath').value.trim().replace(/\/+$/, ''), b = this;
  if (!path) { $('prepSaid').textContent = 'Type or pick a folder first.'; return; }
  if (Date.now() > prepArmed) { prepArmed = Date.now() + 5000; b.textContent = 'Sure? Prepare ' + path.split('/').pop(); return; }
  prepArmed = 0; b.disabled = true; b.textContent = 'Asking…';
  try {
    const r = await (await fetch('analyze.php', { method: 'POST', body: new URLSearchParams({ action: 'prepare', path: path }) })).json();
    $('prepSaid').textContent = r.error ? 'Did not happen: ' + r.error
      : 'Added ✓ ' + path + ' — its proxies start within a minute if nothing else is being made, then it is described.';
  } catch (e) { $('prepSaid').textContent = 'Could not reach the archive: ' + e.message; }
  b.disabled = false; b.textContent = '2 · Prepare this folder'; loadAnalysis();
};
async function forgetFolder(folder, b) {
  if (!confirm('Take ' + folder + ' off this list? Nothing is deleted: its proxies and descriptions stay.')) return;
  b.disabled = true;
  try { await fetch('analyze.php', { method: 'POST', body: new URLSearchParams({ action: 'forget', path: folder }) }); } catch (e) {}
  loadAnalysis();
}
function drawPrepare(rows) {
  const n = function (x) { return (+x || 0).toLocaleString(); };
  if (!rows || !rows.length) { $('prepTable').innerHTML = '<div class="note">No folder is being prepared yet.</div>'; return; }
  const px = function (p) {
    return p.step === 'making' ? '<span class="busy">making · ' + n(p.done) + ' of ' + n(p.total) + '</span>'
      : p.step === 'done' ? '<span class="ok">✓ ' + n(p.ok) + ' made</span>' + (+p.failed ? ' · <span class="bad">' + n(p.failed) + ' failed</span>' : '') +
                            (+p.later ? ' · ' + n(p.later) + ' still arriving, later' : '')
      : p.step === 'again' ? 'making the ones that were still arriving'
      : p.step === 'stopped' ? '<span class="bad">stopped</span> · ' + n(p.ok) + ' made · press Prepare to carry on'
      : p.step === 'no-ffmpeg' ? '<span class="bad">this machine cannot make video yet (no ffmpeg)</span>'
      : '<span class="dim">waiting its turn</span>';
  };
  const ds = function (d) {
    return d.step === 'describing' ? '<span class="busy">describing · ' + n(d.n) + ' of ' + n(d.of) + '</span>'
      : d.step === 'done' ? '<span class="ok">✓ ' + n(d.files) + ' files</span>' + (d.note && d.note.indexOf('could not') > -1 ? ' · <span class="bad">' + esc(d.note.replace(/asked=\d+;? ?/, '')) + '</span>' : '')
      : d.step === 'queued' ? 'queued on the helper, after any copies'
      : d.step === 'next' ? 'starting'
      : '<span class="dim">waiting for proxies</span>';
  };
  $('prepTable').innerHTML = '<table class="prep"><tr><th>Folder</th><th>1 · Proxies</th><th>2 · Descriptions</th><th></th></tr>' +
    rows.map(function (r) {
      return '<tr><td><b>' + esc(r.folder) + '</b></td><td>' + px(r.proxies) + '</td><td>' + ds(r.describe) + '</td>' +
        '<td style="text-align:right"><button class="btn quiet" data-forget="' + esc(r.folder) + '" style="padding:3px 9px;font-size:12px">Take off the list</button></td></tr>';
    }).join('') + '</table>';
  $('prepTable').querySelectorAll('[data-forget]').forEach(function (b) { b.onclick = function () { forgetFolder(b.dataset.forget, b); }; });
}
loadAnalysis(); setInterval(loadAnalysis, 10000);

show((location.hash || '#overview').slice(1));
load(); setInterval(load, 4000);
</script>
