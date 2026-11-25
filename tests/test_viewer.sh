#!/bin/sh
# The Viewer (db/viewer.js, find.php?viewer=1, runner.py viewer_lists): Search saved onto the archive, for people
# with no Rushes. It must answer as Rushes does: the same questions go to search.php and to viewer.js over the
# lists made from the same catalogue, and every answer is compared, value by value.
# Run: sh tests/test_viewer.sh (PHPBIN=…)   needs: php, python3, node, curl
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd)
PHP=${PHPBIN:-php}
R=$(mktemp -d); trap 'kill $(cat "$R/pid" 2>/dev/null) 2>/dev/null; rm -rf "$R"' EXIT
A="$R/Drive"; W="$R/web"; D="$R/Volumes/Films"
mkdir -p "$W" "$A/_rushes" "$D/Old"
cp -r "$HERE/app/." "$W/"
printf '{"name":"Rushes","archive":{"local":"%s","web":"%s"},"helper":{"mode":"built_in"},"sources":[{"label":"Films","path":"%s","how":"in_place"}],"organise":{"shelves":"VIDEOS","departments":[{"name":"Parks"},{"name":"Public Works"}]},"viewer":{"editors":[{"name":"Alex","email":"alex@example.org"},{"name":"No address"}]}}\n' "$A" "$W" "$D" > "$W/settings.json"
printf '[{"source":"%s","name":"Films","id":"UUID-1","path":"%s","connected":true,"seen":1}]\n' "$D" "$D" > "$W/drives.json"
n=0; f() { n=$((n + 1)); mkdir -p "$(dirname "$A/$1")"; head -c $((n * 1000 + 37)) /dev/zero > "$A/$1"; }
f "VIDEOS/Parks/2025/Kite Day/A001C003_250101.mov"; f "VIDEOS/Parks/2025/Kite Day/Kite Promo.mp4"; f "VIDEOS/Parks/2025/Kite Day/Kite Promo v2.mp4"
f "VIDEOS/Parks/2025/Kite Day/openart-kite sky.mp4"; f "VIDEOS/Parks/2024/Picnic/DJI_0002.MP4"; f "VIDEOS/Parks/2024/Picnic/picnic table.jpg"
f "VIDEOS/Harbor Master's Office/2023/Boats #1/boat one.mov"; f "VIDEOS/Harbor Master's Office/2023/Boats #1/Boat Two.mov"
f "VIDEOS/Public Works/2025/Fiesta/niño bailando.mp4"; f "VIDEOS/Public Works/2025/Fiesta/vertical reel.mp4"; f "VIDEOS/Public Works/2025/Fiesta/poster 100%.png"
f "VIDEOS/ARCHIVE/2019/Old Parade/parade wide.mxf"; f "VIDEOS/ARCHIVE/2019/Old Parade/parade close.mxf"; f "VIDEOS/ARCHIVE/2019/Old Parade/no words.mov"
f "VIDEOS/Parks/2025/Kite Day/Adobe Premiere Pro Video Previews/render.mov"       # a cache: never in Search, here or there
mkdir -p "$A/PROXIES/VIDEOS/Parks/2025/Kite Day"; printf p > "$A/PROXIES/VIDEOS/Parks/2025/Kite Day/Kite Promo.mp4"
find "$A/VIDEOS" -type f | sort | while IFS= read -r p; do printf '%s\t%s\n' "$(wc -c < "$p" | tr -d ' ')" "$p"; done > "$W/manifest.tsv"
cut -f2 "$W/manifest.tsv" > "$W/index.txt"
(cd "$R" && PHP_CLI_SERVER_WORKERS=2 exec "$PHP" -S 127.0.0.1:18671 -t "$W" "$HERE/app/router.php" > "$R/srv.log" 2>&1 & echo $! > "$R/pid"); sleep 1.5
U=http://127.0.0.1:18671
ok() { echo "PASS $1"; }
no() { echo "FAIL $1"; tail -5 "$R/srv.log" "$W/php-errors.log" 2>/dev/null; exit 1; }
curl -s "$U/db/import.php?part=web&force=1" > /dev/null
curl -s "$U/db/search.php?q=kite" | grep -q '"total":' || no "Rushes' own search answers"
# what the files are (the media ledger) and what was described in them, written as Rushes writes them
python3 - "$W/rushes.sqlite" "$A" <<'PY' || no "the catalogue is filled"
import sqlite3, sys
db = sqlite3.connect(sys.argv[1]); A = sys.argv[2]
ids = {p[len(A) + 1:]: i for i, p in db.execute("SELECT id, path FROM files") if p.startswith(A + "/")}
media = {   # width, height, fps, codec, duration, proxy_at, recorded, timecode, reel, camera, asis
 "VIDEOS/Parks/2025/Kite Day/A001C003_250101.mov": (3840, 2160, 23.976, "prores", 12.5, 1700000000, "2025-01-01 10:00:00", "01:00:00:00", "A001", "Sony FX6", None),
 "VIDEOS/Parks/2025/Kite Day/Kite Promo.mp4": (1920, 1080, 29.97, "h264", 61.0, 1700000001, "2025-01-05 09:00:00", None, None, None, None),
 "VIDEOS/Parks/2025/Kite Day/Kite Promo v2.mp4": (1920, 1080, 29.97, "h264", 59.2, None, "2025-01-06 09:00:00", None, None, None, "1"),
 "VIDEOS/Parks/2025/Kite Day/openart-kite sky.mp4": (1024, 1024, 24, "h264", 5.0, None, None, None, None, None, "1"),
 "VIDEOS/Parks/2024/Picnic/DJI_0002.MP4": (3840, 2160, 30, "hevc", 301.5, 1700000002, "2024-06-01 12:00:00", None, None, "DJI Mini", None),
 "VIDEOS/Parks/2024/Picnic/picnic table.jpg": (4000, 3000, None, "mjpeg", None, None, None, None, None, None, None),
 "VIDEOS/Harbor Master's Office/2023/Boats #1/boat one.mov": (1280, 720, 25, "h264", 9.9, 1700000003, "2023-03-03 08:00:00", None, "B002", "Canon C70", None),
 "VIDEOS/Harbor Master's Office/2023/Boats #1/Boat Two.mov": (1920, 1080, 25, "h264", 44.0, 1700000004, "2023-03-03 09:00:00", None, "B003", "Canon C70", None),
 "VIDEOS/Public Works/2025/Fiesta/niño bailando.mp4": (1920, 1080, 30, "h264", 20.0, 1700000005, "2025-05-05 20:00:00", None, None, None, None),
 "VIDEOS/Public Works/2025/Fiesta/vertical reel.mp4": (1080, 1920, 30, "h264", 15.0, 1700000006, "2025-05-05 21:00:00", None, None, None, None),
 "VIDEOS/ARCHIVE/2019/Old Parade/parade wide.mxf": (1920, 1080, 29.97, "mpeg2", 120.0, 1700000007, None, None, None, None, None),
 "VIDEOS/ARCHIVE/2019/Old Parade/parade close.mxf": (1920, 1080, 29.97, "mpeg2", 75.0, 1700000008, None, None, None, None, None),
}
for rel, m in media.items():
    db.execute("INSERT OR REPLACE INTO media (file_id,width,height,fps,codec,duration,proxy_at,recorded,timecode,reel,camera,asis) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", (ids[rel],) + m)
