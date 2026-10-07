<?php
// find.php — the search page. The one screen most people ever open.
//
// Pictures first: what was found shows as a wall of stills, each with a small
// chip (kind, length, resolution, how many versions) instead of words. A click
// picks one and the panel beside says the rest; a double-click plays its proxy.
// Nothing loads or plays until it is asked for.
//
// Everything that narrows a search is a filter: the kind of file sits in the
// search bar (picked almost every time); where to look and what a file is
// (labels.php) sit in a Filters panel that stays hidden until it is wanted, and
// every filter that is on shows beside the count, so none is ever forgotten.
// Versions of one piece (v2, _1, FINAL, ENG/SPA: labels.php) show once.
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
  // The same drawings the rest of Rushes uses, handed to the script for tiles and rows.
  window.ICON = <?= json_encode([
    'search'   => icon('search', 1.9),
    'video'    => icon('video'),
    'image'    => icon('image'),
    'audio'    => icon('audio'),
    'project'  => icon('project'),
    'sidecar'  => icon('file'),
    'other'    => icon('file'),
    'file'     => icon('file'),
    'versions' => icon('versions', 2),
    'quote'    => icon('quote', 2),
  ], JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php require __DIR__ . '/../head.php'; ?>
<style>
  .pad { max-width: none }   /* results use the whole window */
  /* the search bar: the kind of file, then the words, as one field */
  .sbar { display: flex; gap: 10px; align-items: center; margin: 0 0 10px }
  .q { position: relative; flex: 1; display: flex; align-items: center; min-width: 0;
       border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface) }
  .q:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px var(--sel-bg) }
  .q select { width: auto; flex: none; border: 0; border-right: 1px solid var(--line); background: none; color: var(--fg);
              font: 600 13.5px var(--font); padding: 12px 10px 12px 14px; cursor: pointer; border-radius: var(--radius) 0 0 var(--radius) }
  .q select:focus { outline: none }
  .q .mag { margin: 0 0 0 12px; color: var(--faint); display: block; flex: none }
  .q .mag svg { width: 17px; height: 17px; display: block }
  .q input { flex: 1; min-width: 0; padding: 12px 14px 12px 10px; font: 15px var(--font); border: 0; background: none; color: var(--fg) }
  .q input:focus { outline: none }
  .sbtn { display: inline-flex; align-items: center; gap: 7px; white-space: nowrap }
  .sbtn svg { width: 16px; height: 16px }
  .sbtn[aria-pressed="true"] { border-color: var(--accent); color: var(--accent-text) }
  /* the drop-downs: recent searches, pulls */
  .dd { position: absolute; z-index: 25; top: calc(100% + 6px); min-width: 240px; padding: 5px; background: var(--surface);
        border: 1px solid var(--line); border-radius: 10px; box-shadow: 0 8px 30px rgba(0,0,0,.3) }
  .dd[hidden] { display: none }
  .dd button, .dd a { display: flex; gap: 8px; align-items: center; width: 100%; text-align: left; padding: 7px 10px; border: 0;
        border-radius: 6px; background: none; color: var(--fg); font: 13px var(--font); cursor: pointer; text-decoration: none }
  .dd button:hover, .dd a:hover { background: var(--raised) }
  .dd svg { width: 15px; height: 15px; color: var(--muted); flex: none }
  .dd .n { margin-left: auto; color: var(--muted); font-size: 12px }
  .dd .on { color: var(--accent-text); font-weight: 600 }
  .dd hr { border: 0; border-top: 1px solid var(--line); margin: 5px 0 }
  .pm { position: relative }
  .pm .dd { right: 0 }
  #recentDd { left: 0; right: 0 }
  /* the Filters panel: the rail's look, hidden until wanted */
  #filters[hidden] { display: none }
  .with-rail:has(> #filters[hidden]) { grid-template-columns: minmax(0, 1fr) }
  #filters .fh { display: flex; align-items: center; justify-content: space-between; padding: 2px 4px 6px 9px }
  #filters .fh b { font-size: 13px }
  .rail .nav.sub { padding-left: 38px; font-size: 12.5px }
  /* the line under the search: count, filters on, what is picked, what just happened */
  .statline { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; color: var(--muted); font-size: 12.5px; margin: 0 0 12px; min-height: 28px }
  .fchip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 6px 3px 10px; border-radius: 99px; font: 12px var(--font);
           background: var(--sel-bg); color: var(--sel-fg); border: 0; cursor: pointer }
  .fchip b { font-weight: 400; opacity: .6; font-size: 13px }
  #selbar[hidden] { display: none }
  #selbar { display: flex; gap: 8px; align-items: center; color: var(--fg) }
  #said { color: var(--ok) }
  .views { display: flex; border: 1px solid var(--line); border-radius: 8px; overflow: hidden }
  .views button { border: 0; background: none; color: var(--muted); padding: 5px 8px; cursor: pointer; display: grid }
  .views button svg { width: 16px; height: 16px }
  .views button[aria-pressed="true"] { background: var(--raised); color: var(--fg) }
  .results { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 16px; align-items: start }
  .results:has(> #inspect[hidden]) { grid-template-columns: minmax(0, 1fr) }   /* only before anything is found */
  @media (max-width: 1100px) { .results { grid-template-columns: minmax(0, 1fr) } .inspect { display: none } }
  @media (max-width: 640px) { .shoot .sh .n { display: none } .row { gap: 8px; padding-left: 10px; padding-right: 10px }
                              .q select { max-width: 110px } .sbtn span { display: none } }
  /* ── the wall: rows of pictures that fill the width, each at its own shape ── */
  .wall { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 18px }
  .wall::after { content: ''; flex-grow: 10000 }     /* the last row keeps its pictures' size */
  .frame { position: relative; height: 170px; flex-grow: 1.78; flex-basis: 302px; border-radius: 6px; overflow: hidden;
          background: var(--line); cursor: pointer; user-select: none; outline: 0 }
  .frame img { width: 100%; height: 100%; object-fit: cover; display: block }
  .frame:hover img { filter: brightness(1.06) }
  .frame.on { box-shadow: 0 0 0 3px var(--accent) }
  .frame:focus-visible { box-shadow: 0 0 0 3px var(--muted) }
  .frame .ph { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
              color: var(--muted); background: var(--raised); padding: 10px 10px 30px; border: 1px solid var(--line); border-radius: 6px }
  .frame .ph svg { width: 30px; height: 30px; opacity: .7 }
  .frame .ph small { font-size: 11.5px; text-align: center; max-width: 100%; overflow: hidden;
                    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; word-break: break-word }
  .chip, .vers { position: absolute; bottom: 6px; display: inline-flex; align-items: center; gap: 5px; padding: 2px 7px;
                 border-radius: 4px; background: rgba(0,0,0,.66); color: #fff; font: 600 11px var(--font) }
  .chip { left: 6px } .vers { right: 6px }
  .chip svg, .vers svg { width: 12px; height: 12px }
  .chip .r { opacity: .85 }
  .frame .pk { position: absolute; top: 6px; right: 6px; display: none; padding: 2px 7px; border-radius: 4px; background: var(--ok); color: #fff; font: 600 11px var(--font) }
  .frame.pulled .pk { display: block }
  .wall-h { font-size: 12px; color: var(--muted); margin: 4px 0 8px; font-weight: 600 }
  /* ── the list: files by the folder they are in ── */
  .shoot { margin: 0 0 12px }
  .shoot > header.sh { background: none; border-bottom: 1px solid var(--line); align-items: flex-start }
  .shn { min-width: 0; color: var(--muted); font-size: 12px; font-weight: 400; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
  .shn b { color: var(--fg); font-size: 13px; font-weight: 600 }
  .shn .sep { color: var(--faint); margin: 0 5px }
  .row { padding-top: 6px; padding-bottom: 6px }
  .row.pick { user-select: none }
  .row.pick.on { box-shadow: inset 3px 0 0 var(--accent) }
  .row .pk { display: none; margin-left: 6px; color: var(--ok); font-size: 11px; font-weight: 600 }
  .row.pulled .pk { display: inline }
  .row .side, .row .vn { color: var(--faint); font-size: 11px }
  .row .vn svg { width: 11px; height: 11px; vertical-align: -1px }
  .thumb { width: 34px; height: 34px; border-radius: 6px; flex: none; display: grid; place-items: center; background: var(--raised); color: var(--muted) }
  .thumb svg { width: 17px; height: 17px }
  .thumb.pic { width: 60px; overflow: hidden; background: var(--line) }
  .thumb.pic img { width: 100%; height: 100%; object-fit: cover; display: block }
  .k-video, .k-image, .k-audio { color: var(--accent-text) }
  .dim { opacity: .5 }
  .more { display: block; width: 100%; margin: 16px 0; padding: 10px; border-radius: var(--radius-sm); border: 1px solid var(--line);
          background: var(--surface); color: var(--fg); font: 13.5px var(--font); cursor: pointer }
  /* ── the panel beside ── */
  .inspect { position: sticky; top: 16px; max-height: calc(100vh - 32px); overflow-y: auto }
  .inspect .k { font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em; color: var(--faint); font-weight: 650; margin-top: 12px }
  .inspect .v { font-size: 13px; overflow-wrap: anywhere }
  .inspect .ons { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px }
  .inspect .ons span { font-size: 11px; padding: 1px 7px; border-radius: 99px; border: 1px solid var(--line); color: var(--muted) }
  .inspect img.big, .inspect video { width: 100%; border-radius: 8px; display: block; background: #000 }
  .vlist { margin-top: 4px; border: 1px solid var(--line); border-radius: 8px; overflow: hidden }
  .vrow { display: flex; gap: 8px; align-items: baseline; width: 100%; padding: 7px 10px; border: 0; border-top: 1px solid var(--line);
          background: none; color: var(--fg); font: 12.5px var(--font); text-align: left; cursor: pointer }
  .vrow:first-child { border-top: 0 }
  .vrow:hover { background: var(--raised) }
  .vrow.cur { background: var(--sel-bg); color: var(--sel-fg) }
  .vrow span { flex: 1; min-width: 0; overflow-wrap: anywhere }
  .vrow small { color: var(--muted); white-space: nowrap }
  /* ── the right-click menu, the pull being filled, the dialogs ── */
  .ctx { position: fixed; z-index: 30; min-width: 190px; padding: 5px; background: var(--surface);
         border: 1px solid var(--line); border-radius: 10px; box-shadow: 0 8px 30px rgba(0,0,0,.3) }
  .ctx button { display: block; width: 100%; text-align: left; padding: 7px 10px; border: 0; border-radius: 6px;
                background: none; color: var(--fg); font: 13px var(--font); cursor: pointer }
  .ctx button:hover { background: var(--raised) }
  .ctx button:disabled { color: var(--faint); cursor: default; background: none }
  .work { padding-bottom: 80px }
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
  dialog#playDlg video { width: 100%; border-radius: 8px; background: #000; display: block }
  #plStart[hidden] { display: none }
  #pullDlg .dh { display: flex; align-items: center; margin: 0 0 8px } #pullDlg .dh b { flex: 1; font-size: 15px }
  .pd-pick { display: flex; width: 100%; justify-content: space-between; gap: 10px; padding: 9px 12px; margin: 0 0 6px;
             border: 1px solid var(--line); border-radius: 9px; background: var(--bg); color: var(--fg);
             font: 13.5px var(--font); cursor: pointer; text-align: left }
  .pd-pick:hover { border-color: var(--accent) }
  .pd-pick small { color: var(--muted) }
  .pd-new { border-top: 1px solid var(--line); margin-top: 12px; padding-top: 12px }
  #pullDlg label.f { margin-bottom: 10px }
</style>

<div class="app" style="grid-template-rows:1fr">
<div class="with-rail">

  <!-- Filters: where to look, and what a file is (labels.php: from names, folders and sizes; nothing moved).
       One choice per group; groups combine. Hidden until wanted. -->
  <nav class="rail" id="filters" aria-label="Filters" hidden>
    <div class="fh"><b>Filters</b><button class="ghost" id="fHide">Hide</button></div>
    <h2>Where</h2>
    <button class="nav" data-f="where" data-v="ARCHIVE" data-l="Archive" title="finished shoots, kept"><span class="ico"><?= icon('archive') ?></span> Archive</button>
    <button class="nav" data-f="where" data-v="PROJECTS" data-l="Projects" title="edits and project files"><span class="ico"><?= icon('projects') ?></span> Projects</button>
    <h2>What it is</h2>
    <button class="nav" data-f="in" data-v="deliverables" data-l="Deliverables" title="finished videos: anything in an Output folder"><span class="ico"><?= icon('video') ?></span> Deliverables</button>
    <button class="nav" data-f="in" data-v="library" data-l="Stock library" title="bought or downloaded: stock footage, music, sound effects, templates, graphics"><span class="ico"><?= icon('library') ?></span> Stock library</button>
    <button class="nav sub" data-f="in" data-v="library/stock" data-l="Stock footage">Stock footage</button>
    <button class="nav sub" data-f="in" data-v="library/music" data-l="Music">Music</button>
    <button class="nav sub" data-f="in" data-v="library/sfx" data-l="Sound effects">Sound effects</button>
    <button class="nav sub" data-f="in" data-v="library/templates" data-l="Templates">Templates</button>
    <button class="nav sub" data-f="in" data-v="library/graphics" data-l="Graphics">Graphics</button>
    <button class="nav" data-f="in" data-v="ai" data-l="AI-generated" title="made with an AI tool (OpenArt …)"><span class="ico"><?= icon('ai') ?></span> AI-generated</button>
    <button class="nav" data-f="in" data-v="made" data-l="Graphics &amp; animation" title="intros, animations and other parts rendered for an edit"><span class="ico"><?= icon('project') ?></span> Graphics &amp; animation</button>
    <button class="nav" data-f="in" data-v="camera" data-l="Camera footage" title="what the cameras and drones shot"><span class="ico"><?= icon('camera') ?></span> Camera footage</button>
    <button class="nav" data-f="in" data-v="photos" data-l="Photos" title="photos and camera raws"><span class="ico"><?= icon('image') ?></span> Photos</button>
    <button class="nav" data-f="in" data-v="design" data-l="Design" title="flyers, logos and graphics: editable files (.psd, .ai) and finished ones"><span class="ico"><?= icon('design') ?></span> Design</button>
    <button class="nav" data-f="in" data-v="voiceover" data-l="Voice over" title="voice over recordings"><span class="ico"><?= icon('audio') ?></span> Voice over</button>
    <button class="nav" data-f="in" data-v="recordings" data-l="Recordings" title="Zoom and screen recordings"><span class="ico"><?= icon('video') ?></span> Recordings</button>
  </nav>

  <main class="work">
    <div class="pad">
      <div class="sbar">
        <button class="ghost sbtn" id="fBtn" aria-pressed="false" title="Where to look, and what kind of file"><?= icon('filter', 2) ?><span>Filters</span></button>
        <div class="q">
          <select id="kind" aria-label="Kind of file" title="The kind of file">
            <option value="all">Everything</option><option value="video">Videos</option><option value="image">Images</option>
            <option value="audio">Audio</option><option value="project">Project files</option>
          </select>
          <span class="mag" aria-hidden="true"><?= icon('search', 1.9) ?></span>
          <input id="q" autofocus autocomplete="off" aria-label="Search the archive"
                 placeholder="what it shows, a name, a folder, an event" title="Every word you type must match">
          <div class="dd" id="recentDd" hidden></div>
        </div>
        <div class="pm">
          <button class="ghost sbtn" id="pBtn" aria-haspopup="true" title="Pulls: clips gathered for a job"><?= icon('archive', 2) ?><span>Pulls</span> ▾</button>
          <div class="dd" id="pullsDd" hidden></div>
        </div>
      </div>
      <div class="statline"><span id="stat">Start typing.</span><span id="active"></span>
        <span id="selbar" hidden><b id="selN"></b><button class="btn" id="selAdd"></button><button class="ghost" id="selClear">Clear</button></span>
        <span id="said" role="status"></span>
        <span class="grow"></span>
        <span class="views" role="group" aria-label="Show as">
          <button data-view="wall" title="Pictures" aria-pressed="true"><?= icon('everything', 2) ?></button>
          <button data-view="list" title="A list, by folder" aria-pressed="false"><?= icon('list', 2) ?></button>
        </span>
      </div>

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
const ROOT    = ARCHIVE + '/';

const $   = function (i) { return document.getElementById(i); };
const esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
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
  return d ? d.name + '/' + p.slice(d.path.length + 1) : p.replace(ROOT, '');
};
// Where a shoot sits, as a readable trail — the filename is on its own row.
const trail = function (p) { const bits = short(p).split('/'); bits.pop(); return bits; };
function store(k, v) {
  try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; }
}

// ── what is being looked for ───────────────────────────────────────────────
// kind: the search bar's menu. where, place: the Filters panel, one choice each.
let kind = 'all', where = '', place = '';
let rows = [], total = 0, seq = 0, timer = null, moments = { count: 0, rows: [] };
let view = store('view') === 'list' ? 'list' : 'wall';
const KNOWN = {};          // every file row seen, by path: the panel finds versions here too

$('kind').onchange = function () { kind = this.value; run(); };
$('filters').querySelectorAll('[data-f]').forEach(function (b) {
  b.onclick = function () {
    const v = b.dataset.v;
    if (b.dataset.f === 'where') where = where === v ? '' : v; else place = place === v ? '' : v;
    run();
  };
});
function showFilters(on) {
  $('filters').hidden = !on; $('fBtn').setAttribute('aria-pressed', on); store('filters', on ? '1' : '');
}
$('fBtn').onclick = function () { showFilters($('filters').hidden); };
$('fHide').onclick = function () { showFilters(false); };
showFilters(store('filters') === '1');
// Every filter that is on, beside the count, with its ✕: a hidden panel never hides one.
function drawActive() {
  $('filters').querySelectorAll('[data-f]').forEach(function (b) {
    const on = (b.dataset.f === 'where' ? where : place) === b.dataset.v;
    if (on) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current');
  });
  const lab = function (f, v) { const b = $('filters').querySelector('[data-f="' + f + '"][data-v="' + v + '"]'); return b ? b.dataset.l : v; };
  const on = [];
  if (kind !== 'all') on.push(['kind', $('kind').selectedOptions[0].textContent]);
  if (where) on.push(['where', lab('where', where)]);
  if (place) on.push(['in', lab('in', place)]);
  $('active').innerHTML = on.map(function (x) {
    return '<button class="fchip" data-off="' + x[0] + '" title="Stop filtering by this">' + esc(x[1]) + ' <b>✕</b></button> '; }).join('');
  $('active').querySelectorAll('[data-off]').forEach(function (b) {
    b.onclick = function () {
      if (b.dataset.off === 'kind') { kind = 'all'; $('kind').value = 'all'; }
      if (b.dataset.off === 'where') where = '';
      if (b.dataset.off === 'in') place = '';
      run();
    };
  });
}
document.querySelectorAll('.views [data-view]').forEach(function (b) {
  b.onclick = function () { view = b.dataset.view; store('view', view); draw(); };
});

async function run(more) {
  const my = ++seq;
  const offset = more ? rows.length : 0;
  if (!more) rows = [];
  drawActive();
  const words = $('q').value.trim();
  if (!words && !place && !where && kind === 'all') {
    total = 0; moments = { count: 0, rows: [] }; $('stat').textContent = 'Start typing.';
    clearSel(); $('inspect').hidden = true; return draw();
  }
  const p = new URLSearchParams({ q: words, kind: kind, limit: 200, offset: offset, in: place, where: where });
  $('stat').textContent = 'searching…';

  let raw;
  try { raw = await (await fetch('search.php?' + p)).text(); }
  catch (e) { return fail('Could not reach the archive.', e.message); }
  let d;
  try { d = JSON.parse(raw); }
  catch (e) { return fail('The archive answered, but not with a result.', raw.slice(0, 400)); }
  if (d.error) return fail('The search could not run.', d.error);
  if (my !== seq) return;                         // a newer keystroke already won

  total = d.total; rows = more ? rows.concat(d.rows) : d.rows;
  d.rows.forEach(function (r) { KNOWN[r.path] = r; });
  if (!more) moments = d.moments || { count: 0, rows: [] };
  // Moments found inside the footage count too: "0 files" above a page of them read as a bug
  const mc = moments.count;
  $('stat').textContent =
    (mc ? mc.toLocaleString() + ' moment' + (mc === 1 ? '' : 's') + ' in the footage · ' : '') +
    (total ? total.toLocaleString() + ' file' + (total === 1 ? '' : 's') + (d.bytes ? ' · ' + tb(d.bytes) : '')
           : mc ? 'no file names match' : '0 files');
  if (!more) clearSel();
  draw();
  remember($('q').value.trim());
}

function fail(what, detail) {
  $('stat').textContent = '';
  $('out').innerHTML = '<div class="panel"><div class="empty"><b>' + esc(what) + '</b>' +
    '<div class="code block" style="text-align:left;margin-top:10px">' + esc(detail) + '</div></div></div>';
}

// ── versions: one piece shows once ─────────────────────────────────────────
// Files that are versions of one piece share a key (labels.php). Of those found,
// the newest stands for the piece; moments come only from that one file, so a
// shot found in v2 and in v3 is not shown twice.
function pieces(list) {
  const best = {};
  list.forEach(function (r) {
    if (!r.vkey) return;
    const b = best[r.vkey];
    if (!b || (+r.vrank || 0) > (+b.vrank || 0)) best[r.vkey] = r;
  });
  return list.filter(function (r) { return !r.vkey || best[r.vkey] === r; });
}
function momentPieces() {
  const best = {};
  moments.rows.forEach(function (m) {
    if (m.vkey && (!best[m.vkey] || (+m.vrank || 0) > (+best[m.vkey].vrank || 0))) best[m.vkey] = m;
  });
  return moments.rows.map(function (m, i) { return [m, i]; })
    .filter(function (x) { return !x[0].vkey || best[x[0].vkey].path === x[0].path; });
}

// ── drawing ────────────────────────────────────────────────────────────────
function tcode(s) { s = Math.floor(s || 0); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); }
function clock(s) {
  s = Math.round(s); const h = Math.floor(s / 3600), m = Math.floor(s / 60) % 60, x = s % 60;
  return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(x).padStart(2, '0');
}
function res(r) {
  if (!r.width) return '';
  const w = Math.max(r.width, r.height), h = Math.min(r.width, r.height);
  return w >= 3800 || h >= 2100 ? '4K' : w >= 2500 || h >= 1400 ? '2.7K' : h >= 1060 ? 'HD' : h >= 700 ? '720p' : 'SD';
}
const versTag = function (n) { return n > 1 ? '<span class="vers" title="' + n + ' versions of this">' + ICON.versions + n + '</span>' : ''; };
const shape = function (w, h) { const a = Math.min(Math.max(w / h || 1.78, .5), 2.6); return 'flex-grow:' + a.toFixed(3) + ';flex-basis:' + Math.round(a * 170) + 'px'; };

// A moment: its picture, and a chip with where in the file it is
function momentTile(m, i) {
  const speech = m.kind === 'speech';
  return '<div class="frame' + (inPull(m.path) ? ' pulled' : '') + '" tabindex="0" data-k="m' + i + '" data-p="' + esc(m.path) + '" data-t="' + (+m.start_s || 0) + '"' +
    ' title="' + esc((speech ? '“' + m.what + '”' : m.what) + '\n' + m.path.split('/').pop()) + '">' +
    (speech ? '<div class="ph">' + ICON.quote + '<small>' + esc(m.what) + '</small></div>'
            : '<img loading="lazy" alt="' + esc(m.what) + '" src="thumb.php?fp=' + encodeURIComponent(m.fp) + '&shot=' + m.shot + '">') +
    '<span class="chip">' + (speech ? ICON.quote : ICON.video) + tcode(m.start_s) + '</span>' + versTag(m.versions) +
    '<span class="pk">✓</span></div>';
}
// A file: its first still if it was described, else its kind drawn with its name
function fileTile(r) {
  const k = r.kind || 'other', st = (r.still || '').split(':');
  return '<div class="frame' + (inPull(r.path) ? ' pulled' : '') + '" tabindex="0" data-k="' + esc(r.path) + '" data-p="' + esc(r.path) + '"' +
    ' style="' + (r.width ? shape(r.width, r.height) : '') + '" title="' + esc(r.name) + '">' +
    (st.length === 2 ? '<img loading="lazy" alt="' + esc(r.name) + '" src="thumb.php?fp=' + encodeURIComponent(st[0]) + '&shot=' + (+st[1]) + '">'
                     : '<div class="ph">' + (ICON[k] || ICON.file) + '<small>' + esc(r.name) + '</small></div>') +
    '<span class="chip">' + (ICON[k] || ICON.file) + (r.duration ? clock(r.duration) : '') +
      (res(r) ? ' <span class="r">' + res(r) + '</span>' : '') + '</span>' +
    versTag(r.versions) + '<span class="pk">✓</span></div>';
}

// Settings and notes a camera or an app writes beside a file
const SIDE = /\.(xmp|sii|cpf|thm|cos|cop|cof|cot|comask)$/i;
const stem = function (n) { return n.toLowerCase().replace(/\.[^.]+$/, ''); };
// Files by the folder they are in: a trail with the shoot in bold, sidecars under their file
function listHTML(list) {
  const groups = [];
  list.forEach(function (r) {
    const ev = r.event || 'no event folder', g = groups[groups.length - 1];
    if (g && g.ev === ev) g.rows.push(r); else groups.push({ ev: ev, rows: [r] });
  });
  let html = '';
  groups.forEach(function (g) {
    const byName = {}, side = {};
    g.rows.forEach(function (r) { if (!SIDE.test(r.name)) byName[r.name.toLowerCase()] = byName[stem(r.name)] = r; });
    const shown = g.rows.filter(function (r) {
      if (!SIDE.test(r.name)) return true;
      const host = byName[r.name.toLowerCase().replace(/\.[^.]+$/, '')] || byName[stem(r.name)];
      if (!host) return true;
      (side[host.path] = side[host.path] || []).push(r.name); return false;
    });
    const gb = g.rows.reduce(function (n, r) { return n + (r.bytes || 0); }, 0);
    const bits = trail(shown[0].path);
    html += '<div class="panel shoot"><header class="sh"><div class="shn" title="' + esc(short(shown[0].path)) + '">' +
      bits.map(function (b) { return b === g.ev ? '<b>' + esc(b) + '</b>' : esc(b); }).join('<span class="sep">›</span>') + '</div>' +
      '<span class="n">' + g.rows.length.toLocaleString() + ' file' + (g.rows.length === 1 ? '' : 's') + (gb ? ' · ' + tb(gb) : '') + '</span></header>';
    shown.forEach(function (r) {
      const moved = r.path.indexOf('/_duplicates/') > -1 || r.path.indexOf('/_Recently Removed/') > -1;
      const k = r.kind || 'other', st = (r.still || '').split(':'), sc = side[r.path] || [];
      html += '<div class="row pick' + (moved ? ' dim' : '') + (inPull(r.path) ? ' pulled' : '') + '" tabindex="0" data-p="' + esc(r.path) + '" data-k="' + esc(r.path) + '">' +
        (st.length === 2 ? '<span class="thumb pic"><img loading="lazy" alt="" src="thumb.php?fp=' + encodeURIComponent(st[0]) + '&shot=' + (+st[1]) + '"></span>'
                         : '<span class="thumb k-' + esc(k) + '">' + (ICON[k] || ICON.file || '') + '</span>') +
        '<span class="nm">' + esc(r.name) + '<span class="pk">✓ in the pull</span>' +
        (moved ? ' <span class="pill">in Recently Removed</span>' : '') +
        (r.drive ? ' <span class="pill"' + (r.away ? ' title="Plug in ' + esc(r.drive) + ' to open it"' : '') + '>on ' + esc(r.drive) +
          (r.away ? ' · not plugged in' : '') + '</span>' : '') +
        '<small>' + esc((r.ext || '').toUpperCase()) + (r.ext ? ' · ' : '') + esc(k) +
        (res(r) ? ' · <b class="res">' + res(r) + '</b>' : '') +
        (r.versions > 1 ? ' · <span class="vn" title="versions of this piece">' + ICON.versions + ' ' + r.versions + '</span>' : '') +
        (sc.length ? ' · <span class="side" title="' + esc(sc.join('\n')) + '">+ ' + sc.length + ' sidecar' + (sc.length === 1 ? '' : 's') + '</span>' : '') +
        '</small></span><span class="sz">' + tb(r.bytes) + '</span></div>';
    });
    html += '</div>';
  });
  return html;
}

function draw() {
  document.querySelectorAll('.views [data-view]').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.view === view); });
  const files = pieces(rows), mom = momentPieces();
  if (!files.length && !mom.length) {
    $('inspect').hidden = true;
    $('out').innerHTML = '<div class="panel"><div class="empty">' +
      ($('q').value.trim() || place || where || kind !== 'all' ? 'Nothing matches.' : 'Type a few words above: what it shows, a name, a folder, an event.') + '</div></div>';
    return;
  }
  let html = '';
  // What the footage shows comes first, as pictures, in both views
  const shown = {};
  if (mom.length) {
    html += '<div class="wall">' + mom.map(function (x) { shown[x[0].vkey || x[0].path] = 1; return momentTile(x[0], x[1]); }).join('') + '</div>';
  }
  if (view === 'wall') {
    // footage and pictures as tiles (not again when a moment of it is already shown); the rest as a list
    const pics = files.filter(function (r) { return (r.kind === 'video' || r.kind === 'image') && !shown[r.vkey || r.path]; });
    const rest = files.filter(function (r) { return r.kind !== 'video' && r.kind !== 'image'; });
    if (pics.length) html += (mom.length ? '<div class="wall-h">Files</div>' : '') + '<div class="wall">' + pics.map(fileTile).join('') + '</div>';
    if (rest.length) html += '<div class="wall-h">Other files</div>' + listHTML(rest);
  } else html += listHTML(files);
  if (rows.length < total) html += '<button class="more" id="more">show 200 more (' + (total - rows.length).toLocaleString() + ' left)</button>';
  $('out').innerHTML = html;
  const m = $('more'); if (m) m.onclick = function () { run(true); };
  if (!SEL.size) hint();
  drawSel();
}
// The panel keeps its place while there are results, so a click never moves the pictures under the mouse
function hint() {
  $('inspect').hidden = false;
  $('inspect').innerHTML = '<div style="padding:16px 14px"><p class="note" style="margin:0">Click a picture to see what it is.<br>' +
    'Double-click to play it. Right-click to add it to a pull.</p></div>';
}
// A picture takes its own shape once it has loaded: a phone's vertical clip stays vertical
$('out').addEventListener('load', function (e) {
  const t = e.target.closest && e.target.closest('.frame');
  if (t && e.target.naturalWidth) t.style.cssText = shape(e.target.naturalWidth, e.target.naturalHeight);
}, true);

