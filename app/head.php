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
  .topbar .slow { font-size: 12px; color: var(--warn, #b26a00); white-space: nowrap }
</style>
<?php $iv = substr(md5(favicon_href()), 0, 8); ?>
<link rel="icon" type="image/png" sizes="64x64" href="/icon.php?v=<?= $iv ?>">
<link rel="apple-touch-icon" href="/icon.php?s=180&amp;v=<?= $iv ?>">
<script>
// A phone or tablet (iPads say they are a Mac, but with a touch screen): the Mac apps' downloads step aside.
if (/iPhone|iPad|iPod|Android/i.test(navigator.userAgent) || (/Mac/.test(navigator.platform) && navigator.maxTouchPoints > 1))
  document.documentElement.classList.add('on-phone');
</script>
<script>
  // Theme before first paint, so a dark page never flashes white on the way in.
  try { var t = localStorage.getItem('theme'); if (t) document.documentElement.dataset.theme = t; }
  catch (e) {}

  // Pages ask the archive only while someone is looking, and ask less when it
  // is slow (rule 6 of DEVELOPING.md). every(fn, ms) replaces setInterval: one ask at a
  // time, none while the tab is hidden, and after a slow or failed answer the
  // wait doubles up to a minute, back to normal on the first quick one.
  // ponytail: one shared count of bad answers for the whole page; if the
  // archive is slow for one question it is slow for all of them.
  var bad = 0, f0 = window.fetch;
  window.fetch = function (u, o) {
    var t = Date.now();
    // A question nobody gets an answer to in 20 s is given up, not left hanging.
    if ((!o || !o.method || o.method === 'GET') && !(o && o.signal) && window.AbortSignal && AbortSignal.timeout)
      o = Object.assign({}, o, { signal: AbortSignal.timeout(20000) });
    return f0.call(window, u, o).then(function (r) {
      if (r.status >= 500 || Date.now() - t > 4000) bad++;
      return r;
    }, function (e) { bad++; throw e; });
  };
  var slowest = {};
  window.every = function (fn, ms) {
    var wait = ms, timer = null, busy = false, id = Math.random();
    function tick() {
      timer = null;
      if (busy || document.hidden) return;   // a hidden tab asks nothing; it starts again when shown
      busy = true;
      var b = bad;
      Promise.resolve().then(fn).catch(function () { bad++; }).then(function () {
        busy = false;
        wait = bad > b ? Math.min(wait * 2, 60000) : ms;
        slowest[id] = wait > ms ? wait : 0;
        var w = Math.max.apply(null, Object.keys(slowest).map(function (k) { return slowest[k]; }));
        var el = document.getElementById('hSlow');
        if (el) {                                   // on a phone, the short form; the whole sentence on a long press
          var say = 'the archive is slow, asking every ' + Math.round(w / 1000) + ' s';
          el.hidden = !w; el.title = say;
          el.textContent = window.matchMedia('(max-width: 640px)').matches ? 'slow · ' + Math.round(w / 1000) + ' s' : say;
        }
        timer = setTimeout(tick, wait);
      });
    }
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && !timer && !busy) tick();
    });
    tick();
  };

  // Asking twice, on the button itself, never in a pop-up: the first press turns
  // the button into "Sure? …" and says beside it what will happen; a second
  // press within six seconds does it (sure() returns true). The key keeps the
  // question open while a page redraws the button.
  var sureUntil = {};
  window.sure = function (btn, what, key) {
    key = key || btn.id || btn.textContent;
    var note = btn.nextElementSibling && btn.nextElementSibling.classList.contains('sure-note') ? btn.nextElementSibling : null;
    if ((sureUntil[key] || 0) > Date.now()) {
      delete sureUntil[key]; if (note) note.remove(); return true;
    }
    sureUntil[key] = Date.now() + 6000;
    var was = btn.textContent; btn.textContent = 'Sure? ' + was;
    if (what && !note) { note = document.createElement('span'); note.className = 'note sure-note'; btn.after(note); }
    if (note) note.textContent = ' ' + what;
    setTimeout(function () {
      if ((sureUntil[key] || 0) <= Date.now()) { if (btn.textContent === 'Sure? ' + was) btn.textContent = was; if (note) note.remove(); }
    }, 6100);
    return false;
  };
</script>

