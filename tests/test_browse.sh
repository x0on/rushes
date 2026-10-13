#!/bin/sh
# Reorganize → Tidy up (db/browse.php): the archive as Finder shows it; a move is a rename, at once, written
# down, followed by Search, its previews with it, undone on asking; a drive kept where it is never changes.
# Run: sh tests/test_browse.sh (PHPBIN=…)
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd)
PHP=${PHPBIN:-php}
R=$(mktemp -d); trap 'kill $(cat "$R/pid" 2>/dev/null) 2>/dev/null; rm -rf "$R"' EXIT
A="$R/Drive"; W="$R/web"; D="$R/Volumes/Films"
mkdir -p "$W" "$A/_rushes" "$D/Old"
cp -r "$HERE/app/." "$W/"
printf '{"name":"Rushes","archive":{"local":"%s","web":"%s"},"helper":{"mode":"built_in"},"sources":[{"label":"Films","path":"%s","how":"in_place"}],"organise":{"shelves":"VIDEOS","departments":[{"name":"Parks"},{"name":"Public Works"}]}}\n' "$A" "$W" "$D" > "$W/settings.json"
printf '[{"source":"%s","name":"Films","id":"UUID-1","path":"%s","connected":true,"seen":1}]\n' "$D" "$D" > "$W/drives.json"
f() { mkdir -p "$(dirname "$A/VIDEOS/$1")"; printf '%s' "$1" > "$A/VIDEOS/$1"; }
f "Parks/2025/Kite/a.mov"; f "Parks/2025/Kite/b.mov"; f "Public Works/2025/Kite/b.mov"; f "Public Works/2025/Kite/c.mov"
f "Public Works/2025/Shelter/d.mov"
mkdir -p "$A/PROXIES/VIDEOS/Parks/2025/Kite"; printf p > "$A/PROXIES/VIDEOS/Parks/2025/Kite/a.mp4"
printf x > "$D/Old/e.mov"
find "$A/VIDEOS" "$D" -type f | while IFS= read -r p; do printf '%s\t%s\n' "$(wc -c < "$p" | tr -d ' ')" "$p"; done > "$W/manifest.tsv"
cut -f2 "$W/manifest.tsv" > "$W/index.txt"
(cd "$R" && "$PHP" -S 127.0.0.1:18665 -t "$W" "$HERE/app/router.php" > "$R/srv.log" 2>&1 & echo $! > "$R/pid"); sleep 1.5
U=http://127.0.0.1:18665
ok() { echo "PASS $1"; }
no() { echo "FAIL $1"; tail -5 "$R/srv.log" "$W/php-errors.log" 2>/dev/null; exit 1; }
curl -s "$U/db/import.php?part=web&force=1" > /dev/null
B() { curl -s -d pass=rushes "$@" "$U/db/browse.php"; }
G() { curl -s -G -d pass=rushes --data-urlencode "$1" "$U/db/browse.php"; }
G roots=1 | grep -q '"name":"Films","archive":false' && ok "the places to look: the archive, and each drive in Search" || no "roots"
G "dir=$A" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert [i["name"] for i in d["items"]] == ["VIDEOS"], d' && ok "the archive's top, without Rushes' own folders" || no "top"
G "dir=$A/../" | grep -q error && ok "never a way out of it" || no "way out"
J=$(python3 -c 'import json,sys; print(json.dumps([sys.argv[1]]))' "$A/VIDEOS/Public Works/2025/Shelter")
X=$(B -d op=move --data-urlencode "items=$J" --data-urlencode "to=$A/VIDEOS/Parks/2025")
[ -f "$A/VIDEOS/Parks/2025/Shelter/d.mov" ] && [ ! -e "$A/VIDEOS/Public Works/2025/Shelter" ] && ok "a folder moves at once, as in Finder" || no "move: $X"
curl -s -G --data-urlencode "q=d.mov" "$U/db/search.php" | grep -q "Parks/2025/Shelter/d.mov" && ok "Search follows it at once" || no "search"
grep -qF "moved	$A/VIDEOS/Public Works/2025/Shelter/d.mov	$A/VIDEOS/Parks/2025/Shelter/d.mov" "$A"/_rushes/origin/*moved\ here.tsv && ok "each file's move is written down, for relinking Premiere projects" || no "record"
J=$(python3 -c 'import json,sys; print(json.dumps([sys.argv[1]]))' "$A/VIDEOS/Parks/2025/Kite")
X=$(B -d op=move --data-urlencode "items=$J" --data-urlencode "to=$A/VIDEOS/Public Works/2025")
echo "$X" | grep -q '"taken":\[{"name":"Kite","dir":true}\]' && [ -d "$A/VIDEOS/Parks/2025/Kite" ] && ok "a folder of the same name there: asked, nothing moved" || no "taken: $X"
X=$(B -d op=move -d merge=1 --data-urlencode "items=$J" --data-urlencode "to=$A/VIDEOS/Public Works/2025")
[ -f "$A/VIDEOS/Public Works/2025/Kite/a.mov" ] && [ "$(cat "$A/VIDEOS/Public Works/2025/Kite/b.mov")" = "Public Works/2025/Kite/b.mov" ] && [ -f "$A/VIDEOS/Parks/2025/Kite/b.mov" ] \
  && [ -f "$A/PROXIES/VIDEOS/Public Works/2025/Kite/a.mp4" ] && echo "$X" | grep -q '"kept_n":1' \
  && ok "merged: each file joins it with its preview; a file whose name is taken stays, and is said" || no "merge: $X"
B -d op=undo > /dev/null
[ -f "$A/VIDEOS/Parks/2025/Kite/a.mov" ] && [ ! -e "$A/VIDEOS/Public Works/2025/Kite/a.mov" ] && [ -f "$A/PROXIES/VIDEOS/Parks/2025/Kite/a.mp4" ] && ok "Undo takes a merge apart again, file by file" || no "undo merge"
B -d op=rename --data-urlencode "item=$A/VIDEOS/Parks/2025/Shelter" -d "name=Picnic Shelter" > /dev/null
[ -f "$A/VIDEOS/Parks/2025/Picnic Shelter/d.mov" ] && ok "renamed" || no "rename"
case "$(B -d op=rename --data-urlencode "item=$A/VIDEOS/Parks/2025/Picnic Shelter" -d "name=../x")" in *error*) ok "a name with a slash is refused" ;; *) no "bad name" ;; esac
B -d op=undo > /dev/null
[ -f "$A/VIDEOS/Parks/2025/Shelter/d.mov" ] && ok "Undo puts the last change back" || no "undo"
B -d op=mkdir --data-urlencode "dir=$A/VIDEOS/Parks" -d "name=2026" > /dev/null; [ -d "$A/VIDEOS/Parks/2026" ] && ok "a new folder" || no "mkdir"
J=$(python3 -c 'import json,sys; print(json.dumps([sys.argv[1]]))' "$D/Old")
X=$(B -d op=move --data-urlencode "items=$J" --data-urlencode "to=$A/VIDEOS/Parks")
[ -f "$D/Old/e.mov" ] && echo "$X" | grep -q "Copying" && ok "a drive kept where it is never changes: copying it in is Copying's" || no "kept: $X"
J=$(python3 -c 'import json,sys; print(json.dumps([sys.argv[1]]))' "$A/_rushes")
X=$(B -d op=move --data-urlencode "items=$J" --data-urlencode "to=$A/VIDEOS")
[ -d "$A/_rushes/origin" ] && echo "$X" | grep -q "own folders stay" && ok "Rushes' own folders stay, and it says so" || no "own: $X"
printf 'phase\ttidying\nts\t%s\n' "$(date +%s)" > "$W/ingest-status.tsv"
case "$(B -d op=mkdir --data-urlencode "dir=$A/VIDEOS/Parks" -d "name=x")" in *"tidy-up is moving"*) ok "nothing changes here while a tidy-up moves files" ;; *) no "during tidy" ;; esac
rm -f "$W/ingest-status.tsv"
for bad in "$A/./_rushes" "$A//_rushes" "$A/_rushes/origin" "$A/proxies"; do
  J=$(python3 -c 'import json,sys; print(json.dumps([sys.argv[1]]))' "$bad")
  B -d op=move --data-urlencode "items=$J" --data-urlencode "to=$A/VIDEOS" > /dev/null
done
[ -d "$A/_rushes/origin" ] && [ ! -e "$A/VIDEOS/_rushes" ] && [ ! -e "$A/VIDEOS/origin" ] && ok "no other spelling of a path gets Rushes' own folders moved" || no "own spelled"
J=$(python3 -c 'import json,sys; print(json.dumps([sys.argv[1]]))' "$A/VIDEOS/Parks/2026")
B -d op=move --data-urlencode "items=$J" --data-urlencode "to=$A/PROXIES" > /dev/null; [ -d "$A/VIDEOS/Parks/2026" ] && ok "nothing goes into them either" || no "into own"
case "$(B -d op=rename --data-urlencode "item=$A/VIDEOS" -d "name=_hidden")" in *error*) ok "nor does anything at the top get one of their names" ;; *) no "own name" ;; esac
mkdir -p "$A/VIDEOS/Parks/Kite/sub/Kite"
J=$(python3 -c 'import json,sys; print(json.dumps([sys.argv[1]]))' "$A/VIDEOS/Parks/Kite")
B -d op=move -d merge=1 --data-urlencode "items=$J" --data-urlencode "to=$A/VIDEOS/Parks/Kite/sub" > /dev/null
[ ! -e "$A/VIDEOS/Parks/Kite/sub/Kite/sub" ] && ok "a merge never goes inside itself" || no "merge into itself"
J=$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1:]))' "$A/VIDEOS/Parks/2025/Kite" "$A/VIDEOS/Public Works/nothing" "$A/VIDEOS/Parks/2025/Shelter")
X=$(B -d op=move --data-urlencode "items=$J" --data-urlencode "to=$A/VIDEOS/Parks")
[ -d "$A/VIDEOS/Parks/Shelter" ] && echo "$X" | grep -q "not there any more" && ok "one that cannot move is said; the rest still move" || no "batch: $X"
B -d op=rename --data-urlencode "item=$A/VIDEOS/Parks/Shelter" -d "name=shelter" > /dev/null
ls "$A/VIDEOS/Parks" | grep -qx shelter && ok "a name changed only in its capitals, as on a Mac" || no "case rename"
G "info=$A/VIDEOS/Parks/2025" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d["dir"] and d["files"] >= 1 and "year" in d, d' && ok "a folder's properties: how many files, its clips' dates, its year" || no "info folder"
G "info=$A/VIDEOS/Parks/shelter/d.mov" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert not d["dir"] and d["size"] == len("Public Works/2025/Shelter/d.mov") and d["modified"], d' && ok "a file's: size and dates" || no "info file"
: > "$A/VIDEOS/Parks/empty.mov"; G "dir=$A/VIDEOS/Parks" | grep -q '"name":"empty.mov","dir":false,"size":0' && ok "an empty file says so (zero bytes)" || no "zero"
J=$(python3 -c 'import json,sys; print(json.dumps([sys.argv[1]]))' "$A/VIDEOS/Parks/shelter")
B -d op=check-remove --data-urlencode "items=$J" | grep -q '"files":1,.*"alone":1' && ok "before removing: how much, and how much exists nowhere else" || no "check-remove"
X=$(B -d op=remove --data-urlencode "items=$J")
[ -f "$A/_Recently Removed/VIDEOS/Parks/shelter/d.mov" ] && [ ! -e "$A/VIDEOS/Parks/shelter" ] && grep -qF "$A/_Recently Removed/VIDEOS/Parks/shelter/d.mov" "$W/removed-moves.tsv" \
  && ok "removed into Recently Removed, its folders kept, with the record Delete All needs" || no "remove: $X"
B -d op=undo > /dev/null; [ -f "$A/VIDEOS/Parks/shelter/d.mov" ] && ok "Undo brings it back" || no "undo remove"
mkdir "$A/VIDEOS/Parks/nothing-here"; J=$(python3 -c 'import json,sys; print(json.dumps([sys.argv[1]]))' "$A/VIDEOS/Parks/nothing-here")
B -d op=remove --data-urlencode "items=$J" > /dev/null; [ ! -e "$A/VIDEOS/Parks/nothing-here" ] && [ ! -e "$A/_Recently Removed/VIDEOS/Parks/nothing-here" ] && ok "an empty folder is simply removed" || no "rmdir"
J=$(python3 -c 'import json,sys; print(json.dumps([sys.argv[1]]))' "$A/_rushes")
case "$(B -d op=check-remove --data-urlencode "items=$J")" in *error*) ok "Rushes' own folders are never removed" ;; *) no "remove own" ;; esac

