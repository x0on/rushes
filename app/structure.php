<?php
// structure.php — the plan the archive follows.
//
// The department list lives here and only here. Ingest offers exactly this
// list; the tidy-up will move things to match it. Each department is linked to
// the folder it already has on the shelf, so writing the plan renames nothing
// and breaks no Premiere project. Only the tidy-up ever moves files, and only
// after showing you everything first.
$NAV = 'admin';
require __DIR__ . '/db/config.php';
require __DIR__ . '/db/auth.php';
require_sign_in();

$folders = shelf_folders();
$said = ''; $bad = [];
$rows = departments();            // what the page shows; replaced by a paste or a failed save

// ── what they are called, and who may add one ───────────────────────────────
$PRESETS = ['Departments' => 'Department', 'Clients' => 'Client', 'Projects' => 'Project'];
if (($_POST['_kind'] ?? '') === '1') {
    $pick = (string)($_POST['kind'] ?? 'Departments');
    if (isset($PRESETS[$pick])) { $one = $PRESETS[$pick]; $many = $pick; }
    else {
        $one  = trim((string)($_POST['kind_one'] ?? ''));
        $many = trim((string)($_POST['kind_many'] ?? '')) ?: ($one === '' ? '' : $one . 's');
    }
    $shelfPick = (string)($_POST['shelves'] ?? '');
    if ($one === '' || preg_match('#[<>/\\]#', $one . $many)) $bad[] = 'Type the word you use — one and many, e.g. Show / Shows.';
    elseif (!in_array($shelfPick, shelf_choices(), true)) $bad[] = 'Pick the folder they live in, from the list.';
    else {
        $s = settings();
        $s['organise']['kind'] = ['one' => $one, 'many' => $many];
        $s['organise']['shelves'] = $shelfPick;
        $s['organise']['add_at_ingest'] = ($_POST['open'] ?? '') === '1';
        if (save_settings($s)) { header('Location: /structure.php?saved=kind'); exit; }
        $bad[] = 'Could not write settings.json — is the web folder writable?';
    }
}

// ── a pasted list: suggest a folder for each, show it, save nothing yet ────
if (($_POST['_paste'] ?? '') === '1') {
    $names = [];
    foreach (preg_split('/\R/', (string)($_POST['list'] ?? '')) as $n) {
        $n = trim(preg_replace('/\s+/', ' ', $n));
        if ($n !== '' && !in_array(strtolower($n), array_map('strtolower', $names), true)) $names[] = $n;
    }
    $link = guess_links($names, $folders);
    $rows = array_map(fn($n) => ['name' => $n, 'folder' => $link[$n]], $names);
    $found = count(array_filter($link));
    $said = "check: $found of " . count($names) . ' matched to a folder you already have.';
}

// ── saving the list ─────────────────────────────────────────────────────────
if (($_POST['_save'] ?? '') === '1') {
    $rows = []; $seenName = []; $seenFolder = [];
    $names = (array)($_POST['d_name'] ?? []); $links = (array)($_POST['d_folder'] ?? []);
    $names[] = (string)($_POST['add_name'] ?? ''); $links[] = (string)($_POST['add_folder'] ?? '');
    foreach ($names as $i => $n) {
        $n = trim(preg_replace('/\s+/', ' ', (string)$n));
        $f = (string)($links[$i] ?? '');
        if ($n === '' || !empty($_POST['d_drop'][$i])) continue;
        $seenName[strtolower($n)] = true;
        // A catch-all is where everything ends up when nobody is sure. There is
        // no such place: every shoot belongs to somebody. Same rule as Ingest.
        if ($why = shelf_name_problem($n, array_column($rows, 'name'))) $bad[] = $why;
        $rows[] = ['name' => $n, 'folder' => $f];
        if ($f !== '' && !in_array($f, $folders, true)) $bad[] = "The folder “{$f}” is not on the shelf any more.";
        if ($f !== '' && isset($seenFolder[$f])) $bad[] = "$f is linked to both “{$seenFolder[$f]}” and “{$n}”. A folder belongs to one " . strtolower(shelf_word()) . '.';
        if ($f !== '') $seenFolder[$f] = $n;
    }
    if (!$rows) $bad[] = 'The list is empty. Ingest needs at least one ' . strtolower(shelf_word()) . '.';
    if (!$bad) {
        $s = settings();
        $s['organise']['departments'] = $rows;
        if (save_settings($s)) { header('Location: /structure.php?saved=1'); exit; }
        $bad[] = 'Could not write settings.json — is the web folder writable?';
    }
}
if (isset($_GET['saved'])) $said = $_GET['saved'] === 'kind' ? 'kind' : 'ok';
$ONE = shelf_word(); $MANY = shelf_word(true); $one = strtolower($ONE); $many = strtolower($MANY);
$kindNow = shelf_word(true);

