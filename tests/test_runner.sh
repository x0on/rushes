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
case "\$u" in *.sqlite) echo 200 ;; *builtin) [ -e "$R/builtin" ] && echo yes || echo no ;; *part=video) [ -e "$R/hang" ] && sleep 30; echo '{"state":"current"}' ;; *) echo '{"state":"current"}' ;; esac
EOF
chmod +x "$R/bin/curl"
run() { PATH="$R/bin:$PATH" VLIMIT=2 IMPORT_LIMIT=2 busybox sh "$R/runner.sh" >/dev/null 2>&1; }
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
[ "$(tail -1 "$R/asked")" = "http://127.0.0.1/db/import.php?part=web" ] && grep -q "/db/import.php?part=video$" "$R/asked" \
  && ok "search update: the VIDEO half only when VIDEO may be read" || no "search update read VIDEO while paused"
rm -f "$W/helper-control.json"

# HOW-IT-WORKS.md → Rushes' own backups: the day's database copy goes onto VIDEO, one per weekday, once
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
S=$R/share/VIDEO; mkdir -p "$S/proj" "$S/@Recycle" "$S/_duplicates/_media-cache" "$S/c"
echo c > "$S/proj/a.pek"; echo c > "$S/@Recycle/b.pek"; echo c > "$S/_duplicates/_media-cache/c.pek"
printf '%s\n' "$S/proj/a.pek" "$S/@Recycle/b.pek" "$S/_duplicates/_media-cache/c.pek" > "$W/cache-files.txt"
printf 'ACTION=cacheclean\n' > "$W/queue/c1.job"; run
[ -f "$S/_duplicates/_media-cache/proj/a.pek" ] && [ -f "$S/@Recycle/b.pek" ] && [ ! -e "$S/_duplicates/_media-cache/_duplicates" ] \
  && ok "cache clean-up never takes from the recycle bin or the holding folder" || no "cache clean-up"
printf 'ACTION=cache-undo\n' > "$W/queue/c2.job"; run
[ -f "$S/proj/a.pek" ] && [ ! -e "$S/_duplicates/_media-cache/proj/a.pek" ] && ok "cache clean-up can be undone" || no "cache undo"
echo same > "$S/proj/clip.mov"; echo same > "$S/c/clip.mov"
printf -- '---- Size 5 B (5 bytes) - 2 files\n"/storage/proj/clip.mov"\n"/storage/c/clip.mov"\n' > "$W/results_duplicates.txt"
touch -d '1 minute ago' "$W/results_duplicates.txt" 2>/dev/null || touch -t 200001010000 "$W/results_duplicates.txt"
printf 'ACTION=plan\n' > "$W/queue/d0.job"; run
grep -q "no rules at" "$W/job.log" && [ ! -s "$W/dedupe-plan.tsv" ] && ok "duplicates: no plan without the rules Rushes writes" || no "planned without rules"
printf '1000\tcontains\t/@Recycle/\n450\tcard\t/c/\n' > "$W/dedupe-rules.tsv"; touch -d '2 minutes ago' "$W/dedupe-rules.tsv" 2>/dev/null
printf 'ACTION=plan\n' > "$W/queue/d1.job"; run
grep -q "/c/clip.mov	.*/proj/clip.mov" "$W/dedupe-plan.tsv" && ok "duplicates: a card-dump folder from the rules loses to the project copy, though its path is shorter" || no "card rule: $(cat "$W/dedupe-plan.tsv")"
printf 'ACTION=apply\n' > "$W/queue/d2.job"; run
grep -q "moving the plan from the last dry run" "$W/job.log" && [ -f "$S/_duplicates/c/clip.mov" ] && [ -f "$S/proj/clip.mov" ] \
  && ok "duplicates: apply moves the plan that was shown" || no "duplicates apply"
printf 'ACTION=verify\n' > "$W/queue/d3.job"; run
grep -q "^VERDICT	SAFE" "$W/verify-result.tsv" && ok "the whole holding folder is checked: safe to empty" || no "verify folder mode: $(head -3 "$W/verify-result.tsv" | tr '\n' ' ')"
printf 'ACTION=undo\n' > "$W/queue/d4.job"; run
[ -f "$S/c/clip.mov" ] && ok "duplicates can be put back" || no "duplicates undo"

