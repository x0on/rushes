#!/bin/sh
# Reorganize → Tidy up (db/tidy.php ?shelftree, refile=1): folders moved, merged or renamed inside the structure,
# planned on the page and worked out once; never a department's own folder, never into itself, never off it.
# Run: sh tests/test_tidy_refile.sh (PHPBIN=…)
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd)
PHP=${PHPBIN:-php}
R=$(mktemp -d); trap 'kill $(cat "$R/pid" 2>/dev/null) 2>/dev/null; rm -rf "$R"' EXIT
A="$R/Drive"; W="$R/web"
mkdir -p "$W" "$A/_rushes"
cp -r "$HERE/app/." "$W/"
printf '{"name":"Rushes","archive":{"local":"%s","web":"%s"},"helper":{"mode":"built_in"},"organise":{"shelves":"VIDEOS","departments":[{"name":"Parks & Recreation"},{"name":"Public Affairs Department","folder":"PUBLIC AFFAIRS"}]}}\n' "$A" "$W" > "$W/settings.json"
f() { mkdir -p "$(dirname "$A/VIDEOS/$1")"; printf '%s' "$1" > "$A/VIDEOS/$1"; }
f "PUBLIC AFFAIRS/2019/0612 card/a.mov"
f "PUBLIC AFFAIRS/2019/0612 card/b.mov"
f "PUBLIC AFFAIRS/DRONE A/City Hall/c.mov"
f "PUBLIC AFFAIRS/009 DRONE/City Hall/e.mov"
f "Parks & Recreation/2020/Kite/d.mov"
f "VIDEO from an old server/x.mov"
find "$A" -type f | while IFS= read -r p; do printf '%s\t%s\n' "$(wc -c < "$p" | tr -d ' ')" "$p"; done > "$W/manifest.tsv"
cut -f2 "$W/manifest.tsv" > "$W/index.txt"
(cd "$R" && "$PHP" -S 127.0.0.1:18663 -t "$W" "$HERE/app/router.php" > "$R/srv.log" 2>&1 & echo $! > "$R/pid"); sleep 1.5
U=http://127.0.0.1:18663
ok() { echo "PASS $1"; }
no() { echo "FAIL $1"; cat "$R/srv.log" "$W/php-errors.log" 2>/dev/null | tail -5; exit 1; }
curl -s "$U/db/import.php?part=web&force=1" > /dev/null
T=$(curl -s -d pass=rushes --data-urlencode "shelftree=" "$U/db/tidy.php")
echo "$T" | python3 -c '
import json, sys; d = json.load(sys.stdin)["folders"]
assert [(x["name"], x["dept"], x["n"]) for x in d] == [("Parks & Recreation", "Parks & Recreation", 1), ("PUBLIC AFFAIRS", "Public Affairs Department", 4)], d
' && ok "the structure starts with each department's folder, A to Z; what waits to be filed is not in it" || no "tree: $T"
T=$(curl -s -d pass=rushes --data-urlencode "shelftree=PUBLIC AFFAIRS" "$U/db/tidy.php")
echo "$T" | python3 -c '
import json, sys; d = {x["name"]: x for x in json.load(sys.stdin)["folders"]}
assert set(d) == {"2019", "DRONE A", "009 DRONE"} and d["2019"]["rel"] == "PUBLIC AFFAIRS/2019", d
' && ok "a folder opens to its folders" || no "subtree: $T"
case "$(curl -s -d pass=rushes --data-urlencode "shelftree=../x" "$U/db/tidy.php")" in *"not a folder"*) ok "never a way out of it" ;; *) no "way out" ;; esac
J='[{"src":"PUBLIC AFFAIRS/2019/0612 card","dst":"Parks & Recreation/2019/0612 card"},
    {"src":"PUBLIC AFFAIRS/DRONE A","dst":"PUBLIC AFFAIRS/DRONE"},{"src":"PUBLIC AFFAIRS/009 DRONE","dst":"PUBLIC AFFAIRS/DRONE"},
    {"src":"PUBLIC AFFAIRS","dst":"Parks & Recreation/PA"},{"src":"Parks & Recreation/2020","dst":"Parks & Recreation/2020/Kite/2020"},
    {"src":"Parks & Recreation/2020/Kite","dst":"Nowhere/Kite"},{"src":"Parks & Recreation/2020/Kite","dst":"../Kite"}]'
D=$(curl -s -d pass=rushes -d drag=1 -d refile=1 -d token=refile0001 --data-urlencode "ops=$J" "$U/db/tidy.php")
echo "$D" | python3 -c '
import json, sys; d = json.load(sys.stdin)
assert d["files"] == 4 and "queued" not in d, d
' && ok "looked at first; a department's own folder, into itself, outside a department or a way out count for nothing" || no "dry: $D"
G=$(curl -s -d pass=rushes -d drag=1 -d go=1 -d token=refile0001 "$U/db/tidy.php")
id=$(echo "$G" | python3 -c 'import json,sys; print(json.load(sys.stdin)["queued"])') || no "go: $G"
plan=$(cat "$W/tidy-$id.tsv")
has() { printf '%s\n' "$plan" | grep -qxF "refile	$A/VIDEOS/$1	$A/VIDEOS/$2" || no "missing: $1 → $2 in
$plan"; }
has "PUBLIC AFFAIRS/2019/0612 card/a.mov" "Parks & Recreation/2019/0612 card/a.mov"
has "PUBLIC AFFAIRS/DRONE A/City Hall/c.mov" "PUBLIC AFFAIRS/DRONE/City Hall/c.mov"
has "PUBLIC AFFAIRS/009 DRONE/City Hall/e.mov" "PUBLIC AFFAIRS/DRONE/City Hall/e.mov"
[ "$(printf '%s\n' "$plan" | wc -l | tr -d ' ')" = 4 ] && ok "moved to another department, two folders merged into one; each file as a refile line for the helper" || no "plan: $plan"
grep -q "^tidy	$id$" "$W/ingest-queue.tsv" && ok "queued as any tidy-up: recorded, and Put back undoes it" || no "queue"
case "$(curl -s -d pass=rushes --data-urlencode "finder=PUBLIC AFFAIRS/2019" -d where=shelf "$U/db/tidy.php")" in
  *"works on the Mac Rushes runs on"*) ok "Show in Finder only on the Mac Rushes runs on (not a server)" ;; *) no "finder elsewhere" ;; esac
