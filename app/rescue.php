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
  .rrow .none { color: var(--muted); font-style: italic } .rrow small.warn, .rsum .warn { color: var(--warn) }
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
    <!-- a server that only answers over SSH (a NAS whose sharing is off): its files listed, looked at, read from -->
    <details class="rgrp" id="rSsh" style="padding:0"><summary style="padding:9px 12px;cursor:pointer;font-size:13px"><b>Also look on a server over SSH</b>
      <span class="note" id="rSshSum"></span> <?= $tip('For a server Finder cannot open but SSH can (a NAS whose file sharing is off). Rushes uses this Mac’s own SSH login, the one Terminal uses, so it never asks for or keeps a password. It lists the server’s files once (names and sizes only), looks there for good copies, and copies only what you rescue, checked by a fingerprint taken on both ends. Nothing on the server is ever changed.') ?></summary>
      <div style="padding:4px 12px 12px">
        <div id="rSshList"></div>
        <div class="rbar" style="margin:10px 0 0">
          <input type="text" id="sName" placeholder="Name, e.g. QNAP" style="width:140px">
          <input type="text" id="sHost" placeholder="user@address, as after ssh" style="width:230px">
          <input type="text" id="sPath" placeholder="/share/VIDEO" style="width:180px">
          <button class="btn quiet" type="button" id="sAdd">Add and list its files</button>
        </div>
      </div>
    </details>
    <div class="rbar rAct" hidden>
      <button class="btn rGo" type="button" disabled>Rescue the ticked</button>
      <button class="btn quiet rAll" type="button">Tick all sure ones</button>
    </div>
    <div id="rOut"><p class="note">Looking at what was found…</p></div>
    <div class="rbar rAct" hidden>
      <button class="btn rGo" type="button" disabled>Rescue the ticked</button>
      <button class="btn quiet rAll" type="button">Tick all sure ones</button>
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
  // the servers over SSH: each with what it last did (listed, failed and why, listing now)
  function servers(d) {
    var sv = d.servers || [];
    $('rSshSum').textContent = sv.length ? '· ' + sv.map(function (x) { return x.name; }).join(', ') : '';
    $('rSshList').innerHTML = sv.map(function (x) {
      var st = x.state || {};
      return '<div class="rrow" style="grid-template-columns:1fr auto auto;border:0;padding:6px 0"><span class="p"><b>' + esc(x.name) + '</b> · ' + esc(x.host) + ':' + esc(x.path) +
        '<small' + (st.state === 'failed' ? ' class="bad"' : '') + '>' +
        (st.state === 'listed' ? '✓ ' + (st.n || 0).toLocaleString() + ' files listed ' + esc(when(st.at)) + ': Look for broken files looks there too'
         : st.state === 'listing' ? '<span class="spin"></span> listing… ' + (st.n || 0).toLocaleString() + ' files so far'
         : st.state === 'testing' ? '<span class="spin"></span> connecting…'
         : st.state === 'asked' ? '<span class="spin"></span> asked: it starts within a minute' + (st.busy ? ', after what is running now (' + esc(st.busy) + ')' : '')
         : st.state === 'failed' ? 'Could not: ' + esc(st.why || '') + (/denied|password|key/i.test(st.why || '') ? ' — this Mac’s SSH login did not let Rushes in without a password'
             : /no route to host/i.test(st.why || '') ? ' — if Terminal reaches it, macOS is keeping Rushes off the local network: System Settings → Privacy & Security → Local Network → turn on Rushes, then List again'
             : /timed out|refused/i.test(st.why || '') ? ' — the server did not answer: is its cable connected, and does Terminal reach it?' : '')
         : 'not listed yet') + '</small></span>' +
        '<button class="btn quiet" type="button" data-sl="' + esc(x.name) + '">List again</button><button class="lnk" type="button" data-sf="' + esc(x.name) + '">Remove</button></div>';
    }).join('') || '<p class="note">None yet.</p>';
    $('rSshList').querySelectorAll('[data-sl]').forEach(function (b) { b.onclick = function () { b.disabled = true; b.textContent = 'Asked ✓';
      post({ action: 'ssh-list', name: b.dataset.sl }).then(load).catch(function (e) { $('rSaid').textContent = e.message; }); }; });
    $('rSshList').querySelectorAll('[data-sf]').forEach(function (b) { b.onclick = function () {
      post({ action: 'ssh-forget', name: b.dataset.sf }).then(load).catch(function (e) { $('rSaid').textContent = e.message; }); }; });
    if (sv.some(function (x) { return x.state && /testing|listing|asked|failed/.test(x.state.state); })) $('rSsh').open = true;
  }
  $('sAdd').onclick = function () {
    $('sAdd').disabled = true; $('sAdd').textContent = 'Asking…';
    post({ action: 'ssh-save', name: $('sName').value, host: $('sHost').value, path: $('sPath').value }).then(function () {
      $('sAdd').disabled = false; $('sAdd').textContent = 'Add and list its files'; $('sName').value = $('sHost').value = $('sPath').value = ''; load();
    }).catch(function (e) { $('sAdd').disabled = false; $('sAdd').textContent = 'Add and list its files'; $('rSaid').textContent = 'Not added: ' + e.message; });
  };
  function draw(d) {
    last = d; servers(d);
    var busy = d.running || d.asked;
    window.rushesBusy = !!busy;
    $('rLook').disabled = !!busy;
    $('rSaid').innerHTML = d.running === 'looking' ? '<span class="spin"></span> Looking at every clip and sidecar in the archive… the top bar says how far.'
      : d.running === 'rescuing' ? '<span class="spin"></span> Rescuing: each good copy is copied in and read back… the top bar says how far.'
      : d.asked ? '<span class="spin"></span> Asked: it starts within a minute.'
      : d.looked ? 'Last looked ' + esc(when(d.looked)) + ' · ' + d.looked_n.toLocaleString() + ' files' : 'Not looked yet.';
    if (!d.looked) { $('rOut').innerHTML = '<p class="note">Press Look for broken files: Rushes reads the first bytes of every clip and sidecar in the archive. Nothing is changed.</p>'; acts(true); return; }
    if (!d.total) { $('rOut').innerHTML = '<div class="banner ok"><div class="txt"><b>No broken files found ✓</b>Every clip and sidecar looked at is what it says it is.</div></div>'; acts(true); return; }
    var g = {};
    d.rows.forEach(function (r) { (g[from(r.came)] = g[from(r.came)] || []).push(r); });
    var none = d.total - d.with_good - d.maybe;
    $('rOut').innerHTML = '<p class="rsum"><b>' + d.total.toLocaleString() + ' broken file' + (d.total === 1 ? '' : 's') + '</b> · ' + d.with_good.toLocaleString() + ' with a good copy, for sure' +
        (d.maybe ? ' · <span class="warn">' + d.maybe.toLocaleString() + ' with a file of the same name only (may be another shoot: check before ticking)</span>' : '') +
        (none ? ' · <span class="bad">' + none.toLocaleString() + ' with none anywhere Rushes can see</span>' : '') + '</p>' +
      (d.rows.length < d.total ? '<p class="note">The first ' + d.rows.length.toLocaleString() + ' are shown; rescue them and look again for the rest.</p>' : '') +
      Object.keys(g).sort().map(function (k) {
        return '<div class="rgrp"><header><b>' + esc(k) + '</b><span class="note">' + g[k].length.toLocaleString() + ' broken</span></header>' +
          g[k].map(function (r) {
            return '<label class="rrow"><input type="checkbox" data-p="' + esc(r.path) + '"' + (r.good ? '' : ' disabled') + (ticked[r.path] ? ' checked' : '') + '>' +
              '<span class="p">' + esc(r.rel) + '<small class="bad">' + esc(r.why) + '</small></span>' +
              (r.good && r.sure ? '<span class="p">' + esc(r.show) + '<small>a good copy · ' + size(r.gsize) + ' · on ' + esc(r.where) + '</small></span>'
               : r.good ? '<span class="p">' + esc(r.show) + '<small class="warn">same name only · ' + size(r.gsize) + ' · on ' + esc(r.where) + (r.other ? ' · ' + (r.other + 1) + ' files have this name' : '') +
                  ': cameras name clips the same on every card, so this may be another shoot. Tick it only if you know it is the same.</small></span>'
                      : '<span class="none">No good copy anywhere Rushes can see. Plug in a drive that may have it and add it to Rushes, then look again.</span>') + '</label>';
          }).join('') + '</div>';
      }).join('');
    acts(false);
    $('rOut').querySelectorAll('input[data-p]').forEach(function (c) { c.onchange = function () { if (c.checked) ticked[c.dataset.p] = 1; else delete ticked[c.dataset.p]; count(); }; });
    count();
    $('rDone').innerHTML = d.done.length ? '<b>Rescued lately</b>' + d.done.map(function (x) { return '<div>✓ ' + esc(x[0]) + ' <span>from ' + esc(x[1]) + '</span></div>'; }).join('') : '';
  }
  var acts = function (hide) { document.querySelectorAll('.rAct').forEach(function (x) { x.hidden = hide; }); };
  function count() {
    var n = Object.keys(ticked).length;
    document.querySelectorAll('.rGo').forEach(function (b) {
      b.disabled = !n || !!(last && (last.running || last.asked));
      b.textContent = n ? 'Rescue ' + n.toLocaleString() + ' file' + (n === 1 ? '' : 's') : 'Rescue the ticked'; });
  }
  document.querySelectorAll('.rAll').forEach(function (b) { b.onclick = function () { (last ? last.rows : []).forEach(function (r) { if (r.sure) ticked[r.path] = 1; }); draw(last); }; });
  $('rLook').onclick = function () {
    $('rLook').disabled = true; $('rSaid').innerHTML = '<span class="spin"></span> Asking…';
    post({ action: 'scan' }).then(load).catch(function (e) { $('rSaid').textContent = 'Did not happen: ' + e.message; $('rLook').disabled = false; });
  };
  document.querySelectorAll('.rGo').forEach(function (b) { b.onclick = go; });
  function go() {
    var ps = Object.keys(ticked); if (!ps.length) return;
    document.querySelectorAll('.rGo').forEach(function (b) { b.disabled = true; }); $('rSaid').innerHTML = '<span class="spin"></span> Asking…';
    post({ action: 'rescue', paths: JSON.stringify(ps) }).then(function (d) {
      ticked = {}; $('rSaid').innerHTML = 'Asked ✓ ' + d.n.toLocaleString() + ' file' + (d.n === 1 ? '' : 's') + ' to rescue: it starts within a minute, and the broken ones go to Recently Removed.'; load();
    }).catch(function (e) { $('rSaid').textContent = 'Did not happen: ' + e.message; count(); });
  }
  function load() { return fetch('/db/rescue.php?t=' + Date.now()).then(function (r) { return r.json(); }).then(draw)
    .catch(function (e) { $('rOut').innerHTML = '<div class="banner bad"><div class="txt">' + esc(e.message) + '</div></div>'; }); }
  load(); setInterval(function () { if (last && (last.running || last.asked || (last.servers || []).some(function (x) { return !x.state || /testing|listing|asked/.test(x.state.state); }))) load(); }, 5000);
})();
</script>
