// viewer.js — Search without Rushes running: the Viewer (HOW-IT-WORKS.md → The Viewer).
//
// The Viewer is Rushes' own Search page (find.php, in its viewer dress) saved onto the archive and opened from
// there, by people who have no Rushes: nothing runs, nothing is installed, nothing is asked. This file answers
// the questions that page asks (search.php, and the little else it needs) from two lists Rushes writes beside
// it each day (runner.py viewer_lists): the footage, and what was described in it.
//
// It must answer as search.php does: tests/test_viewer.sh asks both the same questions and fails when they
// differ. A change to search.php's rules is a change here, and that test says so.
// ponytail: every search reads the lists through (each word looked for once in the whole text of the moments'
// words: a tenth of a second for 200,000 moments); an index if that ever shows. The lists are held as text, a few
// hundred MB of the browser's memory for an archive that size; split into years if that ever weighs.
(function (root) {
'use strict';
const VPAGE = 120;
// files.js, a line each:   path camera reel | kind bytes year event dept width height fps codec duration proxy_at recorded timecode asis label vkey vrank versions id
// moments.js, a line each: fp path kind shot start_s end_s what on_screen themes tags shot_size people light part_of_day mood language comp hay
// words.js, a line each:   the same moment's hay (everything searchable, small letters), a byte a letter; a letter that
//                          does not fit one is \x01 there, and only then is hay in moments.js too (read as it is)
const FC = ['path', 'camera', 'reel', 'kind', 'bytes', 'year', 'event', 'dept', 'width', 'height', 'fps', 'codec', 'duration', 'proxy_at',
            'recorded', 'timecode', 'asis', 'label', 'vkey', 'vrank', 'versions', 'id'];
const MC = ['fp', 'path', 'kind', 'shot', 'start_s', 'end_s', 'what', 'on_screen', 'themes', 'tags', 'shot_size', 'people', 'light',
            'part_of_day', 'mood', 'language', 'comp', 'hay'];
const NUM = { bytes: 1, width: 1, height: 1, fps: 1, duration: 1, proxy_at: 1, vrank: 1, versions: 1, shot: 1, start_s: 1, end_s: 1 };
let INFO = { root: '', related: [], sections: {} };
let FL = [], FH = [], BY = new Map();          // the files' lines; path, camera and reel in small letters; a file by its path
let MT = '', MS = new Uint32Array(1), MN = 0;   // the moments, one text, and where each line starts
let HT = '', HS = new Uint32Array(1), ODD = []; // their words, one text beside it; the moments whose words are read from MT instead
let FPAT = new Map(), MBY = new Map();          // where a video's moments are; a file's moments
const mline = function (i) { return MT.slice(MS[i], MS[i + 1] - 1); };
const mcols = function (i, n) { return n ? mline(i).split('\t', n) : mline(i).split('\t'); };
let last = { q: null };                         // the words searched last, read through once for all its pages

const val = function (k, v) { return v === '' ? null : NUM[k] ? +v : v.indexOf('\x1f') < 0 ? v : v.replace(/\x1f/g, '\t'); };   // a tab inside a column travels as \x1f
const frow = function (i) { const c = FL[i].split('\t'), o = {}; FC.forEach(function (k, n) { o[k] = val(k, c[n] || ''); }); return o; };
const mrow = function (i) { const c = mcols(i), o = {}; MC.forEach(function (k, n) { if (k !== 'hay') o[k] = val(k, c[n] || ''); });
  // what is always text or a number in the catalogue stays so
  ['what', 'on_screen', 'themes', 'tags', 'shot_size', 'people', 'light', 'part_of_day', 'mood', 'language'].forEach(function (k) { if (o[k] === null) o[k] = ''; });
  o.shot = o.shot || 0; o.start_s = o.start_s || 0; o.end_s = o.end_s || 0; o.path = INFO.root + '/' + o.path; return o; };
const cmp = function (a, b) { return a < b ? -1 : a > b ? 1 : 0; };
const cmpAll = function (a, b) { for (let i = 0; i < a.length; i++) { const c = cmp(a[i], b[i]); if (c) return c; } return 0; };
const lowA = function (s) { return s.replace(/[A-Z]+/g, function (m) { return m.toLowerCase(); }); };   // as SQLite's NOCASE and LIKE: A to Z only
const base = function (p) { return p.slice(p.lastIndexOf('/') + 1); };

function load(info, files, moments, words) {
  INFO = info; last = { q: null };
  FL = files ? files.split('\n') : []; if (FL[FL.length - 1] === '') FL.pop();
  MT = moments || ''; if (MT && MT[MT.length - 1] !== '\n') MT += '\n';
  FH = new Array(FL.length); BY = new Map();
  for (let i = 0; i < FL.length; i++) {
    const l = FL[i]; let t = l.indexOf('\t'); BY.set(l.slice(0, t), i);
    t = l.indexOf('\t', l.indexOf('\t', t + 1) + 1); FH[i] = '/' + l.slice(0, t).toLowerCase();
  }
  MN = 0; for (let p = MT.indexOf('\n'); p >= 0; p = MT.indexOf('\n', p + 1)) MN++;
  MS = new Uint32Array(MN + 1); HS = new Uint32Array(MN + 1); FPAT = new Map(); MBY = new Map(); ODD = [];
  HT = words || ''; if (HT && HT[HT.length - 1] !== '\n') HT += '\n';
  for (let i = 0, a = 0; i < MN; i++) { HS[i] = a; a = HT.indexOf('\n', a) + 1; HS[i + 1] = a; }
  for (let p = HT.indexOf('\x01'), li = 0; p >= 0; p = HT.indexOf('\x01', HS[li + 1])) { while (HS[li + 1] <= p) li++; ODD.push(li); }
  for (let i = 0, a = 0; i < MN; i++) {
    const e = MT.indexOf('\n', a), t = MT.indexOf('\t', a), t2 = MT.indexOf('\t', t + 1), fp = MT.slice(a, t), p = MT.slice(t + 1, t2);
    MS[i] = a; MS[i + 1] = e + 1;
    const at = FPAT.get(fp); if (at) at[1] = i + 1; else FPAT.set(fp, [i, i + 1]);
    const of = MBY.get(p); if (of) of.push(i); else MBY.set(p, [i]);
    a = e + 1;
  }
}

// analysis.php word_pattern(): the word whole, its plural or singular, and the words of its group
function wordForms(word) {
  word = word.toLowerCase().trim();
  const one = function (w) { const f = [w];
    if (/ies$/.test(w)) f.push(w.slice(0, -3) + 'y'); else if (/(s|x|z|ch|sh)es$/.test(w)) f.push(w.slice(0, -2)); else if (/[^s]s$/.test(w)) f.push(w.slice(0, -1));
    return f; };
  const all = function (w) { const f = [w, w + 's'];
    if (/[^aeiou]y$/.test(w)) f.push(w.slice(0, -1) + 'ies'); if (/(s|x|z|ch|sh)$/.test(w)) f.push(w + 'es');
    return f; };
  const b = one(word); let words = b.slice();
  (INFO.related || []).forEach(function (g) { g = g.map(function (x) { return String(x).toLowerCase(); }); if (b.some(function (x) { return g.indexOf(x) >= 0; })) words = words.concat(g); });
  const forms = new Set(); words.forEach(function (w) { all(w).forEach(function (f) { forms.add(f); }); });
  return Array.from(forms);
}
// \b as the catalogue's search has it (for every alphabet): at p, a letter, digit or _ on one side only
const WB = /[\p{L}\p{N}_]$/u, WA = /^[\p{L}\p{N}_]/u;
const wch = function (c) { return c < 128 ? (c > 47 && c < 58) || (c > 64 && c < 91) || (c > 96 && c < 123) || c === 95 : -1; };
function edge(t, p) {
  let b = p > 0 ? wch(t.charCodeAt(p - 1)) : false, a = p < t.length ? wch(t.charCodeAt(p)) : false;
  if (b === -1) b = WB.test(t.slice(p > 1 ? p - 2 : 0, p)); if (a === -1) a = WA.test(t.slice(p, p + 2));
  return b !== a;
}
const split = function (s) { return s.split(/\s+/).filter(Boolean); };
const list = function (v) { return String(v || '').split(',').filter(Boolean); };

// The words, read through the moments once: which moments have which, and for each file whether every word is somewhere in it.
function scan(q) {
  if (last.q === q) return last;
  const words = split(q.toLowerCase()).map(wordForms), had = new Int32Array(MN), hits = new Uint8Array(MN), n = words.length;
  for (let w = 0; w < n; w++) {
    const forms = words[w].filter(Boolean);
    // every place the word is written, in the whole text of the words; a place counts when the word stands whole there
    // (one pattern of all its forms finds the places; whether it stands whole is checked here, form by form, as the catalogue's \b does)
    const re = new RegExp(forms.map(function (f) { return f.replace(/[.*+?^${}()|[\]\\\/-]/g, '\\$&'); }).join('|') || '$^', 'g');
    for (let m, li = 0; (m = re.exec(HT)); ) {
      const pos = m.index;
      while (HS[li + 1] <= pos) li++;
      if (edge(HT, pos) && forms.some(function (f) { return HT.startsWith(f, pos) && edge(HT, pos + f.length); })) { had[li] |= 1 << w; re.lastIndex = HS[li + 1]; }
      else re.lastIndex = pos + 1;
    }
    // the few moments with a letter that does not fit a byte: their words as they are
    ODD.forEach(function (li) {
      const h = mline(li), from = h.lastIndexOf('\t') + 1;
      const is = forms.some(function (f) { for (let pos = h.indexOf(f, from); pos >= 0; pos = h.indexOf(f, pos + 1)) if (edge(h, pos) && edge(h, pos + f.length)) return true; return false; });
      if (is) had[li] |= 1 << w; else had[li] &= ~(1 << w);
    });
  }
  const full = n ? (n >= 31 ? -1 : (1 << n) - 1) : 0, seen = new Map(), shown = new Set();   // a file's path → which of the words its moments have
  for (let i = 0; i < MN && n; i++) if (had[i]) {
    let b = had[i], h = 0; while (b) { h++; b &= b - 1; } hits[i] = h;
    const p = mcols(i, 2)[1]; seen.set(p, (seen.get(p) || 0) | had[i]);
  }
  seen.forEach(function (b, p) { if (b === full) shown.add(p); });
  return last = { q: q, n: n, full: full, hits: hits, had: had, shown: shown };
}

function search(g) {
  const t0 = Date.now();
  if (/^[0-9a-f]{24}$/.test(g.shots || '')) {
    const at = FPAT.get(g.shots) || [0, 0], rows = [];
    for (let i = at[0]; i < at[1]; i++) rows.push(mrow(i));
    rows.sort(function (a, b) { return cmpAll([a.start_s, a.kind], [b.start_s, b.kind]); });
    return { shots: rows };
  }
  if (g.facets) {
    const y = {}, m = new Map();
    FL.forEach(function (l) { const v = l.split('\t')[5]; if (v) y[v] = (y[v] || 0) + 1; });
    for (let i = 0; i < MN; i++) { const c = mcols(i, 15); if (c[2] === 'shot' && c[14]) c[14].toLowerCase().split(/\s*,\s*/).forEach(function (w) { if (w) m.set(w, (m.get(w) || 0) + 1); }); }
    return { years: Object.keys(y).sort().reverse().map(function (k) { return { year: k, n: y[k] }; }),
             moods: Array.from(m).sort(function (a, b) { return b[1] - a[1]; }).slice(0, 12).map(function (x) { return x[0]; }) };
  }
  const q = String(g.q || '').trim(), kind = g.kind || '', limit = Math.min(Math.max(parseInt(g.limit || 200) || 0, 1), 1000);
  const offset = Math.max(parseInt(g.offset || 0) || 0, 0), voffset = Math.max(parseInt(g.voffset || 0) || 0, 0);
  const words = split(q).map(function (w) { return w.toLowerCase(); }), rootHay = INFO.root.toLowerCase();
  // ponytail: a word is looked for in the archive's own address and in the path inside it, not across where the two join
  const named = words.filter(function (w) { return rootHay.indexOf(w) < 0; });
  const pick = function (k, known) { return list(g[k]).filter(function (c) { return known[c]; }).map(function (c) { return known[c]; }); };
  const any = function (fs) { return function (x) { return fs.some(function (f) { return f(x); }); }; };
  const fil = [];                                // what a file must be, the words aside
  if (kind !== '' && kind !== 'all') fil.push(function (r) { return r.kind === kind; });
  if (g.where) { const w = '/' + lowA(String(g.where)) + '/'; fil.push(function (r) { return lowA(INFO.root + '/' + r.path).indexOf(w) >= 0; }); }
  if (g.dept) fil.push(function (r) { return r.dept === g.dept; });
  const big = function (r) { return Math.max(r.width, r.height); }, small = function (r) { return Math.min(r.width, r.height); };
  const has = function (r) { return r.width !== null && r.height !== null; };
  [['orient', { h: function (r) { return has(r) && r.width > r.height * 1.1; }, v: function (r) { return has(r) && r.height > r.width * 1.1; },
                s: function (r) { return has(r) && r.width >= r.height * 0.9 && r.width <= r.height * 1.1; } }],
   ['res', { '4k': function (r) { return has(r) && (big(r) >= 3800 || small(r) >= 2100); }, hd: function (r) { return has(r) && big(r) < 3800 && small(r) < 2100 && small(r) >= 1060; },
             sd: function (r) { return has(r) && small(r) < 1060; } }],
   ['len', { s: function (r) { return r.duration !== null && r.duration < 10; }, m: function (r) { return r.duration !== null && r.duration >= 10 && r.duration < 60; },
             l: function (r) { return r.duration !== null && r.duration >= 60 && r.duration < 300; }, xl: function (r) { return r.duration !== null && r.duration >= 300; } }],
  ].forEach(function (x) { const c = pick(x[0], x[1]); if (c.length) fil.push(any(c)); });
  const years = list(g.year).filter(function (y) { return /^\d{4}$/.test(y); });
  if (years.length) fil.push(function (r) { return years.indexOf(r.year) >= 0; });
  if (g.ai === 'only' || g.ai === 'none') fil.push(function (r) { return (r.label === 'ai') === (g.ai === 'only'); });
  // what is in the footage: a shot that is all of these
  const cond = [];
  const is = function (k, vs) { return function (m) { return vs.indexOf(m[k]) >= 0; }; };
  [['shot', { close: is(10, ['close-up', 'extreme-close-up']), medium: is(10, ['medium']), full: is(10, ['full']), wide: is(10, ['wide', 'extreme-wide']) }],
   ['people', { none: is(11, ['none']), one: is(11, ['one']), two: is(11, ['two']), few: is(11, ['few', 'crowd', 'many']) }],
   ['light', { day: is(12, ['daylight']), artificial: is(12, ['artificial']), golden: is(12, ['sunrise-sunset']), night: is(12, ['dusk', 'night']) }],
  ].forEach(function (x) { const c = pick(x[0], x[1]); if (c.length) cond.push(any(c)); });
  const moods = list(String(g.mood || '').toLowerCase()).filter(function (m) { return /^[a-z -]{2,30}$/.test(m); });
  if (moods.length) cond.push(function (m) { return moods.some(function (x) { return lowA(m[14]).indexOf(x) >= 0; }); });
  const shotIs = function (i) { const m = mcols(i, 15); return m[2] === 'shot' && cond.every(function (f) { return f(m); }); };
  let condPaths = null;                          // the files with such a shot
  if (cond.length) { condPaths = new Set(); for (let i = 0; i < MN; i++) if (shotIs(i)) condPaths.add(mcols(i, 2)[1]); }
  if (g.p) fil.push(function (r) { return INFO.root + '/' + r.path === g.p; });
  if (g.v) fil.push(function (r) { return r.vkey === g.v; });
  if (g.in) {
    const want = (INFO.sections || {})[g.in];
    if (!want) return { error: 'unknown section: ' + g.in };
    fil.push(function (r) { return want.indexOf(r.label) >= 0; });
  }
  const fileIs = function (r) { return fil.every(function (f) { return f(r); }); };
  if (!words.length && !fil.length && !cond.length)
    return { q: '', total: 0, bytes: 0, counts: { all: 0 }, rows: [], offset: 0, limit: limit, ms: 0, indexed: FL.length, imported: INFO.catalogue || 0 };

  const only = g.only || '', sort = g.sort || '', rows = [], counts = { all: 0 }; let bytes = 0;
  const sc = (q !== '' || cond.length) ? scan(q) : null;
  if (only !== 'videos') {
    const byname = g.byname === '1' && words.length > 0, found = [];
    for (let i = 0; i < FL.length; i++) {
      const h = FH[i];
      if (!named.every(function (w) { return h.indexOf(w) >= 0; })) continue;
      const r = frow(i);
      if (!fileIs(r) || (condPaths && !condPaths.has(r.path)) || (byname && sc.shown.has(r.path))) continue;
      found.push(r); counts[r.kind] = (counts[r.kind] || 0) + 1; counts.all++; bytes += r.bytes || 0;
    }
    // files.js is in the order of the paths, so "as found" is as it is
    const nul = function (v) { return v === null ? 1 : 0; }, neg = function (f) { return function (a, b) { return -f(a, b); }; };
    const by = { new: function (a, b) { return nul(a.year) - nul(b.year) || cmp(b.year, a.year) || cmp(b.recorded || '', a.recorded || '') || cmp(a.path, b.path); },
                 old: function (a, b) { return nul(a.year) - nul(b.year) || cmp(a.year, b.year) || cmp(a.recorded || '', b.recorded || '') || cmp(a.path, b.path); },
                 big: function (a, b) { return (b.bytes || 0) - (a.bytes || 0); },
                 long: function (a, b) { return nul(a.duration) - nul(b.duration) || b.duration - a.duration; },
                 name: function (a, b) { return cmp(lowA(base(a.path)), lowA(base(b.path))); } }[sort];
    if (by) found.sort(by);
    found.slice(offset, offset + limit).forEach(function (r) {
      const name = base(r.path), dot = name.lastIndexOf('.'), mine = MBY.get(r.path) || []; let st = null;
      mine.forEach(function (i) { const m = mcols(i, 4); if (m[2] === 'shot' && (st === null || +m[3] < st[1])) st = [m[0], +m[3]]; });
      rows.push({ path: INFO.root + '/' + r.path, name: name, ext: dot > 0 ? name.slice(dot + 1).toLowerCase() : '', kind: r.kind, bytes: r.bytes, year: r.year, event: r.event, dept: r.dept,
        width: r.width, height: r.height, fps: r.fps, codec: r.codec, duration: r.duration, proxy_at: r.proxy_at, recorded: r.recorded, timecode: r.timecode,
        reel: r.reel, camera: r.camera, asis: r.asis, copies: null, still: st ? st[0] + ':' + st[1] : null, vkey: r.vkey, vrank: r.vrank, versions: r.versions || 0 });
    });
  }

  const moments = { count: 0, moments: 0, rows: [], offset: voffset, page: VPAGE };
  if (only !== 'files' && (q !== '' || cond.length) && (kind === '' || kind === 'all' || kind === 'video')) {
    // analysis.php analysis_videos(): every word somewhere in the video, and a shot the filters ask for
    const vids = [];
    FPAT.forEach(function (at) {
      let bits = 0, shot = !cond.length; const mine = [];
      for (let i = at[0]; i < at[1]; i++) {
        bits |= sc.had[i];
        const s = cond.length ? shotIs(i) : false; if (s) shot = true;
        if (cond.length ? s : sc.hits[i] > 0) mine.push(i);
      }
      if (!shot || (sc.n && bits !== sc.full) || !mine.length) return;
      // the video's own row is its best moment (most words; a shot before what is said; the earliest), read whole only then
      const ms = mine.map(function (i) { const c = mcols(i, 5); return [sc.hits[i], c[2], +c[4] || 0, i]; });
      ms.sort(function (a, b) { return b[0] - a[0] || cmp(a[1], b[1]) || a[2] - b[2]; });
      const v = mrow(ms[0][3]); v.hits = ms[0][0]; v.found = ms.map(function (m) { return m[2]; }).sort(function (a, b) { return a - b; });
      vids.push(v);
    });
    vids.sort(function (a, b) { return b.hits - a.hits || b.found.length - a.found.length || cmp(a.path, b.path); });
    let vrows = [];
    vids.forEach(function (m) {
      const i = BY.get(m.path.slice(INFO.root.length + 1)); if (i === undefined) return;
      const r = frow(i); if (!fileIs(r)) return;
      m.name = base(m.path);
      ['year', 'bytes', 'duration', 'width', 'height', 'recorded', 'proxy_at', 'asis'].forEach(function (k) { m[k] = r[k]; });
      m.vkey = r.vkey; m.vrank = r.vrank || 0; m.versions = r.versions || 0;
      vrows.push(m);
    });
    moments.count = vrows.length;
    moments.moments = vrows.reduce(function (n, m) { return n + m.found.length; }, 0);
    const key = { new: function (m) { return [-(parseInt(m.year) || 0), m.recorded || '']; }, old: function (m) { return [parseInt(m.year) || (m.year === null ? 9999 : 0), m.recorded || '']; },
                  big: function (m) { return [-(m.bytes || 0)]; }, long: function (m) { return [-(m.duration || 0)]; }, name: function (m) { return [lowA(m.name)]; } }[sort];
    if (key) vrows = vrows.map(function (m) { return [key(m), m]; }).sort(function (a, b) { return cmpAll(a[0], b[0]); }).map(function (x) { return x[1]; });
    if (voffset === 0) {
      // words to narrow it with: the tags and themes these videos have most, not the words already typed
      const typed = new Set(split(q.toLowerCase())), n = new Map();
      vrows.forEach(function (m) {
        new Set((m.tags + ' ' + m.themes).toLowerCase().split(/[^\p{L}\p{N}]+/u).filter(Boolean)).forEach(function (w) {
          if (!typed.has(w) && Array.from(w).length > 2 && ['and', 'the', 'for', 'with'].indexOf(w) < 0) n.set(w, (n.get(w) || 0) + 1); });
      });
      moments.suggest = Array.from(n).sort(function (a, b) { return b[1] - a[1]; }).filter(function (x) { return x[1] > 1 && x[1] < vrows.length; }).slice(0, 12);
    }
    moments.rows = vrows.slice(voffset, voffset + VPAGE);
  }
  return { moments: moments, q: q, total: counts.all, bytes: bytes, counts: counts, rows: rows, offset: offset, limit: limit,
           ms: Date.now() - t0, indexed: FL.length, imported: INFO.catalogue || 0 };
}

// Where a still and a preview are, from where the Viewer's page is (the top of the archive): the same folders Rushes keeps them in.
const enc = function (rel) { return rel.split('/').map(encodeURIComponent).join('/'); };
const still = function (fp, shot) { return '_rushes/analysis/' + fp.slice(0, 2) + '/' + fp + '/' + ('000' + (+shot || 0)).slice(-4) + '.jpg'; };
function play(path) {
  const rel = path.indexOf(INFO.root + '/') === 0 ? path.slice(INFO.root.length + 1) : path, i = BY.get(rel);
  if (i === undefined) return '';
  const r = frow(i);
  if (r.kind !== 'video' || (!r.proxy_at && r.asis === '1')) return enc(rel);          // a picture, or a video light enough to play as it is
  return r.proxy_at ? 'PROXIES/' + enc(rel.replace(/\.[^.\/]*$/, '') + '.mp4') : '';
}

// The pulls, kept in this browser (nothing here can write to the archive): what pulls.php answers in Rushes.
// A pull is sent to the editors as a request (find.php, the Viewer's bar); Rushes makes the real one from it.
function pulls(mem, get, post) {
  let all = {}; try { all = JSON.parse(mem.getItem('vwPulls') || '{}'); } catch (e) {}
  const keep = function () { mem.setItem('vwPulls', JSON.stringify(all)); return { ok: true }; };
  if (post) {
    const p = all[post.p];
    if (post.action === 'create') {
      const name = String(post.name || '').trim().slice(0, 80); if (!name) return { error: 'A pull needs a name.' };
      const slug = 'v' + Date.now().toString(36); all[slug] = { name: name, by: String(post.made_by || ''), items: [] }; keep();
      return { slug: slug, name: name };
    }
    if (!p) return { error: 'That pull is not in this browser.' };
    if (post.action === 'add') {
      const rel = String(post.path || '').slice(INFO.root.length + 1), i = BY.get(rel);
      if (i === undefined) return { error: 'Not a file of the archive.' };
      const r = frow(i);
      if (!p.items.some(function (x) { return x.rel === rel; })) p.items.push({ rel: rel, name: base(rel), bytes: r.bytes || 0, id: r.id });
      return keep();
    }
    if (post.action === 'remove') { p.items = p.items.filter(function (x) { return x.rel !== post.rel; }); return keep(); }
    if (post.action === 'delete') { delete all[post.p]; return keep(); }
    return { error: 'That is done in Rushes itself, not in the Viewer.' };
  }
  if (get.list) return { pulls: Object.keys(all).reverse().map(function (k) { return { slug: k, name: all[k].name, n: all[k].items.length }; }) };
  if (get.places) return { places: [] };
  const p = all[get.p];
  return p ? { pull: { name: p.name, made_by: p.by }, items: p.items } : { error: 'That pull is not in this browser.' };
}

// A pull as the request an editor's Rushes reads (pulls.php action=request): plain words in an email, the part
// that matters between two lines nothing else looks like. Each clip by its name and its number in the catalogue.
const FENCE = ['===== RUSHES REQUEST =====', '===== END OF REQUEST ====='];
function request(pull, from, to, note) {
  const clean = function (v) { return String(v || '').replace(/\s+/g, ' ').trim(); };
  return [FENCE[0], 'From: ' + clean(from), 'For: ' + clean(to), 'Pull: ' + clean(pull.name)].concat(clean(note) ? ['Note: ' + clean(note)] : [],
    ['Clips: ' + pull.items.length], pull.items.map(function (x, n) { return (n + 1) + '. ' + x.name + ' [#' + x.id + ']'; }), [FENCE[1]]).join('\n');
}

const V = { load: load, search: search, still: still, play: play, wordForms: wordForms, pulls: pulls, request: request };
if (typeof module !== 'undefined' && module.exports) { module.exports = V; return; }

// ── in the page ────────────────────────────────────────────────────────────
// The lists come as scripts from beside the page (a page opened from a folder may load those, and nothing else,
// without asking): each hands its text over packed, and the page's questions wait until both are read.
root.VIEWER = V;
const got = {}, want = {};
const text = function (u, how) { return how ? Promise.resolve(new TextDecoder(how).decode(u)) : new Response(u).text(); };
const have = function (n) { return new Promise(function (ok) { if (n in got) ok(got[n]); else want[n] = ok; }); };
const give = function (n, v) { got[n] = v; if (want[n]) want[n](v); };
root.RUSHES_LIST = function (name, b64) {
  const bin = atob(b64), u = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i);
  new Response(new Blob([u]).stream().pipeThrough(new DecompressionStream('gzip'))).arrayBuffer()
    .then(function (b) { return text(b, name === 'words' ? 'latin1' : ''); }).then(function (t) { give(name, t); });
};
root.RUSHES_INFO = function (i) { give('info', i); };
V.ready = Promise.all([have('info'), have('files'), have('moments'), have('words')]).then(function (x) {
  const R = root.RUSHES || {};                   // the archive as the page names it; the words' groups and the sections as Rushes has them
  load(Object.assign({}, x[0], { root: String(R.archive || x[0].root).replace(/\/$/, ''), related: R.related || [], sections: R.sections || {} }), x[1], x[2], x[3]);
  return x[0]; });
const json = function (o) { return new Response(JSON.stringify(o), { headers: { 'Content-Type': 'application/json' } }); };
const f0 = root.fetch;
root.fetch = function (u, o) {
  const url = new URL(String(u), 'http://viewer/db/'), page = url.pathname.replace(/^.*\//, '');
  if (page === 'search.php') return V.ready.then(function () { const g = {}; url.searchParams.forEach(function (v, k) { g[k] = v; }); return json(search(g)); });
  if (page === 'state.php') return Promise.resolve(json({ version: (root.RUSHES || {}).version, runner: { ok: true } }));
  if (page === 'pulls.php') return V.ready.then(function () {
    const g = {}, b = {}; url.searchParams.forEach(function (v, k) { g[k] = v; });
    if (o && o.body) new URLSearchParams(o.body).forEach(function (v, k) { b[k] = v; });
    return json(pulls(root.localStorage, g, o && o.body ? b : null)); });
  if (/\.php$/.test(page)) return Promise.resolve(json({ error: 'That is done in Rushes itself, not in the Viewer.' }));
  return f0.call(root, u, o);
};
})(typeof window !== 'undefined' ? window : globalThis);