// ── playing ────────────────────────────────────────────────────────────────
// Only when asked: the proxy from the moment found, with a way back to its start.
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

// ── choosing ───────────────────────────────────────────────────────────────
// One click picks a tile or a row and shows it beside; nothing opens.
// Cmd/Ctrl-click adds or removes one, Shift-click takes a run. Double-click
// plays. A right click offers what can be done with whatever is picked.
const SEL = new Set(); let lastEl = null;
const items = function () { return Array.prototype.slice.call($('out').querySelectorAll('[data-k]')); };
const picked = function () { return items().filter(function (x) { return SEL.has(x.dataset.k); }); };
const isMoment = function (el) { return el.dataset.t !== undefined; };
const momentOf = function (el) { return moments.rows[+el.dataset.k.slice(1)]; };
const playable = function (el) { return isMoment(el) || (KNOWN[el.dataset.p] || {}).kind === 'video'; };
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
  if (SEL.size === 1) { const one = picked()[0]; if (isMoment(one)) inspectMoment(momentOf(one)); else inspect(KNOWN[one.dataset.p]); }
  else if (!SEL.size) hint();
}
$('out').addEventListener('click', function (e) { const el = e.target.closest('[data-k]'); if (el) choose(el, e); });
$('out').addEventListener('keydown', function (e) {
  const el = e.target.closest('[data-k]'); if (!el) return;
  if (e.key === 'Enter') { e.preventDefault(); if (SEL.has(el.dataset.k) && playable(el)) play(el.dataset.p, +el.dataset.t || 0); else choose(el, e); }
  if (e.key === ' ') { e.preventDefault(); choose(el, e); }
});
$('out').addEventListener('dblclick', function (e) {
  const el = e.target.closest('[data-k]'); if (el && playable(el)) play(el.dataset.p, +el.dataset.t || 0);
});
$('out').addEventListener('contextmenu', function (e) {
  const el = e.target.closest('[data-k]'); if (!el) return;
  e.preventDefault();
  if (!SEL.has(el.dataset.k)) choose(el, null);
  const paths = selPaths(), one = SEL.size === 1, t = +el.dataset.t || 0;
  const m = $('ctx');
  m.innerHTML =
    (one && playable(el) ? '<button data-do="play">Play' + (t ? ' from ' + tcode(t) : '') + '</button>' : '') +
    (one && t ? '<button data-do="start">Play from the start</button>' : '') +
    '<button data-do="pull"' + (paths.length ? '' : ' disabled') + '>' +
      (paths.length ? 'Add ' + (paths.length > 1 ? paths.length + ' ' : '') + (PULL && PULL.name ? 'to ' + esc(PULL.name) : 'to a pull…')
                    : 'Not in the archive: cannot be pulled') + '</button>' +
    (one ? '<button data-do="copy">Copy path</button>' : '');
  m.hidden = false;
  m.style.left = Math.min(e.clientX, innerWidth - m.offsetWidth - 8) + 'px';
  m.style.top  = Math.min(e.clientY, innerHeight - m.offsetHeight - 8) + 'px';
  m.onclick = function (ev) {
    const b = ev.target.closest('[data-do]'); if (!b || b.disabled) return;
    m.hidden = true;
    if (b.dataset.do === 'play')  play(el.dataset.p, t);
    if (b.dataset.do === 'start') play(el.dataset.p, 0);
    if (b.dataset.do === 'pull')  addToPull(paths, null);
    if (b.dataset.do === 'copy')  copyText(localPath(el.dataset.p), function (ok) { say(ok ? 'Path copied.' : 'The browser would not copy: press ⌘C'); });
  };
});
document.addEventListener('click', function (e) {
  if (!e.target.closest('#ctx')) $('ctx').hidden = true;
  if (!e.target.closest('.pm')) $('pullsDd').hidden = true;
});
document.addEventListener('scroll', function () { $('ctx').hidden = true; }, true);
document.addEventListener('keydown', function (e) {
  if (e.key !== 'Escape' || document.querySelector('dialog[open]')) return;
  if (!$('ctx').hidden || !$('pullsDd').hidden || !$('recentDd').hidden) { $('ctx').hidden = $('pullsDd').hidden = $('recentDd').hidden = true; return; }
  clearSel(); hint();
});
$('selAdd').onclick = function () { addToPull(selPaths(), $('selAdd')); };
$('selClear').onclick = function () { clearSel(); hint(); };
// every action says it happened, here, for a few seconds
let saidT = null;
function say(t) { $('said').textContent = t; clearTimeout(saidT); saidT = setTimeout(function () { $('said').textContent = ''; }, 4000); }

