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
  .rsum { font-size: 13px; margin: 0 0 10px; color: var(--muted) }
  .rgrp { border: 1px solid var(--line); border-radius: 10px; margin-top: 12px; background: var(--bg); overflow: hidden }
  .rrow { display: grid; grid-template-columns: 22px 1fr 1fr; gap: 10px; padding: 7px 12px; border-top: 1px solid var(--line-soft); font-size: 12.5px; align-items: start }
  .rrow .p { word-break: break-all } .rrow small { display: block; color: var(--muted) } .bad { color: var(--bad) } .warn { color: var(--warn) }
  /* the three groups, as tiles: one open at a time */
  .rtiles { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin: 6px 0 12px }
  .rtile { text-align: left; font: inherit; color: var(--fg); background: var(--bg); border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; cursor: pointer }
  .rtile b { display: block; font-size: 22px; font-weight: 600 } .rtile span { font-size: 12.5px; color: var(--muted) }
  .rtile.on { border-color: var(--accent); box-shadow: inset 0 0 0 1px var(--accent) }
  .rtile[data-g="sure"] b { color: var(--ok, var(--accent)) } .rtile[data-g="maybe"] b { color: var(--warn) } .rtile[data-g="none"] b { color: var(--bad) }
  .rwhy { font-size: 12.5px; color: var(--muted); margin: 0 0 10px; max-width: 900px }
  /* the folders, as in Reorganize: closed, opened with ▸ */
  .rt { border: 1px solid var(--line); border-radius: 10px; background: var(--bg); padding: 4px 0 }
  .rt .r { display: flex; align-items: center; gap: 6px; padding: 3px 10px; font-size: 13px; min-height: 26px }
  .rt .r:hover { background: var(--line-soft) }
  .rt .tw { background: none; border: 0; color: var(--muted); cursor: pointer; font-size: 13px; width: 18px; padding: 0; flex: none }
  .rt .nm { flex: 1 1 0; min-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
  .rt .d .nm { font-weight: 500 } .rt .n { color: var(--muted); font-size: 12px; flex: none }
  .rt .src { flex: 0 1 40%; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--muted); font-size: 12px; text-align: right }
  .rt .rm { visibility: hidden; flex: none; font-size: 12px } .rt .r:hover .rm { visibility: visible }
  .rt .why { flex: none; font-size: 12px }
  @media (max-width: 700px) { .rtiles { grid-template-columns: 1fr } .rt .src { display: none } }
  .chip { flex: none; font-size: 11.5px; padding: 1px 7px; border-radius: 9px; border: 1px solid var(--line); color: var(--muted) }
  .chip.ok { color: var(--ok, var(--accent)); border-color: currentColor } .chip.run { color: var(--accent-text, var(--accent)); border-color: currentColor }
  .chip.bad { color: var(--bad); border-color: currentColor; max-width: 340px; flex: 0 1 auto; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
  #rTop .banner { margin-bottom: 10px; align-items: center } #rTop .banner .btn { margin-left: auto; flex: none }
  .rprog { height: 5px; border-radius: 3px; background: var(--line-soft); margin-top: 8px; overflow: hidden } .rprog i { display: block; height: 100%; background: var(--accent) }
  .rdone { font-size: 12px; color: var(--muted); margin-top: 18px } .rdone div { word-break: break-all; padding: 2px 0 }
</style>

<div class="app">
<div class="with-rail with-side">
<?php $RAIL = 'rescue'; require __DIR__ . '/db/rail.php'; ?>
  <main class="work">
  <div class="pad form">
    <div id="dvBar"></div>
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
    <div id="rTop"></div>
    <div class="rbar rAct" hidden>
      <button class="btn rGo" type="button" disabled>Rescue the ticked</button>
      <button class="btn quiet rAll" type="button">Tick all</button>
    </div>
    <p class="note" id="rMsg" aria-live="polite"></p>
    <div id="rOut"><p class="note">Looking at what was found…</p></div>
    <div class="rbar rAct" hidden>
      <button class="btn rGo" type="button" disabled>Rescue the ticked</button>
      <button class="btn quiet rAll" type="button">Tick all</button>
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
  var ticked = {}, last = null, grp = null, open = {}, menu = null, pending = null;
  // a rescue asked or running: each picked file's state, said on its own row (never a silent wait)
  var picked = function (d) { return d.run && d.run.picked.length ? d.run.picked : pending || []; };
  var stateOf = function (d, p) {
    var r = d.run || {};
    return (r.done || []).indexOf(p) >= 0 ? 'done' : r.failed && r.failed[p] !== undefined ? 'failed' : r.at === p ? 'now' : picked(d).indexOf(p) >= 0 ? 'wait' : '';
  };
  var CHIP = { done: '<span class="chip ok">✓ rescued</span>', now: '<span class="chip run"><span class="spin"></span>rescuing now</span>', wait: '<span class="chip">waiting</span>' };
  var G = {
    sure: ['Ready to rescue', 'Rushes found the same file: where it was copied from, one in a folder of the same name, or a name no camera gives with one size everywhere. Rescue copies it in and checks it byte for byte; the broken one goes to Recently Removed.'],
    maybe: ['Check first', 'Only a file with the same name was found. Cameras name clips the same on every card (Clip0004, DJI_0128), so it may be another shoot: compare before ticking. Hover a file to see the whole path of both.'],
    none: ['No copy found', 'Nothing anywhere Rushes can see. What you do not need: tick it, or a whole folder, and Remove. It goes to Recently Removed (Undo in Tidy up brings it back) until Delete All.']
  };
  var kind = function (r) { return r.sure ? 'sure' : r.good ? 'maybe' : 'none'; };
  var short = function (w) { return /^empty/.test(w) ? 'empty' : /^too small/.test(w) ? 'too small' : /^cannot/.test(w) ? 'unreadable' : 'damaged'; };
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
    if (!d.looked) { $('rTop').innerHTML = ''; $('rOut').innerHTML = '<p class="note">Press Look for broken files: Rushes reads the first bytes of every clip and sidecar in the archive. Nothing is changed.</p>'; acts(true); return; }
    // a server that could not be read is said out loud: its copies are missing from the groups below
    var unread = Object.keys(d.ssh_read || {}).filter(function (k) { return d.ssh_read[k].read < d.ssh_read[k].want; }).map(function (k) { var x = d.ssh_read[k];
      return '<div class="banner warn"><div class="txt"><b>' + esc(k) + ': read ' + x.read.toLocaleString() + ' of ' + x.want.toLocaleString() + ' files there</b>The ones not read are not offered as good copies, so some files below may have one on ' + esc(k) + '.' + (x.why ? ' ssh said: ' + esc(x.why) : '') + ' Look for broken files again to try again.</div></div>'; }).join('');
    if (!d.total) { $('rTop').innerHTML = unread; $('rOut').innerHTML = '<div class="banner ok"><div class="txt"><b>No broken files found ✓</b>Every clip and sidecar looked at is what it says it is.</div></div>'; acts(true); return; }
    var n = { sure: 0, maybe: 0, none: 0 }; d.rows.forEach(function (r) { if (stateOf(d, r.path) !== 'done') n[kind(r)]++; });
    // the run, above everything: asked (waiting for the runner), how far, or how it ended
    var pk = picked(d), R = d.run || {}, nd = (R.done || []).length, nf = Object.keys(R.failed || {}).length, L = R.last, strip = '';
    if (pk.length && d.running !== 'rescuing') strip = '<div class="banner"><div class="txt"><b><span class="spin"></span> ' + (d.run.picked.length ? 'Asked ✓ ' : 'Asking… ') + pk.length.toLocaleString() + ' file' + (pk.length === 1 ? '' : 's') + ' to rescue</b>' +
        'Waiting for Rushes to start it: it looks for new work every minute' + (d.other ? ', after what is running now (the top bar says what)' : '') + '. Each file below says when its turn comes.</div></div>';
    else if (pk.length) strip = '<div class="banner"><div class="txt"><b><span class="spin"></span> Rescuing ' + Math.min(nd + nf + 1, pk.length).toLocaleString() + ' of ' + pk.length.toLocaleString() + '</b>' +
        nd.toLocaleString() + ' rescued' + (nf ? ' · <span class="bad">' + nf.toLocaleString() + ' could not</span>' : '') + '. Each good copy is copied in and read back before the broken one is moved aside.' +
        '<div class="rprog"><i style="width:' + Math.round(100 * (nd + nf) / pk.length) + '%"></i></div></div>' +
        '<button class="btn quiet" type="button" id="rStop"' + (d.stopping ? ' disabled>Stopping after the file it is on…' : '>Stop') + '</button></div>';
    else if (L && L.at >= d.looked) strip = '<div class="banner ' + (L.failed || L.stopped ? 'warn' : 'ok') + '"><div class="txt"><b>Rescued ' + L.done.toLocaleString() + ' of ' + L.asked.toLocaleString() + (L.failed || L.stopped ? '' : ' ✓') + '</b>' +
        (L.failed ? L.failed.toLocaleString() + ' could not: they are marked ✗ below, with why. ' : '') + (L.stopped ? 'Stopped before the rest: they are still here, tick them and Rescue again. ' : '') +
        'The broken ones are in Recently Removed until Delete All. ' + esc(when(L.at)) + '</div></div>';
    if (!grp || !n[grp]) grp = ['sure', 'maybe', 'none'].filter(function (k) { return n[k]; })[0] || 'sure';
    $('rTop').innerHTML = strip + unread + '<div class="rtiles">' + ['sure', 'maybe', 'none'].map(function (k) {
        return '<button type="button" class="rtile' + (k === grp ? ' on' : '') + '" data-g="' + k + '"><b>' + n[k].toLocaleString() + '</b><span>' + G[k][0] + '</span></button>'; }).join('') + '</div>' +
      '<p class="rwhy">' + esc(G[grp][1]) + '</p>' +
      (d.rows.length < d.total ? '<p class="note">The first ' + d.rows.length.toLocaleString() + ' of ' + d.total.toLocaleString() + ' are shown; look again for the rest.</p>' : '');
    $('rTop').querySelectorAll('.rtile').forEach(function (t) { t.onclick = function () { grp = t.dataset.g; draw(last); }; });
    // the group's files as folders: a chain of folders that each hold only one folder is one line
    var root = { kids: {}, files: [] };
    d.rows.filter(function (r) { return kind(r) === grp; }).forEach(function (r) {
      var parts = r.rel.split('/'), x = root;
      parts.slice(0, -1).forEach(function (q) { x = x.kids[q] = x.kids[q] || { kids: {}, files: [] }; });
      x.files.push(r);
    });
    var all = function (x) { var o = x.files.slice(); Object.keys(x.kids).forEach(function (k) { o = o.concat(all(x.kids[k])); }); return o; };
    var many = all(root).length > 30;
    function rows(x, rel, depth) {
      var h = '';
      Object.keys(x.kids).sort().forEach(function (k) {
        var name = k, y = x.kids[k], r2 = rel ? rel + '/' + k : k;
        var chain = [r2];                      // the folders on this one line, outermost first: each can be removed
        while (!y.files.length && Object.keys(y.kids).length === 1) { var only = Object.keys(y.kids)[0]; name += ' / ' + only; r2 += '/' + only; y = y.kids[only]; chain.push(r2); }
        var fs = all(y), t = fs.filter(function (r) { return ticked[r.path]; }).length, o = open[grp + ':' + r2] !== undefined ? open[grp + ':' + r2] : !many;
        h += '<div class="r d" data-chain="' + esc(JSON.stringify(chain)) + '" style="padding-left:' + (10 + depth * 18) + 'px"><button type="button" class="tw" data-o="' + esc(r2) + '">' + (o ? '▾' : '▸') + '</button>' +
          '<input type="checkbox" data-dt="' + esc(r2) + '"' + (t && t === fs.length ? ' checked' : '') + (t && t < fs.length ? ' data-mixed="1"' : '') + '>' +
          '<span class="nm" title="' + esc(r2) + '">📁 ' + esc(name) + '</span><span class="n">' + fs.length.toLocaleString() + '</span>' +
          '<button type="button" class="lnk rm">Remove…</button></div>';
        if (o) h += rows(y, r2, depth + 1);
      });
      x.files.sort(function (a, b) { return a.rel < b.rel ? -1 : 1; }).forEach(function (r) {
        var nm = r.rel.split('/').pop();
        h += '<label class="r" style="padding-left:' + (28 + depth * 18) + 'px" title="' + esc(r.rel + '\n' + r.why + (r.good ? '\ngood copy: ' + r.show + ' (' + r.where + ')' : '') + (r.came ? '\ncopied from: ' + r.came : '')) + '">' +
          '<input type="checkbox" data-p="' + esc(r.path) + '"' + (ticked[r.path] || /wait|now|done/.test(stateOf(d, r.path)) ? ' checked' : '') + (/wait|now|done/.test(stateOf(d, r.path)) ? ' disabled' : '') + '>' +
          '<span class="nm">' + esc(nm) + '</span>' + (CHIP[stateOf(d, r.path)] || (stateOf(d, r.path) === 'failed' ? '<span class="chip bad" title="Why: ' + esc(d.run.failed[r.path]) + '">✗ could not: ' + esc(d.run.failed[r.path]) + '</span>' : '')) +
          '<span class="why bad">' + short(r.why) + '</span>' +
          (r.good ? '<span class="src">' + esc(r.where) + ' · ' + size(r.gsize) + (r.other ? ' · ' + (r.other + 1) + ' with this name' : '') + '</span>' : '') + '</label>';
      });
      return h;
    }
    $('rOut').innerHTML = '<div class="rt">' + rows(root, '', 0) + '</div>';
    $('rOut').querySelectorAll('input[data-mixed]').forEach(function (c) { c.indeterminate = true; });
    $('rOut').querySelectorAll('[data-o]').forEach(function (b) { b.onclick = function () { var k = grp + ':' + b.dataset.o; open[k] = b.textContent === '▸'; draw(last); }; });
    $('rOut').querySelectorAll('input[data-p]').forEach(function (c) { c.onchange = function () { if (c.checked) ticked[c.dataset.p] = 1; else delete ticked[c.dataset.p]; draw(last); }; });
    var under = function (rel) { return d.rows.filter(function (r) { return kind(r) === grp && r.rel.indexOf(rel + '/') === 0; }); };
    $('rOut').querySelectorAll('input[data-dt]').forEach(function (c) { c.onchange = function () {
      under(c.dataset.dt).forEach(function (r) { if (c.checked) ticked[r.path] = 1; else delete ticked[r.path]; }); draw(last); }; });
    // Remove…, or a right click: one choice per folder on the line (Picnic-App, app, node_modules), never guessed
    $('rOut').querySelectorAll('.r.d').forEach(function (r) {
      var ask = function (e) {
        e.preventDefault(); e.stopPropagation(); closeMenu();
        menu = document.createElement('div'); menu.className = 'fmenu';
        JSON.parse(r.dataset.chain).forEach(function (rel) {
          var m = document.createElement('button'); m.type = 'button'; m.textContent = 'Remove the whole folder “' + rel.split('/').pop() + '”';
          m.onclick = function () { closeMenu(); removeDir(rel); }; menu.appendChild(m); });
        document.body.appendChild(menu);
        menu.style.left = Math.min(e.clientX, innerWidth - menu.offsetWidth - 8) + 'px'; menu.style.top = Math.min(e.clientY, innerHeight - menu.offsetHeight - 8) + 'px';
      };
      r.oncontextmenu = ask; r.querySelector('.rm').onclick = ask;
    });
    acts(false); allShown();
    count();
    // short: the file, and the folder its good copy came from; the whole paths on hover
    $('rDone').innerHTML = d.done.length ? '<b>Rescued lately</b>' + d.done.map(function (x) {
      var g = String(x[1]).replace(/^ssh:([^:]+):/, '$1: '), f = g.split('/');
      return '<div title="' + esc(x[0] + '\nfrom ' + g) + '">✓ ' + esc(String(x[0]).split('/').pop()) + ' <span>· from ' + esc(f.length > 1 ? f[f.length - 2] : g) + '</span></div>'; }).join('') : '';
    if ($('rStop')) $('rStop').onclick = function () { var b = this; b.disabled = true; b.textContent = 'Asking…';
      fetch('/db/stop.php', { method: 'POST', body: new URLSearchParams({}) }).then(function (r) { return r.json(); })
        .then(function (x) { b.textContent = x.error ? 'Did not stop: ' + x.error : 'Stopping after the file it is on…'; load(); }); };
  }
  function closeMenu() { if (menu) { menu.remove(); menu = null; } }
  document.addEventListener('click', closeMenu); document.addEventListener('scroll', closeMenu, true);
  // removing is Tidy up's own (db/browse.php): into Recently Removed, its folders kept; Undo there brings it back
  var tidy = function (o) { return fetch('/db/browse.php', { method: 'POST', body: new URLSearchParams(o) }).then(function (r) { return r.json(); })
    .then(function (x) { if (x.error) throw new Error(x.error); return x; }); };
  function removed(x, what) {
    $('rMsg').innerHTML = 'Moved ✓ ' + what + ' to Recently Removed' + (x.files ? ' · ' + x.files.toLocaleString() + ' file' + (x.files === 1 ? '' : 's') + (x.bytes ? ', ' + size(x.bytes) : '') : '') +
      '. Nothing is deleted until Delete All. <button type="button" class="lnk" id="rUndo">Undo</button>' +
      (x.notes || []).map(function (t) { return '<br><span class="bad">' + esc(t) + '</span>'; }).join('');
    $('rUndo').onclick = function () { $('rUndo').disabled = true;
      tidy({ op: 'undo' }).then(function (u) { $('rMsg').textContent = 'Undone ✓ ' + u.said + '.'; load(); }).catch(function (e) { $('rMsg').textContent = 'Not undone: ' + e.message; }); };
    load();
  }
  function removeDir(rel) {
    $('rMsg').innerHTML = '<span class="spin"></span> Moving ' + esc(rel.split('/').pop()) + ' to Recently Removed…';
    tidy({ op: 'remove', items: JSON.stringify([last.archive + '/' + rel]) }).then(function (x) { removed(x, '<b>' + esc(rel.split('/').pop()) + '</b> (the whole folder)'); })
      .catch(function (e) { $('rMsg').textContent = 'Not removed: ' + e.message; });
  }
  var acts = function (hide) { document.querySelectorAll('.rAct').forEach(function (x) { x.hidden = hide; }); };
  var mine = function () { return last ? last.rows.filter(function (r) { return kind(r) === grp && ticked[r.path]; }) : []; };
  function count() {
    var n = mine().length, rm = grp === 'none';
    document.querySelectorAll('.rGo').forEach(function (b) {
      b.disabled = !n || !!(last && (last.running || last.asked || picked(last).length));
      b.classList.toggle('danger', rm);
      b.textContent = (rm ? 'Remove' : 'Rescue') + ' ' + (n ? n.toLocaleString() + ' file' + (n === 1 ? '' : 's') : 'the ticked'); });
  }
  // Check first has no Tick all: each is looked at before it is ticked
  var allShown = function () { document.querySelectorAll('.rAll').forEach(function (b) { b.hidden = grp === 'maybe'; }); };
  document.querySelectorAll('.rAll').forEach(function (b) { b.onclick = function () {
    var g = (last ? last.rows : []).filter(function (r) { return kind(r) === grp; }), on = g.every(function (r) { return ticked[r.path]; });
    g.forEach(function (r) { if (on) delete ticked[r.path]; else ticked[r.path] = 1; }); draw(last); }; });
  $('rLook').onclick = function () {
    $('rLook').disabled = true; $('rSaid').innerHTML = '<span class="spin"></span> Asking…';
    post({ action: 'scan' }).then(load).catch(function (e) { $('rSaid').textContent = 'Did not happen: ' + e.message; $('rLook').disabled = false; });
  };
  document.querySelectorAll('.rGo').forEach(function (b) { b.onclick = go; });
  function go() {
    var ps = mine().map(function (r) { return r.path; }); if (!ps.length) return;
    if (grp === 'none') {                      // Remove: the ticked files, a batch at a time (Tidy up's own Remove)
      document.querySelectorAll('.rGo').forEach(function (b) { b.disabled = true; });
      var all = { files: 0, bytes: 0, notes: [] }, i = 0;
      (function next() {
        if (i >= ps.length) { ps.forEach(function (p) { delete ticked[p]; }); return removed({ files: all.files, bytes: all.bytes, notes: all.notes }, ps.length.toLocaleString() + ' file' + (ps.length === 1 ? '' : 's')); }
        $('rMsg').innerHTML = '<span class="spin"></span> Moving to Recently Removed… ' + i.toLocaleString() + ' of ' + ps.length.toLocaleString();
        tidy({ op: 'remove', items: JSON.stringify(ps.slice(i, i + 400)) }).then(function (x) {
          all.files += x.files || 0; all.bytes += x.bytes || 0; all.notes = all.notes.concat(x.notes || []); i += 400; next();
        }).catch(function (e) { $('rMsg').textContent = 'Stopped after ' + i.toLocaleString() + ': ' + e.message; load(); });
      })();
      return;
    }
    // said at once, on the rows themselves: they stay ticked, each says "waiting", and the strip above says why
    pending = ps; draw(last);
    post({ action: 'rescue', paths: JSON.stringify(ps) }).then(function () {
      ps.forEach(function (p) { delete ticked[p]; }); load().then(function () { pending = null; });
    }).catch(function (e) { pending = null; draw(last); $('rMsg').innerHTML = '<span class="bad">Not asked: ' + esc(e.message) + '</span>'; });
  }
  function load() { return fetch('/db/rescue.php?t=' + Date.now()).then(function (r) { return r.json(); }).then(draw)
    .catch(function (e) { $('rOut').innerHTML = '<div class="banner bad"><div class="txt">' + esc(e.message) + '</div></div>'; }); }
  window.driveBar($('dvBar'), <?= json_encode(rtrim(archive_dir(), '/')) ?>, 'rescue');      // Rescue looks after the archive
  load(); setInterval(function () { if (last && (last.running || last.asked || picked(last).length || (last.servers || []).some(function (x) { return !x.state || /testing|listing|asked/.test(x.state.state); }))) load(); }, 5000);
})();
</script>
