<?php
// side.php — the Activity column beside the work: what has been happening, newest first,
// the same on every Manage page that does work (Overview, Copying, Reorganize, Duplicates,
// Cache, Describe, Editors' projects). Not on Activity (the full list) nor on Setup.
//
// The Manage page draws it on its own refresh ($SIDE_SELF unset); any other page that
// includes it sets $SIDE_SELF = true and it keeps itself fresh from state.php.
$SIDE_SELF = $SIDE_SELF ?? false;
?>
<style>
  .with-side { grid-template-columns: var(--rail-w) minmax(0,1fr) 296px }
  .side { background: var(--surface); border-left: 1px solid var(--line);
          display: flex; flex-direction: column; min-height: 0 }
  .side-h { display: flex; align-items: center; gap: 8px; padding: 12px 14px;
            border-bottom: 1px solid var(--line); font-size: 11px; font-weight: 650;
            letter-spacing: .09em; text-transform: uppercase; color: var(--faint) }
  .side-h .ghost { margin-left: auto; text-transform: none; letter-spacing: 0 }
  .side-body { flex: 1; overflow-y: auto; min-height: 0 }
  .side-f { border-top: 1px solid var(--line); padding: 10px 14px; font-size: 11.5px;
            color: var(--muted); flex: none }
  .side .ev { padding: 9px 14px }
  .with-side.no-side { grid-template-columns: var(--rail-w) minmax(0,1fr) } .no-side > .side { display: none }
  @media (max-width: 1200px) { .with-side { grid-template-columns: var(--rail-w) 1fr }
                               .side { display: none } }
  @media (max-width: 900px)  { .with-side { grid-template-columns: 1fr } }
</style>
  <!-- The log lives beside the work, not behind a tab: half the point is seeing that something is still moving. -->
  <aside class="side" aria-label="Recent activity">
    <div class="side-h">Activity
      <a class="ghost" id="sideMore" href="/db/admin.php#activity">all</a></div>
    <div class="side-body" id="sideLog"><div class="empty">Nothing yet.</div></div>
    <div class="side-f" id="sideNow">&mdash;</div>
  </aside>
<?php if ($SIDE_SELF): ?>
<script>
(function () {
  // The same rows as Manage's (admin.php), from the same answer (state.php), every 15 seconds.
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
  var ICO = {in: ['↓', 'ok'], out: ['↑', ''], changed: ['•', ''], check: ['✓', 'ok'], problem: ['!', 'bad'], people: ['☺', '']};
  var ev = function (r) {
    var i = ICO[r.kind] || ICO.changed;
    return '<div class="ev" data-k="' + esc(r.kind) + '"><span class="ico ' + i[1] + '">' + i[0] + '</span><div class="t">' +
      (r.who ? '<b>' + esc(r.who) + '</b> · ' : '') + esc(r.text) + '</div><span class="when">' + esc(String(r.at).slice(5, 16)) + '</span></div>';
  };
  async function draw() {
    if (document.hidden) return;
    try {
      var d = await (await fetch('/db/state.php?t=' + Date.now())).json(), recent = d.recent || [];
      document.getElementById('sideLog').innerHTML = (d.running
          ? '<div class="ev"><span class="ico">•</span><div class="t">' + esc(d.running) + '<small>running now' +
            (d.progress ? ' · ' + d.progress.pct + '%' : '') + '</small></div></div>' : '') +
        (recent.length ? recent.slice(0, 12).map(ev).join('') : '<div class="empty">Nothing yet.</div>');
      document.getElementById('sideNow').textContent = d.runner && d.runner.ok ? 'Picking up jobs · checked ' + (d.runner.ago || 'just now')
        : d.runner && d.runner.stopped ? 'Stopped: nothing runs until Start' : 'Not picking up jobs';
    } catch (e) { document.getElementById('sideNow').textContent = 'Could not reach Rushes: ' + e.message; }
  }
  draw(); setInterval(draw, 15000);
})();
</script>
<?php endif; ?>
