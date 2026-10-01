#!/bin/sh
# verify.sh — answers one question: is this safe to delete?
#
#   sh verify.sh                     the whole holding folder
#   sh verify.sh <file or folder>    just that one
#
# A thing is safe to delete when, for every file in it, the copy we kept is
# still on disk at exactly the size it was measured at. Filenames are ignored
# entirely — cameras reuse them, so the archive has dozens of unrelated clips
# called DJI_0002.MOV. Only bytes count.
#
# Writes a verdict the page can render. The log is a byproduct, not the answer.

set -u

PLAN=${PLAN:-/share/Web/dedupe-plan.tsv}      # bytes, moved-from, kept
MOVES=${MOVES:-/share/Web/dedupe-moves.tsv}   # moved-from, moved-to
OUT=${OUT:-/share/Web/verify-result.tsv}      # what the page reads
HOLD=${HOLD:-/share/VIDEO/_duplicates}
TAB=$(printf '\t')
QUERY=${1:-}

say() { printf '%s\n' "$*"; }
out() { printf '%s\n' "$*" >> "$OUT"; }

: > "$OUT"
out "QUERY$TAB${QUERY:-the whole holding folder}"

if [ ! -f "$PLAN" ] || [ ! -f "$MOVES" ]; then
    out "VERDICT${TAB}UNKNOWN"
    out "SUMMARY${TAB}No record of the cleanup was found, so nothing can be confirmed."
    say "no plan or move log"; exit 1
fi

# ---- decide what we are checking ------------------------------------------
# A real folder on disk: check everything inside it, including anything the
# cleanup did not put there. A name or partial path: check the matching moves.
# Scratch files on the archive disk, not /tmp: on a QNAP /tmp is a 64 MB RAM
# disk shared with the system, and these lists alone can fill it. Removed when
# this finishes, however it finishes.
T=/share/Web/.verify-scratch
mkdir -p "$T"
trap 'rm -rf "$T"' EXIT INT TERM
LIST=$T/verify.list
: > "$LIST"
: > $T/verify.ignored
MODE=match
IGNORED=0

# The NAS and the Macs litter every folder with their own bookkeeping:
# QNAP thumbnail caches (.@__thumb, and the .error notes it leaves when it
# cannot make a thumbnail), Synology/QNAP index folders, macOS .DS_Store.
# None of it is footage, none of it is linked from anywhere, and all of it is
# recreated on demand. Counting it as "unaccounted for" made a clean folder
# look dangerous, which is worse than useless.
# Each rule is named, so an ignored file can always be traced to the rule that
# ignored it. A filter you cannot see is a filter you cannot trust.
scan_folder() {
    find "$1" -type f > $T/verify.all 2>/dev/null
    # the two output files are passed in (-v): inside the single-quoted program
    # a "$T/…" would be the literal text, not the scratch folder
    awk -v L="$T/verify.list" -v I="$T/verify.ignored" '
    {
        r = ""
        if (/\.(pek|PEK|cfa|CFA|ims|IMS)$/ || index($0, "/_media-cache/"))
                                           r = "Premiere cache (rebuilds itself)"
        else if (index($0, "/.@__thumb/")) r = "QNAP thumbnail cache"
        else if (index($0, "/@Recycle/"))  r = "network recycle bin"
        else if (index($0, "/@eaDir/"))    r = "NAS index folder"
        else if (index($0, "/.@upload_cache/")) r = "NAS upload cache"
        else if (/\/\.DS_Store$/)          r = "macOS folder settings"
        else if (/\/Thumbs\.db$/)          r = "Windows thumbnail cache"
        else if (/\/\._[^\/]*$/)           r = "macOS resource fork"
        if (r == "") print $0 > L
        else         print r "\t" $0 > I
    }' $T/verify.all
    touch $T/verify.list $T/verify.ignored
    IGNORED=$(wc -l < $T/verify.ignored)
}

if [ -d "$QUERY" ]; then
    MODE=folder; scan_folder "$QUERY"
elif [ -z "$QUERY" ] && [ -d "$HOLD" ]; then
    MODE=folder; scan_folder "$HOLD"
fi

