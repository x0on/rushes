#!/bin/sh
# The helper's buttons and its install script, on a fixture — never the real archive.
#   PHPBIN=php sh tests/test_helper.sh      (any PHP 8 command line)
set -e
PHPBIN=${PHPBIN:-php}
HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=${RUSHES_TEST_TMP:-/tmp}/rushes-helper-$$
mkdir -p "$ROOT/app/db" "$ROOT/archive/_rushes/scripts"
cp "$HERE"/../app/*.php "$HERE"/../app/*.json "$ROOT/app/" 2>/dev/null || true
cp "$HERE"/../app/db/*.php "$ROOT/app/db/"
cp "$HERE/../app/ingest.py" "$ROOT/archive/_rushes/ingest.py"
printf '#!/bin/sh\necho new\n' > "$ROOT/archive/_rushes/scripts/runner.sh"
printf '#!/bin/sh\necho old\n' > "$ROOT/app/runner.sh"
cat > "$ROOT/app/settings.json" <<J
{"name":"Rushes","archive":{"web":"$ROOT/app","local":"$ROOT/archive","as_seen_from_helper":"/Volumes/VIDEO","url":"http://nas.test"},
 "helper":{"mode":"external","label":"workstation"},"organise":{"departments":[]}}
J
call() { $PHPBIN "$HERE/helper_call.php" "$ROOT/app" "$@" 2>&1; }
check() { if eval "$1"; then echo "PASS $2"; else echo "FAIL $2"; exit 1; fi; }

out=$(call GET '{"install":""}')
check 'echo "$out" | grep -q "URL='"'"'http://nas.test'"'"'" && echo "$out" | grep -q "launchctl bootstrap" && echo "$out" | grep -q -- "--service"' \
      'install script carries this archive and starts a background service'
check 'call GET "{\"remove\":\"\"}" | grep -q "launchctl bootout"' 'remove script takes it off again'
want=$(sha256sum "$ROOT/archive/_rushes/ingest.py" | cut -d" " -f1)
check 'call GET "{\"hash\":\"\"}" | grep -q "$want"' 'the helper can compare itself with the archive copy'
check 'call POST "{}" "{\"action\":\"pause\"}" | grep -q "sign in"' 'buttons need the admin password or a session'
call POST '{}' '{"action":"pause","pass":"rushes"}' >/dev/null
check 'call GET "{\"control\":\"\"}" | grep -q "\"paused\":true"' 'Pause is saved for the helper to read'
call POST '{}' '{"action":"resume","pass":"rushes"}' >/dev/null
call POST '{}' '{"action":"nudge","pass":"rushes"}' >/dev/null
check 'call GET "{\"control\":\"\"}" | grep -q "\"paused\":false,\"nudge\":[1-9]"' 'Resume and "Try again now" are saved'
check 'call POST "{}" "{\"action\":\"skip\",\"path\":\"/nope\",\"pass\":\"rushes\"}" | grep -q "not waiting"' 'Skip refuses a folder that is not in the transfer'
call SEED '["/src/A","/src/B"]' >/dev/null
call POST '{}' '{"action":"skip","path":"/src/A","pass":"rushes"}' >/dev/null
check '[ "$(cat "$ROOT/app/ingest-queue.tsv")" = "$(printf "copy\t/src/B")" ]' 'Skip takes the folder out of the queue'
check 'call ITEMS | grep -q "\"/src/A\":\"removed\"" && ! call ITEMS | grep -q "\"/src/B\":\"removed\""' 'and out of the transfer, leaving the rest'
check 'call GET "{\"control\":\"\"}" | grep -q "\"skip\":\[\"/src/A\"\]"' 'a copy already running on it is told to stop'
echo "Helper tests complete."
