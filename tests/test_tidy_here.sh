#!/bin/sh
# The tidy-up of folders already in the archive (not copied in by Rushes):
# what Reorganize offers, and the plan it writes for the helper — exactly the
# files each picked row counted. Run: sh tests/test_tidy_here.sh (PHPBIN=… for another PHP)
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd)
PHP=${PHPBIN:-php}
R=$(mktemp -d); trap 'kill $(cat "$R/pid" 2>/dev/null) 2>/dev/null; rm -rf "$R"' EXIT
A="$R/Drive"; W="$R/web"
mkdir -p "$W" "$A/_rushes"
cp -r "$HERE/app/." "$W/"
cat > "$W/settings.json" <<EOF
{"name":"Rushes","archive":{"local":"$A","web":"$W"},"helper":{"mode":"built_in"},
 "organise":{"shelves":"/","departments":[{"name":"Parks"},{"name":"Police","folder":"POLICE"}]}}
EOF
f() { mkdir -p "$(dirname "$A/$1")"; printf '%s' "$1" > "$A/$1"; }
f "Old server/Library/PARKS/2019/Kite/A001.MXF"
f "Old server/Library/PARKS/2019/Kite/A002.MXF"
f "Old server/Library/PARKS/loose.mov"
f "Old server/Misc stuff/x.mov"
f "Old server/Misc stuff/deeper/PARKS/y.mov"
f "POLICE/2020/already on the shelf.mov"
f "_duplicates/z.mov"
f "top.mov"
touch -t 201806150000 "$A/Old server/Misc stuff/x.mov"           # its year, from the clip's date
(cd "$A" && find "$A" -type f | while IFS= read -r p; do printf '%s\t%s\n' "$(wc -c < "$p" | tr -d ' ')" "$p"; done) > "$W/manifest.tsv"
cut -f2 "$W/manifest.tsv" > "$W/index.txt"
(cd "$R" && "$PHP" -S 127.0.0.1:18660 -t "$W" "$HERE/app/router.php" > /dev/null 2>&1 & echo $! > "$R/pid"); sleep 1.5
U=http://127.0.0.1:18660
ok() { echo "PASS $1"; }
no() { echo "FAIL $1"; exit 1; }
curl -s "$U/db/import.php?part=web&force=1" > /dev/null
P=$(curl -s -d pass=rushes "$U/db/tidy.php")
echo "$P" | python3 -c '
import json, sys
d = json.load(sys.stdin); g = {x["key"].split("/Drive/")[1]: x for x in d["groups"]}
assert set(g) == {"Old server/Library/PARKS/2019", "Old server/Library/PARKS", "Old server/Misc stuff", "Old server/Misc stuff/deeper/PARKS"}, sorted(g)
assert g["Old server/Library/PARKS/2019"]["dept"] == "Parks" and g["Old server/Library/PARKS/2019"]["n"] == 2
assert g["Old server/Misc stuff"]["dept"] is None and g["Old server/Misc stuff"]["n"] == 1
assert all(x["here"] for x in g.values()) and d["here"] == 4
' && ok "what is already in the archive is offered, grouped; the shelf, Rushes' own folders and the top are not" || no "proposal: $P"
K="$A/Old server/Library/PARKS/2019"; M="$A/Old server/Misc stuff"
J=$(python3 -c 'import json,sys; print(json.dumps({sys.argv[1]: "Parks", sys.argv[2]: "Police"}))' "$K" "$M")
Q=$(curl -s --data-urlencode "pass=rushes" --data-urlencode "go=1" --data-urlencode "picks=$J" "$U/db/tidy.php")
id=$(echo "$Q" | python3 -c 'import json,sys; print(json.load(sys.stdin)["queued"])') || no "go: $Q"
plan=$(cat "$W/tidy-$id.tsv")
[ "$(printf '%s\n' "$plan" | grep -c '^file	')" = 3 ] && ok "the plan names exactly the files of the picked rows" || no "plan: $plan"
printf '%s\n' "$plan" | grep -q "^file	$A/Old server/Library/PARKS/2019/Kite/A001.MXF	$A/Parks/2019/Kite/A001.MXF$" \
  && printf '%s\n' "$plan" | grep -q "^file	$M/x.mov	$A/POLICE/2018/Old server/Misc stuff/x.mov$" \
  && ! printf '%s\n' "$plan" | grep -q "deeper" \
  && ok "each goes under its department's folder (and the year its clips agree on, none under a year folder already); a row's subfolders that are rows of their own stay out" || no "plan lines: $plan"
grep -q "^tidy	$id$" "$W/ingest-queue.tsv" && ok "the tidy-up is queued for the helper" || no "queue"
