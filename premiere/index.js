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
  let w = 0, h = 0;
  const fit = function () {
    const nh = window.innerHeight - (note.className === 'hide' ? 0 : note.offsetHeight), nw = window.innerWidth;
    if (nh === h && nw === w || nh < 50) return;
    w = nw; h = nh; wv.setAttribute('width', nw + 'px'); wv.setAttribute('height', nh + 'px');
  };
  fit(); window.addEventListener('resize', fit); setInterval(fit, 400);
  wv.addEventListener('loadstop', function () { note.className = 'hide'; fit(); });
  wv.addEventListener('loaderror', function (e) {
    note.className = ''; said.textContent = 'Rushes is not answering (' + ((e && e.message) || 'no reason given') + '). Is it running on this Mac? Its icon is in the menu bar.'; fit();
  });
  document.getElementById('again').addEventListener('click', function () { said.textContent = 'Opening Rushes…'; wv.src = PAGE + '&t=' + Date.now(); });
  window.addEventListener('message', async function (e) {
    if (typeof e.origin === 'string' && /^https?:/.test(e.origin) && e.origin.indexOf(RUSHES) !== 0) return;      // another website's page
    let d = e.data; try { if (typeof d === 'string') d = JSON.parse(d); } catch (x) { return; }
    if (!d || !d.rushes) return;
    const r = await answer(d);
    if (r) wv.postMessage(JSON.stringify(r));
  });
});
if (typeof module !== 'undefined') module.exports = { answer: answer, projName: projName };
