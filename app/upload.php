<?php
// upload.php — photos and video from a phone (or any computer) into a shoot
// folder, the way a card goes in (HOW-IT-WORKS.md → Upload from a phone).
//
// Who you are (your name, remembered on this phone), what the shoot was, and
// the files. They go up whole and unchanged, in checked pieces that carry on
// after a dropped connection; the helper then puts them in the archive like a
// card. At the end, a receipt: what went where, to keep or send to yourself.
$NAV = 'upload';
require __DIR__ . '/db/config.php';
$depts = shelf_name() === '' ? [] : array_column(departments(), 'name');
$S = settings();
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Upload &middot; <?= $e($S['name'] ?? 'Rushes') ?></title>
<?php require __DIR__ . '/head.php'; ?>
<style>
  .up { max-width: 720px; margin: 0 auto; padding: 22px 20px 64px; width: 100% }
  .up h1 { font-size: 22px; font-weight: 650; letter-spacing: -.02em; margin: 0 0 6px }
  .up .lead { color: var(--muted); margin: 0 0 18px; line-height: 1.5 }
  .card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 16px 18px 18px; margin-bottom: 14px }
  .card > h2 { font-size: 14px; font-weight: 600; margin: 0 0 12px }
  .card > h2 span { color: var(--muted); font-weight: 500; margin-right: 4px }
  .f { display: block; margin: 0 0 12px } .f > span { display: block; font-size: 12.5px; color: var(--muted); margin-bottom: 5px }
  .f input, .f select { width: 100%; font-size: 16px }        /* 16 px: an iPhone does not zoom in on it */
  .f input[type=date] { color-scheme: light dark; min-height: 44px }
  .pick { display: flex; gap: 10px; align-items: center; flex-wrap: wrap }
  .tip { font-size: 13px; color: var(--muted); line-height: 1.5; margin: 10px 0 0 }
  .tip b { color: var(--fg) }
  .files { margin: 12px 0 0; display: grid; gap: 6px }
  .file { display: grid; grid-template-columns: 1fr auto; gap: 2px 10px; font-size: 13.5px; padding: 8px 10px;
          border: 1px solid var(--line); border-radius: 8px; background: var(--bg) }
  .file .n { overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
  .file .s { color: var(--muted); font-variant-numeric: tabular-nums }
  .file .w { grid-column: 1 / -1; color: var(--warn); font-size: 12.5px }
  .file .bar { grid-column: 1 / -1; height: 4px }
  .total { margin: 14px 0 0 } .total .bar { height: 8px; margin: 6px 0 }
  .said { margin: 12px 0 0; font-size: 14px; line-height: 1.5 }
  .said.bad { color: var(--bad) }
  .receipt { border: 1px solid var(--accent); }
  .receipt dl { display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; margin: 0 0 14px; font-size: 14px }
  .receipt dt { color: var(--muted) } .receipt dd { margin: 0; word-break: break-word }
  .btns { display: flex; gap: 10px; flex-wrap: wrap }
</style>

<div class="app"><main class="work"><div class="up">
  <h1>Upload photos and video</h1>
  <p class="lead">From this phone, straight into the archive, the way a card goes in: into the shoot's folder, named
    with its date, checked piece by piece, nothing changed or made smaller.</p>

<?php if (!$depts): ?>
  <div class="card"><h2>First, the archive needs its <?= $e(strtolower(shelf_word(true))) ?></h2>
    <p class="tip">Before anything comes in, Rushes needs to know the <?= $e(strtolower(shelf_word(true))) ?> and the
      folder they live in (Manage → Reorganize, by the admin). Until then there is nowhere to put a shoot.</p></div>
<?php else: ?>
  <section class="card" id="s1">
    <h2><span>01 /</span> Who you are</h2>
    <label class="f"><span>Your name: it goes with the files, so everyone knows who brought them</span>
      <input type="text" id="who" maxlength="60" autocomplete="name" placeholder="e.g. Maria Lopez"></label>
  </section>

  <section class="card" id="s2">
    <h2><span>02 /</span> What the shoot was</h2>
    <label class="f"><span><?= $e(shelf_word()) ?></span>
      <select id="dept"><option value="">Pick one…</option>
        <?php foreach ($depts as $d): ?><option><?= $e($d) ?></option><?php endforeach; ?></select></label>
    <label class="f"><span>What it was</span>
      <input type="text" id="event" maxlength="80" placeholder="e.g. Kite Festival"></label>
    <label class="f"><span>The day it was shot (from the files; change it if it is wrong)</span>
      <input type="date" id="date"></label>
  </section>

  <section class="card" id="s3">
    <h2><span>03 /</span> The photos and videos</h2>
    <div class="pick">
      <input type="file" id="files" multiple accept="image/*,video/*" hidden>
      <button class="btn" type="button" id="choose">Choose files</button>
      <span class="note" id="count">Nothing chosen yet.</span>
    </div>
    <p class="tip"><b>To keep the originals, choose “Choose File” (the Files app), not the Photo Library.</b> From the
      Photo Library an iPhone may convert or shrink a file before it leaves the phone; from Files it sends it exactly as
      it is. 4K, ProRes, RAW: all of it goes up as it was shot.</p>
    <div class="files" id="list"></div>
  </section>

  <div class="btns"><button class="btn" type="button" id="go" disabled>Upload</button></div>
  <div class="total" id="total" hidden><div class="bar"><i id="tbar" style="width:0%"></i></div><div class="note" id="tsaid"></div></div>
  <p class="said" id="said"></p>

  <section class="card receipt" id="receipt" hidden>
    <h2>Receipt</h2>
    <dl id="rlist"></dl>
    <div class="btns">
      <button class="btn" type="button" id="share">Send to myself</button>
      <a class="btn quiet" id="open" href="#">Open in Search</a>
      <button class="btn quiet" type="button" id="again">Upload more</button>
    </div>
    <p class="tip">The link opens Rushes, so it works on the office network or through the VPN.</p>
  </section>
<?php endif; ?>
</div></main></div>

<script>
(function () {
'use strict';
const $ = function (i) { return document.getElementById(i); };
if (!$('go')) return;
const esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
const size = function (b) { return b >= 1e9 ? (b / 1e9).toFixed(2) + ' GB' : b >= 1e6 ? (b / 1e6).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1e3)) + ' KB'; };
const store = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
                set: function (k, v) { try { v == null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) {} } };
