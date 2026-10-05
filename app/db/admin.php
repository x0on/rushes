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
      <!-- rule 5 of DEVELOPING.md: what repeats by itself is never invisible -->
      <details id="repeats" style="margin:14px 0"><summary>What runs by itself</summary>
        <table class="prep" style="margin-top:8px"><thead><tr><th>What</th><th>How often</th><th>Last</th><th></th></tr></thead><tbody id="repeatRows"></tbody></table>
      </details>

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
            <label class="f" style="max-width:520px;margin:0 0 12px"><span>Which copy is kept</span>
              <select id="keepSide">
                <option value="project">The one on the shelf (your projects) &mdash; card dumps lose</option>
                <option value="card">The card dump &mdash; the shelf's copy loses</option>
                <option value="short">The shortest path, wherever it is</option>
                <option value="oldest">The oldest file (reads every copy's date: slower)</option>
              </select>
              <small>Whatever you pick, copies in the recycle bin, <code>Copied_</code> folders, caches and the
                folders below marked as a stopover are never the one kept. Moving uses the plan you looked at, with this choice.</small></label>
            <div class="btns">
              <button class="btn quiet" data-t="scan">1 &middot; Scan the archive (hours)</button>
              <button class="btn" data-t="plan">2 &middot; Look for duplicates</button>
              <button class="btn quiet" data-t="apply">3 &middot; Move the copies aside</button>
              <button class="btn quiet" data-t="undo">Put them back</button>
            </div>
          </div>
        </div>
        <!-- Where the copies are: the archive's own folders, asked about here, where it matters. -->
        <div class="panel" style="margin-top:14px">
          <header><b>Where the copies are</b> <span class="note" id="dwBuilt"></span></header>
          <div style="padding:14px 14px 4px">
            <p class="note" style="margin:0 0 6px">The archive's folders that hold the most duplicate copies. For each one,
              say what it is, and the copy that stays is the one in the right place:
              <b>Normal</b>, the usual rules decide; <b>Stopover</b>, files only sit there for a while, so its copy goes
              when the clip is also somewhere else; <b>Whole cards</b>, cards copied as they were, so the copy on your
              shelf stays. A change counts from the next <b>2 &middot; Look for duplicates</b>, which shows what it does
              before anything moves.</p>
          </div>
          <div id="dwRows"><div class="empty">Looking&hellip;</div></div>
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
        <div class="panel" id="cOwnBox" style="margin-top:12px" hidden>
          <div class="row"><span class="nm">These are my own drives</span>
            <button class="btn quiet" id="cOwn">…</button></div>
          <p class="note" style="margin:0;padding:0 14px 12px">On: caches that rebuild themselves are deleted, which gives
            the space back at once; what went is written down (cache-deleted.tsv). Off: everything is moved to the
            holding folder, as on an archive a team shares. Either way, nothing under Left alone is ever touched.</p>
        </div>
        <div class="btns" style="margin-top:12px">
          <button class="btn" id="cMove" disabled>Move them out</button>
          <button class="btn quiet" id="cBack" title="Every cache file still in the holding folder goes back where it was">Put them back</button>
          <span class="note" id="cNote">They go to the holding folder, not the bin. Editing software rebuilds
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

      <!-- ══ describe ══ -->
      <section id="pane-describe" hidden>
        <!-- One job per folder, two steps in a fixed order, on two machines. -->
        <div class="panel" style="margin-top:8px">
          <header><b>Prepare folders</b> <span class="note">· so their footage plays in search and can be found by what is in it</span></header>
          <div style="padding:14px">
            <div class="steps2">
              <div><b>1 · Proxies</b> <span class="note">on the archive machine</span>
                <p>A small, light copy of each video (H.264, at the setting below) that plays in any browser and reads much faster
                  than the camera original. Describing finds the cuts and hears the sound in it, and takes its still
                  pictures from the original, at full quality. Kept in their own folder, <code>PROXIES</code>, with the same paths as the
                  originals, so nothing mixes with the footage. Made at low priority; a file that arrived in the last
                  two hours waits for a later run, so nothing still being copied is touched.</p></div>
              <div><b>2 · Descriptions</b> <span class="note">on the helper</span>
                <p>Every shot — the cuts and the sound from its proxy, the pictures from the original at full quality: a sentence, the text on screen, shot size, people, light, themes
                  and tags, and everything said, in the language it was said. Kept in <code>_rushes/analysis</code>;
                  nothing in the archive is changed. It starts by itself when a folder's proxies are done.</p></div>
            </div>
            <p class="note" style="margin:12px 0 8px">Add as many folders as you like: each shows at once what is in it and what is left to make.
              They run one at a time, top to bottom, and the list below shows where each one is. Nothing is ever done twice,
              so adding a folder again later only does what is new.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
              <button class="btn quiet" id="prepChoose" type="button">Choose in Finder…</button>
              <input id="prepPick" type="file" webkitdirectory hidden>
              <input id="prepPath" placeholder="or type it: a folder in the archive, e.g. PARK COLLECTION"
                     style="flex:1;min-width:260px;padding:8px 10px;font:13.5px var(--font);border:1px solid var(--line);
                            border-radius:var(--radius-sm);background:var(--bg);color:var(--fg)">
              <button class="btn" id="prepGo" type="button">+ Add to the list</button>
              <button class="btn quiet" data-px="proxy-stop" type="button">Stop proxies</button>
            </div>
            <p class="note" id="prepSaid" style="margin:10px 0 0"></p>
            <div id="pxState" class="note" style="margin-top:6px"></div>
            <div id="prepTable" style="margin-top:12px"></div>
          </div>
        </div>

        <!-- How proxies are made: tested on this machine's own video chip, chosen here. -->
        <div class="panel" style="margin-top:14px">
          <header><b>Proxy settings</b> <span class="note">· how proxies are made on the archive machine: on its video chip or in software</span></header>
          <div style="padding:14px">
            <p id="ptNow" style="margin:0 0 8px"></p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px">
              <select id="ptSet" style="padding:7px 10px;font:13.5px var(--font);border:1px solid var(--line);border-radius:var(--radius-sm);background:var(--bg);color:var(--fg)">
                <optgroup label="On the video chip — fast, the processor stays free">
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
              <span class="note">or test them on one of your clips first, below, and choose by eye</span>
            </div>
            <p class="note" style="margin:0 0 10px">Pick one clip from the archive in Finder. Twenty seconds of it are made at several sizes and
              bitrates on the video chip, and a still from each appears below beside one from the original, at the same moment. Choose the
              one you like: proxies made from then on use it (the ones already made stay as they are). Nothing is uploaded — Safari's button
              says Upload, but only the clip's name and size are read — and nothing in the archive changes. The test clips are kept in
              <code>_rushes/proxy-test</code> on VIDEO, to play full screen.</p>
            <button class="btn" id="ptGo" type="button">Test proxy settings…</button>
            <input id="ptPick" type="file" accept="video/*,.mxf,.MXF,.mts,.MTS" hidden>
            <p class="note" id="ptSaid" style="margin:8px 0 0"></p>
            <div id="pxTest"></div>
          </div>
        </div>

        <!-- What the helper can describe with, and how far the archive has got. -->
        <div class="panel" style="margin-top:14px">
          <header><b>Describing</b></header>
          <div style="padding:14px">
            <div id="anTools" class="note"></div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px">
              <button class="btn quiet" id="anPause" type="button" hidden></button>
              <span class="note" id="anPauseSaid"></span>
            </div>
            <div class="tiles" id="anTiles" style="margin-top:12px"></div>
          </div>
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

        <!-- No lock-in: everything Rushes knows, in formats any other program reads. -->
        <div class="panel" style="margin-top:14px">
          <header><b>Take everything with you</b></header>
          <div style="padding:14px">
            <p class="note" style="margin:0 0 10px">What Rushes knows, in open formats, to keep or to move to
              another program. Downloaded to this computer; nothing on the archive changes.</p>
            <div class="btns" id="exports">
              <a class="btn quiet" href="export.php?what=files" download>Every file, and what it is (CSV)</a>
              <a class="btn quiet" href="export.php?what=moments" download>What describing found (CSV)</a>
              <a class="btn quiet" href="export.php?what=pulls" download>Every pull (JSON)</a>
              <a class="btn quiet" href="export.php?what=copies" download>Where else each file exists (CSV)</a>
            </div>
            <p class="note" style="margin:10px 0 0">Already open files on the archive, readable without Rushes:
              the descriptions (<code>_rushes/analysis</code>, JSON), the record of every copy and move
              (<code>_rushes/origin</code>, text), and the copy proofs (the <code>ascmhl</code> folder in each
              copied folder, ASC MHL).</p>
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
// Where the minute's work runs: inside Rushes Helper on a Mac (HOW-IT-WORKS.md → Rushes on this Mac), or the server's runner
const ON_MAC = <?= on_mac() ? 'true' : 'false' ?>, WHERE = ON_MAC ? 'on this Mac' : 'on the server';
const TITLES = { overview: 'Overview', transfers: 'Transfers', cache: 'Cache',
                 duplicates: 'Duplicates', describe: 'Describe',
                 activity: 'Activity', tools: 'Jobs and tools', projects: "Editors' projects" };

function show(which) {
  pane = which;
  $('title').textContent = TITLES[which] || 'Overview';
  document.querySelectorAll('.rail .nav').forEach(function (b) {
    if (b.dataset.go === which) b.setAttribute('aria-current', 'page');
    else b.removeAttribute('aria-current');
  });
  ['transfers', 'duplicates', 'cache', 'describe', 'projects', 'activity', 'tools'].forEach(function (p) {
    $('pane-' + p).hidden = (p !== which);
  });
  // Overview shows the tiles and the cards; a section shows its own thing.
  $('tiles').hidden = (which !== 'overview');
  $('cards').hidden = (which !== 'overview');
  $('repeats').hidden = (which !== 'overview');
  $('transferSummary').hidden = !latestTransfer || !['overview','transfers'].includes(which);
  if (which === 'cache') loadCache();
  if (which === 'duplicates') loadWhere();
  try { history.replaceState(null, '', '#' + which); } catch (e) {}
}
document.querySelectorAll('.rail .nav[data-go]').forEach(function (b) {
  b.onclick = function (e) { e.preventDefault(); show(b.dataset.go); };
});
$('sideMore').onclick = function () { show('activity'); };
// The fixed buttons in Duplicates use the same path as everything
// else: confirm, ask, watch it happen in the side column.
// Where the copies are: each top folder with what it is, chosen with one press, and said.
async function loadWhere() {
  let r;
  try { r = await (await fetch('dupfolders.php?t=' + Date.now())).json(); } catch (e) { $('dwRows').innerHTML = '<div class="empty">Could not ask the archive.</div>'; return; }
  if (r.error) { $('dwRows').innerHTML = '<div class="empty">' + esc(r.error) + '</div>'; return; }
  $('dwBuilt').textContent = r.built ? (r.done ? 'from the tidy-up of ' + r.built + ': already moved' : 'from the look on ' + r.built) : '';
  const head = r.done ? '<p class="note" style="margin:0;padding:10px 14px;border-top:1px solid var(--line)"><b>That tidy-up is done:</b> ' +
    'these copies were already moved to the holding folder. They show here so you can see where duplicates come from. ' +
    'Press 2 · Look for duplicates to see whether any are left.</p>' : '';
  if (!r.folders.length) { $('dwRows').innerHTML = '<div class="empty">Nothing yet: press 2 · Look for duplicates, and the folders with copies show here.</div>'; return; }
  const KINDS = [['normal', 'Normal'], ['stopover', 'Stopover'], ['cards', 'Whole cards']];
  $('dwRows').innerHTML = head + r.folders.map(function (f) {
    const n = function (k, one, many) { return k.toLocaleString() + (k === 1 ? one : many); };
    const what = r.done
      ? (f.move ? n(f.move, ' copy was', ' copies were') + ' moved out of here' + (f.bytes >= 1048576 ? ' (' + tb(f.bytes) + ')' : '') : 'nothing was moved from here') +
        ' · ' + n(f.stay || 0, ' copy', ' copies') + ' kept here'
      : (f.move ? n(f.move, ' copy', ' copies') + ' would move from here' + (f.bytes >= 1048576 ? ' (' + tb(f.bytes) + ')' : '') : 'nothing would move from here') +
        ' · ' + n(f.stay || 0, ' copy stays', ' stay') + ' here';
    return '<div class="row" style="display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;padding:10px 14px;border-top:1px solid var(--line)">' +
      '<div style="flex:1 1 240px;min-width:0"><b>' + esc(f.name) + '</b><div class="note">' + esc(what) + '</div></div>' +
      (f.kind === 'shelf' ? '<span class="note">your shelf (Reorganize)</span>' :
        '<div class="seg" role="group" aria-label="What ' + esc(f.name) + ' is">' + KINDS.map(function (k) {
          return '<button type="button" class="btn quiet' + (f.kind === k[0] ? ' on' : '') + '" aria-pressed="' + (f.kind === k[0]) +
            '" data-dw="' + esc(f.name) + '" data-k="' + k[0] + '">' + k[1] + '</button>'; }).join('') + '</div>') +
      '</div>';
  }).join('') + '<p class="note" id="dwSaid" style="margin:8px 14px 12px"></p>';
  $('dwRows').querySelectorAll('[data-dw]').forEach(function (b) {
    b.onclick = async function () {
      b.disabled = true;
      try {
        const x = await (await fetch('dupfolders.php', { method: 'POST', body: new URLSearchParams({ folder: b.dataset.dw, kind: b.dataset.k }) })).json();
        if (x.error) throw new Error(x.error);
        await loadWhere();
        $('dwSaid').textContent = b.dataset.dw + ' is now: ' + b.textContent + ' ✓ Press 2 · Look for duplicates to see what that changes; nothing moves until 3.';
      } catch (e) { b.disabled = false; $('dwSaid').textContent = 'Did not happen: ' + e.message; }
    };
  });
}

document.querySelectorAll('#pane-duplicates [data-t]')
  .forEach(function (b) { b.onclick = function () { act(b.dataset.t, b); }; });

// ── asking for work ────────────────────────────────────────────────────────
const ASK = {
  scan:     <?= on_mac() ? json_encode('Read the files that have the same size as another, every byte, to find the ones that are the same (on this Mac). Hours for a big archive the first time, minutes after: what was read is remembered. Pausing copying stops it, and it carries on next time. Moves nothing.')
                 : json_encode('Read every file in the archive to find the ones that are the same (Czkawka, in its container). Hours for a big archive; other jobs wait meanwhile. Moves nothing.') ?>,
  plan:     'Look through the archive for files that are the same file. Moves nothing.',
  apply:    'Move every duplicate copy to the holding folder. Nothing is deleted, and this can be undone.',
  undo:     'Put everything in the holding folder back where it came from.',
  'organize-undo':  'Put back the files the old date-based layout moved.',
  import:   'Rebuild search from the file list. Seconds to minutes.',
  'gpu-test': 'Measure what the video chip can do: test encodes and one real clip, a few minutes. Writes a report; makes no video files. Downloads a public ffmpeg container image.',
  'proxy-plan':  'Count the videos that have no proxy yet, and how much there is to read. Makes nothing.',
  'proxy-build': 'Make the missing proxies, in the background on the archive machine. It takes hours to days; stopping and starting again loses nothing.',
  'proxy-stop':  'Stop making proxies. The one being made is thrown away; everything finished is kept.',
  manifest: 'Write down every file and its size, then rebuild search. A few minutes.',
  verify:   'Check every file in the holding folder still has a twin in the archive. Moves nothing.',
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
  if (ASK[name] && !sure(btn, ASK[name], 'act:' + name)) return;
  const was = btn.textContent;
  btn.disabled = true; btn.textContent = 'asked…';
  try {
    const ask = { action: name };
    if (name === 'plan' || name === 'apply') ask.keep_side = $('keepSide').value;   // run.php checks it again
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

// The export links download at once; each says so on itself, then goes back.
document.querySelectorAll('#exports a').forEach(function (a) {
  a.addEventListener('click', function () {
    const was = a.textContent; a.textContent = 'Downloading ✓';
    setTimeout(function () { a.textContent = was; }, 2500);
  });
});

// ── cache ──────────────────────────────────────────────────────────────────
async function loadCache() {
  try {
    const d = await (await fetch('junk.php?json=1&t=' + Date.now())).json();
    const n = d.total.files, b = d.total.bytes;        // each file once, even when two groups name it
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
    // On a Mac, the person's own drives: caches that rebuild are deleted (runner.py → cache_clean)
    $('cOwnBox').hidden = !d.mac; cacheOwn = !!d.own;
    $('cOwn').textContent = d.own ? 'On — turn off' : 'Off — turn on';
    $('cMove').textContent = d.own ? 'Clear them out' : 'Move them out';
    if (d.own) $('cNote').textContent = 'Deleted, for good: editing software makes them again from the originals when it needs them. The space comes back at once.';
  } catch (e) {
    $('cRows').innerHTML = '<div class="empty">Could not count the cache: ' + esc(e.message) + '</div>';
  }
}
let cacheOwn = false;
$('cMove').onclick = function () { moveCache(this); };
$('cOwn').onclick = async function () {
  const b = this; b.disabled = true;
  try {
    const j = await (await fetch('junk.php', { method: 'POST', body: new URLSearchParams({ own: cacheOwn ? '0' : '1' }) })).json();
    if (j.error) oops(j.error);
    else b.textContent = j.own ? 'On ✓ caches that rebuild will be deleted' : 'Off ✓ caches will be moved aside';
  } catch (e) { oops('could not do that: ' + e.message); }
  setTimeout(function () { b.disabled = false; loadCache(); }, 2500);
};
// Undo for "Move them out": asked twice on the button itself, then a job.
$('cBack').onclick = async function () {
  const b = this;
  if (!b.dataset.sure) {
    b.dataset.sure = '1'; b.textContent = 'Sure? Put every cache file back';
    setTimeout(function () { delete b.dataset.sure; b.textContent = 'Put them back'; }, 5000); return;
  }
  delete b.dataset.sure; b.disabled = true; b.textContent = 'asked…';
  try {
    const j = await (await fetch('../run.php', { method: 'POST', body: new URLSearchParams({ action: 'cache-undo' }) })).json();
    if (j.error) { oops(j.error); } else { b.textContent = 'Asked ✓ The runner puts them back within a minute (Activity shows it)'; }
  } catch (e) { oops('could not do that: ' + e.message); }
  setTimeout(function () { b.disabled = false; b.textContent = 'Put them back'; load(); }, 4000);
};

// Two calls behind one button: work out which files are rebuildable scratch,
// then move them. They go to the holding folder, not the bin.
async function moveCache(btn) {
  if (!sure(btn, cacheOwn ? 'Caches that rebuild themselves are deleted, for good; the rest go to the holding folder. ' +
      'Editing software makes them again from the originals when it needs them.'
      : 'They go to the holding folder, not the bin; the space comes back when you empty that folder. ' +
      'Editing software rebuilds them from the originals, so nothing is lost.', 'cache-out')) return;
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
  if (!sure($('goCopy'), paths.length + ' folder(s), one after another, in this order; anything already in the archive is skipped.' +
      (dropped ? ' ' + dropped + ' that were queued are unticked, so they come off the list (anything already copied stays).' : ''),
      'handover')) return;
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
// Each button asks once more on the button itself ("Sure?"), then says what
// happened. The answer is read back from the helper, never assumed.
let armed = { what: '', until: 0 }, said = { text: '', until: 0 };
function drawHelper(d) {
  const h = d.helper || {}, el = $('hctl');
  el.hidden = !['overview', 'transfers'].includes(pane);   // the switches wherever the transfer shows
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
    'proxy-plan': 'Plan proxies', 'proxy-build': 'Make proxies', 'gpu-test': 'Test the video chip', 'proxy-test': 'Test proxy settings', scan: 'Find duplicates'};
  const tq = d.queued || [];
  $('toolState').innerHTML =
    (d.running ? '<p><span class="spin"></span>Running: <b>' + esc(TOOL[d.running] || d.running) + '</b>' +
        (d.progress ? ' · ' + d.progress.pct + '%' : '') + ' — each step shows in the raw log (Activity)</p>' : '') +
    (tq.length ? '<p><span class="spin"></span>Asked: <b>' + tq.map(function (x) { return esc(TOOL[x] || x); }).join(', ') +
        '</b> — the archive machine starts it at its next turn, within a minute</p>' : '') +
    (d.gpu_test ? '<div style="margin-top:10px;padding:10px 12px;border:1px solid var(--line);border-radius:8px">' +
        '<b>Video chip test</b> · ' + (d.running === 'gpu-test' ? 'running now' : 'finished ' + esc(clock(d.gpu_test.at))) +
        '<pre style="white-space:pre-wrap;margin:8px 0 0;font:12px/1.5 ui-monospace,Menlo,monospace">' + esc(d.gpu_test.text) + '</pre></div>' : '');
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
  $('ptNow').innerHTML = 'In use: <b>' + ps[0] + 'p ' + (ps[1] === 'sw' ? 'in software' : 'at ' + ps[1] + ' Mbit/s on the video chip') + '</b>';
  if (document.activeElement !== $('ptSet')) $('ptSet').value = ps[0] + ' ' + ps[1];   // not while you are choosing
  $('ptGo').disabled = running || asked;
  $('ptGo').textContent = running ? 'Testing…' : asked ? 'Asked…' : 'Test proxy settings…';
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
$('ptGo').onclick = function () { $('ptPick').value = ''; $('ptPick').click(); };
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
  if (!p) { el.textContent = 'Nothing planned yet. Add a folder to the list above; Jobs and tools → Plan proxies counts the whole archive.'; return; }
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
        (p.hw === '1' ? 'with the hardware encoder (QuickSync), fast' : 'in software, which is slow') + when +
        (p.hw !== '1' && p.hw_why ? '<br><span class="note">Why not the video chip: ' + esc(p.hw_why) + '.' +
          (p.cpu ? ' Processor: ' + esc(p.cpu) + '.' : '') + '</span>' : '')
    : p.state === 'building' && p.running
      ? '<b>Making proxies</b>' + (p.only ? ' for ' + esc(p.only) : '') + ' · ' + n(p.done) + ' of ' + n(p.total) + ' · ' + n(p.ok) + ' made' +
        (p.hw === '1' ? ' (' + n(p.chip) + ' on the video chip' + (+p.mixed ? ', ' + n(p.mixed) + ' read by the processor' : '') + (+p.soft ? ', ' + n(p.soft) + ' in software' : '') + ')' : ' in software') +
        ' · ' + n(p.failed) + ' failed · ' +
        n(p.later) + ' left for later (still arriving)<br>now: ' + esc(p.file || '')
    : p.state === 'building'
      ? '<span class="warnline" style="display:block">The proxy build stopped without finishing (the machine restarted?) at ' +
        n(p.done) + ' of ' + n(p.total) + '. Start now (in the list above) carries on; finished ones are kept.</span>'
    : p.state === 'stopped'
      ? 'Stopped from Manage at ' + n(p.done) + ' of ' + n(p.total) + ' · ' + n(p.ok) + ' made. Start now (in the list above) carries on from there.'
    : p.state === 'done'
      ? '✓ Finished · ' + n(p.ok) + ' made · ' + n(p.failed) + ' failed' + (+p.later ? ' · ' + n(p.later) + ' were still arriving: they are made by themselves on a run two hours later' : '')
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
  const b = $('anPause'), on = !!(h && h.label);
  b.hidden = !on;
  if (on && !b.disabled) b.textContent = descPaused ? 'Resume describing' : 'Pause describing';
  if (on && !$('anPauseSaid').dataset.keep)
    $('anPauseSaid').textContent = descPaused ? 'Paused — nothing more is described until Resume. Files already described are kept; copying carries on.'
      : h.describe && h.describe.phase === 'analysing' ? 'Pause stops it after the file it is on; that file is done again on Resume.' : '';
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

show((location.hash || '#overview').slice(1));
every(load, 4000);
</script>
