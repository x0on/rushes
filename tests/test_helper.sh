#!/bin/sh
# The helper's buttons and its install script, on a fixture — never the real archive.
#   PHPBIN=php sh tests/test_helper.sh      (any PHP 8 command line)
set -e; [ -n "${DEBUG:-}" ] && set -x
PHPBIN=${PHPBIN:-php}
HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=${RUSHES_TEST_TMP:-/tmp}/rushes-helper-$$
rm -rf "$ROOT"; trap 'rm -rf "$ROOT"' EXIT     # a fixture left by an earlier run is never reused
mkdir -p "$ROOT/app/db" "$ROOT/archive/_rushes/scripts"
cp "$HERE"/../app/*.php "$HERE"/../app/*.json "$ROOT/app/" 2>/dev/null || true
cp "$HERE"/../app/db/*.php "$ROOT/app/db/"
cp "$HERE/../app/ingest.py" "$ROOT/archive/_rushes/ingest.py"
printf '#!/bin/sh\necho new\n' > "$ROOT/archive/_rushes/scripts/runner.sh"
printf '#!/bin/sh\necho old\n' > "$ROOT/app/runner.sh"
cat > "$ROOT/app/settings.json" <<J
{"name":"Rushes","archive":{"web":"$ROOT/app","local":"$ROOT/archive","as_seen_from_helper":"/Volumes/VIDEO","url":"http://nas.test"},
 "helper":{"mode":"external","label":"workstation"},"organise":{"departments":[],"shelves":"Library"}}
J
call() { $PHPBIN "$HERE/helper_call.php" "$ROOT/app" "$@" 2>&1; }
check() { if eval "$1"; then echo "PASS $2"; else echo "FAIL $2"; exit 1; fi; }

out=$(call GET '{"install":""}')
check 'echo "$out" | grep -q "URL='"'"'http://nas.test'"'"'" && echo "$out" | grep -q "helper.php?app" && echo "$out" | grep -q "open \"\$APP\""' \
      'install script carries this archive, fetches the app and opens it'
check 'call GET "{\"install\":\"\"}" | grep -q "^CERT=[0-9a-f]\{64\}$" && call GET "{\"install\":\"\"}" | grep -q "extract-certificates"' \
      'and opens it only if it is signed with the Rushes author'"'"'s certificate'
check 'call GET "{\"app\":\"\"}" | grep -q "not on the archive yet"' 'no app on the archive: says so'
check 'call GET "{\"app\":\"watcher\"}" | grep -q "Rushes Watcher for Mac is not on the archive yet"' 'and Rushes Watcher, for editors'"'"' computers, the same way'
printf 'PK-fake' > "$ROOT/archive/_rushes/Rushes Helper.zip"
check 'call GET "{\"app\":\"\"}" | grep -q "PK-fake"' 'the app is served from the archive'
check 'call GET "{\"remove\":\"\"}" | grep -q "launchctl bootout"' 'remove script takes it off again'
check 'call GET "{\"where\":\"\"}" | grep -q "\"url\":\"http://nas.test\""' 'helpers can ask where Rushes is'
want=$(sha256sum "$ROOT/archive/_rushes/ingest.py" | cut -d" " -f1)
printf 'helperfile\tingest.py\t%s\n' "$want" > "$ROOT/app/waiting.tsv"     # as the runner lists it
check 'call GET "{\"hash\":\"\"}" | grep -q "$want"' 'the helper can compare itself with the archive copy (from the runner list, not VIDEO)'
check 'call POST "{}" "{\"action\":\"scripts\"}" | grep -q "sign in"' 'installing needs the admin password or a session (Pause and the like do not: the helper window presses them)'
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
check 'call POST "{}" "{\"action\":\"check-updates\"}" | grep -q "sign in" && [ ! -e "$ROOT/app/survey-now" ]' 'Check for updates needs sign-in'
call POST '{}' '{"action":"check-updates","pass":"rushes"}' >/dev/null
check '[ -e "$ROOT/app/survey-now" ]' 'Check for updates asks the runner to look'
# Ingest: the server checks the date again, whatever the page let through
printf 'at\t%s\nvol\t/Volumes/CARD\tCARD\t1\t1\t1\t0\t1\t1\n' "$(date +%s)" > "$ROOT/app/helper-volumes.tsv"
S2=$(sed 's/"departments":\[\]/"departments":[{"name":"News","folder":"NEWS"}]/' "$ROOT/app/settings.json"); echo "$S2" > "$ROOT/app/settings.json"
check 'call POST "{}" "{\"ingest_src\":\"/Volumes/CARD\",\"dept\":\"News\",\"date\":\"1999-05-01\",\"event\":\"x\"}" queue.php | grep -q "cannot be right"' \
      'Ingest: a date before 2005 is refused by the server too'
check 'call POST "{}" "{\"ingest_src\":\"/Volumes/CARD\",\"dept\":\"News\",\"date\":\"2099-05-01\",\"event\":\"x\"}" queue.php | grep -q "cannot be right"' \
      'Ingest: a date in the future is refused by the server too'
check 'call POST "{}" "{\"ingest_src\":\"/Volumes/CARD\",\"dept\":\"News\",\"date\":\"2024-05-01\",\"event\":\"x\"}" queue.php | grep -q "\"queued\"\|ok\|into"' \
      'Ingest: a real date goes through'
# Manage's job buttons (run.php): a job file, and for duplicates the rules it follows
check 'call POST "{}" "{\"action\":\"plan\",\"pass\":\"rushes\",\"keep_side\":\"card\"}" run.php | grep -q "\"queued\":\"plan\"" && grep -q "KEEP_SIDE=card" "$ROOT"/app/queue/*.job && grep -q "contains	/@Recycle/" "$ROOT/app/dedupe-rules.tsv"' \
      'a job button writes its job, and duplicates get their rules'
check 'call POST "{}" "{\"action\":\"plan\"}" run.php | grep -q "not signed in"' 'job buttons need the password or a session'
check 'call POST "{}" "{\"action\":\"plan\",\"pass\":\"rushes\",\"dest\":\"/etc\"}" run.php >/dev/null; grep -h "^DEST=" "$ROOT"/app/queue/*.job | grep -qv "^DEST=/etc"' \
      'a holding folder outside the archive is replaced by the archive'"'"'s own'

# taking everything with you: whole lists, behind the password, in open formats
check 'call GET "{\"what\":\"files\"}" "{}" db/export.php | grep -q "Sign in"' 'the export needs signing in'
check 'SIGNED=1 call GET "{\"what\":\"files\"}" "{}" db/export.php | head -1 | grep -q "path,name,kind,bytes"' 'the catalogue comes out as CSV'
check 'SIGNED=1 call GET "{\"what\":\"pulls\"}" "{}" db/export.php | python3 -c "import json,sys; d=json.load(sys.stdin); assert \"pulls\" in d"' 'pulls come out as JSON'
check 'SIGNED=1 call GET "{\"what\":\"moments\"}" "{}" db/export.php | head -1 | grep -q "path,kind,shot,start_s"' 'what describing found comes out as CSV'

# Search plays a file's proxy, in pieces; nothing but a known file's proxy can be asked for
mkdir -p "$ROOT/archive/PROXIES/Library/K"; printf '0123456789' > "$ROOT/archive/PROXIES/Library/K/a.mp4"; echo secret > "$ROOT/archive/x.txt"
call SQL "INSERT INTO files (path,name) VALUES ('$ROOT/archive/Library/K/a.MXF','a.MXF'), ('$ROOT/archive/x.txt','x.txt');
          INSERT INTO media (file_id, proxy_at) SELECT id, 1 FROM files" >/dev/null
check '[ "$(call GET "{\"p\":\"$ROOT/archive/Library/K/a.MXF\"}" "{}" db/play.php)" = 0123456789 ]' 'a file plays from its proxy'
check '[ "$(RANGE=bytes=3-5 call GET "{\"p\":\"$ROOT/archive/Library/K/a.MXF\"}" "{}" db/play.php)" = 345 ]' 'in pieces, as the player asks (a jump reads from there)'
check 'call GET "{\"p\":\"$ROOT/archive/x.txt\"}" "{}" db/play.php | grep -q "not on the archive" && ! call GET "{\"p\":\"$ROOT/archive/x.txt\"}" "{}" db/play.php | grep -q secret' \
      'only a proxy can be played, never another file'
check 'call GET "{\"p\":\"/etc/passwd\"}" "{}" db/play.php | grep -q "No proxy"' 'a path the catalogue does not know plays nothing'

# editors' computers: a Watcher pairs with its own code and can only deliver
code=$(SIGNED=1 call POST "{}" "{\"action\":\"start\",\"role\":\"watcher\"}" db/pair.php | sed 's/.*"code":"\([0-9]*\)".*/\1/')
check 'call POST "{}" "{\"code\":\"$code\",\"host\":\"Maria Mac\"}" db/pair.php | grep -q "enter it in Rushes Watcher"' 'a Watcher'"'"'s code given to a helper does not make it the helper'
wid=$(call POST "{}" "{\"code\":\"$code\",\"host\":\"Maria Mac\",\"role\":\"watcher\"}" db/pair.php | sed 's/.*"id":"\([0-9a-f]*\)".*/\1/')
check '[ ${#wid} = 32 ] && ! grep -q "$wid" "$ROOT/app/watchers.php"' 'an editor'"'"'s computer pairs as a Watcher; Rushes keeps only the fingerprint of its ID'
check 'WATCHER=$wid call GET "{\"hello\":\"\"}" "{}" db/watcher.php | grep -q "\"shelf\":\"Library\""' 'a Watcher asks where things are'
check 'WATCHER=nope call GET "{\"hello\":\"\"}" "{}" db/watcher.php | grep -q "not a paired Watcher"' 'an unpaired one is refused'
check 'WATCHER=$wid call GET "{\"queue\":\"\"}" "{}" db/helper.php | grep -q "not the paired helper" || [ ! -s "$ROOT/app/helper-id.php" ]' 'a Watcher is not the helper'
key=$(WATCHER=$wid call GET "{\"hello\":\"\"}" "{}" db/watcher.php | sed 's/.*"key":"\([0-9a-f]*\)".*/\1/')
WATCHER=$wid call POST "{}" "{\"action\":\"delivered\",\"batch\":\"b1\"}" db/watcher.php >/dev/null
check 'grep -qx "deliver	$key/b1" "$ROOT/app/ingest-queue.tsv"' 'a delivery is queued for the helper, in that Watcher'"'"'s own folder'
check 'WATCHER=$wid call POST "{}" "{\"action\":\"delivered\",\"batch\":\"../x\"}" db/watcher.php | grep -q "not a batch"' 'and nowhere else'
WATCHER=$wid call POST "{}" "{\"action\":\"project\",\"path\":\"PARKS/2026/20260929 Kite/Kite.prproj\",\"saved\":\"100\",\"files\":\"12\",\"outside\":\"2\"}" db/watcher.php >/dev/null
check 'WATCHER=$wid call GET "{\"where\":\"\",\"project\":\"PARKS/2026/20260929 Kite/Kite.prproj\"}" "{}" db/watcher.php | grep -q "\"files\":\[\]\|\"files\":{}"' 'a project is known; nothing delivered for it yet'
mkdir -p "$ROOT/archive/Stock Library/Music"; printf 'lala' > "$ROOT/archive/Stock Library/Music/song.wav"
check 'call POST "{}" "{\"batch\":\"$key/b1\",\"files\":\"/Users/m/song.wav\tStock Library/Music/song.wav\tsha256:ab\tmusic\tPARKS/2026/20260929 Kite/Kite.prproj\t4\n/x\t../../etc/passwd\tx\tmusic\tP\t1\"}" db/delivered.php | grep -q "\"recorded\":1,\"refused\":\[\"../../etc/passwd\"\]"' \
      'the helper says where a delivered file is now; only a file really inside the archive is taken'
