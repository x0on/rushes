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
# a folder of the drive not in Search yet (the drive still being listed): found on the drive itself
mkdir -p "$D/Recap/Gala Night/Cam A"; printf x > "$D/Recap/Gala Night/Cam A/C001.mov"
L=$(curl -s -G -d pass=rushes --data-urlencode "locate[]=Gala Night/Cam A/C001.mov" "$U/db/analyze.php")
echo "$L" | grep -qF "/Recap/Gala Night\"" && ok "Choose folder finds a drive's folder Search has not listed yet, on the drive" || no "locate on disk: $L"
# Start on this one now: to the top of the list, and the helper is told which folder goes first
X=$(curl -s -d pass=rushes -d action=first --data-urlencode "path=City Manager's Office" "$U/db/analyze.php")
[ "$(head -1 "$W/prepare.tsv" | cut -f1)" = "City Manager's Office" ] && grep -q '"first":"[^"]*City Manager' "$W/helper-control.json" \
    && ok "Start on this one now puts it first, and tells the helper's describing" || no "first: $X $(cat "$W/helper-control.json" 2>&1)"
case "$(curl -s -d pass=rushes -d action=first --data-urlencode "path=Nowhere" "$U/db/analyze.php")" in *error*) ok "only a folder on the list" ;; *) no "first not on list" ;; esac
# proxies stopped for it carry on by themselves after; stopped by a person, they wait for Try again
R2=$(cd "$W/db" && "$PHP" -r '
  require "prepare.php"; $f = "City Manager'"'"'s Office"; $keep = prepare_list();
  prepare_save([$f => time() - 60]);
  file_put_contents(web_dir() . "/proxy-folders.tsv", "$f\tstopped\t1\t0\t0\t5\t" . time() . "\n");
  $a = prepare_advance()["proxies_next"]; prepare_resume($f, true); $b = prepare_advance()["proxies_next"];
  file_put_contents(web_dir() . "/proxy-folders.tsv", "$f\tdone\t5\t0\t0\t5\t" . time() . "\n"); prepare_advance();
  echo json_encode([$a, $b, isset(prepare_resume()[$f])]); prepare_save($keep); @unlink(web_dir() . "/proxy-folders.tsv");')
[ "$R2" = "[\"\",\"City Manager's Office\",false]" ] && ok "proxies stopped for another folder carry on by themselves after (and only those)" || no "resume: $R2"
# Describe without previews: no previews, straight to describing, from the originals
X=$(curl -s -d pass=rushes -d action=nopreview --data-urlencode "path=City Manager's Office" "$U/db/analyze.php")
T=$(curl -s -G -d pass=rushes "$U/db/analyze.php")
echo "$T" | python3 -c "import json,sys; r=[x for x in json.load(sys.stdin)['table'] if x['folder']==\"City Manager's Office\"][0]; assert r['proxies']['step']=='skipped' and r['describe']['step'] in ('queued','next'), r" \
    && grep -q "^analyze	.*City Manager's Office	" "$W/ingest-queue.tsv" && ok "Describe without previews: no previews, and it goes to describing at once" || no "nopreview: $X $(cat "$W/ingest-queue.tsv" 2>&1)"
curl -s -d pass=rushes -d action=withpreview --data-urlencode "path=City Manager's Office" "$U/db/analyze.php" > /dev/null
# the time left: from the folder's own pace while it is made, never from earlier folders' average
R3=$(cd "$W/db" && "$PHP" -r '
  require "prepare.php"; $f = "City Manager'"'"'s Office"; $keep = prepare_list(); prepare_save([$f => time() - 60]);
  $t = time(); file_put_contents(web_dir() . "/proxy-alive.txt", "$t\n");
  $st = fn($files) => file_put_contents(web_dir() . "/proxy-status.txt", "at\t$t\nstate\tbuilding\nonly\t$f\ndone\t11\ntotal\t20\nok\t10\nrun_t0\t" . ($t - 600) . "\nrun_bytes\t" . (10 * 2**30) . "\nrun_files\t$files\n");
  $st(10); $r = prepare_table()[0]; $a = [$r["left_how"], $r["left"]];
  $st(2); $r = prepare_table()[0]; $b = [$r["left_how"], $r["left"]];
  echo json_encode([$a, $b, $r["plan"]["bytes"]]); prepare_save($keep); @unlink(web_dir() . "/proxy-status.txt"); @unlink(web_dir() . "/proxy-alive.txt");')
python3 -c "
import json,sys; (a,b,bytes_)=json.loads(sys.argv[1])
want=round(bytes_*(1-11/20)/(10*2**30/600))
assert a==['measured',want] and b[0]=='working' and b[1] is None, (a,b,want)" "$R3" && ok "while making, the time left is this folder's own pace; before 3 files, it says it is working it out" || no "left: $R3"
# the runner made it (runner.py, tested in test_mac_runner): a preview, and its line in the ledger
mkdir -p "$A/_rushes/previews/UUID-1/Gala"; printf 'preview' > "$A/_rushes/previews/UUID-1/Gala/A001.mp4"
printf '%s\t%s\tDuration: 00:00:01.00 Video: mpeg4, yuv420p, 1280x720, 25 fps\n' "$D/Gala/A001.mov" "$(date +%s)" > "$W/proxy-made.tsv"
curl -s "$U/db/import.php?part=web&force=1" > /dev/null
rm -rf "$D"; printf '[{"source":"%s","name":"Films","id":"UUID-1","path":"%s","connected":false,"seen":1}]\n' "$D" "$D" > "$W/drives.json"
[ "$(curl -s --data-urlencode "p=$D/Gala/A001.mov" -G "$U/db/play.php")" = preview ] && ok "it plays in Search with the drive unplugged, from the archive" || no "play"
curl -s -G --data-urlencode "q=A001" "$U/db/search.php" | grep -q '"away":true' && ok "Search says its drive is not plugged in (the badge on the preview)" || no "away"
T=$(curl -s -G -d pass=rushes "$U/db/analyze.php")
echo "$T" | grep -q '"step":"unplugged"' && ok "on the list, it waits for its drive" || no "unplugged: $(echo "$T" | head -c 300)"
