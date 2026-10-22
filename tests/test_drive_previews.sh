#!/bin/sh
# A drive kept where it is: its previews are kept on the archive (_rushes/previews/<its ID>), so they play
# in Search while the drive is unplugged, and its folders go on the Describe list by their full path.
# Run: sh tests/test_drive_previews.sh (PHPBIN=…)
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd)
PHP=${PHPBIN:-php}
R=$(mktemp -d); trap 'kill $(cat "$R/pid" 2>/dev/null) 2>/dev/null; rm -rf "$R"' EXIT
A="$R/Drive"; W="$R/web"; D="$R/Volumes/Films"
mkdir -p "$W" "$A/_rushes" "$D/Gala"
cp -r "$HERE/app/." "$W/"
printf '{"name":"Rushes","archive":{"local":"%s","web":"%s","runs_on":"mac"},"helper":{"mode":"built_in"},"sources":[{"label":"Films","path":"%s","how":"in_place"}]}\n' "$A" "$W" "$D" > "$W/settings.json"
printf '[{"source":"%s","name":"Films","id":"UUID-1","path":"%s","connected":true,"seen":1}]\n' "$D" "$D" > "$W/drives.json"
printf 'original' > "$D/Gala/A001.mov"
printf '%s\t%s\n' 8 "$D/Gala/A001.mov" > "$W/manifest.tsv"
cut -f2 "$W/manifest.tsv" > "$W/index.txt"
(cd "$R" && "$PHP" -S 127.0.0.1:18664 -t "$W" "$HERE/app/router.php" > "$R/srv.log" 2>&1 & echo $! > "$R/pid"); sleep 1.5
U=http://127.0.0.1:18664
ok() { echo "PASS $1"; }
no() { echo "FAIL $1"; tail -5 "$R/srv.log" "$W/php-errors.log" 2>/dev/null; exit 1; }
curl -s "$U/db/import.php?part=web&force=1" > /dev/null
P=$(cd "$W/db" && "$PHP" -r 'require "config.php"; echo preview_of($argv[1]), "|", preview_of("/elsewhere/x.mov") ?? "none";' "$D/Gala/A001.mov")
[ "$P" = "$A/_rushes/previews/UUID-1/Gala/A001.mp4|none" ] && ok "a kept drive's preview lives on the archive, under the drive's ID; nowhere else has one" || no "preview_of: $P"
L=$(curl -s -G -d pass=rushes --data-urlencode "locate[]=Gala/A001.mov" "$U/db/analyze.php")
case "$L" in *"\"folders\":[\"${D//\//\\/}\/Gala\"]"*|*"\"folders\":[\"$D/Gala\"]"*) ok "Choose in Finder (Describe) finds a folder of a drive kept where it is, by its full path" ;; *) no "locate: $L" ;; esac
X=$(curl -s -d pass=rushes -d action=prepare --data-urlencode "path=$D/Gala" "$U/db/analyze.php")
[ "$(cut -f1 "$W/prepare.tsv")" = "$D/Gala" ] && ok "its folder goes on the Describe list by its full path" || no "prepare: $X"
grep -qxF "$D/Gala" "$W/proxy-next.txt" && ok "its previews are next, for the runner" || no "next: $(cat "$W/proxy-next.txt" 2>&1)"
case "$(curl -s -d pass=rushes -d action=prepare --data-urlencode "path=/etc" "$U/db/analyze.php")" in *error*) ok "a folder on no kept drive is refused" ;; *) no "elsewhere" ;; esac
mkdir -p "$A/City Manager's Office"
X=$(curl -s -d pass=rushes -d action=prepare --data-urlencode "path=City Manager's Office" "$U/db/analyze.php")
cut -f1 "$W/prepare.tsv" | grep -qxF "City Manager's Office" && ok "a folder of the archive with an apostrophe in its name goes on the list" || no "apostrophe: $X"
case "$(curl -s -d pass=rushes -d action=prepare --data-urlencode "path=../etc" "$U/db/analyze.php")" in *error*) ok "but never one outside it" ;; *) no "dotdot" ;; esac
# the runner made it (runner.py, tested in test_mac_runner): a preview, and its line in the ledger
mkdir -p "$A/_rushes/previews/UUID-1/Gala"; printf 'preview' > "$A/_rushes/previews/UUID-1/Gala/A001.mp4"
printf '%s\t%s\tDuration: 00:00:01.00 Video: mpeg4, yuv420p, 1280x720, 25 fps\n' "$D/Gala/A001.mov" "$(date +%s)" > "$W/proxy-made.tsv"
curl -s "$U/db/import.php?part=web&force=1" > /dev/null
rm -rf "$D"; printf '[{"source":"%s","name":"Films","id":"UUID-1","path":"%s","connected":false,"seen":1}]\n' "$D" "$D" > "$W/drives.json"
[ "$(curl -s --data-urlencode "p=$D/Gala/A001.mov" -G "$U/db/play.php")" = preview ] && ok "it plays in Search with the drive unplugged, from the archive" || no "play"
curl -s -G --data-urlencode "q=A001" "$U/db/search.php" | grep -q '"away":true' && ok "Search says its drive is not plugged in (the badge on the preview)" || no "away"
T=$(curl -s -G -d pass=rushes "$U/db/analyze.php")
echo "$T" | grep -q '"step":"unplugged"' && ok "on the list, it waits for its drive" || no "unplugged: $(echo "$T" | head -c 300)"
