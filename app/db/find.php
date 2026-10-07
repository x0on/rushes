<?php
// find.php — the search page. The one screen most people ever open.
//
// Pictures first: what was found shows as a grid of stills, one per video, each
// with a small chip (kind, length, where the match is, how many versions)
// instead of words. A video is a container: it is found when the words appear
// anywhere in it, and opening it shows every shot it has, not only the one that
// matched. A click picks one and the panel beside shows it; a double-click
// opens it large and plays it. Nothing loads or plays until it is asked for.
//
// Everything that narrows a search is a filter: the kind of file sits in the
// search bar (picked almost every time); where to look and what a file is
// (labels.php) sit in a Filters panel that stays hidden until it is wanted, and
// every filter that is on shows beside the count, so none is ever forgotten.
// Versions of one piece (v2, _1, FINAL, ENG/SPA, YouTube/Instagram: labels.php) show once.
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
  // The same drawings the rest of Rushes uses, handed to the script for tiles, rows and buttons.
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
    'pull'     => icon('pull', 1.8),
    'copy'     => icon('copy', 1.8),
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
  .said { color: var(--ok) }
  .views { display: flex; border: 1px solid var(--line); border-radius: 8px; overflow: hidden }
  .views button { border: 0; background: none; color: var(--muted); padding: 5px 8px; cursor: pointer; display: grid }
  .views button svg { width: 16px; height: 16px }
  .views button[aria-pressed="true"] { background: var(--raised); color: var(--fg) }
  .results { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 16px; align-items: start }
  .results:has(> #inspect[hidden]) { grid-template-columns: minmax(0, 1fr) }   /* only before anything is found */
  @media (max-width: 1100px) { .results { grid-template-columns: minmax(0, 1fr) } .inspect { display: none } }
  @media (max-width: 640px) { .shoot .sh .n { display: none } .row { gap: 8px; padding-left: 10px; padding-right: 10px }
                              .q select { max-width: 110px } .sbtn span { display: none } }
  /* ── the grid: every picture the same size, square corners, columns that line up ── */
  .wall { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 4px; margin: 0 0 18px }
  .frame { position: relative; aspect-ratio: 16 / 9; background: #000; cursor: pointer; user-select: none; outline: 0; overflow: hidden }
  .frame img { width: 100%; height: 100%; object-fit: contain; display: block }   /* a vertical clip sits on black, the grid stays */
  .frame:hover img { filter: brightness(1.08) }
  .frame.on { box-shadow: inset 0 0 0 3px var(--accent) }
  .frame:focus-visible { box-shadow: inset 0 0 0 3px var(--muted) }
  .frame .ph { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
               color: var(--muted); background: var(--raised); padding: 10px 10px 30px }
  .frame .ph svg { width: 28px; height: 28px; opacity: .7 }
  .frame .ph small { font-size: 11.5px; text-align: center; max-width: 100%; overflow: hidden;
                     display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; word-break: break-word }
  .chip, .vers { position: absolute; bottom: 6px; display: inline-flex; align-items: center; gap: 5px; padding: 2px 7px;
                 background: rgba(0,0,0,.66); color: #fff; font: 600 11px var(--font) }
  .chip { left: 6px } .vers { right: 6px }
  .chip svg, .vers svg { width: 12px; height: 12px }
  .chip .r { opacity: .85 }
  .frame .pk { position: absolute; top: 6px; right: 6px; display: none; padding: 2px 7px; background: var(--ok); color: #fff; font: 600 11px var(--font) }
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
  .thumb { width: 34px; height: 34px; border-radius: 4px; flex: none; display: grid; place-items: center; background: var(--raised); color: var(--muted) }
  .thumb svg { width: 17px; height: 17px }
  .thumb.pic { width: 60px; overflow: hidden; background: #000 }
  .thumb.pic img { width: 100%; height: 100%; object-fit: contain; display: block }
  .k-video, .k-image, .k-audio { color: var(--accent-text) }
  .dim { opacity: .5 }
  .more { display: block; width: 100%; margin: 16px 0; padding: 10px; border-radius: var(--radius-sm); border: 1px solid var(--line);
          background: var(--surface); color: var(--fg); font: 13.5px var(--font); cursor: pointer }
  /* ── one video, opened: the panel beside (small) and the big view (double-click) share these ── */
  .inspect { position: sticky; top: 16px; max-height: calc(100vh - 32px); overflow-y: auto }
  .vh { display: flex; align-items: flex-start; gap: 8px; padding: 12px 14px 10px }
  .vh b { flex: 1; min-width: 0; font-size: 13.5px; overflow-wrap: anywhere }
  .ib { flex: none; width: 30px; height: 30px; display: grid; place-items: center; border: 1px solid var(--line); border-radius: 7px;
        background: none; color: var(--fg); cursor: pointer; padding: 0 }
  .ib svg { width: 16px; height: 16px }
  .ib:hover { border-color: var(--accent) }
  .ib.in { color: var(--ok); border-color: var(--ok) }
  .ib[hidden] { display: none }
  .vb { padding: 0 14px 14px }
  .pv { position: relative; aspect-ratio: 16 / 9; background: #000; cursor: pointer; overflow: hidden }
  .pv img, .pv video { width: 100%; height: 100%; object-fit: contain; display: block }
  .pv .go { position: absolute; inset: 0; margin: auto; width: 54px; height: 54px; border-radius: 50%; background: rgba(0,0,0,.55);
            display: grid; place-items: center; color: #fff; font-size: 20px; pointer-events: none }
  .pv .ph { position: absolute; inset: 0; display: grid; place-items: center; color: var(--muted); background: var(--raised) }
  .pv .ph svg { width: 34px; height: 34px }
  .pv-note { font-size: 12px; color: var(--muted); margin: 6px 0 0 }
  .sec { font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em; color: var(--faint); font-weight: 650; margin: 16px 0 6px }
  .desc { font-size: 13.5px; line-height: 1.5 }
  .desc small { display: block; color: var(--muted); font-size: 12px; margin-top: 3px }
  .strip { display: grid; grid-template-columns: repeat(auto-fill, minmax(72px, 1fr)); gap: 3px }
  .strip button { position: relative; aspect-ratio: 16 / 9; padding: 0; border: 0; background: #000; cursor: pointer; overflow: hidden }
  .strip img { width: 100%; height: 100%; object-fit: cover; display: block; opacity: .75 }
  .strip button:hover img, .strip .hit img, .strip .cur img { opacity: 1 }
  .strip .hit { box-shadow: inset 0 0 0 2px var(--accent-text) }
  .strip .cur { box-shadow: inset 0 0 0 3px var(--fg) }
  .strip span { position: absolute; left: 3px; bottom: 3px; padding: 0 4px; background: rgba(0,0,0,.66); color: #fff; font: 600 10px var(--font) }
  .strip .said { display: grid; place-items: center; height: 100%; color: var(--muted) }
  .strip .said svg { width: 16px; height: 16px }
  .kv { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 14px }
  .kv div { min-width: 0 }
  .kv .wide { grid-column: 1 / -1 }
  .kv small { display: block; font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em; color: var(--faint); font-weight: 650 }
  .kv span { font-size: 13px; overflow-wrap: anywhere }
  .tags { display: flex; flex-wrap: wrap; gap: 4px }
  .tags span, .tags button { font: 11.5px var(--font); padding: 2px 8px; border: 1px solid var(--line); color: var(--muted); background: none }
  .tags button { cursor: pointer; color: var(--fg); padding: 5px 12px; font-size: 13px }
  .tags button:hover { border-color: var(--accent) }
  .vlist { border: 1px solid var(--line); overflow: hidden }
  .vrow { display: flex; gap: 8px; align-items: baseline; width: 100%; padding: 7px 10px; border: 0; border-top: 1px solid var(--line);
          background: none; color: var(--fg); font: 12.5px var(--font); text-align: left; cursor: pointer }
  .vrow:first-child { border-top: 0 }
  .vrow:hover { background: var(--raised) }
  .vrow.cur { background: var(--sel-bg); color: var(--sel-fg) }
  .vrow.cur small { color: inherit; opacity: .75 }
  .vrow span { flex: 1; min-width: 0; overflow-wrap: anywhere }
  .vrow i { font-style: normal; font-size: 10.5px; padding: 0 5px; margin-left: 4px; border: 1px solid currentColor; opacity: .7 }
  .vrow small { color: var(--muted); white-space: nowrap }
  .facts { border-top: 1px solid var(--line); margin-top: 18px; padding-top: 4px }
  /* the big view: the video large, with room around it */
  dialog#big { width: min(1500px, calc(100vw - 32px)); max-height: calc(100vh - 32px); border: 1px solid var(--line); border-radius: 0;
               background: var(--bg); color: var(--fg); padding: 0; overflow: hidden }
  dialog#big::backdrop { background: rgba(5, 12, 12, .85) }
  .bigbar { display: flex; justify-content: flex-end; gap: 4px; padding: 8px 10px 0 }
  .bigbar button { border: 0; background: none; color: var(--fg); font-size: 22px; width: 38px; height: 34px; cursor: pointer }
  .bigbar button:disabled { opacity: .3; cursor: default }
  .bigbody { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 28px; padding: 6px 28px 28px;
             max-height: calc(100vh - 90px); overflow-y: auto }
  .bigbody .pv { cursor: default }
  .bigbody .vh { padding: 0 0 10px }
  .bigbody .vh b { font-size: 18px; font-weight: 600 }
  .bigbody .vb { padding: 0 }
  .bigbody .strip { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 4px }
  @media (max-width: 1000px) { .bigbody { grid-template-columns: minmax(0, 1fr) } }
  /* ── the right-click menu, the pull being filled, the pull dialog ── */
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
        <span class="said" role="status"></span>
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

<!-- The big view: one video large, its shots, its versions, its keywords -->
<dialog id="big" aria-label="The video, large">
  <div class="bigbar">
    <button type="button" id="bigPrev" title="The one before (←)">‹</button>
    <button type="button" id="bigNext" title="The next one (→)">›</button>
    <button type="button" id="bigClose" title="Close (Esc)">✕</button>
  </div>
  <div class="bigbody" id="bigBody"></div>
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
async function getJSON(params) {
  const d = await (await fetch('search.php?' + new URLSearchParams(params))).json();
  if (d.error) throw new Error(d.error); return d;
}

// ── what is being looked for ───────────────────────────────────────────────
// kind: the search bar's menu. where, place: the Filters panel, one choice each.
let kind = 'all', where = '', place = '';
let rows = [], total = 0, seq = 0, timer = null, videos = { count: 0, moments: 0, rows: [] };
let view = store('view') === 'list' ? 'list' : 'wall';
const KNOWN = {};          // every file row seen, by path: the panel and the big view find versions here too

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
    total = 0; videos = { count: 0, moments: 0, rows: [] }; $('stat').textContent = 'Start typing.';
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
  if (!more) videos = d.moments || { count: 0, moments: 0, rows: [] };
  // Videos found by what they show count too: "0 files" above a page of them read as a bug
  const vc = pieces(videos.rows).length;
  $('stat').textContent =
    (vc ? vc.toLocaleString() + ' video' + (vc === 1 ? '' : 's') + ' · ' + (videos.moments || 0).toLocaleString() + ' moment' + (videos.moments === 1 ? '' : 's') + ' in the footage · ' : '') +
    (total ? total.toLocaleString() + ' file' + (total === 1 ? '' : 's') + (d.bytes ? ' · ' + tb(d.bytes) : '')
           : vc ? 'no file names match' : '0 files');
  if (!more) clearSel();
  draw();
  remember(words);
}

function fail(what, detail) {
  $('stat').textContent = '';
  $('out').innerHTML = '<div class="panel"><div class="empty"><b>' + esc(what) + '</b>' +
    '<div class="code block" style="text-align:left;margin-top:10px">' + esc(detail) + '</div></div></div>';
}

// ── versions: one piece shows once ─────────────────────────────────────────
// Files that are versions of one piece share a key (labels.php). Of those found,
// the newest stands for the piece. Every version keeps its own description:
// only how they are shown is grouped.
function pieces(list) {
  const best = {};
  list.forEach(function (r) {
    if (!r.vkey) return;
    const b = best[r.vkey];
    if (!b || (+r.vrank || 0) > (+b.vrank || 0)) best[r.vkey] = r;
  });
  return list.filter(function (r) { return !r.vkey || best[r.vkey] === r; });
}
// What a version is, at a glance and in standard words: its shape (16:9, 9:16, 1:1 …)
// from its real size, or from its name until it has been read; its language from its name.
function aspect(w, h) {
  if (!w || !h) return '';
  const r = w / h, known = [[16, 9], [9, 16], [1, 1], [4, 5], [4, 3], [21, 9]];
  const k = known.reduce(function (a, b) { return Math.abs(b[0] / b[1] - r) < Math.abs(a[0] / a[1] - r) ? b : a; });
  return Math.abs(k[0] / k[1] - r) < .06 ? k[0] + ':' + k[1] : (r > 1 ? Math.round(r * 100) / 100 + ':1' : '1:' + Math.round(100 / r) / 100);
}
function versionTags(x) {
  const n = x.name.toLowerCase(), t = [];
  t.push(aspect(x.width, x.height) || (/vertical|9x16|instagram|(^|[\s_-])(ig|insta)([\s_.-]|$)|reels?([\s_.-]|$)|tiktok/.test(n) ? '9:16'
                                    : /square|1x1/.test(n) ? '1:1' : /youtube|(^|[\s_-])yt([\s_.-]|$)|1080p|720p|4k|uhd/.test(n) ? '16:9' : ''));
  if (/(^|[\s_-])(eng|english)([\s_.-]|$)|english/.test(n)) t.push('ENG');
  if (/(^|[\s_-])(spa|esp|spanish|espanol|español)([\s_.-]|$)|spanish/.test(n)) t.push('SPA');
  return t.filter(Boolean);
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
const still = function (fp, shot) { return 'thumb.php?fp=' + encodeURIComponent(fp) + '&shot=' + (+shot || 0); };
const versTag = function (n) { return n > 1 ? '<span class="vers">' + ICON.versions + n + '</span>' : ''; };

// A video found by what it shows: the shot that matches best, and where it is
function videoTile(m, i) {
  const speech = m.kind === 'speech';
  return '<div class="frame' + (inPull(m.path) ? ' pulled' : '') + '" tabindex="0" data-k="m' + i + '" data-p="' + esc(m.path) + '" data-t="' + (+m.start_s || 0) + '">' +
    (speech ? '<div class="ph">' + ICON.quote + '<small>' + esc(m.what) + '</small></div>'
            : '<img loading="lazy" alt="' + esc(m.what) + '" src="' + still(m.fp, m.shot) + '">') +
    '<span class="chip">' + (speech ? ICON.quote : ICON.video) + tcode(m.start_s) + '</span>' + versTag(m.versions) +
    '<span class="pk">✓</span></div>';
}
// A file: its first still if it was described, else its kind drawn with its name
function fileTile(r) {
  const k = r.kind || 'other', st = (r.still || '').split(':');
  return '<div class="frame' + (inPull(r.path) ? ' pulled' : '') + '" tabindex="0" data-k="' + esc(r.path) + '" data-p="' + esc(r.path) + '">' +
    (st.length === 2 ? '<img loading="lazy" alt="' + esc(r.name) + '" src="' + still(st[0], st[1]) + '">'
                     : '<div class="ph">' + (ICON[k] || ICON.file) + '<small>' + esc(r.name) + '</small></div>') +
    '<span class="chip">' + (ICON[k] || ICON.file) + (r.duration ? clock(r.duration) : '') +
      (res(r) ? ' <span class="r">' + res(r) + '</span>' : '') +
      (r.width && aspect(r.width, r.height) !== '16:9' ? ' <span class="r">' + aspect(r.width, r.height) + '</span>' : '') + '</span>' +
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
        (st.length === 2 ? '<span class="thumb pic"><img loading="lazy" alt="" src="' + still(st[0], st[1]) + '"></span>'
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
  const files = pieces(rows);
  const vids = videos.rows.map(function (m, i) { return [m, i]; });
  const best = pieces(videos.rows);
  const vshow = vids.filter(function (x) { return best.indexOf(x[0]) > -1; });
  if (!files.length && !vshow.length) {
    $('inspect').hidden = true;
    $('out').innerHTML = '<div class="panel"><div class="empty">' +
      ($('q').value.trim() || place || where || kind !== 'all' ? 'Nothing matches.' : 'Type a few words above: what it shows, a name, a folder, an event.') + '</div></div>';
    return;
  }
  let html = '';
  // Videos found by what they show come first, as pictures, in both views
  const shown = {};
  if (vshow.length) html += '<div class="wall">' + vshow.map(function (x) { shown[x[0].vkey || x[0].path] = 1; return videoTile(x[0], x[1]); }).join('') + '</div>';
  if (view === 'wall') {
    // footage and pictures as tiles (not again when the video is already shown); the rest as a list
    const pics = files.filter(function (r) { return (r.kind === 'video' || r.kind === 'image') && !shown[r.vkey || r.path]; });
    const rest = files.filter(function (r) { return r.kind !== 'video' && r.kind !== 'image'; });
    if (pics.length) html += (vshow.length ? '<div class="wall-h">Files</div>' : '') + '<div class="wall">' + pics.map(fileTile).join('') + '</div>';
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
  $('inspect').innerHTML = '<div style="padding:16px 14px"><p class="note" style="margin:0">Click a picture to see it here.<br>' +
    'Double-click to open it large and play it. Right-click to add it to a pull.</p></div>';
}

// ── choosing ───────────────────────────────────────────────────────────────
// One click picks a tile or a row and shows it beside; nothing plays.
// Cmd/Ctrl-click adds or removes one, Shift-click takes a run. Double-click
// opens it large. A right click offers what can be done with whatever is picked.
const SEL = new Set(); let lastEl = null;
const items = function () { return Array.prototype.slice.call($('out').querySelectorAll('[data-k]')); };
const picked = function () { return items().filter(function (x) { return SEL.has(x.dataset.k); }); };
// What a tile or row stands for: a video found by what it shows, or a file
function itemOf(el) {
  if (el.dataset.t !== undefined) {
    const m = videos.rows[+el.dataset.k.slice(1)];
    return { path: m.path, fp: m.fp, t: +m.start_s || 0, found: m.found || [], vkey: m.vkey, versions: m.versions };
  }
  const r = KNOWN[el.dataset.p] || {}, st = (r.still || '').split(':');
  return { path: el.dataset.p, fp: st.length === 2 ? st[0] : '', t: 0, found: [], vkey: r.vkey, versions: r.versions };
}
const playable = function (el) { return el.dataset.t !== undefined || (KNOWN[el.dataset.p] || {}).kind === 'video'; };
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
    $('inspect').innerHTML = '<div style="padding:16px 14px"><b>' + n + ' picked</b><p class="note" style="margin:6px 0 0">Double-click one to open it. Right-click for more.</p></div>';
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
  if (SEL.size === 1) openIn('panel', itemOf(picked()[0]));
  else if (!SEL.size) hint();
}
$('out').addEventListener('click', function (e) { const el = e.target.closest('[data-k]'); if (el) choose(el, e); });
$('out').addEventListener('keydown', function (e) {
  const el = e.target.closest('[data-k]'); if (!el) return;
  if (e.key === 'Enter') { e.preventDefault(); if (SEL.has(el.dataset.k)) openBig(el); else choose(el, e); }
  if (e.key === ' ') { e.preventDefault(); choose(el, e); }
});
$('out').addEventListener('dblclick', function (e) { const el = e.target.closest('[data-k]'); if (el) openBig(el); });
$('out').addEventListener('contextmenu', function (e) {
  const el = e.target.closest('[data-k]'); if (!el) return;
  e.preventDefault();
  if (!SEL.has(el.dataset.k)) choose(el, null);
  const paths = selPaths(), one = SEL.size === 1;
  const m = $('ctx');
  m.innerHTML =
    (one ? '<button data-do="open">Open large' + (playable(el) ? ' and play' : '') + '</button>' : '') +
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
    if (b.dataset.do === 'open') openBig(el);
    if (b.dataset.do === 'pull') addToPull(paths, null);
    if (b.dataset.do === 'copy') copyText(localPath(el.dataset.p), function (ok) { say(ok ? 'Path copied.' : 'The browser would not copy: press ⌘C'); });
  };
});
document.addEventListener('click', function (e) {
  if (!e.target.closest('#ctx')) $('ctx').hidden = true;
  if (!e.target.closest('.pm')) $('pullsDd').hidden = true;
});
document.addEventListener('scroll', function () { $('ctx').hidden = true; }, true);
document.addEventListener('keydown', function (e) {
  if ($('big').open) {
    if (e.key === 'ArrowLeft' && !$('bigPrev').disabled) { e.preventDefault(); stepBig(-1); }
    if (e.key === 'ArrowRight' && !$('bigNext').disabled) { e.preventDefault(); stepBig(1); }
    return;
  }
  if (e.key !== 'Escape' || document.querySelector('dialog[open]')) return;
  if (!$('ctx').hidden || !$('pullsDd').hidden || !$('recentDd').hidden) { $('ctx').hidden = $('pullsDd').hidden = $('recentDd').hidden = true; return; }
  clearSel(); hint();
});
$('selAdd').onclick = function () { addToPull(selPaths(), $('selAdd')); };
$('selClear').onclick = function () { clearSel(); hint(); };
// every action says it happened, for a few seconds: beside the count, and in the big view when it is open
let saidT = null;
function say(t) {
  document.querySelectorAll('.said').forEach(function (x) { x.textContent = t; });
  clearTimeout(saidT); saidT = setTimeout(function () { document.querySelectorAll('.said').forEach(function (x) { x.textContent = ''; }); }, 4000);
}

// ── one video, opened ──────────────────────────────────────────────────────
// The same pieces in the panel beside (a click) and in the big view (a
// double-click): the name with Pull and Copy, the picture that plays, the
// versions, what the shot shows, every shot of the video, then the details.
const SHOTS = {};          // every shot of a video, by fingerprint, once asked
let PANEL = null, BIG = null;
async function rowOf(path) {
  if (!KNOWN[path]) { try { (await getJSON({ p: path, limit: 1 })).rows.forEach(function (r) { KNOWN[r.path] = r; }); } catch (e) {} }
  return KNOWN[path] || { path: path, name: path.split('/').pop() };
}
async function shotsOf(fp) {
  if (!fp) return [];
  if (!SHOTS[fp]) { try { SHOTS[fp] = (await getJSON({ shots: fp })).shots; } catch (e) { SHOTS[fp] = []; } }
  return SHOTS[fp];
}
async function openIn(where, it) {
  const st = { it: it, row: await rowOf(it.path), shots: await shotsOf(it.fp), t: it.t, playing: where === 'big' };
  if (where === 'panel') { PANEL = st; if (SEL.size === 1) render('panel'); }
  else { BIG = st; render('big'); }
}
const shotAt = function (st) {
  let cur = st.shots[0];
  st.shots.forEach(function (s) { if (s.kind === 'shot' && +s.start_s <= st.t + .01) cur = s; });
  return cur;
};
const kv = function (k, v, wide) { return v ? '<div' + (wide ? ' class="wide"' : '') + '><small>' + k + '</small><span>' + v + '</span></div>' : ''; };
const tagList = function (v) { return v ? '<div class="tags">' + v.split(' · ').filter(Boolean).map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('') + '</div>' : ''; };
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
// The shoot a file belongs to; a folder an edit is saved in (Output …) is not one
function shootOf(r) {
  if (r.event && !/^(outputs?|exports?|renders?|deliverables?|finals?|old|versions?|backups?)$/i.test(r.event)) return r.event;
  const bits = trail(r.path || '').filter(function (b) { return !/^(outputs?|exports?|renders?|deliverables?|finals?|old|versions?|backups?)$/i.test(b) && !/^(19|20)\d\d$/.test(b); });
  return bits.pop() || r.event || '';
}

function render(where) {
  const st = where === 'big' ? BIG : PANEL; if (!st) return;
  const r = st.row, it = st.it, cur = shotAt(st), big = where === 'big';
  const fp = it.fp, t = cur && cur.kind === 'shot' ? +cur.start_s : st.t;
  const canPlay = r.kind === 'video' || it.found.length || !!fp;
  const pic = cur && cur.kind === 'shot' ? '<img alt="" src="' + still(fp, cur.shot) + '">'
            : '<div class="ph">' + (ICON[r.kind] || ICON.file) + '</div>';
  // Every keyword of the whole video: the big view offers them as searches
  const words = {};
  st.shots.forEach(function (s) { [s.tags, s.themes, s.on_screen].forEach(function (v) {
    (v || '').split(' · ').forEach(function (w) { w = w.trim(); if (w) words[w.toLowerCase()] = words[w.toLowerCase()] || w; }); }); });
  const found = {}; it.found.forEach(function (x) { found[(+x).toFixed(2)] = 1; });
  const html =
    '<div' + (big ? '' : '') + '>' +
      (big ? '' : head(r)) +
      '<div class="vb">' +
        '<div class="pv" data-pv>' + pic + (!st.playing && canPlay ? '<span class="go">▶</span>' : '') + '</div>' +
        (canPlay ? '<p class="pv-note" data-pvn>' + (st.playing ? 'Loading its proxy from the archive…' : 'Click to play' + (t ? ' from ' + tcode(t) : '') + '.') + '</p>' : '') +
        (big ? (st.shots.length ? '<div class="sec">In this video · ' + st.shots.filter(function (s) { return s.kind === 'shot'; }).length + ' shots</div>' + strip(st, found) : '') +
               (Object.keys(words).length ? '<div class="sec">Keywords</div><div class="tags">' +
                 Object.keys(words).slice(0, 24).map(function (k) { return '<button data-kw="' + esc(words[k]) + '">' + esc(words[k]) + '</button>'; }).join('') + '</div>' : '')
             : versionsBox(r, it) + describe(cur) + (st.shots.length ? '<div class="sec">In this video</div>' + strip(st, found) : '') + details(r, cur)) +
      '</div>' +
    '</div>' +
    (big ? '<div>' + head(r) + '<div class="vb">' + facts(r, true) + versionsBox(r, it) + describe(cur) + details(r, cur, true) + '</div></div>' : '');
  const box = big ? $('bigBody') : $('inspect');
  box.hidden = false; box.innerHTML = html;
  wire(box, st, where);
  if (st.playing) startVideo(box, r.path, t);
}
function head(r) {
  return '<div class="vh"><b>' + esc(r.name) + '</b>' +
    (pullable(r.path) ? '<button class="ib' + (inPull(r.path) ? ' in' : '') + '" data-do="pull" title="' + (inPull(r.path) ? 'In the pull' : 'Add to pull') + '">' + ICON.pull + '</button>' : '') +
    '<button class="ib" data-do="copy" title="Copy its path">' + ICON.copy + '</button></div>' +
    '<div class="said" style="padding:0 14px;font-size:12px"></div>';
}
function describe(cur) {
  if (!cur) return '';
  return '<div class="sec">Description</div><div class="desc">' + (cur.kind === 'speech' ? '“' + esc(cur.what) + '”' : esc(cur.what)) +
    '<small>' + (cur.kind === 'speech' ? 'said' : 'shot ' + ((+cur.shot || 0) + 1)) + ' · ' + tcode(cur.start_s) + ' → ' + tcode(cur.end_s) + '</small></div>';
}
function strip(st, found) {
  const cur = shotAt(st);
  return '<div class="strip">' + st.shots.filter(function (s) { return s.kind === 'shot'; }).map(function (s) {
    return '<button data-shot="' + (+s.start_s) + '" class="' + (found[(+s.start_s).toFixed(2)] ? 'hit ' : '') + (s === cur ? 'cur' : '') + '" title="' +
      esc(s.what) + '"><img loading="lazy" alt="" src="' + still(s.fp, s.shot) + '"><span>' + tcode(s.start_s) + '</span></button>';
  }).join('') + '</div>';
}
function versionsBox(r, it) {
  const n = r.versions || it.versions;
  return n > 1 ? '<div class="sec">' + n + ' versions</div><div class="vlist" data-vers="' + esc(r.vkey || it.vkey) + '"><div class="vrow"><span>Looking…</span></div></div>' : '';
}
function facts(r, grid) {
  return '<div class="kv">' +
    kv('Dimensions', r.width ? r.width + ' × ' + r.height : '') + kv('File type', esc((r.ext || '').toUpperCase())) +
    kv('Codec', esc((r.codec || '').toUpperCase())) + kv('Length', r.duration ? clock(r.duration) : '') +
    kv('Frame rate', r.fps ? (+r.fps).toFixed(3).replace(/\.?0+$/, '') : '') + kv('Size', r.bytes ? tb(r.bytes) : '') +
    kv('Resolution', res(r)) + kv('Shoot', esc(shootOf(r))) + '</div>';
}
function details(r, cur, big) {
  const shot = cur && cur.kind === 'shot' ? cur : null;
  return '<div class="facts">' +
    (shot ? '<div class="sec">This shot</div><div class="kv">' +
      kv('Text on screen', tagList(shot.on_screen), true) + kv('Themes', tagList(shot.themes), true) + kv('Tags', tagList(shot.tags), true) +
      kv('Shot size', esc(shot.shot_size)) + kv('People', esc(shot.people)) + kv('Light', esc(shot.light)) + kv('Mood', esc(shot.mood)) +
      kv('Part of the day', esc(shot.part_of_day)) + '</div>' : '') +
    '<div class="sec">The file</div>' + (big ? '' : facts(r)) + '<div class="kv" style="margin-top:10px">' +
      kv('Kind', esc(r.kind || 'file')) +
      (r.recorded ? kv('Recorded', esc(recorded(r.recorded)) + ' <small style="display:inline;text-transform:none;letter-spacing:0">(the camera\'s clock)</small>', true) : '') +
      kv('Camera', esc(r.camera)) + kv('Timecode', esc([r.timecode, r.reel ? 'reel ' + r.reel : ''].filter(Boolean).join(' · '))) +
      kv('Copies', copiesText(r.copies), true) +
      (r.proxy_at ? kv('Plays from', 'its proxy (downloads and pulls use the original)', true) : '') +
      (r.drive ? kv('Drive', esc(r.drive) + (r.away ? ' &mdash; <b>not plugged in</b>: plug it in to open this file' : ' (plugged in)'), true) : '') +
      kv('Where it lives', esc(short(r.path)), true) +
    '</div></div>';
}
function startVideo(box, path, t) {
  const pv = box.querySelector('[data-pv]'), note = box.querySelector('[data-pvn]');
  if (!pv) return;
  const was = pv.querySelector('img');               // the shot's still stays on screen while the proxy loads
  pv.innerHTML = '<video controls autoplay playsinline' + (was ? ' poster="' + esc(was.getAttribute('src')) + '"' : '') + ' src="play.php?p=' + encodeURIComponent(path) + (t ? '#t=' + t : '') + '"></video>';
  const v = pv.querySelector('video');
  v.onloadeddata = function () { if (note) note.textContent = 'Playing its proxy' + (t ? ' from ' + tcode(t) : '') + ' (the original stays where it is).'; };
  v.onerror = function () { if (note) note.textContent = 'No proxy to play yet. Proxies are made in Manage → Describe.'; };
  v.play().catch(function () {});
}
function wire(box, st, where) {
  const r = st.row;
  box.querySelectorAll('[data-do="copy"]').forEach(function (b) {
    b.onclick = function () { copyText(localPath(r.path), function (ok) {
      b.innerHTML = ok ? '✓' : '!'; say(ok ? 'Path copied.' : 'The browser would not copy: press ⌘C');
      setTimeout(function () { b.innerHTML = ICON.copy; }, 1600); }); };
  });
  box.querySelectorAll('[data-do="pull"]').forEach(function (b) {
    b.onclick = async function () { await addToPull([r.path], null); b.classList.toggle('in', inPull(r.path)); b.title = inPull(r.path) ? 'In the pull' : 'Add to pull'; };
  });
  // the picture is the player: a click plays it there, from the shot shown
  const pv = box.querySelector('[data-pv]');
  if (pv && !st.playing && box.querySelector('.go')) pv.onclick = function () { st.playing = true; const n = box.querySelector('[data-pvn]'); if (n) n.textContent = 'Loading its proxy from the archive…';
    startVideo(box, r.path, (shotAt(st) && shotAt(st).kind === 'shot') ? +shotAt(st).start_s : st.t); };
  // a shot of the video: shown, and played from there if it is playing
  box.querySelectorAll('[data-shot]').forEach(function (b) {
    b.onclick = function () {
      st.t = +b.dataset.shot;
      const v = box.querySelector('[data-pv] video');
      if (v) { v.currentTime = st.t; v.play().catch(function () {}); st.playing = true; }
      const keep = v ? v : null;
      render(where);
      if (keep) { const pv2 = box.querySelector('[data-pv]'); pv2.innerHTML = ''; pv2.appendChild(keep); }
    };
  });
  box.querySelectorAll('[data-kw]').forEach(function (b) {
    b.onclick = function () { $('big').close(); $('q').value = b.dataset.kw; run(); };
  });
  const vl = box.querySelector('[data-vers]');
  if (vl) loadVersions(vl, st, where);
}
// Every version of the piece, newest first, with what each one is; one click shows that version
async function loadVersions(box, st, where) {
  let d;
  try { d = await getJSON({ v: box.dataset.vers, limit: 100 }); } catch (e) { box.innerHTML = '<div class="vrow"><span>Could not list them: ' + esc(e.message) + '</span></div>'; return; }
  d.rows.forEach(function (x) { KNOWN[x.path] = x; });
  d.rows.sort(function (a, b) { return (+b.vrank || 0) - (+a.vrank || 0) || a.name.localeCompare(b.name); });
  box.innerHTML = d.rows.map(function (x, i) {
    return '<button class="vrow' + (x.path === st.row.path ? ' cur' : '') + '" data-vp="' + esc(x.path) + '" title="' + esc(short(x.path)) + '">' +
      '<span>' + esc(x.name) + versionTags(x).map(function (t) { return '<i>' + esc(t) + '</i>'; }).join('') + '</span>' +
      '<small>' + (i === 0 ? 'newest · ' : '') + tb(x.bytes) + '</small></button>';
  }).join('');
  box.querySelectorAll('[data-vp]').forEach(function (b) {
    b.onclick = function () {
      const x = KNOWN[b.dataset.vp], s2 = (x.still || '').split(':');
      openIn(where, { path: x.path, fp: s2.length === 2 ? s2[0] : '', t: 0, found: [], vkey: x.vkey, versions: x.versions });
    };
  });
}

// The big view: a double-click; ‹ › walk through what was found without closing
let bigAt = -1;
function openBig(el) {
  const all = items(); bigAt = all.indexOf(el);
  $('bigPrev').disabled = bigAt <= 0; $('bigNext').disabled = bigAt >= all.length - 1;
  if (!$('big').open) $('big').showModal();
  $('bigBody').innerHTML = '<p class="note">Opening…</p>';
  openIn('big', itemOf(el));
}
function stepBig(d) { const all = items(), el = all[bigAt + d]; if (el) { choose(el, null); openBig(el); } }
$('bigPrev').onclick = function () { stepBig(-1); };
$('bigNext').onclick = function () { stepBig(1); };
$('bigClose').onclick = function () { $('big').close(); };
$('big').addEventListener('close', function () { $('bigBody').querySelectorAll('video').forEach(function (v) { v.pause(); }); $('bigBody').innerHTML = ''; BIG = null; });
$('big').addEventListener('click', function (e) { if (e.target === $('big')) $('big').close(); });   // a click outside closes it

// The path as this computer reaches it, for Copy path
function localPath(p) {
  const mine = store('archiveBase');
  return !p.startsWith(ROOT) ? p : mine ? (/^([A-Za-z]:|\\\\)/.test(mine)
      ? mine + '\\' + short(p).replace(/\//g, '\\') : mine + '/' + short(p))
    : p.replace(ARCHIVE, LOCAL);
}
const pullable = function (p) { return p.startsWith(ROOT) && !(RUSHES.drives || []).some(function (d) { return p.startsWith(d.path + '/'); }); };

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
  if (!todo.length) return say('Already in ' + PULL.name + '.');
  const was = btn ? btn.textContent : ''; if (btn) btn.disabled = true;
  let n = 0;
  try {
    for (const p of todo) {
      if (btn) btn.textContent = 'Adding ' + (todo.length > 1 ? (n + 1) + ' of ' + todo.length : '') + '…';
      await pullPost({ action: 'add', p: PULL.slug, path: p }); n++;
    }
    await refreshPull();
    say((n === 1 ? 'Added to ' : 'Added ' + n + ' to ') + PULL.name + (paths.length > n ? ' (' + (paths.length - n) + ' already in it)' : '') + '.');
    if (btn) btn.textContent = '✓ Added ' + n;
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
  if (pending) { const x = pending; pending = null; await addToPull(x[0], x[1]); }
  if (PANEL) render('panel');
  if (BIG && $('big').open) { BIG.playing = false; render('big'); }
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
