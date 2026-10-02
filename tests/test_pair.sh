#!/bin/sh
# Pairing (HOW-IT-WORKS.md → Pairing): one code, one helper; any other is refused and named.
# Run: sh tests/test_pair.sh   (each question is its own PHP run, as on the web server)
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd)
PHP="node $HERE/../php-runtime/node_modules/@php-wasm/cli/php-wasm.js"
R=$(mktemp -d "${RUSHES_TEST_TMP:-/tmp}/pair-XXXXXX"); trap 'rm -rf "$R"' EXIT
mkdir -p "$R/web/db"; cp "$HERE"/app/db/*.php "$R/web/db/"
printf '{"archive":{"web":"%s","local":"%s/archive"},"helper":{"mode":"external"}}' "$R/web" "$R" > "$R/web/settings.json"
printf 'copy\t/x\n' > "$R/web/ingest-queue.tsv"
ok() { echo "PASS $1"; }
no() { echo "FAIL $1"; exit 1; }
# ask <file> <GET|POST> <helper id> <post as php array>  — prints what the page answered (and its status)
ask() {
    cat > "$R/ask.php" <<EOF
<?php
\$_SERVER['REQUEST_METHOD'] = '$2'; \$_SERVER['REMOTE_ADDR'] = '10.0.0.9';
\$_SERVER['HTTP_X_RUSHES_HELPER'] = '$3'; \$_POST = $4; \$_GET = ['queue' => ''];
register_shutdown_function(function () { echo ' ', http_response_code() ?: 200; });
include '$R/web/db/$1';
EOF
    $PHP "$R/ask.php" 2>&1 | tr '\n' ' '
}
code() { ask pair.php POST '' "['action' => 'start', 'pass' => 'rushes']" | sed 's/.*"code":"\([0-9]*\)".*/\1/'; }

case "$(ask helper.php GET '' '[]')" in "copy	/x "*" 200") ok "not paired yet: any helper is given work, as before" ;; *) no "unpaired queue" ;; esac
case "$(ask pair.php POST '' "['action' => 'start']")" in *"sign in"*403) ok "only someone signed in can make a code" ;; *) no "code without sign-in" ;; esac

c=$(code)
case "$(ask pair.php POST '' "['code' => '000000', 'host' => 'Mac']")" in *"not the code"*403) ok "a wrong code is refused" ;; *) no "wrong code" ;; esac
r=$(ask pair.php POST '' "['code' => '$c', 'host' => 'Edit Mac']")
id=$(echo "$r" | sed -n 's/.*"id":"\([0-9a-f]\{32\}\)".*/\1/p')
[ -n "$id" ] && ok "the right code pairs and gives the helper its ID" || no "pairing: $r"
case "$(ask pair.php POST '' "['code' => '$c', 'host' => 'Other']")" in *"No code is waiting"*403) ok "a code works once" ;; *) no "code used twice" ;; esac
[ -z "$($PHP "$R/web/helper-id.php" 2>&1)" ] && ok "the ID is kept in a file that prints nothing when fetched" || no "ID downloadable"

case "$(ask helper.php GET "$id" '[]')" in "copy	/x "*" 200") ok "the paired helper is given work" ;; *) no "paired helper refused" ;; esac
case "$(ask helper.php GET 'f00' '[]')" in *"not the paired helper"*403) ok "another helper is refused" ;; *) no "other helper given work" ;; esac
case "$(ask report.php POST '' "['host' => 'Second Mac', 'volumes' => '']")" in *403) ok "and its word is not taken" ;; *) no "other helper's report taken" ;; esac
case "$(ask helper.php POST 'f00' "['action' => 'pause']")" in *"paired Rushes Helper"*403) ok "another computer cannot pause the paired helper" ;; *) no "pause from anywhere" ;; esac
case "$(ask helper.php POST "$id" "['action' => 'pause']")" in *'"paused":true'*200) ok "the paired helper's own window can" ;; *) no "paired window cannot pause" ;; esac
grep -q "Second Mac" "$R/web/helper-refused.tsv" && ok "a refused helper is named for the page" || no "refused helper not named"

c=$(code); for i in 1 2 3 4; do ask pair.php POST '' "['code' => '1', 'host' => 'x']" >/dev/null; done
case "$(ask pair.php POST '' "['code' => '1', 'host' => 'x']")" in *"Five wrong"*403) ;; *) no "five wrong not said" ;; esac
case "$(ask pair.php POST '' "['code' => '$c', 'host' => 'x']")" in *"No code is waiting"*403) ok "five wrong tries cancel the code" ;; *) no "guessing not stopped" ;; esac
echo "pairing: all checks pass"
