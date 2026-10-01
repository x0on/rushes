#!/bin/sh
# The runner, on a pretend archive, on its bad days. Run: sh tests/test_runner.sh
# (busybox sh, as on the NAS). Nothing outside a temporary folder is touched.
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd)
R=$(mktemp -d); trap 'kill $(jobs -p) 2>/dev/null; [ -n "${KEEP:-}" ] && echo "$R" || rm -rf "$R"' EXIT
mkdir -p "$R/share/Web/queue" "$R/share/VIDEO/_rushes/scripts" "$R/share/VIDEO/_rushes/deploy/db" "$R/tmp"
sed -e "s#/share/#$R/share/#g" -e "s#/tmp/\.archive#$R/tmp/.archive#g" "$HERE/app/runner.sh" > "$R/runner.sh"
W=$R/share/Web; V=$R/share/VIDEO/_rushes
# a pretend curl: notes which address the runner asked, answers "current"
mkdir -p "$R/bin"; cat > "$R/bin/curl" <<EOF
#!/bin/sh
for a; do u=\$a; done; echo "\$u" >> "$R/asked"
case "\$u" in *.sqlite) echo 200 ;; *) echo '{"state":"current"}' ;; esac
EOF
chmod +x "$R/bin/curl"
run() { PATH="$R/bin:$PATH" VLIMIT=2 busybox sh "$R/runner.sh" >/dev/null 2>&1; }
ok() { echo "PASS $1"; }
no() { echo "FAIL $1"; exit 1; }

# what is waiting is seen; a dropped page is NOT put live by itself
echo 'echo new' > "$V/scripts/proxy.sh"; echo '<?php echo 1;' > "$V/deploy/db/x.php"; echo 'print(1)' > "$V/ingest.py"
run
grep -q "^script	proxy.sh	" "$W/waiting.tsv" && grep -q "^page	db/x.php	" "$W/waiting.tsv" && grep -q "^helper	" "$W/waiting.tsv" \
  && ok "what is waiting is listed for the page, with fingerprints" || no "waiting list"
grep -q "^helperfile	ingest.py	$(sha256sum "$V/ingest.py" | cut -d' ' -f1)$" "$W/waiting.tsv" && ok "the helper's fingerprints are listed for its updates" || no "helper fingerprints"
[ ! -e "$W/db/x.php" ] && ok "a page dropped in deploy waits for approval" || no "page went live by itself"

# new versions are looked for only when asked: a list already there is left alone
echo 'echo newer' > "$V/scripts/proxy.sh"; before=$(cat "$W/waiting.tsv"); run
[ "$(cat "$W/waiting.tsv")" = "$before" ] && ok "no looking for updates on a timer" || no "looked without being asked"
touch "$W/survey-now"; run
[ "$(cat "$W/waiting.tsv")" != "$before" ] && [ ! -e "$W/survey-now" ] && ok "Check for updates: looked at the next minute" || no "check for updates"
echo 'echo new' > "$V/scripts/proxy.sh"; touch "$W/survey-now"; run

# installed exactly as approved; a page changed after approval is refused
h=$(sha256sum "$V/deploy/db/x.php" | cut -d' ' -f1)
printf 'ACTION=update-scripts\nPAGE=db/x.php:%s\n' "$h" > "$W/queue/1.job"; run
[ -f "$W/db/x.php" ] && [ ! -e "$V/deploy/db/x.php" ] && ok "an approved page is installed and leaves the drop folder" || no "approved page"
echo '<?php evil();' > "$V/deploy/db/y.php"
printf 'ACTION=update-scripts\nPAGE=db/y.php:%s\n' "$h" > "$W/queue/2.job"; run
[ ! -e "$W/db/y.php" ] && grep -q "refused page db/y.php" "$W/job.log" && ok "a page that is not what was approved is refused" || no "wrong page installed"

# paused: VIDEO is left alone (the list is not looked at again)
rm -f "$W/waiting.tsv"; echo '{"paused":true}' > "$W/helper-control.json"; run
[ ! -e "$W/waiting.tsv" ] && ok "paused: nothing reads VIDEO" || no "read VIDEO while paused"
[ "$(tail -1 "$R/asked")" = "http://127.0.0.1/db/import.php?video=0" ] && grep -q "/db/import.php$" "$R/asked" \
  && ok "search update: VIDEO parts only when VIDEO may be read" || no "search update read VIDEO while paused"
rm -f "$W/helper-control.json"

# RISKS.md #7: the day's database copy goes onto VIDEO, one per weekday, once
echo db > "$W/db-copy.sqlite"; run
[ "$(cat "$V/db-copies/rushes-$(date +%a).sqlite")" = db ] && [ -f "$W/db-copied" ] && ok "the database copy goes onto VIDEO, by weekday" || no "database copy"
echo again > "$V/db-copies/rushes-$(date +%a).sqlite"; run
[ "$(cat "$V/db-copies/rushes-$(date +%a).sqlite")" = again ] && ok "and only once per new copy" || no "database copied again"

# a database path that is shell code is never run (it is not even used)
rm -f "$W/db-copied"; mkdir -p "$R/x'; touch $R/pwned; '"; echo db > "$R/x'; touch $R/pwned; '/db-copy.sqlite"
echo "$R/x'; touch $R/pwned; '/db-copy.sqlite" > "$W/db-copy.path"; run
[ ! -e "$R/pwned" ] && ok "a crafted database path is never run as a command" || no "shell injection through db-copy.path"
rm -f "$W/db-copy.path"