<header class="topbar">
  <!-- On a phone: one menu (☰) holds every page, and the bar says which one this is. -->
  <button class="menu-btn" id="hMenu" aria-label="All pages" aria-expanded="false" aria-controls="mnav">
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
  <a class="brand" href="/db/find.php">
    <?= mark() ?><?= htmlspecialchars(settings()['name'] ?? 'Rushes') ?>
  </a>
  <span class="here" id="hHere"></span>

  <a class="tab" href="/db/find.php" <?= $NAV === 'search' ? 'aria-current="page"' : '' ?>>Search</a>
  <a class="tab" href="/ingest.php"  <?= $NAV === 'ingest' ? 'aria-current="page"' : '' ?>>Ingest</a>

  <span class="grow"></span>

  <span class="pulse" id="hPulse" title="what is moving right now">
    <span class="dot" id="hDot"></span><span id="hWhat">checking&hellip;</span>
  </span>
  <span class="slow" id="hSlow" hidden></span>

  <a class="tab tab-i" href="/db/admin.php" style="margin-left:12px"
     <?= $NAV === 'admin' ? 'aria-current="page"' : '' ?>><?= icon('lock', 1.8) ?>Manage</a>

  <!-- Who is using this browser: their name goes beside what they do, in Activity -->
  <button class="tab" id="hWho" type="button" title="The name Activity shows beside what you do here">Who?</button>
  <button class="tab tab-i" id="hTheme" title="light or dark"
          aria-label="Switch light or dark"><svg viewBox="0 0 24 24" fill="none"
    stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
    <circle cx="12" cy="12" r="4.6"/><path d="M12 2.6v2.2M12 19.2v2.2M2.6 12h2.2M19.2 12h2.2
    M5.4 5.4l1.6 1.6M17 17l1.6 1.6M18.6 5.4 17 7M7 17l-1.6 1.6"/></svg></button>
</header>

<nav class="mnav" id="mnav" aria-label="All pages" hidden>
  <?php $m = function (string $label, string $href, string $go = '') {
      return '<a href="' . $href . '"' . ($go !== '' ? ' data-go="' . $go . '"' : '') . '>' . $label . '</a>'; }; ?>
  <h2>Find and bring in</h2>
  <?= $m('Search', '/db/find.php') ?><?= $m('Upload from this phone', '/upload.php') ?><?= $m('Ingest a card', '/ingest.php') ?>
  <h2>Manage</h2>
  <?= $m('Overview', '/db/admin.php#overview', 'overview') ?>
  <h2>Transfer</h2>
  <?= $m('Copying', '/db/admin.php#transfers', 'transfers') ?><?= $m('Reorganize', '/structure.php') ?>
  <?= $m('Duplicates', '/db/admin.php#duplicates', 'duplicates') ?><?= $m('Cache', '/db/admin.php#cache', 'cache') ?>
  <h2>Footage</h2>
  <?= $m('Describe', '/db/admin.php#describe', 'describe') ?><?= $m("Editors' projects", '/db/admin.php#projects', 'projects') ?>
  <h2>System</h2>
  <?= $m('Activity', '/db/admin.php#activity', 'activity') ?><?= $m('Setup', '/setup.php') ?>
  <div class="mnav-foot">
    <button type="button" id="mTheme">Light or dark</button>
    <button type="button" id="mWho" onclick="document.getElementById('hMenu').click(); document.getElementById('hWho').click()">Your name, for Activity</button>
    <form method="post" action="/db/admin.php"><button name="_signout" value="1" type="submit">Sign out</button></form>
  </div>
</nav>

<!-- Asked once in each browser, before anything else: a name, not an account
     (db/activity.php). The page waits behind it until a name is given; it is kept in
     this browser (the rushes_who cookie), so each person types it once per computer
     or phone. Anyone can type any name; editors' computers are known by their pairing. -->
<div class="whoveil" id="whoBar" role="dialog" aria-modal="true" aria-labelledby="whoTitle" hidden>
  <form class="whocard" id="whoForm">
    <h2 id="whoTitle">Who is using Rushes here?</h2>
    <p>Your name goes beside what you do, in Activity: a pull you make, one you download, a switch you turn.
      It is kept in this browser only, so it is asked once.</p>
    <input id="whoName" maxlength="40" autocomplete="name" placeholder="Your name" aria-label="Your name">
    <div class="whorow"><button class="btn quiet" type="button" id="whoLater" hidden>Cancel</button>
      <button class="btn" type="submit">That's me</button></div>
  </form>
</div>
<style>
.whoveil { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; padding: 16px;
  background: rgba(10, 14, 14, .62); -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px) }
.whoveil[hidden] { display: none }
.whocard { width: 100%; max-width: 420px; background: var(--bg); color: var(--fg); border: 1px solid var(--line);
  border-radius: 14px; padding: 24px; box-shadow: 0 24px 60px rgba(0, 0, 0, .35); display: grid; gap: 12px }
