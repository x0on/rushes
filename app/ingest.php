<?php
// ingest.php — where a shoot becomes part of the archive.
//
// Three cards, left to right, the way the work goes: what you brought, what
// it was, where it lands. Under them, whatever is moving right now. Rushes
// works the destination out; nobody types a path.
//
// The source list is whatever the helper machine reports as plugged in — so
// nobody types a path, and nobody can pick something the copier cannot reach.
// The destination is worked out here AND again in queue.php, which never
// trusts a path from the browser.
$NAV = 'ingest';
require __DIR__ . '/db/config.php';

// The departments come from the plan in Structure — never from whatever
// folders happen to be on disk, which is years of history, typos and all.
$shelf = shelf_dir();
// No shelf chosen yet (Reorganize → 00): nowhere to put a shoot, so the
// set-up card below shows instead of the form.
$depts = shelf_name() === '' ? [] : array_column(departments(), 'name');
$deptFolder = [];
foreach ($depts as $d) $deptFolder[$d] = dept_folder($d);
$S      = settings();
$aname  = $S['archive']['label'] ?? 'Archive';
?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ingest &middot; <?= htmlspecialchars($S['name'] ?? 'Rushes') ?></title>
<?php require __DIR__ . '/head.php'; ?>
<style>
  .ing { max-width: 1400px; margin: 0 auto; padding: 22px 24px 48px; width: 100% }
  .ing-h { display: flex; align-items: center; gap: 12px; margin: 0 0 16px }
  .ing-h h1 { font-size: 22px; font-weight: 650; letter-spacing: -.02em; margin: 0 }

  .three { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px }
  @media (max-width: 1100px) { .three { grid-template-columns: 1fr } }
  .card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px;
          padding: 16px 18px 18px; min-width: 0 }
  .card > h2 { font-size: 14px; font-weight: 600; margin: 0 0 16px }
  .card > h2 span { color: var(--muted); font-weight: 500; margin-right: 4px }

  .who { display: grid; grid-template-columns: 64px 1fr; gap: 16px; align-items: start }
  .who .tile-i { width: 64px; height: 64px; border-radius: 12px; background: var(--raised);
                 display: grid; place-items: center; color: var(--fg) }
  .who .tile-i svg { width: 30px; height: 30px }
  .who b { display: block; font-size: 16px; font-weight: 650; margin: 2px 0 4px;
           overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
  .who .m { color: var(--muted); font-size: 13px; line-height: 1.55; word-break: break-word }
  .who .btns { margin-top: 12px }

  .fields { display: grid; grid-template-columns: 96px 1fr; gap: 10px 12px; align-items: center }
  .fields label { color: var(--muted); font-size: 13px }
  .days { margin-top: 14px; display: grid; gap: 10px }
  .day { display: grid; grid-template-columns: 96px 1fr; gap: 12px; align-items: start }
  .day .when b { display: block; font-size: 13.5px; color: var(--fg) }
  .day .when small { color: var(--muted); font-size: 11.5px }
  .day .clock { grid-column: 2; font-size: 12px; color: var(--warn); margin-top: -4px }
  .day .clock input { width: auto; margin-left: 6px; padding: 4px 8px }
  .fields input, .fields select, select.btn {
    width: 100%; padding: 9px 11px; font: 13.5px var(--font); color: var(--fg);
    border: 1px solid var(--line); border-radius: var(--radius-sm); background: var(--bg) }
  select.btn { width: auto; max-width: 100%; cursor: pointer; font-weight: 500 }

  .go { display: flex; align-items: center; gap: 12px; margin: 16px 0 22px; flex-wrap: wrap }
  .go .note { max-width: 640px }

  .q-h { font-size: 11px; font-weight: 650; letter-spacing: .09em; text-transform: uppercase;
         color: var(--faint); margin: 0 0 8px }
  .job { display: grid; grid-template-columns: 88px minmax(0, 1fr) auto auto; gap: 18px;
         align-items: center; background: var(--surface); border: 1px solid var(--line);
         border-radius: 12px; padding: 12px 18px 12px 12px; margin: 0 0 8px }
  .job .th { width: 88px; height: 52px; border-radius: 8px; background: var(--raised);
             display: grid; place-items: center; color: var(--muted) }
  .job .th svg { width: 22px; height: 22px }
  .job .t b { display: block; font-size: 14.5px; font-weight: 600;
              overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
  .job .t .st { font-size: 13px; color: var(--accent-text); margin-top: 1px }
  .job .t .st.idle { color: var(--muted) }
  .job .t .st.bad  { color: var(--bad) }
  .job .bar { height: 7px; border-radius: 4px; margin-top: 9px }
  .job .amt { color: var(--muted); font-size: 13px; white-space: nowrap;
              font-variant-numeric: tabular-nums; padding-right: 16px;
              border-right: 1px solid var(--line) }
  .job .amt.last { border-right: 0; padding-right: 0 }
  .job .pill { font-size: 12px; padding: 4px 11px; border: 1px solid transparent }
  .pill.check { color: var(--accent-text); border-color: var(--accent-text); background: transparent }
  .pill.stop  { color: var(--bad);  border-color: var(--bad);  background: var(--bad-bg) }
  .pill.ok    { color: var(--ok);   border-color: var(--ok);   background: var(--ok-bg) }
  @media (max-width: 800px) { .job { grid-template-columns: 1fr } .job .th { display: none }
                              .job .amt { border: 0; padding: 0 } }

  .foot { display: flex; gap: 10px; align-items: flex-start; color: var(--muted);
          font-size: 13px; margin-top: 16px }
  .foot svg { width: 18px; height: 18px; flex: none; margin-top: 1px }
</style>

<?php if (!$depts): ?>
<!-- Ingest is day-to-day work. It needs the plan first: without one there is
     nowhere to put a shoot except wherever someone guesses. -->
<div class="app"><main class="work"><div class="ing">
  <div class="ing-h"><h1>Bring in a shoot</h1></div>
  <div class="card" style="max-width:620px">
    <h2><span>First /</span> Set up how the archive is organised</h2>
    <p class="note" style="margin:0 0 14px;font-size:13.5px">Before the first shoot comes in, Rushes needs
      your list of <?= htmlspecialchars(strtolower(shelf_word(true))) ?> &mdash; the shelves every shoot goes on &mdash;
      and which folder in the archive they live in<?= shelf_name() === '' && departments() ? ' (the list is there; the folder is not chosen yet)' : '' ?>. It takes a couple of minutes,
      moves nothing, and only has to be done once.</p>
    <a class="btn" href="/structure.php">Set up the structure</a>
    <p class="note" style="margin:12px 0 0">This needs the admin password.</p>
  </div>
</div></main></div>
<?php exit; endif; ?>

<div class="app">
<main class="work">
<div class="ing">

  <div class="ing-h">
    <h1>Bring in a shoot</h1>
    <span class="grow"></span>
    <a class="btn quiet" href="/upload.php" title="Photos and video from a phone or this computer, without a card">Upload files instead</a>
    <button class="btn quiet tab-i" type="button" id="another" disabled
            title="Queue this one first"><?= icon('plus', 2) ?> Queue another source</button>
  </div>

  <div class="three">

    <!-- ══ 01 source ══ -->
    <section class="card" aria-labelledby="h1">
      <h2 id="h1"><span>01 /</span> Source</h2>
      <div class="who">
        <div class="tile-i" id="srcIco"><?= icon('camera', 1.6) ?></div>
        <div>
          <b id="srcName">Looking for cards&hellip;</b>
          <div class="m" id="srcMeta">&nbsp;</div>
          <div class="btns">
            <select class="btn quiet" id="src" aria-label="Change source" hidden></select>
          </div>
        </div>
      </div>
    </section>

    <!-- ══ 02 shoot details ══ -->
    <section class="card" aria-labelledby="h2">
      <h2 id="h2"><span>02 /</span> Shoot details</h2>
      <div class="fields">
        <label for="dept"><?= htmlspecialchars(shelf_word()) ?></label>
        <div>
          <select id="dept">
            <!-- Starts empty on purpose: a list that defaults to its first entry
                 files a Parks shoot under City Attorney for anyone in a hurry. -->
            <option value="" selected disabled>&mdash; pick one &mdash;</option>
            <?php foreach ($depts as $d): ?>
              <option><?= htmlspecialchars($d) ?></option>
            <?php endforeach; ?>
            <?php if (shelf_open()): ?>
              <option value="__new">+ Add a new <?= htmlspecialchars(strtolower(shelf_word())) ?>&hellip;</option>
            <?php endif; ?>
          </select>
          <!-- Only when the list is open to additions: a new name, checked by
               the same no-catch-all rule as Structure, added to the plan. -->
          <input type="text" id="deptNew" hidden style="margin-top:8px"
                 placeholder="its name, as it should appear from now on" aria-label="New <?= htmlspecialchars(strtolower(shelf_word())) ?>">
        </div>
      </div>
      <!-- The date is never typed: it is the day the camera recorded. A card
           that spans several days gets one line — and one folder — per day. -->
      <div id="days" class="days"><p class="note" style="margin:14px 0 0">The shoot date comes from the card itself.</p></div>
    </section>

    <!-- ══ 03 destination ══ -->
    <section class="card" aria-labelledby="h3">
      <h2 id="h3"><span>03 /</span> Destination</h2>
      <div class="who">
        <div class="tile-i"><?= icon('server', 1.6) ?></div>
        <div>
          <b><?= htmlspecialchars($aname) ?></b>
          <div class="m" id="dest">&mdash;</div>
          <div class="m" id="free">&mdash;</div>
        </div>
      </div>
    </section>
  </div>

  <div class="go">
    <button class="btn" type="button" id="start" disabled>Start ingest</button>
    <span class="note" id="goNote">Pick a card, say what it was, and pick the <?= htmlspecialchars(strtolower(shelf_word())) ?>.</span>
  </div>

  <div class="q-h">Moving now</div>
  <div id="jobs"><div class="job"><div class="th"></div><div class="t"><b>Checking&hellip;</b></div></div></div>

  <div class="foot"><?= icon('info', 1.7) ?>
    <span>Each file's size is compared after it lands; one that does not match is removed and
      reported, never kept. Originals stay on the source.</span></div>
</div>
</main>
</div>

<script>
const $ = function (i) { return document.getElementById(i); };
const esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
  return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
const tb = function (b) {
  return b >= 1099511627776 ? (b / 1099511627776).toFixed(2) + ' TB'
       : b >= 1073741824    ? (b / 1073741824).toFixed(1) + ' GB'
       : b >= 1048576       ? Math.round(b / 1048576) + ' MB' : Math.round(b / 1024) + ' KB';
};
const DEPTS   = <?= json_encode($deptFolder, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const SHELF   = <?= json_encode(basename($shelf), JSON_UNESCAPED_UNICODE) ?>;
const HELPER  = <?= json_encode(['external' => helper_mode() === 'external',
                                   'label' => helper_name(),
                                   'command' => helper_command()], JSON_UNESCAPED_SLASHES) ?>;
const ICONS   = <?= json_encode(['video' => icon('video'), 'wait' => icon('transfers'),
                                 'card' => icon('camera', 1.6), 'drive' => icon('server', 1.6),
                                 'done' => icon('archive')]) ?>;
let S = null;

// ── the days on the card ─────────────────────────────────────────────────
const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const nice = function (d) { return MONTHS[+d.slice(5, 7) - 1] + ' ' + (+d.slice(8)) + ', ' + d.slice(0, 4); };
// The one case where a person gives the date, and only for that day: a camera
// whose clock was never set. Those start on January 1st of some year — which
// looks plausible, so it is caught by the day, not only by the year.
const TODAY = new Date().toISOString().slice(0, 10);
const YEAR_AGO = new Date(Date.now() - 365 * 864e5).toISOString().slice(0, 10);
const impossible = function (d) { return !d || d < '2005-01-01' || d > TODAY; };
const unset = function (d) { return impossible(d) || d.slice(5) === '01-01'; };
let daysSig = '';

function paintDays() {
  const v = picked(), days = (v && v.days) || [];
  const sig = (v ? v.path : '') + '|' + JSON.stringify(days);
  if (sig === daysSig) return;           // same card, same days: keep what was typed
  daysSig = sig;
  if (!v) { $('days').innerHTML = '<p class="note" style="margin:0">The shoot date comes from the card itself.</p>'; paint(); return; }
  $('days').innerHTML = (days.length > 1
      ? '<p class="note" style="margin:0">This card has ' + days.length + ' shoot days &mdash; each becomes its own folder.</p>' : '') +
    days.map(function (d, i) {
      const bad = unset(d.day), old = !bad && d.day < YEAR_AGO;
      return '<div class="day" data-day="' + d.day + '">' +
        '<div class="when"><b>' + nice(d.day) + '</b><small>' + d.files.toLocaleString() + ' files · ' + tb(d.bytes) + '</small></div>' +
        '<input type="text" class="ev" placeholder="what it was' + (i ? '' : ' — e.g. Council Meeting') + '" autocomplete="off" aria-label="What was shot on ' + nice(d.day) + '">' +
        (bad ? '<div class="clock">The camera says ' + nice(d.day) + ' — a clock that was never set starts there. ' +
               'Real date:<input type="date" class="fix" max="' + TODAY + '"></div>'
         : old ? '<div class="clock" style="color:var(--muted)">Shot over a year ago. If that is wrong, the camera\'s clock was off:' +
               '<input type="date" class="fix" max="' + TODAY + '" value="' + d.day + '"></div>' : '') +
        '</div>';
    }).join('');
  $('days').querySelectorAll('input').forEach(function (i) { i.oninput = function () { paint(); canStart(); }; });
  paint(); canStart();
}
// Each day as it will be queued: the day on the card, the date the folder is
// named with (the same, unless the clock was wrong), and what it was.
function dayRows() {
  return Array.prototype.map.call($('days').querySelectorAll('.day'), function (el) {
    const day = el.dataset.day, fix = el.querySelector('.fix');
    return { day: day, date: fix ? fix.value : day, event: el.querySelector('.ev').value.trim() };
  });
}

// ── destination: worked out, never typed ──────────────────────────────────
function paint() {
  // The department's own folder — PARKS for Parks & Recreation — not its name.
  const nu = $('dept').value === '__new';
  $('deptNew').hidden = !nu;
  const d = nu ? ($('deptNew').value.trim() || '…') : (DEPTS[$('dept').value] || '—');
  const rows = dayRows();
  $('dest').innerHTML = rows.length ? rows.map(function (r) {
    const w = r.date || '';
    return esc([SHELF, d, w.slice(0, 4) || '____', (w.replace(/-/g, '') || '________') + ' ' + (r.event || '…')].join(' / '));
  }).join('<br>') : esc(SHELF + ' / ' + d + ' / …');
}
['dept', 'deptNew'].forEach(function (i) {
  $(i).oninput = function () { paint(); canStart(); }; $(i).onchange = $(i).oninput; });

// ── source: exactly what is plugged into the workstation ─────────────────
// Rebuilt on every refresh, keeping whatever you had picked if it is still
// there. A card pulled out disappears from the list instead of failing later.
function vols() {
  return ((S && S.volumes && S.volumes.vols) || []).filter(function (v) { return !v.archive; });
}
function picked() {
  const p = $('src').value;
  return vols().find(function (v) { return v.card && v.path === p; }) || null;
}
function paintSource() {
  const h = (S && S.volumes) || {}, was = $('src').value;
  // Cards only. A drive can hold years of footage; splitting it by day would
  // make hundreds of folders. Drives and folders come in through Transfers.
  const list = vols().filter(function (v) { return v.card; });
  if (!h.at || !h.fresh) {
    $('src').hidden = true;
    const where = HELPER.external ? 'the ' + HELPER.label : 'this machine';
    $('srcName').textContent = h.at ? 'The helper stopped' : 'The helper is not running';
    // Built in: the archive machine starts it by itself; on a Mac it is Rushes Helper.
    $('srcMeta').innerHTML = 'Cards plugged into ' + esc(where) + ' show up here once it is. ' +
      (HELPER.external ? 'Open Rushes Helper on ' + esc(where) + ' and check “Run in the background” is on (Setup → 04 Helper has the details).'
                       : 'The archive machine starts it again by itself within a minute; if it does not, see Setup → 04 Helper.');
    paintDays(); return canStart();
  }
  if (!list.length) {
    $('src').hidden = true;
    $('srcName').textContent = 'No card plugged in';
    $('srcMeta').textContent = 'Plug one into ' + (HELPER.external ? 'the ' + HELPER.label : 'this machine') +
      '. It shows up here within twenty seconds. Drives and folders come in through Manage → Transfers.';
    paintDays(); return canStart();
  }
  $('src').innerHTML = list.map(function (v) {
    return '<option value="' + esc(v.path) + '">' + esc(v.name) + '</option>'; }).join('');
  if (list.some(function (v) { return v.path === was; })) $('src').value = was;
  $('src').hidden = false;
  const v = picked();
  $('srcIco').innerHTML = ICONS.card;
  $('srcName').textContent = v.name;
  $('srcMeta').textContent = v.files.toLocaleString() + ' files / ' + tb(v.bytes);
  paintDays(); canStart();
}
$('src').onchange = paintSource;

// ── starting ──────────────────────────────────────────────────────────────
function canStart() {
  const dep = $('dept').value === '__new' ? $('deptNew').value.trim() : $('dept').value;
  const rows = dayRows();
  const ok = !!(picked() && dep && rows.length &&
                rows.every(function (r) { return r.event && !impossible(r.date); }));
  $('start').disabled = !ok;
}

$('start').onclick = async function () {
  const v = picked(), btn = this, rows = dayRows();
  if (!v || !rows.length) return;
  const lines = $('dest').innerHTML.split('<br>').map(function (x) {
    const t = document.createElement('textarea'); t.innerHTML = x; return '  ' + t.value; });
  if (!sure(btn, v.name + ' (' + v.files.toLocaleString() + ' files, ' + tb(v.bytes) + ') into ' +
               lines.map(function (l) { return l.trim(); }).join(', ') + '. Originals stay on the card.', 'ingest')) return;
  btn.disabled = true; btn.textContent = 'Queueing…';
  const done = [];
  try {
    // One request per day, each its own folder. The first may add a new
    // shelf to the plan; the rest then simply use it.
    for (const r of rows) {
      const nu = $('dept').value === '__new';
      const res = await fetch('/queue.php', { method: 'POST', body: new URLSearchParams({
        ingest_src: v.path, day: r.day, date: r.date, event: r.event,
        dept: nu ? '' : $('dept').value, new_dept: nu ? $('deptNew').value.trim() : '' }) });
      const j = await res.json();
      if (j.error) throw new Error(j.error);
      if (j.added) {                     // a new one is in the plan now — show it as such
        const o = document.createElement('option'); o.textContent = j.added;
        $('dept').insertBefore(o, $('dept').querySelector('[value="__new"]'));
        DEPTS[j.added] = j.added; $('dept').value = j.added; $('deptNew').value = ''; paint();
      }
      done.push(j.into);
    }
    $('goNote').innerHTML = '<b>Queued ✓</b> ' + (done.length > 1 ? done.length + ' folders: ' : 'It will land in ') +
      done.map(esc).join(' · ') + '.';
    $('another').disabled = false;
    btn.textContent = 'Queued ✓';
    load();
  } catch (e) {
    $('goNote').textContent = (done.length ? done.length + ' queued, then stopped: ' : 'Not queued: ') + e.message;
    btn.textContent = 'Start ingest'; canStart();
  }
};
$('another').onclick = function () {
  daysSig = ''; paintDays();
  $('start').textContent = 'Start ingest'; canStart();
  $('goNote').textContent = 'Swap the card, then fill this in again.';
  this.disabled = true;
  const first = $('days').querySelector('.ev'); if (first) first.focus();
};

// ── the queue: one row per thing, in the order it will happen ─────────────
function row(th, name, st, stCls, pct, amt, pill) {
  return '<div class="job"><div class="th">' + th + '</div>' +
    '<div class="t"><b>' + esc(name) + '</b><div class="st ' + stCls + '">' + st + '</div>' +
    '<div class="bar' + (pct == null ? '' : '') + '"><i style="width:' + (pct || 0) + '%"></i></div></div>' +
    (amt ? '<span class="amt' + (pill ? '' : ' last') + '">' + amt + '</span>' : '<span></span>') +
    (pill || '<span></span>') + '</div>';
}
function paintJobs() {
  const c = S.copy, out = [];
  if (c && c.phase === 'copying') {
    const pct = c.of ? Math.round(c.copied / c.of * 100) : 0;
    const name = c.label || (c.source || '').split('/').pop() || 'A folder';
    out.push(c.stale
      ? row(ICONS.video, name, 'Stopped at ' + pct + '% · quiet for ' + esc(c.ago), 'bad', pct,
            c.copied.toLocaleString() + ' of ' + c.of.toLocaleString() + ' files',
            '<span class="pill stop">stopped</span>')
      : row(ICONS.video, name, 'Copying / ' + pct + '%', '', pct,
            c.copied.toLocaleString() + ' of ' + c.of.toLocaleString() + ' files',
            '<span class="pill check">checked as it lands</span>'));
  } else if (c && c.phase === 'blocked') {
    out.push(row(ICONS.video, (c.source || '').split('/').pop() || 'The source',
      'Cannot see the source · ' + esc(c.ago), 'bad', 0, '', '<span class="pill stop">blocked</span>'));
  }
  // Cards: queued first, then any that landed while this page was open.
  const busyLabel = c && c.phase === 'copying' && !c.stale ? c.label : '';
  (S.ingests || []).forEach(function (x) {
    if (x.state === 'queued' && x.name !== busyLabel)
      out.push(row(ICONS.card, x.name, 'Queued', 'idle', null, '', ''));
  });
  (S.ingests || []).forEach(function (x) {
    if (x.state === 'done') {
      // The event without its date, as a person would search for it.
      const words = x.name.replace(/^\d{8}\s+/, '');
      out.push(row(ICONS.done, x.name, 'Landed and searchable', '', 100, '',
        '<a class="btn quiet" href="/db/find.php?q=' + encodeURIComponent(words) + '">Find it</a>'));
    }
  });
  // A card is safe to format only when every folder it went into has landed,
  // every file read back from the archive and checked, and none could not be
  // copied. Said plainly either way, never left to be guessed.
  const cards = {};
  (S.ingests || []).forEach(function (x) { (cards[x.src] = cards[x.src] || []).push(x); });
  Object.keys(cards).forEach(function (src) {
    const all = cards[src], name = src.split('/').pop() || src;
    if (!all.every(function (x) { return x.state === 'done'; })) return;
    const failed = all.reduce(function (n, x) { return n + (x.failed || 0); }, 0);
    out.push(failed
      ? row(ICONS.card, name + ' — do not format it yet', failed + ' file' + (failed === 1 ? '' : 's') +
            ' could not be copied. They are listed in the record; queue the card again to try them.', 'bad', null, '',
            '<span class="pill stop">keep the card</span>')
      : row(ICONS.done, name + ' — safe to format', 'Every file is on the archive, read back from its disk and checked against the card.',
            '', 100, '', '<span class="pill check">verified</span>'));
  });
  // The folder being copied is also still 'queued' in the list; show it once.
  const now = c && (c.phase === 'copying' || c.phase === 'blocked') ? c.source : '';
  (S.sections || []).filter(function (x) {
    return (x.state === 'queued' || x.state === 'splitting') && x.path !== now; })
    .forEach(function (x) {
      out.push(row(ICONS.wait, x.name, x.state === 'splitting' ? 'Being measured' : 'Queued', 'idle',
        null, x.files.toLocaleString() + ' files · ' + tb(x.bytes), ''));
    });
  $('jobs').innerHTML = out.length ? out.join('')
    : '<div class="job"><div class="th">' + ICONS.wait + '</div>' +
      '<div class="t"><b>Nothing is moving</b><div class="st idle">' +
      'Queue a card above and it starts here within twenty seconds.' +
      '</div></div><span></span><span></span></div>';
}

function load() {
  return fetch('/db/state.php?t=' + Date.now()).then(function (r) { return r.json(); })
    .then(function (s) {
      if (s.error) return;
      S = s;
      $('free').textContent = s.disk && s.disk.free > 0 ? tb(s.disk.free) + ' available' : 'free space not measured yet';
      paintSource(); paintJobs();
    }).catch(function () { $('free').textContent = 'the archive did not answer'; });
}
paintSource(); every(load, 5000);
</script>