const PIECE = 4 * 1024 * 1024;

// ── SHA-256 of each piece, here: this page is plain http, where browsers keep their own to themselves ──
const K = new Uint32Array([0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
  0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,0xe49b69c1,0xefbe4786,0x0fc19dc6,
  0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,
  0x06ca6351,0x14292967,0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,0xa2bfe8a1,
  0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,
  0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2]);
function sha256(buf) {
  const b = new Uint8Array(buf), n = b.length, total = ((n + 9 + 63) >> 6) << 6;
  const m = new Uint8Array(total); m.set(b); m[n] = 0x80;
  const dv = new DataView(m.buffer); dv.setUint32(total - 8, Math.floor(n / 0x20000000)); dv.setUint32(total - 4, (n << 3) >>> 0);
  const h = new Uint32Array([0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19]), w = new Uint32Array(64);
  for (let o = 0; o < total; o += 64) {
    for (let i = 0; i < 16; i++) w[i] = dv.getUint32(o + i * 4);
    for (let i = 16; i < 64; i++) {
      const a = w[i - 15], c = w[i - 2];
      const s0 = ((a >>> 7) | (a << 25)) ^ ((a >>> 18) | (a << 14)) ^ (a >>> 3);
      const s1 = ((c >>> 17) | (c << 15)) ^ ((c >>> 19) | (c << 13)) ^ (c >>> 10);
      w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
    }
    let A = h[0], B = h[1], C = h[2], D = h[3], E = h[4], F = h[5], G = h[6], H = h[7];
    for (let i = 0; i < 64; i++) {
      const S1 = ((E >>> 6) | (E << 26)) ^ ((E >>> 11) | (E << 21)) ^ ((E >>> 25) | (E << 7));
      const t1 = (H + S1 + ((E & F) ^ (~E & G)) + K[i] + w[i]) | 0;
      const S0 = ((A >>> 2) | (A << 30)) ^ ((A >>> 13) | (A << 19)) ^ ((A >>> 22) | (A << 10));
      const t2 = (S0 + ((A & B) ^ (A & C) ^ (B & C))) | 0;
      H = G; G = F; F = E; E = (D + t1) | 0; D = C; C = B; B = A; A = (t1 + t2) | 0;
    }
    h[0] += A; h[1] += B; h[2] += C; h[3] += D; h[4] += E; h[5] += F; h[6] += G; h[7] += H;
  }
  let s = ''; for (let i = 0; i < 8; i++) s += ('0000000' + h[i].toString(16)).slice(-8);
  return s;
}
window.__sha256 = sha256;            // for the page's own check, below, and the tests

