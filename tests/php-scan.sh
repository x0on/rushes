#!/bin/sh
# Scans the PHP for security problems that a change adds (Semgrep, its PHP and security-audit rules).
#   sh tests/php-scan.sh [the commit to compare with]     default: where this branch left main
# It leaves with 1 when the change adds a flag, so the check shows red. Runs the same here as on GitHub.
base="$1"
case "$base" in ""|0000000000000000000000000000000000000000) base="" ;; esac
[ -n "$base" ] && ! git cat-file -e "$base^{commit}" 2>/dev/null && base=""      # a commit that is gone (a rewritten branch)
[ -z "$base" ] && base=$(git merge-base HEAD origin/main)
echo "php-scan: comparing $(git rev-parse --short HEAD) with $(git rev-parse --short "$base")"
semgrep scan --config p/php --config p/security-audit --include '*.php' --metrics off --error --baseline-commit "$base" .
code=$?
[ "$code" = 0 ] && echo "php-scan: this change adds no security flag in the PHP" || echo "php-scan: look at the flags above (a safe one can be marked: // nosemgrep: <rule>, with the reason)"
exit $code
