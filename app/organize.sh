#!/bin/sh
# organize.sh — propose a date-based structure for the whole archive.
#
#   sh organize.sh              read-only: writes a proposal, touches nothing
#   sh organize.sh --apply      moves files into ARCHIVE/, writes an undo log
#   sh organize.sh --undo       puts everything back
#
# Target layout:   ARCHIVE/YYYY/YYYY-MM-DD_event-name/[CAM 1/]file.MXF
#
# Where the date comes from, best source first:
#   1. the filename        Canon A030C456_240318xx_CANON.MXF  -> 2024-03-18
#                          DJI_20260221180408_0085_D.MP4      -> 2026-02-21
#   2. a date in the path  .../2026-05-08_prayer-day/...      -> 2026-05-08
#   3. year + month words  .../2022/003_MAR/...               -> 2022-03-01 (day unknown)
#   4. year alone          .../2025/...                       -> 2025-00-00
#   5. nothing usable      -> _unsorted/, original path recorded, still searchable
#
# The event name is the deepest folder component that is not camera junk
# (CAM 1, CLIPS001, PRIVATE, XDROOT, Copied_*, Media Cache), not a bare date,
# and not a top-level bucket (001 VIDEO, 001 ALL CARD).
#
# Departments and clients deliberately do NOT become folders. They are tags in
# the database later: a file lives in one folder but can carry many tags, and
# needing a clip in two places is what created half of today's duplicates.

set -u

SHARE=/share/VIDEO
INDEX=${INDEX:-/share/Web/index.txt}
DEST_ROOT=${DEST_ROOT:-$SHARE/ARCHIVE}
PLAN=${PLAN:-/share/Web/organize-plan.tsv}
LOG=${LOG:-/share/Web/organize-moves.tsv}
TAB=$(printf '\t')
MODE=${1:-dry}
# ponytail: two knobs only. Stills/graphics in or out, and folders to leave alone.
INCLUDE_STILLS=${INCLUDE_STILLS:-0}
EXCLUDE=${EXCLUDE:-}

if [ "$MODE" = "--undo" ]; then
    [ -f "$LOG" ] || { echo "no log at $LOG"; exit 1; }
    back=0
    while IFS="$TAB" read -r src dst; do
        case "$src" in *-MISSING|*-EXISTS|MV-FAILED) continue ;; esac
        [ -f "$dst" ] || continue
        mkdir -p "$(dirname "$src")"
        mv -n "$dst" "$src" && back=$((back + 1))
    done < "$LOG"
    echo "restored $back files"
    exit 0
fi

[ -f "$INDEX" ] || { echo "no index at $INDEX — run reindex.sh first"; exit 1; }
echo "proposing structure under $DEST_ROOT"

awk -v OFS="$TAB" -v root="$DEST_ROOT" -v share="$SHARE" \
    -v stills="$INCLUDE_STILLS" -v excl="$EXCLUDE" '
