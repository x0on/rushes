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
    <h2>Archive</h2>
    <?= $railItem('overview',   'Overview',   'overview',  '/db/admin.php#overview',   true, ' <span class="badge bad" id="nOverview" hidden></span>') ?>
    <?= $railItem('transfers',  'Transfers',  'transfers', '/db/admin.php#transfers',  true, ' <span class="count" id="nTransfers"></span>') ?>
    <?= $railItem('duplicates', 'Duplicates', 'library',   '/db/admin.php#duplicates', true) ?>
    <?= $railItem('cache',      'Cache',      'cache',     '/db/admin.php#cache',      true, ' <span class="count" id="nCache"></span>') ?>
    <?= $railItem('describe',   'Describe',   'ai',    '/db/admin.php#describe',   true) ?>
    <?= $railItem('projects',   "Editors' projects", 'project', '/db/admin.php#projects', true) ?>
    <?= $railItem('structure',  'Reorganize',  'reorganize',  '/structure.php',           false) ?>

    <h2>System</h2>
    <?= $railItem('activity',   'Activity',       'activity', '/db/admin.php#activity', true) ?>
    <?= $railItem('setup',      'Setup',          'tools',    '/setup.php',             false) ?>

    <div class="rail-foot">
      <span class="note" title="One number for the pages, the helper's code and both apps">Rushes <?= htmlspecialchars(rushes_version()) ?></span>
      <span id="fFiles">&mdash;</span>
      <div class="meter"><i id="fMeter"></i></div>
      <span id="fFree">&mdash;</span>
      <form method="post" style="margin-top:10px">
        <button class="nav" name="_signout" value="1" type="submit"
                style="padding-left:9px"><span class="ico">&#8635;</span> Sign out</button>
      </form>
    </div>
  </nav>
<?php if ($RAIL === 'structure' || $RAIL === 'setup'): ?>
<script>
// The same two numbers at the foot of the rail on every admin page, from the
// same place the admin page gets them, so they can never disagree.
fetch('/db/state.php?t=' + Date.now()).then(function (r) { return r.json(); }).then(function (s) {
  if (s.error) return;
  var tb = function (b) { return b >= 1099511627776 ? (b / 1099511627776).toFixed(2) + ' TB'
                                : b >= 1073741824 ? (b / 1073741824).toFixed(1) + ' GB' : Math.round(b / 1048576) + ' MB'; };
  document.getElementById('fFiles').textContent = s.archive.files.toLocaleString() + ' files · ' + tb(s.archive.bytes);
  document.getElementById('fFree').textContent = tb(s.disk.free) + ' free · ' + (s.disk.pct || 0) + '% used';
  var m = document.getElementById('fMeter');
  m.style.width = (s.disk.pct || 0) + '%';
  m.className = s.disk.pct >= 90 ? 'full' : s.disk.pct >= 80 ? 'hot' : '';
}).catch(function () {});
</script>
<?php endif; ?>
