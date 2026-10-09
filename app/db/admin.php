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
require_once __DIR__ . '/auth.php';
require_sign_in();   // the whole page is behind the lock, not each button

// ⓘ: the explanation the page does not need to show all the time (tokens.css → .infotip)
$tip = fn($t) => '<span class="infotip" tabindex="0" data-tip="' . htmlspecialchars($t, ENT_QUOTES) . '">i</span>';
?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manage &middot; Rushes</title>
<?php require __DIR__ . '/../head.php'; ?>
<style>
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
  .big-head { padding: 16px 18px }
  .bh { display: flex; gap: 12px; align-items: flex-start; flex-wrap: wrap }
  .bh .t { flex: 1 1 280px; min-width: 0 }
  .bh-n { font-size: 20px; font-weight: 650; letter-spacing: -.01em; margin-bottom: 2px }
  .big-head .btns { margin-top: 12px }
  .chips { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 12px }
  .chips button { font: 12.5px var(--font); border: 1px solid var(--line); background: var(--surface); color: var(--muted);
    border-radius: 14px; padding: 3px 11px; cursor: pointer }
  .chips button[aria-pressed="true"] { background: var(--fg); color: var(--bg); border-color: var(--fg) }
  .grp { padding: 10px 14px; border-top: 1px solid var(--line) } .grp:first-child { border-top: 0 }
  .grp-h { display: flex; gap: 10px; align-items: baseline } .grp-h b { flex: 1; min-width: 0; overflow-wrap: anywhere }
  .cp { display: flex; gap: 10px; align-items: center; padding: 3px 0 3px 2px; font-size: 12.5px }
  .cp .p { flex: 1; min-width: 0; overflow-wrap: anywhere; color: var(--muted) }
  .cp.keep .p { color: var(--fg) }
  .tag { font-size: 11px; font-weight: 650; border-radius: 9px; padding: 1px 8px; background: var(--ok-bg); color: var(--ok); white-space: nowrap }
  .tag.go { background: var(--raised); color: var(--muted) }
  .cp .lnk { font-size: 12px; white-space: nowrap }
  .ov-now { margin: 4px 0 12px; font-size: 13.5px; color: var(--muted) } .ov-now b { color: var(--fg); font-weight: 600 }
  .drv-c .meter i { background: var(--muted) } .drv-c .meter i.hot { background: var(--warn) } .drv-c .meter i.full { background: var(--bad) }
  .mk { margin-top: 8px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; font-size: 12.5px; cursor: default; flex-basis: 100% }
  .mk label { display: flex; gap: 6px; align-items: baseline; margin: 4px 0 } .mk small { color: var(--muted) } .mk .btns { margin-top: 8px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center }
  .drv-c .inS { font-size: 12px; margin-top: 4px } .drv-c .acts { margin-top: 8px } .drv-c .acts .lnk { font-size: 12px }
  .also { padding: 4px 14px 12px } .also .row { gap: 10px; flex-wrap: wrap; padding: 7px 0; border-top: 1px solid var(--line) }
  .also .row svg { width: 20px; height: 20px; color: var(--muted); flex: none } .also .row .nm { flex: 1; min-width: 160px }
  .also .row .nm small { color: var(--muted); margin-left: 8px }
  #worth .row { gap: 10px; flex-wrap: wrap } #worth .row .nm { flex: 1; min-width: 200px } #worth .row .nm small { display: block; color: var(--muted) }
  .drvs { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px; padding: 12px 14px 14px }
  .drv-c { border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; background: var(--bg); min-width: 0 }
  .drv-c .top { display: flex; gap: 10px; align-items: center } .drv-c .top svg { flex: none; width: 28px; height: 28px; color: var(--muted) }
  .drv-c.arch .top svg { color: var(--accent, var(--fg)) }
  .drv-c b { font-size: 13.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
  .drv-c .kind { font-size: 11.5px; color: var(--muted) }
  .drv-c .meter { margin: 8px 0 4px } .drv-c .sp { font-size: 12px; color: var(--muted) }
  .drv-c .roles { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 6px }
  .drv-c .roles span { font-size: 11px; border-radius: 9px; padding: 1px 8px; background: var(--raised); color: var(--fg) }
  .drv-c .lnow { font-size: 12px; margin-top: 6px }
  /* the three levels (db/drive.php): on each card, and big on the drive's own page */
  .drv-c.open { cursor: pointer } .drv-c.open:hover { border-color: var(--muted) }
  .lvs { margin-top: 8px; display: grid; gap: 3px }
  .lv { display: grid; grid-template-columns: 74px 1fr 64px; gap: 8px; align-items: center; font-size: 11.5px; color: var(--muted) }
  .lv i { height: 4px; border-radius: 2px; background: var(--raised); overflow: hidden } .lv i b { display: block; height: 100%; background: var(--ok) }
  .lv em { font-style: normal; text-align: right; color: var(--fg) }
  .drv-c .more { font-size: 11.5px; color: var(--muted); margin-top: 6px }
  .dlv { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; padding: 12px 14px 14px }
  .dlv > div { border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; background: var(--bg) }
  .dlv .lab { font-size: 11px; text-transform: uppercase; letter-spacing: .07em; color: var(--faint); font-weight: 650 }
  .dlv .big { font-size: 20px; font-weight: 650; margin: 2px 0 6px } .dlv .lv { grid-template-columns: 1fr } .dlv .lv i { height: 6px }
  .dlv p { font-size: 12.5px; color: var(--muted); margin: 8px 0 0; line-height: 1.5 }
  .dlv .btn { margin-top: 8px; white-space: normal; max-width: 100% }
  #dvFolders td.n, #dvFolders th.n { text-align: right; white-space: nowrap }
  #dvFolders .pc { display: inline-block; width: 46px; height: 4px; border-radius: 2px; background: var(--raised); vertical-align: middle; margin-left: 6px; overflow: hidden }
  #dvFolders .pc b { display: block; height: 100%; background: var(--ok) }
  #dvFolders tr.wait td:first-child small { color: var(--warn) }
  .crumbs { font-size: 13px; padding: 10px 14px 0 } .crumbs a { color: var(--muted) }
  .cp-what { margin: 0 0 14px; font-size: 13px; color: var(--muted) } .cp-what p { margin: 0 0 4px; max-width: 90ch }
  .cp-what b { color: var(--fg) }
  .cp-route { display: flex; gap: 10px; align-items: center; flex-wrap: wrap }
  .cp-route label, .cp-opts > label { display: flex; gap: 8px; align-items: center; font-size: 13px; color: var(--muted) }
  .cp-route select, .cp-opts input[type=text], .cp-opts input:not([type]) { padding: 7px 10px; font: 13.5px var(--font); border: 1px solid var(--line);
    border-radius: var(--radius-sm); background: var(--bg); color: var(--fg); min-width: 200px }
  .cp-route .arrow { color: var(--muted); font-size: 16px }
  .cp-opts { display: flex; gap: 18px; align-items: center; flex-wrap: wrap; margin-top: 12px }
  .cp-when { display: flex; gap: 14px; flex-wrap: wrap; font-size: 13px }
  .bk .runs .row small { color: var(--muted); margin-left: 6px }
  .cp .p .same { opacity: .55 } .cp .p b { font-weight: 600; color: var(--fg) }     /* where two copies' paths differ */
  .dbox { margin-top: 12px } .dbox .in { padding: 12px 14px } .dbox .in > p { margin: 0 0 10px }
  .dbox .ex { font-size: 12.5px; color: var(--muted); margin: 0 0 10px; padding-left: 12px; border-left: 2px solid var(--line) }
  .dbox .ex div { overflow-wrap: anywhere; padding: 1px 0 } .dbox .ex b { color: var(--fg); font-weight: 600 }
  .dbox .row { gap: 10px; flex-wrap: wrap } .dbox .row .nm { flex: 1; min-width: 160px }
  .dbox .row .nm small { color: var(--muted); margin-left: 6px }
  .rr { margin-top: 18px }
  .rr .row { gap: 12px; flex-wrap: wrap } .rr .nm small { display: block; color: var(--muted); white-space: normal }
  details.fold > summary { cursor: pointer; color: var(--muted); font-size: 13px }
  .actk { display: inline-flex; gap: 4px; flex-wrap: wrap; margin-left: 10px; vertical-align: middle }
  .actk button { font: 12px var(--font); border: 1px solid var(--line); background: var(--bg); color: var(--muted); border-radius: 12px; padding: 1px 9px; cursor: pointer }
  .actk button[aria-pressed="true"] { background: var(--fg); color: var(--bg); border-color: var(--fg) }
  .hctl { display: block; margin: 12px 0; padding: 10px 14px;
          border: 1px solid var(--line); border-radius: var(--radius); font-size: 13px; background: var(--surface) }
  .hctl .t { flex: 1; min-width: 220px; color: var(--muted) }
  .hctl .t b { color: var(--fg); font-weight: 600 }
  .hctl .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--faint); flex: none }
  .hctl .dot.ok { background: var(--ok) } .hctl .dot.off { background: var(--bad) }
  .hctl .btn { padding: 6px 12px; font-size: 12.5px }
  .seg { display: inline-flex; gap: 0; flex: none }
  .seg .btn { border-radius: 0; padding: 6px 12px; font-size: 12.5px } .seg .btn + .btn { margin-left: -1px }
  .seg .btn:first-child { border-radius: 8px 0 0 8px } .seg .btn:last-child { border-radius: 0 8px 8px 0 }
  .seg .btn.on { background: var(--accent); color: #fff; border-color: var(--accent) }
  .hctl .note { margin: 6px 0 0; font-size: 12.5px; color: var(--muted) }
  /* switches, as in Rushes Helper's own window: on means it runs */
  .hctl .hgrp { margin: 4px 0 2px; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted) }
  .hctl .hgrp + .row { border-top: 0 }
  .hctl .row { display: flex; align-items: center; gap: 12px; padding: 9px 0; border-top: 1px solid var(--line) }
  .hctl .row .t { flex: 1; min-width: 0; color: var(--fg) }
  .hctl .row .t small { display: block; margin-top: 2px; color: var(--muted); line-height: 1.45 }
  .hctl .sw { appearance: none; -webkit-appearance: none; width: 38px; height: 22px; border-radius: 11px; border: 0; padding: 0;
              background: var(--line); position: relative; cursor: pointer; flex: none }
  .hctl .sw:after { content: ""; position: absolute; top: 2px; left: 2px; width: 18px; height: 18px; border-radius: 50%;
                    background: #fff; transition: left .15s }
  .hctl .sw.on { background: var(--accent) } .hctl .sw.on:after { left: 18px }
  .hctl .sw:disabled { opacity: .45; cursor: default }
  .hctl .sw:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px }
  .transfer-summary .now { border: 0; padding: 0; margin: 16px 0 10px; background: none }
  .transfer-summary .hctl { border: 0; border-top: 1px solid var(--line); border-radius: 0; background: none;
                            margin: 16px 0 0; padding: 14px 0 0 }
  .hctl .alarm { margin-top: 8px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 10px 12px;
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
  #ptBox > summary::-webkit-details-marker { display: none }
  table.prep { width: 100%; border-collapse: collapse; font-size: 13px }
  table.prep th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--faint);
                  font-weight: 650; padding: 6px 8px; border-bottom: 1px solid var(--line) }
  table.prep td { padding: 8px; border-bottom: 1px solid var(--line); vertical-align: top }
  table.prep .ok { color: var(--ok) } table.prep .busy { color: var(--accent-text); font-weight: 600 }
  table.prep .bad { color: var(--warn) } table.prep .dim { color: var(--faint) }
  #anTools .sw { appearance: none; -webkit-appearance: none; width: 38px; height: 22px; border-radius: 11px; border: 0; padding: 0;
                 background: var(--line); position: relative; cursor: pointer; flex: none }
  #anTools .sw:after { content: ""; position: absolute; top: 2px; left: 2px; width: 18px; height: 18px; border-radius: 50%;
                       background: #fff; transition: left .15s }
  #anTools .sw.on { background: var(--accent) } #anTools .sw.on:after { left: 18px }
  #anTools .sw.big { width: 52px; height: 30px; border-radius: 15px } #anTools .sw.big:after { width: 26px; height: 26px }
  #anTools .sw.big.on:after { left: 24px }
  #anTools .sw:disabled { opacity: .45; cursor: default } #anTools .sw:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px }
  #anTools .aimain { display: flex; align-items: center; gap: 14px }
  #anTools .aimain .t { flex: 1; min-width: 0 } #anTools .aimain .t b { font-size: 16px }
  #anTools .aimain .t small, #anTools .airow small { display: block; margin-top: 2px; color: var(--muted) }
  #anTools .airow { display: flex; align-items: center; gap: 12px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--line) }
  #anTools .ok { color: var(--ok) } #anTools > div { margin-top: 4px } #anTools .spin { margin-right: 6px }
  .warnline { margin: 12px 0 0; padding: 10px 12px; border: 1px solid var(--warn); background: var(--warn-bg); border-radius: 8px; font-size: 13px }
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
      <div class="banner warn" id="pwBanner">
        <div class="txt"><b>The admin password is still the default</b>
          <?php if (!on_mac()): ?>
          Anyone on this network can open this page and move your files. Change it &mdash; it takes ten seconds.
          <?php elseif (getenv('RUSHES_OTHERS') === '1'): ?>
          Other devices are let in, but none can open Rushes until you change it. Only this Mac can now.
          <?php else: ?>
          Only this Mac can open Rushes now. Change it before you let other devices in.
          <?php endif; ?></div>
        <button class="btn" type="button" id="pwGo">Change the password</button>
      </div>
      <?php endif; ?>

      <!-- always true, then only what is -->
      <!-- what is happening, in one line -->
      <p class="ov-now" id="ovNow"></p>
      <div class="tiles" id="tiles"></div>
      <!-- the drives Rushes uses, and the rest this Mac sees (state.php drives_in, drives_other) -->
      <div class="panel" id="drivesNow" style="margin-top:14px" hidden></div>
      <!-- only when there is something: a suggestion each, with the page that handles it -->
      <div class="panel" id="worth" style="margin-top:14px" hidden></div>

      <!-- what the helper is doing this second -->
      <div class="now" id="now" hidden></div>
      <!-- the helper itself: is it there, which version, and its buttons -->
      <div class="hctl" id="hctl" hidden></div>

      <section id="transferSummary" class="transfer-summary" aria-label="Transfer job" hidden></section>
      <div id="cards"></div>
      <!-- rule 5 of DEVELOPING.md: what repeats by itself is never invisible -->
      <details id="repeats" style="margin:14px 0"><summary>What runs by itself</summary>
        <table class="prep" style="margin-top:8px"><thead><tr><th>What</th><th>How often</th><th>Last</th><th></th></tr></thead><tbody id="repeatRows"></tbody></table>
      </details>

      <!-- ══ transfers ══ -->
      <section id="pane-transfers" hidden>
        <!-- From → To, chosen here (HOW-IT-WORKS.md → Copying): a whole drive into the archive, once;
             or the archive onto another drive, once or every night (db/backup.php) -->
        <div class="panel" id="cpPick" style="margin-top:8px">
          <header><b>Copy or back up</b><?= $tip('Copying is the careful way to move a lot at once. Each file is checked after it lands; a stop (a drive unplugged, Pause) carries on where it was; nothing already there is copied twice, nothing different is ever replaced, and nothing is ever deleted. Cards and new shoots come in through Ingest instead.') ?></header>
          <div style="padding:14px">
            <!-- what this page is for, said before anything is chosen: two jobs, one From → To -->
            <div class="cp-what">
              <p><b>Copy once</b> &mdash; a whole drive or server into the archive, folder by folder. From: that drive &rarr; To: the archive.
                (Cards and new shoots come in through Ingest.)</p>
              <p><b>Back up</b> &mdash; a second copy of the archive on another drive, every night or when you ask. From: the archive &rarr; To: that drive.
                It only adds: nothing on that drive is ever replaced or deleted.</p>
              <p><b>Copy a drive onto another</b> &mdash; a copy of one drive kept on another, once or every night. From: that drive &rarr; To: the other drive.
                Not added to Search: it is not the archive.</p>
            </div>
            <div class="cp-route">
              <label>From <select id="cpFrom"></select></label>
              <span class="arrow">&rarr;</span>
              <label>To <select id="cpTo"></select></label>
            </div>
            <div id="cpOpts" class="cp-opts" hidden>
              <label>Folder on that drive <input id="cpFolder" value="Rushes backup" maxlength="80"></label>
              <span class="cp-when">
                <label><input type="radio" name="cpWhen" value="1" checked> Every night</label>
                <label><input type="radio" name="cpWhen" value="0"> <span id="cpOnce">Only when I press Back up now</span></label>
              </span>
            </div>
            <p class="note" id="cpExplain" style="margin:10px 0 0"></p>
            <div class="btns" style="margin-top:12px"><button class="btn" id="cpGo" type="button" hidden></button><span class="note" id="cpSaid"></span></div>
          </div>
        </div>
        <div id="cpBackup"></div>
        <div id="cpBring">
        <div class="head" style="margin:26px 0 12px">
          <h1 style="font-size:14px">Copy from <span id="from">&mdash;</span>
            into <span id="to">&mdash;</span></h1>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px" id="panes">
          <div class="panel">
            <header><b>Not copied yet</b><span class="n" id="leftSum"></span></header>
            <div class="scroll" id="todo"></div>
          </div>
          <div class="panel">
            <header><b>Already copied</b><span class="n" id="rightSum"></span></header>
            <div class="scroll" id="landed"></div>
          </div>
        </div>
        <p class="note" id="queuenote" style="margin:12px 0 0"></p>
        <div class="btns" style="margin-top:12px">
          <button class="btn" id="goCopy" disabled>Copy</button>
          <button class="btn quiet" id="allTodo">Tick all</button>
          <span class="note" id="selnote">Nothing ticked.</span>
        </div>
        </div>
        <p class="note" id="watchhint" style="margin:16px 0 0"></p>
      </section>

      <!-- ══ duplicates ══ -->
      <!-- Find, look, Remove, Recover (HOW-IT-WORKS.md → Duplicates): Rushes chooses which copy
           stays and says why; a person can choose another, per group. Remove puts the other copies
           in Recently Removed; nothing is deleted until someone presses Delete All. -->
      <section id="pane-duplicates" hidden>
        <div class="panel big-head" style="margin-top:8px">
          <div class="bh">
            <div class="t"><div class="bh-n" id="dHead">Duplicates</div><div class="note" id="dSub"></div></div>
            <!-- Drives kept where they are (Setup 01): each looked at on its own, with its own Recently Removed -->
            <select id="dupDrive" aria-label="Where" hidden></select>
          </div>
          <div class="btns" id="dBtns"></div>
          <p class="note" id="dSaid" style="margin:8px 0 0"></p>
        </div>
        <!-- three kinds, three decisions (db/dupgroups.php): same folder, same job, different jobs -->
        <div id="dBoxes"></div>
        <div class="panel" id="dList" style="margin-top:12px" hidden>
          <header><b id="dListHead"></b><span class="n"><a href="#" id="dListClose">Close</a></span></header>
          <div id="dGroups"></div>
          <div class="btns" style="padding:10px 14px" id="dMoreBox" hidden><button class="btn quiet" id="dMore">Show more</button></div>
        </div>
        <div id="rrDup"></div>
      </section>

      <!-- ══ one drive (Overview → a card): its three levels, its folders, what can be done on it ══ -->
      <section id="pane-drive" hidden>
        <div class="panel big-head" style="margin-top:8px">
          <div class="bh"><div class="t"><div class="bh-n" id="dvHead"></div><div class="note" id="dvSub"></div></div>
            <a href="#" class="note" id="dvBack">&larr; All drives</a></div>
          <div class="btns" id="dvActs"></div>
          <p class="note" id="dvSaid" style="margin:8px 0 0"></p>
        </div>
        <div class="panel" style="margin-top:12px"><div class="dlv" id="dvLevels"></div></div>
        <div class="panel" style="margin-top:12px">
          <header><b>Folders</b><span class="n" id="dvAt"></span></header>
          <div class="crumbs" id="dvCrumbs"></div>
          <div id="dvFolders" style="padding:8px 14px 14px"></div>
        </div>
        <div id="rrDrive"></div>
      </section>

      <!-- ══ cache ══ -->
      <!-- Files editing software rebuilds by itself. Counted from the search database, so it
           takes a second. Remove puts them in Recently Removed, like duplicate copies. -->
      <section id="pane-cache" hidden>
        <div class="panel big-head" style="margin-top:8px">
          <div class="bh"><div class="t"><div class="bh-n" id="cHead">Counting&hellip;</div>
            <div class="note" id="cSub">Editing software makes these again from the originals when it needs them.</div></div></div>
          <div class="btns">
            <button class="btn" id="cMove" disabled>Remove</button>
            <button class="btn quiet" id="cBack">Recover</button>
          </div>
          <p class="note" id="cSaid" style="margin:8px 0 0"></p>
        </div>
        <div class="panel" style="margin-top:12px">
          <div id="cRows"><div class="empty">Counting&hellip;</div></div>
        </div>
        <details class="fold" style="margin-top:12px">
          <summary>Never touched <span class="note" id="cKeepN"></span>
            <span class="infotip" tabindex="0" data-tip="Someone's work, not cache: auto-saves you open when a project will not, project backups, Capture One's adjustments, Resolve's stills. Remove never moves them.">i</span></summary>
          <div class="panel" style="margin-top:8px"><div id="cKeep"></div></div>
        </details>
        <details class="fold" style="margin-top:8px">
          <summary>A few examples of what would move</summary>
          <div id="cEx" class="code block" style="white-space:pre;overflow-x:auto;margin-top:8px"></div>
        </details>
        <div id="rrCache"></div>
      </section>

      <!-- ══ activity ══ -->
      <section id="pane-activity" hidden>
        <div class="panel" style="margin-top:8px">
          <header><b>What has happened, and who did it</b>
            <span class="actk" id="actKinds"><button data-k="all" aria-pressed="true">Everything</button><button data-k="in">In</button><button data-k="out">Out</button><button data-k="changed">Changes</button><button data-k="check">Checks</button><button data-k="problem">Problems</button></span></header>
          <div id="events"></div>
        </div>
        <details style="margin-top:16px">
          <summary class="note" style="cursor:pointer">Show the raw log — the jobs this machine ran, in the order they ran:
            newest at the bottom, the last few screens only</summary>
          <pre id="log" class="code block" style="max-height:420px;overflow:auto;
               white-space:pre-wrap;margin-top:10px">&nbsp;</pre>
        </details>
      </section>

      <!-- ══ describe ══ -->
      <section id="pane-describe" hidden>
        <!-- 1 the AI: is it here, and when it runs -->
        <div class="panel" style="margin-top:8px">
          <header><b>AI</b> <span class="note">· describes every shot and writes down what is said, on the helper Mac; nothing is sent anywhere</span></header>
          <div style="padding:14px">
            <div id="anTools"></div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px">
              <button class="btn quiet" id="anPause" type="button" hidden></button>
              <span class="note" id="anPauseSaid"></span>
            </div>
          </div>
        </div>

        <!-- 2 the folders: one job per folder, proxies on the archive machine, then descriptions on the helper -->
        <div class="panel" style="margin-top:14px">
          <header><b>Folders to describe</b><?= $tip('Two steps for each folder, in order. 1 · Proxies, on the archive machine: a small, light copy of each video (H.264) that plays in any browser, kept in their own folder, PROXIES, with the same paths as the originals; made at low priority, and a file that arrived in the last two hours waits, so nothing still being copied is touched. 2 · Descriptions, on the helper: every shot, a sentence, the text on screen, shot size, people, light, themes and tags, and everything said, in its language; the cuts and sound from the proxy, the pictures from the original at full quality. Kept in _rushes/analysis; nothing in the archive changes. Folders run one at a time, top to bottom; nothing is done twice, so adding a folder again only does what is new.') ?></header>
          <div style="padding:14px">
            <p class="note" style="margin:0 0 10px">Rushes makes a light copy of each video to play in Search, then the AI describes it.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
              <button class="btn" id="prepChoose" type="button">Choose in Finder…</button>
              <input id="prepPick" type="file" webkitdirectory hidden>
              <input id="prepPath" placeholder="or type a folder in the archive"
                     style="flex:1;min-width:220px;padding:8px 10px;font:13.5px var(--font);border:1px solid var(--line);
                            border-radius:var(--radius-sm);background:var(--bg);color:var(--fg)">
              <button class="btn quiet" id="prepGo" type="button">+ Add</button>
              <button class="btn quiet" data-px="proxy-stop" id="pxStop" type="button" hidden>Stop</button>
            </div>
            <p class="note" id="prepSaid" style="margin:10px 0 0"></p>
            <div id="pxState" class="note" style="margin-top:6px"></div>
            <div id="prepTable" style="margin-top:12px"></div>
          </div>
        </div>

        <!-- 3 proxy quality: folded, the default suits almost everyone -->
        <details class="panel" id="ptBox" style="margin-top:14px">
          <summary style="padding:12px 14px;cursor:pointer;list-style:none;display:flex;gap:8px;align-items:center">
            <b>Proxy quality</b> <span class="note" id="ptNow"></span> <span class="note" style="margin-left:auto">Change</span></summary>
          <div style="padding:0 14px 14px">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px">
              <select id="ptSet" style="padding:7px 10px;font:13.5px var(--font);border:1px solid var(--line);border-radius:var(--radius-sm);background:var(--bg);color:var(--fg)">
                <optgroup label="<?= on_mac() ? 'On the Mac\'s media engine' : 'On the video chip' ?> — fast, the processor stays free">
                  <option value="720 4">720p · 4 Mbit/s — about 30 MB a minute (the default)</option>
                  <option value="720 6">720p · 6 Mbit/s — about 45 MB a minute</option>
                  <option value="1080 4">1080p · 4 Mbit/s — about 30 MB a minute</option>
                  <option value="1080 6">1080p · 6 Mbit/s — about 45 MB a minute</option>
                </optgroup>
                <optgroup label="In software — often a better picture than an older chip; slower, uses the processor">
                  <option value="720 sw">720p · software — about 25 MB a minute (varies with the shot)</option>
                  <option value="1080 sw">1080p · software — about 50 MB a minute (varies with the shot)</option>
                </optgroup>
              </select>
              <button class="btn quiet" id="ptSave" type="button">Use this setting</button>
              <a href="#" class="note" id="ptGo"<?= on_mac() ? ' hidden' : '' ?>>Test on a clip…</a><?= on_mac() ? '' : $tip('Pick one clip from the archive in Finder. Twenty seconds of it are made at each setting, and a still from each appears beside one from the original, at the same moment: choose by eye. Proxies made from then on use it; the ones already made stay as they are. Nothing is uploaded (Safari\'s button says Upload, but only the clip\'s name and size are read) and nothing in the archive changes. The test clips are kept in _rushes/proxy-test, to play full screen.') ?>
            </div>
            <input id="ptPick" type="file" accept="video/*,.mxf,.MXF,.mts,.MTS" hidden>
            <p class="note" id="ptSaid" style="margin:8px 0 0"></p>
            <div id="pxTest"></div>
          </div>
        </details>

        <!-- 4 what has been described -->
        <div class="panel" style="margin-top:14px">
          <header><b>Described so far</b></header>
          <div style="padding:14px"><div class="tiles" id="anTiles"></div></div>
        </div>

        <div class="panel" style="margin-top:14px">
          <header><b>Latest described</b> <span class="note">· to check the quality, shot by shot</span></header>
          <div id="anLatest" class="mgrid" style="padding:14px"><div class="note">Nothing described yet.</div></div>
        </div>
      </section>

      <!-- ══ editors' projects (HOW-IT-WORKS.md → Projects in and out) ══ -->
      <section id="pane-projects" hidden>