function slug(s) {
    s = tolower(s)
    gsub(/[^a-z0-9]+/, "-", s)
    gsub(/^-+|-+$/, "", s)
    if (length(s) > 48) s = substr(s, 1, 48)
    gsub(/-+$/, "", s)
    return s
}
function is_junk(c) {
    return (c ~ /^(CAM|CAMERA)[ _-]?[0-9A-Z]*$/ ||
            c ~ /^(CLIPS?|PRIVATE|XDROOT|AVCHD|BDMV|DCIM|MEDIA|CONTENTS|CLPR|SUB)[0-9]*$/ ||
            c ~ /^Copied_/ || c ~ /^Media Cache/ || c ~ /^_/ ||
            c ~ /^(FOOTAGE|Footage|footage|RAW|raw|VIDEO|video|CLIPS001)$/ ||
            c ~ /^[0-9]{1,3}$/)
}
function is_bucket(c) {
    return (c ~ /^00[0-9] / || c == "ALL LUIS SOLER" || c == "@Recycle" ||
            c == "ARCHIVE" || c == "_duplicates")
}
function is_datey(c) {
    return (c ~ /^(19|20)[0-9][0-9]$/ || c ~ /^[0-9]{3}_[A-Z]{3}$/ ||
            c ~ /^(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)/ )
}
function mon(m) {
    return index("JANFEBMARAPRMAYJUNJULAUGSEPOCTNOVDEC", toupper(substr(m,1,3))) ?
           sprintf("%02d", (index("JANFEBMARAPRMAYJUNJULAUGSEPOCTNOVDEC", toupper(substr(m,1,3))) + 2) / 3) : ""
}
{
    rel = $0
    full = share "/" rel
    n = split(rel, c, "/")
    file = c[n]

    # skip our own working folders and the recycle bin
    if (rel ~ /^(ARCHIVE|_duplicates)\// || rel ~ /(^|\/)@Recycle\//) next
    # media only: everything else keeps its current home for now
    if (stills == "1") {
        if (file !~ /\.(mxf|MXF|mov|MOV|mp4|MP4|avi|AVI|mts|MTS|m4v|braw|BRAW|r3d|R3D|wav|WAV|aif|AIF|aiff|jpg|JPG|jpeg|JPEG|png|PNG|tif|TIFF|psd|PSD|ai|AI)$/) next
    } else if (file !~ /\.(mxf|MXF|mov|MOV|mp4|MP4|avi|AVI|mts|MTS|m4v|braw|BRAW|r3d|R3D|wav|WAV|aif|AIF|aiff)$/) next
    if (excl != "") {
        n_ex = split(excl, ex, ",")
        for (j = 1; j <= n_ex; j++) {
            gsub(/^[ \t]+|[ \t]+$/, "", ex[j])
            if (ex[j] != "" && index(rel, ex[j]) == 1) next
        }
    }

    date = ""; src = ""

    # ---- 1. date inside the filename ----
    if (match(file, /_[0-9]{6}[A-Z0-9]{0,2}_CANON/)) {
        d = substr(file, RSTART + 1, 6)
        yy = substr(d,1,2); mm = substr(d,3,2); dd = substr(d,5,2)
        if (mm+0 >= 1 && mm+0 <= 12 && dd+0 >= 1 && dd+0 <= 31) {
            date = "20" yy "-" mm "-" dd; src = "filename"
        }
    }
    if (date == "" && match(file, /(19|20)[0-9]{6}/)) {
        d = substr(file, RSTART, 8)
        mm = substr(d,5,2); dd = substr(d,7,2)
        if (mm+0 >= 1 && mm+0 <= 12 && dd+0 >= 1 && dd+0 <= 31) {
            date = substr(d,1,4) "-" mm "-" dd; src = "filename"
        }
    }

    # ---- 2. full date somewhere in the path ----
    if (date == "" && match(rel, /(19|20)[0-9][0-9][-_.][0-1][0-9][-_.][0-3][0-9]/)) {
        d = substr(rel, RSTART, RLENGTH)
        gsub(/[_.]/, "-", d)
        date = d; src = "path"
    }

    # ---- 3 & 4. year, and maybe a month word ----
    if (date == "") {
        yr = ""; mo = ""
        for (i = 1; i <= n; i++) {
            if (c[i] ~ /^(19|20)[0-9][0-9]$/) yr = c[i]
            else if (match(c[i], /(19|20)[0-9][0-9]/) && yr == "") yr = substr(c[i], RSTART, 4)
            if (c[i] ~ /^[0-9]{3}_[A-Z]{3}$/) mo = mon(substr(c[i], 5, 3))
            else if (mo == "" && c[i] ~ /^(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)/) mo = mon(c[i])
        }
        if (yr != "" && mo != "") { date = yr "-" mo "-01"; src = "year+month" }
        else if (yr != "")        { date = yr "-00-00";     src = "year" }
    }

    # ---- event name: deepest meaningful folder ----
    ev = ""
    for (i = n - 1; i >= 1; i--) {
        if (is_junk(c[i]) || is_bucket(c[i]) || is_datey(c[i])) continue
        ev = slug(c[i])
        if (ev != "") break
    }
    if (ev == "") ev = "misc"

    # ---- camera subfolder is worth keeping: it prevents name collisions ----
    cam = ""
    if (n >= 2 && (c[n-1] ~ /^(CAM|CAMERA)[ _-]?[0-9A-Z]*$/ || c[n-1] ~ /^CLIPS?[0-9]*$/)) cam = c[n-1] "/"

    # ---- assemble ----
    if (date == "") {
        dest = root "/_unsorted/" ev "/" cam file
        conf = "low"
    } else if (src == "year") {
        yr = substr(date, 1, 4)
        dest = root "/" yr "/" yr "_" ev "/" cam file
        conf = "medium"
    } else if (src == "year+month") {
        yr = substr(date, 1, 4)
        dest = root "/" yr "/" substr(date,1,7) "_" ev "/" cam file
        conf = "medium"
    } else {
        yr = substr(date, 1, 4)
        dest = root "/" yr "/" date "_" ev "/" cam file
        conf = "high"
    }

    print full, dest, conf, src
}
' "$INDEX" > "$PLAN"

printf '{"root":"%s","stills":"%s","exclude":"%s","built":"%s"}\n' \
    "$DEST_ROOT" "$INCLUDE_STILLS" "$EXCLUDE" "$(date '+%Y-%m-%dT%H:%M:%S')" > "$PLAN.meta"

echo "proposal: $PLAN"
awk -F"$TAB" '{n++; c[$3]++} END {
    printf "  %d media files\n", n
    printf "  high confidence (date from file or path): %d\n", c["high"] + 0
    printf "  medium (year or year+month only):         %d\n", c["medium"] + 0
    printf "  low (no date found, goes to _unsorted):   %d\n", c["low"] + 0
}' "$PLAN"

echo
echo "top proposed folders:"
awk -F"$TAB" '{ d = $2; sub(/\/[^\/]*$/, "", d); sub(/\/(CAM|CLIPS)[^\/]*$/, "", d); n[d]++ }
     END { for (k in n) printf "%8d  %s\n", n[k], k }' "$PLAN" | sort -rn | head -25

if [ "$MODE" != "--apply" ]; then
    echo
    echo "nothing moved. this is a proposal only."
    exit 0
fi

: > "$LOG"
moved=0; skipped=0
while IFS="$TAB" read -r src dst conf datesrc; do
    [ -f "$src" ] || { printf 'SRC-MISSING\t%s\n' "$src" >> "$LOG"; skipped=$((skipped+1)); continue; }
    if [ -e "$dst" ]; then
        # same name, different file: keep both by suffixing
        base=${dst%.*}; ext=${dst##*.}; i=2
        while [ -e "${base}_$i.$ext" ] && [ "$i" -lt 50 ]; do i=$((i+1)); done
        dst="${base}_$i.$ext"
    fi
    mkdir -p "$(dirname "$dst")"
    if mv -n "$src" "$dst"; then
        printf '%s\t%s\n' "$src" "$dst" >> "$LOG"; moved=$((moved+1))
    else
        printf 'MV-FAILED\t%s\n' "$src" >> "$LOG"; skipped=$((skipped+1))
    fi
done < "$PLAN"

echo "moved $moved files, skipped $skipped"
echo "log: $LOG   (undo with: sh $0 --undo)"
# ponytail: the runner re-reads the share after any job that moves files
# (refresh_state), so this script no longer rebuilds the index itself.
