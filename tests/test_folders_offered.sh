#!/bin/sh
# Folders to describe: the box offers the archive's folders (and a kept drive's) from the search catalogue, each
# with its videos and whether it is on the list; Rushes' own folders are never offered. (0.13.54: this replaced
# "Choose folder…", which read every file of a big folder over the network before the page heard anything.)
# Run: sh tests/test_folders_offered.sh (PHPBIN=…)
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd)
PHP=${PHPBIN:-php}
R=$(mktemp -d); trap 'kill $(cat "$R/pid" 2>/dev/null) 2>/dev/null; rm -rf "$R"' EXIT
A="$R/Share"; W="$R/web"; D="$R/Volumes/Films"
mkdir -p "$W" "$A/_rushes" "$A/VIDEOS/Meadow Park/2024" "$A/VIDEOS/Harbor Office/2019/Day 1" "$A/VIDEOS/Picnic Shelter" "$A/PROXIES/VIDEOS/Meadow Park" "$D/Gala/Cam A"
cp -r "$HERE/app/." "$W/"
printf '{"name":"Rushes","archive":{"local":"%s","web":"%s","runs_on":"mac"},"helper":{"mode":"built_in"},"sources":[{"label":"Films","path":"%s","how":"in_place"}]}\n' "$A" "$W" "$D" > "$W/settings.json"
printf '[{"source":"%s","name":"Films","id":"UUID-1","path":"%s","connected":true,"seen":1}]\n' "$D" "$D" > "$W/drives.json"
: > "$W/manifest.tsv"
for f in "$A/VIDEOS/Meadow Park/2024/a.mov" "$A/VIDEOS/Meadow Park/2024/b.mov" "$A/VIDEOS/Harbor Office/2019/Day 1/c.mxf" \
         "$A/VIDEOS/Picnic Shelter/d.mp4" "$A/PROXIES/VIDEOS/Meadow Park/a.mp4" "$A/_rushes/e.mov" "$D/Gala/Cam A/C001.mov"; do
  printf 'x' > "$f"; printf '%s\t%s\n' 1 "$f" >> "$W/manifest.tsv"
done
cut -f2 "$W/manifest.tsv" > "$W/index.txt"
(cd "$R" && "$PHP" -S 127.0.0.1:18671 -t "$W" "$HERE/app/router.php" > "$R/srv.log" 2>&1 & echo $! > "$R/pid"); sleep 1.5
U=http://127.0.0.1:18671
ok() { echo "PASS $1"; }
no() { echo "FAIL $1"; tail -5 "$R/srv.log" "$W/php-errors.log" 2>/dev/null; exit 1; }
curl -s "$U/db/import.php?part=web&force=1" > /dev/null
curl -s -d pass=rushes -d action=prepare --data-urlencode "path=VIDEOS/Meadow Park" "$U/db/analyze.php" > /dev/null
F=$(curl -s -G -d pass=rushes -d folders=1 "$U/db/analyze.php")
echo "$F" | D="$D" python3 -c '
import json, os, sys
f = {x["folder"]: x for x in json.load(sys.stdin)["folders"]}
d = os.environ["D"]
assert f["VIDEOS/Meadow Park"]["on"] == "yes" and f["VIDEOS/Meadow Park"]["videos"] == 2, f
assert f["VIDEOS/Meadow Park/2024"]["on"] == "yes", "inside a folder on the list: on it too"
assert f["VIDEOS"]["on"] == "part" and f["VIDEOS"]["videos"] == 4, f["VIDEOS"]
assert f["VIDEOS/Harbor Office"]["on"] == "" and "VIDEOS/Harbor Office/2019/Day 1" not in f, "three deep, no more"
assert f["VIDEOS/Harbor Office/2019"]["videos"] == 1
assert not any(k.startswith(("PROXIES", "_rushes")) for k in f), "Rushes own folders are never offered"
assert f[d + "/Gala"]["videos"] == 1 and f[d + "/Gala/Cam A"]["on"] == "", "a kept drive: by its full path"
' && ok "the folders offered: counted from Search, marked on the list or not, Rushes' own left out" || no "folders: $F"
P=$(cat "$W/db/admin.php")
case "$P" in *prepChoose*|*webkitdirectory*) no "the Mac's folder window is gone" ;; *'list="prepFolders"'*) ok "the box offers them as you type; the Choose folder window is gone" ;; *) no "admin box" ;; esac
# The "not on the list yet" row: the page's own code, run on two layouts (this archive, and one with no VIDEOS level)
cat > "$R/row.js" <<'JS'
const fs = require('fs');
const src = fs.readFileSync(process.argv[2], 'utf8');
const a = src.indexOf('async function prepFolders()'), b = src.indexOf('\nprepFolders();', a);
const esc = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'})[c]);
async function row(folders) {
  const el = {}; const $ = id => (el[id] = el[id] || { innerHTML: '', textContent: '', querySelectorAll: () => [] });
  const fetch = async () => ({ json: async () => ({ folders }) });
  eval(src.slice(a, b) + '\n; prepFolders_ = prepFolders;'); await prepFolders_();
  return el.prepMissing.innerHTML.replace(/<span class="dim">[^<]*<\/span>/g, '').replace(/<[^>]+>/g, '|').replace(/\|+/g, '|');
}
var prepFolders_;
(async () => {
  const mine = JSON.parse(process.argv[3]).folders;
  const r1 = await row(mine);
  if (!/Harbor Office/.test(r1) || !/Picnic Shelter/.test(r1) || /Meadow Park/.test(r1) || /\|VIDEOS \|/.test(r1)) throw new Error('row: ' + r1);
  const flat = [ {folder: 'Harbor', videos: 9, on: 'part'}, {folder: 'Harbor/2023', videos: 4, on: ''}, {folder: 'Harbor/2024', videos: 5, on: 'yes'},
                 {folder: 'Parks', videos: 3, on: ''}, {folder: 'Parks/2024', videos: 3, on: ''} ];
  const r2 = await row(flat);
  if (r2 !== 'Not on the list yet: |2023 |Parks |') throw new Error('flat row: ' + r2);
  const r3 = await row(flat.map(x => Object.assign({}, x, { on: 'yes' })));
  if (!/Every folder/.test(r3)) throw new Error('all: ' + r3);
  console.log('ok');
})().catch(e => { console.error(e.message); process.exit(1); });
JS
O=$(node "$R/row.js" "$W/db/admin.php" "$F" 2>&1)
[ "$O" = ok ] && ok "the not-on-the-list row: a partly listed folder looked into, never a row of 2024s, all listed said" || no "row: $O"
