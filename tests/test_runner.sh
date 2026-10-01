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
for a; do u=\$a; done; echo "\$u" >> "$R/asked"; echo '{"state":"current"}'
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
[ ! -e "$W/db/x.php" ] && ok "a page dropped in deploy waits for approval" || no "page went live by itself"

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
