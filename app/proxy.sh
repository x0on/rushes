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
#   2. the analysis pass: it finds the cuts and hears the sound in the proxy,
#      and takes its few still pictures from the original, at full quality
#   3. Premiere proxies, so editors cut 720p instead of pulling 4K over the wire
#
# 720p at 4 Mbit/s: the archive's older video chip (Haswell) needs more bits
# than a software encoder for the same picture, and at 1080p/2 Mbit/s moving
# shots broke into blocks. Fewer pixels, twice the bits: clean, still light
# (about 30 MB a minute).

export WEB=${WEB:-/share/Web} ARCH=${ARCH:-/share/VIDEO}     # from runner.sh (Setup); otherwise the QNAP's
set -u

SHARE=${SHARE:-$ARCH}
PROXY_ROOT=${PROXY_ROOT:-$SHARE/PROXIES}
INDEX=${INDEX:-$WEB/index.txt}
PLAN=${PLAN:-$WEB/proxy-plan.tsv}
LOG=${LOG:-$WEB/proxy-built.tsv}
# The setting chosen in Manage → Describe → Proxy settings: height, and Mbit/s
# on the video chip or "sw" for software (x264: often a better picture than an
# older chip, at the cost of the processor). Otherwise 720p at 4 on the chip.
SOFTWARE=0
if [ -z "${HEIGHT:-}" ] && read -r ph pb < $WEB/proxy-setting.txt 2>/dev/null; then
    case "$ph:$pb" in
        720:[46]|1080:[46]) HEIGHT=$ph; BITRATE=${pb}M; MAXRATE=$(( pb * 3 / 2 ))M ;;
        720:sw|1080:sw)     HEIGHT=$ph; SOFTWARE=1 ;;
    esac
fi
HEIGHT=${HEIGHT:-720}
BITRATE=${BITRATE:-4M}
MAXRATE=${MAXRATE:-6M}   # a busy moment may use more, briefly
TAB=$(printf '\t')
MODE=${1:-plan}
ONLY=${PROXY_ONLY:-}     # one folder of the archive (e.g. "PARK COLLECTION"), or empty for all of it
ONLY=${ONLY%/}
STATE=${STATE:-$WEB/proxy-status.txt}
RUNS=${RUNS:-$WEB/proxy-folders.tsv}   # one line per finished or stopped run, per folder: what Rushes reads to know a folder is ready
MADE=${MADE:-$WEB/proxy-made.tsv}      # never emptied: every proxy made, with what the original is (Rushes keeps it in its media ledger)
SPEED=${SPEED:-$WEB/proxy-speed.tsv}    # bytes and seconds of each proxy made: how Rushes knows the time left
FAILS=${FAILS:-$WEB/proxy-failed.tsv}   # each file that could not be made, and why, in ffmpeg's own words
RECENT=${RECENT:-7200}   # seconds: a file written this recently may still be arriving; left for the next run

# What the page shows: one small file, rewritten whole, so it is never half-read.
state() {
    { printf 'at\t%s\n' "$(date +%s)"; for kv in "$@"; do printf '%s\n' "$kv"; done; } > "$STATE.new"
    mv -f "$STATE.new" "$STATE"
}

# ---------- find ffmpeg ----------
# Best: the complete ffmpeg in Container Station (linuxserver/ffmpeg). It
# carries Intel's older video driver, i965, which chips from before 2015 need
# (Jellyfin's documentation says so, and Manage → Test the video chip showed it
# on this machine: twice as fast as software, a fifth of the processor).
# It runs gently (--cpu-shares) and sees only the archive share.
FFMPEG=${FFMPEG:-}
DOCKER=${DOCKER:-$(command -v docker 2>/dev/null)}
if [ -z "$DOCKER" ]; then
    CS=$(getcfg container-station Install_Path -f /etc/config/qpkg.conf 2>/dev/null)
    for d in "$CS/bin/docker" "$CS/usr/bin/docker" /usr/local/bin/docker /share/CACHEDEV1_DATA/.qpkg/container-station/bin/docker; do
        [ -x "$d" ] && DOCKER=$d && break
    done
