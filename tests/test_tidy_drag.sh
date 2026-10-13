#!/bin/sh
# Reorganize → Drag and drop (db/tidy.php ?tree, ?matches, drag=1): any folder at any depth onto a
# department; the deepest drop wins; a department-named folder gives up its name, any other keeps it
# under its year; nothing already filed is ever moved. Run: sh tests/test_tidy_drag.sh (PHPBIN=…)
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd)
PHP=${PHPBIN:-php}
R=$(mktemp -d); trap 'kill $(cat "$R/pid" 2>/dev/null) 2>/dev/null; rm -rf "$R"' EXIT
A="$R/Drive"; W="$R/web"
mkdir -p "$W" "$A/_rushes"
cp -r "$HERE/app/." "$W/"
printf '{"name":"Rushes","archive":{"local":"%s","web":"%s"},"helper":{"mode":"built_in"},"organise":{"shelves":"/","departments":[{"name":"Parks & Recreation"},{"name":"Police Department","folder":"POLICE"},{"name":"Elected Officials"}]}}\n' "$A" "$W" > "$W/settings.json"
f() { mkdir -p "$(dirname "$A/$1")"; printf '%s' "$1" > "$A/$1"; }
Q="VIDEO from an old server"
f "$Q/001 VIDEO/PARKS/2019/Kite/A001.MXF"
f "$Q/001 VIDEO/PARKS/2019/Kite/A002.MXF"
f "$Q/001 VIDEO/PARKS/Spring Fest/b.mov"
f "$Q/001 VIDEO/PARKS/loose.mov"
f "$Q/001 VIDEO/POLICE DEPARTMNET/Sub/c.mov"
f "$Q/COUNCIL EVENTS/Gala/d.mov"
f "$Q/COUNCIL EVENTS/Gala/inner/e.mov"
f "POLICE/2020/filed.mov"
touch -t 202106150000 "$A/$Q/001 VIDEO/PARKS/Spring Fest/b.mov"
touch -t 202303150000 "$A/$Q/COUNCIL EVENTS/Gala/d.mov" "$A/$Q/COUNCIL EVENTS/Gala/inner/e.mov"
find "$A" -type f | while IFS= read -r p; do printf '%s\t%s\n' "$(wc -c < "$p" | tr -d ' ')" "$p"; done > "$W/manifest.tsv"
cut -f2 "$W/manifest.tsv" > "$W/index.txt"
(cd "$R" && "$PHP" -S 127.0.0.1:18662 -t "$W" "$HERE/app/router.php" > /dev/null 2>&1 & echo $! > "$R/pid"); sleep 1.5
U=http://127.0.0.1:18662
ok() { echo "PASS $1"; }
no() { echo "FAIL $1"; exit 1; }
curl -s "$U/db/import.php?part=web&force=1" > /dev/null
T=$(curl -s -d pass=rushes --data-urlencode "tree=" "$U/db/tidy.php")
echo "$T" | python3 -c '
import json, sys; d = json.load(sys.stdin)["folders"]
assert [x["rel"] for x in d] == ["VIDEO from an old server"] and d[0]["n"] == 7, d
' && ok "the left side starts with what waits to be filed; a department's folder already filed is not there" || no "tree: $T"
T=$(curl -s -d pass=rushes --data-urlencode "tree=$Q/001 VIDEO" "$U/db/tidy.php")
echo "$T" | python3 -c '
import json, sys; d = {x["name"]: x for x in json.load(sys.stdin)["folders"]}
assert set(d) == {"PARKS", "POLICE DEPARTMNET"} and d["PARKS"]["n"] == 4, d
assert d["PARKS"]["dept"] == "Parks & Recreation" and d["PARKS"]["sure"] is False and d["POLICE DEPARTMNET"]["dept"] == "Police Department", d
' && ok "a folder opens to its folders, each with the department its name suggests" || no "subtree: $T"
M=$(curl -s -d pass=rushes -d matches=1 "$U/db/tidy.php")
echo "$M" | python3 -c '
import json, sys; d = {x["rel"].split("/", 1)[1]: x for x in json.load(sys.stdin)["matches"]}
assert set(d) == {"001 VIDEO/PARKS", "001 VIDEO/POLICE DEPARTMNET"}, d
assert d["001 VIDEO/PARKS"]["n"] == 4 and not d["001 VIDEO/PARKS"]["sure"], d
' && ok "folders that look like a department are found however deep, as suggestions; others are not guessed" || no "matches: $M"
J=$(python3 -c 'import json,sys; q=sys.argv[1]; print(json.dumps({q+"/001 VIDEO/PARKS": {"dept": "Parks & Recreation"},
  q+"/001 VIDEO/PARKS/Spring Fest": {"dept": "Police Department", "keep": True},
  q+"/COUNCIL EVENTS": {"dept": "Elected Officials", "keep": True}, "POLICE": {"dept": "Elected Officials"},
  q+"/../x": {"dept": "Parks & Recreation"}, q: {"dept": "Nobody"}}))' "$Q")
