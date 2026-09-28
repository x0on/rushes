#!/bin/sh
# dedupe.sh — move redundant copies of identical files into a holding folder,
# preserving their original structure so anything can be put back.
#
#   sh dedupe.sh                    dry run: builds the plan, moves nothing
#   sh dedupe.sh --apply            performs the moves, writes an undo log
#   sh dedupe.sh --undo             puts everything back from the undo log
#
# Settings (environment variables, so the admin page can set them):
#   KEEP_SIDE=project   project tree wins over card dumps   (default)
#   KEEP_SIDE=card      card dumps win over the project tree
#   KEEP_SIDE=short     no tree preference, shortest path wins
#   KEEP_SIDE=oldest    the earliest-written copy wins — usually the original
#   DEST=/share/VIDEO/_duplicates       where moved files go
#   RESULTS=/share/Web/results_duplicates.txt
#
# NEVER moved, whatever the rule says: numbered image-sequence frames
# (name_00007.png). A static matte legitimately has thousands of identical
# frames — they are duplicates by content and load-bearing by function, and
# removing one puts a hole in the sequence that only shows up at render time.
#
# Always loses, whatever KEEP_SIDE says:
#   ALL LUIS SOLER (desk folder), Copied_*, Media Cache, name.MXF.MXF
# Never touched: @Recycle — moving files OUT of it would undelete them.
#
# Safety: a file moves only if it still exists, its size matches the scan, and
# the copy being kept is present on disk right now.

set -u

RESULTS=${RESULTS:-/share/Web/results_duplicates.txt}
SHARE=/share/VIDEO
DEST=${DEST:-$SHARE/_duplicates}
PLAN=${PLAN:-/share/Web/dedupe-plan.tsv}
LOG=${LOG:-/share/Web/dedupe-moves.tsv}
MTIMES=${MTIMES:-/share/Web/dedupe-mtimes.txt}
KEEP_SIDE=${KEEP_SIDE:-project}
TAB=$(printf '\t')

MODE=${1:-dry}

# ---------- undo ----------
if [ "$MODE" = "--undo" ]; then
    [ -f "$LOG" ] || { echo "no log at $LOG — nothing to undo"; exit 1; }
    back=0
    while IFS="$TAB" read -r src dst; do
        case "$src" in *-SKIP|*-MISSING|*-EXISTS|*MISMATCH*|MV-FAILED) continue ;; esac
        [ -f "$dst" ] || continue
        mkdir -p "$(dirname "$src")"
        mv -n "$dst" "$src" && back=$((back + 1))
    done < "$LOG"
    echo "restored $back files"
    exit 0
fi

[ -f "$RESULTS" ] || { echo "results file not found: $RESULTS"; exit 1; }
echo "keep side: $KEEP_SIDE    destination: $DEST"

# ---------- KEEP_SIDE=oldest needs modification times ----------
# Only collected in this mode: it stats every duplicate on disk, which takes a
# few minutes across ~118,000 files. Every other mode decides from the path
# alone and skips this entirely.
if [ "$KEEP_SIDE" = "oldest" ]; then
    echo "reading modification times (this is the slow part, a few minutes)..."
    awk '/^"/ { p = $0; gsub(/"/, "", p); sub("^/storage/", "/share/VIDEO/", p); print p }' \
        "$RESULTS" | sort -u > "$MTIMES.paths"
    # xargs batches the stat calls: one process per few thousand files, not per file
    : > "$MTIMES"
    xargs -r -d '\n' -n 500 stat -c '%Y|%n' < "$MTIMES.paths" >> "$MTIMES" 2>/dev/null
    echo "  got times for $(wc -l < "$MTIMES") of $(wc -l < "$MTIMES.paths") files"
fi

