<?php
// pull.php — one pull (?p=slug), or every pull.
//
// The page a link opens. Whoever collected the clips sends the link; whoever
// edits opens it on their own computer and downloads a file written for that
// computer. Everyone who can open Rushes can see and change every pull —
// they are the team.
$NAV = 'search';
require __DIR__ . '/db/config.php';
$S    = settings();
$host = parse_url($S['archive']['url'] ?? '', PHP_URL_HOST) ?: ($_SERVER['SERVER_NAME'] ?? '');
$share = basename(archive_dir());
// The two usual ways a computer sees the archive, worked out here so nobody
// has to type a path: a Mac mounts the share under /Volumes, and Windows can
// open it by the server's address without mapping a drive letter.
$paths = ['mac' => '/Volumes/' . $share, 'pc' => '\\\\' . $host . '\\' . $share];
$slug = (string)($_GET['p'] ?? '');
?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pulls &middot; <?= htmlspecialchars($S['name'] ?? 'Rushes') ?></title>
<?php require __DIR__ . '/head.php'; ?>
<style>
  .pl { max-width: 980px; margin: 0 auto; padding: 22px 20px 60px; width: 100% }
  .pl-h { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; margin: 0 0 4px }
  .pl-h h1 { font-size: 22px; font-weight: 650; letter-spacing: -.02em; margin: 0 }
  .pl-sub { color: var(--muted); font-size: 13px; margin: 0 0 18px }
  .card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px;
          padding: 16px 18px; margin: 0 0 14px }
  .card > h2 { font-size: 14px; font-weight: 600; margin: 0 0 10px }
  .card > h2 span { color: var(--muted); font-weight: 500; margin-right: 4px }
  .it { display: grid; grid-template-columns: 34px minmax(0,1fr) auto auto; gap: 12px; align-items: center;
        padding: 8px 0; border-top: 1px solid var(--line-soft) }
  .it:first-child { border-top: 0 }
  .it .th { width: 34px; height: 34px; border-radius: 6px; background: var(--raised); display: grid;
            place-items: center; color: var(--muted) }
  .it .th svg { width: 17px; height: 17px }
  .it .nm { min-width: 0 }
  .it .nm b { display: block; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
  .it .nm small { display: block; color: var(--faint); font-size: 11px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
  .it .sz { color: var(--muted); font-size: 12px; font-variant-numeric: tabular-nums }
  .it .ctl { display: flex; gap: 4px }
  .it.gone b { text-decoration: line-through; color: var(--muted) }
  .seg { display: flex; gap: 8px; flex-wrap: wrap; margin: 0 0 10px }
  .seg button[aria-pressed="true"] { border-color: var(--accent); background: var(--sel-bg); color: var(--sel-fg) }
  #base { width: 100%; max-width: 460px }
  .dl { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px }
  .row-l { display: grid; grid-template-columns: minmax(0,1fr) auto auto; gap: 12px; padding: 10px 0;
           border-top: 1px solid var(--line-soft); color: var(--fg); align-items: center }
  .row-l:first-child { border-top: 0 }
  .row-l:hover { text-decoration: none; color: var(--accent-text) }
  .row-l small { display: block; color: var(--muted); font-size: 12px }
  @media (max-width: 640px) { .it { grid-template-columns: 34px minmax(0,1fr) auto } .it .sz { display: none } }
</style>

<div class="app"><main class="work"><div class="pl" id="page">
  <p class="note">Loading&hellip;</p>
</div></main></div>

<script>
const $ = function (i) { return document.getElementById(i); };
const esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
  return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
// ── Copy the clips to work on: asked of the Mac's runner, and watched here until it is done ──
let copyT = null;
function copyShow(c, total) {
  const said = $('copySaid'), bar = $('copyBar'), go = $('copyGo'), stop = $('copyStop');
  if (!said || !c) return false;
  const going = c.state === 'asked' || c.state === 'copying';
  const fails = (c.failed || []).length ? ' ' + c.failed.length + ' could not be copied: ' + c.failed.slice(0, 3).map(function (f) { return f[0] + ' (' + f[1] + ')'; }).join('; ') + (c.failed.length > 3 ? '…' : '') + '.' : '';
  bar.hidden = !going && c.state !== 'done'; bar.firstChild.style.width = (c.total_bytes ? Math.min(100, c.bytes / c.total_bytes * 100) : 0).toFixed(1) + '%';
  go.disabled = going; stop.hidden = !going;
  said.textContent =
    c.state === 'asked' ? 'Asked: the copy starts within a minute.'
    : c.state === 'copying' ? 'Copying ' + c.n + ' of ' + c.total + ' · ' + tb(c.bytes) + ' of ' + tb(c.total_bytes) + (c.file ? ' · now: ' + c.file : '') + fails
    : c.state === 'done' ? 'Copied ✓ ' + (c.total - (c.failed || []).length) + ' of ' + c.total + ' clips into ' + c.dest + (c.skipped ? ' (' + c.skipped + (c.skipped === 1 ? ' was' : ' were') + ' there already)' : '') + '.' + fails
    : (c.state === 'stopped' ? 'Stopped. ' : 'Did not finish. ') + (c.why || '') + fails;
  return going;
}
function copyWatch(total) {
  clearTimeout(copyT);
  copyT = setTimeout(async function () {
    try { const d = await (await fetch('/db/pulls.php?p=' + encodeURIComponent(SLUG) + '&t=' + Date.now())).json(); if (copyShow(d.copy, total)) copyWatch(total); }
    catch (e) { copyWatch(total); }
  }, 1500);
}
function copyWire(total) {
  const to = store.get('copyTo'); if (to && [].some.call($('copyTo').options, function (o) { return o.value === to; })) $('copyTo').value = to;
  if (copyShow(D.copy, total)) copyWatch(total);
  $('copyGo').onclick = async function () {
    store.set('copyTo', $('copyTo').value); this.disabled = true; $('copySaid').textContent = 'Asking…';
    try {
      const r = await (await fetch('/db/pulls.php', { method: 'POST', body: new URLSearchParams({ action: 'copy', p: SLUG, to: $('copyTo').value }) })).json();
      if (r.error) throw new Error(r.error);
      copyShow({ state: 'asked', n: 0, total: r.n, bytes: 0, total_bytes: r.b }, total); copyWatch(total);
    } catch (e) { this.disabled = false; $('copySaid').textContent = 'Not started: ' + e.message; }
  };
  $('copyStop').onclick = async function () {
    this.disabled = true; $('copySaid').textContent = 'Stopping after this moment…';
    try { await fetch('/db/pulls.php', { method: 'POST', body: new URLSearchParams({ action: 'copy-stop', p: SLUG }) }); } catch (e) {}
    this.disabled = false;
  };
}
const tb = function (b) {
  return b >= 1e12 ? (b / 1e12).toFixed(2) + ' TB' : b >= 1e9 ? (b / 1e9).toFixed(1) + ' GB'
       : b >= 1e6 ? Math.round(b / 1e6) + ' MB' : Math.round(b / 1e3) + ' KB'; };
const when = function (t) { return new Date(t * 1000).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); };
const SLUG  = <?= json_encode($slug, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const PATHS = <?= json_encode($paths, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const ICON  = <?= json_encode(['video' => icon('video'), 'image' => icon('image'), 'audio' => icon('audio'),
                               'project' => icon('project'), 'file' => icon('file')], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const store = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
                set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };

async function post(body) {
  const r = await fetch('/db/pulls.php', { method: 'POST', body: new URLSearchParams(body) });
  const j = await r.json(); if (j.error) throw new Error(j.error); return j;
}

// ── every pull ─────────────────────────────────────────────────────────────
async function listAll() {
  const d = await (await fetch('/db/pulls.php?list=1&t=' + Date.now())).json();
  $('page').innerHTML = '<div class="pl-h"><h1>Pulls</h1></div>' +
    '<p class="pl-sub">Clips gathered for a job. Anyone on the team can open one, add to it, and download it for their editing software.</p>' +
    '<div class="card">' + (d.pulls.length ? d.pulls.map(function (p) {
      return '<a class="row-l" href="/pull.php?p=' + encodeURIComponent(p.slug) + '"><span><b>' + esc(p.name) + '</b>' +
        '<small>' + (p.made_by ? esc(p.made_by) + ' · ' : '') + when(p.updated) + '</small></span>' +
        '<span class="note">' + p.n + ' clip' + (p.n == 1 ? '' : 's') + '</span><span class="note">' + tb(p.b) + '</span></a>';
    }).join('') : '<p class="note" style="margin:0">No pulls yet. In Search, press <b>+ Pull</b> on anything to start one.</p>') + '</div>';
}

// ── one pull ───────────────────────────────────────────────────────────────
let D = null;
async function show() {
  const r = await fetch('/db/pulls.php?p=' + encodeURIComponent(SLUG) + '&t=' + Date.now());
  D = await r.json();
  if (D.error) { $('page').innerHTML = '<div class="card"><b>' + esc(D.error) + '</b><p class="note">Ask whoever sent it for the link again, or see <a href="/pull.php">all pulls</a>.</p></div>'; return; }
  const p = D.pull, items = D.items, here = items.filter(function (i) { return i.here; });
  const total = here.reduce(function (n, i) { return n + i.bytes; }, 0);
  document.title = p.name + ' · Pulls';

  $('page').innerHTML =
    '<div class="pl-h"><h1 id="pName">' + esc(p.name) + '</h1>' +
      '<button class="ghost" id="rename">Rename</button>' +
      '<a class="ghost" href="/db/find.php?pull=' + encodeURIComponent(p.slug) + '">+ Add clips</a>' +
      '<a class="note" href="/pull.php" style="margin-left:auto">All pulls</a></div>' +
    '<p class="pl-sub">' + (p.made_by ? 'Gathered by ' + esc(p.made_by) + ' · ' : '') + 'started ' + when(p.created) +
      ' · ' + items.length + ' clip' + (items.length === 1 ? '' : 's') + ' · ' + tb(total) + '</p>' +

    '<div class="card"><h2><span>01 /</span> Send it</h2>' +
      '<p class="note" style="margin:0">Anyone on the office network can open this link and download the pull for their own computer.</p>' +
      cmdHTML(location.origin + '/pull.php?p=' + p.slug) + '</div>' +

    '<div class="card"><h2><span>02 /</span> The clips</h2>' + (items.length ? items.map(function (it, i) {
      const trail = it.rel.split('/').slice(0, -1).join('  ›  ');
      return '<div class="it' + (it.here ? '' : ' gone') + '" data-rel="' + esc(it.rel) + '">' +
        '<span class="th">' + (ICON[it.kind] || ICON.file) + '</span>' +
        '<span class="nm"><b>' + esc(it.name) + '</b><small>' + (it.here ? esc(trail) : 'no longer in the archive — left out of downloads') + '</small></span>' +
        '<span class="sz">' + tb(it.bytes) + '</span>' +
        '<span class="ctl">' + (i ? '<button class="ghost" data-a="up" title="Move up">↑</button>' : '') +
          (i < items.length - 1 ? '<button class="ghost" data-a="down" title="Move down">↓</button>' : '') +
          '<button class="ghost" data-a="remove" title="Take it out">✕</button></span></div>';
    }).join('') : '<p class="note" style="margin:0">Empty. <a href="/db/find.php?pull=' + encodeURIComponent(p.slug) + '">Add clips from Search</a>.</p>') + '</div>' +

    '<div class="card"><h2><span>03 /</span> Download it for this computer</h2>' +
      '<p class="note" style="margin:0 0 10px">The file points at the originals on the archive, written the way <b>this</b> computer reaches them. Nothing is copied.</p>' +
      '<div class="seg"><button class="ghost" data-os="mac">This is a Mac</button><button class="ghost" data-os="pc">This is a PC</button>' +
        '<button class="ghost" data-os="other">Something else</button></div>' +
      '<input type="text" id="base" aria-label="Where this computer sees the archive">' +
      '<div class="dl"><button class="btn" data-f="premiere">For Premiere (XML)</button>' +
        '<button class="btn quiet" data-f="fcpxml">For Final Cut or Resolve (FCPXML)</button>' +
        '<button class="btn quiet" data-f="list">List of paths</button>' +
        '<button class="btn quiet" data-f="zip"' + (total > 1073741824 ? ' disabled title="Over 1 GB — use the Premiere file or the list; they point at the originals"' : '') + '>The files (zip)</button></div>' +
      '<p class="note" id="dlNote" style="margin:10px 0 0">Premiere opens the XML with File → Import, as a bin of clips. ' +
        'Final Cut opens the FCPXML with File → Import → XML, and DaVinci Resolve with File → Import → Timeline; ' +
        'clips that have no proxy yet are left out of it (their length is not known yet).' +
        (total > 1073741824 ? ' The zip is for small sets of stills; this pull is ' + tb(total) + '.' : '') + '</p></div>' +

    // Copy to work on (0.14): the clips themselves, copied to a faster drive than the archive. Only on the Mac Rushes runs on.
    ((D.places || []).length && here.length ?
    '<div class="card"><h2><span>04 /</span> Copy the clips to work on</h2>' +
      '<p class="note" style="margin:0 0 10px">The clips themselves, copied beside each other into <b>Rushes pulls / ' + esc(p.name) + '</b> on the drive you pick, to edit from there. ' +
        'Copies only: the originals stay in the archive, and the copies are yours to delete when the job is done.</p>' +
      '<div class="dl" style="align-items:center"><select id="copyTo" aria-label="Where to copy the clips">' +
        D.places.map(function (x) { return '<option value="' + esc(x.to) + '">' + esc(x.name) + (x.free ? ' · ' + tb(x.free) + ' free' : '') + '</option>'; }).join('') + '</select>' +
        '<button class="btn" id="copyGo">Copy ' + here.length + ' clip' + (here.length === 1 ? '' : 's') + ' · ' + tb(total) + '</button>' +
        '<button class="btn quiet" id="copyStop" hidden>Stop</button></div>' +
      '<div id="copyBar" hidden style="height:6px;border-radius:3px;background:var(--line);margin-top:12px;overflow:hidden"><i style="display:block;height:100%;width:0;background:var(--accent)"></i></div>' +
      '<p class="note" id="copySaid" style="margin:10px 0 0"></p></div>' : '');
  if ($('copyGo')) copyWire(total);

  // which computer this is — remembered, or guessed from the browser
  const saved = store.get('archiveBase');
  const guess = /Win/i.test(navigator.platform || navigator.userAgent) ? 'pc' : 'mac';
  setBase(saved ? (saved === PATHS.mac ? 'mac' : saved === PATHS.pc ? 'pc' : 'other') : guess, saved);
  document.querySelectorAll('[data-os]').forEach(function (b) { b.onclick = function () { setBase(b.dataset.os); }; });
  $('base').oninput = function () { store.set('archiveBase', $('base').value.trim()); };

  document.querySelectorAll('[data-f]').forEach(function (b) {
    b.onclick = function () {
      const base = $('base').value.trim();
      if (b.dataset.f !== 'zip' && !base) { $('base').focus(); return; }
      store.set('archiveBase', base);
      location.href = '/db/pull-export.php?p=' + encodeURIComponent(SLUG) + '&fmt=' + b.dataset.f + '&base=' + encodeURIComponent(base);
      const was = b.textContent; b.textContent = 'Downloading ✓';
      setTimeout(function () { b.textContent = was; }, 2000);
    };
  });
  document.querySelectorAll('.it [data-a]').forEach(function (b) {
    b.onclick = async function () {
      const rel = b.closest('.it').dataset.rel;
      if (b.dataset.a === 'remove' && !sure(b, 'Out of this pull; the file itself stays in the archive.', 'rm:' + rel)) return;
      try { await post({ action: b.dataset.a === 'remove' ? 'remove' : 'move', p: SLUG, rel: rel, dir: b.dataset.a }); show(); }
      catch (e) { b.textContent = 'Did not happen: ' + e.message; }
    };
  });
  // Renamed in place: the name becomes a text box, Enter (or Save) keeps it.
  $('rename').onclick = function () {
    const b = this;
    if (b.dataset.editing) {
      const n = $('pNameBox').value.trim();
      if (!n || n === p.name) { show(); return; }
      post({ action: 'rename', p: SLUG, name: n }).then(show).catch(function (e) { b.textContent = 'Did not happen: ' + e.message; });
      return;
    }
    b.dataset.editing = '1'; b.textContent = 'Save';
    $('pName').innerHTML = '<input id="pNameBox" maxlength="80" style="font:inherit;width:100%">';
    $('pNameBox').value = p.name; $('pNameBox').focus();
    $('pNameBox').onkeydown = function (e) { if (e.key === 'Enter') b.onclick(); if (e.key === 'Escape') show(); };
  };
  // Adding from Search goes into this pull from now on.
  store.set('pull', JSON.stringify({ slug: p.slug, name: p.name }));
}

function setBase(os, saved) {
  document.querySelectorAll('[data-os]').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.os === os); });
  $('base').hidden = os !== 'other';
  $('base').value = os === 'mac' ? PATHS.mac : os === 'pc' ? PATHS.pc : (saved || store.get('archiveBase') || '');
  $('base').placeholder = 'e.g. Z:\\  or  /mnt/video';
  if (os !== 'other') store.set('archiveBase', $('base').value);
}

(SLUG ? show() : listAll()).catch(function (e) {
  $('page').innerHTML = '<div class="card"><b>Rushes did not answer.</b><div class="code block">' + esc(e.message) + '</div></div>'; });
</script>
