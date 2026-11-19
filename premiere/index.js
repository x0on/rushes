// Rushes inside Premiere: search the archive, and Import copies the clip to a faster drive, then brings the
// copy into the open project. The copying is Rushes' own (pulls.php action=copy, runner.py pull_copy):
// a clip asked for here goes into a pull named after the Premiere project, so each project has one folder,
// <drive>/Rushes pulls/<project>/, and Rushes remembers what footage a project took.
// ponytail: the Mac version: Rushes on this same Mac, so a path Rushes writes is a path Premiere reads.
//   When Rushes lives on the NAS: an address field here, and the NAS path turned into this Mac's path.
// ponytail: imports into the project's top bin; a bin per pull when someone asks.
// ponytail: one import at a time, as the runner copies one pull at a time.
const BASE = (typeof window !== 'undefined' && window.RUSHES) || 'http://127.0.0.1:8642';
const ppro = (function () { try { return require('premierepro'); } catch (e) { return null; } })();

const $ = function (id) { return document.getElementById(id); };
const esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
let busy = false, pulls = [], copying = '';

async function api(path, post) {
  let r;
  try {
    r = await fetch(BASE + path, post ? { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: Object.keys(post).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(post[k]); }).join('&') } : undefined);
  } catch (e) { throw new Error('Rushes is not answering. Is it running on this Mac? (its icon in the menu bar)'); }
  let d = null; try { d = await r.json(); } catch (e) { /* not JSON: said below */ }
  if (!r.ok || !d) throw new Error((d && d.error) || 'Rushes answered ' + r.status + (r.status === 404 ? ': it needs to be 0.14.1 or newer' : ''));
  return d;
}

function status(s, bad) { $('status').textContent = s; $('status').className = bad ? 'bad' : ''; }
function said(s, pct) {
  $('work').className = ''; $('said').textContent = s;
  if (pct != null) $('fill').style.width = Math.max(0, Math.min(100, pct)) + '%';
}
function lock(on) {
  busy = on; $('stop').className = on ? '' : 'hide';
  const b = document.querySelectorAll('#list button'); for (let i = 0; i < b.length; i++) b[i].disabled = on;
}
async function project() {
  if (!ppro) return null;
  try { return await ppro.Project.getActiveProject(); } catch (e) { return null; }
}
const projName = function (p) { return String((p && p.name) || '').replace(/\.prproj$/i, '').trim(); };
const gb = function (b) { return b >= 1e9 ? (b / 1e9).toFixed(1) + ' GB' : Math.round(b / 1e6) + ' MB'; };
const sleep = function (ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); };

async function start() {
  try {
    const d = await api('/db/pulls.php?places=1');
    const was = (function () { try { return localStorage.getItem('rushes.place'); } catch (e) { return null; } })();
    $('place').innerHTML = d.places.map(function (p) {
      return '<option value="' + esc(p.to) + '"' + (p.to === was ? ' selected' : '') + '>' + esc(p.to === 'desktop' ? 'Desktop' : p.name) + (p.free ? ' (' + gb(p.free) + ' free)' : '') + '</option>';
    }).join('');
    if (!d.places.length) return status('Rushes ' + d.version + ' answered, but it has nowhere to copy to: copying is done by the Rushes app on a Mac.', true);
    const p = await project();
    status('Rushes ' + d.version + ' · ' + (p ? 'project: ' + projName(p) : 'no project open in Premiere'), !p);
    await showPulls();
  } catch (e) { status(e.message, true); }
}

function row(o) {
  return '<div class="it">' + (o.img ? '<img src="' + esc(o.img) + '">' : '') + '<div class="tx"><div class="nm">' + esc(o.name) + '</div><div class="wh">' + esc(o.what) + '</div></div>'
    + '<button data-' + o.key + '="' + esc(o.val) + '">Import</button></div>';
}
async function showPulls() {
  pulls = (await api('/db/pulls.php?list=1')).pulls;
  const has = pulls.filter(function (p) { return p.n > 0; });
  $('list').innerHTML = '<h4>Pulls</h4>' + (has.length ? has.map(function (p) {
    return row({ name: p.name, what: p.n + (p.n === 1 ? ' clip · ' : ' clips · ') + gb(p.b), key: 'pull', val: p.slug });
  }).join('') : '<div class="none">No pulls yet. Search above, or make a pull in Rushes.</div>');
}
async function search() {
  const q = $('q').value.trim(); if (!q) return showPulls().catch(function (e) { status(e.message, true); });
  $('list').innerHTML = '<div class="none">Searching…</div>';
  try {
    const d = await api('/db/search.php?limit=40&q=' + encodeURIComponent(q));
    const seen = {}, out = [];
    ((d.moments && d.moments.rows) || []).slice(0, 40).forEach(function (m) {
      if (seen[m.path]) return; seen[m.path] = 1;
      out.push(row({ name: m.name, what: m.what || '', img: BASE + '/db/thumb.php?fp=' + encodeURIComponent(m.fp) + '&shot=' + (+m.shot || 0), key: 'clip', val: m.path }));
    });
    (d.rows || []).forEach(function (f) {
      if (seen[f.path] || f.kind === 'project' || f.kind === 'sidecar') return; seen[f.path] = 1;
      out.push(row({ name: f.name, what: [f.dept, f.year, f.event].filter(Boolean).join(' · '), key: 'clip', val: f.path }));
    });
    $('list').innerHTML = '<h4>Found for “' + esc(q) + '”</h4>' + (out.join('') || '<div class="none">Nothing found.</div>')
      + (out.length >= 40 ? '<div class="none">These are the first; Rushes itself shows them all.</div>' : '');
    lock(busy);
  } catch (e) { $('list').innerHTML = '<div class="none">' + esc(e.message) + '</div>'; }
}

