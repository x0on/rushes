#!/bin/sh
# Duplicates, looked at before anything moves (db/dupgroups.php): one group per
# file that exists more than once, the copy kept and why, and "Keep this one"
# changing the plan Remove carries out. Recently Removed says what is in it
# (db/removed.php). Run: sh tests/test_dupgroups.sh (PHPBIN=… for another PHP)
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd)
PHP=${PHPBIN:-php}
R=$(mktemp -d); trap 'kill $(cat "$R/pid" 2>/dev/null) 2>/dev/null; rm -rf "$R"' EXIT
A="$R/Drive"; W="$R/web"
mkdir -p "$W" "$A/Library/Parks" "$A/Cards/c1" "$A/Cards/c2"
cp -r "$HERE/app/." "$W/"
printf '{"name":"Rushes","archive":{"local":"%s","web":"%s","runs_on":"mac"},"helper":{"mode":"built_in"},"organise":{"shelves":"Library","departments":[{"name":"Parks"}]}}\n' "$A" "$W" > "$W/settings.json"
for f in Library/Parks/a.mov Cards/c1/a.mov Cards/c2/a.mov Library/Parks/b.mov Cards/c1/b.mov; do printf x > "$A/$f"; done
printf '%s\t%s\t%s\n' 3000000000 "$A/Cards/c1/a.mov" "$A/Library/Parks/a.mov" 3000000000 "$A/Cards/c2/a.mov" "$A/Library/Parks/a.mov" \
  1000 "$A/Cards/c1/b.mov" "$A/Library/Parks/b.mov" > "$W/dedupe-plan.tsv"
printf '{"built":"2026-10-06T10:00:00"}' > "$W/dedupe-plan.tsv.meta"
mkdir -p "$A/Parks Promo" "$A/Library Opening"; printf y > "$A/Parks Promo/m.mov"; printf y > "$A/Library Opening/m.mov"
printf '%s\t%s\t%s\n' 500 "$A/Parks Promo/m.mov" "$A/Library Opening/m.mov" > "$W/dedupe-left.tsv"
printf '2048 3\n' > "$W/holding-kb.txt"; echo $(( $(date +%s) - 8 * 86400 )) > "$W/removed-at.txt"
(cd "$R" && "$PHP" -S 127.0.0.1:18680 -t "$W" "$HERE/app/router.php" > /dev/null 2>&1 & echo $! > "$R/pid"); sleep 1.5
U=http://127.0.0.1:18680
ok() { echo "PASS $1"; }
no() { echo "FAIL $1"; exit 1; }
[ "$(curl -s -o /dev/null -w '%{http_code}' "$U/db/dupgroups.php")" = 403 ] && ok "only for someone signed in" || no "unsigned"
curl -s -c "$R/cj" -d _pass=rushes "$U/db/admin.php" > /dev/null
curl -s -b "$R/cj" "$U/db/dupgroups.php" | python3 -c '
import json, sys
d = json.load(sys.stdin); g = d["groups"]
assert d["total"] == {"files": 3, "bytes": 6000001000, "groups": 2}, d["total"]
assert g[0]["keep"].endswith("Library/Parks/a.mov") and len(g[0]["moves"]) == 2, g[0]
assert g[0]["why"] == "It is on the shelf, where it belongs", g[0]["why"]
b = d["boxes"]
assert b["job"]["files"] == 3 and [j["name"] for j in b["job"]["jobs"]] == ["Cards"], b
assert b["folder"]["files"] == 0 and b["across"]["files"] == 1, b
assert g[0]["kind"] == "job", g[0]
' && ok "a group per file, the biggest first, the copy kept and why, three kinds (folder, job, across)" || no "groups"
curl -s -b "$R/cj" "$U/db/dupgroups.php?kind=across" | python3 -c '
import json, sys
d = json.load(sys.stdin); g = d["groups"]
assert len(g) == 1 and g[0]["kind"] == "across" and g[0]["why"].startswith("Left alone"), g
' && ok "copies in different jobs: shown, said to be left alone" || no "across"
curl -s -b "$R/cj" -d action=pick -d kind=folder "$U/db/dupgroups.php" | grep -q "Nothing of that kind" && ok "Remove for a kind with nothing in it: said, nothing written" || no "empty pick"
curl -s -b "$R/cj" -d action=pick -d kind=job -d folder=Cards "$U/db/dupgroups.php" > /dev/null
[ "$(sort "$W/dedupe-pick.txt" | tr '\n' ' ')" = "$A/Cards/c1/a.mov $A/Cards/c1/b.mov $A/Cards/c2/a.mov " ] && ok "Remove for one job: its copies written down for dedupe.sh" || no "pick: $(cat "$W/dedupe-pick.txt")"
rm -f "$W/dedupe-pick.txt"
curl -s -b "$R/cj" -d action=keep --data-urlencode "keep=$A/Library/Parks/a.mov" --data-urlencode "pick=$A/Cards/c2/a.mov" "$U/db/dupgroups.php" > /dev/null
grep -q "^3000000000	$A/Library/Parks/a.mov	$A/Cards/c2/a.mov$" "$W/dedupe-plan.tsv" \
  && grep -q "^3000000000	$A/Cards/c1/a.mov	$A/Cards/c2/a.mov$" "$W/dedupe-plan.tsv" \
  && grep -q "	$A/Library/Parks/b.mov$" "$W/dedupe-plan.tsv" && ok "Keep this one: that copy stays, every other goes, other groups untouched" || no "keep: $(cat "$W/dedupe-plan.tsv")"
curl -s -b "$R/cj" "$U/db/dupgroups.php" | python3 -c '
import json, sys
g = json.load(sys.stdin)["groups"][0]
assert g["keep"].endswith("Cards/c2/a.mov") and g["why"] == "Your choice", g
' && ok "said as your choice" || no "your choice"
case "$(curl -s -b "$R/cj" -d action=keep --data-urlencode "keep=$A/Library/Parks/b.mov" --data-urlencode "pick=/etc/passwd" "$U/db/dupgroups.php")" in
  *"not in this group"*) ok "only a copy in that group can be kept" ;; *) no "a stranger kept" ;; esac
curl -s -b "$R/cj" "$U/db/removed.php" | python3 -c '
import json, sys
p = json.load(sys.stdin)["places"][0]
assert p["bytes"] == 2048 * 1024 and p["files"] == 3 and p["ready"] is True, p
' && ok "Recently Removed: how much, and ready once the week is over" || no "removed"
