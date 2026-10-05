#!/bin/sh
# Rushes on a Mac: router.php, the door of PHP's own web server. Run: sh tests/test_router.sh
# (PHPBIN=… for another PHP). Private files refused, other devices only when let in,
# only after the first password is changed, and only signed in.
set -u; [ -n "${DEBUG:-}" ] && set -x
HERE=$(cd "$(dirname "$0")/.." && pwd)
PHP=${PHPBIN:-php}
R=$(mktemp -d); trap 'kill $(cat "$R"/*.pid 2>/dev/null) 2>/dev/null; rm -rf "$R"' EXIT
mkdir -p "$R/web" "$R/Archive" "$R/sessions"
cp -r "$HERE/app/." "$R/web/"; rm -f "$R/web/router.php"
printf '{"name":"Rushes","archive":{"local":"%s","web":"%s"}}\n' "$R/Archive" "$R/web" > "$R/web/settings.json"
echo log > "$R/web/job.log"
ok() { echo "PASS $1"; }
no() { echo "FAIL $1"; exit 1; }
serve() {     # $1 address:port, $2 RUSHES_OTHERS
    (cd "$R" && RUSHES_OTHERS=$2 PHP_CLI_SERVER_WORKERS=2 "$PHP" -d session.save_path="$R/sessions" -d display_errors=0 \
        -S "$1" -t "$R/web" "$HERE/app/router.php" > /dev/null 2>&1 & echo $! > "$R/${1##*:}.pid")
}
code() { curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$@"; }
serve 127.0.0.1:18642 0; sleep 1.5
U=http://127.0.0.1:18642
[ "$(code $U/)" = 200 ] && [ "$(code $U/db/state.php)" = 200 ] && [ -s "$R/web/rushes.sqlite" ] && ok "this Mac opens the pages" || no "pages"
[ "$(code $U/rushes.sqlite)" = 403 ] && ok "the database is never handed out" || no "database"
for f in job.log .htaccess runner.sh ingest.py ingest-history.tsv 'x/../job.log' db/settings.json; do
    [ "$(code "$U/$f")" = 404 ] || no "$f was handed out"
done; ok "logs, lists, hidden files and code are never handed out"
echo "1	$R/Archive/a" > "$R/web/manifest.tsv"
[ "$(code $U/settings.json)" = 200 ] && [ "$(code $U/rules.json)" = 200 ] && [ "$(code $U/manifest.tsv)" = 200 ] \
    && ok "the three files the helper reads are there for it" || no "the helper's files"

# Other devices: this machine's own address that is not 127.0.0.1
IP=$(hostname -I 2>/dev/null | awk '{print $1}')
if [ -z "$IP" ]; then echo "SKIP other devices (no address but 127.0.0.1 here)"; exit 0; fi
serve 0.0.0.0:18643 0; serve 0.0.0.0:18644 1; sleep 1.5
case "$(curl -s --noproxy '*' http://$IP:18643/)" in *"for this Mac only"*) ok "other devices refused while not let in" ;; *) no "let in without the switch" ;; esac
case "$(curl -s --noproxy '*' http://$IP:18644/)" in *"first password"*) ok "refused while the password is the first one" ;; *) no "let in with the first password" ;; esac
(cd "$R/web" && "$PHP" -r 'require "db/auth.php"; set_pass("parks123");'); sleep 3     # opcache looks again after 2 s
[ "$(code http://$IP:18644/)" = 200 ] && case "$(curl -s --noproxy '*' http://$IP:18644/index.html)" in *_pass*) true ;; *) false ;; esac \
    && ok "other devices see the sign-in page first" || no "no sign-in page"
case "$(curl -s --noproxy '*' http://$IP:18644/db/pair.php)" in *'"pairing"'*) ok "an editor's computer can ask about pairing without signing in" ;; *) no "pair.php" ;; esac
[ "$(code http://$IP:18644/db/watcher.php)" = 403 ] && ok "but only a paired editor's computer gets past watcher.php" || no "watcher.php"
[ "$(code -c "$R/cj" -d _pass=wrong http://$IP:18644/)" = 401 ] && ok "a wrong password is refused" || no "wrong password"
[ "$(code -b "$R/cj" -c "$R/cj" -d _pass=parks123 http://$IP:18644/)" = 302 ] \
    && [ "$(code -b "$R/cj" http://$IP:18644/db/state.php)" = 200 ] && ok "signed in, other devices open Rushes" || no "sign in"
[ "$(code -b "$R/cj" http://$IP:18644/rushes.sqlite)" = 403 ] && ok "signed in or not, the database stays private" || no "database when signed in"
