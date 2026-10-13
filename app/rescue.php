<?php
// rescue.php — Rescue: broken copies in the archive, and the good ones brought back (HOW-IT-WORKS.md → Rescue).
// Looking changes nothing. Rescuing replaces only what you tick, each from a good copy read back byte for byte;
// the broken file goes to Recently Removed. The work is the runner's (db/rescue.php asks it).
$NAV = 'admin';
require_once __DIR__ . '/db/config.php';
require_once __DIR__ . '/db/auth.php';
require_sign_in();
$e = fn($x) => htmlspecialchars((string)$x);
$tip = fn($t) => '<span class="infotip" tabindex="0" data-tip="' . htmlspecialchars($t, ENT_QUOTES) . '">i</span>';
?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rescue &middot; <?= $e(settings()['name'] ?? 'Rushes') ?></title>
<?php require __DIR__ . '/head.php'; ?>
<style>
  .rbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin: 4px 0 14px }
  .rsum { font-size: 14px; margin: 0 0 10px }
  .rgrp { border: 1px solid var(--line); border-radius: 10px; margin-top: 12px; background: var(--bg); overflow: hidden }
  .rgrp > header { display: flex; gap: 10px; align-items: center; padding: 9px 12px; border-bottom: 1px solid var(--line-soft); font-size: 13px }
  .rgrp > header b { flex: 1 }
  .rrow { display: grid; grid-template-columns: 22px 1fr 1fr; gap: 10px; padding: 7px 12px; border-top: 1px solid var(--line-soft); font-size: 12.5px; align-items: start }
  .rrow:first-of-type { border-top: 0 }
  .rrow .p { word-break: break-all } .rrow small { display: block; color: var(--muted) } .rrow .bad { color: var(--bad) }
  .rrow .none { color: var(--muted); font-style: italic }
  .rdone { font-size: 12px; color: var(--muted); margin-top: 18px } .rdone div { word-break: break-all; padding: 2px 0 }
</style>

<div class="app">
<div class="with-rail with-side">
<?php $RAIL = 'rescue'; require __DIR__ . '/db/rail.php'; ?>
  <main class="work">
  <div class="pad form">
    <div class="head"><h1>Rescue</h1>
      <span class="sub">Broken copies in the archive, and good ones brought back. <?= $tip('Rushes reads the first bytes of every clip and sidecar in the archive: an empty clip, one too small to be a clip, or one with another file’s data inside (as a damaged disk or a broken copy leaves them) is broken. For each, it says where it came from and looks for a good copy: where it was copied from, or a file of the same name elsewhere in Search, on the archive or a drive. Looking changes nothing. Rescue replaces only what you tick: the good copy is copied in and read back byte for byte, and the broken one goes to Recently Removed.') ?></span></div>

    <div class="rbar">
      <button class="btn" type="button" id="rLook">Look for broken files</button>
      <span class="note" id="rSaid"></span>
    </div>
    <div id="rOut"><p class="note">Looking at what was found…</p></div>
    <div class="rbar" id="rAct" hidden>
      <button class="btn" type="button" id="rGo" disabled>Rescue the ticked</button>
      <button class="btn quiet" type="button" id="rAll">Tick all with a good copy</button>
    </div>
    <div class="rdone" id="rDone"></div>
  </div>
  </main>
