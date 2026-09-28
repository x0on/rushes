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
<link rel="stylesheet" href="/tokens.css">
<style>
  .tab-i { display: inline-flex; align-items: center; gap: 7px }
  .tab-i svg { width: 15px; height: 15px; flex: none }
  .topbar .pulse { padding-right: 14px; border-right: 1px solid var(--line-soft) }
</style>
<link rel="icon" type="image/svg+xml" href="<?= favicon_href() ?>">
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
        // A copy on the helper counts as running. Saying "idle" while a
        // transfer is moving is the one lie this indicator must never tell.
        var c = s.copy, copying = c && (c.phase === 'copying' || c.phase === 'tidying') && !c.stale;
        var busy = !!s.running || copying;
        $('hDot').className = 'dot' + (busy ? ' busy' : (s.runner && s.runner.ok ? '' : ' off'));
        $('hWhat').textContent = s.running
          ? (s.progress ? s.running + ' · ' + s.progress.pct + '%' : s.running)
          : copying
          ? (c.phase === 'tidying' ? 'tidying up' : 'copying ' + (c.source || '').split('/').pop())
            + ' · ' + (c.of ? Math.round(c.copied / c.of * 100) : 0) + '%'
          : (c && c.phase === 'copying' && c.stale) ? 'a copy stopped'
          : (s.runner && s.runner.ok ? 'nothing running' : 'not picking up jobs');
      })
      .catch(function () { $('hDot').className = 'dot off'; $('hWhat').textContent = 'no answer'; });
  }
  paint(); setInterval(paint, 10000);
})();

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
