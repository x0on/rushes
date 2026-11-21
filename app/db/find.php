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
// Inside Premiere (the panel in premiere/ shows this page with ?premiere=1): the same Search, dressed as a Premiere
// panel, with the pull being filled along the bottom and Copy and import on it. A panel is a browser of its own,
// so the question "who is using Rushes here?" is answered for it.
$PP = isset($_GET['premiere']);
if ($PP) header('Cache-Control: no-store');     // a panel keeps what it is given: an older Rushes' page must not outlive the update (0.14.3)
if ($PP && empty($_COOKIE['rushes_who'])) setcookie('rushes_who', 'Premiere', ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
?><!doctype html>
<html lang="en"<?= $PP ? ' class="pp" data-theme="dark"' : '' ?>>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Search &middot; Rushes</title>
<script>
  window.RUSHES = <?= json_encode([
    'premiere' => $PP,
    'version' => trim((string)@file_get_contents(__DIR__ . '/../VERSION')),
    'archive' => archive_dir(),
    'mac'     => on_mac(),
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
  /* plain text buttons: no boxes in the header */
  .plain { display: inline-flex; align-items: center; gap: 7px; border: 0; background: none; color: var(--fg);
           font: 13.5px var(--font); padding: 8px 6px; cursor: pointer; white-space: nowrap }
  .plain:hover { color: var(--accent-text) }
  .plain svg { width: 16px; height: 16px }
  .plain.pulls { padding: 9px 12px; gap: 8px; font-size: 14px }
  .plain.pulls svg { width: 18px; height: 18px }
  .plain.pulls .chev { width: 14px; height: 14px; color: var(--muted) }
  .plain.pulls:hover { background: var(--raised); color: var(--fg) }
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
  /* the tool strip down the left edge, always there; the Filters panel beside it when wanted */
  .with-rail { grid-template-columns: 50px 280px minmax(0, 1fr) }
  .with-rail:has(> #filters[hidden]) { grid-template-columns: 50px minmax(0, 1fr) }
  .tools { border-right: 1px solid var(--line); padding: 12px 0; display: flex; flex-direction: column; align-items: center; gap: 6px }
  .tool { width: 36px; height: 36px; display: grid; place-items: center; border: 0; background: none; color: var(--muted); cursor: pointer; position: relative }
  .tool svg { width: 19px; height: 19px }
  .tool:hover, .tool[aria-pressed="true"] { color: var(--fg); background: var(--raised) }
  .tool b { position: absolute; top: 4px; right: 4px; width: 7px; height: 7px; border-radius: 50%; background: var(--accent-text); display: none }
  .tool.on b { display: block }
  #filters[hidden] { display: none }
  #filters { padding: 0; gap: 0 }
  #filters .fh { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px 10px; border-bottom: 1px solid var(--line) }
  #filters .fh b { font-size: 14px }
  #filters details { border-bottom: 1px solid var(--line) }
  #filters summary { list-style: none; display: flex; justify-content: space-between; align-items: center; padding: 12px 16px;
                     font-size: 13px; font-weight: 600; cursor: pointer }
  #filters summary::-webkit-details-marker { display: none }
  #filters summary::after { content: '⌄'; color: var(--muted); font-size: 14px; transition: transform .15s }
  #filters details:not([open]) summary::after { transform: rotate(90deg) }
  #filters summary small { color: var(--accent-text); font-weight: 400; margin-left: auto; margin-right: 10px; font-size: 11.5px }
  .opts { padding: 0 16px 12px 18px; display: flex; flex-direction: column; gap: 2px }
  .opts label { display: flex; align-items: center; gap: 9px; font-size: 13px; padding: 4px 0; cursor: pointer; color: var(--fg) }
  .opts label.sub { padding-left: 22px; font-size: 12.5px }
  .opts input[type=radio], .opts input[type=checkbox] { width: 15px; height: 15px; accent-color: var(--accent); margin: 0; flex: none }
  .opts label span { flex: 1 } .opts label small { color: var(--faint); font-size: 11px }
  .opts input[type=range] { width: 100%; accent-color: var(--accent) }
  .opts .note { font-size: 11.5px; margin: 2px 0 0 }
  /* the line under the search: count, filters on, what is picked, what just happened */
  .statline { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; color: var(--muted); font-size: 12.5px; margin: 0 0 12px; min-height: 28px }
  #active { display: contents }
  .fchip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 6px 3px 10px; font: 12px var(--font);
           background: var(--sel-bg); color: var(--sel-fg); border: 0; cursor: pointer }
  .fchip b { font-weight: 400; opacity: .6; font-size: 13px }
  .sugg { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 0 0 12px; font-size: 12px; color: var(--muted) }
  .sugg .fchip { padding-right: 10px }
  #selbar[hidden] { display: none }
  #selbar { display: flex; gap: 8px; align-items: center; color: var(--fg) }
  .said { color: var(--ok) }
  .work { overflow: hidden }
  .pad { flex: 1; min-height: 0; display: flex; flex-direction: column; padding-bottom: 0 }
  .results { flex: 1; min-height: 0; display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 16px; align-items: stretch }
  #out { min-height: 0; overflow-y: auto }
  .results:has(> #inspect[hidden]) { grid-template-columns: minmax(0, 1fr) }   /* only before anything is found */
  @media (max-width: 1100px) { .results { grid-template-columns: minmax(0, 1fr) } .inspect { display: none } }
  @media (max-width: 640px) { .shoot .sh .n { display: none } .row { gap: 8px; padding-left: 10px; padding-right: 10px }
                              .q select { max-width: 110px } .plain span { display: none }
                              .with-rail { grid-template-columns: 44px minmax(0, 1fr) } #filters { position: fixed; z-index: 15; left: 44px; top: 0; bottom: 0; width: 280px } }
  /* ── the grid: every picture the same size, square corners, columns that line up ── */
  .wall { display: grid; grid-template-columns: repeat(auto-fill, minmax(var(--tile, 220px), 1fr)); gap: 4px; margin: 0 0 18px }
  .frame { position: relative; aspect-ratio: 16 / 9; background: #000; cursor: pointer; user-select: none; outline: 0; overflow: hidden }
  .frame img { width: 100%; height: 100%; object-fit: contain; display: block }   /* a vertical clip sits on black, the grid stays */
  .frame:hover img { filter: brightness(1.08) }
  .frame.on { box-shadow: inset 0 0 0 3px var(--accent) }
  .frame:focus-visible { box-shadow: inset 0 0 0 3px var(--muted) }
  .frame .ph { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
               color: var(--muted); background: var(--surface); border: 1px solid var(--line); padding: 10px 10px 30px }
  .frame .ph svg { width: 28px; height: 28px; opacity: .7 }
  .frame .ph small { font-size: 11.5px; text-align: center; max-width: 100%; overflow: hidden;
                     display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; word-break: break-word }
  /* Pull and Copy, on the picture: shown on the one under the mouse and the one picked */
  .acts { position: absolute; top: 6px; right: 6px; display: none; gap: 4px }
  .frame:hover .acts, .frame.on .acts, .frame:focus-within .acts { display: flex }
  .acts button { width: 28px; height: 28px; display: grid; place-items: center; border: 0; padding: 0; cursor: pointer;
                 background: rgba(0,0,0,.66); color: #fff }
  .acts button:hover { background: var(--accent); color: var(--accent-fg) }
  .acts button svg { width: 15px; height: 15px }
  .acts button.in { background: var(--ok); color: #fff }
  /* where in the video the match is: a line the width of the video, a tick at each moment */
  .tl { position: absolute; left: 0; right: 0; bottom: 0; height: 4px; background: rgba(0,0,0,.55) }
  .tl i { position: absolute; top: -3px; width: 3px; height: 7px; background: #fff; box-shadow: 0 0 0 1px rgba(0,0,0,.5) }
  .chip, .vers { position: absolute; bottom: 8px; display: inline-flex; align-items: center; gap: 5px; padding: 2px 7px;
                 background: rgba(0,0,0,.66); color: #fff; font: 600 11px var(--font) }
  .chip { left: 6px } .vers { right: 6px }
  .chip svg, .vers svg { width: 12px; height: 12px }
  .chip .r { opacity: .85 }
  .frame .pk { position: absolute; top: 6px; left: 6px; display: none; padding: 2px 7px; background: var(--ok); color: #fff; font: 600 11px var(--font) }
  .frame.pulled .pk { display: block }
  .wall-h { font-size: 12px; color: var(--muted); margin: 4px 0 8px; font-weight: 600 }
  /* ── the list: files by the folder they are in ── */
  .shoot { margin: 0 0 12px }
  .shoot > header.sh { background: none; border-bottom: 1px solid var(--line); align-items: flex-start }
  .shn { min-width: 0; color: var(--muted); font-size: 12px; font-weight: 400; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
  .shn b { color: var(--fg); font-size: 13px; font-weight: 600 }
  .shn .sep { color: var(--faint); margin: 0 5px }
  .row { padding-top: 6px; padding-bottom: 6px }
  .row.pick { user-select: none; position: relative }
  .playbtn { border: 0; cursor: pointer; font-size: 13px; padding: 0 }
  .playbtn:hover, .row.playing .playbtn { background: var(--accent); color: var(--accent-fg) }
  .row .prog { position: absolute; left: 0; bottom: 0; height: 2px; width: 0; background: var(--accent-text); transition: width .25s linear }
  .row.pick.on { box-shadow: inset 3px 0 0 var(--accent); background: var(--raised); color: var(--fg) }
  .row.pick.on .nm small, .row.pick.on .sz { color: var(--muted); opacity: 1 }
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
  /* a tile that plays where it is (a click), with the player's own controls; the picture comes back when it stops */
  .frame.playing video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; background: #000; display: block }
  .frame.playing .chip, .frame.playing .tl, .frame.playing .vers { display: none }
  .frame .ph small.np { opacity: .7; font-size: 10.5px }
  .pager { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin: 16px 0 }
  .pager button { min-width: 34px; padding: 6px 9px; border-radius: var(--radius-sm); border: 1px solid var(--line); background: var(--surface); color: var(--fg); font: 13px var(--font); cursor: pointer }
  .pager button.cur { background: var(--accent); color: var(--accent-fg, #fff); border-color: var(--accent); cursor: default }
  .pager button:disabled { opacity: .4; cursor: default }
  .pager .note { margin-left: 8px }
  /* the page numbers keep one place, at the foot of the results, on a full page and on a short one (in Premiere 0.14.4, everywhere 0.14.5) */
  #out { display: flex; flex-direction: column } #out > * { flex: none }
  #out > .pager { position: sticky; bottom: 0; z-index: 3; margin: auto 0 0; padding: 8px 0; background: var(--bg); border-top: 1px solid var(--line-soft) }
  .inspect .px { position: sticky; top: 6px; float: right; margin: 6px 8px 0 0; z-index: 2; width: 26px; height: 26px; border-radius: 50%; border: 1px solid var(--line); background: var(--surface); color: var(--fg); cursor: pointer; font-size: 15px; line-height: 1 }
  .more { display: block; width: 100%; margin: 16px 0; padding: 10px; border-radius: var(--radius-sm); border: 1px solid var(--line);
          background: var(--surface); color: var(--fg); font: 13.5px var(--font); cursor: pointer }
  /* ── one video, opened: the panel beside (small) and the big view (double-click) share these ── */
  .inspect { min-height: 0; overflow-y: auto; margin-bottom: 16px; align-self: stretch }
  .vh { padding: 14px 14px 8px }
  .vh b { display: block; font-size: 14px; overflow-wrap: anywhere }
  .vbtns { display: flex; gap: 8px; padding: 0 14px 12px }
  .vbtns button { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 40px; padding: 0 12px;
                  font: 600 13.5px var(--font); cursor: pointer; border: 1.5px solid var(--fg); background: none; color: var(--fg) }
  .vbtns button svg { width: 17px; height: 17px }
  .vbtns button:hover { background: var(--raised) }
  .vbtns .go-pull { background: var(--accent); border-color: var(--accent); color: var(--accent-fg) }
  .vbtns .go-pull:hover { background: var(--accent); filter: brightness(1.12) }
  .vbtns .in { background: var(--ok); border-color: var(--ok); color: #fff }
  .bigname { flex: 1; min-width: 0; font-size: 18px; font-weight: 600; align-self: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
  .ib { flex: none; width: 34px; height: 34px; display: grid; place-items: center; border: 1.5px solid var(--fg); border-radius: 0;
        background: none; color: var(--fg); cursor: pointer; padding: 0 }
  .ib svg { width: 17px; height: 17px }
  .ib:hover { background: var(--raised) }
  .ib.go-pull { background: var(--accent); border-color: var(--accent); color: var(--accent-fg) }      /* the one action we want taken */
  .ib.go-pull:hover { filter: brightness(1.12) }
  .ib.in { background: var(--ok); border-color: var(--ok); color: #fff }
  .ib[hidden] { display: none }
  .vb { padding: 0 14px 14px }
  .pv-tag { display: inline-block; margin: 0 0 6px; padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 600;
            background: var(--warn-bg); color: var(--warn) }
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
  .spin { display: inline-block; width: 10px; height: 10px; border: 2px solid var(--line); border-top-color: var(--accent);
          border-radius: 50%; animation: spin .8s linear infinite; vertical-align: -1px; margin-right: 4px }
  @keyframes spin { to { transform: rotate(360deg) } }
  details.more { border: 0; border-top: 1px solid var(--line); border-radius: 0; background: none; padding: 0; margin-top: 18px; box-shadow: none }
  details.more > summary { list-style: none; cursor: pointer; padding: 12px 0 4px; font-size: 13px; font-weight: 600; color: var(--muted) }
  details.more > summary::-webkit-details-marker { display: none }
  details.more > summary::after { content: ' ›'; display: inline-block; transition: transform .15s }
  details.more[open] > summary::after { transform: rotate(90deg) }
  details.more .kv { margin-bottom: 6px }
  /* the big view: the video large, with room around it */
  dialog#big { width: min(1500px, calc(100vw - 32px)); max-height: calc(100vh - 32px); border: 1px solid var(--line); border-radius: 0;
               background: var(--bg); color: var(--fg); padding: 0; overflow: hidden }
  dialog#big::backdrop { background: rgba(5, 12, 12, .85) }
  .bigbar { display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding: 12px 18px 14px; border-bottom: 1px solid var(--line); margin-bottom: 18px }
  .bigbar button { border: 0; background: none; color: var(--fg); font-size: 24px; width: 44px; height: 40px; cursor: pointer }
  .bigbar button:hover:not(:disabled) { background: var(--raised) }
  .bigbar button:disabled { opacity: .3; cursor: default }
  .bigbody { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 28px; padding: 6px 28px 28px;
             max-height: calc(100vh - 90px); overflow-y: auto }
  .bigbody .pv { cursor: default }
  .bigbody .vbtns { padding: 0 0 6px }
  .bigbody .vbtns button { height: 46px; font-size: 14.5px }
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
  body:has(#pullbar:not([hidden])) .work { padding-bottom: 84px }   /* room for the pull's bar, only while there is one */
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

  /* ── inside Premiere: its greys and its blue, its small type, and only what an editor needs ── */
  html.pp.pp { --bg: #1d1d1d; --surface: #262626; --raised: rgba(255,255,255,.07); --fg: #dedede;
    --muted: rgba(255,255,255,.62); --faint: rgba(255,255,255,.4); --line: rgba(255,255,255,.13); --line-soft: rgba(255,255,255,.07);
    --accent: #2d8ceb; --accent-fg: #fff; --accent-text: #74b4f5; --sel-bg: #2d8ceb; --sel-fg: #fff;
    --code-bg: rgba(255,255,255,.06); --shadow: none; --radius: 4px; color-scheme: dark; font-size: 12.5px }
  .pp .topbar, .pp #pbOpen { display: none }
  /* a panel of any size, narrow or a whole screen: the results scroll inside it and the pull's bar stays at its foot */
  html.pp body { display: flex; flex-direction: column; height: 100vh; height: 100dvh; overflow: hidden }
  html.pp body > :not(.app) { flex: none }
  html.pp .app { flex: 1; min-height: 0; height: auto; overflow: hidden }
  .pp .pad { padding: 8px 10px 0 }
  html.pp .work { padding-bottom: 0 !important }   /* room left for the pull's bar where it floats; here it has a place of its own */
  .pp .sbar { margin-bottom: 6px; gap: 6px }
  .pp .q select { font-size: 12.5px; padding: 7px 6px 7px 9px } .pp .q input { font-size: 13px; padding: 7px 10px 7px 8px }
  .pp .statline { font-size: 12px }
  .pp .btn, .pp .ghost, .pp .plain { font-size: 12px; border-radius: 4px }
  .pp .pullbar, .pp #pullbar[hidden] { position: relative; left: auto; bottom: auto; transform: none; display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px;
    width: auto; max-width: none; margin: 0; padding: 6px 10px; border: 0; border-top: 1px solid var(--line); border-radius: 0;
    background: var(--surface); box-shadow: none }
  .pp .pullbar .pb-t b { font-size: 12.5px; max-width: 30vw } .pp .pullbar .pb-t small { font-size: 11.5px }
  .pp #out > .pager { padding: 7px 0 }
  .pp .pager button { min-width: 28px; padding: 3px 7px; font-size: 12px }
  /* menus drawn here, in the panel's greys: the Mac's own are light whatever the page wears (0.14.4) */
  .pp-selwrap { position: relative; display: inline-flex; flex: none } .pp-selwrap select { display: none }
  .pp-sel { display: inline-flex; align-items: center; gap: 6px; width: auto; border: 1px solid var(--line); border-radius: 4px; background: var(--raised); color: var(--fg);
    font: 12px var(--font); padding: 4px 8px; cursor: pointer; white-space: nowrap; max-width: 220px; overflow: hidden; text-overflow: ellipsis }
  .pp-sel::after { content: ''; flex: none; border: 4px solid transparent; border-top-color: var(--muted); margin-top: 4px }
  .q .pp-selwrap { border-right: 1px solid var(--line) } .q .pp-sel { border: 0; background: none; font-weight: 600; padding: 7px 9px }
  .pp-menu { min-width: 100%; width: max-content; max-height: 60vh; overflow-y: auto; left: 0 } .pp-menu.up { top: auto; bottom: calc(100% + 4px) } .pp-menu.right { left: auto; right: 0 }
  .pp-menu button { font-size: 12px; padding: 5px 10px } .pp-menu button.cur { color: var(--accent-text) }
  #ppBar.wait i { width: 32%; animation: sweep 1.6s ease-in-out infinite }
  .pp-grow { flex: 1 }
  #ppSaid { color: var(--muted); font-size: 12px; min-width: 0; max-width: 100%; overflow-wrap: anywhere }
  .pp-to { display: flex; align-items: center; gap: 6px; color: var(--muted); font-size: 12px; white-space: nowrap; margin: 0 }
  .pp-to select { width: auto; max-width: 180px; font-size: 12px; padding: 4px 6px }
  #ppBar { position: absolute; left: 0; right: 0; top: -1px; height: 2px; display: block } #ppBar i { display: block; height: 2px; width: 0; background: var(--accent) }
  #ppList { top: auto; bottom: calc(100% + 4px); left: 10px; max-height: 50vh; overflow-y: auto; min-width: 280px; max-width: calc(100vw - 20px) }
  #ppList div { display: flex; align-items: center; gap: 8px; padding: 4px 8px; font-size: 12px }
  #ppList div span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
  #ppList div small { color: var(--muted); flex: none } #ppList div button { width: auto; padding: 2px 7px }
</style>

<div class="app" style="grid-template-rows:1fr">
<div class="with-rail">

  <!-- The tool strip: always there, icons only, named on hover -->
  <div class="tools">
    <button class="tool" id="fBtn" aria-pressed="false" title="Filters: sort, what it is, where, what is in it"><?= icon('filter', 2) ?><b></b></button>
  </div>
  <!-- Filters: every way to narrow or order what was found, in sections that fold. Within a
       section any of the choices; between sections all of them. Drawn by the script (FILTERS). -->
  <nav class="rail" id="filters" aria-label="Filters" hidden></nav>

  <main class="work">
    <div class="pad">
      <div class="sbar">
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
          <button class="plain pulls" id="pBtn" aria-haspopup="true" title="Pulls: clips gathered for a job"><?= icon('pull', 1.9) ?><span>Pulls</span><svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
          <div class="dd" id="pullsDd" hidden></div>
        </div>
      </div>
      <div class="statline"><span id="stat">Start typing.</span><span id="active"></span>
        <span id="selbar" hidden><b id="selN"></b><button class="btn" id="selAdd"></button><button class="ghost" id="selClear">Clear</button></span>
        <span class="said" role="status"></span>
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
  <div class="bigbar"><b class="bigname" id="bigName"></b>
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
  return b >= 1e12 ? (b / 1e12).toFixed(2) + ' TB'
       : b >= 1e9    ? (b / 1e9).toFixed(1) + ' GB'
       : b >= 1e6       ? Math.round(b / 1e6) + ' MB'
       : Math.round(b / 1e3) + ' KB';
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
// kind: the search bar's menu. Everything else: the Filters panel, F below.
let kind = 'all';
let asked = '';                         // the search as it was asked, for its next page of videos (moreVideos)
let asking = null;                      // the search being waited for (run)
let rows = [], total = 0, seq = 0, timer = null, videos = { count: 0, moments: 0, rows: [] };
// Everything found, a page at a time (0.13.60): first the videos found by what they show, then what was found by
// its name alone. One result is on one page only (search.php, byname), so the pages can be counted and walked.
const PAGE = 120;
let page = 0;
const vPages = function () { return Math.ceil((videos.count || 0) / PAGE); };
const nPages = function () { return vPages() + Math.ceil(total / PAGE); };
// Properties: the panel beside (description, the shots of the video, its versions, the file). Shut until asked
// for (right-click → Properties, or the i on a tile), so the pictures have the whole width; then it follows
// what is picked until it is shut.
let PROPS = false;
let view = store('view') === 'list' ? 'list' : 'wall';
const KNOWN = {};          // every file row seen, by path: the panel and the big view find versions here too

// The Filters panel, section by section: one choice ('one', the first is "as it is") or any of several ('many').
// Shot size, people, light and mood come from what the AI wrote about each shot.
const FILTERS = [
  { k: 'sort', t: 'Sort by', one: [['', 'Best match'], ['new', 'Newest'], ['old', 'Oldest'], ['big', 'Largest'], ['long', 'Longest'], ['name', 'Name']] },
  { k: 'show', t: 'Show', show: 1 },
  { k: 'in', t: 'What it is', one: [['', 'Everything'], ['deliverables', 'Deliverables'], ['library', 'Stock library'], ['library/stock', 'Stock footage', 1],
      ['library/music', 'Music', 1], ['library/sfx', 'Sound effects', 1], ['library/templates', 'Templates', 1], ['library/graphics', 'Graphics', 1],
      ['made', 'Graphics & animation'], ['camera', 'Camera footage'], ['photos', 'Photos'], ['design', 'Design'], ['voiceover', 'Voice over'], ['recordings', 'Recordings']] },
  { k: 'ai', t: 'AI-generated', one: [['', 'Include'], ['only', 'Only AI-generated'], ['none', 'Leave it out']] },
  { k: 'where', t: 'Where', one: [['', 'Anywhere'], ['ARCHIVE', 'Archive'], ['PROJECTS', 'Projects']] },
  { k: 'orient', t: 'Orientation', many: [['h', 'Horizontal'], ['v', 'Vertical'], ['s', 'Square']] },
  { k: 'res', t: 'Resolution', many: [['4k', '4K'], ['hd', 'HD'], ['sd', 'Lower']] },
  { k: 'len', t: 'Length', many: [['s', 'Under 10 seconds'], ['m', '10 seconds to a minute'], ['l', '1 to 5 minutes'], ['xl', 'Over 5 minutes']] },
  { k: 'year', t: 'Year', many: [], closed: 1 },
  { k: 'shot', t: 'Shot size', many: [['close', 'Close-up'], ['medium', 'Medium'], ['full', 'Full'], ['wide', 'Wide']] },
  { k: 'people', t: 'People', many: [['none', 'No people'], ['one', 'One person'], ['two', 'Two'], ['few', 'A group']] },
  { k: 'light', t: 'Light', many: [['day', 'Daylight'], ['artificial', 'Artificial light'], ['golden', 'Sunrise or sunset'], ['night', 'Dusk or night']] },
  { k: 'mood', t: 'Mood', many: [], closed: 1 },
];
const F = {};
function resetF() { FILTERS.forEach(function (f) { if (f.one) F[f.k] = ''; if (f.many) F[f.k] = []; }); }
resetF();
const labelOf = function (f, v) { const o = (f.one || f.many).find(function (x) { return x[0] === v; }); return o ? o[1] : v; };
const filtering = function () { return FILTERS.some(function (f) { return f.k !== 'sort' && (f.one ? F[f.k] : f.many && F[f.k].length); }); };
let tile = +(store('tile') || 220);
document.documentElement.style.setProperty('--tile', tile + 'px');

function drawFilters() {
  $('filters').innerHTML = '<div class="fh"><b>Filters</b><button class="plain" id="fClear">Clear</button></div>' +
    FILTERS.map(function (f) {
      const on = f.one ? (F[f.k] ? labelOf(f, F[f.k]) : '') : f.many ? (F[f.k].length ? F[f.k].length + ' on' : '') : '';
      let body = '';
      if (f.show) body = '<label><input type="radio" name="f-view" value="wall"' + (view === 'wall' ? ' checked' : '') + '><span>Pictures</span></label>' +
        '<label><input type="radio" name="f-view" value="list"' + (view === 'list' ? ' checked' : '') + '><span>A list, by folder</span></label>' +
        '<div class="sec" style="margin:10px 0 4px">Picture size</div><input type="range" id="fTile" min="140" max="420" step="20" value="' + tile + '" aria-label="Picture size">';
      if (f.one) body = f.one.map(function (o) {
        return '<label' + (o[2] ? ' class="sub"' : '') + '><input type="radio" name="f-' + f.k + '" value="' + esc(o[0]) + '"' + (F[f.k] === o[0] ? ' checked' : '') + '><span>' + esc(o[1]) + '</span></label>'; }).join('');
      if (f.many) body = f.many.length ? f.many.map(function (o) {
        return '<label><input type="checkbox" data-many="' + f.k + '" value="' + esc(o[0]) + '"' + (F[f.k].indexOf(o[0]) > -1 ? ' checked' : '') + '><span>' + esc(o[1]) + '</span>' +
          (o[2] ? '<small>' + o[2] + '</small>' : '') + '</label>'; }).join('')
        : '<p class="note">' + (f.k === 'mood' ? 'Once footage is described, its moods are here.' : 'None yet.') + '</p>';
      return '<details' + (f.closed && !on ? '' : ' open') + '><summary>' + esc(f.t) + (on ? '<small>' + esc(on) + '</small>' : '') + '</summary><div class="opts">' + body + '</div></details>';
    }).join('');
  $('fClear').onclick = function () { resetF(); kind = 'all'; $('kind').value = 'all'; drawFilters(); run(); };
  $('filters').querySelectorAll('input[type=radio]').forEach(function (i) {
    i.onchange = function () {
      const k = i.name.slice(2);
      if (k === 'view') { view = i.value; store('view', view); draw(); return; }
      F[k] = i.value; drawFilters(); run();
    };
  });
  $('filters').querySelectorAll('[data-many]').forEach(function (i) {
    i.onchange = function () {
      const k = i.dataset.many;
      F[k] = i.checked ? F[k].concat([i.value]) : F[k].filter(function (x) { return x !== i.value; });
      drawFilters(); run();
    };
  });
  const r = $('fTile');
  if (r) r.oninput = function () { tile = +r.value; store('tile', String(tile)); document.documentElement.style.setProperty('--tile', tile + 'px'); };
}
// The years and moods this archive has, once
fetch('search.php?facets=1').then(function (r) { return r.json(); }).then(function (d) {
  FILTERS.find(function (f) { return f.k === 'year'; }).many = (d.years || []).map(function (y) { return [y.year, y.year, (+y.n).toLocaleString()]; });
  FILTERS.find(function (f) { return f.k === 'mood'; }).many = (d.moods || []).map(function (m) { return [m, m.charAt(0).toUpperCase() + m.slice(1)]; });
  drawFilters();
}).catch(function () {});

$('kind').onchange = function () { kind = this.value; run(); };
function showFilters(on) {
  $('filters').hidden = !on; $('fBtn').setAttribute('aria-pressed', on); store('filters', on ? '1' : '');
}
$('fBtn').onclick = function () { showFilters($('filters').hidden); };
drawFilters();
showFilters(store('filters') === '1');
// Every filter that is on, beside the count, with its ✕: a closed panel never hides one.
function drawActive() {
  const on = [];
  if (kind !== 'all') on.push(['kind', '', $('kind').selectedOptions[0].textContent]);
  FILTERS.forEach(function (f) {
    if (f.one && F[f.k] && f.k !== 'sort') on.push([f.k, '', labelOf(f, F[f.k])]);
    if (f.many) F[f.k].forEach(function (v) { on.push([f.k, v, f.t + ': ' + labelOf(f, v)]); });
  });
  if (F.sort) on.push(['sort', '', 'Sorted: ' + labelOf(FILTERS[0], F.sort)]);
  $('fBtn').classList.toggle('on', on.length > 0);
  $('active').innerHTML = on.map(function (x) {
    return '<button class="fchip" data-off="' + x[0] + '" data-v="' + esc(x[1]) + '" title="Turn this off">' + esc(x[2]) + ' <b>✕</b></button>'; }).join('');
  $('active').querySelectorAll('[data-off]').forEach(function (b) {
    b.onclick = function () {
      const k = b.dataset.off;
      if (k === 'kind') { kind = 'all'; $('kind').value = 'all'; }
      else if (Array.isArray(F[k])) F[k] = F[k].filter(function (x) { return x !== b.dataset.v; });
      else F[k] = '';
      drawFilters(); run();
    };
  });
}

async function run(more) {
  const my = ++seq;
  const offset = 0; more = false; page = 0;
  rows = [];
  drawActive();
  const words = $('q').value.trim();
  if (!words && !filtering() && kind === 'all') {
    total = 0; videos = { count: 0, moments: 0, rows: [] }; $('stat').textContent = 'Start typing.';
    clearSel(); quiet($('inspect')); $('inspect').hidden = true; return draw();
  }
  const p = new URLSearchParams({ q: words, kind: kind, limit: PAGE, offset: offset, byname: 1 });
  FILTERS.forEach(function (f) { const v = Array.isArray(F[f.k]) ? F[f.k].join(',') : F[f.k]; if (v) p.set(f.k, v); });
  if (more) p.set('only', 'files'); else asked = p.toString();     // more files: the videos are on the page already
  $('stat').textContent = 'searching…';

  let raw;
  // the search before this one is let go: its answer would not be shown, and the browser stops waiting for it
  if (asking) asking.abort();
  asking = new AbortController();
  try { raw = await (await fetch('search.php?' + p, { signal: asking.signal })).text(); }
  catch (e) { if (my !== seq || e.name === 'AbortError') return; return fail('Could not reach the archive.', e.message); }
  let d;
  try { d = JSON.parse(raw); }
  catch (e) { return fail('The archive answered, but not with a result.', raw.slice(0, 400)); }
  if (d.error) return fail('The search could not run.', d.error);
  if (my !== seq) return;                         // a newer keystroke already won

  total = d.total; rows = more ? rows.concat(d.rows) : d.rows;
  d.rows.forEach(function (r) { KNOWN[r.path] = r; });
  if (!more) videos = d.moments || { count: 0, moments: 0, rows: [] };
  // Videos found by what they show count too: "0 files" above a page of them read as a bug
  const vc = videos.count || 0;                  // every video found by what it shows, however many are on the page
  $('stat').textContent =
    (vc ? vc.toLocaleString() + ' video' + (vc === 1 ? '' : 's') + ' · ' + (videos.moments || 0).toLocaleString() + ' moment' + (videos.moments === 1 ? '' : 's') + ' in the footage · ' : '') +
    (total ? total.toLocaleString() + ' file' + (total === 1 ? '' : 's') + (d.bytes ? ' · ' + tb(d.bytes) : '')
           : vc ? 'no file names match' : '0 files');
  if (!more) clearSel();
  draw();
  remember(words);
}

// One page of what was found. The videos found by what they show fill the first pages; what was found by name
// alone fills the rest. The page asked for is fetched alone, and takes the place of the one shown.
async function goPage(n, b) {
  const my = seq, vp = vPages();
  if (n < 0 || n >= nPages() || n === page) return;
  const was = b ? b.textContent : ''; if (b) { b.disabled = true; b.textContent = '…'; }
  const p = new URLSearchParams(asked); p.delete('offset');
  if (n < vp) { p.set('only', 'videos'); p.set('voffset', n * PAGE); }
  else { p.set('only', 'files'); p.set('offset', (n - vp) * PAGE); }
  try {
    const d = await (await fetch('search.php?' + p)).json();
    if (my !== seq) return;                         // another search was made meanwhile
    if (d.error || (d.moments || {}).error) throw new Error(d.error || d.moments.error);
    if (n < vp) { videos.rows = d.moments.rows; videos.count = d.moments.count; }
    else { rows = d.rows; d.rows.forEach(function (r) { KNOWN[r.path] = r; }); }
    page = n; clearSel(); draw(); window.scrollTo(0, 0); const o = $('out').closest('.results'); if (o) o.scrollTop = 0;
    say('Page ' + (n + 1) + ' of ' + nPages() + '.');
  } catch (e) { if (b) { b.disabled = false; b.textContent = was; } say('Could not load page ' + (n + 1) + ' (' + e.message + '). Press it again.'); }
}
function pager() {
  const vp = vPages(), n = nPages();
  if (n <= 1) return '';
  const btn = function (i, label, title) { return '<button type="button" data-page="' + i + '"' + (i === page ? ' class="cur" aria-current="page"' : '') + (i < 0 || i >= n ? ' disabled' : '') + (title ? ' title="' + title + '"' : '') + '>' + label + '</button>'; };
  const want = {}; [0, n - 1, page - 2, page - 1, page, page + 1, page + 2, vp - 1, vp].forEach(function (i) { if (i >= 0 && i < n) want[i] = 1; });
  let nums = '', last = -1;
  Object.keys(want).map(Number).sort(function (a, b) { return a - b; }).forEach(function (i) { if (i > last + 1) nums += '<span class="dim">…</span>'; nums += btn(i, i + 1, i < vp ? 'found by what it shows' : 'found by name'); last = i; });
  const fp = n - vp;
  return '<nav class="pager" aria-label="Pages of results">' + btn(page - 1, '‹', 'The page before') + nums + btn(page + 1, '›', 'The next page') +
    '<span class="note">Page ' + (page + 1) + ' of ' + n.toLocaleString() + ' · ' +
      (vp && fp ? 'pages 1' + (vp > 1 ? '–' + vp : '') + ': found by what they show · ' + (fp > 1 ? 'pages ' + (vp + 1) + '–' + n : 'page ' + n) + ': found by name'
                : vp ? 'found by what they show' : 'found by name') + '</span></nav>';
}

function fail(what, detail) {
  $('stat').textContent = ''; quiet($('out'));
  $('out').innerHTML = '<div class="panel"><div class="empty"><b>' + esc(what) + '</b>' +
    '<div class="code block" style="text-align:left;margin-top:10px">' + esc(detail) + '</div></div></div>';
}

// ── versions: one piece shows once ─────────────────────────────────────────
// Files that are versions of one piece share a key (labels.php). Of those found,
// the newest stands for the piece. Every version keeps its own description:
// only how they are shown is grouped.
function pieces(list) {
  const best = {};
  const light = function (r) { return /\.(mp3|m4a|aac|mp4)$/i.test(r.name || r.path || '') ? 1 : 0; };   // the same rank: the one that plays at once
  list.forEach(function (r) {
    if (!r.vkey) return;
    const b = best[r.vkey];
    if (!b || (+r.vrank || 0) > (+b.vrank || 0) || ((+r.vrank || 0) === (+b.vrank || 0) && light(r) > light(b))) best[r.vkey] = r;
  });
  // and renders of one piece that its names do not tell apart as versions (Promo 001, 002, FINAL, 001_480; one
  // saved under a music track's name): videos beside each other, in one folder, of exactly the same length, are
  // one piece, shown once, with how many there are. The one named final stands for it, else the first found.
  // ponytail: among what is on this page; two different pieces of one folder with the same length to the hundredth
  // of a second would fold too (both are still there: Properties, the folder). Camera footage is never folded.
  const same = {}, dir = function (r) { return r.path.slice(0, r.path.lastIndexOf('/')); };
  const out = list.filter(function (r) { return !r.vkey || best[r.vkey] === r; });
  out.forEach(function (r) {
    if (!r.vkey || !(+r.duration > 0) || (r.kind && r.kind !== 'video' && r.kind !== 'shot' && r.kind !== 'speech')) return;   // made pieces only (a deliverable, a render): camera footage is never folded
    const k = dir(r) + '|' + (+r.duration).toFixed(2), g = same[k] = same[k] || [];
    g.push(r);
  });
  const drop = new Set();
  Object.keys(same).forEach(function (k) {
    const g = same[k]; if (g.length < 2) return;
    const face = g.reduce(function (a, b) { return (+b.vrank || 0) > (+a.vrank || 0) ? b : a; });
    face.alike = g.reduce(function (t, r) { return t + Math.max(1, +r.versions || 1); }, 0);
    g.forEach(function (r) { if (r !== face) drop.add(r); });
  });
  return out.filter(function (r) { return !drop.has(r); });
}
// What a version is, at a glance and in standard words: its shape (16:9, 9:16, 1:1 …)
// from its real size, or from its name until it has been read; its language from its name.
function aspect(w, h) {
  if (!w || !h) return '';
  if (Math.abs(Math.max(w, h) / Math.min(w, h) - 1.896) < .02) return w >= 4000 || h >= 4000 ? 'DCI 4K' : 'DCI';   // cinema width, 4096 × 2160
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
  if (HEAR.test(n)) t.push(n.split('.').pop().toUpperCase());                 // a sound: its format, MP3 or WAV …
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

// Pull and Copy, on the picture itself: one click, without opening anything
const acts = function (path) {
  return '<span class="acts">' + (pullable(path) ? '<button data-act="pull" class="' + (inPull(path) ? 'in' : '') + '" title="' + (inPull(path) ? 'In the pull' : 'Add to pull') + '">' +
    (inPull(path) ? '✓' : ICON.pull) + '</button>' : '') + '<button data-act="copy" title="Copy its path">' + ICON.copy + '</button>' +
    '<button data-act="info" title="Properties: what it shows, its shots, its versions, the file" style="font:600 12px var(--font)">i</button></span>';
};
// Where in the video the matches are: a line the width of the video, a tick at each
const timeline = function (found, length) {
  if (!length || !found || !found.length) return '';
  return '<span class="tl">' + found.map(function (t) { return '<i style="left:' + Math.min(99.5, t / length * 100).toFixed(1) + '%"></i>'; }).join('') + '</span>';
};
// A video found by what it shows: the shot that matches best; the chip says how long the video is
function videoTile(m, i) {
  const speech = m.kind === 'speech';
  return '<div class="frame' + (inPull(m.path) ? ' pulled' : '') + '" tabindex="0" data-k="m' + i + '" data-p="' + esc(m.path) + '" data-t="' + (+m.start_s || 0) + '">' +
    (speech ? '<div class="ph">' + ICON.quote + '<small>' + esc(m.what) + '</small></div>'
            : '<img loading="lazy" alt="' + esc(m.what) + '" src="' + still(m.fp, m.shot) + '">') +
    '<span class="chip">' + (speech ? ICON.quote : ICON.video) + (m.duration ? clock(m.duration) : 'at ' + tcode(m.start_s)) +
      (m.width && aspect(m.width, m.height) !== '16:9' ? ' <span class="r">' + aspect(m.width, m.height) + '</span>' : '') + '</span>' +
    versTag(m.alike || m.versions) + acts(m.path) + timeline(m.found, m.duration) + '<span class="pk">✓</span></div>';
}
// A file: its first still if it was described, else its kind drawn with its name
function fileTile(r) {
  const k = r.kind || 'other', st = (r.still || '').split(':');
  return '<div class="frame' + (inPull(r.path) ? ' pulled' : '') + '" tabindex="0" data-k="' + esc(r.path) + '" data-p="' + esc(r.path) + '">' +
    (st.length === 2 ? '<img loading="lazy" alt="' + esc(r.name) + '" src="' + still(st[0], st[1]) + '">'
     : k === 'image' && VIEW.test(r.name) ? '<img loading="lazy" alt="' + esc(r.name) + '" src="' + asIs(r.path) + '" onerror="this.outerHTML=\'<div class=ph>' + esc(ICON.image).replace(/'/g, '&#39;') + '</div>\'">'
     : '<div class="ph">' + (ICON[k] || ICON.file) + '<small>' + esc(r.name) + '</small>' +
         (k === 'video' ? '<small class="np">' + (r.proxy_at || r.asis === '1' ? 'not described yet' : 'no preview yet') + '</small>' : '') + '</div>') +
    '<span class="chip">' + (ICON[k] || ICON.file) + (r.duration ? clock(r.duration) : '') +
      (res(r) ? ' <span class="r">' + res(r) + '</span>' : '') +
      (r.width && aspect(r.width, r.height) !== '16:9' ? ' <span class="r">' + aspect(r.width, r.height) + '</span>' : '') + '</span>' +
    versTag(r.alike || r.versions) + acts(r.path) + '<span class="pk">✓</span></div>';
}
// In the list, a video found by what it shows is a row too: its picture, its name, where the match is
function videoRows(list) {
  return '<div class="panel shoot"><header class="sh"><div class="shn"><b>Found in the footage</b></div><span class="n">' + list.length + ' video' + (list.length === 1 ? '' : 's') + '</span></header>' +
    list.map(function (x) {
      const m = x[0], i = x[1];
      return '<div class="row pick' + (inPull(m.path) ? ' pulled' : '') + '" tabindex="0" data-k="m' + i + '" data-p="' + esc(m.path) + '" data-t="' + (+m.start_s || 0) + '">' +
        '<span class="thumb pic"><img loading="lazy" alt="" src="' + still(m.fp, m.shot) + '"></span>' +
        '<span class="nm">' + esc(m.name || m.path.split('/').pop()) + '<span class="pk">✓ in the pull</span>' +
        '<small>' + esc(m.what) + '</small><small>at ' + tcode(m.start_s) + (m.duration ? ' of ' + clock(m.duration) : '') +
        (m.found && m.found.length > 1 ? ' · ' + m.found.length + ' moments' : '') +
        (m.versions > 1 ? ' · <span class="vn">' + ICON.versions + ' ' + m.versions + '</span>' : '') + '</small></span>' +
        '<span class="sz">' + (m.bytes ? tb(m.bytes) : '') + '</span></div>';
    }).join('') + '</div>';
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
                         : k === 'audio' && HEAR.test(r.name) ? '<button class="thumb k-audio playbtn" data-act="play" title="Play it here">' + PLAY + '</button>'
                         : '<span class="thumb k-' + esc(k) + '">' + (ICON[k] || ICON.file || '') + '</span>') +
        '<span class="nm">' + esc(r.name) + '<span class="pk">✓ in the pull</span>' +
        (moved ? ' <span class="pill">in Recently Removed</span>' : '') +
        (r.drive ? ' <span class="pill"' + (r.away ? ' title="Plug in ' + esc(r.drive) + ' to open it"' : '') + '>on ' + esc(r.drive) +
          (r.away ? ' · not plugged in' : '') + '</span>' : '') +
        '<small>' + esc((r.ext || '').toUpperCase()) + (r.ext ? ' · ' : '') + esc(k) +
        (res(r) ? ' · <b class="res">' + res(r) + '</b>' : '') +
        (r.versions > 1 ? ' · <span class="vn" title="versions of this piece">' + ICON.versions + ' ' + r.versions + '</span>' : '') +
        (sc.length ? ' · <span class="side" title="' + esc(sc.join('\n')) + '">+ ' + sc.length + ' sidecar' + (sc.length === 1 ? '' : 's') + '</span>' : '') +
        '</small></span><span class="sz">' + tb(r.bytes) + '</span>' + (k === 'audio' ? '<i class="prog"></i>' : '') + '</div>';
    });
    html += '</div>';
  });
  return html;
}

function draw() {
  stopSound();                                      // a new page of results: whatever was playing stops
  const files = pieces(rows);
  const vids = videos.rows.map(function (m, i) { return [m, i]; });
  const best = pieces(videos.rows);
  const vshow = vids.filter(function (x) { return best.indexOf(x[0]) > -1; });
  if (!total && !(videos.count || 0)) {
    quiet($('inspect')); $('inspect').hidden = true;
    $('out').innerHTML = '<div class="panel"><div class="empty">' +
      ($('q').value.trim() || filtering() || kind !== 'all' ? 'Nothing matches.' : 'Type a few words above: what it shows, a name, a folder, an event.') + '</div></div>';
    return;
  }
  let html = '';
  // Words to narrow it with: what these videos have most (search.php). A click adds the word to the search;
  // taking it out of the box widens it again. Offered, never applied by itself.
  if ((videos.suggest || []).length) html += '<div class="sugg">Narrow it: ' + videos.suggest.map(function (x) {
    return '<button class="fchip" data-sugg="' + esc(x[0]) + '" title="' + x[1].toLocaleString() + ' of these videos">' + esc(x[0]) + '</button>'; }).join('') + '</div>';
  // One page, one grid: a page of videos found by what they show, or (after those) a page found by name
  if (page < vPages()) {
    html += view === 'list' ? videoRows(vshow) : '<div class="wall">' + vshow.map(function (x) { return videoTile(x[0], x[1]); }).join('') + '</div>';
  } else if (view === 'wall') {
    const pics = files.filter(function (r) { return r.kind === 'video' || r.kind === 'image'; });
    const rest = files.filter(function (r) { return r.kind !== 'video' && r.kind !== 'image'; });
    if (vPages()) html += '<div class="wall-h">Found by name: these have the words in their name or folder, not (yet) in what they show</div>';
    if (pics.length) html += '<div class="wall">' + pics.map(fileTile).join('') + '</div>';
    if (rest.length) html += (pics.length ? '<div class="wall-h">Other files</div>' : '') + listHTML(rest);
  } else html += listHTML(files);
  html += pager();
  quiet($('out')); $('out').innerHTML = html;
  $('out').querySelectorAll('[data-page]').forEach(function (b) { b.onclick = function () { goPage(+b.dataset.page, b); }; });
  $('out').querySelectorAll('[data-sugg]').forEach(function (b) {
    b.onclick = function () { $('q').value = ($('q').value.trim() + ' ' + b.dataset.sugg).trim(); run(); };
  });
  if (!SEL.size) hint();
  drawSel();
}
// The panel keeps its place while there are results, so a click never moves the pictures under the mouse
function hint() {
  if (!PROPS) { quiet($('inspect')); $('inspect').hidden = true; return; }
  quiet($('inspect')); $('inspect').hidden = false;
  $('inspect').innerHTML = '<button class="px" type="button" id="propsX" title="Close Properties">×</button><div style="padding:16px 14px"><p class="note" style="margin:0">Pick a picture to see what it shows here.<br>' +
    'A click plays it where it is. Double-click opens it large. Right-click for more.</p></div>';
  $('propsX').onclick = closeProps;
}
function closeProps() { PROPS = false; PANEL = null; quiet($('inspect')); $('inspect').hidden = true; say('Properties closed. Right-click a picture, or its i, to open them again.'); }
function openProps(el) { PROPS = true; if (!SEL.has(el.dataset.k) || SEL.size !== 1) { SEL.clear(); SEL.add(el.dataset.k); lastEl = el; drawSel(); } openIn('panel', itemOf(el)); }
// ── a tile plays where it is ──────────────────────────────────────────────
// A click on a video's picture plays it there, from the moment that was found, with the player's own controls
// (pause, the line, sound, full screen). One at a time: the one before stops and its picture comes back.
function stopTiles(but) {
  $('out').querySelectorAll('.frame.playing').forEach(function (f) {
    if (f === but) return;
    quiet(f); f.querySelectorAll('video').forEach(function (v) { v.remove(); });
    const i = f.querySelector('img'); if (i) i.hidden = false;
    f.classList.remove('playing');
  });
}
function playTile(el) {
  if (el.classList.contains('playing')) return;
  const r = el.dataset.t !== undefined ? videos.rows[+el.dataset.k.slice(1)] : KNOWN[el.dataset.p];
  if (!r || !(r.proxy_at || r.asis === '1')) { stopTiles(); say('No preview of ' + (r && r.name ? r.name : 'it') + ' yet, so it cannot play here. Previews are made in Manage.'); return; }
  stopTiles(); quiet($('inspect'));
  const img = el.querySelector('img'), t = +el.dataset.t || 0, v = document.createElement('video');
  v.controls = true; v.autoplay = true; v.playsInline = true; if (img) v.poster = img.currentSrc || img.src;
  v.src = 'play.php?p=' + encodeURIComponent(el.dataset.p) + (t ? '#t=' + t : '');
  v.onerror = function () { stopTiles(); say('Its preview would not play (is the archive there?).'); };
  if (img) img.hidden = true;
  el.classList.add('playing'); el.insertBefore(v, el.firstChild);
  v.play().catch(function () {});
}

// ── sounds: played on their own row, one at a time on the whole page ──────
const PLAY = '▶', PAUSE = '❚❚';
const SOUND = new Audio(); let soundEl = null;
function stopSound() {
  SOUND.pause();
  if (soundEl) { soundEl.classList.remove('playing'); const b = soundEl.querySelector('[data-act="play"]'); if (b) b.innerHTML = PLAY;
                 const p = soundEl.querySelector('.prog'); if (p) p.style.width = '0'; }
  soundEl = null;
}
function playSound(el, path) {
  if (soundEl === el) { stopSound(); return; }              // the same one again: stop
  stopSound();
  document.querySelectorAll('audio, video').forEach(function (m) { m.pause(); });
  SOUND.src = asIs(path); soundEl = el; el.classList.add('playing');
  el.querySelector('[data-act="play"]').innerHTML = PAUSE;
  SOUND.play().catch(function () { say('This sound cannot be played here (is its drive plugged in?).'); stopSound(); });
}
SOUND.ontimeupdate = function () { const p = soundEl && soundEl.querySelector('.prog'); if (p && SOUND.duration) p.style.width = (SOUND.currentTime / SOUND.duration * 100) + '%'; };
SOUND.onended = stopSound;
// anything else that starts playing (the panel's player, a video) stops the others
document.addEventListener('play', function (e) {
  stopSound();
  document.querySelectorAll('audio, video').forEach(function (m) { if (m !== e.target) m.pause(); });
}, true);

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
    quiet($('inspect')); $('inspect').hidden = !PROPS;
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
  if (SEL.size === 1 && PROPS) openIn('panel', itemOf(picked()[0]));
  else if (!SEL.size) hint();
}
$('out').addEventListener('click', function (e) {
  if (e.target.closest('video')) return;            // the player's own controls: Pause is Pause, nothing is picked again
  const a = e.target.closest('[data-act]');
  if (a) {
    const p = a.closest('[data-p]').dataset.p;
    if (a.dataset.act === 'info') { openProps(a.closest('[data-k]')); return; }
    if (a.dataset.act === 'play') { playSound(a.closest('[data-p]'), p); return; }
    if (a.dataset.act === 'copy') copyText(localPath(p), function (ok) {
      a.innerHTML = ok ? '✓' : '!'; say(ok ? 'Path copied.' : 'The browser would not copy: press ⌘C'); setTimeout(function () { a.innerHTML = ICON.copy; }, 1600); });
    if (a.dataset.act === 'pull') addToPull([p], null).then(function () {
      const on = inPull(p); a.classList.toggle('in', on); a.innerHTML = on ? '✓' : ICON.pull; a.title = on ? 'In the pull' : 'Add to pull'; });
    return;
  }
  const el = e.target.closest('[data-k]'); if (!el) return;
  choose(el, e);
  // a plain click on a video's picture plays it there; with ⌘ or Shift it is only picked (several, for a pull)
  if (e.metaKey || e.ctrlKey || e.shiftKey) return;
  if (el.classList.contains('frame') && playable(el)) playTile(el); else stopTiles();
});
$('out').addEventListener('keydown', function (e) {
  const el = e.target.closest('[data-k]'); if (!el) return;
  if (e.key === 'Enter') { e.preventDefault(); if (SEL.has(el.dataset.k)) openBig(el); else choose(el, e); }
  if (e.key === ' ') { e.preventDefault(); choose(el, e); }
});
// A double click opens it large, over the grid. The first of the two clicks has already started it playing in its tile,
// so the second lands on that player: it counts too (it was passed over, and nothing opened: 0.14.5), except on the
// player's own strip of controls at its foot, where two quick clicks are somebody scrubbing.
$('out').addEventListener('dblclick', function (e) {
  if (e.target.closest('[data-act]')) return;
  const v = e.target.closest('video');
  if (v && e.offsetY > v.clientHeight - 44) return;
  const el = e.target.closest('[data-k]'); if (!el) return;
  e.preventDefault();                       // not the browser's own full screen
  if (document.fullscreenElement) document.exitFullscreen().catch(function () {});
  stopTiles(); openBig(el);
});
$('out').addEventListener('contextmenu', function (e) {
  const el = e.target.closest('[data-k]'); if (!el) return;
  e.preventDefault();
  if (!SEL.has(el.dataset.k)) choose(el, null);
  const paths = selPaths(), one = SEL.size === 1;
  const m = $('ctx');
  m.innerHTML =
    (one && playable(el) && el.classList.contains('frame') ? '<button data-do="play">Play here</button>' : '') +
    (one ? '<button data-do="open">Open large' + (playable(el) ? ' and play' : '') + '</button>' : '') +
    '<button data-do="pull"' + (paths.length ? '' : ' disabled') + '>' +
      (paths.length ? 'Add ' + (paths.length > 1 ? paths.length + ' ' : '') + (PULL && PULL.name ? 'to ' + esc(PULL.name) : 'to a pull…')
                    : 'Not in the archive: cannot be pulled') + '</button>' +
    (one ? '<button data-do="copy">Copy path</button>' : '') +
    (one ? '<button data-do="props">Properties' + ((itemOf(el).versions || 0) > 1 ? ' and its ' + itemOf(el).versions + ' versions' : '') + '</button>' : '');
  m.hidden = false;
  m.style.left = Math.min(e.clientX, innerWidth - m.offsetWidth - 8) + 'px';
  m.style.top  = Math.min(e.clientY, innerHeight - m.offsetHeight - 8) + 'px';
  m.onclick = function (ev) {
    const b = ev.target.closest('[data-do]'); if (!b || b.disabled) return;
    m.hidden = true;
    if (b.dataset.do === 'open') { stopTiles(); openBig(el); }
    if (b.dataset.do === 'play') playTile(el);
    if (b.dataset.do === 'props') openProps(el);
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
  if (where === 'panel') { PANEL = st; if (SEL.size === 1 && PROPS) render('panel'); }
  else { BIG = st; render('big'); }
}
const shotAt = function (st) {
  let cur = st.shots[0];
  st.shots.forEach(function (s) { if (s.kind === 'shot' && +s.start_s <= st.t + .01) cur = s; });
  return cur;
};
const kv = function (k, v, wide) { return v ? '<div' + (wide ? ' class="wide"' : '') + '><small>' + k + '</small><span>' + v + '</span></div>' : ''; };
const tagList = function (v) { v = (v || '').split(' · ').filter(function (x) { return x.trim().length > 1; }).join(' · ');   // "5", "-": misread, not text
  return v ? '<div class="tags">' + v.split(' · ').filter(Boolean).map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('') + '</div>' : ''; };
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

// Sounds and pictures a browser plays or shows as they are (play.php sends the file itself)
const HEAR = /\.(mp3|wav|m4a|aac|aiff?|flac)$/i, VIEW = /\.(jpe?g|png|gif|webp|heic)$/i;
const asIs = function (p) { return 'play.php?p=' + encodeURIComponent(p); };
// A player about to leave the page is stopped and emptied first. Taken off the page still playing, it plays on
// unseen: its sound goes on, and it keeps writing its own shots into the description of whatever is shown now
// (several of them at once: sound over sound, and a description that flickers between videos). 0.13.59.
function quiet(box, but) {
  box.querySelectorAll('video').forEach(function (v) {
    if (v === but) return;
    v.ontimeupdate = v.onloadeddata = v.onerror = null; v.pause(); v.removeAttribute('src'); v.load();
  });
}
function render(where) {
  const st = where === 'big' ? BIG : PANEL; if (!st) return;
  const r = st.row, it = st.it, cur = shotAt(st), big = where === 'big';
  const fp = it.fp, t = cur && cur.kind === 'shot' ? +cur.start_s : st.t;
  const sound = r.kind === 'audio' && HEAR.test(r.name || ''), picture = r.kind === 'image' && VIEW.test(r.name || '');
  const proxy = !!(r.proxy_at || r.asis === '1');
  const canPlay = r.kind === 'video' && proxy, noProxy = r.kind === 'video' && !proxy;
  const pic = picture ? '<img alt="" src="' + asIs(r.path) + '">'
            : cur && cur.kind === 'shot' ? '<img alt="" src="' + still(fp, cur.shot) + '">'
            : '<div class="ph">' + (ICON[r.kind] || ICON.file) + '</div>';
  // Every keyword of the whole video: the big view offers them as searches
  const words = {};
  st.shots.forEach(function (s) { [s.tags, s.themes].forEach(function (v) {
    (v || '').split(' · ').forEach(function (w) { w = w.trim(); if (w) words[w.toLowerCase()] = words[w.toLowerCase()] || w; }); }); });
  const found = {}; it.found.forEach(function (x) { found[(+x).toFixed(2)] = 1; });
  const player = sound
    ? '<audio controls preload="none" src="' + asIs(r.path) + '" style="width:100%"></audio><p class="pv-note">Plays the file itself (the original stays where it is).</p>'
    : (r.away ? '<p class="pv-tag" title="The preview is kept on the archive; the full-quality original stays on its drive">Preview · the original is on ' + esc(r.drive) + ', not plugged in</p>' : '') +
      '<div class="pv" data-pv>' + pic + (!st.playing && canPlay ? '<span class="go">▶</span>' : '') + '</div>' +
      (canPlay ? '<p class="pv-note" data-pvn>' + (st.playing ? 'Loading its proxy from the archive…' : 'Click to play' + (t ? ' from ' + tcode(t) : '') + '.') + '</p>'
       : noProxy ? '<p class="pv-note" data-pvn>' + (st.making ? '<span class="spin"></span> Making its proxy on this Mac… it plays here as soon as it is ready.'
                   : 'No proxy yet, so it cannot play here. ' + (RUSHES.mac && pullable(r.path) ? '<button class="plain" data-do="proxy" style="padding:0;color:var(--accent-text);font-weight:600">Make its proxy now</button>'
                   : 'Proxies are made in Manage → Describe.')) + '</p>' : '');
  const shotsBox = st.shots.length ? '<div class="sec">In this video' + (big ? ' · ' + (function (n) { return n + ' shot' + (n === 1 ? '' : 's'); })(st.shots.filter(function (s) { return s.kind === 'shot'; }).length) : '') + '</div>' + strip(st, found) : '';
  const html = big
    ? '<div><div class="vb">' + player + shotsBox +
        (Object.keys(words).length ? '<div class="sec">Keywords</div><div class="tags">' +
          Object.keys(words).slice(0, 24).map(function (k) { return '<button data-kw="' + esc(words[k]) + '">' + esc(words[k]) + '</button>'; }).join('') + '</div>' : '') +
      '</div></div>' +
      '<div>' + head(r, true) + '<div class="vb">' + versionsBox(r, it) + '<div data-desc>' + describe(cur) + '</div>' + details(r, cur) + '</div></div>'
    : '<button class="px" type="button" data-do="closeprops" title="Close Properties">×</button>' + head(r) + '<div class="vb">' + player + versionsBox(r, it) + '<div data-desc>' + describe(cur) + '</div>' + shotsBox + details(r, cur) + '</div>';
  if (big) $('bigName').textContent = r.name;
  const box = big ? $('bigBody') : $('inspect');
  const keep = st.keep; st.keep = null;              // the player a click on a shot moved: it stays, playing from there
  if (keep) keep.remove();
  quiet(box); box.hidden = false; box.innerHTML = html;
  wire(box, st, where);
  const pv = box.querySelector('[data-pv]');
  if (keep && pv && canPlay) { pv.innerHTML = ''; pv.onclick = null; pv.appendChild(keep); const n = box.querySelector('[data-pvn]'); if (n) n.textContent = 'Playing its proxy (the original stays where it is).'; }
  else { if (keep) quiet({ querySelectorAll: function () { return [keep]; } }); if (st.playing && canPlay) startVideo(box, r.path, t, st); }
}
function head(r, big) {
  const on = inPull(r.path);
  return (big ? '' : '<div class="vh"><b>' + esc(r.name) + '</b></div>') +
    '<div class="vbtns">' +
    (pullable(r.path) ? '<button class="' + (on ? 'in' : 'go-pull') + '" data-do="pull">' + (on ? '✓ In the pull' : ICON.pull + 'Add to pull') + '</button>' : '') +
    '<button data-do="copy">' + ICON.copy + 'Copy path</button></div>' +
    '<div class="said" style="padding:0 14px 6px;font-size:12px"></div>';
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
// The shot's composition (described with version 4): where the eye goes, and the tags the lens worked out
function composition(c) {
  try { c = JSON.parse(c || 'null'); } catch (e) { c = null; }
  if (!c) return '';
  const words = function (l) { return (l || []).map(function (t) { return t.replace(/_/g, ' '); }).join(' · '); };
  return kv('Subject', esc(c.subject || '')) + kv('Composition', tagList(words(c.tags)) || 'nothing the guides line up with', true);
}
// Everything else about the shot and the file: folded away, one click to open
function details(r, cur) {
  const shot = cur && cur.kind === 'shot' ? cur : null;
  return '<details class="more"><summary>Details</summary>' +
    (shot ? '<div class="sec">This shot</div><div class="kv">' +
      kv('Text on screen', tagList(shot.on_screen), true) + kv('Themes', tagList(shot.themes), true) + kv('Tags', tagList(shot.tags), true) +
      kv('Shot size', esc(shot.shot_size)) + kv('People', esc(shot.people)) + kv('Light', esc(shot.light)) + kv('Mood', esc(shot.mood)) +
      kv('Part of the day', esc(shot.part_of_day)) + composition(shot.comp) + '</div>' : '') +
    '<div class="sec">The file</div><div class="kv">' +
      kv('Dimensions', r.width ? r.width + ' × ' + r.height + (aspect(r.width, r.height) ? ' · ' + aspect(r.width, r.height) : '') : '') +
      kv('File type', esc((r.ext || '').toUpperCase())) + kv('Codec', esc((r.codec || '').toUpperCase())) + kv('Length', r.duration ? clock(r.duration) : '') +
      kv('Frame rate', r.fps ? (+r.fps).toFixed(3).replace(/\.?0+$/, '') : '') + kv('Size', r.bytes ? tb(r.bytes) : '') +
      kv('Resolution', res(r)) + kv('Shoot', esc(shootOf(r))) + kv('Kind', esc(r.kind || 'file')) +
      (r.recorded ? kv('Recorded', esc(recorded(r.recorded)) + ' <small style="display:inline;text-transform:none;letter-spacing:0">(the camera\'s clock)</small>', true) : '') +
      kv('Camera', esc(r.camera)) + kv('Timecode', esc([r.timecode, r.reel ? 'reel ' + r.reel : ''].filter(Boolean).join(' · '))) +
      kv('Copies', copiesText(r.copies), true) +
      (r.proxy_at ? kv('Plays from', 'its proxy (downloads and pulls use the original)', true) : '') +
      (r.drive ? kv('Drive', esc(r.drive) + (r.away ? ' &mdash; <b>not plugged in</b>: plug it in to open this file' : ' (plugged in)'), true) : '') +
      kv('Where it lives', esc(short(r.path)), true) +
    '</div></details>';
}
function startVideo(box, path, t, st) {
  const pv = box.querySelector('[data-pv]'), note = box.querySelector('[data-pvn]');
  if (!pv) return;
  const was = pv.querySelector('img');               // the shot's still stays on screen while the proxy loads
  if (box.id === 'inspect') stopTiles();
  quiet(pv); pv.onclick = null;                      // the picture started it; from here the player's own controls do (a click on Pause is not "play")
  pv.innerHTML = '<video controls autoplay playsinline' + (was ? ' poster="' + esc(was.getAttribute('src')) + '"' : '') + ' src="play.php?p=' + encodeURIComponent(path) + (t ? '#t=' + t : '') + '"></video>';
  const v = pv.querySelector('video');
  v.onloadeddata = function () { if (note) note.textContent = 'Playing its proxy' + (t ? ' from ' + tcode(t) : '') + ' (the original stays where it is).'; };
  v.onerror = function () { if (note) note.textContent = 'No proxy to play yet. Proxies are made in Manage → Describe.'; };
  // the description and the strip follow what is on screen as it plays
  if (st && st.shots.length) v.ontimeupdate = function () {
    if (!v.isConnected) { quiet({ querySelectorAll: function () { return [v]; } }); return; }   // off the page: silent
    let cur = null;
    st.shots.forEach(function (x) { if (x.kind === 'shot' && +x.start_s <= v.currentTime + .05) cur = x; });
    if (!cur || cur === st.onScreen) return;
    st.onScreen = cur; st.t = +cur.start_s;
    const d = box.querySelector('[data-desc]'); if (d) d.innerHTML = describe(cur);
    box.querySelectorAll('.strip [data-shot]').forEach(function (b) { b.classList.toggle('cur', +b.dataset.shot === +cur.start_s); });
  };
  v.play().catch(function () {});
}
function wire(box, st, where) {
  const r = st.row;
  box.querySelectorAll('[data-do="closeprops"]').forEach(function (b) { b.onclick = closeProps; });
  box.querySelectorAll('[data-do="copy"]').forEach(function (b) {
    b.onclick = function () { copyText(localPath(r.path), function (ok) {
      b.innerHTML = ok ? '✓ Copied' : 'Press ⌘C'; say(ok ? 'Path copied.' : 'The browser would not copy: press ⌘C');
      setTimeout(function () { b.innerHTML = ICON.copy + 'Copy path'; }, 1600); }); };
  });
  box.querySelectorAll('[data-do="proxy"]').forEach(function (b) {
    b.onclick = async function () {
      b.disabled = true; b.textContent = 'Asking…';
      let j;
      try { j = await (await fetch('proxy-one.php', { method: 'POST', body: new URLSearchParams({ p: r.path }) })).json(); }
      catch (e) { j = { error: 'Rushes did not answer (' + e.message + ')' }; }
      if (j.error) { say('Not asked: ' + j.error); b.disabled = false; b.textContent = 'Make its proxy now'; return; }
      st.making = true; say('Asked: its proxy is being made on this Mac.'); render(where);
      const until = Date.now() + 20 * 60 * 1000;
      const look = async function () {
        if (!st.making) return;
        try { const d = await getJSON({ p: r.path, limit: 1 }); const x = d.rows[0];
              if (x && (x.proxy_at || x.asis === '1')) { KNOWN[x.path] = x; st.row = x; st.making = false; say('Its proxy is ready.'); render(where); return; } } catch (e) {}
        if (Date.now() > until) { st.making = false; say('Its proxy is taking long: Manage → Describe shows what the proxy maker is doing.'); render(where); return; }
        setTimeout(look, 8000);
      };
      setTimeout(look, 8000);
    };
  });
  box.querySelectorAll('[data-do="pull"]').forEach(function (b) {
    b.onclick = async function () { await addToPull([r.path], null); const on = inPull(r.path);
      b.classList.toggle('in', on); b.classList.toggle('go-pull', !on); b.innerHTML = on ? '✓ In the pull' : ICON.pull + 'Add to pull'; };
  });
  // the picture is the player: a click plays it there, from the shot shown
  const pv = box.querySelector('[data-pv]');
  if (pv && !st.playing && box.querySelector('.go')) pv.onclick = function () { st.playing = true; const n = box.querySelector('[data-pvn]'); if (n) n.textContent = 'Loading its proxy from the archive…';
    startVideo(box, r.path, (shotAt(st) && shotAt(st).kind === 'shot') ? +shotAt(st).start_s : st.t, st); };
  // a shot of the video: shown, and played from there if it is playing
  box.querySelectorAll('[data-shot]').forEach(function (b) {
    b.onclick = function () {
      st.t = +b.dataset.shot;
      const v = box.querySelector('[data-pv] video');
      if (v) { v.currentTime = st.t; v.play().catch(function () {}); st.playing = true; st.keep = v; }
      render(where);
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
$('big').addEventListener('close', function () { quiet($('bigBody')); $('bigBody').innerHTML = ''; BIG = null; });
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
  $('out').querySelectorAll('[data-p]').forEach(function (x) {
    const on = inPull(x.dataset.p); x.classList.toggle('pulled', on);
    const a = x.querySelector('[data-act="pull"]'); if (a) { a.classList.toggle('in', on); a.innerHTML = on ? '✓' : ICON.pull; }
  });
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
// ── Inside Premiere ────────────────────────────────────────────────────────
// The panel (premiere/index.js) shows this page and is spoken to in short notes: hello → project (the open
// project's name), import (the copies' paths) → imported. The pull being filled is the project's own: named after it,
// made when first needed; the Pulls menu fills another. Copy and import copies that pull to the drive chosen
// (pulls.php action=copy, the Mac's runner) and hands Premiere the copies, each one once.
// ponytail: what was imported is remembered in this panel only; Premiere's own bins are not read.
if (RUSHES.premiere) (function () {
  const host = window.uxpHost, bar = $('pullbar'), enc = encodeURIComponent;
  let project = null, heard = false, busy = false, again = false, slugNow = '';
  const waiting = {};
  const tell = function (o) { try { host.postMessage(JSON.stringify(o)); return true; } catch (e) { return false; } };
  bar.insertAdjacentHTML('beforeend', '<button class="ghost" id="ppClips" title="The clips in this pull">Clips</button><span id="ppSaid" role="status"></span><span class="pp-grow"></span>' +
    '<label class="pp-to">Copy to <select id="ppTo" aria-label="Where the clips are copied to"></select></label><button class="btn" id="ppGo" disabled>Copy and import</button>' +
    '<button class="ghost" id="ppStop" hidden>Stop</button><i id="ppBar"><i></i></i><div class="dd" id="ppList" hidden></div>');
  // what is going on, in words; how far, as the blue line; and while nothing can be counted yet (wait) the line moves, so it is not taken for stuck
  function said(t, pct, wait) {
    $('ppSaid').textContent = t; $('ppBar').className = wait ? 'wait' : '';
    if (wait) $('ppBar').firstChild.style.width = ''; else if (pct != null) $('ppBar').firstChild.style.width = Math.max(0, Math.min(100, pct)) + '%';
  }
  // A menu of the panel's own in place of a <select>'s: the select stays, unseen, and is still what the page reads and listens to.
  function ownMenu(sel, how) {
    const wrap = document.createElement('span'), b = document.createElement('button'), dd = document.createElement('div');
    wrap.className = 'pp-selwrap'; b.type = 'button'; b.className = 'pp-sel'; b.title = sel.title || ''; b.setAttribute('aria-label', sel.getAttribute('aria-label') || ''); b.setAttribute('aria-haspopup', 'listbox');
    dd.className = 'dd pp-menu ' + (how || ''); dd.hidden = true;
    sel.parentNode.insertBefore(wrap, sel); wrap.appendChild(b); wrap.appendChild(dd); wrap.appendChild(sel);
    const label = function () { const o = sel.options[sel.selectedIndex]; const t = o ? o.textContent : ''; if (b.textContent !== t) b.textContent = t; };
    b.onclick = function (e) {
      e.stopPropagation(); const open = dd.hidden;
      document.querySelectorAll('.pp-menu').forEach(function (m) { m.hidden = true; });
      if (!open) return;
      dd.innerHTML = Array.prototype.map.call(sel.options, function (o, i) { return '<button type="button" role="option" data-i="' + i + '"' + (i === sel.selectedIndex ? ' class="cur" aria-selected="true"' : '') + '>' + esc(o.textContent) + '</button>'; }).join('');
      dd.hidden = false;
    };
    dd.onclick = function (e) {
      const x = e.target.closest('button[data-i]'); if (!x) return;
      sel.selectedIndex = +x.dataset.i; dd.hidden = true; label(); sel.dispatchEvent(new Event('change', { bubbles: true }));
    };
    document.addEventListener('click', function () { dd.hidden = true; });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') dd.hidden = true; });
    setInterval(label, 400); label();        // the page also sets a select itself (a filter cleared): the button follows
    return label;
  }
  const ready = function () { $('ppGo').disabled = busy || !PULL || !$('ppTo').value; };
  ownMenu($('kind')); const toLabel = ownMenu($('ppTo'), 'up right');
  const wasRefresh = refreshPull;
  refreshPull = async function () { await wasRefresh(); ready(); if (!$('ppList').hidden) clips(); };

  fetch('/db/pulls.php?places=1').then(function (r) { return r.json(); }).then(function (d) {
    const was = store('ppTo');
    $('ppTo').innerHTML = (d.places || []).map(function (p) {
      return '<option value="' + esc(p.to) + '"' + (p.to === was ? ' selected' : '') + '>' + esc(p.to === 'desktop' ? 'Desktop' : p.name) + (p.free ? ' · ' + tb(p.free) + ' free' : '') + '</option>'; }).join('');
    toLabel();
    if (!(d.places || []).length) said('This Rushes has nowhere to copy to: copying is done by the Rushes app on a Mac.');
    ready();
  }).catch(function () { said('Could not ask Rushes where clips can be copied to.'); });

  async function setProject(name) {
    if (name === project) return; project = name;
    if (!name) return said('No project is open in Premiere: clips can be gathered and copied, not imported.');
    try {
      let p = (await pullsList()).filter(function (x) { return x.name === name; })[0];
      if (!p) p = await pullPost({ action: 'create', name: name, made_by: 'Premiere' });
      await usePull({ slug: p.slug, name: name });
      said('Project: ' + name);
    } catch (e) { said('Could not start the pull for ' + name + ': ' + e.message); }
  }
  window.addEventListener('message', function (e) {
    let d = e.data; try { if (typeof d === 'string') d = JSON.parse(d); } catch (x) { return; }
    if (!d || !d.rushes) return;
    if (d.rushes === 'project') { heard = true; setProject(String(d.name || '')); }
    if (d.rushes === 'imported' && waiting[d.id]) { waiting[d.id](d); delete waiting[d.id]; }
  });
  // Premiere keeps a panel alive for as long as it runs itself, so this page would stay the old one after Rushes is
  // updated (0.14.4 was not seen until Premiere was quit). It asks which Rushes is there now, and loads itself afresh
  // when that is a newer one; never in the middle of a copy. Reload, in the bar, does it when asked.
  bar.querySelector('.pp-to').insertAdjacentHTML('beforebegin', '<button class="ghost" id="ppReload" title="Load this panel again from Rushes (' + esc(RUSHES.version) + ')">Reload</button>');
  const reload = function (why) { said(why); setTimeout(function () { location.replace('/db/find.php?premiere=1&t=' + Date.now()); }, 1200); };
  $('ppReload').onclick = function () { if (busy) return said('A copy is running: reload when it is done.'); reload('Loading the panel again…'); };
  setInterval(function () {
    if (busy) return;
    fetch('/db/pulls.php?places=1&t=' + Date.now()).then(function (r) { return r.json(); }).then(function (d) {
      if (d.version && RUSHES.version && d.version !== RUSHES.version && !busy) reload('Rushes was updated to ' + d.version + ': loading the panel again…');
    }).catch(function () {});               // Rushes restarting for its update: asked again in a moment
  }, 30000);
  const hello = function () { if (!busy) tell({ rushes: 'hello' }); };
  hello(); setInterval(hello, 4000);                       // the project open in Premiere can change
  setTimeout(function () { if (!heard) said('Not connected to Premiere: clips can be gathered and copied here, not imported.'); }, 3000);
  const ask = function (o) { return new Promise(function (ok, no) {
    o.id = String(Date.now()); waiting[o.id] = ok;
    if (!tell(o)) { delete waiting[o.id]; return no(new Error('not connected to Premiere')); }
    setTimeout(function () { if (waiting[o.id]) { delete waiting[o.id]; no(new Error('Premiere did not answer in two minutes')); } }, 120000); }); };

  async function go() {
    if (busy || !PULL) return;
    const slug = slugNow = PULL.slug, name = PULL.name, to = $('ppTo').value; store('ppTo', to);
    busy = true; ready(); $('ppStop').hidden = false;
    try {
      said('Asking Rushes to copy ' + name + '…', 0, true);
      await pullPost({ action: 'copy', p: slug, to: to });
      let c; const t0 = Date.now();
      for (;;) {
        await new Promise(function (r) { setTimeout(r, 1000); });
        c = (await (await fetch('/db/pulls.php?p=' + enc(slug) + '&t=' + Date.now())).json()).copy || { state: 'asked' };
        if (c.state === 'asked') {
          if (Date.now() - t0 > 90000) throw new Error('Rushes has not started the copy after 90 seconds: another copy may be running (Activity, in Rushes, says).');
          said('Rushes is picking the copy up…', 0, true);
        } else if (c.state === 'copying') said('Copying ' + c.n + ' of ' + c.total + (c.file ? ' · ' + c.file : '') + ' · ' + tb(c.bytes) + ' of ' + tb(c.total_bytes), c.total_bytes ? 100 * c.bytes / c.total_bytes : 0);
        else break;
      }
      if (c.state !== 'done') throw new Error('The copy ' + (c.state === 'stopped' ? 'was stopped' : 'failed') + ': ' + (c.why || 'no reason given') + '. Nothing was imported.');
      const bad = (c.failed || []).length ? ' ' + c.failed.length + ' could not be copied (' + c.failed.map(function (f) { return f[0] + ': ' + f[1]; }).join('; ') + ').' : '';
      const files = (c.files || []).map(function (f) { return f[1]; });
      if (!files.length) throw new Error('Nothing could be copied, so nothing was imported.' + bad);
      if (!heard || !project) return said('Copied ' + files.length + ' to ' + c.dest + '. Not connected to a Premiere project, so nothing was imported.' + bad, 100);
      const key = 'ppIn:' + project; let before = []; try { before = JSON.parse(store(key) || '[]'); } catch (e) {}
      let fresh = files.filter(function (f) { return before.indexOf(f) < 0; });
      if (!fresh.length && !again) { again = true; return said('All ' + files.length + ' are copied, and were imported into ' + project + ' before. Press again to import them once more.' + bad, 100); }
      if (!fresh.length) fresh = files;
      again = false;
      said('Copied. Importing ' + fresh.length + ' into ' + project + '…', 100, true);
      const r = await ask({ rushes: 'import', files: fresh });
      if (!r.ok) throw new Error('The copies are in ' + c.dest + ', but Premiere did not import them: ' + (r.error || 'no reason given'));
      store(key, JSON.stringify(before.concat(fresh.filter(function (f) { return before.indexOf(f) < 0; })).slice(-5000)));
      said('Imported ' + fresh.length + (fresh.length === 1 ? ' clip' : ' clips') + ' into ' + project + (files.length > fresh.length ? ' (' + (files.length - fresh.length) + ' were in it already)' : '') + '. The copies are in ' + c.dest + '.' + bad, 100);
    } catch (e) { said(e.message, 0); }
    finally { busy = false; ready(); $('ppStop').hidden = true; }
  }
  $('ppGo').onclick = go;
  $('ppStop').onclick = function () { said('Asking Rushes to stop…'); pullPost({ action: 'copy-stop', p: slugNow }).catch(function (e) { said(e.message); }); };

  // Clips: what is in the pull, each with a way out of it
  async function clips() {
    const l = $('ppList'); if (!PULL) { l.hidden = true; return; }
    const d = await (await fetch('/db/pulls.php?p=' + enc(PULL.slug) + '&t=' + Date.now())).json();
    l.innerHTML = (d.items || []).map(function (i) {
      return '<div><span title="' + esc(i.rel) + '">' + esc(i.name) + '</span><small>' + tb(i.bytes) + '</small><button class="ghost" data-rel="' + esc(i.rel) + '" title="Out of this pull; the file stays in the archive">×</button></div>'; }).join('')
      || '<div><span>Nothing in this pull yet. The pull button on a picture adds it.</span></div>';
    l.hidden = false;
  }
  $('ppClips').onclick = function () { if ($('ppList').hidden) clips().catch(function (e) { said(e.message); }); else $('ppList').hidden = true; };
  $('ppList').onclick = async function (e) {
    const b = e.target.closest('button[data-rel]'); if (!b || !PULL) return;
    try { await pullPost({ action: 'remove', p: PULL.slug, rel: b.dataset.rel }); await refreshPull(); } catch (x) { said(x.message); }
  };
  // Another page of Rushes would leave the panel with no way back: a pull's link fills that pull here, the rest say where they open.
  document.addEventListener('click', function (e) {
    const a = e.target.closest('a[href]'); if (!a) return;
    const u = new URL(a.getAttribute('href'), location.href);
    if (u.pathname === location.pathname) return;
    e.preventDefault(); e.stopPropagation();
    const slug = u.pathname === '/pull.php' && u.searchParams.get('p');
    if (slug) { $('pullsDd').hidden = true; usePull({ slug: slug, name: '' }); }
    else say('That page opens in Rushes itself, not in Premiere.');
  }, true);
})();
// Arriving from a pull's "+ Add clips": that pull is the one being filled.
(function () {
  const w = new URLSearchParams(location.search).get('pull');
  if (w) { PULL = { slug: w, name: '' }; store('pull', JSON.stringify(PULL)); }
  refreshPull().catch(function () {});
})();
run();
</script>
