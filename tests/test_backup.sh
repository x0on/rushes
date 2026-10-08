#!/bin/sh
# The backup (Manage → Copying, db/backup.php): only a drive the helper reported, never the
# archive's own; asked now or tonight's, handed to the helper once, gone once it has run.
# Run: sh tests/test_backup.sh (PHPBIN=… for another PHP)
set -u
export TZ=UTC      # the dates below, as PHP reads them
HERE=$(cd "$(dirname "$0")/.." && pwd)
PHP=${PHPBIN:-php}
R=$(mktemp -d); trap 'kill $(cat "$R/pid" 2>/dev/null) 2>/dev/null; rm -rf "$R"' EXIT
A="$R/Archive"; W="$R/web"
mkdir -p "$W" "$A"
cp -r "$HERE/app/." "$W/"
printf '{"name":"Rushes","archive":{"local":"%s","web":"%s","runs_on":"mac","label":"Archive"},"helper":{"mode":"built_in"}}\n' "$A" "$W" > "$W/settings.json"
printf 'at\t%s\nvol\t/Volumes/Spare\tSpare\t8000\t6000\t0\t0\t0\t0\nvol\t%s\tArchive\t8000\t100\t0\t1\t0\t0\n' "$(date +%s)" "$A" > "$W/helper-volumes.tsv"
(cd "$R" && "$PHP" -S 127.0.0.1:18681 -t "$W" "$HERE/app/router.php" > /dev/null 2>&1 & echo $! > "$R/pid"); sleep 1.5
U=http://127.0.0.1:18681
ok() { echo "PASS $1"; }
no() { echo "FAIL $1"; exit 1; }
[ "$(curl -s -o /dev/null -w '%{http_code}' "$U/db/backup.php")" = 403 ] && ok "only for someone signed in" || no "unsigned"
curl -s -c "$R/cj" -d _pass=rushes "$U/db/admin.php" > /dev/null
case "$(curl -s -b "$R/cj" -d action=save -d drive=/etc -d nightly=1 "$U/db/backup.php")" in
  *"helper can see"*) ok "a drive the helper did not report is refused" ;; *) no "typed path taken" ;; esac
case "$(curl -s -b "$R/cj" -d action=save --data-urlencode "drive=$A" -d nightly=1 "$U/db/backup.php")" in
  *"archive is on that drive"*) ok "the archive's own drive is refused" ;; *) no "onto itself" ;; esac
curl -s -b "$R/cj" -d action=save -d drive=/Volumes/Spare --data-urlencode "folder=../Back:up" -d nightly=1 "$U/db/backup.php" | grep -q '"ok":true' \
  && grep -q "\"folder\": \"Back up\"" "$W/settings.json" && ok "saved; the folder name loses what a folder name cannot hold" || no "save: $(cat "$W/settings.json")"
curl -s -b "$R/cj" "$U/db/backup.php" | python3 -c '
import json, sys
d = json.load(sys.stdin)
assert d["into"] == "/Volumes/Spare/Back up" and [x["name"] for x in d["drives"]] == ["Spare"], d
' && ok "the choice, and only drives that are not the archive" || no "get"
cd "$W"
due() { "$PHP" -r 'require "db/backup.php"; echo backup_due((int)$argv[1]);' "$1"; }
[ "$(due "$(date -d '2026-10-08 15:00' +%s)")" = "" ] && ok "in the day: nothing due" || no "day: $(due "$(date -d '2026-10-08 15:00' +%s)")"
[ "$(due "$(date -d '2026-10-08 23:00' +%s)")" = "night-2026-10-08" ] && [ "$(due "$(date -d '2026-10-09 03:00' +%s)")" = "night-2026-10-08" ] \
  && ok "at night: that night's, named for the evening it started" || no "night"
printf '2026-10-09 02:14\tbacked-up\tnight-2026-10-08 /Volumes/Spare/Back up\t3\t30\t5\t0 already there\n' >> "$W/ingest-history.tsv"
[ "$(due "$(date -d '2026-10-09 03:00' +%s)")" = "" ] && ok "done for that night: not again" || no "again"
cd "$R"
curl -s -b "$R/cj" -d action=now "$U/db/backup.php" > /dev/null
(cd "$W" && "$PHP" -r 'require "db/backup.php"; echo backup_line();') | grep -q "^backup	$A	/Volumes/Spare/Back up	now-" \
  && ok "Back up now: handed to the helper as a line of its queue" || no "line"
case "$(curl -s -b "$R/cj" -d action=off "$U/db/backup.php")" in *'"ok":true'*) ;; *) no "off" ;; esac
(cd "$W" && "$PHP" -r 'require "db/backup.php"; echo "[" . backup_line() . "]";') | grep -q '^\[\]$' && ok "turned off: nothing more is asked" || no "still asked"
