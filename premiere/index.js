// Rushes inside Premiere. The panel is a window onto Rushes' own Search page (app/db/find.php?premiere=1), which
// dresses itself for Premiere and does the searching, the pull and the copying. What only Premiere can do is done
// here, asked for by that page in short notes:
//   hello  → project   the name of the project open now
//   import → imported  bring these files (the copies Rushes made) into the project
// ponytail: the Mac version: Rushes on this same Mac, so a path Rushes writes is a path Premiere reads.
//   When Rushes lives on the NAS: its address asked for here, and the NAS path turned into this Mac's path.
// ponytail: imports into the project's top bin; a bin per pull when someone asks.
const RUSHES = 'http://127.0.0.1:8642';
const PAGE = RUSHES + '/db/find.php?premiere=1';
const ppro = (function () { try { return require('premierepro'); } catch (e) { return null; } })();

async function project() { try { return ppro ? await ppro.Project.getActiveProject() : null; } catch (e) { return null; } }
const projName = function (p) { return String((p && p.name) || '').replace(/\.prproj$/i, '').trim(); };

// What the page asked for, answered. Only Rushes' own page is listened to, and only files by their full path are imported.
async function answer(d) {
  if (d.rushes === 'hello') return { rushes: 'project', name: projName(await project()) };
  if (d.rushes === 'import') {
    const files = Array.isArray(d.files) ? d.files.filter(function (f) { return typeof f === 'string' && f.charAt(0) === '/'; }) : [];
    const p = await project();
    if (!p) return { rushes: 'imported', id: d.id, ok: false, error: 'no project is open in Premiere' };
    if (!files.length) return { rushes: 'imported', id: d.id, ok: false, error: 'no files were named' };
    try { const ok = await p.importFiles(files, true, null, false); return { rushes: 'imported', id: d.id, ok: !!ok, n: files.length, error: ok ? '' : 'Premiere refused the files' }; }
    catch (e) { return { rushes: 'imported', id: d.id, ok: false, error: String((e && e.message) || e) }; }
  }
  return null;
}

document.addEventListener('DOMContentLoaded', function () {
  const wv = document.getElementById('wv'), note = document.getElementById('note'), said = document.getElementById('said');
  // the page has the whole panel, whatever size the panel is given
  // (asked of the window, the document and the body in turn: Premiere's panels do not all answer the same one)
  let w = 0, h = 0;
  const size = function (k) { return window['inner' + k] || document.documentElement['client' + k] || document.body['client' + k] || 0; };
  const fit = function () {
    const nh = Math.floor(size('Height') - (note.className === 'hide' ? 0 : note.offsetHeight || 0)), nw = Math.floor(size('Width'));
    if (!(nh > 50) || !(nw > 50) || (nh === h && nw === w)) return;
    w = nw; h = nh; wv.setAttribute('width', nw + 'px'); wv.setAttribute('height', nh + 'px'); wv.style.width = nw + 'px'; wv.style.height = nh + 'px';
  };
  fit(); window.addEventListener('resize', fit); setInterval(fit, 400);
  // A Rushes older than this panel shows its ordinary page and never says hello: said here, not left to be wondered at.
  let hello = false;
  wv.addEventListener('loadstop', function () {
    note.className = 'hide'; fit();
    setTimeout(function () {
      if (hello) return;
      note.className = ''; said.textContent = 'This Rushes is older than the panel, so it shows its ordinary page. In Rushes: Check for updates, then press Try again.'; fit();
    }, 6000);
  });
  wv.addEventListener('loaderror', function (e) {
    note.className = ''; said.textContent = 'Rushes is not answering (' + ((e && e.message) || 'no reason given') + '). Is it running on this Mac? Its icon is in the menu bar.'; fit();
  });
  document.getElementById('again').addEventListener('click', function () { said.textContent = 'Opening Rushes…'; wv.src = PAGE + '&t=' + Date.now(); });
  window.addEventListener('message', async function (e) {
    if (typeof e.origin === 'string' && /^https?:/.test(e.origin) && e.origin.indexOf(RUSHES) !== 0) return;      // another website's page
    let d = e.data; try { if (typeof d === 'string') d = JSON.parse(d); } catch (x) { return; }
    if (!d || !d.rushes) return;
    if (!hello) { hello = true; note.className = 'hide'; fit(); }
    const r = await answer(d);
    if (r) wv.postMessage(JSON.stringify(r));
  });
});
if (typeof module !== 'undefined') module.exports = { answer: answer, projName: projName };
