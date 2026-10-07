<?php
// find.php — the search page. The one screen most people ever open.
//
// Results are grouped by shoot, because that is how people remember footage:
// not by folder and not by filename. A row says what a file is, how big, and
// where it lives; the panel beside it describes whichever one you picked.
//
// Files show no thumbnails yet; described moments show the still the model
// looked at (thumb.php). A file with a proxy plays it (play.php), and a
// described moment plays from its own time.
$NAV = 'search';
require __DIR__ . '/config.php';
?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Search &middot; Rushes</title>
<script>
  window.RUSHES = <?= json_encode([
    'archive' => archive_dir(),
    'local'   => s_path('archive.as_seen_from_helper', archive_dir()),
    // drives kept where they are: a path on one reads from the drive's name
    'drives'  => array_map(fn($d) => ['path' => rtrim($d['path'], '/'), 'name' => $d['name']], drives_seen()),
  ], JSON_UNESCAPED_SLASHES) ?>;
  // The same drawings the rail uses, handed to the script for the result rows.
  window.ICON = <?= json_encode([
    'search'  => icon('search', 1.9),
    'video'   => icon('video'),
    'image'   => icon('image'),
    'audio'   => icon('audio'),
    'project' => icon('project'),
    'sidecar' => icon('file'),
    'other'   => icon('file'),
    'file'    => icon('file'),
  ], JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php require __DIR__ . '/../head.php'; ?>
<style>
  .q { position: relative; margin: 0 0 12px }
  .q input {
    width: 100%; padding: 12px 14px 12px 38px; font: 15px var(--font);
    border: 1px solid var(--line); border-radius: var(--radius);
    background: var(--surface); color: var(--fg);
  }
  .q input:focus { border-color: var(--accent); outline: none;
                   box-shadow: 0 0 0 3px var(--sel-bg) }
  .q .mag { position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            color: var(--faint); pointer-events: none; display: block }
  .q .mag svg { width: 17px; height: 17px; display: block }
  .rail .nav.sub { padding-left: 38px; font-size: 12.5px }
  .chips { display: flex; gap: 6px; flex-wrap: wrap; margin: 0 0 12px }
  .chip { border: 1px solid var(--line); background: var(--surface); color: var(--muted);
          padding: 5px 12px; border-radius: 999px; font: 12.5px var(--font); cursor: pointer }
  .chip:hover { color: var(--fg) }
  .chip[aria-pressed="true"] { background: var(--accent); border-color: var(--accent);
                               color: var(--accent-fg); font-weight: 600 }
  .chip .n { opacity: .7; margin-left: 5px; font-variant-numeric: tabular-nums }
  .statline { display: flex; gap: 12px; align-items: center; color: var(--muted);
              font-size: 12.5px; margin: 0 0 14px }
  .shoot { margin: 0 0 12px }
  .pad { max-width: none }   /* results use the whole window, and the panel's column when nothing is picked */
  .results:has(> #inspect[hidden]) { grid-template-columns: minmax(0, 1fr) }
  .row.pick.on { box-shadow: inset 3px 0 0 var(--accent) }
  .results { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 16px; align-items: start }
  @media (max-width: 1100px) { .results { grid-template-columns: minmax(0, 1fr) } .inspect { display: none } }
  @media (max-width: 640px) { .shoot .sh .n { display: none } .row { gap: 8px; padding-left: 10px; padding-right: 10px } }
  .inspect { position: sticky; top: 16px }
  .inspect .k { font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em;
                color: var(--faint); font-weight: 650; margin-top: 12px }
  .inspect .v { font-size: 13px; overflow-wrap: anywhere }
  .more { display: block; width: 100%; margin: 16px 0; padding: 10px;
          border-radius: var(--radius-sm); border: 1px solid var(--line);
          background: var(--surface); color: var(--fg); font: 13.5px var(--font); cursor: pointer }
  .dim { opacity: .5 }
  .pullbar { position: fixed; left: 50%; bottom: 16px; transform: translateX(-50%); z-index: 20;
             display: flex; align-items: center; gap: 12px; padding: 10px 12px 10px 14px;
             background: var(--surface); border: 1px solid var(--accent); border-radius: 12px;
             box-shadow: 0 8px 30px rgba(0,0,0,.35); max-width: calc(100vw - 32px) }
  .pullbar .pb-ico svg { width: 20px; height: 20px; color: var(--accent-text); display: block }
  .pullbar .pb-t { min-width: 0 }
  .pullbar .pb-t b { display: block; font-size: 13.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 44vw }
  .pullbar .pb-t small { color: var(--muted); font-size: 12px }
  dialog#pullDlg { width: min(460px, calc(100vw - 32px)); border: 1px solid var(--line); border-radius: 14px;
    padding: 18px 20px 20px; background: var(--surface); color: var(--fg) }
  dialog#pullDlg::backdrop { background: rgba(10, 22, 21, .6) }
  dialog#playDlg { width: min(960px, calc(100vw - 32px)); border: 1px solid var(--line); border-radius: 14px;
                   background: var(--panel); color: var(--fg); padding: 14px }
  dialog#playDlg::backdrop { background: rgba(10, 22, 21, .75) }
  dialog#playDlg video, .inspect video { width: 100%; border-radius: 8px; background: #000; display: block }
  #plStart[hidden] { display: none }
  #pullDlg .dh { display: flex; align-items: center; margin: 0 0 8px } #pullDlg .dh b { flex: 1; font-size: 15px }
  .pd-pick { display: flex; width: 100%; justify-content: space-between; gap: 10px; padding: 9px 12px; margin: 0 0 6px;
             border: 1px solid var(--line); border-radius: 9px; background: var(--bg); color: var(--fg);
             font: 13.5px var(--font); cursor: pointer; text-align: left }
  .pd-pick:hover { border-color: var(--accent) }
  .pd-pick small { color: var(--muted) }
  .pd-new { border-top: 1px solid var(--line); margin-top: 12px; padding-top: 12px }
  #pullDlg label.f { margin-bottom: 10px }
  .work { padding-bottom: 80px }
  .sh { align-items: flex-start !important }
  .shn { min-width: 0 }
  /* a folder heading is where the files are, read as a trail: not a button */
  .shoot > header.sh { background: none; border-bottom: 1px solid var(--line) }
  .shn { color: var(--muted); font-size: 12px; font-weight: 400; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
  .shn b { color: var(--fg); font-size: 13px; font-weight: 600 }
  .shn .sep { color: var(--faint); margin: 0 5px }
  /* Where a picture will go once proxies exist. Until then it is the kind,
     drawn — so the list has rhythm instead of being a wall of names. */
  .thumb { width: 34px; height: 34px; border-radius: 6px; flex: none; display: grid;
           place-items: center; background: var(--raised); color: var(--muted) }
  .thumb svg { width: 17px; height: 17px }
  .thumb.pic { width: 60px; overflow: hidden; background: var(--line) }
  .thumb.pic img { width: 100%; height: 100%; object-fit: cover; display: block }
  .row .side { color: var(--faint); font-size: 11px }
  .pk { display: none; color: var(--ok); font-size: 11px; font-weight: 600; margin-left: 6px }
  .pulled .pk { display: inline }
  /* the menu a right click opens */
  .ctx { position: fixed; z-index: 30; min-width: 190px; padding: 5px; background: var(--surface);
         border: 1px solid var(--line); border-radius: 10px; box-shadow: 0 8px 30px rgba(0,0,0,.3) }
  .ctx button { display: block; width: 100%; text-align: left; padding: 7px 10px; border: 0; border-radius: 6px;
                background: none; color: var(--fg); font: 13px var(--font); cursor: pointer }
  .ctx button:hover { background: var(--raised) }
  .ctx button:disabled { color: var(--faint); cursor: default; background: none }
  #selbar[hidden] { display: none }
  #selbar { display: flex; gap: 8px; align-items: center; color: var(--fg) }
  #said { color: var(--ok) }
  .row.on .thumb { background: rgba(19,43,42,.12); color: var(--sel-fg) }
  .k-video, .k-image, .k-audio { color: var(--accent-text) }
  .row { padding-top: 6px; padding-bottom: 6px }
  /* moments: what the footage shows and what was said, found inside the files */
  .moments { margin: 0 0 16px }
  .moments > header { display: flex; justify-content: space-between; align-items: baseline; padding: 12px 16px }
  .mgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; padding: 0 16px 16px }
  /* a moment: the picture, one line of what it shows, the file. Tags and the rest are in the panel beside */
  .mo { border: 1px solid var(--line); border-radius: var(--radius-sm); overflow: hidden; background: var(--bg);
        cursor: pointer; user-select: none }
  .mo:hover { border-color: var(--muted) }
  .mo.on { border-color: var(--accent); box-shadow: 0 0 0 2px var(--accent) }
  .mo .pic { position: relative }
  .mo img, .mo .said { display: block; width: 100%; aspect-ratio: 16 / 9; object-fit: cover; background: var(--line) }
  .mo .said { display: flex; align-items: center; justify-content: center; font-size: 26px; color: var(--muted) }
  .mo .tc { position: absolute; left: 6px; bottom: 6px; padding: 1px 6px; border-radius: 4px;
            background: rgba(0,0,0,.6); color: #fff; font: 11px var(--mono, monospace) }
  .mo .b { padding: 7px 10px 9px; font-size: 12.5px; line-height: 1.4 }
  .mo .w { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden }
  .mo small { display: block; margin-top: 4px; color: var(--faint); font-size: 11px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
  .row.pick { user-select: none }
  .inspect .ons { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px }
  .inspect .ons span { font-size: 11px; padding: 1px 7px; border-radius: 99px; border: 1px solid var(--line); color: var(--muted) }
  .inspect img.big { width: 100%; border-radius: 8px; display: block }
</style>

<div class="app" style="grid-template-rows:1fr">
<div class="with-rail">

  <nav class="rail" aria-label="Where to look">
    <h2>Browse</h2>
    <button class="nav" data-scope="" aria-current="page" title="every file the archive knows about"><span class="ico"><?= icon('everything') ?></span> Everything</button>
    <button class="nav" data-scope="ARCHIVE" title="finished shoots, kept"><span class="ico"><?= icon('archive') ?></span> Archive</button>
    <button class="nav" data-scope="PROJECTS" title="edits and project files"><span class="ico"><?= icon('projects') ?></span> Projects</button>
    <!-- What each file is, wherever it is (labels.php): from names, folders and sizes; nothing moved -->
    <h2>What it is</h2>
    <button class="nav" data-scope="" data-in="deliverables" title="finished videos: anything in an Output folder"><span class="ico"><?= icon('video') ?></span> Deliverables</button>
    <button class="nav" data-scope="" data-in="library" title="bought or downloaded: stock footage, music, sound effects, templates, graphics"><span class="ico"><?= icon('library') ?></span> Stock library</button>
    <button class="nav sub" data-scope="" data-in="library/stock">Stock footage</button>
    <button class="nav sub" data-scope="" data-in="library/music">Music</button>
    <button class="nav sub" data-scope="" data-in="library/sfx">Sound effects</button>
    <button class="nav sub" data-scope="" data-in="library/templates">Templates</button>
    <button class="nav sub" data-scope="" data-in="library/graphics">Graphics</button>
    <button class="nav" data-scope="" data-in="ai" title="made with an AI tool (OpenArt …)"><span class="ico"><?= icon('ai') ?></span> AI-generated</button>
    <button class="nav" data-scope="" data-in="made" title="intros, animations and other parts rendered for an edit"><span class="ico"><?= icon('project') ?></span> Graphics &amp; animation</button>
    <button class="nav" data-scope="" data-in="camera" title="what the cameras and drones shot"><span class="ico"><?= icon('camera') ?></span> Camera footage</button>
    <button class="nav" data-scope="" data-in="photos" title="photos and camera raws"><span class="ico"><?= icon('image') ?></span> Photos</button>
    <button class="nav" data-scope="" data-in="design" title="flyers, logos and graphics: editable files (.psd, .ai) and finished ones"><span class="ico"><?= icon('design') ?></span> Design</button>
    <button class="nav" data-scope="" data-in="voiceover" title="voice over recordings"><span class="ico"><?= icon('audio') ?></span> Voice over</button>
    <button class="nav" data-scope="" data-in="recordings" title="Zoom and screen recordings"><span class="ico"><?= icon('video') ?></span> Recordings</button>
    <h2>Pulls</h2>
    <div id="pulls"></div>
    <a class="nav" href="/pull.php"><span class="ico"><?= icon('everything') ?></span> All pulls</a>
    <h2>Recent searches</h2>
    <div id="recent"></div>
    <div class="rail-foot">
      <span id="fFiles">&mdash;</span>
      <div class="meter"><i id="fMeter"></i></div>
      <span id="fFree">&mdash;</span>
    </div>
  </nav>

  <main class="work">
    <div class="pad">
      <div class="q">
        <span class="mag" aria-hidden="true"><?= icon('search', 1.9) ?></span>
        <input id="q" autofocus autocomplete="off" aria-label="Search the archive"
               placeholder="a name, a folder, an event" title="Every word you type must match">
      </div>
      <div class="chips" id="chips"></div>
      <div class="statline"><span id="stat">Start typing.</span>
        <span id="selbar" hidden><b id="selN"></b><button class="btn" id="selAdd"></button><button class="ghost" id="selClear">Clear</button></span>
        <span id="said" role="status"></span>
        <span class="grow"></span><span id="ms"></span></div>

      <div class="results">
        <div id="out"></div>
        <aside class="panel inspect" id="inspect" hidden></aside>
      </div>
    </div>
  </main>
</div>
</div>

<!-- The pull being filled. Shown once there is one; stays put while you
     search, so clips from several searches end up together. -->
<div class="pullbar" id="pullbar" hidden>
  <span class="pb-ico"><?= icon('archive') ?></span>
  <span class="pb-t"><b id="pbName"></b><small id="pbMeta"></small></span>
  <a class="btn" id="pbOpen" href="#">Open</a>
  <button class="ghost" id="pbSwitch">Switch</button>
</div>

<dialog id="playDlg" aria-labelledby="plT">
  <div class="dh"><b id="plT"></b><button type="button" class="ghost" id="plStart">From the start</button><button type="button" class="ghost" id="plClose">Close</button></div>
  <video id="plV" controls playsinline preload="metadata"></video>
  <p class="note" id="plSaid" style="margin:8px 0 0"></p>
</dialog>
<div class="ctx" id="ctx" hidden role="menu"></div>
<dialog id="pullDlg" aria-labelledby="pdT">
  <div class="dh"><b id="pdT">Add to a pull</b><button type="button" class="ghost" id="pdClose">Close</button></div>
  <p class="note" style="margin:0 0 12px">A pull gathers clips for a job. Send its link to whoever edits.</p>
  <div id="pdList"></div>
  <div class="pd-new">
    <label class="f"><span>Or start a new one</span><input type="text" id="pdName" placeholder="e.g. Harbor documentary"></label>
    <label class="f"><span>Your name</span><input type="text" id="pdBy" placeholder="so the editor knows who gathered it"></label>
    <button class="btn" id="pdCreate" disabled>Start it</button>
  </div>
</dialog>

<script>
const ARCHIVE = (window.RUSHES && RUSHES.archive) || '';
const LOCAL   = (window.RUSHES && RUSHES.local)   || ARCHIVE;
const ICON    = window.ICON || {};

const $   = function (i) { return document.getElementById(i); };
const esc = function (s) { return (s || '').replace(/[&<>"]/g, function (c) {
  return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
const tb  = function (b) {
  return b >= 1099511627776 ? (b / 1099511627776).toFixed(2) + ' TB'
       : b >= 1073741824    ? (b / 1073741824).toFixed(1) + ' GB'
       : b >= 1048576       ? Math.round(b / 1048576) + ' MB'
       : Math.round(b / 1024) + ' KB';
};
const short = function (p) {
  p = p || '';
  const d = (RUSHES.drives || []).find(function (x) { return p.indexOf(x.path + '/') === 0; });
  return d ? d.name + '/' + p.slice(d.path.length + 1) : p.replace(ARCHIVE + '/', '');
};
// Where a shoot sits, as a readable trail — the filename is on its own row.
const trail = function (p) {
  const bits = short(p).split('/'); bits.pop();
  return bits.join('  ›  ');
};

const KINDS = ['all', 'video', 'image', 'audio', 'project', 'sidecar', 'other'];
let kind = 'all', scope = '', place = '', rows = [], total = 0, offset = 0, seq = 0, timer = null, moments = { count: 0, rows: [] };

document.querySelectorAll('.rail [data-scope]').forEach(function (b) {
  b.onclick = function () {
    scope = b.dataset.scope; place = b.dataset.in || '';
    document.querySelectorAll('.rail [data-scope]').forEach(function (x) {
      if (x === b) x.setAttribute('aria-current', 'page'); else x.removeAttribute('aria-current');
    });
    run();
  };
});

async function run(more) {
  const my = ++seq;
  if (!more) { offset = 0; rows = []; }
  const words = ($('q').value.trim() + ' ' + scope).trim();
  const p = new URLSearchParams({ q: words, kind: kind, limit: 200, offset: offset, in: place });
  $('stat').textContent = words || place ? 'searching…' : 'Start typing.';

  let raw;
  try { raw = await (await fetch('search.php?' + p)).text(); }
  catch (e) { return fail('Could not reach the archive.', e.message); }

  let d;
  try { d = JSON.parse(raw); }
  catch (e) { return fail('The archive answered, but not with a result.', raw.slice(0, 400)); }
  if (d.error) return fail('The search could not run.', d.error);
  if (my !== seq) return;                         // a newer keystroke already won

  total = d.total; rows = more ? rows.concat(d.rows) : d.rows;
  if (!more) moments = d.moments || { count: 0, rows: [] };
  $('ms').textContent = d.ms + ' ms';

  $('chips').innerHTML = KINDS.filter(function (k) { return k === 'all' || d.counts[k]; })
    .map(function (k) {
      return '<button class="chip" aria-pressed="' + (k === kind) + '" data-k="' + k + '">' + k +
        (k === 'all' ? '' : '<span class="n">' + (d.counts[k] || 0).toLocaleString() + '</span>') +
        '</button>';
    }).join('');
  $('chips').querySelectorAll('.chip').forEach(function (b) {
    b.onclick = function () { kind = b.dataset.k; run(); };
  });

  // Moments found inside the footage count too: "0 files" above a page of them read as a bug
  const mc = moments.count;
  $('stat').textContent = !(words || place) ? 'Start typing.'
    : (mc ? mc.toLocaleString() + ' moment' + (mc === 1 ? '' : 's') + ' in the footage · ' : '') +
      (total ? total.toLocaleString() + ' file' + (total === 1 ? '' : 's') + (d.bytes ? ' · ' + tb(d.bytes) : '')
             : mc ? 'no file names match' : '0 files');
  clearSel();
  draw();
  remember($('q').value.trim());
}

function fail(what, detail) {
  $('stat').textContent = '';
  $('out').innerHTML = '<div class="panel"><div class="empty"><b>' + esc(what) + '</b>' +
    '<div class="code block" style="text-align:left;margin-top:10px">' + esc(detail) + '</div></div></div>';
}

// Moments: shots whose description, on-screen text or themes match, and lines
// of speech — each with its time in the file and the picture of that shot.
function tcode(s) { s = Math.floor(s || 0); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); }
function momentsHTML() {
  if (!moments.rows.length) return '';
  return '<div class="panel moments"><header><b>In the footage</b><span class="note">' +
    moments.count.toLocaleString() + ' moment' + (moments.count === 1 ? '' : 's') +
    (moments.count > moments.rows.length ? ' · the first ' + moments.rows.length : '') + '</span></header><div class="mgrid">' +
    moments.rows.map(function (m, i) {
      const speech = m.kind === 'speech';
      return '<div class="mo" data-p="' + esc(m.path) + '" data-k="m' + i + '" data-t="' + (+m.start_s || 0) + '" title="Double-click to play from here">' +
        '<div class="pic">' + (speech ? '<div class="said">“ ”</div>'
                : '<img loading="lazy" alt="" src="thumb.php?fp=' + encodeURIComponent(m.fp) + '&shot=' + m.shot + '">') +
        '<span class="tc">' + tcode(m.start_s) + '</span></div>' +
        '<div class="b"><div class="w">' + (speech ? '“' + esc(m.what) + '”' : esc(m.what)) + '</div>' +
        '<small title="' + esc(m.path) + '">' + esc(m.path.split('/').pop()) + '<span class="pk">✓ in the pull</span></small></div></div>';
    }).join('') + '</div></div>';
}

// Double-click plays the file's proxy from the moment found, with a way back to its start.
function play(path, t) {
  const v = $('plV');
  $('plT').textContent = path.split('/').pop() + (t ? ' · from ' + tcode(t) : '');
  $('plStart').hidden = !t;
  $('plSaid').textContent = 'Loading its proxy from the archive…';
  v.onloadeddata = function () { $('plSaid').textContent = 'Playing its proxy (the original stays where it is).'; };
  v.onerror = function () { $('plSaid').textContent = 'No proxy to play yet. Proxies are made in Manage → Describe.'; };
  v.src = 'play.php?p=' + encodeURIComponent(path) + (t ? '#t=' + t : '');
  $('playDlg').showModal();
  v.play().catch(function () {});
}
$('plStart').onclick = function () { const v = $('plV'); v.currentTime = 0; v.play().catch(function () {}); $('plStart').hidden = true;
  $('plT').textContent = $('plT').textContent.replace(/ · from .*$/, ''); };
$('plClose').onclick = function () { $('plV').pause(); $('plV').removeAttribute('src'); $('plV').load(); $('playDlg').close(); };
$('playDlg').addEventListener('close', function () { $('plV').pause(); });

// ── choosing ──────────────────────────────────────────────────────────────
// One click picks a file or a moment and shows it beside; nothing opens.
// Cmd/Ctrl-click adds or removes one, Shift-click takes a run. Double-click
// plays. A right click offers what can be done with whatever is picked.
const SEL = new Set(); let lastEl = null;
const items = function () { return Array.prototype.slice.call($('out').querySelectorAll('[data-k]')); };
const picked = function () { return items().filter(function (x) { return SEL.has(x.dataset.k); }); };
const playable = function (el) { return el.classList.contains('mo') || (rowOf(el) || {}).kind === 'video'; };
function rowOf(el) { return rows.find(function (x) { return x.path === el.dataset.p; }); }
function clearSel() { SEL.clear(); lastEl = null; drawSel(); }
function drawSel() {
  items().forEach(function (x) { x.classList.toggle('on', SEL.has(x.dataset.k)); });
  const n = SEL.size, paths = selPaths();
  $('selbar').hidden = n < 2;
  if (n >= 2) {
    $('selN').textContent = n + ' picked';
    $('selAdd').textContent = paths.length ? 'Add ' + paths.length + ' to pull' : 'Not in the archive: cannot be pulled';
    $('selAdd').disabled = !paths.length;
    $('inspect').hidden = false;
    $('inspect').innerHTML = '<header><b>' + n + ' picked</b></header><div style="padding:12px 14px">' +
      '<p class="note" style="margin:0">Double-click one to play it. Right-click for more.</p></div>';
  }
}
// what the picked things add to a pull: each file once, and only files in the archive
function selPaths() {
  const seen = {};
  picked().forEach(function (x) { if (pullable(x.dataset.p)) seen[x.dataset.p] = 1; });
  return Object.keys(seen);
}
function choose(el, e) {
  if (e && (e.metaKey || e.ctrlKey)) { if (SEL.has(el.dataset.k)) SEL.delete(el.dataset.k); else SEL.add(el.dataset.k); }
  else if (e && e.shiftKey && lastEl && document.contains(lastEl)) {
    const all = items(), a = all.indexOf(lastEl), b = all.indexOf(el);
    all.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(function (x) { SEL.add(x.dataset.k); });
  } else { SEL.clear(); SEL.add(el.dataset.k); }
  if (!(e && e.shiftKey)) lastEl = el;
  drawSel();
  if (SEL.size === 1) {
    const one = picked()[0];
    if (one.classList.contains('mo')) inspectMoment(moments.rows[+one.dataset.k.slice(1)]); else inspect(rowOf(one));
  } else if (!SEL.size) $('inspect').hidden = true;
}
$('out').addEventListener('click', function (e) {
  const el = e.target.closest('[data-k]'); if (el) choose(el, e);
});
$('out').addEventListener('dblclick', function (e) {
  const el = e.target.closest('[data-k]'); if (!el) return;
  if (el.classList.contains('mo')) play(el.dataset.p, +el.dataset.t || 0);
  else if (playable(el)) play(el.dataset.p, 0);
});
$('out').addEventListener('contextmenu', function (e) {
  const el = e.target.closest('[data-k]'); if (!el) return;
  e.preventDefault();
  if (!SEL.has(el.dataset.k)) choose(el, null);
  const paths = selPaths(), one = SEL.size === 1;
  const m = $('ctx');
  m.innerHTML =
    (one && playable(el) ? '<button data-do="play">Play' + (el.dataset.t && +el.dataset.t ? ' from ' + tcode(+el.dataset.t) : '') + '</button>' : '') +
    (one && el.dataset.t && +el.dataset.t ? '<button data-do="start">Play from the start</button>' : '') +
    '<button data-do="pull"' + (paths.length ? '' : ' disabled') + '>' +
      (paths.length ? (PULL && PULL.name ? 'Add ' + (paths.length > 1 ? paths.length + ' ' : '') + 'to ' + esc(PULL.name) : 'Add ' + (paths.length > 1 ? paths.length + ' ' : '') + 'to a pull…')
                    : 'Not in the archive: cannot be pulled') + '</button>' +
    (one ? '<button data-do="copy">Copy path</button>' : '');
  m.hidden = false;
  m.style.left = Math.min(e.clientX, innerWidth - m.offsetWidth - 8) + 'px';
  m.style.top  = Math.min(e.clientY, innerHeight - m.offsetHeight - 8) + 'px';
  m.onclick = function (ev) {
    const b = ev.target.closest('[data-do]'); if (!b || b.disabled) return;
    m.hidden = true;
    if (b.dataset.do === 'play')  play(el.dataset.p, +el.dataset.t || 0);
    if (b.dataset.do === 'start') play(el.dataset.p, 0);
    if (b.dataset.do === 'pull')  addToPull(paths, null);
    if (b.dataset.do === 'copy')  copyText(localPath(el.dataset.p), function (ok) { say(ok ? 'Path copied.' : 'The browser would not copy: press ⌘C'); });
  };
});
document.addEventListener('click', function (e) { if (!e.target.closest('#ctx')) $('ctx').hidden = true; });
document.addEventListener('scroll', function () { $('ctx').hidden = true; }, true);
document.addEventListener('keydown', function (e) {
  if (e.key !== 'Escape' || document.querySelector('dialog[open]')) return;
  if (!$('ctx').hidden) { $('ctx').hidden = true; return; }
  clearSel(); $('inspect').hidden = true;
});
$('selAdd').onclick = function () { addToPull(selPaths(), $('selAdd')); };
$('selClear').onclick = function () { clearSel(); $('inspect').hidden = true; };
// every action says it happened, here, for a few seconds
let saidT = null;
function say(t) { $('said').textContent = t; clearTimeout(saidT); saidT = setTimeout(function () { $('said').textContent = ''; }, 4000); }

// Settings and notes a camera or an app writes beside a file
const SIDE = /\.(xmp|sii|cpf|thm|cos|cop|cof|cot|comask)$/i;
const stem = function (n) { return n.toLowerCase().replace(/\.[^.]+$/, ''); };

function draw() {
  if (!rows.length && moments.rows.length) { $('out').innerHTML = momentsHTML(); return; }
  if (!rows.length) {
    $('out').innerHTML = '<div class="panel"><div class="empty">' +
      ($('q').value.trim() ? 'Nothing matches.' : 'Type a few words above.') + '</div></div>';
    return;
  }
  // One panel per shoot, with its own count and size: a shoot reads as a thing,
  // not as a run of lines that happen to sit next to each other.
  const groups = [];
  rows.forEach(function (r) {
    const ev = r.event || 'no event folder';
    const g  = groups[groups.length - 1];
    if (g && g.ev === ev) g.rows.push(r); else groups.push({ ev: ev, year: r.year, rows: [r] });
  });

  let html = momentsHTML();
  groups.forEach(function (g) {
    // A sidecar (.xmp, Capture One's .cos …) sits under the file it belongs to, not as a row of its own
    const byName = {}, side = {};
    g.rows.forEach(function (r) { if (!SIDE.test(r.name)) byName[r.name.toLowerCase()] = byName[stem(r.name)] = r; });
    const shown = g.rows.filter(function (r) {
      if (!SIDE.test(r.name)) return true;
      const host = byName[r.name.toLowerCase().replace(/\.[^.]+$/, '')] || byName[stem(r.name)];
      if (!host) return true;
      (side[host.path] = side[host.path] || []).push(r.name); return false;
    });
    const gb = g.rows.reduce(function (n, r) { return n + (r.bytes || 0); }, 0);
    // The heading is where these files are, as a trail with the shoot in bold
    const bits = trail(shown[0].path).split('  ›  ').filter(Boolean);
    html += '<div class="panel shoot"><header class="sh"><div class="shn" title="' + esc(short(shown[0].path)) + '">' +
      bits.map(function (b) { return b === g.ev ? '<b>' + esc(b) + '</b>' : esc(b); }).join('<span class="sep">›</span>') +
      (bits.indexOf(g.ev) < 0 && g.ev !== 'no event folder' ? '<span class="sep">·</span><b>' + esc(g.ev) + '</b>' : '') + '</div>' +
      '<span class="n">' + g.rows.length.toLocaleString() + ' file' +
      (g.rows.length === 1 ? '' : 's') + (gb ? ' · ' + tb(gb) : '') + '</span></header>';
    shown.forEach(function (r) {
      const moved = r.path.indexOf('/_duplicates/') > -1 || r.path.indexOf('/_Recently Removed/') > -1;
      const k = r.kind || 'other', st = (r.still || '').split(':');
      const sc = side[r.path] || [];
      html += '<div class="row pick' + (moved ? ' dim' : '') + (inPull(r.path) ? ' pulled' : '') + '" data-p="' + esc(r.path) + '" data-k="' + esc(r.path) + '"' +
        (k === 'video' ? ' title="Double-click to play"' : '') + '>' +
        (st.length === 2 ? '<span class="thumb pic"><img loading="lazy" alt="" src="thumb.php?fp=' + encodeURIComponent(st[0]) + '&shot=' + (+st[1]) + '"></span>'
                         : '<span class="thumb k-' + esc(k) + '">' + (ICON[k] || ICON.file || '') + '</span>') +
        '<span class="nm">' + esc(r.name) + '<span class="pk">✓ in the pull</span>' +
        (moved ? ' <span class="pill">in Recently Removed</span>' : '') +
        // on a drive kept where it is; one that is not plugged in says so
        (r.drive ? ' <span class="pill"' + (r.away ? ' title="Plug in ' + esc(r.drive) + ' to open it"' : '') + '>on ' + esc(r.drive) +
          (r.away ? ' · not plugged in' : '') + '</span>' : '') +
        '<small>' + esc((r.ext || '').toUpperCase()) + (r.ext ? ' · ' : '') + esc(k) +
        (res(r) ? ' · <b class="res">' + res(r) + '</b>' : '') +
        (sc.length ? ' · <span class="side" title="' + esc(sc.join('\n')) + '">+ ' + sc.length + ' sidecar' + (sc.length === 1 ? '' : 's') + '</span>' : '') +
        '</small></span>' +
        '<span class="sz">' + tb(r.bytes) + '</span></div>';
    });
    html += '</div>';
  });
  if (rows.length < total) {
    html += '<button class="more" id="more">show 200 more (' +
      (total - rows.length).toLocaleString() + ' left)</button>';
  }
  $('out').innerHTML = html;

  drawSel();
  const m = $('more');
  if (m) m.onclick = function () { offset = rows.length; run(true); };
}

// The panel beside the results: what this file is, and the two things you
// actually want to do with it: put it in a pull, or copy its path.
// What the original is, however small its proxy: 4K, HD, 720p or SD.
// "2024-05-03 14:22:10" as the camera wrote it -> "3 May 2024, 2:22 pm · afternoon".
// Never converted between time zones: cameras disagree about which one they mean.
function recorded(t) {
  const m = /^(\d{4})-(\d\d)-(\d\d) (\d\d):(\d\d)/.exec(t || '');
  if (!m) return t;
  const h = +m[4], day = new Date(+m[1], +m[2] - 1, +m[3]).toLocaleDateString([], {day: 'numeric', month: 'short', year: 'numeric'});
  const part = h < 5 ? 'night' : h < 12 ? 'morning' : h < 17 ? 'afternoon' : h < 21 ? 'evening' : 'night';
  return day + ', ' + ((h % 12) || 12) + ':' + m[5] + (h < 12 ? ' am' : ' pm') + ' · ' + part;
}
// "oldserver|1|1727700000;…" -> how many copies, and where. One is not a warning:
// it is this file, the original, and nothing else on record.
function copiesText(s) {
  const day = function (t) { return new Date(t * 1000).toLocaleDateString([], {day: 'numeric', month: 'short'}); };
  const rows = (s || '').split(';').filter(Boolean).map(function (x) { const f = x.split('|'); return {place: f[0], there: f[1] === '1', at: +f[2]}; });
  const there = rows.filter(function (x) { return x.there; });
  if (there.length) return (1 + there.length) + ' — this one, and ' +
    there.map(function (x) { return 'one on ' + esc(x.place) + ' (seen ' + day(x.at) + ')'; }).join(', ');
  if (rows.length) return '<b>1 — this one.</b> The copy on ' +
    rows.map(function (x) { return esc(x.place) + ' was gone when checked on ' + day(x.at); }).join(', ');
  return '1 — this file. No other copy on record.';
}
function res(r) {
  if (!r.width) return '';
  const w = Math.max(r.width, r.height), h = Math.min(r.width, r.height);
  return w >= 3800 || h >= 2100 ? '4K' : w >= 2500 || h >= 1400 ? '2.7K' : h >= 1060 ? 'HD' : h >= 700 ? '720p' : 'SD';
}
function clock(s) {
  s = Math.round(s); const h = Math.floor(s / 3600), m = Math.floor(s / 60) % 60, x = s % 60;
  return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(x).padStart(2, '0');
}

// The path as this computer reaches it, for Copy path
function localPath(p) {
  const mine = store('archiveBase');
  return !p.startsWith(ROOT) ? p : mine ? (/^([A-Za-z]:|\\\\)/.test(mine)
      ? mine + '\\' + short(p).replace(/\//g, '\\') : mine + '/' + short(p))
    : p.replace(ARCHIVE, LOCAL);
}
const pullable = function (p) { return p.startsWith(ROOT) && !(RUSHES.drives || []).some(function (d) { return p.startsWith(d.path + '/'); }); };

// A moment picked: its picture, everything the model said about it, and what to do with it
function inspectMoment(m) {
  if (!m) return;
  const speech = m.kind === 'speech', t = +m.start_s || 0;
  const line = function (k, v) { return v ? '<div class="k">' + k + '</div><div class="v">' + esc(v) + '</div>' : ''; };
  const tags = function (k, v) { return v ? '<div class="k">' + k + '</div><div class="ons">' +
    v.split(' · ').map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('') + '</div>' : ''; };
  $('inspect').hidden = false;
  $('inspect').innerHTML = '<header><b>' + esc(m.path.split('/').pop()) + '</b></header><div style="padding:12px 14px">' +
    (speech ? '' : '<img class="big" alt="" src="thumb.php?fp=' + encodeURIComponent(m.fp) + '&shot=' + m.shot + '">') +
    '<div class="k">' + (speech ? 'Said' : 'Shot ' + ((+m.shot || 0) + 1)) + '</div><div class="v">' + tcode(m.start_s) + ' → ' + tcode(m.end_s) +
      (speech && m.language ? ' · ' + esc(m.language) : '') + '</div>' +
    '<div class="k">' + (speech ? 'Words' : 'What it shows') + '</div><div class="v">' + esc(m.what) + '</div>' +
    tags('Text on screen', m.on_screen) + tags('Themes', m.themes) + tags('Tags', m.tags) +
    line('Shot size', m.shot_size) + line('People', m.people) + line('Light', m.light) + line('Mood', m.mood) +
    line('Part of the day', m.part_of_day) +
    '<div class="k">Where it lives</div><div class="v">' + esc(short(m.path)) + '</div>' +
    '<div class="btns" style="margin-top:14px">' +
      '<button class="btn" id="iPlay">Play from ' + tcode(t) + '</button>' +
      (t ? '<button class="btn quiet" id="iStart">From the start</button>' : '') +
      (pullable(m.path) ? '<button class="btn quiet" id="iPull">' + (inPull(m.path) ? 'In the pull ✓' : 'Add to pull') + '</button>' : '') +
      '<button class="btn quiet" id="iCopy">Copy path</button>' +
    '</div></div>';
  $('iPlay').onclick = function () { play(m.path, t); };
  if ($('iStart')) $('iStart').onclick = function () { play(m.path, 0); };
  if ($('iPull')) $('iPull').onclick = function () { addToPull([m.path], $('iPull')); };
  $('iCopy').onclick = function () { copyText(localPath(m.path), $('iCopy')); };
}

function inspect(r) {
  if (!r) return;
  const local = r.drive ? r.path : localPath(r.path);
  $('inspect').hidden = false;
  $('inspect').innerHTML =
    '<header><b>' + esc(r.name) + '</b></header>' +
    '<div style="padding:12px 14px">' +
      (r.proxy_at ? '<video controls playsinline preload="metadata" src="play.php?p=' + encodeURIComponent(r.path) + '"></video>' +
                    '<p class="note" style="margin:6px 0 12px">Its proxy, read from the archive as it plays.</p>' : '') +
      '<div class="k">Kind</div><div class="v">' + esc(r.kind || 'file') +
        (r.ext ? ' · ' + esc(r.ext.toUpperCase()) : '') + '</div>' +
      '<div class="k">Size</div><div class="v">' + tb(r.bytes) + '</div>' +
      (r.width ? '<div class="k">Original</div><div class="v">' + res(r) + ' · ' + r.width + ' × ' + r.height +
        (r.fps ? ' · ' + (+r.fps).toFixed(2).replace(/\.?0+$/, '') + ' fps' : '') +
        (r.codec ? ' · ' + esc(r.codec.toUpperCase()) : '') +
        (r.duration ? ' · ' + clock(r.duration) : '') + '</div>' : '') +
      (r.recorded ? '<div class="k">Recorded</div><div class="v">' + esc(recorded(r.recorded)) + ' <small>(the camera\'s clock)</small></div>' : '') +
      (r.camera ? '<div class="k">Camera</div><div class="v">' + esc(r.camera) + '</div>' : '') +
      (r.timecode || r.reel ? '<div class="k">Timecode</div><div class="v">' + esc([r.timecode, r.reel ? 'reel ' + r.reel : ''].filter(Boolean).join(' · ')) + '</div>' : '') +
      '<div class="k">Copies</div><div class="v">' + copiesText(r.copies) + '</div>' +
      (r.proxy_at ? '<div class="k">Plays from</div><div class="v">its proxy (downloads and pulls use the original)</div>' : '') +
      (r.event ? '<div class="k">Shoot</div><div class="v">' + esc(r.event) + '</div>' : '') +
      (r.year  ? '<div class="k">Year</div><div class="v">' + esc(r.year) + '</div>' : '') +
      (r.drive ? '<div class="k">Drive</div><div class="v">' + esc(r.drive) +
        (r.away ? ' &mdash; <b>not plugged in</b>: plug it in to open this file' : ' (plugged in)') + '</div>' : '') +
      '<div class="k">Where it lives</div><div class="v">' + esc(short(r.path)) + '</div>' +
      '<div class="btns" style="margin-top:14px">' +
        (r.drive ? '' : '<button class="btn" id="iPull">' + (inPull(r.path) ? 'In the pull ✓' : 'Add to pull') + '</button>') +
        '<button class="btn quiet" id="iCopy">Copy path</button>' +
      '</div>' +
    '</div>';
  $('iCopy').onclick = function () { copyText(local, $('iCopy')); };
  if ($('iPull')) $('iPull').onclick = function () { addToPull([r.path], $('iPull')); };
}

// A short memory of what you looked for, kept in this browser only.
function remember(words) {
  if (!words || words.length < 3) return;
  let list = [];
  try { list = JSON.parse(localStorage.getItem('recent') || '[]'); } catch (e) {}
  list = [words].concat(list.filter(function (x) { return x !== words; })).slice(0, 6);
  try { localStorage.setItem('recent', JSON.stringify(list)); } catch (e) {}
  drawRecent(list);
}
function drawRecent(list) {
  $('recent').innerHTML = (list || []).map(function (w) {
    return '<button class="nav" data-w="' + esc(w) + '"><span class="ico">' + ICON.search +
      '</span>' + esc(w) + '</button>';
  }).join('');
  $('recent').querySelectorAll('[data-w]').forEach(function (b) {
    b.onclick = function () { $('q').value = b.dataset.w; run(); };
  });
}
try { drawRecent(JSON.parse(localStorage.getItem('recent') || '[]')); } catch (e) {}

// The rail's footer carries the same two numbers the admin page shows, from the
// same place, so they can never disagree.
fetch('state.php?t=' + Date.now()).then(function (r) { return r.json(); }).then(function (s) {
  if (s.error) return;
  $('fFiles').textContent = s.archive.files.toLocaleString() + ' files · ' + tb(s.archive.bytes);
  $('fFree').textContent  = tb(s.disk.free) + ' free · ' + (s.disk.pct || 0) + '% used';
  const m = $('fMeter');
  m.style.width = (s.disk.pct || 0) + '%';
  m.className = s.disk.pct >= 90 ? 'full' : s.disk.pct >= 80 ? 'hot' : '';
}).catch(function () {});

$('q').oninput = function () { clearTimeout(timer); timer = setTimeout(run, 180); };
// /db/find.php?q=spring+festival opens with those words already searched, so Ingest
// can send you straight to a card it just brought in.
try { const w = new URLSearchParams(location.search).get('q'); if (w) $('q').value = w; } catch (e) {}
run();

// ── pulls ──────────────────────────────────────────────────────────────────
// The pull being filled lives in Rushes; this browser only remembers which
// one it is, so clips from several searches — or a phone — land together.
function store(k, v) {
  try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; }
}
let PULL = null, IN = new Set();
try { PULL = JSON.parse(store('pull') || 'null'); } catch (e) {}
const ROOT = ARCHIVE + '/';
function inPull(path) { return IN.has(path.replace(ROOT, '')); }
async function pullPost(body) {
  const j = await (await fetch('/db/pulls.php', { method: 'POST', body: new URLSearchParams(body) })).json();
  if (j.error) throw new Error(j.error); return j;
}
async function refreshPull() {
  if (!PULL) { $('pullbar').hidden = true; return; }
  const d = await (await fetch('/db/pulls.php?p=' + encodeURIComponent(PULL.slug) + '&t=' + Date.now())).json();
  if (d.error) { PULL = null; store('pull', ''); $('pullbar').hidden = true; return; }
  PULL.name = d.pull.name; IN = new Set(d.items.map(function (i) { return i.rel; }));
  const b = d.items.reduce(function (n, i) { return n + i.bytes; }, 0);
  $('pbName').textContent = d.pull.name;
  $('pbMeta').textContent = d.items.length + ' clip' + (d.items.length === 1 ? '' : 's') + ' · ' + tb(b);
  $('pbOpen').href = '/pull.php?p=' + encodeURIComponent(PULL.slug);
  $('pullbar').hidden = false;
  $('out').querySelectorAll('[data-p]').forEach(function (x) { x.classList.toggle('pulled', inPull(x.dataset.p)); });
}
// Files go in one by one, each confirmed; the bar below and the line above say how it went.
let pending = null;
async function addToPull(paths, btn) {
  paths = paths.filter(pullable);
  if (!paths.length) return;
  if (!PULL) { pending = [paths, btn]; return openPullDlg(); }
  const todo = paths.filter(function (p) { return !inPull(p); });
  if (!todo.length) {
    if (paths.length === 1 && btn) { location.href = '/pull.php?p=' + encodeURIComponent(PULL.slug); return; }
    return say('Already in ' + PULL.name + '.');
  }
  const was = btn ? btn.textContent : ''; if (btn) btn.disabled = true;
  let n = 0;
  try {
    for (const p of todo) {
      if (btn) btn.textContent = 'Adding ' + (todo.length > 1 ? (n + 1) + ' of ' + todo.length : '') + '…';
      await pullPost({ action: 'add', p: PULL.slug, path: p }); n++;
    }
    await refreshPull();
    say((n === 1 ? 'Added to ' : 'Added ' + n + ' to ') + PULL.name + (paths.length > n ? ' (' + (paths.length - n) + ' already in it)' : '') + '.');
    if (btn) btn.textContent = btn.id === 'iPull' ? 'In the pull ✓' : '✓ Added ' + n;
  } catch (e) {
    say((n ? n + ' added, then ' : '') + 'not added: ' + e.message);
    if (btn) { btn.textContent = 'Not added'; setTimeout(function () { btn.textContent = was; }, 4000); }
    await refreshPull().catch(function () {});
  }
  if (btn) btn.disabled = false;
}
async function openPullDlg() {
  const d = await (await fetch('/db/pulls.php?list=1&t=' + Date.now())).json();
  $('pdList').innerHTML = d.pulls.slice(0, 6).map(function (p) {
    return '<button class="pd-pick" data-slug="' + esc(p.slug) + '" data-name="' + esc(p.name) + '"><span>' + esc(p.name) +
      '</span><small>' + p.n + ' clip' + (p.n == 1 ? '' : 's') + '</small></button>'; }).join('');
  $('pdList').querySelectorAll('.pd-pick').forEach(function (b) {
    b.onclick = function () { usePull({ slug: b.dataset.slug, name: b.dataset.name }); };
  });
  $('pdBy').value = store('myName') || (decodeURIComponent((document.cookie.match(/(?:^|; )rushes_who=([^;]*)/) || [])[1] || ''));     // or the name this browser was given (head.php)
  $('pullDlg').showModal(); $('pdName').focus();
}
async function usePull(p) {
  PULL = p; store('pull', JSON.stringify(p)); $('pullDlg').close();
  await refreshPull(); drawPulls();
  if (pending) { const x = pending; pending = null; addToPull(x[0], x[1]); }
}
$('pdName').oninput = function () { $('pdCreate').disabled = !this.value.trim(); };
$('pdClose').onclick = function () { $('pullDlg').close(); pending = null; };
$('pdCreate').onclick = async function () {
  store('myName', $('pdBy').value.trim());
  try { const j = await pullPost({ action: 'create', name: $('pdName').value, made_by: $('pdBy').value });
        $('pdName').value = ''; usePull({ slug: j.slug, name: j.name }); }
  catch (e) { $('pdCreate').textContent = 'Did not happen: ' + e.message; }
};
$('pbSwitch').onclick = function () { pending = null; openPullDlg(); };
async function drawPulls() {
  const d = await (await fetch('/db/pulls.php?list=1&t=' + Date.now())).json();
  $('pulls').innerHTML = d.pulls.slice(0, 5).map(function (p) {
    return '<a class="nav" href="/pull.php?p=' + encodeURIComponent(p.slug) + '"' + (PULL && PULL.slug === p.slug ? ' aria-current="page"' : '') +
      '><span class="ico">' + ICON.project + '</span>' + esc(p.name) + '<span class="count">' + p.n + '</span></a>'; }).join('');
}
// Arriving from a pull's "+ Add clips": that pull is the one being filled.
(function () {
  const w = new URLSearchParams(location.search).get('pull');
  if (w) { PULL = { slug: w, name: '' }; store('pull', JSON.stringify(PULL)); }
  refreshPull().then(drawPulls).catch(function () {});
})();
</script>