.whocard h2 { margin: 0; font-size: 19px } .whocard p { margin: 0; color: var(--muted); font-size: 13.5px; line-height: 1.5 }
.whocard input { width: 100%; padding: 10px 12px; font: inherit; font-size: 16px; border: 1px solid var(--line);
  border-radius: var(--radius-sm); background: var(--raised); color: var(--fg) }
.whorow { display: flex; gap: 8px; justify-content: flex-end }
</style>
<script>
(function () {
  var bar = document.getElementById('whoBar'), btn = document.getElementById('hWho'), inp = document.getElementById('whoName');
  var later = document.getElementById('whoLater');
  var name = (document.cookie.match(/(?:^|; )rushes_who=([^;]*)/) || [])[1];
  name = name ? decodeURIComponent(name) : '';
  function show() { btn.textContent = name || 'Who?'; btn.title = name ? 'You are ' + name + ' here: press to change it' : 'Say who you are, for Activity'; }
  // open: the page waits behind it; with a name already given, it can be cancelled
  function ask() {
    bar.hidden = false; later.hidden = !name; inp.value = name;
    document.documentElement.style.overflow = 'hidden';
    setTimeout(function () { inp.focus(); }, 0);
  }
  function close() { bar.hidden = true; document.documentElement.style.overflow = ''; }
  show();
  if (!name) ask();
  btn.onclick = ask;
  later.onclick = close;
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !bar.hidden && name) close(); });
  document.getElementById('whoForm').onsubmit = function (e) {
    e.preventDefault();
    var n = inp.value.replace(/[\x00-\x1f]/g, ' ').trim().slice(0, 40);
    if (!n) { inp.focus(); inp.placeholder = 'Type your name first'; return; }
    // said in Activity first (while the old name is still the cookie), then kept
    fetch('/db/activity.php', {method: 'POST', body: new URLSearchParams({action: 'hello', name: n})}).catch(function () {}).then(function () {
      document.cookie = 'rushes_who=' + encodeURIComponent(n) + '; max-age=31536000; path=/; SameSite=Lax';
      name = n; show(); close();
      btn.textContent = '✓ ' + n; setTimeout(show, 2500);       // seen to have happened
    });
  };
})();
</script>

