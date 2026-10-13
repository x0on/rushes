#!/bin/sh
# Rescue (db/rescue.php): what the runner found is shown; only a found file with a good copy goes on the list
# to rescue, with the good copy the runner found (never one the page sends); asking queues the runner's job.
# The looking and the rescuing themselves: tests/test_mac_runner.py Rescue. Run: sh tests/test_rescue.sh (PHPBIN=…)
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd)
PHP=${PHPBIN:-php}
R=$(mktemp -d); trap 'kill $(cat "$R/pid" 2>/dev/null) 2>/dev/null; rm -rf "$R"' EXIT
A="$R/Drive"; W="$R/web"
mkdir -p "$W" "$A/VIDEOS/Card"
cp -r "$HERE/app/." "$W/"
printf '{"name":"Rushes","archive":{"local":"%s","web":"%s"},"helper":{"mode":"built_in"}}\n' "$A" "$W" > "$W/settings.json"
printf '' > "$A/VIDEOS/Card/C1.MP4"
printf '%s\t0\tempty: zero bytes\t/Volumes/Old/Card/C1.MP4\t/Volumes/Old/Card/C1.MP4\t200000\t0\t1\n%s\t70000\tnot a clip\t\t\t0\t0\t0\n' "$A/VIDEOS/Card/C1.MP4" "$A/VIDEOS/Card/C2.MP4" > "$W/rescue-found.tsv"
printf '%s\t5\n' "$(date +%s)" > "$W/rescue-looked.txt"
(cd "$R" && "$PHP" -S 127.0.0.1:18666 -t "$W" "$HERE/app/router.php" > "$R/srv.log" 2>&1 & echo $! > "$R/pid"); sleep 1.5
U=http://127.0.0.1:18666
ok() { echo "PASS $1"; }
no() { echo "FAIL $1"; tail -5 "$R/srv.log" "$W/php-errors.log" 2>/dev/null; exit 1; }
X=$(curl -s -G -d pass=rushes "$U/db/rescue.php"); echo "$X" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d["total"] == 2 and d["with_good"] == 1 and d["rows"][0]["rel"] == "VIDEOS/Card/C1.MP4", d' \
  && ok "what was found is shown: broken, where from, its good copy, or none" || no "list"
J=$(python3 -c 'import json,sys; print(json.dumps([sys.argv[1]+"/VIDEOS/Card/C1.MP4", sys.argv[1]+"/VIDEOS/Card/C2.MP4", "/etc/passwd"]))' "$A")
curl -s -d pass=rushes -d action=rescue --data-urlencode "paths=$J" "$U/db/rescue.php" | grep -q '"n":1' \
  && [ "$(cat "$W/rescue-pick.tsv")" = "$A/VIDEOS/Card/C1.MP4	/Volumes/Old/Card/C1.MP4" ] && ls "$W"/queue/*rescue-apply.job > /dev/null 2>&1 \
  && ok "only a found file with a good copy is rescued, from the copy the scan found; the runner is asked" || no "rescue"
curl -s -d pass=rushes -d action=scan "$U/db/rescue.php" > /dev/null; ls "$W"/queue/*rescue-scan.job > /dev/null 2>&1 && ok "Look asks the runner" || no "scan"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$U/db/rescue.php")" = 403 ] && ok "only signed in" || no "auth"
case "$(curl -s -d pass=rushes -d action=ssh-save -d name=QNAP --data-urlencode "host=-oProxyCommand=x" -d path=/share/VIDEO "$U/db/rescue.php")" in *error*) ok "a server address ssh would misread is refused" ;; *) no "bad host" ;; esac
curl -s -d pass=rushes -d action=ssh-save -d name=QNAP -d host=admin@192.168.1.20 -d path=/share/VIDEO "$U/db/rescue.php" > /dev/null
python3 -c 'import json,sys; assert json.load(open(sys.argv[1]))["ssh_sources"] == [{"name": "QNAP", "host": "admin@192.168.1.20", "path": "/share/VIDEO"}]' "$W/settings.json" && ls "$W"/queue/*ssh-list.job > /dev/null 2>&1 \
  && ok "a server over SSH is saved (no password anywhere) and listed at once" || no "ssh save: $(cat "$W/settings.json")"