D=$(curl -s -d pass=rushes -d drag=1 -d token=review0001 --data-urlencode "drops=$J" "$U/db/tidy.php")
echo "$D" | python3 -c '
import json, sys; d = json.load(sys.stdin)
assert d["files"] == 6 and "queued" not in d, d
' && ok "looked at first: how many files, nothing queued; a filed folder, a way out and an unknown department count for nothing" || no "dry: $D"
S=$(curl -s -d pass=rushes -d dragsaid=review0001 "$U/db/tidy.php")
echo "$S" | grep -q '"done":true' && [ -f "$W/tidy-draft-review0001.tsv" ] && ok "the review is worked out once, saying how far it is, and kept" || no "said: $S"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$U/tidy-draft-review0001.tsv")" != 200 ] && ok "a review is not handed out" || no "draft served"
G=$(curl -s -d pass=rushes -d drag=1 -d go=1 -d token=review0001 "$U/db/tidy.php")
id=$(echo "$G" | python3 -c 'import json,sys; print(json.load(sys.stdin)["queued"])') || no "go: $G"
plan=$(cat "$W/tidy-$id.tsv")
has() { printf '%s\n' "$plan" | grep -qxF "file	$A/$1	$A/$2" || no "missing: $1 → $2 in
$plan"; }
has "$Q/001 VIDEO/PARKS/2019/Kite/A001.MXF" "Parks & Recreation/2019/Kite/A001.MXF"
has "$Q/001 VIDEO/PARKS/loose.mov" "Parks & Recreation/loose.mov"
has "$Q/001 VIDEO/PARKS/Spring Fest/b.mov" "POLICE/2021/Spring Fest/b.mov"
has "$Q/COUNCIL EVENTS/Gala/d.mov" "Elected Officials/2023/COUNCIL EVENTS/Gala/d.mov"
has "$Q/COUNCIL EVENTS/Gala/inner/e.mov" "Elected Officials/2023/COUNCIL EVENTS/Gala/inner/e.mov"
[ "$(printf '%s\n' "$plan" | wc -l | tr -d ' ')" = 6 ] && ! printf '%s\n' "$plan" | grep -q "filed.mov\|c.mov" \
  && ok "a department's folder gives up its name, any other keeps it under its year; the deepest drop wins; the rest stays" || no "plan: $plan"
grep -q "^tidy	$id$" "$W/ingest-queue.tsv" && [ ! -f "$W/tidy-draft-review0001.tsv" ] && ok "Yes queues exactly the plan reviewed, as any tidy-up" || no "queue"
case "$(curl -s -d pass=rushes -d drag=1 -d go=1 -d token=review0001 "$U/db/tidy.php")" in *"press Move"*) ok "a review is queued once" ;; *) no "twice" ;; esac