# a folder name that climbs out of VIDEO is refused before anything runs
printf 'ACTION=proxy-plan\nQUERY=../Web\n' > "$W/queue/p1.job"; run
grep -q "refused a folder with .. in it" "$W/job.log" && ! grep -q "unknown action: refused" "$W/job.log" && ok "a folder with .. in it is refused" || no ".. not refused"

# a job lock left by a run that was killed does not block jobs for ever
mkdir -p "$R/tmp/.archive-runner.lock"; echo 999999 > "$R/tmp/.archive-runner.lock/pid"
printf 'ACTION=df\n' > "$W/queue/l1.job"; run
[ ! -e "$W/queue/l1.job" ] && grep -q "took over the job lock" "$W/job.log" && ok "a stale job lock is taken over" || no "stale job lock blocked jobs"

# a long job (here: one still holding the job lock) does not hold up the search update
sleep 30 & busy=$!
mkdir -p "$R/tmp/.archive-runner.lock"; echo $busy > "$R/tmp/.archive-runner.lock/pid"
printf 'ACTION=df\n' > "$W/queue/l2.job"; : > "$R/asked"; run
[ -e "$W/queue/l2.job" ] && grep -q "import.php?part=web" "$R/asked" && ok "a job still running: the search update happens anyway, the next job waits" || no "upkeep waited for the job lock"
kill $busy 2>/dev/null; rm -rf "$R/tmp/.archive-runner.lock" "$W/queue/l2.job"

# the search update's look at VIDEO hangs: walked away from and counted, like any touch of VIDEO
touch "$R/hang"; t0=$(date +%s); run; rm -f "$R/hang"
[ $(( $(date +%s) - t0 )) -lt 15 ] && grep -q "VIDEO did not answer" "$W/job.log" && ok "a stuck look at VIDEO by the search update is walked away from" || no "search update held the runner"
rm -f "$W/video-stalls.txt"

# a disk that stops answering: walked away from, counted, and after three the breaker trips
rm -f "$V/ingest.py"; mkfifo "$V/ingest.py"
for i in 1 2 3; do touch "$W/survey-now"; t0=$(date +%s); run; [ $(( $(date +%s) - t0 )) -lt 10 ] || no "a stuck read held the runner"; done
ok "a stuck read never holds the runner past its time limit"
[ -f "$W/video-tripped.txt" ] && ok "three in a row: the breaker trips" || no "breaker did not trip"
n=$(grep -c "did not answer" "$W/job.log"); touch "$W/survey-now"; run
[ "$(grep -c "did not answer" "$W/job.log")" = "$n" ] && ok "tripped: VIDEO is not tried again by itself" || no "tried VIDEO while tripped"
[ "$(tail -1 "$R/asked")" = "http://127.0.0.1/db/import.php?part=web" ] && ok "tripped: the search update leaves VIDEO alone" || no "search update while tripped"
printf 'ACTION=reset-breaker\n' > "$W/queue/3.job"; run
[ ! -e "$W/video-tripped.txt" ] && ok "Try again resets it" || no "reset"

# logs that only grow are trimmed; each folder keeps only its last proxy run
head -c 6000000 /dev/zero | tr '\0' 'x' | fold -w 99 > "$W/helper.log"; echo "the newest line" >> "$W/helper.log"
awk 'BEGIN { for (i = 0; i < 90000; i++) printf "f%d\tdone\t1\t0\t0\t1\t%d\n", i % 3, i }' > "$W/proxy-folders.tsv"
run
[ "$(wc -c < "$W/helper.log")" -le 1000000 ] && tail -1 "$W/helper.log" | grep -q "the newest line" && ok "a log past 5 MB keeps its newest 1 MB" || no "helper.log not trimmed"
[ "$(wc -l < "$W/proxy-folders.tsv")" = 3 ] && grep -q "	89999$" "$W/proxy-folders.tsv" && ok "the proxy runs list keeps each folder's last run" || no "proxy-folders: $(wc -l < "$W/proxy-folders.tsv")"