fi
if [ -z "$FFMPEG" ] && [ -n "$DOCKER" ] && "$DOCKER" image inspect linuxserver/ffmpeg >/dev/null 2>&1; then
    FFMPEG="$DOCKER run --rm --cpu-shares 256 -v $SHARE:$SHARE"
    [ -e /dev/dri/renderD128 ] && FFMPEG="$FFMPEG --device /dev/dri:/dev/dri -e LIBVA_DRIVER_NAME=${LIBVA_DRIVER_NAME:-i965}"
    # ffmpeg itself, not the image's wrapper: the wrapper runs it as the owner of
    # the camera file, who may not write in PROXIES ("Permission denied"). Owners
    # are set below instead, to match each original.
    FFMPEG="$FFMPEG --entrypoint /usr/local/bin/ffmpeg linuxserver/ffmpeg"
fi
# Then an ffmpeg of the NAS's own (software only, on this machine).
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
# When it does not work, say which of the three reasons it is: no video chip
# the system offers, an ffmpeg built without hardware encoding, or the chip
# refusing (driver). Each has a different fix, so guessing helps nobody.
CPU=$(grep -m1 'model name' /proc/cpuinfo 2>/dev/null | cut -d: -f2- | sed 's/^ *//')
HW_WHY=""
if [ ! -e /dev/dri/renderD128 ]; then
    HW_WHY="this machine offers no video chip to programs (no /dev/dri): its processor may not have one"
elif [ -n "$FFMPEG" ] && ! $FFMPEG -hide_banner -encoders 2>/dev/null | grep -q h264_vaapi; then
    HW_WHY="the video chip is there, but this ffmpeg ($FFMPEG) was built without hardware encoding"
fi
if [ -z "$HW_WHY" ] && [ -n "$FFMPEG" ] && hw_err=$($FFMPEG -nostdin -loglevel error \
        -vaapi_device /dev/dri/renderD128 -f lavfi -i color=c=black:s=320x240:d=0.2 \
        -vf 'format=nv12|vaapi,hwupload' -c:v h264_vaapi -f null - 2>&1); then
    HW=1
else
    HW=0
    [ -z "$HW_WHY" ] && HW_WHY="the video chip is there and this ffmpeg can use it, but the chip refused a test frame: $(printf '%s' "${hw_err:-no ffmpeg}" | head -1 | cut -c1-160)"
fi

# One proxy: the fastest way that works for this file. All on the chip (H.264,
# MPEG-2/MXF); if the chip cannot read the file (HEVC on older chips), the
# processor reads it and the chip resizes and encodes; software is the last
# resort. $how says which way it was made. Each try runs in the background so
# Stop (from Manage) can end it at once.
ERRF=${ERRF:-$WEB/proxy-last.err}
run_ff() { $NICE $FFMPEG -nostdin -loglevel error -y "$@" 2>"$ERRF" & ff=$!; wait "$ff"; r=$?; cat "$ERRF" >> "$LOG.err"; return $r; }
AUDIO="-c:a aac -b:a 128k -movflags +faststart"

# The kinds of file the chip cannot read (say HEVC 10-bit from a drone), learnt
# once and kept: such a file goes straight to the way that works, instead of
# failing on the chip first every time. A kind is the codec, its profile and
# its pixel format, read in a moment by the NAS's own ffmpeg (no container).
CANNOT=${CANNOT:-$WEB/proxy-chip-cannot.txt}
PEEK=${PEEK:-}
[ -z "$PEEK" ] && for c in /usr/local/medialibrary/bin/ffmpeg /mnt/ext/opt/medialibrary/bin/ffmpeg /usr/local/bin/ffmpeg; do
    [ -x "$c" ] && PEEK=$c && break