fpn = 0
def video(rel, *shots):       # each: kind, start, end, what, tags, themes, shot size, people, light, mood
    global fpn; fpn += 1; fp = "%024x" % (fpn * 7919)
    for n, (kind, a, b, what, tags, themes, size, people, light, mood) in enumerate(shots):
        hay = " ".join([what, tags, themes, mood]).lower()
        db.execute("""INSERT INTO moments (fp,path,kind,shot,start_s,end_s,what,on_screen,themes,tags,shot_size,people,ages,light,part_of_day,mood,language,model,hay,comp)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                   (fp, A + "/" + rel, kind, n, a, b, what, "", themes, tags, size, people, "", light, "day", mood, "en", "test", hay, '{"tags":["rule_of_thirds"]}' if n == 0 else None))
    return fp
K = "VIDEOS/Parks/2025/Kite Day/"
video(K + "A001C003_250101.mov", ("shot", 0, 5, "Children fly kites in a park", "kite · park · children", "family · outdoors", "wide", "few", "daylight", "joyful, calm"),
                                 ("shot", 5, 12.5, "A boy holds a red kite", "kite · boy", "family · play", "close-up", "one", "daylight", "joyful"))
video(K + "Kite Promo.mp4", ("shot", 0, 30, "A kite over the harbor at sunset", "kite · harbor · boats", "outdoors · evening", "extreme-wide", "none", "sunrise-sunset", "calm"),
                            ("speech", 2, 9, "welcome to the kite festival in the park", "", "", "", "", "", ""),
                            ("shot", 30, 61, "Families at a picnic", "picnic · family · park", "family · outdoors", "medium", "crowd", "daylight", "warm"))
video(K + "Kite Promo v2.mp4", ("shot", 0, 59.2, "A kite over the harbor at sunset", "kite · harbor", "outdoors · evening", "wide", "none", "sunrise-sunset", "calm"))
video("VIDEOS/Parks/2024/Picnic/DJI_0002.MP4", ("shot", 0, 100, "A park from above, people at tables", "park · picnic · aerial", "outdoors · city", "extreme-wide", "many", "daylight", "calm, open"),
                                               ("shot", 100, 301.5, "An elderly couple walks by the water", "senior · couple · water", "family · outdoors", "full", "two", "daylight", "tender"),
                                               ("failed", 200, 210, "", "", "", "", "", "", ""))
B = "VIDEOS/Harbor Master's Office/2023/Boats #1/"
video(B + "boat one.mov", ("shot", 0, 9.9, "Boats in the harbor at night — “still” water, Ελληνικά on a sign", "boats · harbor · night", "work · city", "wide", "none", "night", "quiet"))
video(B + "Boat Two.mov", ("shot", 0, 44, "A worker repairs a boat", "boat · worker · repair", "work · city", "medium", "one", "artificial", "focused"),
                          ("speech", 3, 20, "we fix about ten boats a week here", "", "", "", "", "", ""))
F = "VIDEOS/Public Works/2025/Fiesta/"
video(F + "niño bailando.mp4", ("shot", 0, 20, "Un niño baila en la fiesta", "niño · baile · fiesta", "family · city", "full", "one", "dusk", "joyful"))
video(F + "vertical reel.mp4", ("shot", 0, 15, "A crowd dances at a street party", "party · dance · crowd", "city · evening", "wide", "crowd", "artificial", "joyful, loud"))
P = "VIDEOS/ARCHIVE/2019/Old Parade/"
video(P + "parade wide.mxf", ("shot", 0, 60, "A parade with children and a band", "parade · band · kids", "city · family", "wide", "many", "daylight", "festive"),
                             ("shot", 60, 120, "Police officers on horses", "police · horses · parade", "city · work", "full", "few", "daylight", "festive"))
video(P + "parade close.mxf", ("shot", 0, 75, "The band's drummer, close", "band · drummer · parade", "city · music", "extreme-close-up", "one", "daylight", "festive"))
db.commit()
PY
curl -s "$U/db/find.php?viewer=1" > "$R/Viewer.html"
grep -q '<html lang="en" class="vw">' "$R/Viewer.html" && grep -q "RUSHES_LIST = function" "$R/Viewer.html" && ! grep -q 'href="/tokens.css' "$R/Viewer.html" \
  && grep -q '_rushes/viewer/moments.js' "$R/Viewer.html" && ok "the Viewer's page carries all it needs, and asks for its lists beside it" || no "page"
grep -q "Who is using Rushes here" "$R/Viewer.html" && no "the Viewer never asks who is using Rushes"
grep -q '"editors":\[{"name":"Alex","email":"alex@example.org"}\]' "$R/Viewer.html" && ok "the editors a request can go to are the ones set in Rushes (a name without an address is not one)" || no "editors"
curl -s "$U/db/find.php" | grep -q "RUSHES_LIST\|class=\"vw\"" && no "Search in Rushes itself is as it was"
(cd "$HERE/app" && python3 -c 'import sys, runner; i = runner.viewer_lists(sys.argv[1], sys.argv[2], sys.argv[3], say=lambda x: None); assert i["files"] == 14 and i["moments"] == 16, i' \
  "$W/rushes.sqlite" "$A" "$A/_rushes/viewer") && ok "the lists: the archive's videos and pictures and what was described; no cache, no drive, no shot that failed" || no "lists"
cat > "$R/ask.txt" <<'Q'
q=kite&byname=1&limit=120
q=kite+park&byname=1&limit=120
q=kites&byname=1&limit=120&sort=new
q=kite&byname=1&limit=120&sort=old
q=kite&byname=1&limit=120&sort=big
q=kite&byname=1&limit=120&sort=long
q=kite&byname=1&limit=120&sort=name
q=kite&kind=video&limit=120
q=Kite&kind=image&byname=1
q=kid&byname=1
q=elder&byname=1
q=niño&byname=1
q=BOAT&byname=1&limit=1&offset=1
q=boat&byname=1&only=videos&voffset=1
q=boat&byname=1&only=files
q=harbor+master%27s&byname=1
q=%231&byname=1
q=100%25&byname=1
q=parade&where=ARCHIVE&byname=1
q=parade&where=PROJECTS&byname=1
q=fx6
q=B00&byname=1&sort=name
q=dji&res=4k&len=xl
q=&orient=v
q=&orient=s,h&res=hd,sd&kind=video&sort=long
q=&res=4k&sort=big
q=&len=s,m&kind=video&sort=name
q=&year=2025,2019&kind=video&sort=old
q=&year=2025&kind=video&sort=new
q=&ai=only
q=kite&ai=none&byname=1
q=&in=deliverables
q=&in=camera&sort=name
q=&in=nothing
q=&shot=close&kind=video
q=&shot=wide&people=few
q=kite&shot=wide&byname=1
q=&light=golden,night
q=&mood=joyful&people=one
q=parade&mood=festive&light=day&shot=full&byname=1
q=kite&dept=Parks&byname=1
q=promo&byname=1
q=still&byname=1
q=%CE%B5%CE%BB%CE%BB%CE%B7%CE%BD%CE%B9%CE%BA%CE%AC&byname=1
q=night+water&byname=1
q=stil&byname=1
q=&kind=image
q=
q=nothingatall&byname=1
facets=1
Q
V=$(python3 -c 'import sqlite3, sys; print(sqlite3.connect(sys.argv[1]).execute("SELECT vkey FROM labels WHERE vkey IS NOT NULL AND vkey != \"\" LIMIT 1").fetchone()[0])' "$W/rushes.sqlite")
python3 -c 'import sys, urllib.parse as u; print("q=&v=" + u.quote(sys.argv[1])); print("q=&limit=1&p=" + u.quote(sys.argv[2] + "/VIDEOS/Parks/2025/Kite Day/Kite Promo.mp4"))' "$V" "$A" >> "$R/ask.txt"
python3 -c 'import sqlite3, sys; [print("shots=" + r[0]) for r in sqlite3.connect(sys.argv[1]).execute("SELECT DISTINCT fp FROM moments LIMIT 4")]' "$W/rushes.sqlite" >> "$R/ask.txt"
node "$HERE/tests/viewer_agree.js" "$A/_rushes/viewer" "$U" "$R/Viewer.html" "$HERE/app/db/viewer.js" < "$R/ask.txt" > "$R/agree.txt" 2>&1 \
  && ok "the Viewer answers as Rushes does: $(tail -1 "$R/agree.txt")" || { grep -A8 "^FAIL" "$R/agree.txt" | head -60; tail -12 "$R/agree.txt"; no "the Viewer and Rushes differ: $(tail -1 "$R/agree.txt")"; }
grep -c "^PASS" "$R/agree.txt" | grep -q "^56$" || no "every question was asked ($(grep -c '^PASS' "$R/agree.txt"))"
grep "^PASS q=kite&byname=1&limit=120 " "$R/agree.txt" | grep -q "3 videos" && ok "found by what they show, each video once" || no "kite: $(grep '^PASS q=kite&byname=1&limit=120 ' "$R/agree.txt")"
# a drive kept where it is, a sound, a project file: in Rushes' Search, never in the Viewer (not reachable from it, or not footage)
python3 - "$W/rushes.sqlite" "$A" "$D" <<'PY'
import sqlite3, sys
db = sqlite3.connect(sys.argv[1])
for p, k in ((sys.argv[3] + "/Old/kite on a drive.mov", "video"), (sys.argv[2] + "/VIDEOS/Parks/2025/Kite Day/kite song.mp3", "audio"), (sys.argv[2] + "/VIDEOS/Parks/2025/Kite Day/kite.prproj", "project"),
             (sys.argv[2] + "/PROXIES/VIDEOS/x.mp4", "video"), (sys.argv[2] + "/_rushes/analysis/aa/x.jpg", "image")):
    db.execute("INSERT INTO files (path, name, ext, kind, bytes) VALUES (?, ?, ?, ?, 5)", (p, p.rsplit("/", 1)[1], p.rsplit(".", 1)[1], k))
db.execute("INSERT INTO moments (fp, path, kind, shot, start_s, end_s, what, hay) VALUES ('ffffffffffffffffffffffff', ?, 'shot', 0, 0, 1, 'a kite', 'a kite')", (sys.argv[3] + "/Old/kite on a drive.mov",))
db.commit()
PY
(cd "$HERE/app" && python3 -c 'import sys, runner; i = runner.viewer_lists(sys.argv[1], sys.argv[2], sys.argv[3], say=lambda x: None); assert i["files"] == 14 and i["moments"] == 16, i' \
  "$W/rushes.sqlite" "$A" "$R/again") && ok "a drive, a sound, a project file, Rushes' own folders: not in the Viewer's lists" || no "what stays out"
# where the pictures and the previews are, from where the page is; a pull kept in the browser; the request an editor's Rushes reads
node - "$A/_rushes/viewer" "$R/Viewer.html" "$HERE/app/db/viewer.js" "$A" <<'JS' || no "stills, previews, pulls, the request"
const fs = require('fs'), zlib = require('zlib'), [dir, page, js, A] = process.argv.slice(2), V = require(js), assert = require('assert');
const un = (n, how) => zlib.gunzipSync(Buffer.from(fs.readFileSync(dir + '/' + n + '.js', 'utf8').match(/'([A-Za-z0-9+\/=]*)'\);/)[1], 'base64')).toString(how);
V.load({ root: A, related: [], sections: {} }, un('files'), un('moments'), un('words', 'latin1'));
assert.strictEqual(V.still('00000000000000000000aabb', 3), '_rushes/analysis/00/00000000000000000000aabb/0003.jpg');
const K = A + '/VIDEOS/Parks/2025/Kite Day/';
assert.strictEqual(V.play(K + 'Kite Promo.mp4'), 'PROXIES/VIDEOS/Parks/2025/Kite%20Day/Kite%20Promo.mp4', 'a video plays from its proxy');
assert.strictEqual(V.play(K + 'Kite Promo v2.mp4'), 'VIDEOS/Parks/2025/Kite%20Day/Kite%20Promo%20v2.mp4', 'one light enough plays as it is');
assert.strictEqual(V.play(A + "/VIDEOS/Harbor Master's Office/2023/Boats #1/boat one.mov"), "PROXIES/VIDEOS/Harbor%20Master's%20Office/2023/Boats%20%231/boat%20one.mp4", 'a # in a folder name is part of the name');
assert.strictEqual(V.play(A + '/VIDEOS/Parks/2024/Picnic/picnic table.jpg'), 'VIDEOS/Parks/2024/Picnic/picnic%20table.jpg', 'a picture is shown as it is');
assert.strictEqual(V.play(A + '/VIDEOS/ARCHIVE/2019/Old Parade/no words.mov'), '', 'no proxy: nothing to play');
assert.strictEqual(V.play('/etc/passwd'), '', 'only what the lists hold');
const mem = { v: {}, getItem(k) { return this.v[k] || null; }, setItem(k, x) { this.v[k] = x; } };
const p = V.pulls(mem, {}, { action: 'create', name: ' Recap  video ', made_by: 'Sam' });
assert.ok(V.pulls(mem, {}, { action: 'add', p: p.slug, path: K + 'Kite Promo.mp4' }).ok); V.pulls(mem, {}, { action: 'add', p: p.slug, path: K + 'Kite Promo.mp4' });
assert.ok(V.pulls(mem, {}, { action: 'add', p: p.slug, path: K + 'A001C003_250101.mov' }).ok);
assert.ok(V.pulls(mem, {}, { action: 'add', p: p.slug, path: '/etc/passwd' }).error, 'only files of the archive');
let d = V.pulls(mem, { p: p.slug });
assert.deepStrictEqual(d.items.map(i => i.name), ['Kite Promo.mp4', 'A001C003_250101.mov'], 'each clip once');
assert.deepStrictEqual(V.pulls(mem, { list: '1' }).pulls, [{ slug: p.slug, name: 'Recap  video', n: 2 }]);
const t = V.request({ name: d.pull.name, items: d.items }, 'Sam\nRivera', 'Alex', 'due Friday');
fs.writeFileSync(process.argv[5] + '/../request.txt', t);
assert.ok(/^===== RUSHES REQUEST =====\nFrom: Sam Rivera\nFor: Alex\nPull: Recap video\nNote: due Friday\nClips: 2\n1\. Kite Promo\.mp4 \[#\d+\]\n2\. A001C003_250101\.mov \[#\d+\]\n===== END OF REQUEST =====$/.test(t), t);
V.pulls(mem, {}, { action: 'remove', p: p.slug, rel: d.items[0].rel }); assert.strictEqual(V.pulls(mem, { p: p.slug }).items.length, 1);
assert.ok(V.pulls(mem, { p: 'nope' }).error && V.pulls(mem, {}, { action: 'copy', p: p.slug }).error, 'copying is Rushes\' own');
JS
grep -q "^PASS q=still&byname=1 .* 1 videos" "$R/agree.txt" && grep -q "^PASS q=%CE%B5.* 1 videos" "$R/agree.txt" && grep -q "^PASS q=stil&byname=1 .* 0 videos" "$R/agree.txt" \
  && ok "a word beside a curly quote, a word in another alphabet: found, and only whole" || no "odd letters: $(grep 'still\|%CE\|stil&' "$R/agree.txt")"
ok "stills and previews from where the page is; a pull kept in the browser; the request as an editor's Rushes reads it"
# the request, as it arrives in an email: quoted by the mail program, a long name folded, a greeting and a signature around it
python3 - "$R/request.txt" > "$R/mail.txt" <<'PY'
import sys
t = open(sys.argv[1]).read().replace("1. Kite Promo.mp4 [", "1. Kite\nPromo.mp4 [")
print("Hi Alex,\r\n\r\nCould you bring these in?\r\n\r\n" + "\r\n".join("> " + l for l in t.split("\n")) + "\r\n\r\nThanks,\r\nSam\r\n--\r\n1. Not a clip [#1]\r\nSent from my phone")
PY
P() { curl -s --data-urlencode "text@$1" -d action=request "$U/db/pulls.php"; }
X=$(P "$R/mail.txt"); S=$(echo "$X" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("slug",""))')
echo "$X" | grep -q '"name":"Request from Sam Rivera: Recap video","n":2,"missing":\[\]' && ok "Rushes reads the request out of the email around it, and makes the pull" || no "request: $X"
curl -s "$U/db/pulls.php?p=$S" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert [i["name"] for i in d["items"]] == ["Kite Promo.mp4", "A001C003_250101.mov"], d' && ok "its clips, in the order asked" || no "its clips"
sed 's/A001C003_250101.mov/Something else.mov/' "$R/request.txt" > "$R/renamed.txt"
P "$R/renamed.txt" | grep -q '"n":1,"missing":\["Something else.mov"\]' && ok "a clip whose name and number do not agree is said to be missing, never guessed" || no "missing: $(P "$R/renamed.txt")"
grep -v "^2\. " "$R/request.txt" > "$R/cut.txt"
case "$(P "$R/cut.txt")" in *"says 2 clips, and 1 could be read"*) ok "a request with a part missing makes nothing" ;; *) no "cut: $(P "$R/cut.txt")" ;; esac
printf 'Hi, can you send me the kite video?' > "$R/none.txt"
case "$(P "$R/none.txt")" in *"No request found"*) ok "an email with no request in it says so" ;; *) no "none" ;; esac
[ "$(curl -s "$U/db/pulls.php?list=1" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)["pulls"]))')" = 2 ] && ok "and only the requests that could be read made a pull" || no "how many pulls"
echo "viewer: all checks pass"