# the daily self-check: a private file the web server hands out is named
echo x > "$W/rushes.sqlite"; rm -f "$W/exposed.txt"; run
grep -qx rushes.sqlite "$W/exposed.txt" && ok "a database the web server hands out is named for Overview" || no "self-check"
n=$(grep -c "rushes.sqlite" "$R/asked"); run
[ "$(grep -c "rushes.sqlite" "$R/asked")" = "$n" ] && ok "and asked once a day, not every minute" || no "self-check every minute"

# the holding-folder tools: cache clean-up, duplicates, and the "safe to delete?" check
for x in dedupe verify; do sed -e "s#/share/#$R/share/#g" "$HERE/app/$x.sh" > "$W/$x.sh"; done
S=$R/share/VIDEO; mkdir -p "$S/proj" "$S/@Recycle" "$S/_duplicates/_media-cache" "$S/cards"
echo c > "$S/proj/a.pek"; echo c > "$S/@Recycle/b.pek"; echo c > "$S/_duplicates/_media-cache/c.pek"
printf '%s\n' "$S/proj/a.pek" "$S/@Recycle/b.pek" "$S/_duplicates/_media-cache/c.pek" > "$W/cache-files.txt"
printf 'ACTION=cacheclean\n' > "$W/queue/c1.job"; run
[ -f "$S/_duplicates/_media-cache/proj/a.pek" ] && [ -f "$S/@Recycle/b.pek" ] && [ ! -e "$S/_duplicates/_media-cache/_duplicates" ] \
  && ok "cache clean-up never takes from the recycle bin or the holding folder" || no "cache clean-up"
printf 'ACTION=cache-undo\n' > "$W/queue/c2.job"; run
[ -f "$S/proj/a.pek" ] && [ ! -e "$S/_duplicates/_media-cache/proj/a.pek" ] && ok "cache clean-up can be undone" || no "cache undo"
echo same > "$S/proj/clip.mov"; echo same > "$S/cards/clip.mov"
printf -- '---- Size 5 B (5 bytes) - 2 files\n"/storage/proj/clip.mov"\n"/storage/cards/clip.mov"\n' > "$W/results_duplicates.txt"
touch -d '1 minute ago' "$W/results_duplicates.txt" 2>/dev/null || touch -t 200001010000 "$W/results_duplicates.txt"
printf 'ACTION=plan\n' > "$W/queue/d1.job"; run
printf 'ACTION=apply\n' > "$W/queue/d2.job"; run
grep -q "moving the plan from the last dry run" "$W/job.log" && [ -f "$S/_duplicates/cards/clip.mov" ] && [ -f "$S/proj/clip.mov" ] \
  && ok "duplicates: apply moves the plan that was shown" || no "duplicates apply"
printf 'ACTION=verify\n' > "$W/queue/d3.job"; run
grep -q "^VERDICT	SAFE" "$W/verify-result.tsv" && ok "the whole holding folder is checked: safe to empty" || no "verify folder mode: $(head -3 "$W/verify-result.tsv" | tr '\n' ' ')"
printf 'ACTION=undo\n' > "$W/queue/d4.job"; run
[ -f "$S/cards/clip.mov" ] && ok "duplicates can be put back" || no "duplicates undo"

# a folder name that climbs out of VIDEO is refused before anything runs
printf 'ACTION=proxy-plan\nQUERY=../Web\n' > "$W/queue/p1.job"; run
grep -q "refused a folder with .. in it" "$W/job.log" && ! grep -q "unknown action: refused" "$W/job.log" && ok "a folder with .. in it is refused" || no ".. not refused"

# a disk that stops answering: walked away from, counted, and after three the breaker trips
rm -f "$V/ingest.py"; mkfifo "$V/ingest.py"
for i in 1 2 3; do touch "$W/survey-now"; t0=$(date +%s); run; [ $(( $(date +%s) - t0 )) -lt 10 ] || no "a stuck read held the runner"; done
ok "a stuck read never holds the runner past its time limit"
[ -f "$W/video-tripped.txt" ] && ok "three in a row: the breaker trips" || no "breaker did not trip"
n=$(grep -c "did not answer" "$W/job.log"); touch "$W/survey-now"; run
[ "$(grep -c "did not answer" "$W/job.log")" = "$n" ] && ok "tripped: VIDEO is not tried again by itself" || no "tried VIDEO while tripped"
[ "$(tail -1 "$R/asked")" = "http://127.0.0.1/db/import.php?video=0" ] && ok "tripped: the search update leaves VIDEO alone" || no "search update while tripped"
printf 'ACTION=reset-breaker\n' > "$W/queue/3.job"; run
[ ! -e "$W/video-tripped.txt" ] && ok "Try again resets it" || no "reset"

# STOP: nothing at all, not even the heartbeat
rm -f "$W/runner-alive.txt"; touch "$W/STOP"; run
[ ! -e "$W/runner-alive.txt" ] && ok "STOP: the runner does nothing" || no "ran despite STOP"
echo "runner: all checks pass"