done
kind_of() {
    [ -n "$PEEK" ] || return 0
    $PEEK -hide_banner -nostdin -i "$1" 2>&1 | grep -m1 'Video:' | sed -e 's/.*Video: //' -e 's/ ([a-z0-9]* \/ 0x[0-9a-f]*)//' -e 's/\([a-z0-9]\)([^)]*)/\1/g' -e 's/, [0-9][0-9]*x[0-9].*//' | cut -c1-80
}

encode() {
    kind=$(kind_of "$1")
    if [ "$SOFTWARE" = 1 ]; then          # chosen in Proxy settings: straight to software
        how="software"
        run_ff -i "$1" -vf "scale=-2:$HEIGHT" -c:v libx264 -preset veryfast -crf 23 $AUDIO "$2"; return
    fi
    if [ "$HW" = 1 ] && ! { [ -n "$kind" ] && grep -qxF "$kind" "$CANNOT" 2>/dev/null; }; then
        how="video chip"
        run_ff -hwaccel vaapi -hwaccel_device /dev/dri/renderD128 -hwaccel_output_format vaapi -i "$1" \
            -vf "scale_vaapi=w=-2:h=$HEIGHT" -c:v h264_vaapi -b:v "$BITRATE" -maxrate "$MAXRATE" $AUDIO "$2" && return 0
        chip_failed=1
    else
        chip_failed=0
    fi
    if [ "$HW" = 1 ]; then
        how="video chip (read by the processor)"
        if run_ff -vaapi_device /dev/dri/renderD128 -i "$1" \
            -vf "format=nv12,hwupload,scale_vaapi=w=-2:h=$HEIGHT" -c:v h264_vaapi -b:v "$BITRATE" -maxrate "$MAXRATE" $AUDIO "$2"; then
            # the chip could not read this kind, the processor could: remember it
            if [ "$chip_failed" = 1 ] && [ -n "$kind" ] && ! grep -qxF "$kind" "$CANNOT" 2>/dev/null; then
                echo "$kind" >> "$CANNOT"; echo "  learnt: the chip cannot read \"$kind\" — such files go straight to the processor from now on"
            fi
            return 0
        fi
    fi
    how="software"
    run_ff -i "$1" -vf "scale=-2:$HEIGHT" -c:v libx264 -preset veryfast -crf 23 $AUDIO "$2"
}

# ---------- what is missing ----------
# One folder: read the folder itself, so footage that landed since the last
# full scan counts too. The whole archive: the index of the last scan.
state "state${TAB}planning" "only${TAB}$ONLY" "step${TAB}listing the videos in the folder"
if [ -n "$ONLY" ]; then
    [ -d "$SHARE/$ONLY" ] || { state "state${TAB}no-folder" "only${TAB}$ONLY"; echo "no folder $SHARE/$ONLY"; exit 1; }
    list() { find "$SHARE/$ONLY" -type f 2>/dev/null; }
else
    [ -f "$INDEX" ] || { echo "no index at $INDEX"; exit 1; }
    list() { cat "$INDEX"; }
fi