<?php
require_once __DIR__ . '/schema.php'; db_init();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$ago = function (int $t): string { if (!$t) return '—'; $d = time() - $t;
    return $d < 3600 ? max(1, intdiv($d, 60)) . ' min ago' : ($d < 86400 ? intdiv($d, 3600) . ' h ago' : date('Y-m-d', $t)); };
$restDays  = (int)(settings()['projects']['rest_days'] ?? 10);
$asideDays = (int)(settings()['projects']['aside_days'] ?? 0);   // projects are kept on the NAS now, nothing to move aside by default
// What the runner moved lately, newest first (projects.php writes it)
$moves = array_reverse(array_slice(@file(web_dir() . '/projects-moves.tsv', FILE_IGNORE_NEW_LINES) ?: [], -8));
$prj = []; $r = db()->query('SELECT p.*, (SELECT COUNT(*) FROM delivered d WHERE d.project = p.path) AS taken FROM projects p ORDER BY saved DESC LIMIT 500');
while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $prj[] = $x;
// What each Watcher last said, and the end of its log: the same lines the editor sees on that computer.
$said = [];
foreach (watchers() as $k => $w) {
    $key = substr($k, 0, 16); $t = @file_get_contents(web_dir() . "/watchers/$key.txt") ?: '';
    [$head, $log] = array_pad(explode("\n--\n", $t, 2), 2, '');
    $f = []; foreach (explode("\n", $head) as $l) { [$a, $b] = array_pad(explode("\t", $l, 2), 2, ''); $f[$a] = $b; }
    $said[$key] = ['host' => $w['host'] ?? $key, 'at' => (int)($f['at'] ?? 0), 'state' => $f['state'] ?? '', 'now' => $f['now'] ?? '', 'log' => trim($log)];
}
?>
        <div class="panel" style="margin-top:8px">
          <header><b>Editors' projects</b> <span class="note">as each editor's Rushes Watcher reports them, newest save first</span></header>
<?php if (!$prj): ?>
          <div class="empty">No project reported yet. Pair an editor's computer in Setup &rarr; Editors' work, and its projects appear here after their next save.</div>
<?php else: ?>
          <div style="overflow-x:auto"><table class="prep"><thead><tr><th>Project</th><th>Computer</th><th>Last saved</th>
            <th title="files the project uses from outside the archive">From outside</th><th>Missing</th><th>In the archive</th><th>State</th></tr></thead><tbody>
<?php foreach ($prj as $p):
    $idle = (int)$p['saved'] ? time() - (int)$p['saved'] : 0;
    $state = $p['state'] === 'aside' ? 'moved aside' : ($idle > $restDays * 86400 ? 'resting' : 'active');
    // why it will, or will not, be moved aside: said before it happens
    $when = $state !== 'resting' || !$asideDays ? ''
          : ((string)$p['archived'] === '' ? 'stays: no copy of the project in the archive yet'
          : ((string)$p['missing'] !== '' ? 'stays: files it uses are missing'
          : 'its folder moves aside after ' . date('j M', max((int)$p['saved'], (int)$p['aside_at']) + $asideDays * 86400))); ?>
            <tr><td><b><?= $h($p['name']) ?></b><div class="note"><?= $h($p['path']) ?></div></td>
              <td><?= $h($p['host']) ?></td><td><?= $ago((int)$p['saved']) ?></td>
              <td><?= (int)$p['outside'] ?></td>
              <td<?= $p['missing'] !== '' ? ' class="bad" title="' . $h($p['missing']) . '"' : '' ?>><?= $p['missing'] !== '' ? count(explode(';', $p['missing'])) : '0' ?></td>
              <td><?= (int)$p['taken'] ?> file<?= (int)$p['taken'] === 1 ? '' : 's' ?><?= $p['archived'] ? '<div class="note">project kept: ' . $h($p['archived']) . '</div>' : '' ?></td>
              <td title="resting: no save for <?= $restDays ?> days; nothing moves"><?= $state ?>
                <?= $state === 'moved aside' ? '<div class="note">' . date('j M Y', (int)$p['aside_at']) . ', to _Moved aside</div>'
                    . '<button type="button" class="ghost" data-back="' . $h(dirname($p['path'])) . '">Bring it back</button>'
                    : ($when !== '' ? '<div class="note">' . $h($when) . '</div>' : '') ?></td></tr>
<?php endforeach; ?>
          </tbody></table></div>
<?php endif; ?>
<?php if ($moves): ?>
          <div class="note" style="padding:10px 14px">Moved lately:
            <?php foreach ($moves as $m): [$t, $w, $d, $why] = array_pad(explode("\t", $m), 4, ''); ?>
              <div><?= date('j M H:i', (int)$t) ?> · <?= $h($d) ?> · <?= $w === 'aside' ? 'moved aside' : 'brought back' ?><?= $why === 'ok' ? ' ✓' : ' — ' . $h($why) ?></div>
            <?php endforeach; ?></div>
