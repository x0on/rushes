<?php
// head.php — the chrome every page wears: the mark, Search and Ingest as peers,
// what is moving right now, and Admin behind a lock.
//
// Admin is deliberately not a peer tab. It is a separate workspace with its own
// rail, because maintenance tools crowding a search box is what made the first
// version of this page unusable. From here it is one door, with a lock on it.
//
// A page sets $NAV to 'search', 'ingest' or 'admin' before including this.
require_once __DIR__ . '/db/config.php';
$NAV = $NAV ?? '';
?>
<link rel="stylesheet" href="/tokens.css?v=<?= @filemtime(__DIR__ . '/tokens.css') ?>">
<style>
  .tab-i { display: inline-flex; align-items: center; gap: 7px }
  .tab-i svg { width: 15px; height: 15px; flex: none }
  .topbar .pulse { padding-right: 14px; border-right: 1px solid var(--line-soft) }
</style>
<?php $iv = substr(md5(favicon_href()), 0, 8); ?>
<link rel="icon" type="image/png" sizes="64x64" href="/icon.php?v=<?= $iv ?>">
<link rel="apple-touch-icon" href="/icon.php?s=180&amp;v=<?= $iv ?>">
<script>
  // Theme before first paint, so a dark page never flashes white on the way in.
  try { var t = localStorage.getItem('theme'); if (t) document.documentElement.dataset.theme = t; }
  catch (e) {}
</script>

<header class="topbar">
  <a class="brand" href="/db/find.php">
    <?= mark() ?><?= htmlspecialchars(settings()['name'] ?? 'Rushes') ?>
  </a>

  <a class="tab" href="/db/find.php" <?= $NAV === 'search' ? 'aria-current="page"' : '' ?>>Search</a>
  <a class="tab" href="/ingest.php"  <?= $NAV === 'ingest' ? 'aria-current="page"' : '' ?>>Ingest</a>

  <span class="grow"></span>

  <span class="pulse" id="hPulse" title="what is moving right now">
    <span class="dot" id="hDot"></span><span id="hWhat">checking&hellip;</span>
  </span>

  <a class="tab tab-i" href="/db/admin.php" style="margin-left:12px"
     <?= $NAV === 'admin' ? 'aria-current="page"' : '' ?>><?= icon('lock', 1.8) ?>Manage</a>

  <button class="tab tab-i" id="hTheme" title="light or dark"
          aria-label="Switch light or dark"><svg viewBox="0 0 24 24" fill="none"
    stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
    <circle cx="12" cy="12" r="4.6"/><path d="M12 2.6v2.2M12 19.2v2.2M2.6 12h2.2M19.2 12h2.2
    M5.4 5.4l1.6 1.6M17 17l1.6 1.6M18.6 5.4 17 7M7 17l-1.6 1.6"/></svg></button>
</header>

<script>
(function () {
  var $ = function (i) { return document.getElementById(i); };

  $('hTheme').onclick = function () {
    var now = document.documentElement.dataset.theme
      || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    var next = now === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem('theme', next); } catch (e) {}
  };

  // One line about whether anything is happening, on every page. It says a word
  // as well as showing a colour, because a coloured dot on its own tells some
  // people nothing at all.
  function paint() {
    fetch('/db/state.php?t=' + Date.now())
      .then(function (r) { return r.json(); })
      .then(function (s) {
        if (s.error) { $('hDot').className = 'dot off'; $('hWhat').textContent = 'needs setting up'; return; }
        // What the helper is doing counts as running. Saying "nothing running"
        // while a transfer is moving is the one lie this must never tell.
        var h = helperNow(s.copy), c = s.copy, j = s.transfer;
        var busy = !!s.running || (h && h.busy);
        // No live helper, but a selection is unfinished: say where it stands,
        // from the progress saved on the archive (it survives restarts).
        var jw = !h && j && j.phase !== 'done' ? ({copying: 'transferring', checking: 'checking files', queued: 'transfer waiting for the helper',
            interrupted: 'transfer interrupted', blocked: 'waiting for the source', stopped: 'waiting for space'}[j.phase] || 'transfer')
            + (j.pct === null ? '' : ' · ' + j.pct + '%') : '';
        $('hDot').className = 'dot' + (h && h.bad ? ' off' : busy ? ' busy'
          : jw && /interrupted|blocked|stopped/.test(j.phase) ? ' off' : (s.runner && s.runner.ok ? '' : ' off'));
        $('hWhat').textContent = h ? h.short
          : jw ? jw
          : s.running ? (s.progress ? s.running + ' · ' + s.progress.pct + '%' : s.running)
          : (c && c.stale && /copying|looking|tracing|tidying/.test(c.phase)) ? 'the helper went quiet'
          : (s.runner && s.runner.ok ? 'nothing running' : 'not picking up jobs');
        $('hPulse').title = h ? h.title + (h.facts ? ' — ' + h.facts.map(function (f) { return f[0] + ' ' + f[1]; }).join(', ') : '') : 'what is moving right now';
      })
      .catch(function () { $('hDot').className = 'dot off'; $('hWhat').textContent = 'no answer'; });
  }
  paint(); setInterval(paint, 5000);
})();

