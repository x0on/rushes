#!/bin/sh
# Tidy up on a named shelf that holds whole drives copied onto it (PAMedia: VIDEOS/VIDEO from QNAP …):
# the drives' folders wait to be filed, a department's folder on the shelf does not, a folder Setup
# keeps as it is never does; each goes to shelf / department / year / its folder, the year from its
# name or from its clips' dates, none when they disagree. Run: sh tests/test_tidy_shelf.sh
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd)
PHP=${PHPBIN:-php}
R=$(mktemp -d); trap 'kill $(cat "$R/pid" 2>/dev/null) 2>/dev/null; rm -rf "$R"' EXIT
A="$R/Media\$"; W="$R/web"
mkdir -p "$W" "$A/_rushes"
cp -r "$HERE/app/." "$W/"
cat > "$W/settings.json" <<EOF
{"name":"Rushes","archive":{"local":"$A","web":"$W"},"helper":{"mode":"built_in"},
 "organise":{"shelves":"VIDEOS","keep":["PHOTO GALLERY"],"departments":[{"name":"Parks","folder":"PARKS"},{"name":"Police","folder":"POLICE"}]}}
EOF
f() { mkdir -p "$(dirname "$A/$1")"; printf '%s' "$1" > "$A/$1"; [ -n "${2:-}" ] && touch -t "$2" "$A/$1"; return 0; }
f "VIDEOS/VIDEO from QNAP/001 VIDEO/PARKS/Kite Fest/A001.MXF" 201904060000
f "VIDEOS/VIDEO from QNAP/001 VIDEO/PARKS/Kite Fest/A002.MXF" 201904060000
f "VIDEOS/VIDEO from QNAP/001 VIDEO/PARKS/STOC 2025/B.mp4" 202601010000
f "VIDEOS/VIDEO from QNAP/ALL LUIS/Fishing/c1.mov" 201901010000
f "VIDEOS/VIDEO from QNAP/ALL LUIS/Fishing/c2.mov" 202301010000
f "VIDEOS/VIDEO from publicaffairs/Departments/Parks/2025/Un parque/d.mxf" 202505050000
f "VIDEOS/POLICE/already filed.mov" 202001010000
f "PHOTO GALLERY/Events/e.jpg"
f "VIDEOS/VIDEO from QNAP/001 VIDEO/PARKS/Kite Fest/old clock.mov" 200001010000   # a clock never set: not believed
(cd "$A" && find "$A" -type f | while IFS= read -r p; do printf '%s\t%s\n' "$(wc -c < "$p" | tr -d ' ')" "$p"; done) > "$W/manifest.tsv"
cut -f2 "$W/manifest.tsv" > "$W/index.txt"
(cd "$R" && "$PHP" -S 127.0.0.1:18661 -t "$W" "$HERE/app/router.php" > /dev/null 2>&1 & echo $! > "$R/pid"); sleep 1.5
U=http://127.0.0.1:18661
ok() { echo "PASS $1"; }
no() { echo "FAIL $1"; exit 1; }
curl -s "$U/db/import.php?part=web&force=1" > /dev/null
P=$(curl -s -d pass=rushes "$U/db/tidy.php")
echo "$P" | python3 -c '
import json, sys
d = json.load(sys.stdin); g = {x["key"].split("/VIDEOS/")[1]: x for x in d["groups"]}
assert set(g) == {"VIDEO from QNAP/001 VIDEO/PARKS/Kite Fest", "VIDEO from QNAP/001 VIDEO/PARKS/STOC 2025",
                  "VIDEO from QNAP/ALL LUIS/Fishing", "VIDEO from publicaffairs/Departments/Parks/2025"}, sorted(g)
y = {k: (v["year"], v["year_why"]) for k, v in g.items()}
assert y["VIDEO from QNAP/001 VIDEO/PARKS/Kite Fest"][0] == "2019", y
assert y["VIDEO from QNAP/001 VIDEO/PARKS/STOC 2025"] == ("2025", "year in the folder'"'"'s name"), y
assert y["VIDEO from QNAP/ALL LUIS/Fishing"][0] is None and "disagree" in y["VIDEO from QNAP/ALL LUIS/Fishing"][1], y
assert y["VIDEO from publicaffairs/Departments/Parks/2025"] == (None, "a year folder already"), y
assert all(v["flat"] for v in g.values())
' && ok "a drive copied onto the shelf waits to be filed; a department's folder and a kept folder do not; years from names and clips" || no "proposal: $P"
J=$(python3 -c 'import json,sys; a=sys.argv[1]+"/VIDEOS/"; print(json.dumps({a+"VIDEO from QNAP/001 VIDEO/PARKS/Kite Fest": "Parks", a+"VIDEO from QNAP/ALL LUIS/Fishing": "Police", a+"VIDEO from publicaffairs/Departments/Parks/2025": "Parks"}))' "$A")
Q=$(curl -s --data-urlencode "pass=rushes" --data-urlencode "go=1" --data-urlencode "picks=$J" "$U/db/tidy.php")
id=$(echo "$Q" | python3 -c 'import json,sys; print(json.load(sys.stdin)["queued"])') || no "go: $Q"
plan=$(cat "$W/tidy-$id.tsv")
printf '%s\n' "$plan" | grep -qF "	$A/VIDEOS/Parks/2019/Kite Fest/A001.MXF" \
  && printf '%s\n' "$plan" | grep -qF "	$A/VIDEOS/POLICE/Fishing/c1.mov" \
  && printf '%s\n' "$plan" | grep -qF "	$A/VIDEOS/Parks/2025/Un parque/d.mxf" \
  && ! printf '%s\n' "$plan" | grep -q "PHOTO GALLERY\|already filed" \
  && ok "shelf / department / year / the folder, whole; no year when the clips disagree; a year folder kept as the year" || no "plan: $plan"