# ---- the lookup tables, built once ----------------------------------------
# dst -> src   (where each moved file came from)
# src -> kept  (which copy was kept in its place)
# The moves log carries the kept copy and the size on each line (columns 3 and
# 4) since moves stopped being forgotten at every apply; older lines without
# them fall back to the plan.
awk -F"$TAB" -v mode="$MODE" -v q="$QUERY" '
NR == FNR { keep[$2] = $3; bytes[$2] = $1; next }
{
    src = $1; dst = $2
    if (src ~ /^#/ || src ~ /^(SRC-MISSING|SIZE-MISMATCH|KEEPER-MISSING|DEST-EXISTS|MV-FAILED)$/) next
    if (mode == "match" && q != "" && !index(src, q) && !index(dst, q)) next
    print dst "\t" src "\t" ($3 != "" ? $3 : keep[src]) "\t" ($4 != "" ? $4 : bytes[src])
}' "$PLAN" "$MOVES" > $T/verify.map

if [ "$MODE" = folder ]; then
    # every file physically in the folder, joined to its record
    awk -F"$TAB" '
    NR == FNR { src[$1] = $2; keep[$1] = $3; bytes[$1] = $4; next }
    { print $0 "\t" (($0 in src) ? src[$0] "\t" keep[$0] "\t" bytes[$0] : "\t\t") }
    ' $T/verify.map "$LIST" > $T/verify.work
else
    cut -f1 $T/verify.map > $T/verify.names
    paste $T/verify.names $T/verify.map | cut -f1,3,4,5 > $T/verify.work
fi

if [ ! -s $T/verify.work ]; then
    out "VERDICT${TAB}NOTHING"
    out "SUMMARY${TAB}Nothing here came from the duplicate cleanup, so there is no record to check against."
    say "no matching files"; exit 0
fi

# ---- the actual check -----------------------------------------------------
ok=0; bad=0; n=0
: > $T/verify.rows

while IFS="$TAB" read -r dst src keeper bytes; do
    n=$((n + 1))
    if [ -z "$keeper" ]; then
        printf 'ROW%sno%s%s%s%s%snot part of any job — no record of where it came from\n' \
            "$TAB" "$TAB" "$dst" "$TAB" "" "$TAB" >> $T/verify.rows
        bad=$((bad + 1)); continue
    fi
    if [ ! -f "$keeper" ]; then
        printf 'ROW%sno%s%s%s%s%sthe copy we kept is gone\n' \
            "$TAB" "$TAB" "$dst" "$TAB" "$keeper" "$TAB" >> $T/verify.rows
        bad=$((bad + 1)); continue
    fi
    ksize=$(stat -c %s "$keeper" 2>/dev/null || echo 0)
    if [ "$ksize" != "$bytes" ]; then
        printf 'ROW%sno%s%s%s%s%sthe copy we kept changed size\n' \
            "$TAB" "$TAB" "$dst" "$TAB" "$keeper" "$TAB" >> $T/verify.rows
        bad=$((bad + 1)); continue
    fi
    printf 'ROW%syes%s%s%s%s%s%s bytes\n' \
        "$TAB" "$TAB" "$dst" "$TAB" "$keeper" "$TAB" "$bytes" >> $T/verify.rows
    ok=$((ok + 1))
done < $T/verify.work

# ---- the verdict ----------------------------------------------------------
if [ "$bad" -eq 0 ]; then
    out "VERDICT${TAB}SAFE"
    if [ "$n" -eq 1 ]; then
        out "SUMMARY${TAB}Safe to delete. The original is still on the server, untouched."
    else
        out "SUMMARY${TAB}Safe to delete. All $n files here are copies, and all $n originals are still on the server."
    fi
else
    out "VERDICT${TAB}UNSAFE"
    out "SUMMARY${TAB}Do not delete. $bad of $n files have no surviving original."
fi
out "COUNTS${TAB}$n${TAB}$ok${TAB}$bad${TAB}$IGNORED"
cut -f1 $T/verify.ignored | sort | uniq -c | sort -rn | while read -r c r; do
    out "SKIP${TAB}$c${TAB}$r"
done
cat $T/verify.rows >> "$OUT"

# the log gets the short version; the page gets the detail
say ""
say "$(grep '^SUMMARY' "$OUT" | cut -f2)"
say "  checked $n, confirmed $ok, problems $bad"
[ "$bad" -eq 0 ] || grep '^ROW' "$OUT" | grep -v "${TAB}yes${TAB}" | head -10 | cut -f3,5
exit 0
