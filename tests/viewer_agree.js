// The same questions to Rushes' search.php and to the Viewer's viewer.js, every answer compared (tests/test_viewer.sh runs it).
//   node viewer_agree.js <the lists' folder> <Rushes' address> <find.php?viewer=1 as saved> <viewer.js>   the questions on stdin, a line each
const fs = require('fs'), zlib = require('zlib'), V = require(process.argv[5]);
const dir = process.argv[2], U = process.argv[3], html = fs.readFileSync(process.argv[4], 'utf8');
const R = JSON.parse(html.match(/window\.RUSHES = (\{.*?\});\n/s)[1]);
const unpack = (n, how) => zlib.gunzipSync(Buffer.from(fs.readFileSync(dir + '/' + n + '.js', 'utf8').match(/'([A-Za-z0-9+\/=]*)'\);/)[1], 'base64')).toString(how);
const info = JSON.parse(fs.readFileSync(dir + '/info.js', 'utf8').replace(/^RUSHES_INFO\(|\);\s*$/g, ''));
let t = Date.now();
V.load(Object.assign({}, info, { root: R.archive.replace(/\/$/, ''), related: R.related, sections: R.sections }), unpack('files'), unpack('moments'), unpack('words', 'latin1'));
console.log('lists read in', Date.now() - t, 'ms');
const norm = v => v === null || v === undefined || v === '' ? null : typeof v === 'number' ? v : /^-?\d+(\.\d+)?$/.test(v) ? +v : Array.isArray(v) ? v.map(norm) : v;
const pickRow = (a, b) => { const o = {}; Object.keys(a).forEach(k => { if (k !== 'copies' && k !== 'comp') o[k] = [norm(a[k]), norm(b[k])]; }); return o; };
const m_count = a => (a.moments || {}).count || 0;
const qs = fs.readFileSync(0, 'utf8').split('\n').filter(Boolean);
(async () => { let bad = 0;
  for (const q of qs) {
    const g = Object.fromEntries(new URLSearchParams(q)); const t1 = Date.now();
    const a = await (await fetch(U + '/db/search.php?' + q)).json(), t2 = Date.now(), b = V.search(g), t3 = Date.now();
    const diff = [];
    const eq = (what, x, y) => { if (JSON.stringify(x) !== JSON.stringify(y)) diff.push(what + ': ' + JSON.stringify(x).slice(0, 300) + ' != ' + JSON.stringify(y).slice(0, 300)); };
    if (a.shots) { eq('shots n', a.shots.length, b.shots.length); a.shots.forEach((r, i) => { const p = pickRow(r, b.shots[i] || {}); Object.keys(p).forEach(k => eq('shot ' + i + ' ' + k, p[k][0], p[k][1])); }); }
    else if (a.years) { eq('years', a.years.map(y => y.year), b.years.map(y => y.year)); eq('moods', a.moods, b.moods); }
    else if (a.error || b.error) eq('error', a.error, b.error);
    else {
      ['total', 'bytes', 'offset', 'limit'].forEach(k => eq(k, a[k], b[k])); eq('counts', Object.entries(a.counts).sort(), Object.entries(b.counts).sort());
      eq('rows n', a.rows.length, b.rows.length);
      a.rows.forEach((r, i) => { const p = pickRow(r, b.rows[i] || {}); Object.keys(p).forEach(k => eq('row ' + i + ' ' + k, p[k][0], p[k][1])); });
      const m = a.moments || { rows: [] }, n = b.moments || { rows: [] }; eq('videos asked', !!a.moments, !!b.moments);
      ['count', 'moments', 'offset', 'page', 'suggest'].forEach(k => eq('moments.' + k, m[k], n[k])); eq('moments.error', m.error, undefined);
      eq('videos n', m.rows.length, n.rows.length);
      m.rows.forEach((r, i) => { const p = pickRow(r, n.rows[i] || {}); Object.keys(p).forEach(k => eq('video ' + i + ' ' + k, p[k][0], p[k][1])); });
    }
    console.log(diff.length ? 'FAIL' : 'PASS', q, '(' + (t2 - t1) + ' ms Rushes, ' + (t3 - t2) + ' ms Viewer' + (a.total !== undefined ? '; ' + a.total + ' files, ' + m_count(a) + ' videos' : '') + ')');
    diff.slice(0, 6).forEach(d => console.log('    ' + d)); if (diff.length) bad++;
  }
  console.log(bad ? bad + ' of ' + qs.length + ' DIFFER' : 'all ' + qs.length + ' agree'); process.exit(bad ? 1 : 0); })();
