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
  .side .ev { padding: 9px 14px }
  @media (max-width: 1200px) { .with-side { grid-template-columns: var(--rail-w) 1fr }
                               .side { display: none } }
  @media (max-width: 900px)  { .with-side { grid-template-columns: 1fr } }
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
          <summary class="note" style="cursor:pointer">Show the raw log</summary>
          <pre id="log" class="code block" style="max-height:420px;overflow:auto;
               white-space:pre-wrap;margin-top:10px">&nbsp;</pre>
        </details>
      </section>

      <!-- ══ jobs and tools ══ -->
      <section id="pane-tools" hidden>
        <div class="panel" style="margin-top:8px">
          <header><b>Run something by hand</b></header>
          <div style="padding:14px">
            <div class="btns" id="tools"></div>
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
let pane = 'overview';

// ── moving between sections ────────────────────────────────────────────────
const TITLES = { overview: 'Overview', transfers: 'Transfers', cache: 'Cache',
                 duplicates: 'Duplicates',
                 activity: 'Activity', tools: 'Jobs and tools' };

function show(which) {
  pane = which;
  $('title').textContent = TITLES[which] || 'Overview';
  document.querySelectorAll('.rail .nav').forEach(function (b) {
    if (b.dataset.go === which) b.setAttribute('aria-current', 'page');
    else b.removeAttribute('aria-current');
  });
  ['transfers', 'duplicates', 'cache', 'activity', 'tools'].forEach(function (p) {
    $('pane-' + p).hidden = (p !== which);
  });
  // Overview shows the tiles and the cards; a section shows its own thing.
  $('tiles').hidden = (which !== 'overview');
  $('cards').hidden = (which !== 'overview');
  if (which === 'cache') loadCache();
  try { history.replaceState(null, '', '#' + which); } catch (e) {}
}
document.querySelectorAll('.rail .nav[data-go]').forEach(function (b) {
  b.onclick = function () { show(b.dataset.go); };
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
  manifest: 'Write down every file and its size, then rebuild search. A few minutes.',
  verify:   'Check every file in the holding folder still has a twin in the archive. Moves nothing.',
  df:       'Measure free space.'
};

async function act(name, btn) {
  if (name === 'import')    { location.href = 'import.php'; return; }
  if (name === 'cachejunk') { return moveCache(btn); }
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
function drawNow(d) {
  const h = (pane === 'overview') ? helperNow(d.copy) : null;
  $('now').hidden = !h || h.bad;
  if (!h || h.bad) return;
  const secs = d.copy.secs == null ? '' : d.copy.secs < 10 ? 'live' : 'updated ' + d.copy.secs + ' s ago';
  $('now').innerHTML =
    '<div class="now-h"><span class="dot busy"></span><b>' + esc(h.title) + '</b><span>' + esc(secs) + '</span></div>' +
    (h.pct != null ? '<div class="bar"><i style="width:' + h.pct + '%"></i><em>' + h.pct + '%</em></div>' : '') +
    '<div class="facts">' + (h.facts || []).map(function (f) {
      return '<div><b>' + esc(f[0]) + '</b><span>' + esc(f[1]) + '</span></div>'; }).join('') + '</div>' +
    (h.file ? '<div class="file">now: ' + esc(h.file) + '</div>' : '');
}

// ── what the page shows ────────────────────────────────────────────────────
async function load() {
  let d;
  try { d = await (await fetch('state.php?t=' + Date.now())).json(); }
  catch (e) { oops('The archive is not answering.'); return; }
  if (d.error) { oops(d.error); return; }
  $('err').hidden = true;

  secs = d.sections || [];
  if (!seeded) {                        // the tick starts as whatever is queued
    secs.forEach(function (x) { if (x.state === 'queued') ticked.add(x.path); });
    seeded = true;
  }

  $('built').textContent = 'search built ' + (d.archive.imported_ago || 'never');
  $('fFiles').textContent = d.archive.files.toLocaleString() + ' files · ' + tb(d.archive.bytes);
  $('fFree').textContent  = tb(d.disk.free) + ' free · ' + (d.disk.pct || 0) + '% used';
  const m = $('fMeter');
  m.style.width = (d.disk.pct || 0) + '%';
  m.className = (d.disk.pct >= 90 ? 'full' : d.disk.pct >= 80 ? 'hot' : '');

  const bad = (d.conditions || []).filter(function (c) { return c.level === 'bad'; }).length;
  $('nOverview').hidden = !bad; $('nOverview').textContent = bad || '';

  drawTiles(d);
  drawNow(d);

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
    return '<div class="ev"><span class="ico ok">✓</span><div class="t">' +
      esc(r.target) + ' ' + esc(r.what) +
      '<small>' + r.files.toLocaleString() + ' files · ' + tb(r.bytes) +
      (r.secs ? ' · ' + Math.round(r.secs / 60) + ' min' : '') + '</small></div>' +
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
    ? 'Picking up jobs · checked ' + (d.runner.ago || 0) + 's ago'
    : 'Not picking up jobs';
  const lg = $('log'), stuck = lg.scrollTop + lg.clientHeight >= lg.scrollHeight - 30;
  lg.textContent = d.log || 'nothing logged yet';
  if (stuck) lg.scrollTop = lg.scrollHeight;

  $('queuenote').textContent = (function () {
    const w = secs.filter(function (x) { return x.state === 'queued' || x.state === 'splitting'; }).length;
    return w ? w + ' on the list. One at a time, in this order — anything you add waits.' : '';
  })();

  const h = d.helper || {};
  $('watchhint').innerHTML = h.command
    ? 'Nothing moves until the helper machine is listening. Once per session:' +
      cmdHTML(h.command)
    : '';

  drawMove(d);

  $('tools').innerHTML = [['manifest', 'Rebuild the file list'], ['import', 'Rebuild search'],
    ['verify', 'Check the holding folder'], ['df', 'Measure free space']]
    .map(function (a) { return '<button class="btn quiet" data-t="' + a[0] + '">' + a[1] + '</button>'; })
    .join('');
  $('tools').querySelectorAll('[data-t]').forEach(function (b) {
    b.onclick = function () { act(b.dataset.t, b); };
  });

  if (busy && !d.running) busy = null;
}

show((location.hash || '#overview').slice(1));
load(); setInterval(load, 4000);
</script>