<?php $SIDE_SELF = true; require __DIR__ . '/db/side.php'; ?>
</div>
</div>
<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
  var size = function (b) { return b === 0 ? 'Zero bytes' : b >= 1e12 ? (b / 1e12).toFixed(1) + ' TB' : b >= 1e9 ? (b / 1e9).toFixed(1) + ' GB' : b >= 1e6 ? Math.round(b / 1e6) + ' MB' : Math.max(1, Math.round(b / 1e3)) + ' KB'; };
  var when = function (t) { return t ? new Date(t * 1000).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : ''; };
  var post = function (o) { return fetch('/db/rescue.php', { method: 'POST', body: new URLSearchParams(o) }).then(function (r) { return r.json(); })
    .then(function (d) { if (d.error) throw new Error(d.error); return d; }); };
  var ticked = {}, last = null;
  // where a broken file came from, as a group: the source's drive and top folder, or "not copied by Rushes"
  var from = function (c) { if (!c) return 'Not copied by Rushes (no record of where it came from)'; var p = c.split('/'); return 'Copied from ' + p.slice(0, p[1] === 'Volumes' ? 4 : 3).join('/'); };
  function draw(d) {
    last = d;
    var busy = d.running || d.asked;
    window.rushesBusy = !!busy;
    $('rLook').disabled = !!busy;
    $('rSaid').innerHTML = d.running === 'looking' ? '<span class="spin"></span> Looking at every clip and sidecar in the archive… the top bar says how far.'
      : d.running === 'rescuing' ? '<span class="spin"></span> Rescuing: each good copy is copied in and read back… the top bar says how far.'
      : d.asked ? '<span class="spin"></span> Asked: it starts within a minute.'
      : d.looked ? 'Last looked ' + esc(when(d.looked)) + ' · ' + d.looked_n.toLocaleString() + ' files' : 'Not looked yet.';
    if (!d.looked) { $('rOut').innerHTML = '<p class="note">Press Look for broken files: Rushes reads the first bytes of every clip and sidecar in the archive. Nothing is changed.</p>'; $('rAct').hidden = true; return; }
    if (!d.total) { $('rOut').innerHTML = '<div class="banner ok"><div class="txt"><b>No broken files found ✓</b>Every clip and sidecar looked at is what it says it is.</div></div>'; $('rAct').hidden = true; return; }
    var g = {};
    d.rows.forEach(function (r) { (g[from(r.came)] = g[from(r.came)] || []).push(r); });
    $('rOut').innerHTML = '<p class="rsum"><b>' + d.total.toLocaleString() + ' broken file' + (d.total === 1 ? '' : 's') + '</b> · ' + d.with_good.toLocaleString() + ' with a good copy found' +
        (d.total - d.with_good ? ' · <span class="bad">' + (d.total - d.with_good).toLocaleString() + ' with none anywhere Rushes can see</span>' : '') + '</p>' +
      (d.rows.length < d.total ? '<p class="note">The first ' + d.rows.length.toLocaleString() + ' are shown; rescue them and look again for the rest.</p>' : '') +
      Object.keys(g).sort().map(function (k) {
        return '<div class="rgrp"><header><b>' + esc(k) + '</b><span class="note">' + g[k].length.toLocaleString() + ' broken</span></header>' +
          g[k].map(function (r) {
            return '<label class="rrow"><input type="checkbox" data-p="' + esc(r.path) + '"' + (r.good ? '' : ' disabled') + (ticked[r.path] ? ' checked' : '') + '>' +
              '<span class="p">' + esc(r.rel) + '<small class="bad">' + esc(r.why) + '</small></span>' +
              (r.good ? '<span class="p">' + esc(r.good) + '<small>a good copy · ' + size(r.gsize) + ' · on ' + esc(r.where) + (r.other ? ' · and ' + r.other + ' more' : '') + '</small></span>'
                      : '<span class="none">No good copy anywhere Rushes can see. Plug in a drive that may have it and add it to Rushes, then look again.</span>') + '</label>';
          }).join('') + '</div>';
      }).join('');
    $('rAct').hidden = false;
    $('rOut').querySelectorAll('input[data-p]').forEach(function (c) { c.onchange = function () { if (c.checked) ticked[c.dataset.p] = 1; else delete ticked[c.dataset.p]; count(); }; });
    count();
    $('rDone').innerHTML = d.done.length ? '<b>Rescued lately</b>' + d.done.map(function (x) { return '<div>✓ ' + esc(x[0]) + ' <span>from ' + esc(x[1]) + '</span></div>'; }).join('') : '';
  }
  function count() {
    var n = Object.keys(ticked).length;
    $('rGo').disabled = !n || !!(last && (last.running || last.asked));
    $('rGo').textContent = n ? 'Rescue ' + n.toLocaleString() + ' file' + (n === 1 ? '' : 's') : 'Rescue the ticked';
  }
  $('rAll').onclick = function () { (last ? last.rows : []).forEach(function (r) { if (r.good) ticked[r.path] = 1; }); draw(last); };
  $('rLook').onclick = function () {
    $('rLook').disabled = true; $('rSaid').innerHTML = '<span class="spin"></span> Asking…';
    post({ action: 'scan' }).then(load).catch(function (e) { $('rSaid').textContent = 'Did not happen: ' + e.message; $('rLook').disabled = false; });
  };
  $('rGo').onclick = function () {
    var ps = Object.keys(ticked); if (!ps.length) return;
    $('rGo').disabled = true; $('rSaid').innerHTML = '<span class="spin"></span> Asking…';
    post({ action: 'rescue', paths: JSON.stringify(ps) }).then(function (d) {
      ticked = {}; $('rSaid').innerHTML = 'Asked ✓ ' + d.n.toLocaleString() + ' file' + (d.n === 1 ? '' : 's') + ' to rescue: it starts within a minute, and the broken ones go to Recently Removed.'; load();
    }).catch(function (e) { $('rSaid').textContent = 'Did not happen: ' + e.message; count(); });
  };
  function load() { return fetch('/db/rescue.php?t=' + Date.now()).then(function (r) { return r.json(); }).then(draw)
    .catch(function (e) { $('rOut').innerHTML = '<div class="banner bad"><div class="txt">' + esc(e.message) + '</div></div>'; }); }
  load(); setInterval(function () { if (last && (last.running || last.asked)) load(); }, 5000);
})();
</script>
