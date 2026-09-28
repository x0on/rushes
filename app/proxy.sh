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

SHARE=/share/VIDEO
PROXY_ROOT=${PROXY_ROOT:-$SHARE/PROXIES}
INDEX=${INDEX:-/share/Web/index.txt}
PLAN=${PLAN:-/share/Web/proxy-plan.tsv}
LOG=${LOG:-/share/Web/proxy-built.tsv}
HEIGHT=${HEIGHT:-1080}
BITRATE=${BITRATE:-2M}
TAB=$(printf '\t')
MODE=${1:-plan}

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
    echo "no ffmpeg found on the NAS."
    echo "install one in Container Station, then re-run with:"
    echo "  FFMPEG='docker run --rm --device /dev/dri -v $SHARE:$SHARE linuxserver/ffmpeg' sh $0 --build"
    [ "$MODE" = "--build" ] && exit 1
fi

# ---------- hardware or software ----------
if [ -e /dev/dri/renderD128 ]; then
    HW=1
    ENC="-vaapi_device /dev/dri/renderD128 -vf format=nv12|vaapi,hwupload,scale_vaapi=w=-2:h=$HEIGHT -c:v h264_vaapi"
else
    HW=0
    ENC="-vf scale=-2:$HEIGHT -c:v libx264 -preset veryfast -crf 23"
fi

# ---------- what is missing ----------
[ -f "$INDEX" ] || { echo "no index at $INDEX"; exit 1; }

awk -v OFS="$TAB" -v share="$SHARE" -v proot="$PROXY_ROOT" '
{
    rel = $0
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
    echo
    echo "nothing encoded. re-run with --build."
    exit 0
fi

# ---------- build ----------
total=$missing
done_n=0; ok=0; failed=0
: > "$LOG"
echo "building $total proxies..."

while IFS="$TAB" read -r src out; do
    done_n=$((done_n + 1))
    mkdir -p "$(dirname "$out")"
    # encode to a temp name so an interrupted run never leaves a playable-looking
    # but truncated file behind
    tmp="$out.part.mp4"
    if $FFMPEG -nostdin -loglevel error -y -i "$src" \
        $ENC -b:v "$BITRATE" -c:a aac -b:a 128k -movflags +faststart "$tmp" 2>>"$LOG.err"; then
        mv -f "$tmp" "$out"
        printf '%s\t%s\n' "$src" "$out" >> "$LOG"
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

echo "built $ok proxies, $failed failed"
[ "$failed" -gt 0 ] && echo "errors in $LOG.err"
echo "proxies: $PROXY_ROOT"