// ── the panel beside ───────────────────────────────────────────────────────
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
  const rs = (s || '').split(';').filter(Boolean).map(function (x) { const f = x.split('|'); return {place: f[0], there: f[1] === '1', at: +f[2]}; });
  const there = rs.filter(function (x) { return x.there; });
  if (there.length) return (1 + there.length) + ' — this one, and ' +
    there.map(function (x) { return 'one on ' + esc(x.place) + ' (seen ' + day(x.at) + ')'; }).join(', ');
  if (rs.length) return '<b>1 — this one.</b> The copy on ' +
    rs.map(function (x) { return esc(x.place) + ' was gone when checked on ' + day(x.at); }).join(', ');
  return '1 — this file. No other copy on record.';
}
// The path as this computer reaches it, for Copy path
function localPath(p) {
  const mine = store('archiveBase');
  return !p.startsWith(ROOT) ? p : mine ? (/^([A-Za-z]:|\\\\)/.test(mine)
      ? mine + '\\' + short(p).replace(/\//g, '\\') : mine + '/' + short(p))
    : p.replace(ARCHIVE, LOCAL);
}
const pullable = function (p) { return p.startsWith(ROOT) && !(RUSHES.drives || []).some(function (d) { return p.startsWith(d.path + '/'); }); };
const kv = function (k, v) { return v ? '<div class="k">' + k + '</div><div class="v">' + v + '</div>' : ''; };

// Every version of the piece, newest first; one click shows that version here
function versionsHTML(r) {
  return r && r.versions > 1 ? '<div class="k">' + r.versions + ' versions</div><div class="vlist" id="iVers"><div class="vrow"><span>Looking…</span></div></div>' : '';
}
async function loadVersions(r) {
  if (!r || !(r.versions > 1) || !$('iVers')) return;
  let d;
  try { d = await (await fetch('search.php?' + new URLSearchParams({ v: r.vkey, limit: 100 }))).json(); } catch (e) { d = { error: e.message }; }
  if (!$('iVers')) return;
  if (d.error) { $('iVers').innerHTML = '<div class="vrow"><span>Could not list them: ' + esc(d.error) + '</span></div>'; return; }
  d.rows.forEach(function (x) { KNOWN[x.path] = x; });
  d.rows.sort(function (a, b) { return (+b.vrank || 0) - (+a.vrank || 0) || a.name.localeCompare(b.name); });
  $('iVers').innerHTML = d.rows.map(function (x, i) {
    return '<button class="vrow' + (x.path === r.path ? ' cur' : '') + '" data-vp="' + esc(x.path) + '" title="' + esc(short(x.path)) + '">' +
      '<span>' + esc(x.name) + '</span><small>' + (i === 0 ? 'newest · ' : '') + tb(x.bytes) + '</small></button>';
  }).join('');
  $('iVers').querySelectorAll('[data-vp]').forEach(function (b) {
    b.onclick = function () { inspect(KNOWN[b.dataset.vp]); };
    b.ondblclick = function () { if ((KNOWN[b.dataset.vp] || {}).kind === 'video') play(b.dataset.vp, 0); };
  });
}

function inspect(r) {
  if (!r) return;
  const local = r.drive ? r.path : localPath(r.path), st = (r.still || '').split(':');
  $('inspect').hidden = false;
  $('inspect').innerHTML =
    '<header><b>' + esc(r.name) + '</b></header>' +
    '<div style="padding:12px 14px">' +
      // the player is ready, not playing: nothing loads until Play is pressed
      (r.proxy_at ? '<video controls playsinline preload="none"' + (st.length === 2 ? ' poster="thumb.php?fp=' + encodeURIComponent(st[0]) + '&shot=' + (+st[1]) + '"' : '') +
          ' src="play.php?p=' + encodeURIComponent(r.path) + '"></video>'
        : st.length === 2 ? '<img class="big" alt="" src="thumb.php?fp=' + encodeURIComponent(st[0]) + '&shot=' + (+st[1]) + '">' : '') +
      versionsHTML(r) +
      kv('Kind', esc(r.kind || 'file') + (r.ext ? ' · ' + esc(r.ext.toUpperCase()) : '')) +
      kv('Size', tb(r.bytes)) +
      (r.width ? kv('Original', res(r) + ' · ' + r.width + ' × ' + r.height +
        (r.fps ? ' · ' + (+r.fps).toFixed(2).replace(/\.?0+$/, '') + ' fps' : '') +
        (r.codec ? ' · ' + esc(r.codec.toUpperCase()) : '') + (r.duration ? ' · ' + clock(r.duration) : '')) : '') +
      (r.recorded ? kv('Recorded', esc(recorded(r.recorded)) + ' <small>(the camera\'s clock)</small>') : '') +
      kv('Camera', esc(r.camera)) +
      kv('Timecode', esc([r.timecode, r.reel ? 'reel ' + r.reel : ''].filter(Boolean).join(' · '))) +
      kv('Copies', copiesText(r.copies)) +
      (r.proxy_at ? kv('Plays from', 'its proxy (downloads and pulls use the original)') : '') +
      kv('Shoot', esc(r.event)) + kv('Year', esc(r.year)) +
      (r.drive ? kv('Drive', esc(r.drive) + (r.away ? ' &mdash; <b>not plugged in</b>: plug it in to open this file' : ' (plugged in)')) : '') +
      kv('Where it lives', esc(short(r.path))) +
      '<div class="btns" style="margin-top:14px">' +
        (pullable(r.path) ? '<button class="btn" id="iPull">' + (inPull(r.path) ? 'In the pull ✓' : 'Add to pull') + '</button>' : '') +
        '<button class="btn quiet" id="iCopy">Copy path</button>' +
      '</div>' +
    '</div>';
  $('iCopy').onclick = function () { copyText(local, $('iCopy')); };
  if ($('iPull')) $('iPull').onclick = function () { addToPull([r.path], $('iPull')); };
  loadVersions(r);
}

// A moment picked: its picture, everything the model said about it, and what to do with it
function inspectMoment(m) {
  if (!m) return;
  const speech = m.kind === 'speech', t = +m.start_s || 0;
  const tags = function (k, v) { return v ? '<div class="k">' + k + '</div><div class="ons">' +
    v.split(' · ').map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('') + '</div>' : ''; };
  const piece = { path: m.path, vkey: m.vkey, versions: m.versions };
  $('inspect').hidden = false;
  $('inspect').innerHTML = '<header><b>' + esc(m.path.split('/').pop()) + '</b></header><div style="padding:12px 14px">' +
    (speech ? '' : '<img class="big" alt="" src="thumb.php?fp=' + encodeURIComponent(m.fp) + '&shot=' + m.shot + '">') +
    kv(speech ? 'Said' : 'Shot ' + ((+m.shot || 0) + 1), tcode(m.start_s) + ' → ' + tcode(m.end_s) + (speech && m.language ? ' · ' + esc(m.language) : '')) +
    kv(speech ? 'Words' : 'What it shows', esc(m.what)) +
    tags('Text on screen', m.on_screen) + tags('Themes', m.themes) + tags('Tags', m.tags) +
    kv('Shot size', esc(m.shot_size)) + kv('People', esc(m.people)) + kv('Light', esc(m.light)) + kv('Mood', esc(m.mood)) +
    kv('Part of the day', esc(m.part_of_day)) +
    versionsHTML(piece) +
    kv('Where it lives', esc(short(m.path))) +
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
  loadVersions(piece);
}

// ── recent searches: under the search box, kept in this browser only ──────
function recentList() { try { return JSON.parse(store('recent') || '[]'); } catch (e) { return []; } }
function remember(words) {
  if (!words || words.length < 3) return;
  store('recent', JSON.stringify([words].concat(recentList().filter(function (x) { return x !== words; })).slice(0, 8)));
}
function drawRecent() {
  const w = $('q').value.trim().toLowerCase();
  const list = recentList().filter(function (x) { return x.toLowerCase() !== w && x.toLowerCase().indexOf(w) > -1; });
  $('recentDd').innerHTML = list.map(function (x) { return '<button data-w="' + esc(x) + '">' + ICON.search + esc(x) + '</button>'; }).join('');
  $('recentDd').hidden = !list.length;
}
$('q').addEventListener('focus', drawRecent);
$('q').addEventListener('blur', function () { setTimeout(function () { $('recentDd').hidden = true; }, 150); });
$('recentDd').addEventListener('mousedown', function (e) {
  const b = e.target.closest('[data-w]'); if (!b) return;
  e.preventDefault(); $('q').value = b.dataset.w; $('recentDd').hidden = true; run();
});
$('q').oninput = function () { drawRecent(); clearTimeout(timer); timer = setTimeout(run, 180); };
$('q').addEventListener('keydown', function (e) { if (e.key === 'Enter') { $('recentDd').hidden = true; clearTimeout(timer); run(); } });
// /db/find.php?q=spring+festival opens with those words already searched, so Ingest
// can send you straight to a card it just brought in.
try { const w = new URLSearchParams(location.search).get('q'); if (w) $('q').value = w; } catch (e) {}

// ── pulls ──────────────────────────────────────────────────────────────────
// The pull being filled lives in Rushes; this browser only remembers which
// one it is, so clips from several searches — or a phone — land together.
let PULL = null, IN = new Set();
try { PULL = JSON.parse(store('pull') || 'null'); } catch (e) {}
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
async function pullsList() { return (await (await fetch('/db/pulls.php?list=1&t=' + Date.now())).json()).pulls || []; }
async function openPullDlg() {
  const pulls = await pullsList();
  $('pdList').innerHTML = pulls.slice(0, 6).map(function (p) {
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
  await refreshPull();
  say('Filling ' + (PULL ? PULL.name : p.name) + '.');
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
// The Pulls menu: its own thing, apart from the filters
$('pBtn').onclick = async function () {
  const dd = $('pullsDd');
  if (!dd.hidden) { dd.hidden = true; return; }
  dd.innerHTML = '<a>Looking…</a>'; dd.hidden = false;
  let pulls = [];
  try { pulls = await pullsList(); } catch (e) { dd.innerHTML = '<a>Could not list pulls: ' + esc(e.message) + '</a>'; return; }
  dd.innerHTML = pulls.slice(0, 8).map(function (p) {
    const on = PULL && PULL.slug === p.slug;
    return '<a href="/pull.php?p=' + encodeURIComponent(p.slug) + '"' + (on ? ' class="on" title="the one being filled"' : '') + '>' +
      (on ? '● ' : '') + esc(p.name) + '<span class="n">' + p.n + '</span></a>'; }).join('') +
    (pulls.length ? '<hr>' : '') +
    '<button id="pdNew">Fill another pull…</button><a href="/pull.php">All pulls</a>';
  $('pdNew').onclick = function () { dd.hidden = true; pending = null; openPullDlg(); };
};
// Arriving from a pull's "+ Add clips": that pull is the one being filled.
(function () {
  const w = new URLSearchParams(location.search).get('pull');
  if (w) { PULL = { slug: w, name: '' }; store('pull', JSON.stringify(PULL)); }
  refreshPull().catch(function () {});
})();
run();
</script>
