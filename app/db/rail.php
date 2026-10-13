<?php
// rail.php — the one Admin sidebar, drawn the same on every admin page, so
// Structure and Setup read as parts of Admin rather than places you leave to.
//
// A page sets $RAIL to the item it is before including this. The sections of
// the admin page itself are links to it (#transfers, #activity …); when you are
// already on it, its own script switches section without reloading.
$RAIL = $RAIL ?? '';
$railItem = function (string $id, string $label, string $ico, string $href, bool $section, string $extra = '') use ($RAIL) {
    return '<a class="nav" href="' . $href . '"' . ($section ? ' data-go="' . $id . '"' : '')
         . ($RAIL === $id ? ' aria-current="page"' : '') . '><span class="ico">' . icon($ico) . '</span> '
         . $label . $extra . '</a>';
};
?>
  <nav class="rail rail-manage" aria-label="Manage sections">
    <?= $railItem('overview',   'Overview',   'overview',  '/db/admin.php#overview',   true, ' <span class="badge bad" id="nOverview" hidden></span>') ?>

    <!-- The pages that move files: each shows everything first, asks before it moves, records every move, and can put it back -->
    <h2>Transfer</h2>
    <?= $railItem('transfers',  'Copying',    'transfers', '/db/admin.php#transfers',  true, ' <span class="count" id="nTransfers"></span>') ?>
    <?= $railItem('structure',  'Reorganize', 'reorganize', '/structure.php#filein',   false) ?>
    <?= $railItem('tidyup',     'Tidy up',    'projects',  '/structure.php#tidy',     false) ?>
    <?= $railItem('duplicates', 'Duplicates', 'library',   '/db/admin.php#duplicates', true) ?>
    <?= $railItem('rescue',     'Rescue',     'rescue',  '/rescue.php',              false) ?>
    <?= $railItem('cache',      'Cache',      'cache',     '/db/admin.php#cache',      true, ' <span class="count" id="nCache"></span>') ?>

    <h2>Footage</h2>
    <?= $railItem('describe',   'Describe',   'ai',        '/db/admin.php#describe',   true) ?>
    <?= $railItem('projects',   "Editors' projects", 'project', '/db/admin.php#projects', true) ?>

    <h2>System</h2>
    <?= $railItem('activity',   'Activity',   'activity',  '/db/admin.php#activity',   true) ?>
    <?= $railItem('setup',      'Setup',      'tools',     '/setup.php',               false) ?>

    <div class="rail-foot">
      <div class="note" title="One number for the pages, the helper's code and both apps" style="margin-bottom:4px">Rushes <?= htmlspecialchars(rushes_version()) ?></div>
      <span id="fFiles">&mdash;</span>
      <div class="meter"><i id="fMeter"></i></div>
      <span id="fFree">&mdash;</span>
      <form method="post" style="margin-top:10px">
        <button class="nav" name="_signout" value="1" type="submit"
                style="padding-left:9px"><span class="ico">&#8635;</span> Sign out</button>
      </form>
    </div>
  </nav>
<script>
// The foot of the rail: the archive and how full it is. Not a total of files: each drive's are on its card
// in Overview (a total added up drives holding the same footage twice).
window.railFoot = function (s) {
  var tb = function (b) { return b >= 1e12 ? (b / 1e12).toFixed(2) + ' TB' : b >= 1e9 ? (b / 1e9).toFixed(1) + ' GB' : Math.round(b / 1e6) + ' MB'; };
  var a = (s.drives_in || []).filter(function (x) { return x.kind === 'archive'; })[0];
  document.getElementById('fFiles').textContent = 'The archive' + (a ? ': ' + a.name : '');
  document.getElementById('fFree').textContent = tb(s.disk.free) + ' free · ' + (s.disk.pct || 0) + '% used';
  var m = document.getElementById('fMeter');
  m.style.width = (s.disk.pct || 0) + '%';
  m.className = s.disk.pct >= 90 ? 'full' : s.disk.pct >= 80 ? 'hot' : '';
};
</script>
<?php if ($RAIL === 'structure' || $RAIL === 'setup' || $RAIL === 'rescue'): ?>
<script>
// the admin page draws it with everything else; the other pages ask for it once
fetch('/db/state.php?t=' + Date.now()).then(function (r) { return r.json(); }).then(function (s) {
  if (!s.error) window.railFoot(s);
}).catch(function () {});
</script>
<?php endif; ?>
