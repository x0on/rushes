#!/bin/sh
# proxy.sh — build H.264 proxies so the browser can actually play the archive.
#
#   sh proxy.sh              plan: what is missing, how big it will be
#   sh proxy.sh --build      encode the missing ones (resumable, skips existing)
#
# Proxies mirror the archive's paths under PROXIES/, so nothing is ever
# ambiguous about which file a proxy belongs to, and the whole tree can be
# excluded from backups and rebuilt at will. Proxies are derived data.
#
# Why on the NAS and not the Mac: transcoding is I/O before it is CPU. The
# files are already here, and this box has QuickSync (/dev/dri/renderD128),
# so H.264 encoding happens in hardware at a fraction of real time.
#
# These same files serve three purposes:
#   1. the web player (a browser cannot play MXF at all)
#   2. the analysis pass, which reads frames far more cheaply from a proxy
#   3. Premiere proxies, so editors cut 1080p instead of pulling 4K over the wire

set -u

SHARE=${SHARE:-/share/VIDEO}
PROXY_ROOT=${PROXY_ROOT:-$SHARE/PROXIES}
INDEX=${INDEX:-/share/Web/index.txt}
PLAN=${PLAN:-/share/Web/proxy-plan.tsv}
LOG=${LOG:-/share/Web/proxy-built.tsv}
HEIGHT=${HEIGHT:-1080}
BITRATE=${BITRATE:-2M}
TAB=$(printf '\t')
MODE=${1:-plan}
ONLY=${PROXY_ONLY:-}     # one folder of the archive (e.g. "PARK COLLECTION"), or empty for all of it
ONLY=${ONLY%/}
STATE=${STATE:-/share/Web/proxy-status.txt}
RUNS=${RUNS:-/share/Web/proxy-folders.tsv}   # one line per finished or stopped run, per folder: what Rushes reads to know a folder is ready
MADE=${MADE:-/share/Web/proxy-made.tsv}      # never emptied: every proxy made, with what the original is (Rushes keeps it in its media ledger)
RECENT=${RECENT:-7200}   # seconds: a file written this recently may still be arriving; left for the next run

# What the page shows: one small file, rewritten whole, so it is never half-read.
state() {
    { printf 'at\t%s\n' "$(date +%s)"; for kv in "$@"; do printf '%s\n' "$kv"; done; } > "$STATE.new"
    mv -f "$STATE.new" "$STATE"
}

# ---------- find ffmpeg ----------
# QTS hides it; Container Station may be the only place it exists.
FFMPEG=${FFMPEG:-}
if [ -z "$FFMPEG" ]; then
    for c in /usr/local/medialibrary/bin/ffmpeg \
             /usr/local/bin/ffmpeg \
             /opt/bin/ffmpeg \
             /mnt/ext/opt/medialibrary/bin/ffmpeg; do
        [ -x "$c" ] && FFMPEG="$c" && break
    done
fi
[ -z "$FFMPEG" ] && FFMPEG=$(command -v ffmpeg 2>/dev/null || true)

if [ -z "$FFMPEG" ]; then
    state "state${TAB}no-ffmpeg"
    echo "no ffmpeg found on the NAS."
    echo "install one in Container Station, then re-run with:"
    echo "  FFMPEG='docker run --rm --device /dev/dri -v $SHARE:$SHARE linuxserver/ffmpeg' sh $0 --build"
    [ "$MODE" = "--build" ] && exit 1
fi

# ---------- hardware or software ----------
# A render device can be there and still not work (no driver for this ffmpeg):
# try one tiny frame before trusting it, or every proxy would fail.
if [ -e /dev/dri/renderD128 ] && [ -n "$FFMPEG" ] && $FFMPEG -nostdin -loglevel error \
        -vaapi_device /dev/dri/renderD128 -f lavfi -i color=c=black:s=320x240:d=0.2 \
        -vf 'format=nv12|vaapi,hwupload' -c:v h264_vaapi -f null - 2>/dev/null; then
    HW=1
    ENC="-vaapi_device /dev/dri/renderD128 -vf format=nv12|vaapi,hwupload,scale_vaapi=w=-2:h=$HEIGHT -c:v h264_vaapi"
else
    HW=0
    ENC="-vf scale=-2:$HEIGHT -c:v libx264 -preset veryfast -crf 23"
fi

# ---------- what is missing ----------
[ -f "$INDEX" ] || { echo "no index at $INDEX"; exit 1; }

