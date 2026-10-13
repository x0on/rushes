<?php
// structure.php — Reorganize: moving what is already here into the archive's structure.
//
// The structure itself (the word, the shelf, the department list, how a shoot is
// named) is chosen in Setup → Archive structure. This page only moves older folders
// into it, and only after showing you everything first. Relinking an edit project
// afterwards is in Manage → Editors' projects.
$NAV = 'admin';
require_once __DIR__ . '/db/config.php';
require_once __DIR__ . '/db/auth.php';
require_sign_in();

$ONE = shelf_word(); $one = strtolower($ONE);
$e = fn($x) => htmlspecialchars((string)$x);
// the shelf as people read it: never the placeholder for "not chosen yet"
// Show in Finder: offered only on the Mac Rushes runs on, opened from it (db/tidy.php finder_ok())
$FINDER = on_mac() && PHP_OS_FAMILY === 'Darwin' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$shelf = !shelf_chosen() ? '(the folder chosen in Setup)' : (shelf_is_top() ? (settings()['archive']['label'] ?? 'the archive') : shelf_name());
?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reorganize &middot; <?= $e(settings()['name'] ?? 'Rushes') ?></title>
<?php require __DIR__ . '/head.php'; ?>
<style>
  .dep { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: center;
         padding: 7px 0; border-top: 1px solid var(--line-soft) }
  .dep:first-of-type { border-top: 0 }
  .kinds { display: flex; gap: 8px; flex-wrap: wrap }
  .opt { border: 1px solid var(--line); border-radius: var(--radius); padding: 12px 14px;
         background: var(--bg); cursor: pointer; display: block; position: relative }
  .opt.sm { padding: 8px 14px }
  .opt:has(input:checked) { border-color: var(--accent); background: var(--sel-bg); color: var(--sel-fg) }
  .opt b { display: block; font-size: 13.5px }
  .opt small { color: var(--muted); font-size: 12.5px; line-height: 1.5; display: block; margin-top: 4px }
  .opt:has(input:checked) small { color: var(--sel-fg); opacity: .8 }
  .opt input { position: absolute; opacity: 0; pointer-events: none }
  .pick { display: grid; grid-template-columns: 1fr 1fr; gap: 10px }
  .two-in { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 12px; max-width: 420px }
  @media (max-width: 640px) { .pick { grid-template-columns: 1fr } }
  .dep.new select { color: var(--muted) }
  .dep label.x { font-size: 12.5px; color: var(--muted); white-space: nowrap; cursor: pointer }
  .dep-h { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; font-size: 11px;
           text-transform: uppercase; letter-spacing: .07em; color: var(--faint); font-weight: 650;
           margin: 0 0 6px }
  .dep-h span:last-child { width: 62px }
  textarea { width: 100%; min-height: 180px; padding: 10px 12px; font: 13.5px/1.5 var(--font);
             color: var(--fg); background: var(--bg); border: 1px solid var(--line);
             border-radius: var(--radius-sm); resize: vertical }
  .loose { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 }
  .loose span { font-size: 12.5px; padding: 4px 10px; border-radius: 999px;
                border: 1px dashed var(--line); color: var(--muted) }
  .path { font-family: var(--mono); font-size: 13px; background: var(--bg); padding: 10px 12px;
          border-radius: var(--radius-sm); border: 1px solid var(--line) }
  .path b { color: var(--accent-text); font-weight: 600 }
  @media (max-width: 640px) { .dep, .dep-h { grid-template-columns: 1fr } .dep-h { display: none } }
  .tg { display: grid; grid-template-columns: minmax(0,1fr) minmax(240px,340px); gap: 14px; padding: 10px 0;
        border-top: 1px solid var(--line-soft); align-items: start }
  .dep-h.tg { border-top: 0; padding: 0 }
  .tg b { font-size: 13.5px; font-weight: 600; word-break: break-word }
  .tg small { display: block; color: var(--muted); font-size: 12.5px; margin-top: 3px; word-break: break-word }
  .tg.gh { background: var(--bg); border-radius: 8px; padding: 10px 10px; margin-top: 6px; border-top: 0 }
  .gk { padding-left: 26px; border-left: 2px solid var(--line-soft); margin-left: 9px }
  .tw { background: none; border: 0; color: var(--muted); cursor: pointer; font-size: 13px; width: 20px; padding: 0; margin-right: 4px }
  .tag { font-size: 11px; padding: 1px 7px; border-radius: 999px; border: 1px solid var(--line); color: var(--warn); margin-right: 6px; white-space: nowrap }
  .tg small.flag { color: var(--warn, var(--accent-text)) }
  .tg select { width: 100% }
  .tg .to { color: var(--accent-text) }
  .tg .to.stay { color: var(--faint) }
  .run { display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 8px 0;
         border-top: 1px solid var(--line-soft); font-size: 13px }
  .run small { display: block; color: var(--faint); font-size: 11.5px; font-family: var(--mono) }
  @media (max-width: 640px) { .tg { grid-template-columns: 1fr } .dep-h.tg { display: none } }
  .intro .parts { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; margin-top: 12px }
  .intro .parts > div { border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; background: var(--bg) }
  .intro .parts p { margin: 6px 0 0 }
  /* the drag view: what waits on the left, the departments on the right */
  .views { display: flex; gap: 2px; margin: 0 0 16px; border-bottom: 1px solid var(--line) }
  .views button { border: 0; border-bottom: 2px solid transparent; margin-bottom: -1px; background: none; color: var(--muted); padding: 9px 14px 8px;
                  font: inherit; font-size: 13.5px; font-weight: 550; cursor: pointer; display: inline-flex; gap: 6px; align-items: center }
  .views button:hover { color: var(--fg) }
  .views button.on { color: var(--fg); border-bottom-color: var(--accent) }
  .views .infotip { position: relative; font-weight: 400 }
  .dv { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1.35fr); gap: 14px; align-items: start }
  @media (max-width: 900px) { .dv { grid-template-columns: 1fr } }
  .pane { border: 1px solid var(--line); border-radius: 10px; background: var(--bg); min-height: 200px; max-height: 72vh; overflow: auto }
  .pane > header { position: sticky; top: 0; background: var(--bg); padding: 10px 12px 8px; border-bottom: 1px solid var(--line-soft); z-index: 1 }
  .pane > header b { font-size: 13px } .pane > header small { display: block; color: var(--muted); font-size: 12px; margin-top: 2px }
  .pane.over { border-color: var(--accent) }
  .nd { display: flex; align-items: center; gap: 6px; padding: 5px 10px; border-top: 1px solid var(--line-soft); font-size: 13px; cursor: grab; flex-wrap: wrap }
  .nd:hover { background: var(--raised) } .nd.loose { cursor: default; color: var(--faint) }
  .nd .nm { flex: 1; min-width: 120px; word-break: break-word } .nd small { color: var(--muted); font-size: 11.5px; white-space: nowrap }
  .nd .tw { flex: none } .nd select { font-size: 12px; max-width: 220px }
  .sg { font-size: 11px; border: 1px dashed var(--accent); color: var(--accent-text); border-radius: 999px; padding: 1px 8px; background: none; cursor: pointer; white-space: nowrap }
  .mv { font-size: 11.5px; background: none; border: 0; color: var(--muted); cursor: pointer; text-decoration: underline; padding: 0 }
  .db { border-top: 1px solid var(--line-soft); padding: 8px 12px 10px }
  .db.over { background: var(--sel-bg) } .db > b { font-size: 13px; display: block }
  .db .dz { font-size: 12px; color: var(--faint); margin-top: 2px }
  .pl { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 6px; padding: 6px 8px; border-radius: 7px; background: var(--raised); font-size: 12.5px; cursor: grab }
  .pl .nm { flex: 1; min-width: 140px; word-break: break-word } .pl small { color: var(--muted); font-size: 11.5px; display: block }
  .pl label { font-size: 11.5px; color: var(--muted); white-space: nowrap; cursor: pointer }
  .pl .x { background: none; border: 0; color: var(--muted); cursor: pointer; font-size: 14px; padding: 0 2px }
  .crumb { display: block; font-size: 12px; overflow-wrap: anywhere; word-break: normal; line-height: 1.5 } .crumb b { color: var(--fg) } .crumb .sep { color: var(--faint); margin: 0 4px }
  .drv { font-size: 11px; border: 1px solid var(--line); border-radius: 5px; padding: 0 6px; color: var(--muted); white-space: nowrap }
  .lnk2 { background: none; border: 0; color: var(--accent-text); cursor: pointer; font-size: 12px; text-decoration: underline; padding: 0 }
  .nd.flash { outline: 2px solid var(--accent); outline-offset: -2px }
  /* both sides as folder trees: each level indented under its parent, with a guide line down the side */
  .kids { margin-left: 17px; border-left: 1px solid var(--line); }
  .fi { width: 15px; height: 15px; flex: none; color: var(--muted) } .fi.d { color: var(--accent-text) } .fi.q { opacity: .55 }
  .nd { border-top: 0 }
  .dh { display: flex; align-items: center; gap: 8px; padding: 9px 12px; border-top: 1px solid var(--line-soft); font-size: 14px; font-weight: 650; cursor: default }
  .dh small { margin-left: auto; font-weight: 400; color: var(--muted); font-size: 12px; white-space: nowrap }
  .db { border-top: 0; padding: 0 } .db.over > .dh { background: var(--sel-bg) } .db.empty .dh { font-weight: 500; color: var(--muted); font-size: 13px; padding-top: 6px; padding-bottom: 6px }
  .db > .kids { margin: 0 12px 8px 22px }
  .tr { display: flex; gap: 8px; align-items: flex-start; padding: 6px 8px 6px 10px; font-size: 13px; position: relative }
  .tr::before { content: ''; position: absolute; left: 0; top: 15px; width: 8px; border-top: 1px solid var(--line) }
  .tr .nm { flex: 1 1 0; min-width: 0 } .tr > .tw { margin-top: 1px }
  .acts { display: flex; gap: 8px; align-items: center; flex: none; flex-wrap: nowrap }
  .nd .nm { flex: 1 1 140px; min-width: 0 } .nd .acts { margin-left: auto } .tr .nm > b { font-weight: 600 } .tr small { display: block; color: var(--muted); font-size: 11.5px; margin-top: 2px }
  .tr.pl { cursor: grab; background: none; margin: 0; border-radius: 0 }
  .tr.sug .nm > b { font-weight: 500; color: var(--muted) } .tr.sug .nm > b::after { content: ' ?' }
  .tr input.nmin { display: inline-block; font: inherit; font-size: 12px; height: auto; min-height: 0; padding: 2px 6px; margin: 0 0 0 4px; width: 200px; max-width: 100%; color: var(--fg); background: var(--bg); border: 1px solid var(--line); border-radius: 5px }
  .tag2 { font-size: 10.5px; border: 1px solid var(--line); border-radius: 999px; padding: 0 6px; color: var(--muted); margin-left: 6px; font-weight: 400 }
  .sublab { font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em; color: var(--faint); padding: 8px 10px 2px }
  .fbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 10px }
  .fwins { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 12px }
  .fwin { min-width: 0 }
  .fwin { border: 1px solid var(--line); border-radius: 10px; background: var(--bg); overflow: hidden }
  .fwin > header { display: flex; gap: 8px; align-items: center; padding: 6px 10px; border-bottom: 1px solid var(--line-soft); font-size: 12.5px }
  .fwin > header select { font: inherit; font-size: 12.5px; padding: 3px 6px; height: auto; min-height: 0; border: 1px solid var(--line); border-radius: 5px; background: var(--bg); color: var(--fg) }
  .fwin > header .ro { color: var(--muted) } .fwin > header .x { margin-left: auto }
  .fcols { display: flex; overflow-x: auto; height: 46vh; min-height: 260px }
  .fcol { flex: none; width: 220px; border-right: 1px solid var(--line-soft); overflow-y: auto; padding: 3px 0 }
  .fcol.over { background: var(--ok-bg) }
  .fr { display: flex; align-items: center; gap: 7px; padding: 3px 10px; margin: 0 4px; border-radius: 5px; font-size: 13px; cursor: default; user-select: none; white-space: nowrap }
  .fr .n { flex: 1; overflow: hidden; text-overflow: ellipsis } .fr small { color: var(--muted); font-size: 11px } .fr .gt { color: var(--faint) }
  .fr .ic { width: 16px; text-align: center; color: var(--muted) } .fr.d .ic { color: var(--accent-text) }
  .fr.open { background: var(--line-soft) } .fr.sel { background: var(--accent); color: var(--accent-fg) } .fr.sel small, .fr.sel .ic, .fr.sel .gt { color: var(--accent-fg) }
  .fr.over { outline: 2px solid var(--accent); outline-offset: -2px }
  .fr.empty { color: var(--faint) } .fr .pl { font-size: 10px; color: var(--accent-text) } .fr.sel .pl { color: var(--accent-fg) }
  .fr small.zero { color: var(--bad); font-weight: 600 } .fr.sel small.zero { color: var(--accent-fg) }
  .finfo { width: 280px; padding: 12px 14px; font-size: 12.5px }
  .fi-n { font-weight: 650; font-size: 13.5px; word-break: break-word; margin-bottom: 8px }
  .fi-k { color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .05em; margin-top: 8px }
  .fi-e summary { cursor: pointer; color: var(--accent-text); font-size: 12px; margin-top: 4px } .fi-e div { font-size: 11.5px; color: var(--muted); word-break: break-all; padding: 1px 0 }
  .fi-v { word-break: break-word } .fi-v.warn { color: var(--warn) } .fi-v.bad { color: var(--bad); font-weight: 600 } .fi-v small { color: var(--muted) }
  .fpath { padding: 4px 10px; font-size: 11.5px; color: var(--muted); border-top: 1px solid var(--line-soft); white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
  .fmenu { position: fixed; z-index: 50; background: var(--bg); border: 1px solid var(--line); border-radius: 8px; box-shadow: 0 6px 24px rgba(0,0,0,.18); padding: 4px; min-width: 170px }
  .fmenu button { display: block; width: 100%; text-align: left; font: inherit; font-size: 13px; padding: 5px 10px; border: 0; background: none; color: var(--fg); border-radius: 5px; cursor: pointer }
  .fmenu button:hover { background: var(--accent); color: var(--accent-fg) }
  .fplay { position: fixed; inset: 0; z-index: 60; background: rgba(0,0,0,.7); display: flex; align-items: center; justify-content: center }
  .fplay video { max-width: 86vw; max-height: 80vh; background: #000; border-radius: 6px }
  .fplay p { position: absolute; bottom: 20px; color: #fff; font-size: 13px }
  .review { margin-top: 14px; padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: var(--bg) }
  .review .rh { font-size: 11px; text-transform: uppercase; letter-spacing: .07em; color: var(--accent-text); font-weight: 650; margin-bottom: 4px }
  .review > b { font-size: 15px }
  .egs { margin-top: 8px } .egs summary { cursor: pointer; font-size: 12.5px; color: var(--muted) }
  .eg { font-family: var(--mono); font-size: 11.5px; color: var(--muted); margin: 6px 0 0; line-height: 1.6; word-break: break-word }
  h3.part { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin: 26px 0 10px }
</style>

<div class="app">
<div class="with-rail with-side">
<?php $RAIL = 'structure'; require __DIR__ . '/db/rail.php'; ?>

  <main class="work">
  <div class="pad form">
    <div class="head"><h1>Reorganize</h1>
      <span class="sub">Moving what is already here into the structure set in <a href="/setup.php#plan">Setup</a>.</span></div>

    <?php if (!shelf_chosen()): ?>
      <div class="banner warn"><div class="txt"><b>The archive's structure is not set yet.</b>
        Say which folder your <?= $e(strtolower(shelf_word(true))) ?> live in, in <a href="/setup.php#plan">Setup → Archive structure</a>; the tidy-up moves folders into it.</div></div>
    <?php endif; ?>
    <!-- ══ the tidy-up ══ -->
    <div class="grp" id="tidy">
      <?php $tipR = fn($t) => '<span class="infotip" tabindex="0" data-tip="' . htmlspecialchars($t, ENT_QUOTES) . '">i</span>'; ?>
      <!-- three ways to work on the same structure: tabs, each showing its own part of the page -->
      <div class="views" role="tablist">
        <button type="button" role="tab" data-view="drag">File in <?= $tipR('Folders that are not organized yet, filed into a ' . strtolower($one) . ': Rushes suggests where each goes, you check, and Move files them all, under their year. For a copy, where each file goes is read from where it came from. Nothing moves until you press Move; every move is recorded so Premiere projects can be relinked, and a tidy-up can be put back.') ?></button>
        <button type="button" role="tab" data-view="tidy">Tidy up <?= $tipR('The archive as Finder shows it: drag to move, right-click to rename, make a folder or move to Recently Removed. Each change happens at once, is recorded, and Undo puts it back. Nothing is copied: a move inside the archive is a rename on the drive.') ?></button>
        <button type="button" role="tab" data-view="list">File in, as a list <?= $tipR('The same as File in, worked out for every folder at once, as a list to tick.') ?></button>
      </div>
      <div id="tWait" class="banner warn" hidden><div class="txt"></div></div>

      <!-- ══ Drag and drop: folders from the left onto a <?= $e($one) ?> on the right. Nothing moves until Move. ══ -->
      <div id="dragView" hidden>
        <div class="dv">
          <div class="pane" id="dvL"><header><b>Where it is now</b><small>Folders in the archive that are not organized yet. Open one to see what is inside; drag any folder to the right. Once placed, it leaves this side.</small><small id="dvLeft" style="color:var(--fg);margin-top:4px"></small></header>
            <div id="dvTree"><p class="note" style="margin:10px 12px">Looking &hellip;</p></div></div>
          <div class="pane" id="dvR"><header><b>Where it will go &middot; <?= $e($shelf) ?></b><small>The new structure, before anything moves. Whatever is under a <?= $e($one) ?> moves there when you press Move: Rushes put in what matches, marked <i>guess</i> where the name is only similar. <b>Take out</b> what does not belong; drop more from the left.</small></header>
            <div id="dvDepts"></div></div>
        </div>
        <div class="btns" style="margin-top:14px"><button class="btn" type="button" id="dvAsk" disabled>Nothing placed yet</button>
          <span class="note" id="dvSum">Nothing moves until you press it, and it shows what it will do first.</span></div>
        <!-- the review: a calm summary, not a warning; the examples folded, labelled for what they are -->
        <div id="dvSure" class="review" hidden>
          <div class="rh">Ready to move</div>
          <b id="dvSureWhat"></b>
          <p id="dvSureMore" class="note" style="margin:4px 0 0"></p>
          <details class="egs"><summary>Examples: where one file from each of a few folders will go</summary><div class="eg" id="dvEg"></div></details>
          <p class="note" style="margin:8px 0 0">The helper on <span class="tHelper"></span> moves them one file at a time, never over another file. Every move is written down and can be put back.</p>
          <div class="btns" style="margin-top:10px">
            <button class="btn" type="button" id="dvYes">Yes, move them</button>
            <button class="btn quiet" type="button" id="dvNo">Not yet</button>
          </div></div>
      </div>

      <!-- ══ Tidy up: inside the structure. Changes are planned here; nothing moves until Apply. ══ -->
      <!-- ══ Tidy up: the archive as Finder shows it (db/browse.php). Each change happens at once, as in Finder. ══ -->
      <div id="tuView" hidden>
        <div class="fbar">
          <button class="btn quiet" type="button" id="fUndo" disabled>Undo</button>
          <button class="btn quiet" type="button" id="fAdd">Add a window</button>
          <span class="note" id="fSaid">Drag onto a folder to move, as in Finder. Right-click for more; ⌘⌫ moves to Recently Removed (pick it in a window’s list to drag things back); Space plays a clip with a preview.</span>
        </div>
        <div id="fAsk" class="banner warn" hidden><div class="txt"></div></div>
        <div class="fwins" id="fWins"></div>
      </div>

      <div id="listView" hidden>
      <div id="tList"><p class="note" style="margin:0">Looking at what is outside the shelf &hellip;</p></div>
      <div id="tGo" class="btns" style="margin-top:14px" hidden>
        <button class="btn" type="button" id="tAsk"></button>
        <span class="note" id="tSum"></span>
      </div>
      <div id="tSure" class="banner warn" hidden><div class="txt">
        <b id="tSureWhat"></b>
        The helper on <span class="tHelper"></span> moves them one file at a time, never over another
        file and never while its folder is still being copied. It shows its progress in the top bar.
        <div class="btns" style="margin-top:10px">
          <button class="btn" type="button" id="tYes">Yes, move them</button>
          <button class="btn quiet" type="button" id="tNo">Not yet</button>
        </div></div></div>
      </div>
      <div id="tRuns"></div>
    </div>

    <script>
    (function () {
      var SHELF = <?= json_encode($shelf, JSON_UNESCAPED_UNICODE) ?>, data = null, tops = [];
      var $ = function (id) { return document.getElementById(id); };
      var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
      var size = function (b) { return b >= 1e12 ? (b / 1e12).toFixed(1) + ' TB' : b >= 1e9 ? Math.round(b / 1e9) + ' GB' : Math.max(1, Math.round(b / 1e6)) + ' MB'; };
      var num = function (n) { return n.toLocaleString(); };
      var ONE = <?= json_encode($one) ?>;
      // a long file name, shortened in the middle so the row stays one line (the whole name shows on hover)
      var mid = function (t) { return t.length > 56 ? t.slice(0, 22) + '…' + t.slice(-30) : t; };
      var shown = function (r) {       // share / Departments / Parks & Recreation / 2024
        var from = r.root.slice(0, r.root.lastIndexOf('/') + 1);
        return r.key.slice(from.length).split('/').join(' / ');
      };
      var dest = function (r, dept) {
        var d = data.depts.filter(function (x) { return x.name === dept; })[0];
        if (!d) return '';
        // as tidy.php dest_for(): a drive copied onto the shelf files its folders, not its own name; the year
        // (from the folder's name or its clips' dates) goes between the department and the folder
        var tail = r.base !== '' ? r.key.slice(r.base.length)
                 : r.flat ? '/' + r.key.split('/').pop()
                 : '/' + (r.key.slice(r.root.length + 1) || r.root.split('/').pop());
        return (SHELF + '/' + d.folder + (r.year ? '/' + r.year : '') + tail).split('/').join(' / ');
      };

      function sum() {
        var n = 0, b = 0, rows = 0;
        data.groups.forEach(function (r, i) {
          var v = $('tp' + i).value;
          $('td' + i).innerHTML = v ? '&rarr; ' + esc(dest(r, v)) : (r.here ? 'stays where it is' : 'stays in ARCHIVE');
          $('td' + i).className = 'to' + (v ? '' : ' stay');
          if (v) { n += r.n; b += r.bytes; rows++; }
        });
        tops.forEach(function (g, gi) {
          if (g.rows.length === 1 || !$('tg' + gi)) return;
          var vs = g.rows.map(function (i) { return $('tp' + i).value; }), same = vs.every(function (v) { return v === vs[0]; });
          $('tg' + gi).value = same ? vs[0] : '*';
          $('tgd' + gi).innerHTML = !same ? 'different for each folder: open it to see'
            : vs[0] ? '&rarr; ' + esc(SHELF + ' / ' + (data.depts.filter(function (x) { return x.name === vs[0]; })[0] || {}).folder) + ' / …' : 'they stay where they are';
          $('tgd' + gi).className = 'to' + (same && !vs[0] ? ' stay' : '');
        });
        $('tGo').hidden = !data.groups.length;
        $('tAsk').disabled = !n;
        $('tAsk').textContent = n ? 'Move ' + num(n) + ' files (' + size(b) + ')' : 'Nothing picked to move';
        $('tSum').textContent = n ? 'from ' + rows + ' folder' + (rows > 1 ? 's' : '') + '. Asks first.' : '';
        $('tSure').hidden = true;
        return {n: n, b: b, rows: rows};
      }

      function draw(d) {
        data = d;
        document.querySelectorAll('.tHelper').forEach(function (x) { x.textContent = d.helper; });
        $('tWait').hidden = !d.waiting.length;
        $('tWait').querySelector('.txt').innerHTML = d.waiting.length
          ? '<b>A tidy-up is waiting for the helper.</b> It runs next, before any copy, as soon as the helper on '
            + esc(d.helper) + ' is running. Moving more now adds another one behind it.' : '';
        if (!d.groups.length) {
          $('tList').innerHTML = '<p class="note" style="margin:0">' + (d.records
            ? 'Nothing to tidy &mdash; everything the copies brought in is on the shelf already, and nothing else is outside it.'
            : 'Nothing to tidy: nothing is outside the shelf, and no copy has written a record yet.') + '</p>';
        } else {
          var opts = d.depts.map(function (x) {
            return '<option value="' + esc(x.name) + '">' + esc(x.name) + '</option>'; }).join('');
          var none = d.groups.filter(function (r) { return !r.dept; }).length;
          var row = function (i) {
            var r = d.groups[i];
            return '<div class="tg' + (r.busy ? ' busy' : '') + '"><div><b>' + esc(shown(r)) + '</b>'
              + '<small>' + (r.dept ? '' : '<span class="tag">no ' + ONE + '</span>') + (r.here ? 'already in the archive · ' : 'copied in · ') + num(r.n) + ' file' + (r.n > 1 ? 's' : '') + ' · ' + size(r.bytes)
              + (r.eg ? ' · <span title="' + esc(r.eg) + '">e.g. …' + esc(mid(r.eg)) + '</span>' : '') + '</small>'
              + (r.here ? '<small>' + (r.year ? '<b>' + esc(r.year) + '</b> · ' : 'no year · ') + esc(r.year_why || '') + '</small>' : '')
              + (r.busy ? '<small class="flag">Still being copied &mdash; those files wait for the next tidy-up.</small>' : '')
              + '</div><div><select id="tp' + i + '" aria-label="Goes to"><option value="">— leave it '
              + (r.here ? 'where it is' : 'in ARCHIVE') + ' —</option>' + opts + '</select>'
              + '<small id="td' + i + '" class="to"></small></div></div>';
          };
          tops = []; var byTop = {};
          d.groups.forEach(function (r, i) {
            var k = shown(r).split(' / ').slice(0, 2).join(' / ');
            if (!(k in byTop)) { byTop[k] = tops.length; tops.push({name: k, rows: []}); }
            tops[byTop[k]].rows.push(i);
          });
          $('tList').innerHTML = (none ? '<p class="note" style="margin:0 0 10px"><span class="tag">no ' + ONE + '</span> ' + num(none) + ' of these folders have no ' + ONE
              + ' in their path: pick one for each, or leave it where it is.</p>' : '')
            + '<div class="dep-h tg"><span>Came from</span><span>Goes to</span></div>'
            // One line per top folder (T7 / KITE FEST), folded, with one "Goes to" for all of
            // it; opened only when its folders need to go different ways.
            + tops.map(function (g, gi) {
              if (g.rows.length === 1) return row(g.rows[0]);
              var n = 0, b = 0, nd = 0;
              g.rows.forEach(function (i) { n += d.groups[i].n; b += d.groups[i].bytes; nd += d.groups[i].dept ? 0 : 1; });
              return '<div class="tg gh"><div><button type="button" class="tw" aria-expanded="false" data-g="' + gi + '">▸</button>'
                + '<b>' + esc(g.name) + '</b><small>' + (nd ? '<span class="tag">' + (nd === g.rows.length ? 'no ' + ONE : nd + ' with no ' + ONE) + '</span>' : '')
                + g.rows.length + ' folders · ' + num(n) + ' files · ' + size(b) + '</small></div>'
                + '<div><select id="tg' + gi + '" aria-label="Goes to, all of it"><option value="">— leave them where they are —</option>' + opts
                + '<option value="*" disabled>— different for each (open it) —</option></select><small id="tgd' + gi + '" class="to"></small></div></div>'
                + '<div class="gk" id="gk' + gi + '" hidden>' + g.rows.map(row).join('') + '</div>';
            }).join('');
          d.groups.forEach(function (r, i) { $('tp' + i).value = r.dept || ''; $('tp' + i).onchange = sum; });
          tops.forEach(function (g, gi) {
            if (g.rows.length === 1) return;
            $('tg' + gi).onchange = function () {          // the whole top folder at once
              var v = this.value; g.rows.forEach(function (i) { $('tp' + i).value = v; }); sum();
            };
          });
          $('tList').querySelectorAll('.tw').forEach(function (t) {
            t.onclick = function () {
              var open = t.getAttribute('aria-expanded') !== 'true';
              t.setAttribute('aria-expanded', open); t.textContent = open ? '▾' : '▸'; $('gk' + t.dataset.g).hidden = !open;
            };
          });
        }
        $('tRuns').innerHTML = d.runs.length ? '<p style="margin:18px 0 6px;color:var(--fg);font-size:13px"><b>Done so far</b></p>'
          + d.runs.map(function (r) {
            return '<div class="run"><span>' + esc(r.ago) + ' &mdash; ' + num(r.moved) + ' moved (' + size(r.bytes) + ')'
              + (r.left ? ', ' + num(r.left) + ' left where they were' : '') + '<small>' + esc(r.record) + '</small></span>'
              + (r.undone ? '<span class="note">put back</span>'
                          : '<button class="btn quiet sm" type="button" data-undo="' + esc(r.record) + '">Put back</button>') + '</div>';
          }).join('') : '';
        if (d.groups.length) sum(); else $('tGo').hidden = true;
      }

      function load() {
        fetch('/db/tidy.php?t=' + Date.now()).then(function (r) { return r.json(); }).then(function (d) {
          if (d.error) throw new Error(d.error);
          draw(d);
        }).catch(function (e) {
          $('tList').innerHTML = '<div class="banner bad"><div class="txt"><b>Could not read the records.</b> ' + esc(e.message) + '</div></div>';
        });
      }

      function post(body, done) {
        fetch('/db/tidy.php', {method: 'POST', body: body}).then(function (r) { return r.json(); }).then(function (d) {
          if (d.error) throw new Error(d.error);
          done(d); (window.tidyRefresh || load)();
        }).catch(function (e) {
          $('tWait').hidden = false; $('tWait').className = 'banner bad';
          $('tWait').querySelector('.txt').innerHTML = '<b>Nothing was queued.</b> ' + esc(e.message);
        });
      }

      $('tAsk').onclick = function () {
        var s = sum();
        $('tSureWhat').textContent = 'Move ' + num(s.n) + ' files (' + size(s.b) + ') from ' + s.rows + ' folder' + (s.rows > 1 ? 's' : '') + ' onto ' + SHELF + '?';
        $('tSure').hidden = false; $('tYes').focus();
      };
      $('tNo').onclick = function () { $('tSure').hidden = true; };
      $('tYes').onclick = function () {
        var f = new FormData(), picks = {}; f.append('go', '1');
        data.groups.forEach(function (r, i) { if ($('tp' + i).value) picks[r.key] = $('tp' + i).value; });
        f.append('picks', JSON.stringify(picks));
        $('tYes').disabled = true; $('tYes').textContent = 'Queuing…';
        post(f, function (d) {
          $('tYes').disabled = false; $('tYes').textContent = 'Yes, move them';
          $('tWait').className = 'banner ok'; $('tWait').hidden = false;
          $('tWait').querySelector('.txt').innerHTML = '<b>Queued ✓</b> ' + num(d.files) + ' files. The helper on ' + esc(data.helper) + ' does it next &mdash; watch the top bar.';
        });
      };
      $('tRuns').onclick = function (ev) {
        var b = ev.target.closest('[data-undo]'); if (!b) return;
        if (b.dataset.sure !== '1') { b.dataset.sure = '1'; b.textContent = 'Sure? Put them back'; return; }
        var f = new FormData(); f.append('undo', b.dataset.undo);
        b.disabled = true; b.textContent = 'Queuing…';
        post(f, function () { b.textContent = 'Queued ✓'; });
      };
      window.tidyList = load;          // the list works out every row's year: read only when it is shown
    })();
    </script>
    <script>
    (function () {
      // ══ Tidy up (db/browse.php): Finder's column view, in windows side by side. A drag onto a folder moves
      // at once, as in Finder; right-click for Rename, New folder, Show in Finder; Undo puts the last change back.
      // Each window remembers where it was (this browser only).
      var $ = function (id) { return document.getElementById(id); };
      var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
      var size = function (b) { return b == null ? '' : b === 0 ? 'Zero bytes' : b >= 1e12 ? (b / 1e12).toFixed(1) + ' TB' : b >= 1e9 ? (b / 1e9).toFixed(1) + ' GB' : b >= 1e6 ? Math.round(b / 1e6) + ' MB' : Math.max(1, Math.round(b / 1e3)) + ' KB'; };
      var KEY = 'rushes-finder', roots = [], finder = false, wins = [], cache = {}, menu = null, busy = window.rushesBusy = false;
      var VIDEO = /\.(mxf|mov|mp4|avi|mts|m4v|braw|r3d)$/i;
      var get = function (q) { return fetch('/db/browse.php?' + q + '&t=' + Date.now()).then(function (r) { return r.json(); })
        .then(function (d) { if (d.error) throw new Error(d.error); return d; }); };
      var post = function (o) { var f = new URLSearchParams(o); return fetch('/db/browse.php', { method: 'POST', body: f }).then(function (r) { return r.json(); })
        .then(function (d) { if (d.error) throw new Error(d.error); return d; }); };
      var save = function () { try { localStorage.setItem(KEY, JSON.stringify(wins.map(function (w) { return w.open; }))); } catch (e) {} };
      var said = function (html, bad) { $('fSaid').innerHTML = html; $('fSaid').className = 'note' + (bad ? ' bad' : ''); };
      var rootOf = function (p) { return roots.filter(function (r) { return p === r.path || p.indexOf(r.path + '/') === 0; }).sort(function (a, b) { return b.path.length - a.path.length; })[0]; };

      // a folder's contents, kept until something changes
      function list(dir) {
        if (cache[dir]) return Promise.resolve(cache[dir]);
        return get('dir=' + encodeURIComponent(dir)).then(function (d) { cache[dir] = d; return d; });
      }
      // ── one window: the folders open in it, left to right, and what is selected in the last one ──
      function Win(open) { this.open = open; this.sel = {}; this.anchor = null; }
      Win.prototype.draw = function () {
        var w = this, i = wins.indexOf(w), el = w.el, root = rootOf(w.open[0]) || roots[0];
        if (!el) {
          el = w.el = document.createElement('div'); el.className = 'fwin';
          $('fWins').appendChild(el);
        }
        el.innerHTML = '<header><select>' + roots.map(function (r) {
            return '<option value="' + esc(r.path) + '"' + (r === root ? ' selected' : '') + (r.here ? '' : ' disabled') + '>' + esc(r.name) + (r.hold ? '' : r.archive ? ' · the archive' : r.here ? ' · looked at only' : ' · not plugged in') + '</option>'; }).join('') +
          '</select>' + (root && root.hold ? '<span class="ro">Drag back out to recover.</span>' : root && !root.archive ? '<span class="ro">Nothing on this drive changes here.</span>' : '') +
          (wins.length > 1 ? '<button class="lnk x" type="button">Close</button>' : '') + '</header>' +
          '<div class="fcols"></div><div class="fpath"></div>';
        el.querySelector('select').onchange = function () { w.open = [this.value]; w.sel = {}; save(); w.draw(); };
        var x = el.querySelector('.x'); if (x) x.onclick = function () { wins.splice(wins.indexOf(w), 1); el.remove(); save(); wins.forEach(function (v) { v.draw(); }); };
        var cols = el.querySelector('.fcols');
        el.querySelector('.fpath').textContent = w.open[w.open.length - 1].replace(root ? root.path : '', root ? root.name : '').split('/').join(' › ');
        w.open.forEach(function (dir, k) {
          var c = document.createElement('div'); c.className = 'fcol'; c.dataset.dir = dir; cols.appendChild(c);
          c.innerHTML = '<div class="fr empty"><span class="n">Looking…</span></div>';
          list(dir).then(function (d) { w.col(c, d, k); }).catch(function (e) {
            c.innerHTML = '<div class="fr empty"><span class="n" style="white-space:normal">' + esc(e.message) + '</span></div>'; });
          dropOn(c, function () { return dir; });
        });
        // one thing picked: its properties in a last column, dates first (as Finder's preview column)
        var one = w.picked(); if (one.length === 1) info(cols, one[0]);
        setTimeout(function () { cols.scrollLeft = cols.scrollWidth; }, 0);
      };
      Win.prototype.col = function (c, d, k) {
        var w = this, next = w.open[k + 1];
        c.innerHTML = !d.items.length ? '<div class="fr empty"><span class="n">Empty</span></div>' : d.items.map(function (it, n) {
          var p = d.dir + '/' + it.name;
          return '<div class="fr' + (it.dir ? ' d' : '') + (w.selCol === k && w.sel[p] ? ' sel' : p === next ? ' open' : '') + '" draggable="' + d.archive + '" data-p="' + esc(p) + '" data-i="' + n + '">' +
            '<span class="ic">' + (it.dir ? '▸' : VIDEO.test(it.name) ? '▶' : '·') + '</span><span class="n" title="' + esc(it.name) + '">' + esc(it.name) + '</span>' +
            (it.plays ? '<span class="pl" title="has a preview: Space plays it">●</span>' : '') +
            (it.dir ? '<span class="gt">›</span>' : '<small' + (it.size === 0 ? ' class="zero"' : '') + '>' + size(it.size) + '</small>') + '</div>';
        }).join('') + (d.more ? '<div class="fr empty"><span class="n">and ' + d.more.toLocaleString() + ' more</span></div>' : '');
        c.querySelectorAll('.fr[data-p]').forEach(function (r) {
          var p = r.dataset.p, it = d.items[+r.dataset.i];
          r.onclick = function (e) { w.pick(k, d, it, p, e); };
          r.ondblclick = function () { if (!it.dir) play(p, it); };
          r.oncontextmenu = function (e) { e.preventDefault(); quiet(); openMenu(e, w, p, it, d); };
          // a row dragged or right-clicked without being picked becomes what is picked, without drawing again
          // (a drag whose row is drawn again is lost, and a menu closes as the columns scroll)
          var quiet = function () { if (w.sel[p] && w.selCol === k) return; w.sel = {}; w.sel[p] = 1; w.selCol = k; w.anchor = p; current = w;
            c.querySelectorAll('.fr.sel').forEach(function (x) { x.classList.remove('sel'); }); r.classList.add('sel'); };
          r.ondragstart = function (e) {
            quiet();
            e.dataTransfer.setData('application/x-rushes-items', JSON.stringify(Object.keys(w.sel)));
            e.dataTransfer.effectAllowed = 'move';
          };
          if (it.dir) dropOn(r, function () { return p; });
        });
      };
      // click: a folder opens in the next column; ⌘ adds to what is selected, ⇧ selects a run, as in Finder
      Win.prototype.pick = function (k, d, it, p, e) {
        var w = this;
        if ((e.metaKey || e.ctrlKey) && w.selCol === k) { if (w.sel[p]) delete w.sel[p]; else w.sel[p] = 1; w.open = w.open.slice(0, k + 1); }
        else if (e.shiftKey && w.selCol === k && w.anchor) {
          var names = d.items.map(function (x) { return d.dir + '/' + x.name; }), a = names.indexOf(w.anchor), b = names.indexOf(p);
          w.sel = {}; names.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(function (x) { w.sel[x] = 1; }); w.open = w.open.slice(0, k + 1);
        } else { w.sel = {}; w.sel[p] = 1; w.open = w.open.slice(0, k + 1).concat(it.dir ? [p] : []); }
        if (!e.shiftKey) w.anchor = p;
        w.selCol = k; current = w; save(); w.draw();
      };
      var current = null;
      var when = function (t) { return t ? new Date(t * 1000).toLocaleString([], { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—'; };
      var day = function (d) { var x = new Date(d + 'T12:00:00'); return isNaN(x) ? d : x.toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' }); };
      var clock = function (s) { s = Math.round(+s || 0); return Math.floor(s / 3600) ? Math.floor(s / 3600) + ':' + ('0' + Math.floor(s % 3600 / 60)).slice(-2) + ':' + ('0' + s % 60).slice(-2) : Math.floor(s / 60) + ':' + ('0' + s % 60).slice(-2); };
      function info(cols, p) {
        var c = document.createElement('div'); c.className = 'fcol finfo'; cols.appendChild(c);
        c.innerHTML = '<div class="fi-n">' + esc(name(p)) + '</div><p class="note"><span class="spin"></span> Counting on the drive…</p>';
        get('info=' + encodeURIComponent(p)).then(function (d) {
          var kv = function (k, v, cls) { return v == null || v === '' ? '' : '<div class="fi-k">' + k + '</div><div class="fi-v' + (cls ? ' ' + cls : '') + '">' + v + '</div>'; };
          var m = d.media || {};
          c.innerHTML = '<div class="fi-n">' + esc(name(p)) + '</div>' + (d.dir
            ? (!d.files && !d.dirs && d.full ? kv('Files', 'Empty folder: nothing inside')
                : kv('Files', (d.full ? '' : 'at least ') + d.files.toLocaleString() + ' file' + (d.files === 1 ? '' : 's') + (d.files ? ' · ' + (d.full ? '' : 'at least ') + size(d.bytes) : '') +
                     (d.dirs ? ' <small>in ' + d.dirs.toLocaleString() + ' folder' + (d.dirs === 1 ? '' : 's') + '</small>' : ''))) +
              (d.full && d.files > d.known ? kv('Search', (d.files - d.known).toLocaleString() + ' of these are not in Search yet <small>(moved here in Finder, or new): Setup → Jobs and tools → Rebuild the file list</small>', 'warn') : '') +
              (d.zero ? kv('Empty files', (d.zero_clips ? '<span class="bad">' + d.zero_clips.toLocaleString() + ' clip' + (d.zero_clips === 1 ? '' : 's') + ' with zero bytes</span> <small>(broken: a copy that never got its data)</small>' : '') +
                  (d.zero - d.zero_clips ? (d.zero_clips ? '<br>' : '') + (d.zero - d.zero_clips).toLocaleString() + ' other file' + (d.zero - d.zero_clips === 1 ? '' : 's') + ' with zero bytes <small>(often a card’s or a program’s markers: harmless)</small>' : '') +
                  '<details class="fi-e"><summary>Show where</summary>' + d.empty.map(function (e) { return '<div>' + esc(e) + '</div>'; }).join('') +
                  (d.zero > d.empty.length ? '<div class="note">and ' + (d.zero - d.empty.length).toLocaleString() + ' more</div>' : '') + '</details>') : '') +
              kv('Shot', d.shot ? (d.shot[0] === d.shot[1] ? day(d.shot[0]) : day(d.shot[0]) + ' – ' + day(d.shot[1])) + ' <small>(' + d.shot[2] + ' clips carry a camera date)</small>' : d.videos ? 'no camera date read yet' : 'no clips') +
              kv('Its year', d.year ? '<b>' + esc(d.year) + '</b> <small>' + esc(d.year_why) + '</small>' : '<small>' + esc(d.year_why) + '</small>')
            : kv('Size', size(d.size), d.size === 0 ? 'bad' : '') +
              kv('Shot on', d.shot ? esc(d.shot) + ' <small>(the camera’s date, inside the file)</small>' : '<small>no camera date in it' + (d.year ? '; its oldest date says ' + esc(d.year) : '') + '</small>') +
              kv('Length', m.duration ? clock(m.duration) : '') + kv('Picture', m.width ? m.width + ' × ' + m.height + (m.fps ? ' · ' + (+m.fps).toFixed(2).replace(/\.?0+$/, '') + ' fps' : '') : '') +
              kv('Codec', m.codec ? esc(String(m.codec).toUpperCase()) : '') + kv('Camera', m.camera ? esc(m.camera) : '') +
              kv('Timecode', m.timecode ? esc(m.timecode) + (m.reel ? ' · reel ' + esc(m.reel) : '') : '')) +
            kv('Modified', when(d.modified)) + kv('Created', d.created ? when(d.created) + ' <small>(often the day it was copied here)</small>' : '') +
            (!d.dir && d.plays ? '<p style="margin-top:10px"><button class="btn quiet" type="button">▶ Play its preview</button></p>' : '');
          var b = c.querySelector('button'); if (b) b.onclick = function () { play(p, { plays: true }); };
        }).catch(function (e) { c.innerHTML = '<p class="note bad">' + esc(e.message) + '</p>'; });
      }
      // what is selected in a window: the rows marked in the column they were picked in
      Win.prototype.picked = function () { return Object.keys(this.sel); };

      // ── drop: onto a folder row, or onto a column (its own folder) ──
      function dropOn(el, where) {
        el.addEventListener('dragover', function (e) {
          if (e.dataTransfer.types.indexOf('application/x-rushes-items') < 0) return;
          e.preventDefault(); e.stopPropagation(); el.classList.add('over');
        });
        el.addEventListener('dragleave', function () { el.classList.remove('over'); });
        el.addEventListener('drop', function (e) {
          e.preventDefault(); e.stopPropagation(); el.classList.remove('over');
          var items = JSON.parse(e.dataTransfer.getData('application/x-rushes-items') || '[]');
          if (items.length) move(items, where());
        });
      }
      window.addEventListener('dragover', function (e) { if (e.dataTransfer && e.dataTransfer.types.indexOf('application/x-rushes-items') >= 0) e.preventDefault(); });
      window.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.types.indexOf('application/x-rushes-items') >= 0) e.preventDefault(); });

      function name(p) { return p.split('/').pop(); }
      function refresh() { cache = {}; wins.forEach(function (w) { w.draw(); }); $('fUndo').disabled = false; }
      // ── the changes, each at once ──
      function move(items, to, merge) {
        if (busy) return;
        var tr = rootOf(to); if (tr && tr.hold) { removeNow(items); return; }       // dropped on Recently Removed: as onto the Trash
        items = items.filter(function (p) { return p !== to && to.indexOf(p + '/') !== 0; });
        if (!items.length) return;
        busy = window.rushesBusy = true; $('fAsk').hidden = true;
        said('<span class="spin"></span> Moving ' + (items.length === 1 ? esc(name(items[0])) : items.length + ' items') + ' into ' + esc(name(to)) + '…');
        post({ op: 'move', items: JSON.stringify(items), to: to, merge: merge ? '1' : '' }).then(function (d) {
          busy = window.rushesBusy = false;
          // what moved leaves the windows' open paths
          wins.forEach(function (w) { items.forEach(function (p) {
            var i = w.open.findIndex(function (x) { return x === p || x.indexOf(p + '/') === 0; }); if (i > 0) w.open = w.open.slice(0, i); delete w.sel[p]; }); });
          var parts = [];
          if (d.moved.length && (rootOf(items[0]) || {}).hold) parts.push('Recovered ✓ ' + esc(d.moved.join(', ')) + ' is back in <b>' + esc(name(to)) + '</b>');
          else if (d.moved.length) parts.push('Moved ✓ ' + esc(d.moved.length === 1 ? d.moved[0] : d.moved.length + ' items') + ' into <b>' + esc(name(to)) + '</b>' +
            (d.files ? ' · ' + d.files.toLocaleString() + ' file' + (d.files === 1 ? '' : 's') + ' followed in Search' : ''));
          d.merged.forEach(function (m) { parts.push('Merged ✓ ' + esc(m.name) + ': ' + m.n.toLocaleString() + ' files joined it' +
            (m.kept_n ? ' · <span class="bad">' + m.kept_n + ' stayed where they were (a file of the same name is already there): ' + esc(m.kept.slice(0, 5).join(', ')) + (m.kept_n > 5 ? '…' : '') + '</span>' : '')); });
          d.notes.forEach(function (n) { parts.push('<span class="bad">' + esc(n) + '</span>'); });
          said(parts.join('<br>') || 'Nothing to move: already there.');
          if (d.taken.length) {
            var dirs = d.taken.filter(function (t) { return t.dir; }), files = d.taken.filter(function (t) { return !t.dir; });
            $('fAsk').className = 'banner warn';
            $('fAsk').querySelector('.txt').innerHTML = (dirs.length ? '<b>' + esc(dirs.map(function (t) { return t.name; }).join(', ')) + ' ' + (dirs.length === 1 ? 'is' : 'are') + ' already in ' + esc(name(to)) +
                '.</b> Merge, so each becomes one folder? Every file joins it; a file whose name is already there stays where it is. <button class="btn" type="button" id="fMerge">Merge</button> <button class="btn quiet" type="button" id="fNoMerge">Stop</button>' : '') +
              (files.length ? '<p style="margin:6px 0 0">Not moved: ' + esc(files.map(function (t) { return t.name; }).join(', ')) + ' (a file of that name is already in ' + esc(name(to)) + '; rename one first).</p>' : '');
            $('fAsk').hidden = false;
            var m = $('fMerge'); if (m) m.onclick = function () { move(dirs.map(function (t) { return items.filter(function (p) { return name(p) === t.name; })[0]; }), to, true); };
            var n = $('fNoMerge'); if (n) n.onclick = function () { $('fAsk').hidden = true; };
          }
          refresh();
        }).catch(function (e) { busy = window.rushesBusy = false; said('Did not move: ' + esc(e.message), true); refresh(); });
      }
      // Removing, as Finder's Trash: at once, into Recently Removed. Undo brings it back, and so does dragging it
      // out of Recently Removed (a place in each window's list) onto a folder
      function removeNow(items) {
        if (busy || !items.length) return;
        busy = window.rushesBusy = true; $('fAsk').hidden = true; said('<span class="spin"></span> Moving to Recently Removed…');
        post({ op: 'remove', items: JSON.stringify(items) }).then(function (d) {
          busy = window.rushesBusy = false;
          wins.forEach(function (w) { items.forEach(function (p) {
            var i = w.open.findIndex(function (x) { return x === p || x.indexOf(p + '/') === 0; }); if (i > 0) w.open = w.open.slice(0, i); delete w.sel[p]; }); });
          said((d.removed.length ? 'Moved to Recently Removed ✓ ' + esc(d.removed.join(', ')) + ' · ' + d.files.toLocaleString() + ' file' + (d.files === 1 ? '' : 's') + (d.bytes ? ' · ' + size(d.bytes) : '') +
              '. Undo, or drag it back out of Recently Removed; Delete All there gives the space back.' : '') +
            d.notes.map(function (n) { return '<br><span class="bad">' + esc(n) + '</span>'; }).join(''));
          refresh();
        }).catch(function (e) { busy = window.rushesBusy = false; said('Not removed: ' + esc(e.message), true); });
      }
      $('fUndo').onclick = function () {
        if (busy) return;                                  // never beside a move still going on
        busy = window.rushesBusy = true; said('<span class="spin"></span> Undoing the last change…');
        post({ op: 'undo' }).then(function (d) { busy = window.rushesBusy = false; said('Undone ✓ ' + esc(d.said) + '.'); refresh(); $('fUndo').disabled = !d.left; })
          .catch(function (e) { busy = window.rushesBusy = false; said(esc(e.message), true); });
      };
      $('fAdd').onclick = function () { var w = new Win([wins.length ? wins[wins.length - 1].open[0] : roots[0].path]); wins.push(w); save(); wins.forEach(function (v) { v.draw(); }); };

      // ── right-click ──
      function openMenu(e, w, p, it, d) {
        closeMenu();
        var dir = it.dir ? p : d.dir, items = [];
        var inHold = (rootOf(p) || {}).hold;
        if (d.archive && !inHold) {
          items.push(['Rename…', function () {
            var n = prompt('A new name for ' + name(p), name(p)); if (!n || n === name(p)) return;
            said('<span class="spin"></span> Renaming…');
            post({ op: 'rename', item: p, name: n }).then(function (x) {
              wins.forEach(function (v) { v.open = v.open.map(function (o) { return o === p || o.indexOf(p + '/') === 0 ? d.dir + '/' + n + o.slice(p.length) : o; }); v.sel = {}; });
              save(); said('Renamed ✓ ' + esc(name(p)) + ' is now <b>' + esc(n) + '</b>' + (x.files ? ' · ' + x.files.toLocaleString() + ' files followed in Search' : '') + '.' +
                (x.notes || []).map(function (t) { return '<br><span class="bad">' + esc(t) + '</span>'; }).join('')); refresh();
            }).catch(function (x) { said('Not renamed: ' + esc(x.message), true); });
          }]);
          items.push(['New folder in ' + name(dir) + '…', function () {
            var n = prompt('Name of the new folder in ' + name(dir), 'untitled folder'); if (!n) return;
            post({ op: 'mkdir', dir: dir, name: n }).then(function () { said('Made ✓ the folder <b>' + esc(n) + '</b> in ' + esc(name(dir)) + '.'); refresh(); })
              .catch(function (x) { said('Not made: ' + esc(x.message), true); });
          }]);
        }
        if (d.archive && !inHold) items.push(['Move to Recently Removed', function () { removeNow(w.picked().length ? w.picked() : [p]); }]);
        if (!it.dir && it.plays) items.push(['Play its preview', function () { play(p, it); }]);
        if (finder) items.push(['Show in Finder', function () {
          post({ op: 'reveal', item: p }).then(function () { said('Opened ✓ ' + esc(name(p)) + ' in a Finder window.'); }).catch(function (x) { said(esc(x.message), true); }); }]);
        if (!items.length) return;
        menu = document.createElement('div'); menu.className = 'fmenu';
        items.forEach(function (m) { var b = document.createElement('button'); b.type = 'button'; b.textContent = m[0]; b.onclick = function () { closeMenu(); m[1](); }; menu.appendChild(b); });
        document.body.appendChild(menu);
        menu.style.left = Math.min(e.clientX, innerWidth - 190) + 'px'; menu.style.top = Math.min(e.clientY, innerHeight - menu.offsetHeight - 8) + 'px';
      }
      function closeMenu() { if (menu) { menu.remove(); menu = null; } }
      document.addEventListener('click', closeMenu); document.addEventListener('scroll', closeMenu, true);

      // ── Space: a clip's preview, as Quick Look ──
      function play(p, it) {
        if (!it.plays) { said(esc(name(p)) + ' has no preview yet: Make previews and descriptions (on its drive\'s page) makes them.' + (finder ? ' Show in Finder plays the original.' : '')); return; }
        var o = document.createElement('div'); o.className = 'fplay';
        o.innerHTML = '<video controls autoplay src="/db/play.php?p=' + encodeURIComponent(p) + '"></video><p>' + esc(name(p)) + ' · its preview · Space or Esc closes</p>';
        o.onclick = function (e) { if (e.target === o) o.remove(); };
        document.body.appendChild(o);
      }
      document.addEventListener('keydown', function (e) {
        var o = document.querySelector('.fplay');
        if (o && (e.key === 'Escape' || e.key === ' ')) { e.preventDefault(); o.remove(); return; }
        if (!$('tuView').hidden && current && (e.key === 'Backspace' && e.metaKey || e.key === 'Delete') && !/INPUT|TEXTAREA|SELECT/.test((e.target || {}).tagName || '')) {
          var root = rootOf(current.open[0]);
          if (root && root.archive && !root.hold && current.picked().length) { e.preventDefault(); removeNow(current.picked()); }
          return;
        }
        if ($('tuView').hidden || e.key !== ' ' || /INPUT|TEXTAREA|SELECT/.test((e.target || {}).tagName || '') || !current) return;
        var ps = current.picked(); if (ps.length !== 1) return;
        var p = ps[0], d = cache[p.slice(0, p.lastIndexOf('/'))]; if (!d) return;
        var it = d.items.filter(function (x) { return x.name === name(p); })[0];
        if (it && !it.dir) { e.preventDefault(); play(p, it); }
      });

      window.tuLoad = function () {
        get('roots=1').then(function (d) {
          roots = d.roots; finder = d.finder; cache = {};
          var was = []; try { was = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) {}
          var ok = function (o) { return Array.isArray(o) && o.length && rootOf(o[0]); };
          was = was.filter(ok);
          if (!was.length) was = [[roots[0].path], [roots[0].path]];
          $('fWins').innerHTML = ''; wins = was.map(function (o) { return new Win(o); });
          wins.forEach(function (w) { w.draw(); });
        }).catch(function (e) { $('fWins').innerHTML = '<div class="banner bad"><div class="txt">' + esc(e.message) + '</div></div>'; });
      };
    })();
    </script>
    <script>
    (function () {
      // ══ Drag and drop (db/tidy.php ?tree, ?matches, drag=1) ══
      // plan: {folder: {dept, keep, n, b, by}} kept in this browser until moved (a reload does not lose it).
      var $ = function (id) { return document.getElementById(id); };
      var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
      var size = function (b) { return b >= 1e12 ? (b / 1e12).toFixed(1) + ' TB' : b >= 1e9 ? Math.round(b / 1e9) + ' GB' : Math.max(1, Math.round(b / 1e6)) + ' MB'; };
      var num = function (n) { return Number(n).toLocaleString(); };
      var ONE = <?= json_encode($one) ?>, KEY = 'rushes-drag-plan';
      var plan = {}, no = {}, kids = {}, open = {}, depts = [], sugg = [], dragging = null;
      try { var kept = JSON.parse(localStorage.getItem(KEY) || '{}'); plan = kept.plan || {}; no = kept.no || {}; } catch (e) {}
      var save = function () { try { localStorage.setItem(KEY, JSON.stringify({plan: plan, no: no})); } catch (e) {} };
      var last = function (rel) { return rel.split('/').pop(); };
      var parent = function (rel) { return rel.indexOf('/') < 0 ? '' : rel.slice(0, rel.lastIndexOf('/')); };
      // a folder's whole path as Finder would show it: the drive it came on as a tag, then each folder, the last in bold
      var crumbs = function (rel) {
        var p = rel.split('/'), top = p.shift();
        return '<span class="crumb"><span class="drv">' + esc(top) + '</span>' + p.map(function (x, i) {
          return '<span class="sep">›</span>' + (i === p.length - 1 ? '<b>' + esc(x) + '</b>' : esc(x)); }).join('')
          + (p.length ? '' : '') + '</span>';
      };
      var ICON = function (cls) { return '<svg class="fi ' + (cls || '') + '" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M1.5 3.5A1.5 1.5 0 0 1 3 2h3.2l1.6 1.6H13A1.5 1.5 0 0 1 14.5 5.1v7.4A1.5 1.5 0 0 1 13 14H3a1.5 1.5 0 0 1-1.5-1.5z"/></svg>'; };
      var dopen = {}, peek = '';
      var FINDER = <?= $FINDER ? 'true' : 'false' ?>;
      // opens the folder in Finder, on this Mac; says so on the button for a moment
      window.finder = function (b, rel, where) {
        var was = b.textContent; b.textContent = 'Opening…';
        var f = new FormData(); f.append('finder', rel); f.append('where', where);
        fetch('/db/tidy.php', {method: 'POST', body: f}).then(function (r) { return r.json(); }).then(function (d) {
          b.textContent = d.error ? 'Did not open: ' + d.error : 'Opened in Finder ✓';
        }).catch(function () { b.textContent = 'Did not open'; }).then(function () { setTimeout(function () { b.textContent = was; }, 3000); });
      };
      var finder = window.finder;                  // departments folded or open on the right (open while they hold something)
      var SMALL = 20;                  // fewer files than this: listed under "small ones", not in the way
      var sopen = {}, ssmall = {};
      // placed: the folder itself or one above it is on the right (then it is not on the left any more)
      var placed = function (rel) { for (var r = rel; r; r = parent(r)) if (plan[r]) return true; return false; };
      // files already given a place, under a folder: taken off its count on the left, so the left side shrinks
      // as you go (a folder placed inside one already placed is counted once)
      var top = function () { return Object.keys(plan).filter(function (r) { return !placed(parent(r)); }); };
      var under = function (rel) { var n = 0, b = 0; top().forEach(function (r) { if (r.indexOf(rel + '/') === 0) { n += plan[r].n; b += plan[r].b; } }); return [n, b]; };
      var post = function (body) {
        return fetch('/db/tidy.php', {method: 'POST', body: body}).then(function (r) { return r.json(); })
          .then(function (d) { if (d.error) throw new Error(d.error); return d; });
      };
      var ask = function (what) { var f = new FormData(); Object.keys(what).forEach(function (k) { f.append(k, what[k]); }); return post(f); };

      function place(rel, dept, info, by) {
        // a folder whose own name says a department gives up its name (PARKS → Parks & Recreation); any other keeps it
        var named = info && info.dept && info.named !== false;     // only the department's own words: its name goes
        var was = plan[rel] && plan[rel].dept;
        plan[rel] = {dept: dept, keep: !named, named: !!named, n: (info && info.n) || 0, b: (info && info.b) || 0, by: by || 'you'};
        delete no[rel]; save(); soft(rel, was && was !== dept ? [was, dept] : [dept]);
      }
      function unplace(rel) { no[rel] = 1; delete plan[rel]; save(); draw(); }   // taken out: Rushes does not put it back

      function node(f) {
        if (f.name === '') return '<div class="nd loose">' + num(f.n) + ' loose file' + (f.n > 1 ? 's' : '') + ' &middot; they stay where they are</div>';
        var onWay = peek && (peek === f.rel || peek.indexOf(f.rel + '/') === 0);   // Show it: on the way to a placed folder
        if (placed(f.rel) && !onWay) return '';
        var u = under(f.rel), left = f.n - u[0];
        if (left <= 0 && !onWay) return '';             // everything in it has a place already
        var o = open[f.rel], k = kids[f.rel];
        if (peek === f.rel && plan[f.rel]) return '<div class="nd flash" data-rel="' + esc(f.rel) + '">' + ICON() + '<span class="nm">' + esc(f.name) + '</span><small>' + num(f.n) + ' files &middot; placed: moves to ' + esc(plan[f.rel].dept) + '</small></div>';
        return '<div class="nd" draggable="true" data-rel="' + esc(f.rel) + '">'
          + '<button type="button" class="tw" data-open="' + esc(f.rel) + '" aria-label="Open">' + (o ? '▾' : '▸') + '</button>' + ICON()
          + '<span class="nm">' + esc(f.name) + '</span><small>' + (u[0] ? num(left) + ' of ' + num(f.n) + ' files left &middot; ' + size(Math.max(0, f.b - u[1])) : num(f.n) + ' files &middot; ' + size(f.b)) + '</small>'
          + '<span class="acts">' + (f.dept && !no[f.rel] ? '<button type="button" class="sg" data-put="' + esc(f.rel) + '" data-dept="' + esc(f.dept) + '" title="Place it there">&rarr; ' + esc(f.dept) + (f.sure ? '' : '?') + '</button>' : '')
          + '<button type="button" class="mv" data-to="' + esc(f.rel) + '">Move to&hellip;</button></span></div>'
          + (o ? '<div class="kids">' + (k ? k.map(node).join('') : '<div class="nd loose">opening &hellip;</div>') + '</div>' : '');
      }
      function info(rel) {             // what is known of a folder: from the tree or from the suggestions
        var k = kids[parent(rel)]; var f = k && k.filter(function (x) { return x.rel === rel; })[0];
        if (f) return f;
        var m = sugg.filter(function (x) { return x.rel === rel; })[0];
        return m ? {n: m.n, b: m.b, dept: m.dept, named: m.named} : null;
      }

      function groups(list) {
        var by = {}, out = [];
        list.forEach(function (m) {
          var nm = last(m.rel), k = nm.toLowerCase();
          if (!by[k]) { by[k] = {name: nm, items: [], n: 0, b: 0}; out.push(by[k]); }
          by[k].items.push(m); by[k].n += m.n; by[k].b += m.b;
        });
        out.forEach(function (g) { g.items.sort(function (a, b) { return b.b - a.b; }); });
        return out.sort(function (a, b) { return b.b - a.b; });
      }
      // Show it: open the left side down to that folder, and point at it
      function reveal(rel) {
        peek = rel; clearTimeout(reveal.t); reveal.t = setTimeout(function () { peek = ''; draw(); }, 8000);
        var p = rel.split('/'), chain = [];
        for (var i = 1; i < p.length; i++) chain.push(p.slice(0, i).join('/'));
        var step = function () {
          var r = chain.shift();
          if (r === undefined) {
            draw(); var el = document.querySelector('#dvTree [data-rel="' + CSS.escape(rel) + '"]');
            if (el) { el.scrollIntoView({block: 'center', behavior: 'smooth'}); el.classList.add('flash'); setTimeout(function () { el.classList.remove('flash'); }, 2500); }
            return;
          }
          var known = Object.keys(kids).some(function (k) { return (kids[k] || []).some(function (f) { return f.rel === r; }); });
          if (!known) return step();          // a named shelf's own folder: its drives are listed at the top already
          open[r] = true;
          if (kids[r]) return step();
          ask({tree: r}).then(function (d) { kids[r] = d.folders; step(); });
        };
        step();
      }

      // Every box that scrolls, from both sides up to the page (Manage's own column scrolls, not the window):
      // where each was, put back after a redraw, so nothing jumps
      function scrolled() {
        var out = [];
        [$('dvL'), $('dvR')].forEach(function (e) { for (; e; e = e.parentElement) if (e.scrollTop) out.push([e, e.scrollTop]); });
        out.push([document.scrollingElement, document.scrollingElement.scrollTop]);
        return function () { out.forEach(function (x) { x[0].scrollTop = x[1]; }); };
      }
      function draw() {
        var back = scrolled();
        try { paint(); } finally { back(); }
      }
      // one department's block on the right
      function deptHtml(d) {
          // what is under a department is what moves there: Rushes places what matches, you take out what does not belong
          var here = Object.keys(plan).filter(function (r) { return plan[r].dept === d; })
            .sort(function (a, b) { return (plan[b].guess ? 1 : 0) - (plan[a].guess ? 1 : 0) || plan[b].b - plan[a].b; });
          var hn = 0, gs = 0; here.forEach(function (r) { if (!placed(parent(r))) hn += plan[r].n; if (plan[r].guess) gs++; });
          var has = here.length, o = dopen[d] !== undefined ? dopen[d] : has;
          return '<div class="db' + (has ? '' : ' empty') + '" data-dept="' + esc(d) + '"><div class="dh">'
            + (has ? '<button type="button" class="tw" data-dopen="' + esc(d) + '">' + (o ? '▾' : '▸') + '</button>' : '<span style="width:20px"></span>') + ICON('d') + esc(d)
            + '<small>' + (has ? here.length + ' folder' + (here.length > 1 ? 's' : '') + ' &middot; ' + num(hn) + ' files' + (gs ? ' &middot; <span style="color:var(--warn)">' + gs + ' to check</span>' : '') : 'drop folders here') + '</small></div>'
            + (o && has ? '<div class="kids">' + here.map(function (r) {
                var p = plan[r], nm = p.name || last(r),
                    what = p.keep ? esc(d) + ' / ' + esc(nm) + ' / year / each folder in it' : esc(d) + ' / year / what is inside it';
                return '<div class="tr pl" draggable="true" data-rel="' + esc(r) + '">' + ICON() + '<span class="nm"><b>' + esc(last(r)) + '</b>'
                  + (p.guess ? '<span class="tag2" style="color:var(--warn);border-color:var(--warn)">guess: check it</span>' : p.by === 'rushes' ? '<span class="tag2">same name</span>' : '')
                  + '<small>' + crumbs(r) + '</small>'
                  + '<small>' + (p.n ? num(p.n) + ' files &middot; ' + size(p.b) + ' &middot; ' : '') + '&rarr; ' + what + '</small>'
                  // kept as a folder, under a name you can change: two folders given the same name become one (DRONE)
                  + (p.named ? '' : '<small><label style="cursor:pointer"><input type="checkbox" data-keep="' + esc(r) + '"' + (p.keep ? ' checked' : '') + '> keep it as a folder</label>'
                    + (p.keep ? ' named <input type="text" class="nmin" data-name="' + esc(r) + '" value="' + esc(nm) + '" aria-label="The folder\'s name">' : '') + '</small>')
                  + '</span><span class="acts"><button type="button" class="lnk2" data-show="' + esc(r) + '">' + (FINDER ? 'Show in Finder' : 'Show it') + '</button>'
                  + '<button type="button" class="btn quiet sm" data-off="' + esc(r) + '" title="It goes back to the left and does not move">Take out</button></span></div>';
              }).join('') + '</div>' : '')
            + '</div>';
      }
      // the counts at the top of the left side and on Move
      function counts() {
        var n = 0, b = 0, c = Object.keys(plan).length;
        top().forEach(function (r) { n += plan[r].n; b += plan[r].b; });
        var all = (kids[''] || []).reduce(function (s, f) { return s + (f.name ? f.n : 0); }, 0);
        $('dvLeft').textContent = all ? (all - n > 0 ? num(all - n) + ' of ' + num(all) + ' files still to place' : 'Everything has a place ✓') + (n ? ' · ' + num(n) + ' placed' : '') : '';
        $('dvAsk').disabled = !c;
        $('dvAsk').textContent = c ? 'Move ' + (n ? num(n) + ' files (' + size(b) + ') from ' : '') + c + ' folder' + (c > 1 ? 's' : '') + '…' : 'Nothing placed yet';
        $('dvSure').hidden = true;
      }
      function paint() {
        $('dvTree').innerHTML = kids[''] ? (kids[''].map(node).join('')
          || '<p class="note" style="margin:10px 12px">Nothing waits to be filed.</p>') : '<p class="note" style="margin:10px 12px">Looking &hellip;</p>';
        $('dvDepts').innerHTML = depts.map(deptHtml).join('');
        counts();
      }
      // A folder placed with Move to… (or dropped): only what changed is touched, so nothing jumps or flashes. The
      // folder's row leaves the left side, the folders above it count again, and only its department is drawn again.
      function soft(rel, depts2) {
        var back = scrolled();
        var row = document.querySelector('#dvTree [data-rel="' + CSS.escape(rel) + '"]');
        if (row) { var nx = row.nextElementSibling; if (nx && nx.classList.contains('kids')) nx.remove(); row.remove(); }
        for (var a = parent(rel); a; a = parent(a)) {
          var el = document.querySelector('#dvTree [data-rel="' + CSS.escape(a) + '"]'), f = info(a);
          if (!el || !f) continue;
          var u = under(a), left = f.n - u[0];
          if (left <= 0) { var k = el.nextElementSibling; if (k && k.classList.contains('kids')) k.remove(); el.remove(); continue; }
          var sm = el.querySelector(':scope > small');
          if (sm) sm.innerHTML = num(left) + ' of ' + num(f.n) + ' files left &middot; ' + size(Math.max(0, f.b - u[1]));
        }
        depts2.forEach(function (d) {
          var old = document.querySelector('#dvDepts .db[data-dept="' + CSS.escape(d) + '"]');
          if (!old) return;
          var t = document.createElement('div'); t.innerHTML = deptHtml(d); old.replaceWith(t.firstElementChild);
        });
        counts(); back();
      }

      function openFolder(rel) {
        open[rel] = !open[rel]; draw();
        if (!open[rel] || kids[rel]) return;
        ask({tree: rel}).then(function (d) { kids[rel] = d.folders; draw(); })
          .catch(function (e) { open[rel] = false; draw(); window.oops ? oops(e.message) : alert(e.message); });
      }

      // clicks: open a folder, take a suggestion, Move to…, keep its name, take off
      $('dragView').addEventListener('click', function (ev) {
        var t = ev.target, x;
        if ((x = t.closest('[data-open]'))) return openFolder(x.dataset.open);
        if ((x = t.closest('[data-put]'))) return place(x.dataset.put, x.dataset.dept, info(x.dataset.put));
        if ((x = t.closest('[data-yes]'))) { var m = sugg.filter(function (s) { return s.rel === x.dataset.yes; })[0]; return place(m.rel, m.dept, m); }
        if ((x = t.closest('[data-nah]'))) { no[x.dataset.nah] = 1; save(); return draw(); }
        if ((x = t.closest('[data-dopen]'))) { var dd = x.dataset.dopen; dopen[dd] = !(dopen[dd] !== undefined ? dopen[dd] : true); return draw(); }
        if ((x = t.closest('[data-sg]'))) { sopen[x.dataset.sg] = !sopen[x.dataset.sg]; return draw(); }
        if ((x = t.closest('[data-small]'))) { ssmall[x.dataset.small] = 1; return draw(); }
        if ((x = t.closest('[data-show]'))) return FINDER ? finder(x, x.dataset.show, 'archive') : reveal(x.dataset.show);
        if ((x = t.closest('[data-yesall]'))) {
          var k = x.dataset.yesall, d = k.slice(0, k.indexOf('|')), nm = k.slice(k.indexOf('|') + 1).toLowerCase();
          sugg.forEach(function (m) { if (m.dept === d && last(m.rel).toLowerCase() === nm && !plan[m.rel] && !no[m.rel] && !placed(m.rel)) plan[m.rel] = {dept: d, keep: false, n: m.n, b: m.b, by: 'you'}; });
          save(); return draw();
        }
        if ((x = t.closest('[data-off]'))) return unplace(x.dataset.off);
        if ((x = t.closest('[data-to]'))) {
          var sel = document.createElement('select');
          sel.innerHTML = '<option value="">Move to…</option>' + depts.map(function (d) { return '<option>' + esc(d) + '</option>'; }).join('');
          sel.onchange = function () { if (sel.value) place(x.dataset.to, sel.value, info(x.dataset.to)); };
          x.replaceWith(sel); sel.focus();
        }
      });
      $('dragView').addEventListener('input', function (ev) {       // the name box: kept as you type, said in its row
        var nr = ev.target.dataset && ev.target.dataset.name; if (!nr || !plan[nr]) return;
        var v = ev.target.value.replace(/[\/\\:*?"<>|]+/g, ' ').trim(); plan[nr].name = v && v !== last(nr) ? v : ''; save();
        var p = plan[nr], to = ev.target.closest('.tr').querySelectorAll('.nm > small')[1];
        if (to) to.innerHTML = (p.n ? num(p.n) + ' files &middot; ' + size(p.b) + ' &middot; ' : '') + '&rarr; ' + esc(p.dept) + ' / ' + esc(p.name || last(nr)) + ' / year / each folder in it';
      });
      $('dragView').addEventListener('change', function (ev) {
        var r = ev.target.dataset && ev.target.dataset.keep;
        if (r && plan[r]) { plan[r].keep = ev.target.checked; save(); soft('', [plan[r].dept]); return; }
        var nr = ev.target.dataset && ev.target.dataset.name;
        if (nr && plan[nr]) { var v = ev.target.value.replace(/[\/\\:*?"<>|]+/g, ' ').trim(); plan[nr].name = v && v !== last(nr) ? v : ''; save(); soft('', [plan[nr].dept]); }
      });
      // dragging: from the left onto a department, between departments, or back to the left
      $('dragView').addEventListener('dragstart', function (ev) {
        if (ev.target.closest && ev.target.closest('input')) { ev.preventDefault(); return; }   // typing a name is not dragging
        var x = ev.target.closest('[data-rel]'); if (!x) return;
        dragging = x.dataset.rel; ev.dataTransfer.setData('application/x-rushes-folder', dragging); ev.dataTransfer.effectAllowed = 'move';
      });
      var target = function (ev) { return ev.target.closest('.db') || ev.target.closest('#dvL'); };
      // a drop anywhere else on the page does nothing: the browser must never take a dragged folder as a link to open
      window.addEventListener('dragover', function (ev) { if (dragging) ev.preventDefault(); });
      window.addEventListener('drop', function (ev) { if (dragging || !ev.target.closest('#dragView .db, #dvL')) { ev.preventDefault(); } });
      $('dragView').addEventListener('dragover', function (ev) {
        var t = target(ev); if (!t || !dragging) { if (dragging) ev.dataTransfer.dropEffect = 'none'; return; }
        ev.preventDefault(); document.querySelectorAll('.over').forEach(function (o) { if (o !== t) o.classList.remove('over'); }); t.classList.add('over');
      });
      $('dragView').addEventListener('dragleave', function (ev) { var t = target(ev); if (t && !t.contains(ev.relatedTarget)) t.classList.remove('over'); });
      $('dragView').addEventListener('drop', function (ev) {
        var t = target(ev); if (!t || !dragging) return;
        ev.preventDefault(); t.classList.remove('over');
        var rel = dragging; dragging = null;
        if (t.id === 'dvL') { if (plan[rel]) unplace(rel); return; }
        if (plan[rel]) { plan[rel].dept = t.dataset.dept; plan[rel].by = 'you'; save(); draw(); }
        else place(rel, t.dataset.dept, info(rel));
      });
      $('dragView').addEventListener('dragend', function () { dragging = null; document.querySelectorAll('.over').forEach(function (o) { o.classList.remove('over'); }); });

      var drops = function () { var o = {}; Object.keys(plan).forEach(function (r) { o[r] = {dept: plan[r].dept, keep: plan[r].keep, name: plan[r].name || ''}; }); return JSON.stringify(o); };
      // Move…: the plan is worked out once, on the archive, and said as it goes ("Reading clip dates: 120 of 316
      // folders"); Yes queues exactly that plan (db/tidy.php drag=1, ?dragsaid)
      var token = '';
      $('dvAsk').onclick = function () {
        var b = this, t0 = Date.now(); token = Math.random().toString(36).slice(2, 12) + Date.now().toString(36);
        b.disabled = true; b.textContent = 'Working out where each file goes…';
        $('dvSum').textContent = 'Starting…';
        var poll = setInterval(function () {
          ask({dragsaid: token}).then(function (p) {
            if (p.done) return;
            var secs = Math.round((Date.now() - t0) / 1000);
            $('dvSum').textContent = p.said + (p.of ? ': ' + num(p.i) + ' of ' + num(p.of) + ' folders' : p.i ? ': ' + num(p.i) + ' files so far' : '') + ' · ' + secs + ' s';
          }).catch(function () {});
        }, 1000);
        ask({drag: 1, token: token, drops: drops()}).then(function (d) {
          clearInterval(poll);
          $('dvSureWhat').textContent = num(d.files) + ' files (' + size(d.bytes) + ') from ' + Object.keys(d.per).length + ' folders, into ' + <?= json_encode($shelf, JSON_UNESCAPED_UNICODE) ?>;
          var yrs = {}; Object.keys(d.per).forEach(function (r) { d.per[r].years.forEach(function (y) { yrs[y] = 1; }); });
          $('dvSureMore').textContent = Object.keys(yrs).length ? 'Filed under the years ' + Object.keys(yrs).sort().join(', ') + ' where a folder’s name or its clips agree on one; the others go in without a year.' : 'No folder has a year its clips agree on: each goes in without one.';
          $('dvEg').innerHTML = d.eg.map(function (e) { return esc(e[0]) + '<br>&nbsp;&nbsp;&rarr; ' + esc(e[1]); }).join('<br>');
          draw(); $('dvSure').hidden = false; $('dvYes').focus();
          $('dvSum').textContent = 'Worked out in ' + Math.round((Date.now() - t0) / 1000) + ' s. Nothing has moved yet.';
        }).catch(function (e) {
          clearInterval(poll); draw(); $('dvSum').textContent = '';
          $('tWait').hidden = false; $('tWait').className = 'banner bad'; $('tWait').querySelector('.txt').innerHTML = '<b>Nothing was worked out.</b> ' + esc(e.message);
        });
      };
      $('dvNo').onclick = function () { $('dvSure').hidden = true; };
      $('dvYes').onclick = function () {
        var b = this; b.disabled = true; b.textContent = 'Queuing…';
        ask({drag: 1, go: 1, token: token}).then(function (d) {
          b.disabled = false; b.textContent = 'Yes, move them';
          plan = {}; save(); kids = {}; open = {}; $('dvSure').hidden = true; $('dvSum').textContent = '';
          $('tWait').className = 'banner ok'; $('tWait').hidden = false;
          $('tWait').querySelector('.txt').innerHTML = '<b>Queued ✓</b> ' + num(d.files) + ' files (' + size(d.bytes) + '). The helper moves them one at a time, never over another file &mdash; '
            + 'the top bar shows how far it is, and Done so far below shows the result. It can be paused and carries on where it stopped.';
          load();
        }).catch(function (e) { b.disabled = false; b.textContent = 'Yes, move them'; $('dvSureMore').textContent = 'Nothing was queued: ' + e.message; });
      };

      function runs() {
        return ask({runs: 1}).then(function (d) {
          document.querySelectorAll('.tHelper').forEach(function (x) { x.textContent = d.helper; });
          if (d.waiting.length && $('tWait').className.indexOf('ok') < 0) {
            $('tWait').hidden = false; $('tWait').className = 'banner warn';
            $('tWait').querySelector('.txt').innerHTML = '<b>A tidy-up is waiting for the helper.</b> It runs next, before any copy. Moving more now adds another one behind it.';
          }
          $('tRuns').innerHTML = d.runs.length ? '<p style="margin:18px 0 6px;color:var(--fg);font-size:13px"><b>Done so far</b></p>'
            + d.runs.map(function (r) {
              return '<div class="run"><span>' + esc(r.ago) + ' &mdash; ' + num(r.moved) + ' moved (' + size(r.bytes) + ')'
                + (r.left ? ', ' + num(r.left) + ' left where they were' : '') + '<small>' + esc(r.record) + '</small></span>'
                + (r.undone ? '<span class="note">put back</span>' : '<button class="btn quiet sm" type="button" data-undo="' + esc(r.record) + '">Put back</button>') + '</div>';
            }).join('') : '';
          return d.waiting.length > 0;
        }).catch(function () { return false; });
      }
      function load() {
        runs().then(function (busy) { return ask({matches: 1}).then(function (d) { return [busy, d]; }); }).then(function (x) {
          var busy = x[0], d = x[1];
          depts = d.depts; sugg = d.matches;
          // only a folder with exactly a department's name is placed by Rushes; the rest wait for a yes. Not while
          // a tidy-up waits for the helper: what it moves is still listed here until it is done
          if (!busy) sugg.forEach(function (m) { if (!plan[m.rel] && !no[m.rel] && !placed(m.rel)) plan[m.rel] = {dept: m.dept, keep: !m.named, named: !!m.named, n: m.n, b: m.b, by: 'rushes', guess: !m.sure}; });
          Object.keys(plan).forEach(function (r) { if (depts.indexOf(plan[r].dept) < 0) delete plan[r]; });   // a department taken out of Setup
          save(); draw();
        }).catch(function (e) { $('dvDepts').innerHTML = '<div class="banner bad" style="margin:10px"><div class="txt">' + esc(e.message) + '</div></div>'; });
        ask({tree: ''}).then(function (d) { kids[''] = d.folders; draw(); })
          .catch(function (e) { $('dvTree').innerHTML = '<div class="banner bad" style="margin:10px"><div class="txt"><b>Could not read the archive&rsquo;s folders.</b> ' + esc(e.message) + '</div></div>'; });
      }

      // the two views: Drag and drop first; the list (every row worked out at once) when asked for
      var listRead = false, tuRead = false;
      function show(v) {
        try { localStorage.setItem('rushes-reorg-view', v); } catch (e) {}
        if (history.replaceState) history.replaceState(null, '', { tidy: '#tidy', drag: '#filein', list: '#list' }[v]);
        // the side bar has Reorganize and Tidy up, both this page: the one showing is the one marked
        document.querySelectorAll('.rail a.nav[href^="/structure.php"]').forEach(function (a) {
          if ((a.getAttribute('href') === '/structure.php#tidy') === (v === 'tidy')) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
        document.querySelectorAll('.views button').forEach(function (b) { b.classList.toggle('on', b.dataset.view === v); });
        $('dragView').hidden = v !== 'drag'; $('listView').hidden = v !== 'list'; $('tuView').hidden = v !== 'tidy';
        window.tidyRefresh = v === 'drag' ? load : v === 'tidy' ? window.tuLoad : window.tidyList;
        if (v === 'list' && !listRead) { listRead = true; window.tidyList(); }
        if (v === 'tidy' && !tuRead) { tuRead = true; window.tuLoad(); }
      }
      document.querySelectorAll('.views button').forEach(function (b) { b.onclick = function () { show(b.dataset.view); }; });
      // the tab: from the address (the side bar's Tidy up opens #tidy), else the last one used here
      var v = 'drag'; try { v = localStorage.getItem('rushes-reorg-view') || 'drag'; } catch (e) {}
      var h = { '#tidy': 'tidy', '#filein': 'drag', '#list': 'list' }[location.hash]; if (h) v = h;
      window.addEventListener('hashchange', function () { var x = { '#tidy': 'tidy', '#filein': 'drag', '#list': 'list' }[location.hash]; if (x) show(x); });
      show(v); load();
    })();
    </script>
  </div>
  </main>
<?php $SIDE_SELF = true; require __DIR__ . '/db/side.php'; ?>
</div>
</div>