<script>
(function () {
  var $ = function (i) { return document.getElementById(i); };

  // The phone menu: opens over the page, says where you are, and closes on a choice.
  var menu = $('mnav'), btn = $('hMenu'), here = $('hHere');
  var title = null;                          // Manage's own heading, which follows its sections
  function where() {
    var t = (title && title.textContent) || document.title.split(' · ')[0];
    here.textContent = t;
    menu.querySelectorAll('a').forEach(function (a) {   // this page; on Manage, its section
      var cur = a.getAttribute('href').split('#')[0] === location.pathname && (!a.dataset.go || a.textContent === t);
      if (cur) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
  }
  function open(yes) { menu.hidden = !yes; btn.setAttribute('aria-expanded', yes); document.documentElement.classList.toggle('mnav-open', yes); }
  btn.onclick = function () { open(menu.hidden); };
  menu.addEventListener('click', function (e) {
    var a = e.target.closest('a');
    if (!a) return;
    // Already on Manage: switch its section without loading the page again.
    if (a.dataset.go && typeof window.show === 'function' && location.pathname.indexOf('/db/admin.php') === 0) {
      e.preventDefault(); window.show(a.dataset.go);
    }
    open(false);
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') open(false); });
  function watch() {                         // the page below this bar exists only now
    title = $('title');
    if (title) new MutationObserver(where).observe(title, { childList: true, characterData: true, subtree: true });
    where();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watch); else watch();
  $('mTheme').onclick = function () { $('hTheme').onclick(); open(false); };

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
    return fetch('/db/state.php?t=' + Date.now())
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
            interrupted: 'transfer interrupted', blocked: 'waiting for the source', stopped: 'waiting for space',
            paused: 'transfer paused'}[j.phase] || 'transfer')
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
  every(paint, 5000);
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
  // Two steps, and the page says which one and how much of it is left.
  if (c.phase === 'tracing' && c.step === 'matching') return {busy: true,
      title: 'Matching earlier copies to their originals on ' + name + ' · step 2 of 2: checking the copies',
      pct: c.of ? Math.min(100, Math.floor(c.checked / c.of * 100)) : null,
      short: 'matching · step 2 of 2 · ' + num(c.checked) + (c.of ? ' of ' + num(c.of) : ''),
      facts: [[num(c.checked) + (c.of ? ' / ' + num(c.of) : ''), 'earlier copies checked'], [num(c.traced), 'traced to an original'],
              [num(c.untraced), 'no original found'], [num(c.originals), 'originals listed']]};
  if (c.phase === 'tracing') return {busy: true,
      title: 'Matching earlier copies to their originals on ' + name + ' · step 1 of 2: listing the originals',
      pct: c.folders ? Math.floor(c.folders_read / c.folders * 100) : null,
      short: 'matching · step 1 of 2 · ' + num(c.checked),
      facts: [[num(c.checked), 'originals listed so far']]
        .concat(c.folders ? [[c.folders_read + ' of ' + c.folders, 'folders read — kept if it stops']] : [])
        .concat(c.copies ? [[num(c.copies), 'earlier copies to check next']] : [])
        .concat([['reading only', 'nothing moves']])};
  if (c.phase === 'analysing') {
    var p3 = c.of ? Math.floor(((c.n || 1) - 1 + (c.shots ? c.shot / c.shots : 0)) / c.of * 100) : null;
    return {busy: true, pct: p3, file: c.file, title: 'Describing ' + name + ' · vision model and speech',
      short: 'describing ' + name + (c.of ? ' · ' + c.n + ' of ' + c.of : ''),
      facts: c.of ? [[num(c.n) + ' / ' + num(c.of), 'files'],
                     [c.step === 'speech' ? 'listening' : num(c.shot) + ' / ' + num(c.shots), c.step === 'speech' ? 'writing down what is said' : 'shots in this file'],
                     [c.per_shot ? c.per_shot + ' s' : '—', 'per shot'],
                     [num(c.failed), 'shots it could not read']]
                 : [[c.step || 'starting', 'loading the model']]};
  }
  if (c.phase === 'delivering') {
    var p6 = c.of ? Math.floor(c.copied / c.of * 100) : 0;
    return {busy: true, pct: p6, title: 'Taking in what an editor delivered · ' + name, short: 'taking in a delivery · ' + p6 + '%',
      facts: [[num(c.copied) + ' / ' + num(c.of), 'files checked and copied']]};
  }
  if (c.phase === 'tidying') {
    var p2 = c.of ? Math.floor(c.copied / c.of * 100) : 0;
    return {busy: true, pct: p2, title: 'Tidying up', short: 'tidying up · ' + p2 + '%',
      facts: [[num(c.copied) + ' / ' + num(c.of), 'files moved']]};
  }
  // Checking, when there is nothing to copy: older copies against their
  // originals (once), then every recorded file against its fingerprint.
  if (c.phase === 'proving' && c.step === 'copies') return {busy: true, pct: c.of ? Math.floor(c.n / c.of * 100) : null,
      title: 'Counting copies · is the original of each file still where it came from?',
      short: 'counting copies · ' + num(c.n) + ' of ' + num(c.of),
      facts: [[num(c.n) + ' / ' + num(c.of), 'files looked at'], [num(c.ok), 'original still there'],
              [num(c.bad), 'original gone or changed'], ['names and sizes only', 'nothing is read or moved']]};
  if (c.phase === 'proving') {
    var older = c.step === 'older', p5 = c.of ? Math.floor(((c.n || 1) - 1) / c.of * 100) : null;
    return {busy: true, pct: p5, file: c.file,
      title: (older ? 'Checking older copies against their originals · ' : 'Checking the archive for damage · ') + name,
      short: (older ? 'checking older copies' : 'checking the archive') + (c.of ? ' · ' + num(c.n) + ' of ' + num(c.of) : ''),
      facts: c.of ? [[num(c.n) + ' / ' + num(c.of), 'files in this folder'], [num(c.ok), 'match'], [num(c.bad), 'differ'],
                     [num(c.missing), older ? 'originals not reachable' : 'missing'], ['reading only', 'nothing moves']]
                  : [[c.note || 'getting ready', '']]};
  }
  if (c.phase === 'paused') return {short: 'paused', title: 'Paused from Manage — nothing new starts until Resume'};
  // no source: the helper stopped by itself after a share stopped answering (Try again now)
  if (c.phase === 'blocked') return {bad: true, short: c.source ? 'copying stopped · source gone' : 'helper stopped · press Try again',
      title: c.note || 'The helper cannot see the source'};
  if (c.phase === 'stopped') return {bad: true, short: 'copying paused · archive nearly full', title: c.note || ''};
  return null;
};

// Copy anything, from any page. Every copy says it happened — or says what to
// do instead, when the browser would not let it.
window.copyText = function (text, btn) {
  // btn is the button that says so, or a function told whether it worked (a menu has no button)
  if (typeof btn === 'function') { var tell = btn; btn = { textContent: '' }; }
  var was = btn.textContent;
  var done = function (ok) {
    if (tell) return tell(ok);
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