// ── what is chosen ──────────────────────────────────────────────────────────
let picked = [], running = false;
$('who').value = store.get('rushes-uploader') || '';
$('choose').onclick = function () { $('files').click(); };
const day = function (t) { const d = new Date(t); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); };
// An iPhone that converted a file on the way out leaves a sign of it in the name.
const converted = function (f) {
  return /^trim\./i.test(f.name) ? 'This video looks re-encoded by the iPhone (its name starts with “trim.”). Choose it from Files to keep the original.' : '';
};
$('files').onchange = function () {
  picked = Array.prototype.slice.call(this.files);
  const total = picked.reduce(function (a, f) { return a + f.size; }, 0);
  $('count').textContent = picked.length ? picked.length + ' file' + (picked.length === 1 ? '' : 's') + ' · ' + size(total) : 'Nothing chosen yet.';
  $('list').innerHTML = picked.map(function (f, i) {
    const w = converted(f);
    return '<div class="file" id="f' + i + '"><span class="n">' + esc(f.name) + '</span><span class="s">' + size(f.size) + '</span>' +
      (w ? '<span class="w">' + esc(w) + '</span>' : '') + '<div class="bar"><i style="width:0%"></i></div></div>';
  }).join('');
  if (picked.length && !$('date').value) $('date').value = day(Math.min.apply(null, picked.map(function (f) { return f.lastModified || Date.now(); })));
  ready();
};
function ready() {
  const ok = picked.length && $('who').value.trim() && $('dept').value && $('event').value.trim() && $('date').value;
  $('go').disabled = !ok || running;
}
['who', 'dept', 'event', 'date'].forEach(function (i) { $(i).oninput = ready; $(i).onchange = ready; });