$e = fn($x) => htmlspecialchars((string)$x);
$linked = array_filter(array_column($rows, 'folder'));
$loose  = array_values(array_diff($folders, $linked));
$shelf  = basename(shelf_dir());
$opts = function (string $cur) use ($folders, $e) {
    $o = '<option value="">— a new folder, made the first time</option>';
    foreach ($folders as $f) $o .= '<option value="' . $e($f) . '"' . ($f === $cur ? ' selected' : '') . '>' . $e($f) . '</option>';
    return $o;
};
?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reorganize &middot; <?= $e(settings()['name'] ?? 'Rushes') ?></title>
<?php require __DIR__ . '/head.php'; ?>
<style>
  .form { max-width: 820px }
  .dep { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: center;
         padding: 7px 0; border-top: 1px solid var(--line-soft) }
  .dep:first-of-type { border-top: 0 }
  .kinds { display: flex; gap: 8px; flex-wrap: wrap }
  .opt { border: 1px solid var(--line); border-radius: var(--radius); padding: 12px 14px;
         background: var(--bg); cursor: pointer; display: block; position: relative }
  .opt.sm { padding: 8px 14px }
  .opt:has(input:checked) { border-color: var(--accent); background: var(--sel-bg); color: var(--sel-fg) }
  .opt b { display: block; font-size: 13.5px }
  .opt small { color: var(--muted); font-size: 12.5px; line-height: 1.5; display: block; margin-top: 4px }
  .opt:has(input:checked) small { color: var(--sel-fg); opacity: .8 }
  .opt input { position: absolute; opacity: 0; pointer-events: none }
  .pick { display: grid; grid-template-columns: 1fr 1fr; gap: 10px }
  .two-in { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 12px; max-width: 420px }
  @media (max-width: 640px) { .pick { grid-template-columns: 1fr } }
  .dep.new select { color: var(--muted) }
  .dep label.x { font-size: 12.5px; color: var(--muted); white-space: nowrap; cursor: pointer }
  .dep-h { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; font-size: 11px;
           text-transform: uppercase; letter-spacing: .07em; color: var(--faint); font-weight: 650;
           margin: 0 0 6px }
  .dep-h span:last-child { width: 62px }
  textarea { width: 100%; min-height: 180px; padding: 10px 12px; font: 13.5px/1.5 var(--font);
             color: var(--fg); background: var(--bg); border: 1px solid var(--line);
             border-radius: var(--radius-sm); resize: vertical }
  .loose { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 }
  .loose span { font-size: 12.5px; padding: 4px 10px; border-radius: 999px;
                border: 1px dashed var(--line); color: var(--muted) }
  .path { font-family: var(--mono); font-size: 13px; background: var(--bg); padding: 10px 12px;
          border-radius: var(--radius-sm); border: 1px solid var(--line) }
  .path b { color: var(--accent-text); font-weight: 600 }
  @media (max-width: 640px) { .dep, .dep-h { grid-template-columns: 1fr } .dep-h { display: none } }
  .tg { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,260px); gap: 14px; padding: 10px 0;
        border-top: 1px solid var(--line-soft); align-items: start }
  .dep-h.tg { border-top: 0; padding: 0 }
  .tg b { font-size: 13.5px; font-weight: 600; word-break: break-word }
  .tg small { display: block; color: var(--muted); font-size: 12.5px; margin-top: 3px; word-break: break-word }
  .tg small.flag { color: var(--warn, var(--accent-text)) }
  .tg select { width: 100% }
  .tg .to { color: var(--accent-text) }
  .tg .to.stay { color: var(--faint) }
  .run { display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 8px 0;
         border-top: 1px solid var(--line-soft); font-size: 13px }
  .run small { display: block; color: var(--faint); font-size: 11.5px; font-family: var(--mono) }
  @media (max-width: 640px) { .tg { grid-template-columns: 1fr } .dep-h.tg { display: none } }
  .intro .parts { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; margin-top: 12px }
  .intro .parts > div { border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; background: var(--bg) }
  .intro .parts p { margin: 6px 0 0 }
  h3.part { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin: 26px 0 10px }
