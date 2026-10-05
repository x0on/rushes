#!/bin/sh
# Activity: what happened and who did it (db/activity.php). The copier's record
# said in plain sentences, a pull made and downloaded with the name this browser
# was given, a switch turned, and names only for someone signed in.
# Run: sh tests/test_activity.sh (PHPBIN=… for another PHP)
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd)
PHP=${PHPBIN:-php}
R=$(mktemp -d); trap 'kill $(cat "$R/pid" 2>/dev/null) 2>/dev/null; rm -rf "$R"' EXIT
A="$R/Drive"; W="$R/web"
mkdir -p "$W" "$A/_rushes" "$A/Parks"
cp -r "$HERE/app/." "$W/"
printf '{"name":"Rushes","archive":{"local":"%s","web":"%s"},"helper":{"mode":"built_in"}}\n' "$A" "$W" > "$W/settings.json"
printf 'clip' > "$A/Parks/a.mov"
printf '4\t%s\n' "$A/Parks/a.mov" > "$W/manifest.tsv"; echo "$A/Parks/a.mov" > "$W/index.txt"
cat > "$W/ingest-history.tsv" <<'EOF'
2026-10-05 09:00	copied	/Volumes/CARD A014	12	64000000000	600
2026-10-05 09:30	copied	/Volumes/EMPTY	0	0	2
2026-10-05 10:00	checked	/Volumes/Drive/Parks	300	1000	60	2 differ from their fingerprint (a.mov …)
2026-10-05 11:00	uploaded	phone/x1	3	3000000	9	by Sam into Parks/2026
EOF
(cd "$R" && "$PHP" -S 127.0.0.1:18670 -t "$W" "$HERE/app/router.php" > /dev/null 2>&1 & echo $! > "$R/pid"); sleep 1.5
U=http://127.0.0.1:18670
ok() { echo "PASS $1"; }
no() { echo "FAIL $1"; exit 1; }
curl -s "$U/db/import.php?part=web&force=1" > /dev/null

[ "$(curl -s -o /dev/null -w '%{http_code}' "$U/db/activity.php")" = 403 ] && ok "names are not handed to anyone who is not signed in" || no "unsigned read"
curl -s -c "$R/cj" -d _pass=rushes "$U/db/admin.php" > /dev/null
# head.php: the name is said first, then kept in the browser
curl -s -b "$R/cj" -d action=hello -d "name=Ana M" "$U/db/activity.php" > /dev/null
printf '127.0.0.1\tFALSE\t/\tFALSE\t0\trushes_who\tAna%%20M\n' >> "$R/cj"     # the name head.php keeps in this browser
S=$(curl -s -b "$R/cj" -d action=create -d "name=Council promo" "$U/db/pulls.php")
slug=$(echo "$S" | python3 -c 'import json,sys; print(json.load(sys.stdin)["slug"])') || no "pull: $S"
curl -s -b "$R/cj" -d action=add -d "p=$slug" --data-urlencode "path=$A/Parks/a.mov" "$U/db/pulls.php" > /dev/null
curl -s -b "$R/cj" "$U/db/pull-export.php?p=$slug&fmt=list&base=/Volumes/VIDEO" > /dev/null
curl -s -b "$R/cj" -d action=pause "$U/db/helper.php" > /dev/null
curl -s -b "$R/cj" "$U/db/activity.php" | python3 -c '
import json, sys
e = json.load(sys.stdin)["events"]; t = [(x["kind"], x["who"], x["text"]) for x in e]
say = lambda k, w, s: any(a == k and b == w and s in c for a, b, c in t) or sys.exit(f"missing {k} {w} {s}: {t}")
say("people", "Ana M", "Started using Rushes on a")
say("out", "Ana M", "Started the pull “Council promo”")
say("out", "Ana M", "Downloaded the pull “Council promo” as a list of where its clips are: 1 clip")
say("changed", "Ana M", "Paused copying")
say("in", "", "Copied 12 files (64.0 GB) from CARD A014 into the archive")
say("problem", "", "Checked 300 files in Parks against their fingerprints · 2 differ")
say("in", "Sam", "Uploaded 3 files (3 MB) into Parks/2026")
assert not any("EMPTY" in c for _, _, c in t), "a card with nothing new is not an event"
assert [x["at"] for x in e] == sorted((x["at"] for x in e), reverse=True), "newest first"
' && ok "the copier, a person and a switch: each said plainly, with who, newest first" || no "activity"
curl -s -b "$R/cj" "$U/db/state.php" | python3 -c 'import json,sys; r = json.load(sys.stdin)["recent"]; assert r and r[0]["who"] == "Ana M", r[:2]' \
  && ok "Manage tells the same story" || no "state.php recent"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$U/activity.tsv")" = 403 ] && ok "the record itself is never handed out" || no "activity.tsv served"