check 'WATCHER=$wid call GET "{\"where\":\"\",\"project\":\"PARKS/2026/20260929 Kite/Kite.prproj\"}" "{}" db/watcher.php | grep -q "\"/Users/m/song.wav\":{\"rel\":\"Stock Library/Music/song.wav\""' \
      'and the Watcher learns it, to point the project there'
k16=$(php_key=$(grep -o "'[0-9a-f]\{64\}'" "$ROOT/app/watchers.php" | head -1 | tr -d "'"); echo "$php_key" | cut -c1-16)
SIGNED=1 call POST "{}" "{\"action\":\"forget\",\"key\":\"$k16\"}" db/pair.php >/dev/null
check 'WATCHER=$wid call GET "{\"hello\":\"\"}" "{}" db/watcher.php | grep -q "not a paired Watcher"' 'a removed computer can no longer deliver'

# another website cannot make a browser press Rushes' buttons; Rushes' own pages can
check 'ORIGIN=http://evil.example call POST "{}" "{\"action\":\"pause\",\"pass\":\"rushes\"}" | grep -q "another website"' \
      'a button pressed from another website is refused, even with the password'
check 'ORIGIN=http://nas.test call POST "{}" "{\"action\":\"pause\",\"pass\":\"rushes\"}" | grep -q "\"paused\"\|ok\|true"' \
      'the same button from Rushes itself works'
echo "Helper tests complete."