<?php endif; ?>
        </div>
        <script>
        // Bring it back: asked twice, on the button; the runner does it within a minute.
        document.querySelectorAll('[data-back]').forEach(function (b) {
          b.onclick = async function () {
            if (!sure(b, 'Sure? Back to ' + b.dataset.back, 'back:' + b.dataset.back)) return;
            b.disabled = true;
            try {
              var r = await (await fetch('/db/projects.php', {method: 'POST', body: new URLSearchParams({action: 'back', folder: b.dataset.back})})).json();
              b.outerHTML = '<div class="note">' + (r.error ? 'Did not happen: ' + r.error : r.said).replace(/</g, '&lt;') + '</div>';
            } catch (e) { b.disabled = false; b.textContent = 'Could not ask — try again'; }
          };
        });
        </script>
    <!-- ══ Premiere projects after a tidy-up ══ -->
    <div class="panel" id="relink" style="margin-top:14px"><header><b>Edit projects after a tidy-up</b></header><div style="padding:14px">
      <p class="note" style="margin:0 0 8px;color:var(--fg)">A tidy-up moves footage, so a project that used it opens with &ldquo;media offline&rdquo;.
         Choose the project here and Rushes points every clip at where the tidy-up put it, from the record
         of every move. You get a copy, &ldquo;<i>name</i> (relinked)&rdquo;; the file you chose is not changed.
         It is read on this computer: only the file paths written in it are sent to Rushes, never the project.</p>
      <p class="note">Premiere: the project itself (<code>.prproj</code>). Final Cut Pro: export the library or event as
         XML (<code>.fcpxml</code>), relink it here, and import the copy. DaVinci Resolve: export the timeline as FCPXML
         or as XML, relink it here, and import the copy.</p>
      <div class="btns">
        <button class="btn" type="button" id="rlGo">Choose a project or an XML&hellip;</button>
        <input type="file" id="rlPick" accept=".prproj,.fcpxml,.xml" hidden>
      </div>
      <p class="note" id="rlSaid" style="margin:10px 0 0"></p>
      <div id="rlOut"></div>
    </div></div>
    <script>
    (function () {
      var $ = function (id) { return document.getElementById(id); };
      var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
      var ENT = {amp: '&', lt: '<', gt: '>', quot: '"', apos: "'"};
      var dec = function (s) { return s.replace(/&(#x[0-9a-f]+|#\d+|\w+);/gi, function (m, e) {
        return e[0] === '#' ? String.fromCodePoint(e[1].toLowerCase() === 'x' ? parseInt(e.slice(2), 16) : +e.slice(1)) : (ENT[e] || m); }); };
      var enc = function (s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
      var TEXT = />([^<>]{3,2000})</g;                 // every piece of text in the project
      var isPath = function (s) { return /[\\/]/.test(s) && /\.\w{2,5}$/.test(s); };
      // file:///Volumes/A/b.mov -> /Volumes/A/b.mov; file:///Z:/A -> Z:\A; file://server/share/A -> \\server\share\A
      var toPath = function (u) {
        var m = u.match(/^file:\/\/([^\/]*)(\/.*)$/i); if (!m) return '';
        var p; try { p = decodeURIComponent(m[2]); } catch (e) { return ''; }
        if (m[1] && m[1].toLowerCase() !== 'localhost') return '\\\\' + m[1] + p.replace(/\//g, '\\');
        return /^\/[A-Za-z]:/.test(p) ? p.slice(1).replace(/\//g, '\\') : p;
      };
      // and back, written the way the file wrote it (with or without "localhost")
      var toUrl = function (p, was) {
        var pre = /^file:\/\/localhost\//i.test(was) ? 'file://localhost' : 'file://';
        var segs = function (s) { return s.split(/[\\\/]+/).filter(Boolean).map(encodeURIComponent).join('/'); };
        if (/^\\\\/.test(p)) { var parts = p.split('\\').filter(Boolean); return 'file://' + parts.shift() + '/' + segs(parts.join('/')); }
        if (/^[A-Za-z]:/.test(p)) return pre + '/' + p.slice(0, 2) + '/' + segs(p.slice(2));
        return pre + '/' + segs(p);
      };
      $('rlGo').onclick = function () { $('rlPick').value = ''; $('rlPick').click(); };
      $('rlPick').onchange = async function () {
        var f = (this.files || [])[0], said = $('rlSaid'), out = $('rlOut');
        if (!f) return;
        out.innerHTML = ''; said.textContent = 'Reading ' + f.name + ' on this computer…';
        try {
          if (!window.DecompressionStream) throw new Error('this browser is too old to open a Premiere project (Safari 16.4 or newer, or Chrome, is needed)');
          var buf = await f.arrayBuffer(), b = new Uint8Array(buf, 0, 2);
          var xml = b[0] === 0x1f && b[1] === 0x8b      // a .prproj is gzipped XML
            ? await new Response(new Blob([buf]).stream().pipeThrough(new DecompressionStream('gzip'))).text()
            : new TextDecoder().decode(buf);
          var head = xml.slice(0, 4000);
          var kind = /<PremiereData/.test(head) ? 'premiere' : /<fcpxml/.test(head) ? 'fcpxml' : /<xmeml/.test(head) ? 'xmeml' : '';
          if (!kind) throw new Error(f.name + ' is not a Premiere project, an FCPXML, or an XML from Premiere or Resolve');
          // FCPXML and XML name each file as an address (file:///Volumes/…): a path in, a path out.
          var URLS = kind === 'fcpxml' ? /(\ssrc=")(file:[^"]+)(")/g : /(<pathurl>)(file:[^<]+)(<\/pathurl>)/g;
          var found = new Set();
          if (kind === 'premiere') xml.replace(TEXT, function (m, t) { var d = dec(t); if (isPath(d)) found.add(d); return m; });
          else xml.replace(URLS, function (m, a, u) { var p = toPath(dec(u)); if (p) found.add(p); return m; });
          said.textContent = 'Asking Rushes where the ' + found.size.toLocaleString() + ' files this project names are now…';
          var r = await (await fetch('/db/relink.php', {method: 'POST', body: new URLSearchParams({paths: JSON.stringify(Array.from(found))})})).json();
          if (r.error) throw new Error(r.error);
          if (!r.moved) {
            said.textContent = '';
            out.innerHTML = '<div class="banner ok"><div class="txt"><b>Nothing in ' + esc(f.name) + ' was moved by a tidy-up.</b> ' +
              (r.tidyups ? 'Its ' + found.size.toLocaleString() + ' files are where the project expects them, as far as Rushes moved them.'
                         : 'No tidy-up has run yet, so every file is where it was.') + ' No copy is needed.</div></div>';
            return;
          }
          var n = 0, fixed = kind === 'premiere'
            ? xml.replace(TEXT, function (m, t) { var to = r.map[dec(t)]; if (!to) return m; n++; return '>' + enc(to) + '<'; })
            : xml.replace(URLS, function (m, a, u, c) { var to = r.map[toPath(dec(u))]; if (!to) return m; n++; return a + enc(toUrl(to, dec(u))).replace(/"/g, '&quot;') + c; });
          var ext = (f.name.match(/\.(prproj|fcpxml|xml)$/i) || ['', 'xml'])[1];
          var blob = kind === 'premiere'
            ? await new Response(new Blob([fixed]).stream().pipeThrough(new CompressionStream('gzip'))).blob()
            : new Blob([fixed], {type: 'application/xml'});
          var name = f.name.replace(/\.(prproj|fcpxml|xml)$/i, '') + ' (relinked).' + ext, url = URL.createObjectURL(blob);
          said.textContent = '';
          out.innerHTML = '<div class="banner ok"><div class="txt"><b>' + r.moved.toLocaleString() + ' file' + (r.moved === 1 ? '' : 's') +
            ' pointed at where the tidy-up put ' + (r.moved === 1 ? 'it' : 'them') + '</b> (' + n.toLocaleString() + ' places in the project); ' +
            r.kept.toLocaleString() + ' left as they were. Open the copy in Premiere; the one you chose is unchanged.' +
            (r.missing.length ? '<br><b>' + r.missing.length + ' of them are not where the last tidy-up put them</b> — moved since, outside Rushes: ' +
              r.missing.slice(0, 5).map(esc).join(', ') + (r.missing.length > 5 ? ' …' : '') : '') +
            '<div class="btns" style="margin-top:10px"><a class="btn" id="rlGet" href="' + url + '" download="' + esc(name) + '">Save ' + esc(name) + '</a></div></div></div>';
        } catch (e) { said.textContent = 'Could not relink it: ' + e.message; }
      };
    })();
    </script>
        <div class="panel" style="margin-top:14px">
          <header><b>Editors' computers</b> <span class="note">what each Watcher is doing, and its log</span></header>
<?php if (!$said): ?>
          <div class="empty">None paired yet.</div>
<?php else: foreach ($said as $key => $s): ?>
          <details style="padding:10px 14px;border-bottom:1px solid var(--line)">
            <summary style="cursor:pointer"><b><?= $h($s['host']) ?></b> &middot; <?= $s['at'] ? $h($s['state'] ?: 'running') . ', heard ' . $ago($s['at']) : 'not heard from yet' ?>
              <?= $s['now'] !== '' ? '<span class="note"> &middot; ' . $h($s['now']) . '</span>' : '' ?></summary>
            <pre class="code block" style="max-height:300px;overflow:auto;white-space:pre-wrap;margin-top:8px"><?= $s['log'] !== '' ? $h($s['log']) : 'Nothing in its log yet.' ?></pre>
          </details>
<?php endforeach; endif; ?>
        </div>
      </section>

    </div>
  </main>

<?php require __DIR__ . '/side.php'; ?>
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
let drv = null;                       // the drive whose page is open (a card from state.php drives_in), and the folder in it
let lastRunning = '';                 // the job the runner says it is doing (state.php)
// Activity's filter: everything, or one kind (activity.php names them)
let actKind = 'all';
document.querySelectorAll('#actKinds button').forEach(function (b) {
  b.onclick = function () {
    actKind = b.dataset.k;
    document.querySelectorAll('#actKinds button').forEach(function (x) { x.setAttribute('aria-pressed', x === b); });
    load();
  };
});

// ── moving between sections ────────────────────────────────────────────────
// Where the minute's work runs: inside Rushes Helper on a Mac (HOW-IT-WORKS.md → Rushes on this Mac), or the server's runner
const ON_MAC = <?= on_mac() ? 'true' : 'false' ?>, WHERE = ON_MAC ? 'on this Mac' : 'on the server';
const CHIP = ON_MAC ? "the Mac's media engine" : 'the video chip';      // what makes the proxies
const TITLES = { overview: 'Overview', transfers: 'Copying', cache: 'Cache',
                 duplicates: 'Duplicates', describe: 'Describe', drive: 'Drive',
                 activity: 'Activity', projects: "Editors' projects" };

function show(which) {
  pane = which;
  $('title').textContent = TITLES[which] || 'Overview';
  document.querySelectorAll('.rail .nav').forEach(function (b) {
    if (b.dataset.go === which) b.setAttribute('aria-current', 'page');
    else b.removeAttribute('aria-current');
  });
  if (which === 'drive' && !drv) which = pane = 'overview';     // a reload on #drive: which drive is not kept
  ['transfers', 'duplicates', 'cache', 'describe', 'projects', 'activity', 'drive'].forEach(function (p) {
    $('pane-' + p).hidden = (p !== which);
  });
  // Overview shows the tiles and the cards; a section shows its own thing.
  // Activity in full: the short feed beside it would only repeat it
  document.querySelector('.with-side').classList.toggle('no-side', which === 'activity');
  if ($('pwBanner')) $('pwBanner').hidden = which !== 'overview';      // said where it is acted on: Overview (Setup has the form itself)
  $('tiles').hidden = (which !== 'overview');
  $('drivesNow').hidden = which !== 'overview' || !$('drivesNow').innerHTML;
  $('worth').hidden = which !== 'overview' || !$('worth').innerHTML;
  $('ovNow').hidden = which !== 'overview';
  $('cards').hidden = (which !== 'overview');
  $('repeats').hidden = (which !== 'overview');
  $('transferSummary').hidden = !latestTransfer || !['overview','transfers'].includes(which);
  if (which === 'cache') { loadCache(); loadRemoved(); }
  if (which === 'duplicates') { loadDup(); loadRemoved(); }
  if (which === 'transfers') loadCopy();
  if (which === 'drive') { loadDrive(); loadRemoved(); }
  try { history.replaceState(null, '', '#' + which); } catch (e) {}
  if (window.lastState) drawHelper(window.lastState);     // the switches show (or go) at once, not at the next refresh
}
document.addEventListener('click', function (e) {
  const a = e.target.closest && e.target.closest('[data-go-overview]'); if (!a) return;
  e.preventDefault(); show('overview'); $('hctl').scrollIntoView({ block: 'start' });
});
document.querySelectorAll('.rail .nav[data-go]').forEach(function (b) {
  b.onclick = function (e) { e.preventDefault(); show(b.dataset.go); };
});
$('sideMore').onclick = function (e) { e.preventDefault(); show('activity'); };
// The password banner's button: Setup, at the form
if ($('pwGo')) $('pwGo').onclick = function () { location.href = '/setup.php#password'; };
// ── duplicates: Find, look, Remove, Recover ─────────────────────────────────
// One press finds them (which files are the same, then which copy stays: the runner's
// "find"). Rushes chooses the copy that stays and says why; "Keep this one" chooses
// another, in the plan itself. Remove puts the other copies in Recently Removed.
let dupKind = '', dupFolder = '', dupOffset = 0, dupData = null, dupList = null, dupWasRunning = false;
const rel = function (p, root) { return root && p.indexOf(root) === 0 ? p.slice(root.length) : p; };
const base = function (p) { return String(p || '').split('/').pop(); };
// Two copies' paths, with what they share dimmed and where they differ bright:
// "PSA Promo/AUDIO/MATERIAL/Footsteps.wav" beside "PSA Promo/AUDIO/Footsteps.wav" shows MATERIAL/
function diffPath(p, other) {
  let i = 0, j = 0;
  while (i < p.length && i < other.length && p[i] === other[i]) i++;
  while (j < p.length - i && j < other.length - i && p[p.length - 1 - j] === other[other.length - 1 - j]) j++;
  const mid = p.slice(i, p.length - j);
  return '<span class="same">' + esc(p.slice(0, i)) + '</span>' + (mid ? '<b>' + esc(mid) + '</b>' : '') + '<span class="same">' + esc(p.slice(p.length - j)) + '</span>';
}
async function job(action, extra) {
  const ask = Object.assign({ action: action }, extra || {});
  if ($('dupDrive').value && ['find', 'plan', 'apply', 'undo', 'empty'].includes(action) && !('drive' in ask)) ask.drive = $('dupDrive').value;
  const j = await (await fetch('../run.php', { method: 'POST', body: new URLSearchParams(ask) })).json();
  if (j.error) throw new Error(j.error);
  busy = action; setTimeout(load, 1200);
  return j;
}
const DUPJOBS = ['find', 'scan', 'plan', 'apply', 'undo'];
const dupAsk = function (q) {
  return fetch('dupgroups.php?t=' + Date.now() + '&drive=' + encodeURIComponent($('dupDrive').value || '') + '&' + new URLSearchParams(q))
    .then(function (x) { return x.json(); });
};
async function loadDup() {
  let r;
  try { r = await dupAsk({ kind: 'folder', limit: 3 }); }      // the summary, and three examples of the safe kind
  catch (e) { $('dHead').textContent = 'Could not ask the archive'; return; }
  if (r.error) { $('dHead').textContent = r.error; return; }
  dupData = r;
  // the drives kept where they are: the archive or one of them, each with its own plan
  const sel = $('dupDrive'), was = sel.value;
  sel.hidden = !(r.drives || []).length;
  sel.innerHTML = '<option value="">The archive</option>' + (r.drives || []).map(function (d) {
    return '<option value="' + esc(d.source) + '"' + (d.connected ? '' : ' disabled') + '>' + esc(d.name) + (d.connected ? '' : ' (not plugged in)') + '</option>'; }).join('');
  sel.value = was;
  drawDup();
  if (dupList) loadList();
}
// The files of one kind (and one job), folded until asked for
async function loadList(more) {
  if (!more) dupOffset = 0;
  const r = await dupAsk({ kind: dupKind, folder: dupFolder, offset: dupOffset, limit: 40 });
  if (r.error) return;
  dupList = more && dupList ? Object.assign(r, { groups: dupList.groups.concat(r.groups) }) : r;
  drawList();
}
const KINDS = {
  folder: ['Extra copies in the same folder', 'The same file twice, side by side: a Finder copy ("IMG_3241 2.HEIC"), or a clip saved again under another name. Safe to remove.'],
  job:    ['Copies in other folders of the same job', 'A project may use one of them, so they are looked at job by job. Open a job to see which copy stays.'],
  across: ['The same file in different jobs', 'Left alone: each job\'s project may point at its own copy, and removing one would make its media go offline. Shown so you know the space is there.']
};
function drawDup() {
  const r = dupData; if (!r) return;
  const running = DUPJOBS.includes(lastRunning) || DUPJOBS.includes(busy);
  const t = r.total || { files: 0, bytes: 0, groups: 0 }, bx = r.boxes || {};
  const btn = function (id, label, go) { return '<button class="btn' + (go ? '' : ' quiet') + '" id="' + id + '">' + label + '</button>'; };
  const copies = function (n) { return n.toLocaleString() + (n === 1 ? ' copy' : ' copies'); };
  let head, sub, btns = '';
  const left = (bx.across || {}).files || 0;
  if (running) {
    head = lastRunning === 'apply' ? 'Removing the copies…' : lastRunning === 'undo' ? 'Recovering the copies…' : 'Finding duplicates…';
    sub = (lastRunning === 'find' || lastRunning === 'scan') ? 'Files of the same size are read to see which are the same: the first time can take hours on a big archive, minutes after. Nothing moves. The column on the right shows how far.' : 'The column on the right shows how far.';
  } else if (!r.built) {
    head = 'Find files that are the same file'; sub = 'Same content, whatever the name. Rushes looks, shows what it found, and nothing moves until you press Remove.';
    btns = btn('dFind', 'Find duplicates', true);
  } else if (r.done) {
    head = copies(t.files) + (t.files === 1 ? ' is' : ' are') + ' in Recently Removed';
    sub = 'From the look on ' + r.built + '. Recover puts them back where they were; Delete All, below, gives the space back.';
    btns = btn('dRecover', 'Recover') + btn('dFind', 'Find again');
  } else if (!t.files) {
    head = 'No duplicates to remove ✓';
    sub = 'Looked on ' + r.built + '.' + (left ? ' ' + copies(left) + ' of files in different jobs are left alone, below.' : '');
    btns = btn('dFind', 'Find again');
  } else {
    head = '<span class="num">' + tb(t.bytes) + '</span> in ' + copies(t.files).replace(/^(\S+) /, '$1 extra ');
    sub = 'Found ' + r.built + '. Three kinds below, from the safest. Remove puts copies in Recently Removed: nothing is deleted.' +
          ' Files editing software rebuilds by itself, and the system\'s own hidden files, are not counted here: Cache looks after those.';
    btns = btn('dFind', 'Find again');
  }
  $('dHead').innerHTML = head; $('dSub').textContent = sub; $('dBtns').innerHTML = btns;
  if ($('dFind')) $('dFind').onclick = function () {
    if (!sure(this, 'Find duplicates: reads files of the same size to see which are the same. Moves nothing.', 'dup:find')) return;
    const b = this; b.disabled = true; b.textContent = 'asked…';
    job('find').then(function () { $('dSaid').textContent = 'Asked ✓ Finding starts within a minute.'; dupWasRunning = true; })
      .catch(function (e) { b.disabled = false; b.textContent = 'Find duplicates'; $('dSaid').textContent = 'Did not happen: ' + e.message; });
  };
  if ($('dRecover')) $('dRecover').onclick = function () {
    if (!sure(this, 'Every duplicate copy in Recently Removed goes back where it was.', 'dup:recover')) return;
    const b = this; b.disabled = true; b.textContent = 'asked…';
    job('undo').then(function () { $('dSaid').textContent = 'Asked ✓ They go back within a minute.'; dupWasRunning = true; })
      .catch(function (e) { b.disabled = false; $('dSaid').textContent = 'Did not happen: ' + e.message; });
  };
  // the three kinds: what, how much, and what to do
  const show = !running && r.built && !r.done;
  $('dBoxes').innerHTML = !show ? '' : ['folder', 'job', 'across'].filter(function (k) { return (bx[k] || {}).files; }).map(function (k) {
    const b = bx[k];
    let inner = '<p class="note">' + esc(KINDS[k][1]) + '</p>';
    if (k === 'folder') {
      inner += '<div class="ex">' + (r.groups || []).map(function (g) {
        return '<div>' + diffPath(rel(g.moves[0], r.root), rel(g.keep, r.root)) + ' <span class="same">beside</span> ' + esc(base(g.keep)) + '</div>'; }).join('') +
        (b.groups > 3 ? '<div class="same">and ' + (b.groups - 3).toLocaleString() + ' more</div>' : '') + '</div>' +
        '<div class="btns"><button class="btn" data-rm="folder" data-what="' + esc(copies(b.files)) + '">Remove ' + copies(b.files) + '</button>' +
        '<button class="btn quiet" data-show="folder">Show the files</button></div>';
    } else if (k === 'job') {
      inner += b.jobs.map(function (j) {
        return '<div class="row"><span class="nm">' + esc(j.name || '(the top)') + '<small>' + tb(j.bytes) + ' · ' + copies(j.files) + '</small></span>' +
          '<button class="btn quiet" data-show="job" data-f="' + esc(j.name) + '">Show</button>' +
          '<button class="btn quiet" data-rm="job" data-f="' + esc(j.name) + '" data-what="' + esc(copies(j.files) + ' in ' + (j.name || 'the top folder')) + '">Remove</button></div>';
      }).join('');
    } else {
      inner += '<div class="btns"><button class="btn quiet" data-show="across">Show the files</button></div>';
    }
    return '<div class="panel dbox"><header><b>' + esc(KINDS[k][0]) + '</b><span class="n">' + tb(b.bytes) + ' · ' + copies(b.files) +
      (k === 'across' ? ' · left alone' : '') + '</span></header><div class="in">' + inner + '</div></div>';
  }).join('');
  $('dBoxes').querySelectorAll('[data-show]').forEach(function (b) {
    b.onclick = function () { dupKind = b.dataset.show; dupFolder = b.dataset.f || ''; loadList().then(function () { $('dList').scrollIntoView({ block: 'start', behavior: 'smooth' }); }); };
  });
  $('dBoxes').querySelectorAll('[data-rm]').forEach(function (b) {
    b.onclick = async function () {
      if (!sure(b, b.dataset.what + ' go to Recently Removed, on the same drive. Nothing is deleted; Recover puts them back.', 'dup:rm:' + b.dataset.rm + ':' + (b.dataset.f || ''))) return;
      b.disabled = true; b.textContent = 'asked…';
      try {
        const x = await (await fetch('dupgroups.php', { method: 'POST', body: new URLSearchParams({ action: 'pick', kind: b.dataset.rm,
          folder: b.dataset.f || '', drive: $('dupDrive').value || '' }) })).json();
        if (x.error) throw new Error(x.error);
        await job('apply', { pick: '1' });
        $('dSaid').textContent = 'Asked ✓ Removing ' + b.dataset.what + ' starts within a minute; Activity says when it is done.'; dupWasRunning = true;
      } catch (e) { b.disabled = false; b.textContent = 'Remove'; $('dSaid').textContent = 'Did not happen: ' + e.message; }
    };
  });
  if (!show) { dupList = null; }
  if (!dupList || r.done) $('dList').hidden = true;
}
function drawList() {
  const r = dupList; if (!r) return;
  const gs = r.groups || [];
  $('dList').hidden = !gs.length;
  $('dListHead').textContent = KINDS[dupKind][0] + (dupFolder ? ' · ' + dupFolder : '') + ' · ' + r.shown.toLocaleString() + ' files';
  $('dGroups').innerHTML = gs.map(function (g) {
    const k = rel(g.keep, r.root);
    return '<div class="grp"><div class="grp-h"><b>' + esc(base(g.keep)) + '</b><span class="note">' + tb(g.size) + ' · ' +
      (g.moves.length + 1) + ' copies</span></div>' +
      '<div class="cp keep"><span class="tag">' + (g.kind === 'across' ? 'Stays' : 'Keeps') + '</span><span class="p">' + diffPath(k, rel(g.moves[0], r.root)) + '</span>' +
        '<span class="infotip" tabindex="0" data-tip="' + esc(g.why) + '">i</span></div>' +
      g.moves.map(function (m) {
        return '<div class="cp"><span class="tag go">' + (g.kind === 'across' ? 'Stays' : 'Goes') + '</span><span class="p">' + diffPath(rel(m, r.root), k) + '</span>' +
          (g.kind === 'across' ? '' : '<button class="lnk" data-keep="' + esc(g.keep) + '" data-pick="' + esc(m) + '">Keep this one</button>') + '</div>';
      }).join('') + '</div>';
  }).join('');
  $('dMoreBox').hidden = gs.length >= r.shown;
  $('dGroups').querySelectorAll('[data-pick]').forEach(function (b) {
    b.onclick = async function () {
      b.disabled = true; b.textContent = 'choosing…';
      try {
        const x = await (await fetch('dupgroups.php', { method: 'POST', body: new URLSearchParams({ action: 'keep', keep: b.dataset.keep,
          pick: b.dataset.pick, drive: $('dupDrive').value || '' }) })).json();
        if (x.error) throw new Error(x.error);
        $('dSaid').textContent = 'Keeps ' + base(b.dataset.pick) + ' there instead ✓ The other copies go when you press Remove.';
        loadDup();
      } catch (e) { b.disabled = false; b.textContent = 'Keep this one'; $('dSaid').textContent = 'Did not happen: ' + e.message; }
    };
  });
}
$('dMore').onclick = function () { dupOffset = (dupList && dupList.groups.length) || 0; loadList(true); };
$('dListClose').onclick = function (e) { e.preventDefault(); dupList = null; $('dList').hidden = true; };
$('dupDrive').onchange = function () { dupList = null; loadDup(); loadRemoved(); };

// ── Recently Removed: on each drive, how much and since when; Delete All ─────
// Nothing in it is deleted by itself. A week is suggested; Delete All is always there,
// with Sure? saying when the week is not over yet.
async function loadRemoved() {
  let r;
  try { r = await (await fetch('removed.php?t=' + Date.now())).json(); } catch (e) { return; }
  if (r.error) return;
  const rrHtml = function (places) { return '<div class="panel rr"><header><b>Recently Removed</b>' +
    '<span class="infotip" tabindex="0" data-tip="What Remove took out of the way, on the same drive, in a folder called _Recently Removed. Nothing in it is deleted until you press Delete All. Rushes suggests keeping it ' + r.wait_days + ' days, in case something was needed.">i</span></header>' +
    places.map(function (p) {
      const days = p.at ? Math.floor((Date.now() / 1000 - p.at) / 86400) : 0;
      const left = Math.max(0, r.wait_days - days);
      const when = !p.files ? 'Empty' : (p.at ? 'removed ' + (days < 1 ? 'today' : days === 1 ? 'yesterday' : days + ' days ago') : '') +
        (p.ready ? ' · ready to delete' : ' · suggested to keep ' + left + ' more ' + (left === 1 ? 'day' : 'days'));
      return '<div class="row"><span class="nm">' + esc(p.name) + '<small>' + (p.files ? tb(p.bytes) + ' · ' + p.files.toLocaleString() + ' files · ' : '') + esc(when) + '</small></span>' +
        (p.files && r.mac ? '<button class="btn quiet" data-empty="' + esc(p.drive) + '" data-ready="' + (p.ready ? 1 : 0) + '" data-left="' + left + '" data-name="' + esc(p.name) +
          '" data-size="' + esc(tb(p.bytes)) + '">Delete All</button>' : '') + '</div>';
    }).join('') + '</div>'; };
  const html = rrHtml(r.places);
  ['rrDup', 'rrCache'].forEach(function (id) { $(id).innerHTML = html; });
  // a drive's page: its own Recently Removed only (the archive's is the one with drive '')
  const mine = drv && r.places.filter(function (p) { return p.drive === (drv.kind === 'archive' ? '' : drv.source); });
  $('rrDrive').innerHTML = mine && mine.length ? '<div style="margin-top:12px">' + rrHtml(mine) + '</div>' : '';
  document.querySelectorAll('[data-empty]').forEach(function (b) {
    b.onclick = function () {
      const what = 'Deletes everything in Recently Removed on ' + b.dataset.name + ' (' + b.dataset.size + '), for good: it cannot be recovered.' +
        (b.dataset.ready === '1' ? '' : ' Rushes suggests waiting ' + b.dataset.left + ' more ' + (b.dataset.left === '1' ? 'day' : 'days') + '.');
      if (!sure(b, what, 'empty:' + b.dataset.empty)) return;
      b.disabled = true; b.textContent = 'asked…';
      job('empty', { drive: b.dataset.empty }).then(function () { b.textContent = 'Asked ✓ deleting within a minute'; setTimeout(loadRemoved, 5000); })
        .catch(function (e) { b.disabled = false; b.textContent = 'Delete All'; oops(e.message); });
    };
  });
}

// ── asking for work ────────────────────────────────────────────────────────
const ASK = {
  scan:     <?= on_mac() ? json_encode('Read the files that have the same size as another, every byte, to find the ones that are the same (on this Mac). Hours for a big archive the first time, minutes after: what was read is remembered. Pausing copying stops it, and it carries on next time. Moves nothing.')
                 : json_encode('Read every file in the archive to find the ones that are the same (Czkawka, in its container). Hours for a big archive; other jobs wait meanwhile. Moves nothing.') ?>,
  plan:     'Look through the archive for files that are the same file. Moves nothing.',
  apply:    'Every extra copy goes to Recently Removed. Nothing is deleted; Recover puts them back.',
  undo:     'Recover: every duplicate copy in Recently Removed goes back where it was.',
  'organize-undo':  'Put back the files the old date-based layout moved.',
  import:   'Rebuild search from the file list. Seconds to minutes.',
  'gpu-test': 'Measure what the video chip can do: test encodes and one real clip, a few minutes. Writes a report; makes no video files. Downloads a public ffmpeg container image.',
  'proxy-plan':  'Count the videos that have no proxy yet, and how much there is to read. Makes nothing.',
  'proxy-build': 'Make the missing proxies, in the background on the archive machine. It takes hours to days; stopping and starting again loses nothing.',
  'proxy-stop':  'Stop making proxies. The one being made is thrown away; everything finished is kept.',
  manifest: 'Write down every file and its size, then rebuild search. A few minutes.',
  verify:   'Check every file in Recently Removed still has a twin in the archive. Moves nothing.',
  df:       'Measure free space.'
};

async function act(name, btn) {
  if (name === 'import') {
    // Same rebuild the scheduled runner does, from the file list in the web
    // folder; the old search keeps working until the new one is complete.
    // Say how it went, on the button itself. It may take minutes: waited for.
    if (!sure(btn, ASK.import, 'act:import')) return;
    const was = btn.textContent; btn.disabled = true; btn.textContent = 'Rebuilding search…';
    try {
      const r = await (await fetch('import.php?part=web&force=1', { method: 'POST', signal: AbortSignal.timeout(900000) })).json();
      btn.textContent = r.state === 'retrying' ? 'Kept the old search — ' + (r.error || 'try again')
                      : r.state === 'updating' ? 'Already rebuilding — give it a minute' : 'Search is up to date ✓';
    } catch (e) { btn.textContent = 'Could not reach the archive'; }
    setTimeout(function () { btn.textContent = was; btn.disabled = false; load(); }, 3000);
    return;
  }
  if (name === 'cachejunk') { show('cache'); return; }
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
  if (name.charAt(0) === '#'){ show(name.slice(1)); return; }      // a card's button that opens a section
  if (ASK[name] && !sure(btn, ASK[name], 'act:' + name)) return;
  const was = btn.textContent;
  btn.disabled = true; btn.textContent = 'asked…';
  try {
    const ask = { action: name };
    if (['plan', 'apply', 'undo', 'verify'].includes(name) && $('dupDrive').value) ask.drive = $('dupDrive').value;
    const j = await (await fetch('../run.php', { method: 'POST',
      body: new URLSearchParams(ask) })).json();
    if (j.error) { oops(j.error); btn.disabled = false; btn.textContent = was; return; }
    busy = name;
  } catch (e) {
    oops('could not reach the archive: ' + e.message);
    btn.disabled = false; btn.textContent = was;
  }
  setTimeout(load, 1200);
}

// ── cache: Remove and Recover, as for duplicates ─────────────────────────────
async function loadCache() {
  try {
    const d = await (await fetch('junk.php?json=1&t=' + Date.now())).json();
    const n = d.total.files, b = d.total.bytes;        // each file once, even when two groups name it
    $('cHead').innerHTML = n ? '<span class="num">' + tb(b) + '</span> of cache, in ' + n.toLocaleString() + (n === 1 ? ' file' : ' files') : 'No cache to remove ✓';
    $('cRows').innerHTML = d.sweep.map(function (x) {
      return '<div class="row' + (x.files ? '' : ' dim') + '"><span class="nm">' + esc(x.label) + '</span>' +
        '<span class="n">' + x.files.toLocaleString() + '</span><span class="sz">' + tb(x.bytes) + '</span></div>';
    }).join('');
    $('cKeep').innerHTML = d.keep.map(function (x) {
      return '<div class="row"><span class="nm">' + esc(x.label) +
        (x.why ? '<small>' + esc(x.why) + '</small>' : '') + '</span>' +
        '<span class="n">' + x.files.toLocaleString() + '</span><span class="sz">' + tb(x.bytes) + '</span></div>';
    }).join('');
    $('cKeepN').textContent = '(' + d.keep.length + ' kinds, ' + d.keep.reduce(function (a, x) { return a + x.files; }, 0).toLocaleString() + ' files)';
    $('cEx').textContent = d.examples.join('\n') || 'Nothing to move.';
    $('cMove').disabled = !n;
    $('nCache').textContent = n ? tb(b) : '';
  } catch (e) {
    $('cRows').innerHTML = '<div class="empty">Could not count the cache: ' + esc(e.message) + '</div>';
  }
}
// Remove: work out which files are cache now, then move them, into Recently Removed
$('cMove').onclick = async function () {
  const btn = this;
  if (!sure(btn, 'They go to Recently Removed, on the same drive: nothing is deleted, and Recover puts them back. ' +
      'Editing software makes them again from the originals when it needs them.', 'cache-out')) return;
  btn.disabled = true; btn.textContent = 'listing them…';
  try {
    const r = await fetch('junk.php?write=1&t=' + Date.now());
    if (!r.ok) throw new Error('junk.php said ' + r.status);
    btn.textContent = 'asked…';
    await job('cacheclean');
    $('cSaid').textContent = 'Asked ✓ Removing starts within a minute; Activity says when it is done.';
  } catch (e) { $('cSaid').textContent = 'Did not happen: ' + e.message; }
  setTimeout(function () { btn.textContent = 'Remove'; btn.disabled = false; }, 4000);
};
// Recover: every cache file in Recently Removed goes back where it was
$('cBack').onclick = async function () {
  const b = this;
  if (!sure(b, 'Every cache file in Recently Removed goes back where it was.', 'cache-back')) return;
  b.disabled = true; b.textContent = 'asked…';
  try { await job('cache-undo'); $('cSaid').textContent = 'Asked ✓ They go back within a minute; Activity says when it is done.'; }
  catch (e) { $('cSaid').textContent = 'Did not happen: ' + e.message; }
  setTimeout(function () { b.disabled = false; b.textContent = 'Recover'; }, 4000);
};

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

let lastLanded = [];
function drawMove(d) {
  const landed = d.landed || [];
  lastLanded = landed;
  $('nTransfers').textContent = secs.length ? secs.length : '';

  const s = JSON.stringify([secs, landed]);
  if (s === sig) return;                 // no redraw, so your ticks survive
  sig = s;

  const picked = cpFromName();
  $('from').textContent = (d.route && d.route.from) || picked || 'the source';
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
  }).join('') : '<div class="empty">' + (picked ? (cpLooking === picked ? 'Looking inside ' + picked + '… (about a minute)' : 'Press Continue, above, to see its folders.') : 'Nothing left to copy.') + '</div>';

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
      if (!sure(b, 'Break it into its subfolders, to do in pieces. Measuring takes a few minutes; nothing is copied.',
                'split:' + b.dataset.s)) return;
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
  $('goCopy').textContent = ticked.size ? 'Copy ' + ticked.size + (ticked.size === 1 ? ' folder' : ' folders') + ' · ' + tb(b)
                          : (off ? 'Clear the list' : 'Copy');
  // the space it needs, against the archive's free space: said before, not found out part-way
  const free = (window.lastState && lastState.disk && lastState.disk.free) || (cpData && cpData.archive_free);   // state's: right on big network volumes too
  $('selnote').innerHTML = !ticked.size ? 'Tick the folders to copy.'
    : free && b > free ? '<span class="bad">' + tb(b) + ' ticked, and ' + esc(cpData ? cpData.archive_name : 'the archive') + ' has ' + tb(free) + ' free: it would stop part-way.</span> ' +
      'What the archive already has is not copied again, so it may need less.'
    : 'What the archive already has is skipped.' + (free && cpData ? ' ' + esc(cpData.archive_name) + ' has ' + tb(free) + ' free.' : '');
}

$('allTodo').onclick = function () {
  secs.forEach(function (x) { ticked.add(x.path); });
  sig = ''; load();
};

$('goCopy').onclick = async function () {
  const paths   = [...ticked];
  const dropped = secs.filter(function (x) { return x.state === 'queued' && !ticked.has(x.path); }).length;
  if (!paths.length && !dropped) return;
  if (!sure($('goCopy'), paths.length + ' folder(s), one after another, in this order; anything already in the archive is skipped.' +
      (dropped ? ' ' + dropped + ' that were queued are unticked, so they come off the list (anything already copied stays).' : ''),
      'handover')) return;
  await sendQueue(paths, '', $('goCopy'), 'Copy');
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

// ── Copying: From → To (db/backup.php) ─────────────────────────────────────
// A source into the archive (the folders below, ticked and copied once), or the archive onto
// another drive: the backup, once or every night. Chosen here, never typed: the drives are the
// ones the helper reports.
let cpData = null, cpAt = 0, cpWas = { from: null, to: null }, cpLooking = '', cpOptsFor = '';
const ARCH = '@archive';
async function loadCopy() {
  try { cpData = await (await fetch('backup.php?t=' + Date.now())).json(); } catch (e) { return; }
  if (cpData.error) return;
  cpAt = Date.now(); drawCopy();
}
// by the calendar, not by hours: last night at 11:40 is "Yesterday" the next morning
function dayWord(at) {
  const d = new Date(at * 1000); d.setHours(0, 0, 0, 0);
  const n = Math.round((new Date().setHours(0, 0, 0, 0) - d) / 86400000);
  return n < 1 ? 'Today' : n === 1 ? 'Yesterday' : n + ' days ago';
}
function whenWords(at) {
  if (!at) return 'never yet';
  const d = new Date(at * 1000);
  const t = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  const w = dayWord(at);
  return (w === 'Today' ? 'today ' : w === 'Yesterday' ? 'yesterday ' : d.toLocaleDateString([], { day: 'numeric', month: 'short' }) + ', ') + t;
}
// the drive chosen under From for Copy once, by its name alone ('' when it is the archive)
function cpFromName() {
  const o = $('cpFrom') && $('cpFrom').selectedOptions[0];
  return o && o.value !== '@archive' && $('cpTo').value === '@archive' ? o.textContent.split(' · ')[0].replace(/ \(not plugged in\)$/, '') : '';
}
function drawCopy() {
  const r = cpData; if (!r) return;
  const b = r.backup, drives = r.drives || [];
  // the choices: the archive, the sources from Setup, and the drives the helper sees
  const opt = function (v, label, on) { return '<option value="' + esc(v) + '"' + (on ? ' selected' : '') + '>' + esc(label) + '</option>'; };
  const from = cpWas.from !== null ? cpWas.from : ARCH;
  const to = cpWas.to !== null ? cpWas.to : (b ? b.drive : ARCH);
  // From: every drive and share the helper sees, and the ones copied from before (even unplugged); a card greyed: Ingest
  const plugged = {}; drives.forEach(function (x) { plugged[x.path] = x; });
  const known = {}; r.sources.forEach(function (x) { known[x.path] = x; });
  const arch = 'The archive (' + r.archive_name + ')' + (r.archive_free ? ' · ' + tb(r.archive_free) + ' free' : '');
  $('cpFrom').innerHTML = opt(ARCH, arch, from === ARCH) + (r.in_place ? '' :
    drives.map(function (x) { return opt(x.path, (known[x.path] ? known[x.path].label : x.name) + ' · ' + tb(x.total - x.free) + ' on it', from === x.path); }).join('') +
    r.sources.filter(function (x) { return !plugged[x.path]; }).map(function (x) { return opt(x.path, x.label + ' (not plugged in)', from === x.path); }).join('') +
    (r.cards || []).map(function (x) { return '<option disabled>' + esc(x.name) + ' (a card: use Ingest)</option>'; }).join(''));
  $('cpTo').innerHTML = opt(ARCH, arch, to === ARCH) +
    drives.map(function (x) { return opt(x.path, x.name + ' · ' + tb(x.free) + ' free', to === x.path); }).join('') +
    (b && !drives.some(function (x) { return x.path === b.drive; }) ? opt(b.drive, b.drive.split('/').pop() + ' (not plugged in)', to === b.drive) : '');
  const f = $('cpFrom').value, t = $('cpTo').value;
  const backup = f === ARCH && t !== ARCH, bring = f !== ARCH && t === ARCH, copy = f !== ARCH && t !== ARCH && f !== t;
  const fromName = (($('cpFrom').selectedOptions[0] || {}).textContent || '').split(' · ')[0];
  // the job these choices are: the archive's backup, or a copy of one drive onto another (one per pair)
  const job = backup ? (b && b.drive === t ? b : null) : copy ? (r.copies || []).find(function (c) { return c.from === f && c.drive === t; }) : null;
  $('cpOpts').hidden = !backup && !copy;
  $('cpOnce').textContent = copy ? 'Once, now' : 'Only when I press Back up now';
  const key = f + '|' + t;
  if ((backup || copy) && cpOptsFor !== key && document.activeElement !== $('cpFolder')) {
    // a new pair chosen: its saved choices, or the defaults (a copy: once, into "<drive> copy")
    cpOptsFor = key;
    $('cpFolder').value = job ? job.folder : copy ? fromName + ' copy' : 'Rushes backup';
    document.querySelector('[name=cpWhen][value="' + (job ? +job.nightly : copy ? 0 : 1) + '"]').checked = true;
  }
  const when = document.querySelector('[name=cpWhen]:checked').value;
  const same = job && job.folder === $('cpFolder').value.trim() && String(+job.nightly) === when;
  const name = ($('cpTo').selectedOptions[0] || {}).textContent || '';
  $('cpExplain').innerHTML = copy
    ? 'A copy of <b>' + esc(fromName) + '</b> on <b>' + esc(name.split(' · ')[0]) + '</b>, in the folder named here, keeping its layout. ' +
      'It only adds and checks: a file already there is never replaced, and nothing on either drive is deleted. ' +
      (when === '1' ? 'Every night from 10 pm only what is new is copied. ' : '') + 'This copy is not added to Search: it is not the archive.'
    : f !== ARCH && f === t ? 'From and To are the same drive.'
    : backup
    ? 'A second copy of the archive on <b>' + esc(name.split(' · ')[0]) + '</b>, in the folder named here, keeping its layout. Each night only what is new is copied; ' +
      'a file that is already there is checked, never replaced, and nothing on that drive is ever deleted (Recently Removed is left out). It runs after the night\'s copies, from 10 pm.'
    : bring ? 'Everything on <b>' + esc(cpFromName()) + '</b> into the archive, folder by folder, once. ' +
      'Press Continue to see its folders, then tick the ones to copy. Nothing is copied until you press Copy.'
    : r.in_place ? 'Your drives stay where they are, so nothing needs copying in: choose a drive under To, and the archive is backed up onto it.' +
      (drives.length ? '' : ' No other drive is plugged in right now: connect one and it appears under To within a minute.')
    : !drives.length ? 'No other drive is plugged in, so From and To only offer the archive. Connect a USB drive or a network share (a NAS) and it appears in both within a minute.'
    : 'Choose a drive under From to copy it in, or one under To to back the archive up onto it.';
  // room: what is on From against what is free on To, said before it starts (a warning: what is there already is skipped)
  const dest = drives.find(function (x) { return x.path === t; }), src = drives.find(function (x) { return x.path === f; });
  const need = copy && src ? src.total - src.free : backup && window.lastState && lastState.archive ? lastState.archive.bytes : 0;
  if ((backup || copy) && dest && need > dest.free)
    $('cpExplain').innerHTML += '<br><span class="bad">' + (copy ? esc(fromName) + ' has ' : 'The archive is ') + tb(need) + (copy ? ' on it' : '') +
      ', and ' + esc(dest.name) + ' has ' + tb(dest.free) + ' free: it would stop part-way.</span> What is already there is not copied again.';
  const go = $('cpGo');
  go.hidden = !((backup || copy) && !same) && !bring;
  go.textContent = bring ? 'Continue' : copy ? (job ? 'Save the change' : when === '1' ? 'Set the copy' : 'Copy now') : b ? 'Save the change' : 'Set up the backup';
  // the backup, and each drive copy, once set: how it went, and what can be done
  $('cpBackup').innerHTML = (b ? jobPanel(Object.assign({ id: 'archive', said: r.said, runs: r.runs, due: r.due }, b), r) : '') +
    (r.copies || []).map(function (c) { return jobPanel(c, r); }).join('');
  $('cpBackup').querySelectorAll('[data-now]').forEach(function (x) {
    x.onclick = function () { cpAsk('now', { id: x.dataset.now }, x, 'Copies what is new onto ' + x.dataset.to + ' now, after any copies already running. Deletes nothing.', x.textContent); };
  });
  $('cpBackup').querySelectorAll('[data-off]').forEach(function (x) {
    x.onclick = function () { cpAsk('off', { id: x.dataset.off }, x, 'No more runs. Nothing on ' + x.dataset.to + ' is deleted: the copy there stays as it is.', x.textContent); };
  });
  // bringing in: the folders of the source, ticked and copied (below), shown once there is something
  if (bring && !secs.length) {
    $('from').textContent = cpFromName() || 'the source';
    $('todo').innerHTML = '<div class="empty">' + (cpLooking === cpFromName() ? 'Looking inside ' + esc(cpLooking) + '… (about a minute)' : 'Press Continue, above, to see its folders.') + '</div>';
  }
  $('cpBring').hidden = !bring && !secs.length && !(lastLanded || []).length;
}
// One job's box: the archive's backup, or a drive copied onto another
function jobPanel(j, r) {
  const arch = j.id === 'archive', bs = j.said, runs = j.runs || [], to = j.drive.split('/').pop();
  const word = arch ? 'backup' : 'copy';
  const kinds = { 'backed-up': arch ? 'backed up' : 'copied', 'backup-interrupted': 'stopped part-way', 'backup-waiting': 'waited: a drive not plugged in', 'backup-refused': 'refused' };
  return '<div class="panel bk" style="margin-top:12px"><header><b>' + (arch ? 'Backup of the archive' : esc(j.from_name)) + ' onto ' + esc(to) + ' / ' + esc(j.folder) + '</b>' +
    '<span class="n">' + (j.nightly ? 'every night' : arch ? 'only when asked' : 'once') + '</span></header><div style="padding:14px">' +
    '<p style="margin:0 0 6px" class="' + (bs && bs.late ? 'bad' : '') + '">Last good ' + word + ': <b>' + esc(whenWords(bs && bs.at)) + '</b>' +
      (bs && bs.at ? ' · ' + bs.files.toLocaleString() + ' copied (' + tb(bs.bytes) + ')' + (bs.note ? ', ' + esc(bs.note) : '') : '') +
      (bs && bs.late ? ' — late: check the drives are plugged in and the helper running' : bs && bs.at ? ' ✓'
        : j.nightly ? ' — the first runs tonight, from 10 pm' : j.due ? '' : ' — press ' + (arch ? 'Back up now' : 'Copy again now')) + '</p>' +
    (j.due ? '<p class="note" style="margin:0 0 6px"><span class="spin" style="margin-right:6px"></span>' + (j.due.indexOf('now-') === 0 ? 'Asked: ' : 'Tonight\'s: ') +
      'the helper runs it once the copies before it are done' + (r.helper_fresh ? '' : ' — the helper is not running right now') + '.</p>' : '') +
    (runs.length ? '<details class="fold runs" style="margin:6px 0 10px"><summary>The last runs</summary>' + runs.map(function (x) {
      return '<div class="row"><span class="nm">' + esc(x.when) + ' · ' + esc(kinds[x.kind] || x.kind) +
        '<small>' + (x.files ? x.files.toLocaleString() + ' copied, ' + tb(x.bytes) : '') + (x.note ? (x.files ? ' · ' : '') + esc(x.note) : '') + '</small></span></div>'; }).join('') + '</details>' : '') +
    '<div class="btns"><button class="btn" type="button" data-now="' + esc(j.id) + '" data-to="' + esc(to) + '"' + (j.due ? ' disabled' : '') + '>' + (arch ? 'Back up now' : 'Copy again now') + '</button>' +
    '<button class="btn quiet" type="button" data-off="' + esc(j.id) + '" data-to="' + esc(to) + '">' + (arch ? 'Turn off' : 'Take off the list') + '</button></div></div></div>';
}
async function cpAsk(action, extra, btn, what, was) {
  was = String(was).replace(/^Sure\? /, '');
  if (!sure(btn, what, 'cp:' + action + ':' + (extra.id || ''))) return;
  btn.disabled = true; btn.textContent = 'asked…';
  try {
    const x = await (await fetch('backup.php', { method: 'POST', body: new URLSearchParams(Object.assign({ action: action }, extra)) })).json();
    if (x.error) throw new Error(x.error);
    $('cpSaid').textContent = x.said + ' ✓';
    btn.disabled = false; btn.textContent = was;
    if (action === 'off') { cpWas = { from: null, to: null }; cpOptsFor = ''; }
    loadCopy();
  } catch (e) { btn.disabled = false; btn.textContent = was; $('cpSaid').textContent = 'Did not happen: ' + e.message; }
}
['cpFrom', 'cpTo'].forEach(function (id) {
  $(id).onchange = function () {
    if ($('cpFrom').value === '@add') { location.href = '/setup.php#sources'; return; }
    cpWas = { from: $('cpFrom').value, to: $('cpTo').value }; $('cpSaid').textContent = ''; drawCopy();
  };
});
$('cpFolder').oninput = function () { drawCopy(); };
document.querySelectorAll('[name=cpWhen]').forEach(function (x) { x.onchange = drawCopy; });
$('cpGo').onclick = function () {
  const f = $('cpFrom').value, t = $('cpTo').value;
  if (f !== ARCH && t === ARCH) {
    const go = this;
    // a drive picked here becomes a source by itself (db/backup.php: only one the helper reported), then is listed
    (cpData.sources.some(function (x) { return x.path === f; }) ? Promise.resolve({}) :
      fetch('backup.php', { method: 'POST', body: new URLSearchParams({ action: 'source', drive: f }) }).then(function (x) { return x.json(); }))
    .then(function (x) {
      if (x.error) { $('cpSaid').textContent = 'Did not happen: ' + x.error; return; }
      // what is already on the list stays on it: listing adds, it never takes anything off
      const keep = new Set([...ticked, ...secs.filter(function (x) { return x.state === 'queued'; }).map(function (x) { return x.path; })]);
      cpLooking = cpFromName();
      sendQueue([...keep], f, go, 'Continue');
      $('cpSaid').textContent = (x.said ? x.said + ' ✓ ' : '') + 'Looking inside ' + cpLooking + '; its folders appear below within a minute.';
      loadCopy();
    });
    return;
  }
  const nightly = document.querySelector('[name=cpWhen]:checked').value, folder = $('cpFolder').value.trim();
  const onto = (($('cpTo').selectedOptions[0] || {}).textContent || '').split(' · ')[0];
  if (f !== ARCH) {          // a drive onto another
    const what = (($('cpFrom').selectedOptions[0] || {}).textContent || '').split(' · ')[0];
    cpAsk('save', { from: f, drive: t, folder: folder, nightly: nightly }, this,
      what + ' is copied onto ' + onto + ' / ' + (folder || what + ' copy') + (nightly === '1' ? ', every night from 10 pm.' : ', starting within a minute.') +
      ' Only adds: nothing on either drive is replaced or deleted.', this.textContent);
    return;
  }
  cpAsk('save', { drive: t, folder: folder, nightly: nightly }, this,
    'The archive is copied onto ' + onto + ' / ' + (folder || 'Rushes backup') +
    (nightly === '1' ? ', every night from 10 pm.' : ', when you press Back up now.') + ' Deletes nothing there.', this.textContent);
};

// ── tiles: two that are always true, then only what is ─────────────────────
// Overview → Drives: an icon for what each is (the archive, a NAS share, a drive, a card), how full,
// and what Rushes does with it, so plugging one in shows at once
const DRV_ICON = <?= json_encode(['archive' => icon('archive', 1.6), 'nas' => icon('server', 1.6), 'drive' => icon('drive', 1.6), 'card' => icon('card', 1.6)]) ?>;
const DRV_KIND = { archive: 'the archive', nas: 'network share (NAS)', drive: 'drive plugged in', card: 'card' };
function drvMeter(x) {
  if (!x.total) return '<div class="sp">' + (x.kind === 'nas' ? 'size not reported by the share' : 'size not reported') + '</div>';
  const used = Math.round((x.total - x.free) / x.total * 100);
  return '<div class="meter"><i class="' + (used >= 90 ? 'full' : used >= 80 ? 'hot' : '') + '" style="width:' + used + '%"></i></div>' +
    '<div class="sp">' + tb(x.free) + ' free of ' + tb(x.total) + (used >= 80 ? ' · ' + used + '% full' : '') + '</div>';
}
// ── one drive: the three levels (db/drive.php) ─────────────────────────────
// Searchable: in Search by name. Playable: its videos play in Search (a preview was made, or it plays
// as it is). Described: the AI wrote down what is in them. A percentage is rounded down: 100% means all.
const pct = function (a, b) { return b ? (a >= b ? 100 : Math.floor(a / b * 100)) : 0; };
function lvRow(lab, val, p) {
  return '<div class="lv"><span>' + lab + '</span><i><b style="width:' + p + '%"></b></i><em>' + val + '</em></div>';
}
function drvLevels(x) {
  const L = x.levels; if (!L) return '';
  return '<div class="lvs">' +
    lvRow('Searchable', x.now ? 'listing…' : L.n ? '✓' : '—', x.now ? 50 : L.n ? 100 : 0) +
    lvRow('Playable', L.v ? pct(L.p, L.v) + '%' : 'no videos', pct(L.p, L.v)) +
    lvRow('Described', L.v ? pct(L.d, L.v) + '%' : 'no videos', pct(L.d, L.v)) + '</div>';
}
function openDrive(x) {
  if (!x) return;
  drv = Object.assign({}, x, { in: '' }); show('drive'); window.scrollTo(0, 0);
}
async function loadDrive(fresh) {
  if (!drv) return;
  const arch = drv.kind === 'archive', n = function (v) { return (+v || 0).toLocaleString(); };
  $('dvHead').textContent = drv.name; $('title').textContent = drv.name;
  $('dvSub').textContent = DRV_KIND[drv.kind] + (drv.total ? ' · ' + tb(drv.free) + ' free of ' + tb(drv.total) : '') +
    (drv.roles && drv.roles.length ? ' · ' + drv.roles.join(' · ') : '');
  $('dvBack').onclick = function (e) { e.preventDefault(); drv = null; show('overview'); };
  $('dvFolders').innerHTML = '<div class="note">Counting…</div>';
  let r;
  try { r = await (await fetch('drive.php?path=' + encodeURIComponent(drv.root) + '&in=' + encodeURIComponent(drv.in) + (fresh ? '&fresh=1' : '') + '&t=' + Date.now())).json(); }
  catch (e) { $('dvFolders').innerHTML = '<div class="note bad">Could not count: ' + esc(e.message) + '</div>'; return; }
  if (r.error) { $('dvFolders').innerHTML = '<div class="note bad">' + esc(r.error) + '</div>'; return; }
  const T = r.total, top = drv.in === '';

  // the three levels, for the whole drive (or the folder open), each with what moves it on
  const lv = function (lab, big, p, say, btn) {
    return '<div><div class="lab">' + lab + '</div><div class="big">' + big + '</div><div class="lv"><i><b style="width:' + p + '%"></b></i></div>' +
      '<p>' + say + '</p>' + (btn || '') + '</div>';
  };
  const where = top ? drv.name : drv.in;
  const keptHere = arch ? '' : ' Previews for a drive kept where it is come in a next version: Rushes will first ask where to keep them, and show how much room and time they take.';
  $('dvLevels').innerHTML =
    lv('Searchable by name', drv.now && !T.n ? 'Listing…' : n(T.n) + ' files', drv.now ? 50 : T.n ? 100 : 0,
      drv.now ? esc(drv.now) + '. Files show up in Search as they are listed.' : tb(T.b) + ' · found in Search by their names and folders.' +
        (arch ? '' : ' Listed again every night, while it is plugged in.')) +
    lv('Playable', T.v ? pct(T.p, T.v) + '%' : 'No videos', pct(T.p, T.v),
      T.v ? n(T.p) + ' of ' + n(T.v) + ' videos play in Search: a light preview of each was made, or it plays as it is.' + keptHere : 'Nothing here to make previews of.',
      arch && !top && (T.v > T.p || T.v > T.d) ? '<button class="btn quiet" data-dv="prep" data-f="' + esc(drv.in) + '">Make previews and descriptions</button>'
        : arch && T.v > T.p ? '<p>Pick folders below: each goes on the list, previews first, then descriptions.</p>' : '') +
    lv('Described', T.v ? pct(T.d, T.v) + '%' : 'No videos', pct(T.d, T.v),
      T.v ? n(T.d) + ' of ' + n(T.v) + ' videos described by the AI: every shot in words, and everything said, so Search finds what is in them. It needs the previews first.' : 'Nothing here to describe.');

  // the archive: what the copies brought in, not on the shelf yet
  const w = T.wait;
  $('dvAt').innerHTML = 'counted ' + esc(whenWords(r.at)) + ' · <a href="#" data-dv="count">count again</a>';
  $('dvCrumbs').innerHTML = top ? '' : '<a href="#" data-in="">' + esc(drv.name) + '</a>' + drv.in.split('/').map(function (part, i, all) {
    return ' / ' + (i === all.length - 1 ? '<b>' + esc(part) + '</b>' : '<a href="#" data-in="' + esc(all.slice(0, i + 1).join('/')) + '">' + esc(part) + '</a>');
  }).join('');
  const rows = r.folders.filter(function (f) { return f.name !== ''; }), loose = r.folders.find(function (f) { return f.name === ''; });
  $('dvFolders').innerHTML = (w && w.n ? '<div class="banner warn" style="margin:0 0 10px"><div class="txt"><b>' + n(w.n) + ' files waiting to be filed · ' + tb(w.b) +
      '</b><br>Copied in, not on the archive\'s shelf yet. Tidy up shows where each folder goes, and moves nothing until you say so.</div>' +
      '<a class="btn" href="/structure.php">Tidy up</a></div>' : '') +
    (!rows.length ? '<div class="note">' + (loose ? n(loose.n) + ' files, no folders.' : 'Nothing in Search here.') + '</div>' :
    '<table class="prep"><thead><tr><th>Folder</th><th class="n">Files</th><th class="n">Size</th><th class="n">Playable</th><th class="n">Described</th><th></th></tr></thead><tbody>' +
    rows.map(function (f) {
      const path = (drv.in ? drv.in + '/' : '') + f.name;
      const bar = function (a) { return f.v ? pct(a, f.v) + '%<span class="pc"><b style="width:' + pct(a, f.v) + '%"></b></span>' : '<span class="dim">—</span>'; };
      return '<tr' + (f.is === 'waiting' ? ' class="wait"' : '') + '><td><a href="#" data-in="' + esc(path) + '">' + esc(f.name) + '</a>' +
          (f.is === 'waiting' ? ' <small>waiting to be filed</small>' : '') + '</td>' +
        '<td class="n">' + n(f.n) + '</td><td class="n">' + tb(f.b) + '</td><td class="n">' + bar(f.p) + '</td><td class="n">' + bar(f.d) + '</td>' +
        '<td class="n">' + (arch && f.v && (f.p < f.v || f.d < f.v) ? '<button class="lnk" data-dv="prep" data-f="' + esc(path) + '">Make previews and descriptions</button>' : '') + '</td></tr>';
    }).join('') + '</tbody></table>' +
    (loose ? '<p class="note" style="margin:8px 0 0">And ' + n(loose.n) + ' files loose in ' + esc(where) + '.</p>' : ''));

  // what can be done on this drive: each opens the page that does it, set to this drive
  const isSrc = drv.roles && (drv.roles.indexOf('In Search') >= 0 || drv.roles.indexOf('To copy from') >= 0);
  $('dvActs').innerHTML =
    '<button class="btn quiet" data-dv="dup">Find duplicates on this drive</button>' +
    (arch ? '<button class="btn quiet" data-dv="cache">Clear editing cache</button>' : '') +
    '<button class="btn quiet" data-dv="copy">' + (arch ? 'Back up the archive…' : 'Copy this drive…') + '</button>' +
    (arch ? '<button class="btn quiet" data-dv="describe">Previews and descriptions, in order</button>' : '') +
    (isSrc && !arch ? '<button class="btn quiet" data-dv="unsource">Remove from Rushes</button>' : '');

  document.querySelectorAll('#pane-drive [data-in]').forEach(function (a) {
    a.onclick = function (e) { e.preventDefault(); drv.in = a.dataset.in; loadDrive(); };
  });
  document.querySelectorAll('#pane-drive [data-dv]').forEach(function (b) {
    b.onclick = async function (e) {
      const k = b.dataset.dv; if (e) e.preventDefault();
      if (k === 'count') { loadDrive(true); return; }
      if (k === 'dup') { show('duplicates'); $('dupDrive').value = arch ? '' : drv.source; dupList = null; loadDup(); loadRemoved(); return; }
      if (k === 'cache') { show('cache'); return; }
      if (k === 'describe') { show('describe'); return; }
      if (k === 'copy') { cpWas = { from: arch ? '@archive' : drv.path, to: null }; show('transfers'); loadCopy(); return; }
      if (k === 'unsource') {
        if (!sure(b, 'Rushes stops listing ' + drv.name + ': its files leave Search. Nothing on the drive is touched.', 'unsrc:' + drv.path)) return;
        b.disabled = true; b.textContent = 'removing…';
        try {
          const x = await (await fetch('backup.php', { method: 'POST', body: new URLSearchParams({ action: 'unsource', drive: drv.path }) })).json();
          if (x.error) throw new Error(x.error);
          $('dvSaid').textContent = 'Removed ✓ ' + drv.name + ' leaves Search within a minute. Nothing on it was touched.'; b.textContent = 'Removed ✓';
        } catch (e) { b.disabled = false; b.textContent = 'Remove from Rushes'; oops('Did not happen: ' + e.message); }
        return;
      }
      if (k === 'prep') {
        // the same list as Describe: previews first, then descriptions, folders one at a time, top to bottom
        const f = b.dataset.f;
        if (!f) { show('describe'); return; }
        b.disabled = true; const was = b.textContent; b.textContent = 'adding…';
        try {
          const x = await (await fetch('analyze.php', { method: 'POST', body: new URLSearchParams({ action: 'prepare', path: f }) })).json();
          if (x.error) throw new Error(x.error);
          b.textContent = 'On the list ✓';
          $('dvSaid').innerHTML = 'Added ✓ <b>' + esc(f) + '</b> is on the list: previews first, then descriptions. Folders run one at a time, top to bottom; ' +
            '<a href="#" data-go-describe="1">Describe</a> shows the list and changes the order.';
          const g = $('dvSaid').querySelector('[data-go-describe]'); if (g) g.onclick = function (e) { e.preventDefault(); show('describe'); };
        } catch (e) { b.disabled = false; b.textContent = was; $('dvSaid').textContent = 'Did not happen: ' + e.message; }
      }
    };
  });
}
// Make this the archive (HOW-IT-WORKS.md → Changing the archive): one question, on the drive itself: what the
// archive now becomes. Kept open across redraws (mkOpen); asked twice before it happens.
let mkOpen = '', mkOld = '', mkSaid = '';
function mkPanel(x, oldName) {
  if (mkOpen !== x.path) return '';
  const o = function (v, t, sub) {
    return '<label><input type="radio" name="mkOld" value="' + v + '"' + (mkOld === v ? ' checked' : '') + '><span>' + t + '<br><small>' + sub + '</small></span></label>'; };
  return '<div class="mk"><b>' + esc(x.name) + ' becomes the archive.</b> What does ' + esc(oldName) + ' become?' +
    o('read', 'A drive Rushes still reads', 'Its files stay in Search, where they are. Nothing on it changes.') +
    o('backup', 'Where the archive is backed up', 'Copying → Back up copies the archive onto it, when you start it. What is on it now stays.') +
    o('forget', 'Nothing: forget it', 'Its files leave Search. Nothing on it is touched; add it again any time.') +
    '<div class="btns"><button class="btn" data-mkgo="' + esc(x.path) + '" data-name="' + esc(x.name) + '"' + (mkOld ? '' : ' disabled') + '>Make ' + esc(x.name) + ' the archive</button>' +
    '<button class="lnk" data-mkno="1">Cancel</button></div>' +
    '<div class="note" style="margin-top:6px">Nothing is moved, copied or deleted. Every part of Rushes switches to it at once, and it is listed straight away.</div></div>';
}
function drawDrives(d) {
  const ins = d.drives_in || [], other = d.drives_other || [];
  const inPlace = !!d.in_place;
  const archNow = (ins.find(function (x) { return x.kind === 'archive'; }) || {}).name || d.archive_name || 'the archive now';
  const canMk = d.can_make_archive !== false;
  if (drv) { const nx = ins.find(function (x) { return x.path === drv.path; }); if (nx) drv = Object.assign(nx, { in: drv.in }); }
  $('drivesNow').innerHTML = !ins.length && !other.length ? '' :
    (mkSaid ? '<div class="banner ok" style="margin:10px 14px 0"><div class="txt">' + esc(mkSaid) + '</div></div>' : '') +
    '<header><b>Drives in Rushes</b><span class="n">' + ins.length + (ins.length === 1 ? ' drive' : ' drives') +
      (d.helper_seen ? ' · looked ' + new Date(d.helper_seen * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '') + '</span></header>' +
    '<div class="drvs">' + ins.map(function (x) {
      const own = x.roles.indexOf('In Search') >= 0 || x.roles.indexOf('To copy from') >= 0;
      return '<div class="drv-c' + (x.kind === 'archive' ? ' arch' : '') + (x.levels ? ' open" data-open="' + esc(x.path) + '" title="Open ' + esc(x.name) : '') + '"><div class="top">' + DRV_ICON[x.kind] +
        '<div style="min-width:0"><b>' + esc(x.name) + '</b><div class="kind">' + DRV_KIND[x.kind] + '</div></div></div>' +
        drvMeter(x) +
        (x.files != null && (x.kind === 'archive' || x.roles.indexOf('In Search') >= 0)
          ? '<div class="inS">' + (x.files ? x.files.toLocaleString() + ' files in Search · ' + tb(x.bytes) : x.now ? '' : 'Not in Search yet') + '</div>' : '') +
        '<div class="roles">' + x.roles.map(function (r) { return '<span>' + esc(r) + '</span>'; }).join('') + '</div>' +
        (x.now ? '<div class="lnow"><span class="spin" style="margin-right:6px"></span>' + esc(x.now) + '</div>' : '') +
        drvLevels(x) +
        (x.levels && x.levels.wait && x.levels.wait.n ? '<div class="more"><span class="bad" style="color:var(--warn)">' + x.levels.wait.n.toLocaleString() + ' files waiting to be filed</span></div>' : '') +
        '<div class="acts">' + (own ? '<button class="lnk" data-unsource="' + esc(x.path) + '" data-name="' + esc(x.name) + '">Remove from Rushes</button>' : '') +
          (x.roles.indexOf('To copy from') >= 0 ? ' · <button class="lnk" data-how="in_place" data-src="' + esc(x.path) + '">Search it where it is</button>' : '') +
          (canMk && x.kind !== 'archive' && x.kind !== 'card' && mkOpen !== x.path ? (own ? ' · ' : '') + '<button class="lnk" data-mk="' + esc(x.path) + '">Make this the archive…</button>' : '') +
        '</div>' + mkPanel(x, archNow) +
        '</div>';
    }).join('') + '</div>' +
    (other.length ? '<div class="also"><div class="note" style="margin:4px 0 2px">Also on this Mac: Rushes sees them, and does nothing with them.</div>' +
      other.map(function (x) {
        return '<div class="row">' + DRV_ICON[x.kind] + '<span class="nm">' + esc(x.name) +
          '<small>' + DRV_KIND[x.kind] + (x.total ? ' · ' + tb(x.total - x.free) + ' on it' : '') + '</small></span>' +
          // asked for each drive, not once for all: kept where it is, copied in, or the archive itself
          (x.kind === 'card' ? '<span class="note">use Ingest</span>'
            : '<button class="btn quiet" data-addsrc="' + esc(x.path) + '" data-name="' + esc(x.name) + '" data-size="' + (x.total ? esc(tb(x.total - x.free)) : '') + '">Search it where it is</button>' +
              '<button class="btn quiet" data-copyfrom="' + esc(x.path) + '">Copy into the archive…</button>' +
              (canMk && mkOpen !== x.path ? '<button class="btn quiet" data-mk="' + esc(x.path) + '">Make it the archive…</button>' : '')) +
          mkPanel(x, archNow) + '</div>';
      }).join('') + '</div>' : '');
  $('drivesNow').hidden = pane !== 'overview' || (!ins.length && !other.length);
  $('drivesNow').querySelectorAll('[data-addsrc]').forEach(function (b) {
    b.onclick = async function () {
      if (!sure(b, 'Its files become searchable by name. Listing ' + (b.dataset.size ? b.dataset.size + ' ' : '') + 'takes a while on a big share; nothing on it is moved or changed.', 'add:' + b.dataset.addsrc)) return;
      b.disabled = true; b.textContent = 'adding…';
      try {
        const x = await (await fetch('backup.php', { method: 'POST', body: new URLSearchParams({ action: 'source', drive: b.dataset.addsrc, how: 'in_place' }) })).json();
        if (x.error) throw new Error(x.error);
        b.textContent = 'Added ✓ listing within a minute';
      } catch (e) { b.disabled = false; b.textContent = 'Search it where it is'; oops('Did not happen: ' + e.message); }
    };
  });
  $('drivesNow').querySelectorAll('[data-how]').forEach(function (b) {
    b.onclick = async function () {
      if (!sure(b, 'Its files become searchable by name, where they are. Nothing on it is moved or changed.', 'how:' + b.dataset.src)) return;
      b.disabled = true; b.textContent = 'asking…';
      try {
        const x = await (await fetch('backup.php', { method: 'POST', body: new URLSearchParams({ action: 'source', drive: b.dataset.src, how: b.dataset.how }) })).json();
        if (x.error) throw new Error(x.error);
        b.textContent = 'Done ✓ listing within a minute';
      } catch (e) { b.disabled = false; b.textContent = 'Search it where it is'; oops('Did not happen: ' + e.message); }
    };
  });
  $('drivesNow').querySelectorAll('[data-mk]').forEach(function (b) {
    b.onclick = function () { mkOpen = b.dataset.mk; mkOld = ''; drawDrives(d); };
  });
  $('drivesNow').querySelectorAll('[data-mkno]').forEach(function (b) {
    b.onclick = function () { mkOpen = ''; mkOld = ''; drawDrives(d); };
  });
  $('drivesNow').querySelectorAll('[name=mkOld]').forEach(function (r) {
    r.onchange = function () { mkOld = r.value; const g = $('drivesNow').querySelector('[data-mkgo]'); if (g) g.disabled = false; };
  });
  $('drivesNow').querySelectorAll('[data-mkgo]').forEach(function (b) {
    b.onclick = async function () {
      if (!mkOld) return;
      if (!sure(b, 'Every part of Rushes switches to ' + b.dataset.name + ' now. Nothing on any drive is moved or deleted.', 'mk:' + b.dataset.mkgo + ':' + mkOld)) return;
      b.disabled = true; b.textContent = 'Switching…';
      try {
        const x = await (await fetch('backup.php', { method: 'POST', body: new URLSearchParams({ action: 'make_archive', drive: b.dataset.mkgo, old: mkOld }) })).json();
        if (x.error) throw new Error(x.error);
        mkOpen = ''; mkOld = '';
        mkSaid = 'Running ✓ · ' + x.said + '.';
        drawDrives(d); setTimeout(load, 1500);
      } catch (e) { b.disabled = false; b.textContent = 'Make ' + b.dataset.name + ' the archive'; oops('Did not happen: ' + e.message); }
    };
  });
  $('drivesNow').querySelectorAll('[data-unsource]').forEach(function (b) {
    b.onclick = async function () {
      if (!sure(b, 'Rushes stops listing ' + b.dataset.name + ': its files leave Search. Nothing on the drive is touched.', 'unsrc:' + b.dataset.unsource)) return;
      b.disabled = true; b.textContent = 'removing…';
      try {
        const x = await (await fetch('backup.php', { method: 'POST', body: new URLSearchParams({ action: 'unsource', drive: b.dataset.unsource }) })).json();
        if (x.error) throw new Error(x.error);
        b.textContent = 'Removed ✓';
      } catch (e) { b.disabled = false; b.textContent = 'Remove from Rushes'; oops('Did not happen: ' + e.message); }
    };
  });
  $('drivesNow').querySelectorAll('[data-open]').forEach(function (c) {
    c.onclick = function (e) {
      if (e.target.closest('button, .mk')) return;            // its own buttons (Remove from Rushes) and questions are not "open"
      openDrive((d.drives_in || []).find(function (x) { return x.path === c.dataset.open; }));
    };
  });
  $('drivesNow').querySelectorAll('[data-copyfrom]').forEach(function (b) {
    b.onclick = function () { cpWas = { from: b.dataset.copyfrom, to: '@archive' }; show('transfers'); loadCopy(); };
  });
}
function drawTiles(d) {
  drawDrives(d);
  const t = [];
  // what is happening, in one line
  $('ovNow').innerHTML = d.running ? '<span class="spin" style="margin-right:6px"></span><b>' + esc(window.nowSaid(d)) + '</b>'
    : 'Nothing running' + (d.backup && d.backup.at ? ' · last backup ' + esc(whenWords(d.backup.at)) + (d.backup.late ? ' (late)' : ' ✓') : '') + '.';
  // the headline: what is in Search, and on how many drives; why it has not grown yet, while a drive is listed
  const ins = d.drives_in || [], listing = ins.filter(function (x) { return x.now; });
  const searched = ins.filter(function (x) { return x.kind === 'archive' || x.roles.indexOf('In Search') >= 0; }).length || 1;
  t.push('<div class="tile"><div class="lab">In Search</div>' +
    '<div class="big">' + d.archive.files.toLocaleString() + ' files</div>' +
    '<div class="sub">' + tb(d.archive.bytes) + ', on ' + searched + (searched === 1 ? ' drive' : ' drives') +
      (listing.length ? ' · ' + esc(listing.map(function (x) { return x.name; }).join(', ')) + ' still being listed…' : '') + '</div></div>');

  // How many copies: the archive is one; the place each file came from, still
  // holding it, is a second. Shown once the helper has looked (weekly).
  const cp = d.copies;
  if (cp) {
    const all = cp.twice[1] + cp.lost[1] + cp.never[1], one = cp.lost[1] + cp.never[1];
    t.push('<div class="tile' + (cp.lost[0] ? ' warn' : '') + '" title="' + esc('Only in the archive, by department: ' +
        Object.entries(cp.depts || {}).map(function (e) { return e[0] + ' ' + tb(e[1]); }).join(', ')) + '">' +
      '<div class="lab">Kept twice</div>' +
      '<div class="big">' + (all ? Math.round(cp.twice[1] / all * 100) : 0) + '%</div>' +
      '<div class="sub">' + tb(one) + ' only in the archive · looked ' +
        new Date(cp.at * 1000).toLocaleDateString([], {day: 'numeric', month: 'short'}) + '</div></div>');
  }

  // The backup (Manage → Copying), once one is set: when the last good one was, red when late
  const bk = d.backup;
  if (bk) {
    t.push('<button class="tile' + (bk.late ? ' bad' : '') + '" data-go-copy="1"><div class="lab">Backed up</div>' +
      '<div class="big">' + (bk.at ? dayWord(bk.at) : 'Not yet') + '</div>' +
      '<div class="sub">onto ' + esc(bk.drive) + (bk.late ? ' · late' : bk.at ? ' ✓' : '') + '</div></button>');
  }

  // Worth a look: only when there is something, one line each, with the page that handles it
  const ws = (d.conditions || []).map(function (c, i) { return [c, i]; }).filter(function (x) { return x[0].tile; });
  const arch = ins.find(function (x) { return x.kind === 'archive'; });
  const full = arch && arch.total ? Math.round((arch.total - arch.free) / arch.total * 100) : 0;
  $('worth').innerHTML = !ws.length && full < 85 ? '' : '<header><b>Worth a look</b></header><div style="padding:4px 14px 10px">' +
    (full >= 85 ? '<div class="row"><span class="nm"><span class="' + (full >= 90 ? 'bad' : '') + '">' + esc(arch.name) + ' is ' + full + '% full</span>' +
      '<small>' + tb(arch.free) + ' free. Duplicates and Cache show what could go.</small></span></div>' : '') +
    ws.map(function (x) {
      const c = x[0];
      return '<div class="row"><span class="nm"><span class="' + (c.level === 'bad' ? 'bad' : '') + '">' + esc(c.tile.lab + ': ' + c.tile.big) + '</span>' +
        '<small>' + esc(c.tile.sub) + '</small></span>' + (c.act ? '<button class="btn quiet" data-c="' + x[1] + '">' + esc(c.act[1]) + '</button>' : '') + '</div>';
    }).join('') + '</div>';
  $('worth').hidden = pane !== 'overview' || !$('worth').innerHTML;
  $('worth').querySelectorAll('[data-c]').forEach(function (b) {
    b.onclick = function () { const c = d.conditions[+b.dataset.c]; if (c && c.act) act(c.act[0], b); };
  });

  $('tiles').innerHTML = t.join('');
  $('tiles').querySelectorAll('[data-go-copy]').forEach(function (b) { b.onclick = function () { show('transfers'); }; });
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
// Each button asks once more on the button itself ("Sure?"), then says what
// happened. The answer is read back from the helper, never assumed.
let armed = { what: '', until: 0 }, said = { text: '', until: 0 };
function drawHelper(d) {
  const h = d.helper || {}, el = $('hctl');
  el.hidden = pane !== 'overview';   // every on/off switch in one place: Overview, the dashboard
  if (el.hidden) return;
  const how = h.how === 'service' ? 'runs in the background' : h.how === 'window' ? 'runs in a Terminal window' : '';
  const seen = h.seen ? (h.fresh ? 'seen ' + h.seen_ago : 'not heard from since ' + h.seen_ago) : 'not started yet';
  const updating = h.fresh && h.ver && h.current && h.ver !== h.current ? ' · updating itself to the new version' : '';
  const cur = d.transfer && d.transfer.phase !== 'done' ? d.transfer.source : '';
  // Something is wrong only when a folder stopped and the helper has not been
  // back on it for three minutes (a retry is normally seconds away).
  // (Just resumed: the helper's own status still says paused for a few seconds — not stuck.)
  const stuck = cur && h.fresh && !h.paused && !(d.copy && d.copy.phase === 'paused') && d.transfer.phase === 'interrupted' && !liveFor(d.transfer, d.copy)
    && Date.now() / 1000 - d.transfer.updated > 180;
  const off = d.runner && d.runner.stopped;   // the runner on the server: STOP in the web folder
  // Switches, as in Rushes Helper's own window: on means it runs; each acts at once and says what happened.
  const sw = function (on, ids, title, sub, dis) {
    return '<div class="row"><div class="t">' + esc(title) + '<small>' + esc(sub) + '</small></div>' +
      '<button class="sw' + (on ? ' on' : '') + '" role="switch" aria-checked="' + on + '" aria-label="' + esc(title) +
      '" title="' + (on ? 'Turn off' : 'Turn on') + '" data-h="' + (on ? ids[1] : ids[0]) + '"' + (dis ? ' disabled' : '') + '></button></div>';
  };
  const doing = h.describe && h.describe.phase === 'analysing' && !h.describe_paused
    ? ' · describing ' + (h.describe.label || '') + (h.describe.of ? ' (' + h.describe.n + ' of ' + h.describe.of + ')' : '') : '';
  const late = h.drives_late ? (h.drive_stuck ? h.drive_stuck.split('/').pop() + ' is not answering (' + h.drive_stuck + ')' : 'a connected drive is not answering') +
    ', so cards plugged in now may not show in Ingest — eject it in Finder, or connect it again' : '';
  const ask = h.fresh ? '' : ' Switches work once it is heard from again.';
  const now = Date.now();
  el.innerHTML = (h.label
      ? '<div class="hgrp">Rushes Helper on ' + esc(h.label) + '</div>' +
        '<div class="row"><span class="dot ' + (h.fresh ? (h.paused ? '' : 'ok') : 'off') + '"></span><div class="t">' +
          esc([how, seen].filter(Boolean).join(' · ') + updating + doing) + (late ? '<small>' + esc(late) + '</small>' : '') + '</div>' +
          '<button class="btn quiet" data-h="nudge">Try again now</button></div>' +
        sw(!h.paused, ['resume', 'pause'], 'Copy footage', 'Off pauses copying at its next safe point; nothing is lost.' + ask, !h.fresh) +
        sw(!h.describe_paused, ['describe-resume', 'describe-pause'], 'Describe footage', 'Off pauses describing; files already described are kept. Copying is not affected.' + ask, !h.fresh) +
        sw(!h.check_paused, ['check-resume', 'check-pause'], 'Check copies', 'When there is nothing to copy, copies are read again against their fingerprints. Off pauses it; where it got to is kept.' + ask, !h.fresh) +
        sw(!h.no_reconnect, ['reconnect-on', 'reconnect-off'], 'Reconnect network drives by itself', 'When a drive drops, the helper connects it again once the server answers. Off: you connect drives in Finder.', false)
      : '') +
    '<div class="hgrp" style="margin-top:10px">' + (ON_MAC ? 'On this Mac' : 'On the server') + '</div>' +
    sw(!off, ['start-runner', 'stop-runner'], ON_MAC ? 'Rushes\' minute\'s work' : 'Rushes on the server', off
      ? 'Off: nothing runs ' + WHERE + ' (no jobs, no checks, nothing touching the archive) until you turn it on. These pages keep working.'
      : 'Its jobs and checks, once a minute. Off stops all of it, for a disk rebuild or repairs, until you turn it on. These pages keep working.', false) +
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
      if (what === 'skip' && (armed.what !== what || Date.now() > armed.until)) { armed = { what: what, until: Date.now() + 5000 }; drawHelper(d); return; }
      armed = { what: '', until: 0 }; b.disabled = true; if (!b.classList.contains('sw')) b.textContent = 'Asking…';
      const body = new URLSearchParams({ action: what }); if (what === 'skip') body.set('path', cur);
      try {
        const r = await (await fetch('helper.php', { method: 'POST', body: body })).json();
        said = { until: Date.now() + 8000, text: r.error ? 'Did not happen: ' + r.error
          : { pause: 'Paused ✓ What is running stops at its next safe point; nothing new starts until Resume.',
              resume: 'Resumed ✓ It carries on within a few seconds.',
              nudge: 'Asked ✓ It stops waiting and looks again now.',
              skip: 'Skipped ✓ That folder is out of this transfer. Tick it again in Transfers to bring it back.',
              'describe-pause': 'Describing paused ✓ It stops at its next safe point; files already described are kept. Copying carries on.',
              'describe-resume': 'Describing resumed ✓ It carries on with the next file. Copying is not affected.',
              'reconnect-off': 'Off ✓ The helper no longer connects dropped shares by itself (no more "problem connecting" windows). Connect them in Finder; copying carries on once they are back.',
              'reconnect-on': 'On ✓ The helper connects dropped shares again by itself, only when the server answers.',
              'check-pause': 'Checking paused ✓ It stops after the file it is reading; where it got to is kept. Copying is not affected.',
              'check-resume': 'Checking resumed ✓ It carries on from the same file whenever there is nothing to copy.',
              'stop-runner': 'Stopped ✓ Nothing runs ' + WHERE + ' until Start. What it is in the middle of finishes; these pages keep working.',
              'start-runner': 'Started ✓ The minute\'s work ' + WHERE + ' checks in within a minute.' }[what] };
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
  // A Duplicates or Cache job that has just finished: what it found or moved, at once
  const was = lastRunning; lastRunning = d.running || '';
  if (was && !lastRunning && (pane === 'duplicates' || pane === 'cache')) {
    if (pane === 'duplicates') loadDup(); else loadCache();
    loadRemoved();
  } else if (pane === 'duplicates' && was !== lastRunning) drawDup();

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
  window.lastState = d; drawHelper(d);
  drawDescribeTools(d.helper);
  drawProxies(d.proxies);
  drawProxyTest(d);

  $('repeatRows').innerHTML = (d.repeats || []).map(function (r) {
    const ago = r[2] ? Math.round((Date.now() / 1000 - r[2]) / 60) : null;
    return '<tr><td>' + esc(r[0]) + '</td><td class="muted">' + esc(r[1]) + '</td><td>' +
      (ago === null ? 'not yet' : ago < 1 ? 'just now' : ago < 120 ? ago + ' min ago' : Math.round(ago / 60) + ' h ago') + '</td><td>' +
      (r[3] === 'ok' ? '<span class="ok">✓</span>' : r[3].startsWith('asked') ? '<span class="busy">' + esc(r[3]) + '</span>' : '<span class="bad">' + esc(r[3]) + '</span>') +
      (r[4] ? ' <button class="btn quiet" data-rep="' + esc(r[4][0]) + '">' + esc(r[4][1]) + '</button>' : '') + '</td></tr>';
  }).join('');
  $('repeatRows').querySelectorAll('[data-rep]').forEach(function (b) {
    b.onclick = async function () {
      b.disabled = true; b.textContent = 'Asking …';
      try {
        const r = await (await fetch('helper.php', { method: 'POST', body: new URLSearchParams({ action: b.dataset.rep }) })).json();
        b.textContent = r.error ? 'Did not happen: ' + r.error
          : r.stopped === true ? 'Stopped ✓ Nothing runs ' + WHERE + ' until Start'
          : r.stopped === false ? 'Started ✓ The runner checks in within a minute'
          : 'Asked ✓ The runner looks within a minute';
        if (r.stopped !== undefined) setTimeout(load, 2500);
      } catch (e) { b.textContent = 'The archive did not answer'; }
    };
  });

  // Cards carry the detail the tiles cannot. Only what is true, in order.
  $('cards').innerHTML = (d.conditions || []).map(function (c, i) {
    if (!c.title) return '';   // a tile on its own (the cache): nothing to say twice
    return '<div class="banner ' + esc(c.level === 'good' ? 'ok' : c.level) + '">' +
      '<div class="txt"><b>' + esc(c.title) + '</b>' + esc(c.body) +
      (c.help ? '<div class="code block">' + esc(c.help) + '</div>' : '') + '</div>' +
      (c.act ? '<button class="btn" data-a="' + i + '">' + esc(c.act[1]) + '</button>' : '') +
      '</div>';
  }).join('');
  $('cards').querySelectorAll('[data-a]').forEach(function (b) {
    b.onclick = function () { act(d.conditions[+b.dataset.a].act[0], b); };
  });

  // Activity: what happened and who did it (activity.php). Same markup in the
  // pane and in the side column, so the two can never tell different stories.
  const ICO = {in: ['↓', 'ok'], out: ['↑', ''], changed: ['•', ''], check: ['✓', 'ok'], problem: ['!', 'bad'], people: ['☺', '']};
  const ev = function (r) {
    const i = ICO[r.kind] || ICO.changed;
    return '<div class="ev" data-k="' + esc(r.kind) + '"><span class="ico ' + i[1] + '">' + i[0] + '</span><div class="t">' +
      (r.who ? '<b>' + esc(r.who) + '</b> · ' : '') + esc(r.text) + '</div>' +
      '<span class="when">' + esc(String(r.at).slice(5, 16)) + '</span></div>';
  };
  const recent = d.recent || [];
  const evs = recent.filter(function (r) { return actKind === 'all' || r.kind === actKind || (actKind === 'changed' && r.kind === 'people'); }).map(ev);
  // what is happening now, in words and how far, first: in Activity and in the column beside every page
  const nowRow = d.running
      ? '<div class="ev"><span class="ico"><span class="spin"></span></span><div class="t">' + esc(d.running_said || d.running) +
        '<small>happening now' + (d.progress && d.progress.said ? ' · ' + esc(d.progress.said) : d.progress && d.progress.pct != null ? ' · ' + d.progress.pct + '%' : '') + '</small>' +
        '<div class="bar' + (d.progress && d.progress.pct != null ? '' : ' wait') + '"><i style="width:' +
        ((d.progress && d.progress.pct) || 0) + '%"></i></div></div></div>' : '';
  $('events').innerHTML = (actKind === 'all' ? nowRow : '') + (evs.length ? evs.join('') : '<div class="empty">Nothing ' + (actKind === 'all' ? 'yet.' : 'of this kind yet.') + '</div>');
  $('sideLog').innerHTML = nowRow + (recent.length ? recent.slice(0, 12).map(ev).join('') : '<div class="empty">Nothing yet.</div>');
  $('sideNow').textContent = d.runner && d.runner.ok
    ? 'Picking up jobs · checked ' + (d.runner.ago || 'just now')
    : d.runner && d.runner.stopped ? 'Stopped: nothing runs until Start' : 'Not picking up jobs';
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
  if (pane === 'transfers' && Date.now() - cpAt > 20000) loadCopy();      // the backup's state, now and then
  else if (cpData) $('cpBring').hidden = !($('cpFrom').value !== ARCH && $('cpTo').value === ARCH) && !secs.length && !lastLanded.length;

  const tq = d.queued || [];
  if (busy && !d.running && !tq.length) busy = null;
}

$('anPause').onclick = async function () {
  const b = this, act = descPaused ? 'describe-resume' : 'describe-pause'; b.disabled = true; b.textContent = 'Asking…';
  try {
    const r = await (await fetch('helper.php', { method: 'POST', body: new URLSearchParams({ action: act }) })).json();
    $('anPauseSaid').textContent = r.error ? 'Did not happen: ' + r.error : act === 'describe-pause'
      ? 'Paused ✓ It stops after the file it is on; files already described are kept. Copying carries on.'
      : 'Resumed ✓ It carries on with the next file within a few seconds.';
  } catch (e) { $('anPauseSaid').textContent = 'Could not reach the archive: ' + e.message; }
  $('anPauseSaid').dataset.keep = '1'; setTimeout(function () { delete $('anPauseSaid').dataset.keep; }, 8000);
  b.disabled = false; load();
};

// ── proxy settings: test on one clip, see the stills side by side, choose ──
let ptOpen = false, ptLast = '';        // the log stays as you left it; nothing redrawn that did not change
function drawProxyTest(d) {
  const ps = d.proxy_setting || [720, 4], pt = d.proxy_test;
  const running = d.running === 'proxy-test', asked = (d.queued || []).includes('proxy-test');
  $('ptNow').textContent = ps[0] + 'p · ' + (ps[1] === 'sw' ? 'in software' : ps[1] + ' Mbit/s on ' + CHIP) + (ps.join(' ') === '720 4' ? ' (the default)' : '');
  if (document.activeElement !== $('ptSet')) $('ptSet').value = ps[0] + ' ' + ps[1];   // not while you are choosing
  $('ptGo').dataset.busy = running || asked ? '1' : '';
  $('ptGo').textContent = running ? 'Testing…' : asked ? 'Asked…' : 'Test on a clip…';
  if (!pt && !running && !asked) { $('pxTest').innerHTML = ''; return; }
  const mb = {};                               // "720p at 4Mbit/s: 30 MB a minute" -> {"720-4M": 30}
  ((pt && pt.text) || '').replace(/(\d+)p at (\d+)Mbit\/s: (\d+) MB a minute/g, function (_, h, b, m) { mb[h + '-' + b + 'M'] = m; });
  ((pt && pt.text) || '').replace(/(\d+)p in software: (\d+) MB a minute/g, function (_, h, m) { mb[h + '-sw'] = m; });
  const order = ['original', '720-4M', '720-6M', '1080-4M', '1080-6M', '720-sw', '1080-sw'];
  const have = (pt && pt.stills) || [];
  const tiles = order.filter(function (k) { return have.includes(k + '.jpg'); }).map(function (k) {
    const m = /^(\d+)-(\d)M$/.exec(k) || /^(\d+)-(sw)$/.exec(k), inUse = m && +m[1] === ps[0] && m[2] === String(ps[1]);
    const label = k === 'original' ? 'The original' : m[2] === 'sw' ? m[1] + 'p · software' : m[1] + 'p · ' + m[2] + ' Mbit/s <span class="note">· chip</span>';
    const size = k === 'original' ? 'the camera file' : mb[k] ? mb[k] + ' MB a minute' : '';
    return '<div style="border:1px solid var(--line);border-radius:8px;overflow:hidden' + (inUse ? ';outline:2px solid var(--accent)' : '') + '">' +
      '<a href="../proxy-test/' + k + '.jpg?t=' + pt.at + '" target="_blank" title="Open it full size">' +
      '<img src="../proxy-test/' + k + '.jpg?t=' + pt.at + '" style="display:block;width:100%;aspect-ratio:16/9;object-fit:cover" alt=""></a>' +
      '<div style="padding:8px 10px"><b>' + label + '</b><div class="note">' + esc(size) + '</div>' +
      (m ? (inUse ? '<div class="note" style="margin-top:6px">✓ in use</div>'
                  : '<button class="btn quiet" style="margin-top:6px" data-use="' + m[1] + ' ' + m[2] + '" type="button">Use this</button>') : '') +
      '</div></div>';
  });
  const html = '<div style="margin-top:12px">' +
    (running ? '<p><span class="spin"></span><b>Making the test clips now</b> — a few minutes; the stills appear here when they are done.</p>'
      : asked ? '<p><span class="spin"></span><b>Asked</b> — the archive machine starts it within a minute.</p>'
      : '<p><b>Last test</b> · ' + esc(new Date(pt.at * 1000).toLocaleString()) + ' · click a still to see it full size</p>') +
    (tiles.length && !running ? '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px">' + tiles.join('') + '</div>' : '') +
    (pt ? '<details id="ptLog" style="margin-top:8px"' + (ptOpen ? ' open' : '') + '><summary class="note">What the archive machine said</summary><pre style="white-space:pre-wrap;margin:8px 0 0;font:12px/1.5 ui-monospace,Menlo,monospace">' +
      esc(pt.text) + '</pre></details>' : '') + '</div>';
  if (html === ptLast) return;              // the same as shown: the page is left alone (no flicker, no closing)
  ptLast = html; $('pxTest').innerHTML = html;
  if ($('ptLog')) $('ptLog').ontoggle = function () { ptOpen = this.open; ptLast = ''; };
  $('pxTest').querySelectorAll('[data-use]').forEach(function (b) {
    b.onclick = function () { b.disabled = true; b.textContent = 'Saving…'; useSetting(b.dataset.use); };
  });
}
async function useSetting(v) {
  v = v.split(' ');
  try {
    const j = await (await fetch('analyze.php', { method: 'POST', body: new URLSearchParams({ action: 'proxy-setting', height: v[0], mbits: v[1] }) })).json();
    $('ptSaid').textContent = j.error ? 'Did not happen: ' + j.error
      : 'Saved ✓ Proxies made from now on are ' + v[0] + 'p at ' + v[1] + ' Mbit/s. The ones already made stay as they are.';
  } catch (e) { $('ptSaid').textContent = 'Could not reach the archive: ' + e.message; }
  load();
}
$('ptSave').onclick = function () { this.disabled = true; this.textContent = 'Saving…'; const b = this;
  useSetting($('ptSet').value).then(function () { b.disabled = false; b.textContent = 'Use this setting'; }); };
$('ptGo').onclick = function (e) { e.preventDefault(); if (this.dataset.busy) return; $('ptPick').value = ''; $('ptPick').click(); };
$('ptPick').onchange = async function () {
  const f = (this.files || [])[0], said = $('ptSaid');
  if (!f) return;
  said.textContent = 'Finding “' + f.name + '” in the archive… (only its name and size are read)';
  try {
    const r = await (await fetch('analyze.php?' + new URLSearchParams({ file: f.name, size: f.size }))).json();
    const rel = (r.files || [])[0];
    if (!rel) { said.textContent = '“' + f.name + '” is not in the archive (or search has not seen it yet). Pick a clip from the VIDEO share.'; return; }
    if (/[^\p{L}\p{N} _.\/&(),+-]/u.test(rel)) { said.textContent = 'Its name or folder has a character the archive machine\'s jobs cannot take (' + rel + '). Pick another clip.'; return; }
    const j = await (await fetch('../run.php', { method: 'POST', body: new URLSearchParams({ action: 'proxy-test', query: rel }) })).json();
    said.textContent = j.error ? 'Did not happen: ' + j.error : 'Asked ✓ Testing with ' + rel + ' — the stills appear below in a few minutes.';
  } catch (e) { said.textContent = 'Could not reach the archive: ' + e.message; }
  load();
};

// ── describing footage ─────────────────────────────────────────────────────
// Asks, and says what happened; the helper does the work and the live box shows it.
function tcode(v) { v = Math.floor(v || 0); return Math.floor(v / 60) + ':' + String(v % 60).padStart(2, '0'); }
function drawProxies(p) {
  const el = $('pxState');
  $('pxStop').hidden = !(p && ((p.state === 'building' && p.running) || p.state === 'planning'));   // Stop, only while something runs
  if (!p) { el.textContent = ''; return; }        // the folder list says it
  const n = function (x) { return (+x || 0).toLocaleString(); };
  const size = function (gb) { gb = +gb || 0; return gb >= 1000 ? (gb / 1024).toFixed(1) + ' TB' : gb + ' GB'; };
  const when = p.ago == null ? '' : ' <span class="note">(' + (p.ago < 90 ? 'just now' : Math.round(p.ago / 60) + ' min ago') + ')</span>';
  // Asked, not started: the runner looks at its list once a minute.
  if (p.asked) { el.innerHTML = ''; return; }     // the list above says it, once
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
        (p.hw === '1' ? 'on ' + CHIP + ', fast' : 'in software, which is slower') + when +
        (p.hw !== '1' && p.hw_why ? '<br><span class="note">Why not ' + CHIP + ': ' + esc(p.hw_why) + '.' +
          (p.cpu ? ' Processor: ' + esc(p.cpu) + '.' : '') + '</span>' : '')
    : p.state === 'building' && p.running
      ? '<b>Making proxies</b>' + (p.only ? ' for ' + esc(p.only) : '') + ' · ' + n(p.done) + ' of ' + n(p.total) + ' · ' + n(p.ok) + ' made' +
        (p.hw === '1' ? ' (' + n(p.chip) + ' on ' + CHIP + (+p.mixed ? ', ' + n(p.mixed) + ' read by the processor' : '') + (+p.soft ? ', ' + n(p.soft) + ' in software' : '') + ')' : ' in software') +
        (+p.asis ? ' · ' + n(p.asis) + ' played as they are' : '') + ' · ' + n(p.failed) + ' failed · ' +
        n(p.later) + ' left for later (still arriving)<br>now: ' + esc(p.file || '')
    : p.state === 'building'
      ? '<span class="warnline" style="display:block">The proxy build stopped without finishing (the machine restarted?) at ' +
        n(p.done) + ' of ' + n(p.total) + '. Start now (in the list above) carries on; finished ones are kept.</span>'
    : p.state === 'stopped'
      ? 'Stopped from Manage at ' + n(p.done) + ' of ' + n(p.total) + ' · ' + n(p.ok) + ' made. Start now (in the list above) carries on from there.'
    : p.state === 'done'
      ? '✓ Finished · ' + n(p.ok - (+p.asis || 0)) + ' made' + (+p.asis ? ' · ' + n(p.asis) + ' light enough to play as they are (no copy needed)' : '') + ' · ' + n(p.failed) + ' failed' + (+p.later ? ' · ' + n(p.later) + ' were still arriving: they are made by themselves on a run two hours later' : '')
    : esc(p.state || '');
}
// Stop proxies: a runner job, asked twice on the button.
document.querySelectorAll('[data-px]').forEach(function (b) {
  b.onclick = async function () {
    const what = b.dataset.px, folder = what === 'proxy-stop' ? '' : $('prepPath').value.trim().replace(/\/+$/, '');
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
let descPaused = false;                // for the list, which says "paused" instead of "queued"
function drawDescribeTools(h) {
  descPaused = !!(h && h.describe_paused);
  // Always there: it is a switch Rushes keeps, so it works even while the helper is
  // away (it sees it when it is back).
  $('anPause').hidden = true;                     // the main switch, in the card, starts and pauses it
  const an = (h && h.analysis) || {}, d = (h && h.describe) || {};
  const steps = function () {        // Install the AI, step by step: each a ✓, the one under way turning
    return (d.done ? d.done.split(' | ').map(function (x) { return '<div class="ok">✓ ' + esc(x) + '</div>'; }).join('') : '') +
      (d.note ? '<div><span class="spin"></span>' + esc(d.note) + '</div>' : '');
  };
  const ask = function (act, label, quiet) {
    return '<button class="btn' + (quiet ? ' quiet' : '') + '" type="button" data-an="' + act + '">' + label + '</button>';
  };
  $('anTools').innerHTML = !h || !h.label ? 'No helper is set up yet (Setup → 04 Helper).'
    : d.phase === 'installing' ? '<b>Installing the AI on ' + esc(h.label) + '</b>' + steps()
    : !an.model ? 'The helper on <b>' + esc(h.label) + '</b> has not said yet whether it can describe footage.'
    // Ready: one main switch (describing on, or paused) with what it is doing now; the schedule a small switch below
    // Ready: whether it is on (its switch is on Overview, with every other one) and what it is doing now; the schedule here
    : an.ready ? '<div class="aimain">' +
        '<span class="dot' + (descPaused ? ' off' : ' ok') + '" style="width:10px;height:10px;border-radius:50%;flex:none;background:var(--' + (descPaused ? 'warn' : 'ok') + ')"></span>' +
        '<div class="t"><b>' + (descPaused ? 'Paused' : 'Describing') + '</b><small>' + esc(
          descPaused ? 'Nothing more is described until it is switched on again, on Overview. What is done is kept; copying carries on.'
          : d.phase === 'analysing' ? 'Now: ' + (d.label || d.source || '') + (d.of ? ' · ' + d.n + ' of ' + d.of : '') + ' — switching off stops after the file it is on'
          : d.note ? d.note.charAt(0).toUpperCase() + d.note.slice(1)
          : 'On: folders on the list below are described as soon as their proxies are made') + '</small></div>' +
        '<a class="btn quiet" href="#overview" data-go-overview>On/off is on Overview →</a>' +
        '<span class="ok" style="white-space:nowrap">✓ AI ready<span class="infotip" tabindex="0" data-tip="' + esc('On ' + h.label + '. Vision model ' + an.model + ', speech ' +
          (an.speech ? an.speech.split('/').pop() : 'off') + '. Both run on that Mac itself; nothing is sent anywhere.') + '">i</span></span></div>' +
      '<div class="airow"><button class="sw' + (h.describe_night ? ' on' : '') + '" role="switch" aria-checked="' + !!h.describe_night +
          '" aria-label="Only at night" data-an="' + (h.describe_night ? 'describe-anytime' : 'describe-night') + '"></button>' +
        '<div class="t">Only at night<small>' + (h.describe_night ? '10 pm to 7 am: the Mac is free during the day'
          : 'Off: any time there is something to describe') + '</small></div></div>'
    : an.nochip ? 'Describing needs a Mac with an Apple chip; <b>' + esc(h.label) + '</b> has an Intel one.'
    : (d.phase === 'install-failed' ? '<span class="warnline" style="display:block">The AI was not installed: ' + esc(d.note) + '</span>' : '') +
      '<div style="margin-top:6px">The AI that describes footage is not on <b>' + esc(h.label) + '</b> yet.' +
      '<span class="infotip" tabindex="0" data-tip="A vision model that says what each shot shows, and a speech model that writes down what is said. About 8 GB, downloaded once, and run on that Mac itself: nothing is sent anywhere.">i</span></div>' +
      '<div style="margin-top:10px">' + ask('ai-install', d.phase === 'install-failed' ? 'Try again' : 'Install the AI (about 8 GB)') + '</div>';
  $('anTools').querySelectorAll('[data-an]').forEach(function (b) {
    b.onclick = async function () {
      const act = b.dataset.an; b.disabled = true;
      if (b.classList.contains('sw')) b.classList.toggle('on'); else b.textContent = 'Asking…';     // a switch moves at once
      try {
        const r = await (await fetch('helper.php', { method: 'POST', body: new URLSearchParams({ action: act }) })).json();
        $('anPauseSaid').textContent = r.error ? 'Did not happen: ' + r.error
          : act === 'ai-install' ? 'Asked ✓ The helper starts within a minute; each step shows here.'
          : act === 'describe-night' ? 'Only at night ✓ Describing waits for 10 pm, and stops at 7 am.'
          : act === 'describe-anytime' ? 'Any time ✓ Describing carries on whenever there is something to describe.'
          : act === 'describe-pause' ? 'Paused ✓ It stops after the file it is on; what is done is kept. Copying carries on.'
          : 'On ✓ It carries on with the next file within a few seconds.';
      } catch (e) { $('anPauseSaid').textContent = 'Could not reach the archive: ' + e.message; }
      $('anPauseSaid').dataset.keep = '1'; setTimeout(function () { delete $('anPauseSaid').dataset.keep; }, 8000);
      load();                                        // the card again, as the helper now has it
    };
  });
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
    drawPrepare(a.table, a);
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
    if (f.length === 1) { $('prepPath').value = f[0]; planOf(f[0]); }
    else if (f.length > 1) {
      said.innerHTML = 'There are ' + f.length + ' folders like that in the archive — which one? ' +
        f.map(function (p) { return '<button class="btn quiet" data-pick="' + esc(p) + '" type="button">' + esc(p) + '</button>'; }).join(' ');
      said.querySelectorAll('[data-pick]').forEach(function (b) {
        b.onclick = function () { $('prepPath').value = b.dataset.pick; planOf(b.dataset.pick); };
      });
    } else said.textContent = '“' + top + '” is not in the archive (or search has not seen it yet). Pick it from the archive share in Finder.';
  } catch (e) { said.textContent = 'Could not reach the archive: ' + e.message; }
};

// What a folder holds, the moment it is chosen or typed: from the search
// catalogue, so it is instant and starts no job.
const gb = function (b) { b = +b || 0; return b >= 1e12 ? (b / 1e12).toFixed(1) + ' TB' : Math.round(b / 1e9) + ' GB'; };
const planLine = function (p) {
  const left = p.videos - p.have;
  return (+p.videos || 0).toLocaleString() + ' videos · ' + (+p.have || 0).toLocaleString() + ' already have a proxy · ' +
    (left ? '<b>' + left.toLocaleString() + ' to make</b>, ' + gb(p.to_read) + ' to read' : '<b>nothing left to make</b>');
};
async function planOf(folder) {
  const said = $('prepSaid');
  said.innerHTML = '<span class="spin"></span> Looking at ' + esc(folder) + ' …';
  try {
    const p = await (await fetch('analyze.php?plan=' + encodeURIComponent(folder))).json();
    said.innerHTML = p.error ? esc(p.error) : '✓ <b>' + esc(folder) + '</b>: ' + planLine(p) + ' — press + Add to the list.';
  } catch (e) { said.textContent = 'Could not reach the archive: ' + e.message; }
}
$('prepPath').onchange = function () { const v = this.value.trim().replace(/\/+$/, ''); if (v) planOf(v); };

// Prepare: one confirmation on the button itself, then say what happened.
let prepArmed = 0;
$('prepGo').onclick = async function () {
  const path = $('prepPath').value.trim().replace(/\/+$/, ''), b = this;
  if (!path) { $('prepSaid').textContent = 'Type or pick a folder first.'; return; }
  if (Date.now() > prepArmed) { prepArmed = Date.now() + 5000; b.textContent = 'Sure? Add ' + path.split('/').pop(); return; }
  prepArmed = 0; b.disabled = true; b.textContent = 'Asking…';
  try {
    const r = await (await fetch('analyze.php', { method: 'POST', body: new URLSearchParams({ action: 'prepare', path: path }) })).json();
    $('prepSaid').textContent = r.error ? 'Did not happen: ' + r.error
      : 'Added ✓ ' + path + ' — it is on the list below; it starts as soon as the folders before it are done.';
    if (!r.error) $('prepPath').value = '';
  } catch (e) { $('prepSaid').textContent = 'Could not reach the archive: ' + e.message; }
  b.disabled = false; b.textContent = '+ Add to the list'; loadAnalysis();
};
// The list's buttons: each asks once more on the button itself, then acts.
let listArmed = {key: '', until: 0}, openFails = {}, saidOpen = false;
async function listAct(action, folder, b, sure) {
  const key = action + '|' + folder;
  if (sure && (listArmed.key !== key || Date.now() > listArmed.until)) {
    listArmed = {key: key, until: Date.now() + 5000}; b.textContent = 'Sure? ' + b.textContent; return;
  }
  listArmed = {key: '', until: 0}; b.disabled = true;
  try {
    const r = await (await fetch('analyze.php', { method: 'POST', body: new URLSearchParams({ action: action, path: folder }) })).json();
    $('prepSaid').textContent = r.error ? 'Did not happen: ' + r.error
      : {forget: 'Taken off the list ✓ ' + folder + ' — nothing was deleted: its proxies and descriptions stay.',
         up: 'Moved up ✓ ' + folder, down: 'Moved down ✓ ' + folder,
         retry: 'Trying again ✓ ' + folder + ' — the files still missing a proxy are made again, in its place in the list.',
         start: 'Asked ✓ The archive machine starts the proxies for ' + folder + ' at its next turn, within a minute.',
         remake: 'Asked ✓ Within a minute the archive machine throws away the proxies of ' + folder + ' and makes them again with the setting in use. The footage is not touched.'}[action];
  } catch (e) { $('prepSaid').textContent = 'Could not reach the archive: ' + e.message; }
  loadAnalysis();
}
const hm = function (secs) {
  secs = Math.max(60, +secs || 0); const h = Math.floor(secs / 3600), m = Math.round(secs % 3600 / 60);
  return h ? h + ' h' + (m ? ' ' + m + ' min' : '') : m + ' min';
};
function drawPrepare(rows, a) {
  const n = function (x) { return (+x || 0).toLocaleString(); };
  a = a || {};
  if (!rows || !rows.length) { $('prepTable').innerHTML = '<div class="note">No folder is on the list yet.</div>'; return; }
  const px = function (r) {
    const p = r.proxies, f = (r.failures || []).length;
    const fails = f ? ' · <a href="#" class="bad" data-fails="' + esc(r.folder) + '">' + n(f) + ' could not be made — see why</a>' : '';
    return p.step === 'making' ? '<span class="busy">making · ' + n(p.done) + ' of ' + n(p.total) + '</span>' + fails
      : p.step === 'done' ? '<span class="ok">✓ ' + n(p.ok) + ' made</span>' + fails + (+p.later ? ' · ' + n(p.later) + ' still arriving, later' : '')
      : p.step === 'again' ? 'making the ones that were still arriving' + fails
      : p.step === 'stopped' ? '<span class="bad">stopped' + (p.why ? ': ' + esc(p.why) : '') + '</span> · ' + n(p.ok) + ' made · Try again carries on' + fails
      : p.step === 'no-room' ? '<span class="bad">not enough room on the archive: about ' + gb(p.need) + ' needed, ' + gb(p.free) + ' free</span> · free some space, then Try again'
      : p.step === 'no-ffmpeg' ? '<span class="bad">this machine cannot make video yet (no ffmpeg)</span>'
      : '<span class="dim">waiting its turn</span>';
  };
  const ds = function (d) {
    const off = a.helper_fresh === false && ['queued', 'next', 'describing'].includes(d.step)
      ? ' · <span class="bad">the helper Mac is off or asleep, so this waits</span>' : '';
    return (d.step === 'describing' ? '<span class="busy">describing · ' + n(d.n) + ' of ' + n(d.of) + '</span>' +
        '<div class="note">' + (d.doing === 'speech' ? 'listening to ' : d.doing === 'loading the model' ? 'loading the model' : 'looking at ') +
        (d.doing === 'loading the model' ? '' : esc(d.file || 'a file')) + ' — from these proxies (cuts, sound) and the originals (pictures), so the proxies stay until it is done</div>'
      : d.step === 'done' ? '<span class="ok">✓ ' + n(d.files) + ' files</span>' + (d.note && d.note.indexOf('could not') > -1 ? ' · <span class="bad">' + esc(d.note.replace(/asked=\d+;? ?/, '')) + '</span>' : '')
      : descPaused && ['queued', 'next'].includes(d.step) ? '<b>paused</b> — Resume describing above to carry on'
      : d.step === 'queued' ? 'queued on the helper — it starts next, beside any copying'
      : d.step === 'next' ? 'starting'
      : '<span class="dim">waiting for proxies</span>') + off;
  };
  const busy = function (r) { return r.proxies.step === 'making' || r.proxies.step === 'again' || ['describing', 'queued', 'next'].includes(r.describe.step); };
  const doneRow = function (r) { return r.proxies.step === 'done' && r.describe.step === 'done'; };
  // the whole list: how much proxy time is left, at the speed measured on this machine
  const open = rows.filter(function (r) { return !doneRow(r); });
  const total = open.reduce(function (t, r) { return t + (r.left || 0); }, 0);
  const head = '<p class="note" style="margin:0 0 8px">' + n(open.length) + ' of ' + n(rows.length) + ' folder' + (rows.length === 1 ? '' : 's') + ' still to finish' +
    (a.rate > 0 ? ' · proxies: about <b>' + hm(total) + '</b> left, at ' + (a.rate / 1e6).toFixed(0) + ' MB/s measured here'
                : ' · the time left shows once the first proxies are made and the speed is known') + '</p>';
  // why it is where it is, in words; Start now when it should have started and has not
  const why = a.why || {};
  const head2 = why.text ? '<p class="note" style="margin:0 0 8px">' + (why.state === 'stuck' ? '<span class="bad">' + esc(why.text) + '</span>'
    : (why.state === 'making' || why.state === 'starting' || why.state === 'asked' ? '<span class="spin"></span>' : '') + esc(why.text)) + '</p>' : '';
  let place = 0;          // place in line among the folders not finished yet: 1 is the one running now
  // the proxy maker's own words, one click away — nothing it does is hidden
  const pl = a.proxy_log || {};
  const said = (pl.lines || []).length || (pl.errors || []).length
    ? '<details id="pxSaid"' + (saidOpen ? ' open' : '') + ' style="margin:0 0 10px"><summary class="note" style="cursor:pointer">What the archive machine said last' +
      (pl.at ? ' (' + esc(clock(pl.at)) + ')' : '') + '</summary><pre style="white-space:pre-wrap;margin:6px 0 0;font:12px/1.5 ui-monospace,Menlo,monospace">' +
      esc((pl.lines || []).join('\n')) + ((pl.errors || []).length ? '\n\nffmpeg errors:\n' + esc(pl.errors.join('\n')) : '') + '</pre></details>'
    : '<p class="note" style="margin:0 0 10px">The archive machine has not written anything about proxies yet (no proxy.log): no proxy run has started there.</p>';
  $('prepTable').innerHTML = head + head2 + said + '<table class="prep"><tr><th>#</th><th>Folder</th><th>What is in it</th><th>1 · Proxies</th><th>2 · Descriptions</th><th></th></tr>' +
    rows.map(function (r, i) {
      const where = doneRow(r) ? '<span class="ok">✓ done</span>' : (++place, busy(r) || place === 1 ? '<span class="busy">now</span>' : '#' + place);
      const p = r.plan || {};
      const tools = (i > 0 ? '<button class="btn quiet" data-l="up" title="Move up">↑</button>' : '') +
        (i < rows.length - 1 ? '<button class="btn quiet" data-l="down" title="Move down">↓</button>' : '') +
        (['done', 'stopped', 'no-room'].includes(r.proxies.step) && (r.failures || []).length + (r.proxies.step !== 'done' ? 1 : 0)
          ? '<button class="btn quiet" data-l="retry">Try again</button>' : '') +
        // the first folder's start button says what is happening, and cannot start anything twice
        (place === 1 && !doneRow(r) && (['waiting', 'stopped', 'making'].includes(r.proxies.step) || why.state === 'making')
          ? (why.state === 'making' || r.proxies.step === 'making' ? '<button class="btn" disabled title="Proxies are being made now">Running</button>'
            : why.state === 'asked' || why.state === 'starting' ? '<button class="btn" disabled title="Asked; the archive machine starts it within a minute">Starting…</button>'
            : '<button class="btn" data-l="start">Start now</button>') : '') +
        (r.proxies.step === 'done' && r.describe.step !== 'describing' ? '<button class="btn quiet" data-l="remake" title="Throw its proxies away and make them again with the setting in use">Remake proxies</button>' : '') +
        '<button class="btn quiet" data-l="forget">Take off the list</button>';
      const fails = openFails[r.folder] && (r.failures || []).length
        ? '<tr><td></td><td colspan="5"><div style="border:1px solid var(--line);border-radius:8px;padding:8px 10px;margin:2px 0 8px">' +
          '<b>Could not be made</b> — in ffmpeg\'s own words:' +
          '<ul style="margin:6px 0 8px;padding-left:18px">' + r.failures.map(function (f) {
            // a failure from before this folder was (re)started is being made again right now
            const old = f.at && f.at < r.asked && r.proxies.step === 'making';
            return '<li><b>' + esc(f.file) + '</b> — ' + esc(f.why || 'no reason given') +
              ' <span class="note">(' + (old ? 'from an earlier run — being made again now' : esc(clock(f.at))) + ')</span></li>'; }).join('') + '</ul>' +
          '<span class="note">The reason is ffmpeg\'s own words. The original is never touched. Each file leaves this list as soon as its proxy is made; Try again makes just these once more.</span></div></td></tr>'
        : '';
      return '<tr data-f="' + esc(r.folder) + '"><td>' + where + '</td><td><b>' + esc(r.folder) + '</b></td><td>' +
        n(p.videos) + ' videos · ' + gb(p.bytes) + (r.left ? ' · <b>about ' + hm(r.left) + '</b>' : '') + '</td><td>' + px(r) + '</td><td>' + ds(r.describe) + '</td>' +
        '<td style="text-align:right;white-space:nowrap" class="ltools">' + tools + '</td></tr>' + fails;
    }).join('') + '</table>';
  const ps = document.getElementById('pxSaid');
  if (ps) ps.ontoggle = function () { saidOpen = ps.open; };    // stays as you left it when the list refreshes
  $('prepTable').querySelectorAll('.ltools button').forEach(function (b) { b.style.cssText = 'padding:3px 9px;font-size:12px;margin-left:4px'; });
  $('prepTable').querySelectorAll('[data-l]').forEach(function (b) {
    // a redraw keeps a "Sure?" that is still waiting for its second press
    if (listArmed.key === b.dataset.l + '|' + b.closest('tr').dataset.f && Date.now() < listArmed.until) b.textContent = 'Sure? ' + b.textContent;
    b.onclick = function () { listAct(b.dataset.l, b.closest('tr').dataset.f, b, ['forget', 'retry', 'start', 'remake'].includes(b.dataset.l)); };
  });
  $('prepTable').querySelectorAll('[data-fails]').forEach(function (x) {
    x.onclick = function (e) { e.preventDefault(); openFails[x.dataset.fails] = !openFails[x.dataset.fails]; drawPrepare(rows, a); };
  });
}
every(loadAnalysis, 10000);

// Jobs and tools moved to Setup (0.12.23): an old link or bookmark lands there
if (location.hash === '#tools') location.replace('/setup.php#tools');
else show((location.hash || '#overview').slice(1));
every(load, 4000);
</script>
