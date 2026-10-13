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
  .views { display: flex; gap: 6px; margin: 4px 0 14px }
  .views button { border: 1px solid var(--line); background: var(--bg); color: var(--muted); border-radius: 999px; padding: 5px 12px; font: inherit; font-size: 12.5px; cursor: pointer }
  .views button.on { border-color: var(--accent); background: var(--sel-bg); color: var(--sel-fg) }
  .dv { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: 14px; align-items: start }
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
  .pl.sug { background: none; border: 1px dashed var(--line); cursor: default }
  .pl label { font-size: 11.5px; color: var(--muted); white-space: nowrap; cursor: pointer }
  .pl .x { background: none; border: 0; color: var(--muted); cursor: pointer; font-size: 14px; padding: 0 2px }
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
      <h2>Tidy-up</h2>
      <p>Moves what the copies brought into ARCHIVE onto the shelf, and the folders that were already
         in the archive outside it (an old server's layout, a drive's own folders). For a copy, where
         each file goes is read from where it <i>came from</i>, not where the copy put it; a folder already
         here moves as it is. Either way everything below the <?= $e($one) ?> keeps the layout it had.
         Nothing is copied, renamed or deleted, every move is recorded so Premiere projects can be
         relinked, a tidy-up can be put back, and folders left empty are removed.</p>
      <div class="views" role="tablist"><button type="button" data-view="drag">Drag and drop</button><button type="button" data-view="list">List</button></div>
      <div id="tWait" class="banner warn" hidden><div class="txt"></div></div>

      <!-- ══ Drag and drop: folders from the left onto a <?= $e($one) ?> on the right. Nothing moves until Move. ══ -->
      <div id="dragView" hidden>
        <div class="dv">
          <div class="pane" id="dvL"><header><b>To be filed</b><small>What is in the archive and not on the shelf yet. Open a folder to see its folders; drag any of them to the right.</small></header>
            <div id="dvTree"><p class="note" style="margin:10px 12px">Looking &hellip;</p></div></div>
          <div class="pane" id="dvR"><header><b>How you want it &middot; <?= $e($shelf) ?></b><small>Drop a folder on a <?= $e($one) ?>. Drag it back to the left, or press &times;, to take it off.</small></header>
            <div id="dvDepts"></div></div>
        </div>
        <div class="btns" style="margin-top:14px"><button class="btn" type="button" id="dvAsk" disabled>Nothing placed yet</button>
          <span class="note" id="dvSum">Nothing moves until you press it, and it shows what it will do first.</span></div>
        <div id="dvSure" class="banner warn" hidden><div class="txt">
          <b id="dvSureWhat"></b> <span id="dvSureMore"></span>
          <div class="eg" id="dvEg"></div>
          The helper on <span class="tHelper"></span> moves them one file at a time, never over another file. Every move is written down and can be put back.
          <div class="btns" style="margin-top:10px">
            <button class="btn" type="button" id="dvYes">Yes, move them</button>
            <button class="btn quiet" type="button" id="dvNo">Not yet</button>
          </div></div></div>
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
      // placed: the folder itself or one above it is on the right (then it is not on the left any more)
      var placed = function (rel) { for (var r = rel; r; r = parent(r)) if (plan[r]) return true; return false; };
      var post = function (body) {
        return fetch('/db/tidy.php', {method: 'POST', body: body}).then(function (r) { return r.json(); })
          .then(function (d) { if (d.error) throw new Error(d.error); return d; });
      };
      var ask = function (what) { var f = new FormData(); Object.keys(what).forEach(function (k) { f.append(k, what[k]); }); return post(f); };

      function place(rel, dept, info, by) {
        // a folder whose own name says a department gives up its name (PARKS → Parks & Recreation); any other keeps it
        var named = info && info.dept;
        plan[rel] = {dept: dept, keep: !named, n: (info && info.n) || 0, b: (info && info.b) || 0, by: by || 'you'};
        delete no[rel]; save(); draw();
      }
      function unplace(rel) { if (plan[rel] && plan[rel].by === 'rushes') no[rel] = 1; delete plan[rel]; save(); draw(); }

      function node(f, depth) {
        if (f.name === '') return '<div class="nd loose" style="padding-left:' + (10 + depth * 16) + 'px">' + num(f.n) + ' loose file' + (f.n > 1 ? 's' : '') + ' &middot; they stay where they are</div>';
        if (placed(f.rel)) return '';
        var o = open[f.rel], k = kids[f.rel];
        return '<div class="nd" draggable="true" data-rel="' + esc(f.rel) + '" style="padding-left:' + (10 + depth * 16) + 'px">'
          + '<button type="button" class="tw" data-open="' + esc(f.rel) + '" aria-label="Open">' + (o ? '▾' : '▸') + '</button>'
          + '<span class="nm">' + esc(f.name) + '</span><small>' + num(f.n) + ' files &middot; ' + size(f.b) + '</small>'
          + (f.dept && !no[f.rel] ? '<button type="button" class="sg" data-put="' + esc(f.rel) + '" data-dept="' + esc(f.dept) + '" title="Place it there">&rarr; ' + esc(f.dept) + (f.sure ? '' : '?') + '</button>' : '')
          + '<button type="button" class="mv" data-to="' + esc(f.rel) + '">Move to&hellip;</button></div>'
          + (o ? (k ? k.map(function (c) { return node(c, depth + 1); }).join('') || '' : '<div class="nd loose" style="padding-left:' + (26 + depth * 16) + 'px">opening &hellip;</div>') : '');
      }
      function info(rel) {             // what is known of a folder: from the tree or from the suggestions
        var k = kids[parent(rel)]; var f = k && k.filter(function (x) { return x.rel === rel; })[0];
        if (f) return f;
        var m = sugg.filter(function (x) { return x.rel === rel; })[0];
        return m ? {n: m.n, b: m.b, dept: m.dept} : null;
      }

      function draw() {
        $('dvTree').innerHTML = kids[''] ? (kids[''].map(function (f) { return node(f, 0); }).join('')
          || '<p class="note" style="margin:10px 12px">Nothing waits to be filed.</p>') : '<p class="note" style="margin:10px 12px">Looking &hellip;</p>';
        var n = 0, b = 0, c = 0;
        $('dvDepts').innerHTML = depts.map(function (d) {
          var here = Object.keys(plan).filter(function (r) { return plan[r].dept === d; }).sort();
          var maybe = sugg.filter(function (m) { return m.dept === d && !m.sure && !plan[m.rel] && !no[m.rel] && !placed(m.rel); });
          return '<div class="db" data-dept="' + esc(d) + '"><b>' + esc(d) + '</b>'
            + (!here.length && !maybe.length ? '<div class="dz">drop folders here</div>' : '')
            + here.map(function (r) {
              var p = plan[r]; n += p.n; b += p.b; c++;
              return '<div class="pl" draggable="true" data-rel="' + esc(r) + '"><span class="nm">' + esc(last(r))
                + '<small>from ' + esc(parent(r).split('/').join(' / ') || 'the top') + (p.n ? ' &middot; ' + num(p.n) + ' files &middot; ' + size(p.b) : '')
                + (p.by === 'rushes' ? ' &middot; placed by Rushes: the same name' : '') + '</small>'
                + '<small>' + (p.keep ? '&rarr; ' + esc(d) + ' / year / ' + esc(last(r)) + ' / &hellip;' : '&rarr; what is in it goes into ' + esc(d) + ', each folder by its year') + '</small></span>'
                + '<label><input type="checkbox" data-keep="' + esc(r) + '"' + (p.keep ? ' checked' : '') + '> keep its name</label>'
                + '<button type="button" class="x" data-off="' + esc(r) + '" title="Take it off">&times;</button></div>';
            }).join('')
            + maybe.map(function (m) {
              return '<div class="pl sug"><span class="nm">' + esc(last(m.rel)) + '?<small>looks like ' + esc(d) + ' &middot; in ' + esc(parent(m.rel).split('/').join(' / ')) + ' &middot; ' + num(m.n) + ' files &middot; ' + size(m.b) + '</small></span>'
                + '<button type="button" class="btn quiet sm" data-yes="' + esc(m.rel) + '">Yes, here</button>'
                + '<button type="button" class="x" data-nah="' + esc(m.rel) + '" title="Not this one">&times;</button></div>';
            }).join('') + '</div>';
        }).join('');
        $('dvAsk').disabled = !c;
        $('dvAsk').textContent = c ? 'Move ' + (n ? num(n) + ' files (' + size(b) + ') from ' : '') + c + ' folder' + (c > 1 ? 's' : '') + '…' : 'Nothing placed yet';
        $('dvSure').hidden = true;
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
        if ((x = t.closest('[data-off]'))) return unplace(x.dataset.off);
        if ((x = t.closest('[data-to]'))) {
          var sel = document.createElement('select');
          sel.innerHTML = '<option value="">Move to…</option>' + depts.map(function (d) { return '<option>' + esc(d) + '</option>'; }).join('');
          sel.onchange = function () { if (sel.value) place(x.dataset.to, sel.value, info(x.dataset.to)); };
          x.replaceWith(sel); sel.focus();
        }
      });
      $('dragView').addEventListener('change', function (ev) {
        var r = ev.target.dataset && ev.target.dataset.keep;
        if (r && plan[r]) { plan[r].keep = ev.target.checked; save(); draw(); }
      });
      // dragging: from the left onto a department, between departments, or back to the left
      $('dragView').addEventListener('dragstart', function (ev) {
        var x = ev.target.closest('[data-rel]'); if (!x) return;
        dragging = x.dataset.rel; ev.dataTransfer.setData('text/plain', dragging); ev.dataTransfer.effectAllowed = 'move';
      });
      var target = function (ev) { return ev.target.closest('.db') || ev.target.closest('#dvL'); };
      $('dragView').addEventListener('dragover', function (ev) {
        var t = target(ev); if (!t || !dragging) return;
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

      var drops = function () { var o = {}; Object.keys(plan).forEach(function (r) { o[r] = {dept: plan[r].dept, keep: plan[r].keep}; }); return JSON.stringify(o); };
      $('dvAsk').onclick = function () {
        var b = this; b.disabled = true; b.textContent = 'Working out where each file goes…';
        ask({drag: 1, drops: drops()}).then(function (d) {
          $('dvSureWhat').textContent = 'Move ' + num(d.files) + ' files (' + size(d.bytes) + ') onto ' + <?= json_encode($shelf, JSON_UNESCAPED_UNICODE) ?> + '?';
          var yrs = 0; Object.keys(d.per).forEach(function (r) { yrs += d.per[r].years.length; });
          $('dvSureMore').textContent = Object.keys(d.per).length + ' folders, into ' + yrs + ' year folders where their clips agree on one. For example:';
          $('dvEg').innerHTML = d.eg.map(function (e) { return esc(e[0]) + '<br>&nbsp;&nbsp;&rarr; ' + esc(e[1]); }).join('<br>');
          $('dvSure').hidden = false; $('dvYes').focus(); draw(); $('dvSure').hidden = false;
        }).catch(function (e) { draw(); $('tWait').hidden = false; $('tWait').className = 'banner bad'; $('tWait').querySelector('.txt').innerHTML = '<b>Nothing was worked out.</b> ' + esc(e.message); });
      };
      $('dvNo').onclick = function () { $('dvSure').hidden = true; };
      $('dvYes').onclick = function () {
        var b = this; b.disabled = true; b.textContent = 'Queuing…';
        ask({drag: 1, go: 1, drops: drops()}).then(function (d) {
          b.disabled = false; b.textContent = 'Yes, move them';
          plan = {}; save(); kids = {}; open = {};
          $('tWait').className = 'banner ok'; $('tWait').hidden = false;
          $('tWait').querySelector('.txt').innerHTML = '<b>Queued ✓</b> ' + num(d.files) + ' files. The helper moves them next &mdash; watch the top bar. Once moved, they leave the left side.';
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
          if (!busy) sugg.forEach(function (m) { if (m.sure && !plan[m.rel] && !no[m.rel]) plan[m.rel] = {dept: m.dept, keep: false, n: m.n, b: m.b, by: 'rushes'}; });
          Object.keys(plan).forEach(function (r) { if (depts.indexOf(plan[r].dept) < 0) delete plan[r]; });   // a department taken out of Setup
          save(); draw();
        }).catch(function (e) { $('dvDepts').innerHTML = '<div class="banner bad" style="margin:10px"><div class="txt">' + esc(e.message) + '</div></div>'; });
        ask({tree: ''}).then(function (d) { kids[''] = d.folders; draw(); })
          .catch(function (e) { $('dvTree').innerHTML = '<div class="banner bad" style="margin:10px"><div class="txt"><b>Could not read the archive&rsquo;s folders.</b> ' + esc(e.message) + '</div></div>'; });
      }

      // the two views: Drag and drop first; the list (every row worked out at once) when asked for
      var listRead = false;
      function show(v) {
        try { localStorage.setItem('rushes-reorg-view', v); } catch (e) {}
        document.querySelectorAll('.views button').forEach(function (b) { b.classList.toggle('on', b.dataset.view === v); });
        $('dragView').hidden = v !== 'drag'; $('listView').hidden = v !== 'list';
        window.tidyRefresh = v === 'drag' ? load : window.tidyList;
        if (v === 'list' && !listRead) { listRead = true; window.tidyList(); }
      }
      document.querySelectorAll('.views button').forEach(function (b) { b.onclick = function () { show(b.dataset.view); }; });
      var v = 'drag'; try { v = localStorage.getItem('rushes-reorg-view') || 'drag'; } catch (e) {}
      show(v); load();
    })();
    </script>
  </div>
  </main>
<?php $SIDE_SELF = true; require __DIR__ . '/db/side.php'; ?>
</div>
</div>
