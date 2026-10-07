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
      <div id="tWait" class="banner warn" hidden><div class="txt"></div></div>
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
        var tail = r.base !== '' ? r.key.slice(r.base.length)
                 : '/' + (r.key.slice(r.root.length + 1) || r.root.split('/').pop());
        return (SHELF + '/' + d.folder + tail).split('/').join(' / ');
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
          done(d); load();
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
      load();
    })();
    </script>
  </div>
  </main>
<?php $SIDE_SELF = true; require __DIR__ . '/db/side.php'; ?>
</div>
</div>