// ── sending ─────────────────────────────────────────────────────────────────
const say = function (t, bad) { $('said').textContent = t; $('said').className = 'said' + (bad ? ' bad' : ''); };
const sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
async function ask(url, opts) {                      // carries on through a dropped connection
  for (let tries = 0; ; tries++) {
    try {
      const r = await fetch(url, opts), j = await r.json().catch(function () { return {}; });
      if (r.status >= 500 && r.status !== 507 && tries < 30) throw new Error(j.error || ('the server said ' + r.status));
      j._status = r.status; return j;
    } catch (e) {
      if (tries >= 30) throw e;
      say('The connection dropped — trying again (' + (tries + 1) + ')… Keep this page open; nothing is lost.', true);
      await sleep(Math.min(30000, 2000 * (tries + 1)));
    }
  }
}
$('go').onclick = async function () {
  running = true; ready(); store.set('rushes-uploader', $('who').value.trim());
  const total = picked.reduce(function (a, f) { return a + f.size; }, 0);
  let sent = 0, t0 = Date.now();
  $('total').hidden = false;
  const show = function () {
    const secs = (Date.now() - t0) / 1000, rate = secs > 2 ? sent / secs : 0;
    $('tbar').style.width = (100 * sent / total).toFixed(1) + '%';
    $('tsaid').textContent = size(sent) + ' of ' + size(total) + (rate ? ' · ' + size(rate) + '/s · about ' + Math.ceil((total - sent) / rate / 60) + ' min left' : '');
  };
  try {
    say('Starting…');
    const s = await ask('/db/upload.php', { method: 'POST', body: new URLSearchParams({ action: 'start', uploader: $('who').value.trim(),
      dept: $('dept').value, event: $('event').value.trim(), date: $('date').value,
      files: JSON.stringify(picked.map(function (f) { return { name: f.name, size: f.size, mtime: Math.floor((f.lastModified || 0) / 1000) }; })) }) });
    if (s.error) throw new Error(s.error);
    for (let i = 0; i < picked.length; i++) {
      const f = picked[i], bar = $('f' + i).querySelector('.bar i'), q = 'batch=' + s.batch + '&name=' + encodeURIComponent(f.name);
      let have = (await ask('/db/upload.php?have&' + q)).have || 0; sent += have;
      while (have < f.size) {
        say('Sending ' + f.name + ' (' + (i + 1) + ' of ' + picked.length + ')…');
        const piece = await f.slice(have, Math.min(f.size, have + PIECE)).arrayBuffer();
        const r = await ask('/db/upload.php?piece&' + q + '&offset=' + have, { method: 'POST', body: piece, headers: { 'X-Piece-SHA256': sha256(piece) } });
        if (r._status === 409 || r._status === 422) { sent += (r.have || 0) - have; have = r.have || 0; continue; }   // back to what is really there
        if (r.error) throw new Error(r.error);
        sent += r.have - have; have = r.have; bar.style.width = (100 * have / f.size).toFixed(1) + '%'; show();
      }
      bar.style.width = '100%';
    }
    const fin = await ask('/db/upload.php', { method: 'POST', body: new URLSearchParams({ action: 'finish', batch: s.batch }) });
    if (fin.error) throw new Error(fin.error);
    say('All ' + picked.length + ' arrived whole ✓ Now the helper puts them in the archive, checked again, like a card.');
    receipt(s.batch, fin);
  } catch (e) {
    say('Did not finish: ' + e.message + '. Press Upload again with the same files: it carries on from what arrived.', true);
    running = false; ready();
  }
};

// ── the receipt ─────────────────────────────────────────────────────────────
function receipt(batch, fin) {
  const when = new Date(), ev = $('event').value.trim();
  const rows = [['Uploaded by', $('who').value.trim()], ['Shoot', ev + ' · ' + $('dept').value + ' · ' + $('date').value],
                ['Files', fin.files + ' (' + size(fin.bytes) + ')'], ['Where', fin.into], ['When', when.toLocaleString()],
                ['In the archive', 'putting them in…']];
  const draw = function () { $('rlist').innerHTML = rows.map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; }).join(''); };
  draw(); $('receipt').hidden = false; $('receipt').scrollIntoView({ behavior: 'smooth' });
  const link = location.origin + '/db/find.php?q=' + encodeURIComponent(ev);
  $('open').href = link;
  $('share').onclick = function () {
    const text = 'Rushes upload receipt\n' + rows.map(function (r) { return r[0] + ': ' + r[1]; }).join('\n') + '\n' + link;
    if (navigator.share) navigator.share({ title: 'Rushes: ' + ev, text: text }).catch(function () {});
    else { copyText(text, $('share')); }
  };
  $('again').onclick = function () { location.reload(); };
  (async function watch() {                          // until it is in the archive, every 15 s
    for (;;) {
      let st; try { st = await (await fetch('/db/upload.php?state&batch=' + batch)).json(); } catch (e) { st = {}; }
      rows[5][1] = st.state === 'placed' ? '✓ ' + (st.note || 'in the archive') : st.state === 'refused' ? 'not taken in: ' + (st.note || '')
        : st.state === 'paused' ? 'waiting: copying is paused in Manage; it goes in when it resumes' : 'waiting for the helper…';
      draw();
      if (st.state === 'placed' || st.state === 'refused') return;
      await sleep(15000);
    }
  })();
}

// The page's own check of its fingerprint maker, once, against a known answer.
if (sha256(new TextEncoder().encode('abc').buffer) !== 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad')
  say('This browser cannot check the pieces it sends; upload from another browser.', true), $('go').remove();
})();
</script>