awk -v OFS="$TAB" -v share="$SHARE" -v proot="$PROXY_ROOT" -v only="$ONLY" '
{
    rel = $0
    if (only != "" && index(rel, only "/") != 1) next
    if (rel ~ /^(PROXIES|_duplicates)\//) next
    if (rel ~ /(^|\/)@Recycle\//) next
    if (rel !~ /\.(mxf|MXF|mov|MOV|mp4|MP4|avi|AVI|mts|MTS|m4v|M4V|braw|BRAW|r3d|R3D)$/) next
    src = share "/" rel
    out = proot "/" rel
    sub(/\.[^.\/]*$/, ".mp4", out)
    print src, out
}' "$INDEX" > "$PLAN.all"

# only the ones that do not exist yet — this is what makes it resumable
: > "$PLAN"
missing=0; have=0
while IFS="$TAB" read -r src out; do
    if [ -f "$out" ]; then
        have=$((have + 1))
    else
        printf '%s\t%s\n' "$src" "$out" >> "$PLAN"
        missing=$((missing + 1))
    fi
done < "$PLAN.all"
rm -f "$PLAN.all"

echo "encoder: $([ "$HW" = 1 ] && echo 'hardware (QuickSync)' || echo 'software (libx264)')   ${HEIGHT}p @ $BITRATE"
echo "proxies already built: $have"
echo "proxies to build:      $missing"

if [ "$missing" -gt 0 ]; then
    # source bytes of what is missing, to estimate both time and space
    awk -F"$TAB" '{print $1}' "$PLAN" | head -2000 | xargs -r -d '\n' stat -c %s 2>/dev/null |
    awk -v n="$missing" '{ s += $1; c++ } END {
        if (c > 0) {
            avg = s / c
            printf "  roughly %.1f TB of source to read\n", avg * n / 1099511627776
            printf "  proxies will take roughly %.0f GB\n", avg * n * 0.04 / 1073741824
        }
    }'
fi

if [ "$MODE" != "--build" ]; then
    src_tb=$(awk -F"$TAB" '{print $1}' "$PLAN" | head -2000 | xargs -r -d '\n' stat -c %s 2>/dev/null |
             awk -v n="$missing" '{ s += $1; c++ } END { if (c) printf "%.1f", s / c * n / 1099511627776; else print 0 }')
    state "state${TAB}planned" "only${TAB}$ONLY" "have${TAB}$have" "missing${TAB}$missing" "source_tb${TAB}$src_tb" \
          "hw${TAB}$HW" "height${TAB}$HEIGHT"
    echo
    echo "nothing encoded. re-run with --build."
    exit 0
fi

# ---------- build ----------
total=$missing
done_n=0; ok=0; failed=0; later=0; tmp=""; ff=""
: > "$LOG"
echo "building $total proxies..."
# Low priority: copies, search and editors reading the share come first.
NICE=""; command -v nice >/dev/null 2>&1 && NICE="nice -n 15"
# Stop (from Manage) ends the proxy being made and leaves no half file.
trap '[ -n "$ff" ] && kill "$ff" 2>/dev/null; [ -n "$tmp" ] && rm -f "$tmp";
      state "state${TAB}stopped" "done${TAB}$done_n" "total${TAB}$total" "ok${TAB}$ok" "failed${TAB}$failed" "later${TAB}$later";
      printf "%s\t%s\t%s\t%s\t%s\t%s\t%s\n" "$ONLY" stopped "$ok" "$failed" "$later" "$total" "$(date +%s)" >> "$RUNS";
      echo "stopped from Manage after $ok proxies"; exit 1' TERM INT

while IFS="$TAB" read -r src out; do
    done_n=$((done_n + 1))
    [ -f "$out" ] && continue                      # made meanwhile, e.g. by a second run
    # Still arriving? A copy writes its files over hours; one written in the last
    # two hours is left alone and made on the next run.
    mt=$(stat -c %Y "$src" 2>/dev/null || echo 0)
    if [ $(( $(date +%s) - mt )) -lt "$RECENT" ]; then later=$((later + 1)); continue; fi
    state "state${TAB}building" "only${TAB}$ONLY" "done${TAB}$done_n" "total${TAB}$total" "ok${TAB}$ok" "failed${TAB}$failed" \
          "later${TAB}$later" "file${TAB}${src#$SHARE/}" "hw${TAB}$HW"
    mkdir -p "$(dirname "$out")"
    # encode to a temp name so an interrupted run never leaves a playable-looking
    # but truncated file behind
    tmp="$out.part.mp4"
    $NICE $FFMPEG -nostdin -loglevel error -y -i "$src" \
        $ENC -b:v "$BITRATE" -c:a aac -b:a 128k -movflags +faststart "$tmp" 2>>"$LOG.err" &
    ff=$!
    if wait "$ff"; then
        mv -f "$tmp" "$out"
        printf '%s\t%s\n' "$src" "$out" >> "$LOG"
        # What the original is (4K or HD, frame rate, codec, length), read once
        # here, where the file is: the proxy is always 1080p, the original is not.
        probe=$($FFMPEG -hide_banner -nostdin -i "$src" 2>&1 | grep -E 'Duration:|Video:' | head -2 | tr '\t\n' '  ')
        printf '%s\t%s\t%s\n' "$src" "$(date +%s)" "$probe" >> "$MADE"
        ok=$((ok + 1))
    else
        rm -f "$tmp"
        printf 'FAILED\t%s\n' "$src" >> "$LOG"
        failed=$((failed + 1))
    fi
    if [ $((done_n % 25)) -eq 0 ]; then
        echo "progress: $done_n of $total ($((done_n * 100 / total))%)"
    fi
done < "$PLAN"

ff=""; tmp=""
state "state${TAB}done" "only${TAB}$ONLY" "done${TAB}$done_n" "total${TAB}$total" "ok${TAB}$ok" "failed${TAB}$failed" "later${TAB}$later"
printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$ONLY" done "$ok" "$failed" "$later" "$total" "$(date +%s)" >> "$RUNS"
echo "built $ok proxies, $failed failed, $later left for the next run (still arriving)"
[ "$failed" -gt 0 ] && echo "errors in $LOG.err"
echo "proxies: $PROXY_ROOT"