</style>

<div class="app">
<div class="with-rail">
<?php $RAIL = 'structure'; require __DIR__ . '/db/rail.php'; ?>

  <main class="work">
  <div class="pad form">
    <div class="head"><h1>Reorganize</h1>
      <span class="sub">How the archive should be organised, and moving what is already here into it.</span></div>

    <!-- What this page is, for someone who did not build it. -->
    <div class="grp intro">
      <h2>What this is</h2>
      <p>An archive that grew over years has every shoot organised a different way: by date in one
         place, by whoever filed it in another, the same event in three folders. This page gives it
         <b>one shape</b>: a shelf per <?= $e($one) ?>, and inside it every shoot filed by year and date.
         It works in two parts, and nothing moves until you say so.</p>
      <div class="parts">
        <div><b>Part 1 · The plan</b> <span class="note">(00 to 03)</span>
          <p>Say what your top folders are, which folder on the shelf each <?= $e($one) ?> uses, and how a
             shoot's folder is named. Saving the plan moves nothing. From then on, <b>Ingest</b> files every
             new card straight into it, so new footage is never out of place.</p></div>
        <div><b>Part 2 · Moving what is already here</b> <span class="note">(04 · Tidy-up)</span>
          <p>Footage copied in from another server arrives as an exact copy of that server, in ARCHIVE.
             Tidy-up proposes where each of its folders belongs on the shelf. You check it, approve it,
             and the helper moves it: every move recorded, so it can be put back and Premiere projects
             can be relinked.</p></div>
      </div>
      <p class="note" style="margin:10px 0 0"><b>Why bother:</b> every shoot in one predictable place;
         searching or filtering by <?= $e($one) ?> works; the same footage stops landing in three
         folders; and anyone can find last year's event without knowing who filed it.</p>
    </div>

    <h3 class="part">Part 1 · The plan</h3>

    <!-- ══ 00 what they are, and who adds them ══ -->
    <form class="grp" method="post">
      <h2><span>00 /</span> What your top folders are</h2>
      <p>The same shape whatever you call them &mdash; only the word changes, everywhere Rushes says it.</p>
      <div class="kinds">
        <?php foreach ($PRESETS as $m => $o): ?>
          <label class="opt sm"><input type="radio" name="kind" value="<?= $e($m) ?>" <?= $kindNow === $m ? 'checked' : '' ?>><b><?= $e($m) ?></b></label>
        <?php endforeach; $custom = !isset($PRESETS[$kindNow]); ?>
        <label class="opt sm"><input type="radio" name="kind" value="custom" id="kCustom" <?= $custom ? 'checked' : '' ?>><b>Another word</b></label>
      </div>
      <div class="two-in" id="kWords" <?= $custom ? '' : 'hidden' ?>>
        <label class="f"><span>One</span><input type="text" name="kind_one" value="<?= $custom ? $e($ONE) : '' ?>" placeholder="Show"></label>
        <label class="f"><span>Many</span><input type="text" name="kind_many" value="<?= $custom ? $e($MANY) : '' ?>" placeholder="Shows"></label>
      </div>

      <p style="margin:18px 0 8px;color:var(--fg);font-size:13px"><b>Which folder in <?= $e(settings()['archive']['label'] ?? 'the archive') ?> they live in</b></p>
      <label class="f"><select name="shelves">
        <?php if (shelf_name() === ''): ?><option value="">— pick one (Ingest and the tidy-up wait for this)</option><?php endif; ?>
        <?php foreach (shelf_choices() as $f): ?>
          <option value="<?= $e($f) ?>" <?= $f === shelf_name() ? 'selected' : '' ?>><?= $e($f) ?></option>
        <?php endforeach; ?>
      </select>
      <small>The shelf: every <?= $e($one) ?> has its own folder inside it, and every shoot is filed there.
        Make the folder in the archive first if it is not in the list.</small></label>

      <p style="margin:18px 0 8px;color:var(--fg);font-size:13px"><b>Who can add a new <?= $e($one) ?>?</b></p>
      <div class="pick">
        <label class="opt"><input type="radio" name="open" value="0" <?= !shelf_open() ? 'checked' : '' ?>>
          <b>Only here, by the admin</b>
          <small>For a list that hardly changes &mdash; departments, regular clients.
                 Someone at Ingest can only pick from it.</small></label>
        <label class="opt"><input type="radio" name="open" value="1" <?= shelf_open() ? 'checked' : '' ?>>
          <b>Anyone, while bringing a shoot in</b>
          <small>For a list that grows every week &mdash; projects. Ingest gets
                 &ldquo;add a new one&rdquo;; the no-catch-all rule still applies.</small></label>
      </div>
      <input type="hidden" name="_kind" value="1">
      <div class="btns" style="margin-top:14px"><button class="btn quiet" type="submit">Save these three</button></div>
    </form>
    <script>
      document.querySelectorAll('[name=kind]').forEach(function (r) {
        r.onchange = function () { document.getElementById('kWords').hidden = !document.getElementById('kCustom').checked; }; });
    </script>

    <?php if ($said === 'kind'): ?>
      <div class="banner ok"><div class="txt">Saved. Rushes now says &ldquo;<?= $e($MANY) ?>&rdquo;, they live in <?= $e(shelf_name()) ?>, and <?= shelf_open() ? 'anyone can add one at Ingest' : 'only the admin adds them, here' ?>.</div></div>
    <?php elseif ($said === 'ok'): ?>
      <div class="banner ok"><div class="txt">Saved. Ingest offers exactly this list from now on. Nothing on disk was moved.</div></div>
    <?php elseif ($bad): ?>
      <div class="banner bad"><div class="txt"><b>Nothing was saved.</b><?= implode('<br>', array_map($e, $bad)) ?></div></div>
    <?php elseif ($said): ?>
      <div class="banner warn"><div class="txt"><b>Not saved yet &mdash; <?= $e($said) ?></b>
        Check each link below, fix any that are wrong, then press Save.</div></div>
    <?php endif; ?>

    <?php if (!$rows): ?>
    <!-- ══ first time: start from a list ══ -->
    <form class="grp" method="post">
      <h2><span>01 /</span> Your <?= $e($many) ?></h2>
      <p>Every shoot goes on one of these shelves, and Ingest offers exactly this list &mdash;
         nothing else, and no &ldquo;Others&rdquo;. Paste them one per line. Rushes suggests
         which folder you already have for each one; you check it before anything is saved.</p>
      <textarea name="list" placeholder="Parks &amp; Recreation&#10;Police Department&#10;Public Works Department&#10;…" autofocus></textarea>
      <input type="hidden" name="_paste" value="1">
      <div class="btns" style="margin-top:12px">
        <button class="btn" type="submit">Suggest folders</button>
        <span class="note">Saves nothing yet.</span>
      </div>
    </form>
    <?php else: ?>
    <!-- ══ the list, each linked to its folder ══ -->
    <form class="grp" method="post">
      <h2><span>01 /</span> Your <?= $e($many) ?></h2>
      <p>Every shoot goes on one of these shelves, and Ingest offers exactly this list.
         A linked folder is used as it is &mdash; nothing is renamed, so no Premiere project breaks.</p>
      <div class="dep-h"><span><?= $e($ONE) ?></span><span>Its folder in <?= $e($shelf) ?></span><span></span></div>
      <?php foreach ($rows as $i => $r): ?>
        <div class="dep<?= ($r['folder'] ?? '') === '' ? ' new' : '' ?>">
          <input type="text" name="d_name[<?= $i ?>]" value="<?= $e($r['name']) ?>" aria-label="<?= $e($ONE) ?>">
          <select name="d_folder[<?= $i ?>]" aria-label="Its folder"><?= $opts($r['folder'] ?? '') ?></select>
          <label class="x"><input type="checkbox" name="d_drop[<?= $i ?>]" value="1"> remove</label>
        </div>
      <?php endforeach; ?>
      <div class="dep" style="border-top:1px solid var(--line);margin-top:6px;padding-top:12px">
        <input type="text" name="add_name" placeholder="add a <?= $e($one) ?>" aria-label="New <?= $e($one) ?>">
        <select name="add_folder" aria-label="Its folder"><?= $opts('') ?></select>
        <span></span>
      </div>
      <input type="hidden" name="_save" value="1">
      <div class="btns" style="margin-top:14px">
        <button class="btn" type="submit">Save</button>
        <span class="note">Writes the plan. Moves nothing.</span>
      </div>
    </form>
    <?php endif; ?>

    <!-- ══ what is on the shelf but not in the plan ══ -->
    <div class="grp">
      <h2><span>02 /</span> Folders not in the plan</h2>
      <?php if ($loose): ?>
        <p>Folders already on the shelf that no <?= $e($one) ?> in the plan uses. They stay exactly where
           they are and stay searchable; Ingest never offers them. Tidy-up (part 2) is where each one gets a home.</p>
        <div class="loose"><?php foreach ($loose as $f): ?><span><?= $e($f) ?></span><?php endforeach; ?></div>
      <?php else: ?>
        <p style="margin:0">None &mdash; every folder on the shelf belongs to a <?= $e($one) ?>.</p>
      <?php endif; ?>
    </div>

    <!-- ══ how a path is built ══ -->
    <div class="grp">
      <h2><span>03 /</span> How a shoot's folder is named</h2>
      <p>Every shoot that comes through Ingest lands like this. The date comes from the camera,
         not from whoever brings the card in.</p>
      <div class="path"><?= $e($shelf) ?> / <b><?= $e($one) ?></b> / <b>year</b> / <b>date</b> <b>what it was</b>
        <div class="note" style="margin-top:6px;font-family:var(--font)">e.g. <?= $e($shelf) ?> / PARKS / 2026 / 20260926 Spring Festival</div></div>
    </div>

    <!-- ══ the tidy-up ══ -->
    <h3 class="part">Part 2 · Moving what is already here</h3>
    <div class="grp" id="tidy">
      <h2><span>04 /</span> Tidy-up</h2>
      <p>Moves what the copies brought into ARCHIVE onto the shelf. Where each file goes is read
         from where it <i>came from</i>, not where the copy put it &mdash; and everything below the
         <?= $e($one) ?> keeps the layout it had. Nothing is copied, renamed or deleted, every
         move is recorded so Premiere projects can be relinked, and a tidy-up can be put back.</p>
      <div id="tWait" class="banner warn" hidden><div class="txt"></div></div>
      <div id="tList"><p class="note" style="margin:0">Reading the records of what was copied &hellip;</p></div>
      <div id="tGo" class="btns" style="margin-top:14px" hidden>
        <button class="btn" type="button" id="tAsk"></button>
        <span class="note" id="tSum"></span>
      </div>
      <div id="tSure" class="banner warn" hidden><div class="txt">
        <b id="tSureWhat"></b>
        The helper on <span class="tHelper"></span> moves them one file at a time, never over another
        file and never while its folder is still being copied. It shows its progress in the top bar.
        <div class="btns" style="margin-top:10px">
          <button class="btn" type="button" id="tYes">Yes, move them</button>
          <button class="btn quiet" type="button" id="tNo">Not yet</button>
        </div></div></div>
      <div id="tRuns"></div>
    </div>

    <!-- ══ Premiere projects after a tidy-up ══ -->
    <div class="grp" id="relink">
      <h2><span>05 /</span> Premiere projects after a tidy-up</h2>
      <p>A tidy-up moves footage, so a Premiere project that used it opens with &ldquo;media offline&rdquo;.
         Choose the project here and Rushes points every clip at where the tidy-up put it, from the record
         of every move. You get a copy, &ldquo;<i>name</i> (relinked).prproj&rdquo;; the project you chose is not changed.
         It is read on this computer: only the file paths written in it are sent to Rushes, never the project.</p>
      <div class="btns">
        <button class="btn" type="button" id="rlGo">Choose a Premiere project&hellip;</button>
        <input type="file" id="rlPick" accept=".prproj" hidden>
      </div>
      <p class="note" id="rlSaid" style="margin:10px 0 0"></p>
      <div id="rlOut"></div>
    </div>
    <script>
    (function () {
      var $ = function (id) { return document.getElementById(id); };
      var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
      var ENT = {amp: '&', lt: '<', gt: '>', quot: '"', apos: "'"};
      var dec = function (s) { return s.replace(/&(#x[0-9a-f]+|#\d+|\w+);/gi, function (m, e) {
        return e[0] === '#' ? String.fromCodePoint(e[1].toLowerCase() === 'x' ? parseInt(e.slice(2), 16) : +e.slice(1)) : (ENT[e] || m); }); };
      var enc = function (s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
      var TEXT = />([^<>]{3,2000})</g;                 // every piece of text in the project
      var isPath = function (s) { return /[\\/]/.test(s) && /\.\w{2,5}$/.test(s); };
      $('rlGo').onclick = function () { $('rlPick').value = ''; $('rlPick').click(); };
      $('rlPick').onchange = async function () {
        var f = (this.files || [])[0], said = $('rlSaid'), out = $('rlOut');
        if (!f) return;
        out.innerHTML = ''; said.textContent = 'Reading ' + f.name + ' on this computer…';
        try {
          if (!window.DecompressionStream) throw new Error('this browser is too old to open a Premiere project (Safari 16.4 or newer, or Chrome, is needed)');
          var buf = await f.arrayBuffer(), b = new Uint8Array(buf, 0, 2);
          var xml = b[0] === 0x1f && b[1] === 0x8b      // a .prproj is gzipped XML
            ? await new Response(new Blob([buf]).stream().pipeThrough(new DecompressionStream('gzip'))).text()
            : new TextDecoder().decode(buf);
          if (!/<PremiereData/.test(xml.slice(0, 4000))) throw new Error(f.name + ' is not a Premiere project');
          var found = new Set();
          xml.replace(TEXT, function (m, t) { var d = dec(t); if (isPath(d)) found.add(d); return m; });
          said.textContent = 'Asking Rushes where the ' + found.size.toLocaleString() + ' files this project names are now…';
          var r = await (await fetch('/db/relink.php', {method: 'POST', body: new URLSearchParams({paths: JSON.stringify(Array.from(found))})})).json();
          if (r.error) throw new Error(r.error);
          if (!r.moved) {
            said.textContent = '';
            out.innerHTML = '<div class="banner ok"><div class="txt"><b>Nothing in ' + esc(f.name) + ' was moved by a tidy-up.</b> ' +
              (r.tidyups ? 'Its ' + found.size.toLocaleString() + ' files are where the project expects them, as far as Rushes moved them.'
                         : 'No tidy-up has run yet, so every file is where it was.') + ' No copy is needed.</div></div>';
            return;
          }
          var n = 0, fixed = xml.replace(TEXT, function (m, t) { var to = r.map[dec(t)]; if (!to) return m; n++; return '>' + enc(to) + '<'; });
          var gz = await new Response(new Blob([fixed]).stream().pipeThrough(new CompressionStream('gzip'))).blob();
          var name = f.name.replace(/\.prproj$/i, '') + ' (relinked).prproj', url = URL.createObjectURL(gz);
          said.textContent = '';
          out.innerHTML = '<div class="banner ok"><div class="txt"><b>' + r.moved.toLocaleString() + ' file' + (r.moved === 1 ? '' : 's') +
            ' pointed at where the tidy-up put ' + (r.moved === 1 ? 'it' : 'them') + '</b> (' + n.toLocaleString() + ' places in the project); ' +
            r.kept.toLocaleString() + ' left as they were. Open the copy in Premiere; the one you chose is unchanged.' +
            (r.missing.length ? '<br><b>' + r.missing.length + ' of them are not where the last tidy-up put them</b> — moved since, outside Rushes: ' +
              r.missing.slice(0, 5).map(esc).join(', ') + (r.missing.length > 5 ? ' …' : '') : '') +
            '<div class="btns" style="margin-top:10px"><a class="btn" id="rlGet" href="' + url + '" download="' + esc(name) + '">Save ' + esc(name) + '</a></div></div></div>';
        } catch (e) { said.textContent = 'Could not relink it: ' + e.message; }
      };
    })();
    </script>
    <script>
    (function () {
      var SHELF = <?= json_encode($shelf, JSON_UNESCAPED_UNICODE) ?>, data = null;
      var $ = function (id) { return document.getElementById(id); };
      var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
      var size = function (b) { return b >= 1e12 ? (b / 1e12).toFixed(1) + ' TB' : b >= 1e9 ? Math.round(b / 1e9) + ' GB' : Math.max(1, Math.round(b / 1e6)) + ' MB'; };
      var num = function (n) { return n.toLocaleString(); };
      var shown = function (r) {       // share / Departments / Parks & Recreation / 2024
        var from = r.root.slice(0, r.root.lastIndexOf('/') + 1);
        return r.key.slice(from.length).split('/').join(' / ');
      };
      var dest = function (r, dept) {
        var d = data.depts.filter(function (x) { return x.name === dept; })[0];
        if (!d) return '';
        var tail = r.base !== '' ? r.key.slice(r.base.length)
                 : '/' + (r.key.slice(r.root.length + 1) || r.root.split('/').pop());
        return (SHELF + '/' + d.folder + tail).split('/').join(' / ');
      };

      function sum() {
        var n = 0, b = 0, rows = 0;
        data.groups.forEach(function (r, i) {
          var v = $('tp' + i).value;
          $('td' + i).innerHTML = v ? '&rarr; ' + esc(dest(r, v)) : 'stays in ARCHIVE';
          $('td' + i).className = 'to' + (v ? '' : ' stay');
          if (v) { n += r.n; b += r.bytes; rows++; }
        });
        $('tGo').hidden = !data.groups.length;
        $('tAsk').disabled = !n;
        $('tAsk').textContent = n ? 'Move ' + num(n) + ' files (' + size(b) + ')' : 'Nothing picked to move';
        $('tSum').textContent = n ? 'from ' + rows + ' folder' + (rows > 1 ? 's' : '') + '. Asks first.' : '';
        $('tSure').hidden = true;
        return {n: n, b: b, rows: rows};
      }

      function draw(d) {
        data = d;
        document.querySelectorAll('.tHelper').forEach(function (x) { x.textContent = d.helper; });
        $('tWait').hidden = !d.waiting.length;
        $('tWait').querySelector('.txt').innerHTML = d.waiting.length
          ? '<b>A tidy-up is waiting for the helper.</b> It runs next, before any copy, as soon as the helper on '
            + esc(d.helper) + ' is running. Moving more now adds another one behind it.' : '';
        if (!d.groups.length) {
          $('tList').innerHTML = '<p class="note" style="margin:0">' + (d.records
            ? 'Nothing to tidy &mdash; everything the copies brought in is on the shelf already.'
            : 'Nothing to tidy yet. Every copy writes a record of where each file came from, and the tidy-up works from those &mdash; none has been written so far.') + '</p>';
        } else {
          var opts = '<option value="">— leave it in ARCHIVE —</option>' + d.depts.map(function (x) {
            return '<option value="' + esc(x.name) + '">' + esc(x.name) + '</option>'; }).join('');
          $('tList').innerHTML = '<div class="dep-h tg"><span>Came from</span><span>Goes to</span></div>'
            + d.groups.map(function (r, i) {
              return '<div class="tg' + (r.busy ? ' busy' : '') + '"><div><b>' + esc(shown(r)) + '</b>'
                + '<small>' + num(r.n) + ' file' + (r.n > 1 ? 's' : '') + ' · ' + size(r.bytes)
                + (r.eg ? ' · e.g. …' + esc(r.eg) : '') + '</small>'
                + (r.busy ? '<small class="flag">Still being copied &mdash; those files wait for the next tidy-up.</small>' : '')
                + (r.dept ? '' : '<small>No ' + <?= json_encode(strtolower($one)) ?> + ' in its path &mdash; pick one, or leave it.</small>')
                + '</div><div><select id="tp' + i + '" aria-label="Goes to">' + opts + '</select>'
                + '<small id="td' + i + '" class="to"></small></div></div>';
            }).join('');
          d.groups.forEach(function (r, i) { $('tp' + i).value = r.dept || ''; $('tp' + i).onchange = sum; });
        }
        $('tRuns').innerHTML = d.runs.length ? '<p style="margin:18px 0 6px;color:var(--fg);font-size:13px"><b>Done so far</b></p>'
          + d.runs.map(function (r) {
            return '<div class="run"><span>' + esc(r.ago) + ' &mdash; ' + num(r.moved) + ' moved (' + size(r.bytes) + ')'
              + (r.left ? ', ' + num(r.left) + ' left where they were' : '') + '<small>' + esc(r.record) + '</small></span>'
              + (r.undone ? '<span class="note">put back</span>'
                          : '<button class="btn quiet sm" type="button" data-undo="' + esc(r.record) + '">Put back</button>') + '</div>';
          }).join('') : '';
        if (d.groups.length) sum(); else $('tGo').hidden = true;
      }

      function load() {
        fetch('/db/tidy.php?t=' + Date.now()).then(function (r) { return r.json(); }).then(function (d) {
          if (d.error) throw new Error(d.error);
          draw(d);
        }).catch(function (e) {
          $('tList').innerHTML = '<div class="banner bad"><div class="txt"><b>Could not read the records.</b> ' + esc(e.message) + '</div></div>';
        });
      }

      function post(body, done) {
        fetch('/db/tidy.php', {method: 'POST', body: body}).then(function (r) { return r.json(); }).then(function (d) {
          if (d.error) throw new Error(d.error);
          done(d); load();
        }).catch(function (e) {
          $('tWait').hidden = false; $('tWait').className = 'banner bad';
          $('tWait').querySelector('.txt').innerHTML = '<b>Nothing was queued.</b> ' + esc(e.message);
        });
      }

      $('tAsk').onclick = function () {
        var s = sum();
        $('tSureWhat').textContent = 'Move ' + num(s.n) + ' files (' + size(s.b) + ') from ' + s.rows + ' folder' + (s.rows > 1 ? 's' : '') + ' onto ' + SHELF + '?';
        $('tSure').hidden = false; $('tYes').focus();
      };
      $('tNo').onclick = function () { $('tSure').hidden = true; };
      $('tYes').onclick = function () {
        var f = new FormData(), picks = {}; f.append('go', '1');
        data.groups.forEach(function (r, i) { if ($('tp' + i).value) picks[r.key] = $('tp' + i).value; });
        f.append('picks', JSON.stringify(picks));
        $('tYes').disabled = true; $('tYes').textContent = 'Queuing…';
        post(f, function (d) {
          $('tYes').disabled = false; $('tYes').textContent = 'Yes, move them';
          $('tWait').className = 'banner ok'; $('tWait').hidden = false;
          $('tWait').querySelector('.txt').innerHTML = '<b>Queued ✓</b> ' + num(d.files) + ' files. The helper on ' + esc(data.helper) + ' does it next &mdash; watch the top bar.';
        });
      };
      $('tRuns').onclick = function (ev) {
        var b = ev.target.closest('[data-undo]'); if (!b) return;
        if (b.dataset.sure !== '1') { b.dataset.sure = '1'; b.textContent = 'Sure? Put them back'; return; }
        var f = new FormData(); f.append('undo', b.dataset.undo);
        b.disabled = true; b.textContent = 'Queuing…';
        post(f, function () { b.textContent = 'Queued ✓'; });
      };
      load();
    })();
    </script>
  </div>
  </main>
</div>
</div>
