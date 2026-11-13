#!/bin/sh
# Every check Rushes has, in one go: what GitHub runs on every push (.github/workflows/tests.yml) and what the
# build runs before it makes an app (mac/build.py). It ends with a count, and exits 1 if anything failed.
#   sh tests/all.sh            needs: python3, php (with sqlite3), ffmpeg, node; busybox for the NAS runner test
cd "$(dirname "$0")" || exit 1
export PHPBIN="${PHPBIN:-php}"
SH=sh; command -v busybox >/dev/null 2>&1 && SH="busybox sh"      # the NAS runs the scripts in busybox
bad=""; n=0
run() { n=$((n + 1)); printf '%-34s' "$1"; shift
        if out=$("$@" 2>&1); then echo ok; else echo FAILED; echo "$out" | tail -25 | sed 's/^/    /'; bad="$bad $n"; fi; }
for t in test_*.py; do run "$t" python3 -m unittest -q "${t%.py}"; done
for t in test_*.php; do run "$t" php "$t"; done
for t in test_*.js; do run "$t" node "$t"; done
for t in test_*.sh; do
  case "$t" in test_runner.sh) run "$t" $SH "$t" ;; *) run "$t" bash "$t" ;; esac
done
run "analyze.py --selftest" python3 ../app/analyze.py --selftest
run "release.py --selftest" python3 ../app/release.py --selftest
run "helper code is signed" python3 ../app/release.py verify ../app
if [ -n "$bad" ]; then echo; echo "FAILED: $(echo $bad | wc -w) of $n"; exit 1; fi
echo; echo "all $n checks pass"