list | awk -v OFS="$TAB" -v share="$SHARE" -v proot="$PROXY_ROOT" -v only="$ONLY" '
{
    rel = $0
    # the index and find both give full paths: make them relative to the share
    if (index(rel, share "/") == 1) rel = substr(rel, length(share) + 2)
    if (only != "" && index(rel, only "/") != 1) next
    if (rel ~ /^(PROXIES|_duplicates|_Recently Removed)\//) next
    if (rel ~ /(^|\/)@Recycle\//) next
    if (rel !~ /\.(mxf|MXF|mov|MOV|mp4|MP4|avi|AVI|mts|MTS|m4v|M4V|braw|BRAW|r3d|R3D)$/) next
    src = share "/" rel
    out = proot "/" rel
    sub(/\.[^.\/]*$/, ".mp4", out)
    print src, out
}' > "$PLAN.all"

# only the ones that do not exist yet — this is what makes it resumable
: > "$PLAN"
missing=0; have=0; seen=0; videos=$(wc -l < "$PLAN.all")
while IFS="$TAB" read -r src out; do
    seen=$((seen + 1))
    [ $((seen % 200)) -eq 0 ] && state "state${TAB}planning" "only${TAB}$ONLY" "step${TAB}checking which already have a proxy" \
        "seen${TAB}$seen" "videos${TAB}$videos"
    if [ -f "$out" ]; then
        have=$((have + 1))
    else
        printf '%s\t%s\n' "$src" "$out" >> "$PLAN"
        missing=$((missing + 1))
    fi
done < "$PLAN.all"
rm -f "$PLAN.all"

echo "encoder: $([ "$HW" = 1 ] && echo 'hardware (QuickSync)' || echo "software (libx264), because $HW_WHY")   ${HEIGHT}p @ $([ "${SOFTWARE:-0}" = 1 ] && echo 'software, quality 23' || echo "$BITRATE")"
echo "processor: ${CPU:-unknown} · ffmpeg: ${FFMPEG:-none}"
echo "proxies already built: $have"
echo "proxies to build:      $missing"

sizes() { head -2000 "$PLAN" | while IFS="$TAB" read -r src out; do stat -c %s "$src" 2>/dev/null; done; }
if [ "$missing" -gt 0 ]; then
    # source bytes of what is missing, to estimate both time and space
    sizes |
    awk -v n="$missing" '{ s += $1; c++ } END {
        if (c > 0) {
            avg = s / c
            printf "  roughly %.1f TB of source to read\n", avg * n / 1099511627776
            printf "  proxies will take roughly %.0f GB\n", avg * n * 0.04 / 1073741824
        }
    }'
fi

if [ "$MODE" != "--build" ]; then
    src_tb=$(sizes |
             awk -v n="$missing" '{ s += $1; c++ } END { if (c) printf "%.0f", s / c * n / 1073741824; else print 0 }')
    state "state${TAB}planned" "only${TAB}$ONLY" "have${TAB}$have" "missing${TAB}$missing" "source_gb${TAB}$src_tb" \
          "hw${TAB}$HW" "height${TAB}$HEIGHT" "videos${TAB}$videos" \
          "hw_why${TAB}$HW_WHY" "cpu${TAB}$CPU" "ffmpeg${TAB}$FFMPEG"
    echo
    echo "nothing encoded. re-run with --build."
    exit 0
fi

# ---------- build ----------
# Room first: a proxy is about 4% of its original; 5% plus 2 GB is asked for, to
# be safe. Checked before starting, so a
# full archive is said once, plainly, instead of failing file after file.
mkdir -p "$PROXY_ROOT"
chown "$(stat -c %u:%g "$SHARE")" "$PROXY_ROOT" 2>/dev/null   # PROXIES belongs to whoever owns the share
need=$(while IFS="$TAB" read -r src out; do stat -c %s "$src" 2>/dev/null; done < "$PLAN" | awk '{ s += $1 } END { printf "%.0f", s * 0.05 + 2e9 }')
free=$(df -Pk "$PROXY_ROOT" 2>/dev/null | awk 'NR == 2 { printf "%.0f", $4 * 1024 }')
if [ -n "$free" ] && [ "$free" -lt "$need" ]; then
    state "state${TAB}no-room" "only${TAB}$ONLY" "need${TAB}$need" "free${TAB}$free"
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$ONLY" no-room 0 0 0 "$missing" "$(date +%s)" >> "$RUNS"
    echo "not enough room for the proxies: about $((need / 1000000000)) GB needed, $((free / 1000000000)) GB free"
    exit 1
fi
total=$missing
done_n=0; ok=0; failed=0; later=0; tmp=""; ff=""; chip=0; mixed=0; soft=0
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
          "later${TAB}$later" "file${TAB}${src#$SHARE/}" "hw${TAB}$HW" "chip${TAB}$chip" "mixed${TAB}$mixed" "soft${TAB}$soft"
    mkdir -p "$(dirname "$out")"
    # encode to a temp name so an interrupted run never leaves a playable-looking
    # but truncated file behind
    tmp="$out.part.mp4"
    t0=$(date +%s)
    if encode "$src" "$tmp"; then
        # The proxy and its folders belong to whoever owns the original, like
        # the original: readable on the share, and movable when tidy-up moves it.
        own=$(stat -c %u:%g "$src" 2>/dev/null) && {
            chown "$own" "$tmp" 2>/dev/null
            d=$(dirname "$out"); while [ "$d" != "$PROXY_ROOT" ] && [ "$d" != "/" ]; do chown "$own" "$d" 2>/dev/null; d=$(dirname "$d"); done
        }
        printf '%s\t%s\t%s\n' "$(stat -c %s "$src" 2>/dev/null || echo 0)" "$(( $(date +%s) - t0 ))" "$how" >> "$SPEED"
        mv -f "$tmp" "$out"
        printf '%s\t%s\t%s\n' "$src" "$out" "$how" >> "$LOG"
        case "$how" in "video chip") chip=$((chip + 1)) ;; software) soft=$((soft + 1)) ;; *) mixed=$((mixed + 1)) ;; esac
        # What the original is (4K or HD, frame rate, codec, length) and what the
        # camera wrote inside it (its clock, timecode, reel, make and model),
        # read once here, where the file is: the proxy is smaller than the original.
        probe=$($FFMPEG -hide_banner -nostdin -i "$src" 2>&1 \
            | grep -E 'Duration:|Video:|creation_time|modification_date|timecode|reel_name|model|make|product_name|company_name|encoder' \
            | head -24 | tr '\t\n' '  ')
        printf '%s\t%s\t%s\n' "$src" "$(date +%s)" "$probe" >> "$MADE"
        ok=$((ok + 1))
    else
        rm -f "$tmp"
        printf 'FAILED\t%s\n' "$src" >> "$LOG"
        printf '%s\t%s\t%s\n' "$src" "$(date +%s)" "$(tail -1 "$ERRF" 2>/dev/null | tr '\t' ' ' | cut -c1-200)" >> "$FAILS"
        # Not this file's fault: nothing can be written in PROXIES. Stop once,
        # saying why, instead of failing every file the same way.
        if grep -q "Permission denied\|No space left" "$ERRF" 2>/dev/null; then
            why=$(grep -m1 "Permission denied\|No space left" "$ERRF" | cut -c1-200)
            failed=$((failed + 1))
            state "state${TAB}stopped" "only${TAB}$ONLY" "done${TAB}$done_n" "total${TAB}$total" "ok${TAB}$ok" "failed${TAB}$failed" "why${TAB}$why"
            printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$ONLY" stopped "$ok" "$failed" "$later" "$total" "$(date +%s)" >> "$RUNS"
            echo "stopped: the proxies cannot be written — $why"
            exit 1
        fi
        failed=$((failed + 1))
    fi
    if [ $((done_n % 25)) -eq 0 ]; then
        echo "progress: $done_n of $total ($((done_n * 100 / total))%)"
    fi
done < "$PLAN"

ff=""; tmp=""
state "state${TAB}done" "only${TAB}$ONLY" "done${TAB}$done_n" "total${TAB}$total" "ok${TAB}$ok" "failed${TAB}$failed" "later${TAB}$later" "chip${TAB}$chip" "mixed${TAB}$mixed" "soft${TAB}$soft"
printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$ONLY" done "$ok" "$failed" "$later" "$total" "$(date +%s)" >> "$RUNS"
echo "built $ok proxies, $failed failed, $later left for the next run (still arriving)"
[ "$failed" -gt 0 ] && echo "errors in $LOG.err"
echo "proxies: $PROXY_ROOT"