# where the archive is comes from Setup (archive-path.txt), checked: a system
# folder, or one that does not exist, falls back to the QNAP's
rm -f "$V/ingest.py"; echo 'print(1)' > "$V/ingest.py"           # a plain file again (the stalls above made it a pipe)
A2=$R/share/OTHER; mkdir -p "$A2/_rushes"; echo 'print(2)' > "$A2/_rushes/ingest.py"
echo "$A2" > "$W/archive-path.txt"; touch "$W/survey-now"; run
grep -q "^helperfile	ingest.py	$(sha256sum "$A2/_rushes/ingest.py" | cut -d' ' -f1)$" "$W/waiting.tsv" \
  && ok "the archive's place comes from Setup: the runner looks there" || no "archive-path.txt not followed"
mkdir -p "$R/share/.hidden/_rushes" "$W/sub"; echo 'print(3)' > "$R/share/.hidden/_rushes/ingest.py"
for bad in /etc "$A2/../x" "/nowhere/at/all" "$A2 x" "$R/share/.hidden" "$W" "$W/sub" /tmp; do
    echo "$bad" > "$W/archive-path.txt"; touch "$W/survey-now"; run
    grep -q "^helperfile	ingest.py	$(sha256sum "$V/ingest.py" | cut -d' ' -f1)$" "$W/waiting.tsv" || no "a bad archive path was followed: $bad"
done
ok "a system folder, .., a hidden or missing folder, the web folder, a space: the QNAP's place instead"
rm -rf "$A2" "$W/archive-path.txt"

# a new file list less than half the last one is a share that answered partly: the last list stays
awk 'BEGIN { for (i = 0; i < 3000; i++) printf "1\t/share/VIDEO/f%d\n", i }' > "$W/manifest.tsv"
printf 'ACTION=manifest\n' > "$W/queue/m1.job"; run
[ "$(wc -l < "$W/manifest.tsv")" = 3000 ] && [ -f "$W/manifest-rejected.tsv" ] && grep -q "kept the last list" "$W/job.log" \
  && ok "a file list less than half the last one is refused, and said" || no "manifest guard: $(wc -l < "$W/manifest.tsv")"
rm -f "$W/manifest.tsv" "$W/manifest-rejected.tsv"

# the built-in helper runs with full rights: only a signed release, from a checked copy
if command -v python3 >/dev/null 2>&1; then
    touch "$R/builtin"; rm -f "$V/ingest.py"
    for n in ingest.py transfer_state.py analyze.py; do echo 'import time; time.sleep(3)' > "$V/$n"; done
    PUBT=$(python3 -c "import sys; sys.path.insert(0, '$HERE/app'); import release; print(release.public_key(bytes(32)).hex())")
    sed "s/^PUBLIC = .*/PUBLIC = \"$PUBT\"/" "$HERE/app/release.py" > "$V/release.py"; cp "$V/release.py" "$W/release.py"
    printf '%s' "$(printf '0%.0s' $(seq 1 64))" > "$R/key"; python3 "$V/release.py" sign "$V" "$R/key" >/dev/null
    rm -f "$W/helper.pid"; run
    [ "$(cat "$W/helper-builtin.txt")" = started ] && [ -f "$W/helper-code/ingest.py" ] && ok "a signed release: the built-in helper starts, from the checked copy" || no "signed built-in: $(cat "$W/helper-builtin.txt"); $(tail -3 "$W/job.log")"
    sleep 4; echo 'import os; os.system("touch /tmp/pwned")' > "$V/ingest.py"; rm -f "$W/helper.pid"; run
    [ "$(cat "$W/helper-builtin.txt")" = unsigned ] && grep -q "not a signed release" "$W/job.log" && ok "code changed after signing: not started, and said" || no "unsigned code was started"
    rm -f "$R/builtin" "$W/helper.pid"; rm -rf "$W/helper-code"
fi

# STOP: nothing at all, not even the heartbeat
rm -f "$W/runner-alive.txt"; touch "$W/STOP"; run
[ ! -e "$W/runner-alive.txt" ] && ok "STOP: the runner does nothing" || no "ran despite STOP"
echo "runner: all checks pass"