// Copy the pull to the place chosen, saying how it goes, then import what was asked for (only: the paths; all if none given).
async function copyAndImport(slug, only, label) {
  const proj = await project();
  if (!proj) throw new Error('Open a project in Premiere first: the clips are imported into it.');
  const to = $('place').value; try { localStorage.setItem('rushes.place', to); } catch (e) { /* remembered only where it can be */ }
  said('Asking Rushes to copy ' + label + '…', 0);
  await api('/db/pulls.php', { action: 'copy', p: slug, to: to });
  copying = slug;
  let c, asked = Date.now();
  for (;;) {
    await sleep(1000);
    c = (await api('/db/pulls.php?p=' + encodeURIComponent(slug))).copy || { state: 'asked' };
    if (c.state === 'asked') {
      if (Date.now() - asked > 90000) throw new Error('Rushes has not started the copy after 90 seconds. Another copy may be running: look at Activity in Rushes.');
      said('Waiting for Rushes to start the copy…', 0);
    } else if (c.state === 'copying') {
      said('Copying ' + c.n + ' of ' + c.total + (c.file ? ' · ' + c.file : '') + '\n' + gb(c.bytes) + ' of ' + gb(c.total_bytes), c.total_bytes ? 100 * c.bytes / c.total_bytes : 0);
    } else break;
  }
  if (c.state !== 'done') throw new Error('The copy ' + (c.state === 'stopped' ? 'was stopped' : 'failed') + ': ' + (c.why || 'no reason given') + '. Nothing was imported.');
  const files = (c.files || []).filter(function (f) { return !only || only.indexOf(f[0]) >= 0; }).map(function (f) { return f[1]; });
  const bad = (c.failed || []).map(function (f) { return '\n  ' + f[0] + ': ' + f[1]; }).join('');
  if (!files.length) throw new Error('Nothing could be copied, so nothing was imported.' + bad);
  said('Copied. Importing ' + files.length + (files.length === 1 ? ' clip' : ' clips') + ' into ' + projName(proj) + '…', 100);
  const ok = await proj.importFiles(files, true, null, false);
  if (!ok) throw new Error('The copy is in ' + c.dest + ', but Premiere refused to import it.');
  said('Imported ' + files.length + (files.length === 1 ? ' clip' : ' clips') + ' into ' + projName(proj) + '.\nThe copies are in ' + c.dest + (bad ? '\nCould not be copied:' + bad : ''), 100);
}

async function importClip(path) {
  const proj = await project(); const name = projName(proj);
  if (!name) throw new Error('Open a project in Premiere first: the clips are imported into it.');
  pulls = (await api('/db/pulls.php?list=1')).pulls;
  let p = pulls.filter(function (x) { return x.name === name; })[0];
  if (!p) p = await api('/db/pulls.php', { action: 'create', name: name, made_by: 'Premiere' });
  await api('/db/pulls.php', { action: 'add', p: p.slug, path: path });
  await copyAndImport(p.slug, [path], path.split('/').pop());
}

async function clicked(ev) {
  const b = ev.target; if (!b || b.tagName !== 'BUTTON' || busy) return;
  const clip = b.getAttribute('data-clip'), pull = b.getAttribute('data-pull'); if (!clip && !pull) return;
  lock(true);
  try { if (clip) await importClip(clip); else await copyAndImport(pull, null, 'the pull'); }
  catch (e) { said(e.message, null); }
  lock(false);
}

document.addEventListener('DOMContentLoaded', function () {
  $('go').addEventListener('click', search);
  $('q').addEventListener('keydown', function (e) { if (e.key === 'Enter') search(); });
  $('list').addEventListener('click', clicked);
  $('stop').addEventListener('click', function () {
    said('Asking Rushes to stop…', null);
    api('/db/pulls.php', { action: 'copy-stop', p: copying }).catch(function (e) { said(e.message, null); });
  });
  start();
});