// What the helper is doing, in words and numbers, from state.php's "copy".
// One place, so the top bar and Overview never disagree. null = nothing live.
window.helperNow = function (c) {
  if (!c || c.stale || !c.phase) return null;
  var num  = function (n) { return (n || 0).toLocaleString(); };
  var size = function (b) { b = b || 0; return b >= 1e12 ? (b / 1e12).toFixed(2) + ' TB' : b >= 1e9 ? (b / 1e9).toFixed(1) + ' GB' : Math.round(b / 1e6) + ' MB'; };
  var speed = function (r) { return r >= 1e9 ? (r / 1e9).toFixed(1) + ' GB/s' : Math.round(r / 1e6) + ' MB/s'; };
  var left = function (t) { return t >= 5400 ? Math.round(t / 3600) + ' h' : t >= 90 ? Math.round(t / 60) + ' min' : 'under 1 min'; };
  var name = c.label || (c.source || '').split('/').pop();
  if (c.phase === 'copying') {
    var pct = c.bytes ? Math.min(100, Math.floor(c.done_bytes / c.bytes * 100)) : (c.of ? Math.floor(c.copied / c.of * 100) : 0);
    return {busy: true, pct: pct, file: c.file, title: 'Copying ' + name,
      short: 'copying ' + name + ' · ' + pct + '%' + (c.rate ? ' · ' + speed(c.rate) : ''),
      facts: [[num(c.copied) + ' / ' + num(c.of), 'files'], [size(c.done_bytes) + ' / ' + size(c.bytes), 'copied'],
              [c.rate ? speed(c.rate) : '—', 'speed'], [c.eta != null && c.rate ? left(c.eta) : '—', 'left']]};
  }
  if (c.phase === 'looking') return {busy: true, title: 'Checking what ' + name + ' still needs',
      short: 'checking ' + name + ' · ' + num(c.checked) + ' files',
      facts: [[num(c.checked), 'files checked'], [num(c.new) + ' · ' + size(c.bytes), 'to copy'], [num(c.already), 'already here']]};
  if (c.phase === 'tracing') return {busy: true, title: 'Matching earlier copies to their originals on ' + name,
      short: 'matching earlier copies · ' + num(c.checked),
      facts: c.step === 'matching'
        ? [[num(c.checked), 'copies checked'], [num(c.traced), 'traced'], [num(c.untraced), 'no original found'], [num(c.originals), 'originals listed']]
        : [[num(c.checked), 'originals listed so far'], ['reading only', 'nothing moves']]};
  if (c.phase === 'tidying') {
    var p2 = c.of ? Math.floor(c.copied / c.of * 100) : 0;
    return {busy: true, pct: p2, title: 'Tidying up', short: 'tidying up · ' + p2 + '%',
      facts: [[num(c.copied) + ' / ' + num(c.of), 'files moved']]};
  }
  if (c.phase === 'blocked') return {bad: true, short: 'copying stopped · source gone', title: c.note || 'The helper cannot see the source'};
  if (c.phase === 'stopped') return {bad: true, short: 'copying paused · archive nearly full', title: c.note || ''};
  return null;
};

// Copy anything, from any page. Every copy says it happened — or says what to
// do instead, when the browser would not let it.
window.copyText = function (text, btn) {
  var was = btn.textContent;
  var done = function (ok) {
    btn.textContent = ok ? 'Copied ✓' : 'Press ⌘C';
    setTimeout(function () { btn.textContent = was; }, 1600);
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
    return;
  }
  var t = document.createElement('textarea');
  t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
  document.body.appendChild(t); t.select();
  var ok = false; try { ok = document.execCommand('copy'); } catch (e) {}
  t.remove(); done(ok);
};
// Any button with data-copy copies that text, including ones drawn later.
document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('[data-copy]');
  if (b) copyText(b.getAttribute('data-copy'), b);
});
// The same one-line command block, for pages that draw it in the browser.
window.cmdHTML = function (cmd) {
  var e = String(cmd).replace(/[&<>"]/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
  return '<div class="cmd"><code>' + e + '</code><button type="button" class="ghost" data-copy="' +
         e + '">Copy</button></div>';
};
</script>
