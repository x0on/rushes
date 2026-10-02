#!/bin/sh
# organize.sh — undo for the old date-based reorganisation.
#
#   sh organize.sh --undo       puts back every file the old --apply moved
#
# An early version of Rushes proposed sorting the whole archive by date
# (ARCHIVE/YYYY/YYYY-MM-DD_event) and could carry that out. That is no longer
# the plan: the archive follows the departments set in Reorganize, and the
# tidy-up (ingest.py --tidy) is what moves files now. The proposal and the
# move were removed (they also no longer worked with today's file list); the
# undo stays, so anything that version moved can still be put back from its
# log. The old code is in the project's history (git log -- app/organize.sh).

export WEB=${WEB:-/share/Web} ARCH=${ARCH:-/share/VIDEO}     # from runner.sh (Setup); otherwise the QNAP's
set -u
LOG=${LOG:-$WEB/organize-moves.tsv}
TAB=$(printf '\t')

[ "${1:-}" = "--undo" ] || { echo "only --undo is left: see the top of this file"; exit 1; }
[ -f "$LOG" ] || { echo "no log at $LOG — nothing was ever moved by the old layout"; exit 0; }
back=0
while IFS="$TAB" read -r src dst _rest; do
    case "$src" in *-MISSING|*-EXISTS|MV-FAILED) continue ;; esac
    [ -f "$dst" ] && [ ! -e "$src" ] || continue
    mkdir -p "$(dirname "$src")"
    mv -n "$dst" "$src" && back=$((back + 1))
done < "$LOG"
echo "restored $back files"