# ---------- build the plan ----------
awk -v OFS="$TAB" -v keep_side="$KEEP_SIDE" -v mtimes="$MTIMES" '
BEGIN {
    if (keep_side == "oldest") {
        while ((getline line < mtimes) > 0) {
            i = index(line, "|")
            if (i) mt[substr(line, i + 1)] = substr(line, 1, i - 1) + 0
        }
        close(mtimes)
    }
}
# Penalties apply in every mode: these copies should never be the survivor.
function penalty(p,   s) {
    s = 0
    if (p ~ /\/@Recycle\//)                      s += 1000
    if (p ~ /\/ALL LUIS SOLER\//)                s += 500
    if (keep_side == "project" && p ~ /\/001 ALL CARD\//) s += 450
    if (keep_side == "card"    && p ~ /\/001 VIDEO\//)    s += 450
    if (p ~ /\/Copied_/)                         s += 400
    if (p ~ /\/Media Cache\//)                   s += 300
    if (p ~ /\.(MXF|MOV|MP4)\.(MXF|MOV|MP4)$/)   s += 200
    return s
}
function score(p) {
    # Penalties dominate; within an equal penalty the mode decides.
    if (keep_side == "oldest")
        return penalty(p) * 1000000000000 + (p in mt ? mt[p] : 2000000000)
    return penalty(p) + length(p) / 10000    # tie-break: shorter path wins
}
function flush(   i, best) {
    if (seq) { n = 0; seq = 0; skipped_seq++; return }
    if (n < 2) { n = 0; return }
    best = 1
    for (i = 2; i <= n; i++) if (sc[i] < sc[best]) best = i
    for (i = 1; i <= n; i++) {
        if (i == best) continue
        if (path[i] ~ /\/@Recycle\//) continue
        print bytes, path[i], path[best]
    }
    n = 0
}
/^---- Size/ { flush(); split($0, a, "[()]"); bytes = a[2] + 0; n = 0; seq = 0; next }
/^"/ {
    p = $0; gsub(/"/, "", p)
    sub("^/storage/", "/share/VIDEO/", p)
    # image-sequence frame: leave the whole group alone
    # busybox awk has no {n,} intervals, so the counts are spelled out.
    # A frame number is 4+ digits starting with 0 (0001, 00006) or 5+ digits;
    # that distinguishes it from a year, which is 4 digits starting 1 or 2.
    if (p ~ /[._-]0[0-9][0-9][0-9]*\.(png|PNG|jpg|JPG|jpeg|JPEG|tif|TIF|tiff|TIFF|tga|TGA|dpx|DPX|exr|EXR)$/ ||
        p ~ /[._-][0-9][0-9][0-9][0-9][0-9][0-9]*\.(png|PNG|jpg|JPG|jpeg|JPEG|tif|TIF|tiff|TIFF|tga|TGA|dpx|DPX|exr|EXR)$/) {
        seq = 1
    }
    n++; path[n] = p; sc[n] = score(p)
    next
}
END {
    flush()
    if (skipped_seq > 0)
        printf "  %d duplicate groups skipped: image-sequence frames\n", skipped_seq > "/dev/stderr"
}
' "$RESULTS" > "$PLAN"

printf '{"keep_side":"%s","dest":"%s","built":"%s"}\n' \
    "$KEEP_SIDE" "$DEST" "$(date '+%Y-%m-%dT%H:%M:%S')" > "$PLAN.meta"

echo "plan: $PLAN"
awk -F"$TAB" '{n++; b += $1} END {
    printf "  %d files to move, %.1f GB (freed only once the folder is deleted)\n", n, b / 1073741824
}' "$PLAN"

if [ "$MODE" != "--apply" ]; then
    echo
    echo "first 15 planned moves:"
    head -15 "$PLAN" | awk -F"$TAB" '{printf "  %7.1f GB  move %s\n            keep %s\n", $1/1073741824, $2, $3}'
    echo
    echo "nothing moved. re-run with --apply when the plan looks right."
    exit 0
fi

# ---------- apply ----------
: > "$LOG"
moved=0; skipped=0
total=$(wc -l < "$PLAN")
echo "moving $total files..." 
while IFS="$TAB" read -r bytes src keep; do
    if [ ! -f "$src" ]; then
        printf 'SRC-MISSING\t%s\n' "$src" >> "$LOG"; skipped=$((skipped + 1)); continue
    fi
    actual=$(stat -c %s "$src" 2>/dev/null || echo 0)
    if [ "$actual" != "$bytes" ]; then
        printf 'SIZE-MISMATCH\t%s\n' "$src" >> "$LOG"; skipped=$((skipped + 1)); continue
    fi
    if [ ! -f "$keep" ]; then
        printf 'KEEPER-MISSING\t%s\n' "$src" >> "$LOG"; skipped=$((skipped + 1)); continue
    fi
    rel=${src#"$SHARE"/}
    dst="$DEST/$rel"
    if [ -e "$dst" ]; then
        printf 'DEST-EXISTS\t%s\n' "$src" >> "$LOG"; skipped=$((skipped + 1)); continue
    fi
    mkdir -p "$(dirname "$dst")"
    # ponytail: mv within one volume is a rename — instant, no copy, no space needed
    if mv -n "$src" "$dst"; then
        printf '%s\t%s\n' "$src" "$dst" >> "$LOG"
        moved=$((moved + 1))
    else
        printf 'MV-FAILED\t%s\n' "$src" >> "$LOG"; skipped=$((skipped + 1))
    fi
    done_n=$((moved + skipped))
    # a heartbeat the page can parse into a real progress bar
    if [ $((done_n % 250)) -eq 0 ]; then
        echo "progress: $done_n of $total ($((done_n * 100 / total))%)"
    fi
done < "$PLAN"

echo "moved $moved files, skipped $skipped"
echo "log: $LOG   (undo with: sh $0 --undo)"
# ponytail: the runner re-reads the share after any job that moves files
# (refresh_state), so this script no longer rebuilds the index itself.
