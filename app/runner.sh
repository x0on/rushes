#!/bin/sh
# runner.sh — executes jobs queued by the admin page. Runs as root from cron,
# every minute. The web page never touches the filesystem itself: it drops a
# job file, this picks it up. That separation is the whole security model.
#
# Install:
#   mkdir -p /share/Web/queue && chmod 777 /share/Web/queue
#   echo "* * * * * sh /share/Web/runner.sh" >> /etc/config/crontab
#   crontab /etc/config/crontab && /etc/init.d/crond.sh restart
#
# Recommended second line — the duplicate scan takes hours and should never run
# during the working day. Sunday 02:00, when the index rebuild is also idle:
#   0 2 * * 0 printf 'ACTION=scan\n' > /share/Web/queue/weekly.job

# The off switch: a file called STOP in the web folder (File Station: Web →
# create a folder or file named STOP) and the runner does nothing at all, every
# minute, until it is removed. For a disk rebuild, or anything else where the
# archive machine must be left alone. Checked before anything else is touched.
[ -e /share/Web/STOP ] && exit 0

Q=/share/Web/queue
LOG=/share/Web/job.log
log() { printf '%s\n' "$*" >> "$LOG"; }     # one line into the job log
STATUS=/share/Web/job-status.txt
LOCKDIR=/tmp/.archive-runner.lock

mkdir -p "$Q"

# Heartbeat first, before the lock, so it keeps ticking even while a long job
# holds it. Without this the page cannot tell a busy runner from a dead one —
# which is exactly how a broken cron went unnoticed for forty minutes.
date '+%s' > /share/Web/runner-alive.txt

# One minute's work at a time. If last minute's run is still stuck (a disk that
# stops answering holds a read for minutes), this one does nothing rather than
# pile another stuck run on top of it. Kept in /tmp, which is memory, not disk.
TICK=/tmp/.archive-runner-tick
if ! mkdir "$TICK" 2>/dev/null; then
    old=$(cat "$TICK/pid" 2>/dev/null)
    if [ -n "$old" ] && kill -0 "$old" 2>/dev/null; then exit 0; fi
    rm -rf "$TICK"; mkdir "$TICK" 2>/dev/null || exit 0      # its run ended without tidying up
fi
echo $$ > "$TICK/pid"
trap 'rm -rf "$TICK"' EXIT INT TERM

# Paused from Manage (Pause copying): paused is paused. Nothing is read from or
# written to VIDEO by itself — no status mirrored, no page deployed, no proxies
# started. Only what someone asks for in Manage still runs.
PAUSED=0
grep -q '"paused":true' /share/Web/helper-control.json 2>/dev/null && PAUSED=1

# ── reaching VIDEO: with a time limit, and a breaker ────────────────────────
# Every step that reads or writes the VIDEO share goes through v(). It starts
# the step and waits at most VLIMIT seconds. A step that has not finished by
# then is walked away from — not waited for: a process stuck on a dying disk
# often cannot even be stopped — and counted. Three in a row and the breaker
# trips: nothing touches VIDEO by itself until a person presses Try again in
# Manage (which removes video-tripped.txt). A minute with no stall resets the count.
VLIMIT=${VLIMIT:-20}
VSTALL=/share/Web/video-stalls.txt
VTRIP=/share/Web/video-tripped.txt
VOK=1; [ -f "$VTRIP" ] && VOK=0
v() {
    "$@" & vp=$!
    vi=0
    while kill -0 "$vp" 2>/dev/null && [ "$vi" -lt "$VLIMIT" ]; do sleep 1; vi=$((vi + 1)); done
    if kill -0 "$vp" 2>/dev/null; then
        kill "$vp" 2>/dev/null
        vn=$(( $(cat "$VSTALL" 2>/dev/null || echo 0) + 1 )); echo "$vn" > "$VSTALL"
        echo "$(date '+%Y-%m-%d %H:%M:%S')  VIDEO did not answer within ${VLIMIT}s ($1 $2) — $vn in a row" >> "$LOG"
        if [ "$vn" -ge 3 ] && [ ! -f "$VTRIP" ]; then
            printf '%s\t%s\n' "$(date +%s)" "VIDEO did not answer $vn times in a row (last: $1 $2)" > "$VTRIP"
            echo "$(date '+%Y-%m-%d %H:%M:%S')  STOPPED touching VIDEO until Try again is pressed in Manage" >> "$LOG"
        fi
        VOK=0; VSTALLED=1; return 124
    fi
    wait "$vp"
}
VSTALLED=0
# Whether this minute may reach VIDEO by itself: not paused, not tripped, not stuck.
may_v() { [ "$PAUSED" = 0 ] && [ "$VOK" = 1 ]; }

may_v && v sh -c 'df -P /share/VIDEO | tail -1 > /share/Web/disk.txt'

# /tmp on a QNAP is a 64 MB RAM disk shared with the system. Give it room (it
# only uses memory for what is actually in it), and say how full it is.
tmp_kb=$(df -Pk /tmp | awk 'NR==2 {print $2}')
if [ -n "$tmp_kb" ] && [ "$tmp_kb" -lt 262144 ]; then
    mount -o remount,size=256M /tmp 2>/dev/null && echo "$(date '+%Y-%m-%d %H:%M:%S')  gave /tmp 256 MB (it had $((tmp_kb / 1024)) MB)" >> "$LOG"
fi
df -P /tmp | tail -1 > /share/Web/tmp-disk.txt

# The built-in helper: when Setup says the helper runs on this machine, keep
# it running. Started again within a minute if it stops; its output goes to
# /share/Web/helper.log, which is kept small.
RUSHES="${RUSHES_URL:-http://127.0.0.1}"
if [ "$(curl -fsS --max-time 5 "$RUSHES/db/helper.php?builtin" 2>/dev/null)" = "yes" ]; then
    PY=$(command -v python3 2>/dev/null)
    pid=$(cat /share/Web/helper.pid 2>/dev/null)
    if [ -z "$PY" ]; then
        echo "no-python" > /share/Web/helper-builtin.txt
    elif [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
        echo "running" > /share/Web/helper-builtin.txt
    else
        [ -f /share/Web/helper.log ] && [ "$(wc -c < /share/Web/helper.log)" -gt 5000000 ] && mv /share/Web/helper.log /share/Web/helper.log.old
        "$PY" -u /share/VIDEO/_rushes/ingest.py --watch --url "$RUSHES" --service >> /share/Web/helper.log 2>&1 &
        echo $! > /share/Web/helper.pid
        echo "started" > /share/Web/helper-builtin.txt
        echo "$(date '+%Y-%m-%d %H:%M:%S')  started the built-in helper" >> "$LOG"
    fi
fi

# Folders being prepared (Manage → Describe): Rushes writes the next folder that
# needs proxies into proxy-next.txt; start it here when nothing is being made.
# Rushes only decides; this is the only place a build is started from.
# Is a proxy build really running? The number in proxy.pid can outlive its
# job and be given to another program later: only trust it if that process is
# proxy.sh, or the list would wait for ever for a build that is not there.
# No nohup here: the NAS's shell does not have it (it failed with "nohup: command
# not found" every minute), and a job started from cron keeps running after the
# runner ends anyway. Input from /dev/null so nothing waits on a terminal.
proxy_alive() { pid=$(cat /share/Web/proxy.pid 2>/dev/null); [ -n "$pid" ] && grep -q proxy.sh "/proc/$pid/cmdline" 2>/dev/null; }
NEXT=$(head -1 /share/Web/proxy-next.txt 2>/dev/null | tr -cd 'A-Za-z0-9 _./&(),+-' | cut -c1-200)
if [ -n "$NEXT" ] && may_v; then
    if ! proxy_alive; then
        rm -f /share/Web/proxy-next.txt
        PROXY_ONLY="$NEXT" sh /share/Web/proxy.sh --build < /dev/null >> /share/Web/proxy.log 2>&1 &
        echo $! > /share/Web/proxy.pid
        echo "$(date '+%Y-%m-%d %H:%M:%S')  making proxies for $NEXT" >> "$LOG"
    fi
fi

# (The helper's status, history and section list used to be copied off the
# VIDEO share here every minute. The helper sends them to Rushes itself now
# — db/status.php — so an idle minute reads nothing from the archive.)

# ── deploying a page ────────────────────────────────────────────────────────
# The web folder is not reachable from anywhere except the NAS itself, which
# meant every fix to a page travelled: download, File Station, upload, refresh.
# This gives it a front door. Anything dropped into _rushes/deploy on the VIDEO
# share lands in the web folder within the minute, and is then removed, so the
# drop folder is always either empty or a change waiting to happen.
#
# Only these extensions, and only files, never folders: this folder is writable
# by anyone who can write to the share, and it publishes onto the web server.
# A db/ prefix is the one bit of nesting allowed, because that is where the
# pages actually live.
DROP=/share/VIDEO/_rushes/deploy
# ── what is waiting to be installed ─────────────────────────────────────────
# Updated scripts (_rushes/scripts) and pages (_rushes/deploy) are never put in
# place by themselves: a page runs on this machine's web server and a script as
# root, so both wait for an admin's "Install it" in Manage, exactly as approved
# (RISKS.md #14 — pages used to go live within a minute of being dropped).
# What is waiting, with fingerprints, and the fingerprint of the helper's code,
# is looked at here when asked (below), within the time limit, and kept in the
# web folder — so no page ever has to read VIDEO itself.
page_ok() {      # only these, only one folder deep, only db/
    case "$1" in */*/*|.*|*/.*) return 1 ;; db/*|*/*) case "$1" in db/*) ;; *) return 1 ;; esac ;; esac
    case "$1" in *.php|*.html|*.js|*.css|*.json|favicon.ico|apple-touch-icon.png) return 0 ;; esac
    return 1
}
survey() {
    out=/share/Web/waiting.tsv.new; : > "$out"
    for f in /share/VIDEO/_rushes/scripts/*.sh; do
        [ -f "$f" ] || continue; n=${f##*/}
        case "$n" in *[!a-z0-9.-]*|.*) continue ;; esac
        h=$(sha256sum "$f" | cut -d' ' -f1); have=$(sha256sum "/share/Web/$n" 2>/dev/null | cut -d' ' -f1)
        [ "$h" != "$have" ] && printf 'script\t%s\t%s\t%s\n' "$n" "$h" "$(stat -c %Y "$f")" >> "$out"
    done
    [ -d "$DROP" ] && find "$DROP" -type f 2>/dev/null | while read -r f; do
        rel=${f#$DROP/}; page_ok "$rel" || continue
        printf 'page\t%s\t%s\t%s\n' "$rel" "$(sha256sum "$f" | cut -d' ' -f1)" "$(stat -c %Y "$f")" >> "$out"
    done
    printf 'helper\t%s\n' "$(sha256sum /share/VIDEO/_rushes/ingest.py 2>/dev/null | cut -c1-12)" >> "$out"
    # the helper's own files, whole fingerprints: helpers ask for these to update
    # themselves (helper.php?hash), so that question never reads VIDEO
    for n in ingest.py transfer_state.py analyze.py; do
        [ -f "/share/VIDEO/_rushes/$n" ] && printf 'helperfile\t%s\t%s\n' "$n" "$(sha256sum "/share/VIDEO/_rushes/$n" | cut -d' ' -f1)" >> "$out"
    done
    mv "$out" /share/Web/waiting.tsv
}
# New versions of Rushes' own files are looked for only when asked: Manage →
# "Check for updates" (survey-now), right after an install, or when there is
# no list yet. They only ever arrive because a person put them in _rushes, so
# looking on a timer would read VIDEO for nothing.
if may_v && { [ ! -f /share/Web/waiting.tsv ] || [ -f /share/Web/survey-now ]; }; then
    rm -f /share/Web/survey-now
    v survey
fi

# Transfers add files incrementally through landed.php. A finished folder no
# longer triggers another full archive walk.

# ponytail: mkdir is the portable atomic lock; busybox has no flock
[ "$VSTALLED" = 0 ] && [ -f "$VSTALL" ] && rm -f "$VSTALL"     # a whole minute without a stall: the count starts again
rm -rf "$TICK"; trap - EXIT INT TERM       # this minute's share work is done; long jobs have their own lock
mkdir "$LOCKDIR" 2>/dev/null || exit 0
trap 'rmdir "$LOCKDIR" 2>/dev/null' EXIT INT TERM

# keep the log from growing forever
[ -f "$LOG" ] && [ "$(wc -c < "$LOG")" -gt 2000000 ] && tail -c 500000 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"


# QNAP's busybox find has no -printf, so it wrote an empty file and said
# nothing. An empty manifest is worse than none: it tells the Mac the archive
# is empty and every file is new. Try the fast way, check it worked, fall back.
build_manifest() {
    M=/share/Web/manifest.tsv.new
    date +%s > /share/Web/manifest-started.txt.new
    T=$(printf '\t')
    if find -L /share/VIDEO -type f -not -path '*/@Recycle/*' \
        -printf '%s\t%p\n' > "$M" 2>/dev/null && [ -s "$M" ]; then
        method='find -printf'
    elif find -L /share/VIDEO -type f -not -path '*/@Recycle/*' \
        -exec stat -c "%s${T}%n" {} + > "$M" 2>/dev/null && [ -s "$M" ]; then
        method='find -exec stat'
    else
        # Check the walk and every stat; a partial inventory must not replace
        # the last complete snapshot just because it contains some rows.
        P=/share/Web/manifest-paths.tmp
        find -L /share/VIDEO -type f -not -path '*/@Recycle/*' > "$P" 2>/dev/null || return 1
        failed=0
        : > "$M"
        while IFS= read -r f; do
            size=$(stat -c %s "$f" 2>/dev/null) || { failed=1; break; }
            printf '%s\t%s\n' "$size" "$f" >> "$M"
        done < "$P"
        rm -f "$P"
        [ "$failed" -eq 0 ] && [ -s "$M" ] || return 1
        method='stat per file'
    fi
    mv /share/Web/manifest-started.txt.new /share/Web/manifest-started.txt
    mv "$M" /share/Web/manifest.tsv
    echo "$method"
}


# One walk, two files. The search index is the manifest with the size column
# removed — same list, same order, same format the search page already reads.
# Walking a million files twice to produce that was pure waste.
#
# The guard matters: index.txt is what the search page lives on. If the derived
# list comes out short, the old one stays and the log says so, rather than
# leaving someone with a search that silently finds nothing.
build_index() {
    m=$(build_manifest) || { echo "File scan interrupted; keeping the previous inventory" >> "$LOG"; return 1; }
    n=$(wc -l < /share/Web/manifest.tsv)
    if [ "$n" -lt 1000 ]; then
        echo "  file list came back with only $n files — keeping the old search index" >> "$LOG"
        echo "  (the archive should have hundreds of thousands; something is wrong)" >> "$LOG"
        return 1
    fi
    cut -f2 /share/Web/manifest.tsv > /share/Web/index.txt.new
    i=$(wc -l < /share/Web/index.txt.new)
    if [ "$i" -eq "$n" ]; then
        mv /share/Web/index.txt.new /share/Web/index.txt
        echo "  file list and search index: $n files, one pass (via $m)" >> "$LOG"
    else
        rm -f /share/Web/index.txt.new
        echo "  index came out $i lines from a $n line list — kept the old one" >> "$LOG"
        return 1
    fi
}

field() { grep "^$1=" "$2" 2>/dev/null | head -1 | cut -d= -f2- ; }

# Container Station's docker: cron starts this script with a short PATH that
# does not include it, so look where Container Station installs it.
DOCKER=$(command -v docker 2>/dev/null)
if [ -z "$DOCKER" ]; then
    CS=$(getcfg container-station Install_Path -f /etc/config/qpkg.conf 2>/dev/null)
    for d in "$CS/bin/docker" "$CS/usr/bin/docker" /usr/local/bin/docker /share/CACHEDEV1_DATA/.qpkg/container-station/bin/docker; do
        [ -x "$d" ] && DOCKER=$d && break
    done
fi

# After anything that moves files, the system's picture of the share is out of
# date. Re-look, automatically. These are the cheap observations — seconds to a
# few minutes. The duplicate scan is hours, so it stays on the nightly schedule
# rather than blocking the queue in the middle of the working day.
refresh_state() {
    echo "" >> "$LOG"
    echo "re-reading the share after the job..." >> "$LOG"
    build_index
    du -sk /share/VIDEO/_duplicates 2>/dev/null | cut -f1 > /share/Web/holding-kb.txt
    df -P /share/VIDEO | tail -1 > /share/Web/disk.txt
    echo "  free space: $(df -h /share/VIDEO | tail -1 | awk '{print $4}')" >> "$LOG"
}

for job in $(ls -1 "$Q"/*.job 2>/dev/null | sort); do
    [ -f "$job" ] || continue

    ACTION=$(field ACTION "$job")
    KEEP_SIDE=$(field KEEP_SIDE "$job")
    DEST=$(field DEST "$job")
    STILLS=$(field STILLS "$job")
    EXCLUDE=$(field EXCLUDE "$job")
    QUERY=$(field QUERY "$job")
    SCRIPTS=$(grep '^SCRIPT=' "$job" 2>/dev/null | cut -d= -f2-)
    PAGES=$(grep '^PAGE=' "$job" 2>/dev/null | cut -d= -f2-)
    rm -f "$job"

    # ---- validate everything; never trust the queue file ----
    case "$KEEP_SIDE" in project|card|short|oldest) ;; *) KEEP_SIDE=project ;; esac
    case "$STILLS" in 1) ;; *) STILLS=0 ;; esac
    # exclusions are plain relative folder names; strip anything else
    EXCLUDE=$(printf '%s' "$EXCLUDE" | tr -cd 'A-Za-z0-9 ,_./-')
    QUERY=$(printf '%s' "$QUERY" | tr -cd 'A-Za-z0-9 _./&(),+-' | cut -c1-200)
    case "$DEST" in
        /share/VIDEO/*) case "$DEST" in *..*) DEST=/share/VIDEO/_duplicates ;; esac ;;
        *) DEST=/share/VIDEO/_duplicates ;;
    esac

    echo "running: $ACTION" > "$STATUS"
    {
        echo ""
        echo "=============================================================="
        echo "$(date '+%Y-%m-%d %H:%M:%S')  $ACTION"
        echo "=============================================================="
    } >> "$LOG"

    case "$ACTION" in
        plan)
            KEEP_SIDE="$KEEP_SIDE" DEST="$DEST" sh /share/Web/dedupe.sh >> "$LOG" 2>&1
            ;;
        apply)
            KEEP_SIDE="$KEEP_SIDE" DEST="$DEST" sh /share/Web/dedupe.sh --apply >> "$LOG" 2>&1
            refresh_state
            ;;
        undo)
            sh /share/Web/dedupe.sh --undo >> "$LOG" 2>&1
            refresh_state
            ;;
        organize)
            DEST_ROOT="$DEST" INCLUDE_STILLS="$STILLS" EXCLUDE="$EXCLUDE" \
                sh /share/Web/organize.sh >> "$LOG" 2>&1
            ;;
        organize-apply)
            DEST_ROOT="$DEST" INCLUDE_STILLS="$STILLS" EXCLUDE="$EXCLUDE" \
                sh /share/Web/organize.sh --apply >> "$LOG" 2>&1
            refresh_state
            ;;
        organize-undo)
            sh /share/Web/organize.sh --undo >> "$LOG" 2>&1
            refresh_state
            ;;
        reindex)
            echo "walking the share once — file list and search index together" >> "$LOG"
            build_index
            ;;
        cachescan)
            echo "scanning for Premiere cache files..." >> "$LOG"
            find -L /share/VIDEO/ \( -iname '*.pek' -o -iname '*.cfa' -o -iname '*.ims' \) -type f > /share/Web/cache-files.txt 2>/dev/null
            echo "$(wc -l < /share/Web/cache-files.txt) cache files" >> "$LOG"
            awk '{print}' /share/Web/cache-files.txt | xargs -r du -ch 2>/dev/null | tail -1 >> "$LOG"
            ;;
        cacheclean)
            echo "moving cache files to /share/VIDEO/_duplicates/_media-cache ..." >> "$LOG"
            n=0
            # ponytail: same shape as dedupe-moves.tsv, so one undo path fits both
            : > /share/Web/cache-moves.tsv
            while IFS= read -r f; do
                [ -f "$f" ] || continue
                rel=${f#/share/VIDEO/}
                d="/share/VIDEO/_duplicates/_media-cache/$rel"
                if mkdir -p "$(dirname "$d")" && mv -n "$f" "$d"; then
                    printf '%s\t%s\n' "$f" "$d" >> /share/Web/cache-moves.tsv
                    n=$((n + 1))
                fi
            done < /share/Web/cache-files.txt
            echo "moved $n cache files" >> "$LOG"
            refresh_state
            ;;
        verify)
            # Proof before deletion. Walks every move and checks, on disk now,
            # that the copy we kept is still there at the exact size the scan
            # measured. A filename search cannot do this: cameras reuse names,
            # so 58 files called DJI_0002.MOV is normal and means nothing.
            # With QUERY set, explains one file instead of checking all of them.
            if [ -n "$QUERY" ]; then
                sh /share/Web/verify.sh "$QUERY" >> "$LOG" 2>&1
            else
                sh /share/Web/verify.sh >> "$LOG" 2>&1
            fi
            ;;
        df)
            df -h /share/VIDEO >> "$LOG" 2>&1
            ;;
        gpu-test)
            [ -n "$DOCKER" ] || echo "Container Station's docker was not found, so the container part of this test cannot run." >> "$LOG"
            # Can this machine make video on its video chip? Changes nothing: it
            # makes a 20-second test picture and keeps none of it. It tries the
            # NAS's own ffmpeg, then a complete one in a container
            # (linuxserver/ffmpeg) with each of Intel's two drivers — i965 for
            # chips from before 2015, iHD for newer — and software for comparison.
            # The result also goes to the VIDEO share, where the helper side can read it.
            out=/share/Web/gpu-test.txt
            SRC="-f lavfi -i testsrc2=size=1920x1080:rate=30:duration=20"
            enc() {
                lab=$1; shift; t0=$(date +%s)
                if msg=$("$@" 2>&1); then echo "  $lab: WORKS — 20 s of 1080p made in $(( $(date +%s) - t0 )) s"
                else echo "  $lab: does not work — $(printf '%s' "$msg" | tail -2 | tr '\n' ' ' | cut -c1-240)"; fi
            }
            {
                echo "Video chip test · $(date '+%Y-%m-%d %H:%M')"
                echo "processor:$(grep -m1 'model name' /proc/cpuinfo | cut -d: -f2-)"
                if [ -e /dev/dri/renderD128 ]; then echo "video chip device: $(ls /dev/dri | tr '\n' ' ')"
                else echo "video chip device: none — the system offers no video chip to programs"; fi
                echo "The NAS's own ffmpeg:"
                for c in /usr/local/medialibrary/bin/ffmpeg /usr/local/bin/ffmpeg /opt/bin/ffmpeg /mnt/ext/opt/medialibrary/bin/ffmpeg; do
                    [ -x "$c" ] || continue
                    if "$c" -hide_banner -encoders 2>/dev/null | grep -q h264_vaapi; then echo "  $c: has hardware encoding"
                    else echo "  $c: built without hardware encoding"; fi
                done
                echo "A complete ffmpeg in a container (linuxserver/ffmpeg):"
                "$DOCKER" pull -q linuxserver/ffmpeg >/dev/null 2>&1 || echo "  could not download it (is the NAS online? is Container Station running?)"
                for drv in i965 iHD; do
                    enc "video chip, driver $drv" "$DOCKER" run --rm --device /dev/dri:/dev/dri -e LIBVA_DRIVER_NAME=$drv \
                        linuxserver/ffmpeg -hide_banner -loglevel error -vaapi_device /dev/dri/renderD128 $SRC \
                        -vf 'format=nv12,hwupload' -c:v h264_vaapi -b:v 2M -f null -
                done
                enc "software (libx264), for comparison" "$DOCKER" run --rm linuxserver/ffmpeg -hide_banner -loglevel error \
                    $SRC -c:v libx264 -preset veryfast -crf 23 -f null -
                # The real work: one of the archive's own clips, 30 seconds of it,
                # read, made 1080p and encoded — the way a proxy is made — and how
                # busy the processor was meanwhile (the chip's point is to free it).
                # The first big video in the file list that is really still there
                # (the list can be a day old; files move).
                clip=""
                TAB=$(printf '\t')
                while IFS="$TAB" read -r size path; do
                    [ "$size" -gt 100000000 ] 2>/dev/null || continue
                    case "$path" in */PROXIES/*|*/_rushes/*|*/_duplicates/*|*/@Recycle/*) continue ;; esac
                    case "$path" in *.[mM][pP]4|*.[mM][oO][vV]|*.[mM][xX][fF]|*.[mM][tT][sS]) ;; *) continue ;; esac
                    [ -f "$path" ] && { clip=$path; break; }
                done < /share/Web/manifest.tsv
                if [ -n "$clip" ]; then
                    echo "With a real clip: ${clip#/share/VIDEO/}"
                    echo "  $("$DOCKER" run --rm -v /share/VIDEO:/share/VIDEO:ro linuxserver/ffmpeg -hide_banner -i "$clip" 2>&1 | grep -m1 'Video:' | sed 's/^ *//' | cut -c1-150)"
                    busy() { awk '/^cpu /{print $2+$3+$4+$7+$8, $2+$3+$4+$5+$6+$7+$8}' /proc/stat; }
                    real() {
                        lab=$1; shift; set -- $(busy) "$@"; b0=$1; t0=$2; shift 2; s0=$(date +%s)
                        if msg=$("$@" 2>&1); then
                            set -- $(busy); echo "  $lab: WORKS — 30 s of footage in $(( $(date +%s) - s0 )) s, processor $(( 100 * ($1 - b0) / ($2 - t0 + 1) ))% busy"
                        else echo "  $lab: does not work — $(printf '%s' "$msg" | tail -2 | tr '\n' ' ' | cut -c1-200)"; fi
                    }
                    RUN="$DOCKER run --rm -v /share/VIDEO:/share/VIDEO:ro"
                    real "all on the chip (read, resize, encode)" $RUN --device /dev/dri:/dev/dri -e LIBVA_DRIVER_NAME=i965 linuxserver/ffmpeg \
                        -hide_banner -loglevel error -hwaccel vaapi -hwaccel_device /dev/dri/renderD128 -hwaccel_output_format vaapi \
                        -ss 5 -t 30 -i "$clip" -vf scale_vaapi=w=-2:h=1080 -c:v h264_vaapi -b:v 2M -an -f null -
                    real "read by the processor, resize and encode on the chip" $RUN --device /dev/dri:/dev/dri -e LIBVA_DRIVER_NAME=i965 linuxserver/ffmpeg \
                        -hide_banner -loglevel error -vaapi_device /dev/dri/renderD128 \
                        -ss 5 -t 30 -i "$clip" -vf 'format=nv12,hwupload,scale_vaapi=w=-2:h=1080' -c:v h264_vaapi -b:v 2M -an -f null -
                    real "all in software" $RUN linuxserver/ffmpeg -hide_banner -loglevel error \
                        -ss 5 -t 30 -i "$clip" -vf scale=-2:1080 -c:v libx264 -preset veryfast -crf 23 -an -f null -
                else
                    echo "With a real clip: none found in the file list"
                fi
            } > "$out" 2>&1
            cp "$out" /share/VIDEO/_rushes/gpu-test.txt 2>/dev/null
            cat "$out" >> "$LOG"
            ;;
        proxy-test)
            # Which proxy setting looks right on THIS machine's video chip? The
            # same 20 seconds of one real clip, made at several sizes and
            # bitrates exactly the way proxies are made, kept side by side in
            # _rushes/proxy-test with a still from each (and one from the
            # original, at the same moment) — to look at before a whole folder
            # is made. Changes nothing else. QUERY: a clip, or a folder (its
            # biggest video, usually the longest).
            out=/share/Web/proxy-test.txt
            T=/share/VIDEO/_rushes/proxy-test
            src="/share/VIDEO/$QUERY"
            clip=""
            if [ -f "$src" ]; then clip=$src
            elif [ -d "$src" ]; then
                clip=$(find "$src" -type f 2>/dev/null | grep -iE '\.(mp4|mov|mxf|mts|m4v)$' | grep -v '/PROXIES/' \
                       | while IFS= read -r f; do printf '%s\t%s\n' "$(stat -c %s "$f" 2>/dev/null || echo 0)" "$f"; done \
                       | sort -rn | head -1 | cut -f2-)
            fi
            D="$DOCKER run --rm --cpu-shares 256 -v /share/VIDEO:/share/VIDEO --device /dev/dri:/dev/dri -e LIBVA_DRIVER_NAME=i965 --entrypoint /usr/local/bin/ffmpeg linuxserver/ffmpeg -hide_banner -nostdin -y"
            {
                echo "Proxy settings test · $(date '+%Y-%m-%d %H:%M')"
                if [ -z "$DOCKER" ]; then echo "Container Station's docker was not found, so nothing could be made."
                elif [ -z "$clip" ]; then echo "No video found at ${QUERY:-(nothing chosen)}."
                else
                    mkdir -p "$T" && rm -f "$T"/*
                    W=/share/Web/proxy-test; mkdir -p "$W" && rm -f "$W"/*     # the stills again, for the page to show side by side
                    name=$(basename "$clip"); name=${name%.*}
                    info=$($D -i "$clip" 2>&1)
                    dur=$(printf '%s' "$info" | sed -n 's/.*Duration: \([0-9]*\):\([0-9]*\):\([0-9]*\).*/\1 \2 \3/p' | head -1 | awk '{print $1*3600+$2*60+$3}')
                    at=$(( ${dur:-0} > 45 ? ${dur:-0} * 3 / 10 : 0 ))       # 30% in: past the start, where the camera settles
                    echo "clip: ${clip#/share/VIDEO/}"
                    echo "  $(printf '%s' "$info" | grep -m1 'Video:' | sed 's/^ *//' | cut -c1-150)"
                    echo "20 seconds from ${at}s, each setting made on the video chip (the way proxies are made):"
                    $D -loglevel error -ss $(( at + 10 )) -i "$clip" -frames:v 1 -q:v 2 "$T/$name - 0 original - still.jpg" 2>/dev/null
                    cp "$T/$name - 0 original - still.jpg" "$W/original.jpg" 2>/dev/null
                    for s in "720 4M" "720 6M" "1080 4M" "1080 6M"; do   # below 4 Mbit/s is too rough to offer
                        set -- $s; h=$1; b=$2; m=$(( ${b%M} * 3 / 2 ))M; o="$T/$name - ${h}p ${b}.mp4"; t0=$(date +%s)
                        if $D -loglevel error -hwaccel vaapi -hwaccel_device /dev/dri/renderD128 -hwaccel_output_format vaapi -ss $at -t 20 -i "$clip" \
                               -vf "scale_vaapi=w=-2:h=$h" -c:v h264_vaapi -b:v $b -maxrate $m -c:a aac -b:a 128k -movflags +faststart "$o" 2>/dev/null \
                           || $D -loglevel error -vaapi_device /dev/dri/renderD128 -ss $at -t 20 -i "$clip" \
                               -vf "format=nv12,hwupload,scale_vaapi=w=-2:h=$h" -c:v h264_vaapi -b:v $b -maxrate $m -c:a aac -b:a 128k -movflags +faststart "$o" 2>/dev/null; then
                            $D -loglevel error -ss 10 -i "$o" -frames:v 1 -q:v 2 "$T/$name - ${h}p ${b} - still.jpg" 2>/dev/null
                            cp "$T/$name - ${h}p ${b} - still.jpg" "$W/$h-$b.jpg" 2>/dev/null
                            echo "  ${h}p at ${b}bit/s: $(( $(stat -c %s "$o") * 3 / 1000000 )) MB a minute · made in $(( $(date +%s) - t0 )) s"
                        else rm -f "$o"; echo "  ${h}p at ${b}bit/s: the chip could not make it"; fi
                    done
                    # In software (x264): an older chip's picture is often beaten by it,
                    # at the cost of the processor; a newer chip may not be.
                    for h in 720 1080; do
                        o="$T/$name - ${h}p software.mp4"; t0=$(date +%s)
                        if $D -loglevel error -ss $at -t 20 -i "$clip" -vf scale=-2:$h -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 128k -movflags +faststart "$o" 2>/dev/null; then
                            $D -loglevel error -ss 10 -i "$o" -frames:v 1 -q:v 2 "$T/$name - ${h}p software - still.jpg" 2>/dev/null
                            cp "$T/$name - ${h}p software - still.jpg" "$W/$h-sw.jpg" 2>/dev/null
                            echo "  ${h}p in software: $(( $(stat -c %s "$o") * 3 / 1000000 )) MB a minute · made in $(( $(date +%s) - t0 )) s"
                        fi
                    done
                    chown -R "$(stat -c %u:%g /share/VIDEO)" "$T" 2>/dev/null
                    echo "Look at them in _rushes/proxy-test on VIDEO: each clip, and a still from each at the same moment."
                    [ -f /share/Web/proxy.pid ] && echo "(Proxies were being made meanwhile, so the times are slower than on a quiet chip.)"
                fi
            } > "$out" 2>&1
            cat "$out" >> "$LOG"
            ;;
        proxy-plan)
            PROXY_ONLY="$QUERY" sh /share/Web/proxy.sh >> "$LOG" 2>&1
            ;;
        proxy-build)
            # Hours to days, so in the background: the runner stays free for
            # everything else (deploys, search, other jobs). Resumable: every
            # proxy already made is skipped, so stopping and starting costs nothing.
            if proxy_alive; then
                echo "proxies are already being made (process $pid, detail in proxy.log)" >> "$LOG"
            else
                PROXY_ONLY="$QUERY" sh /share/Web/proxy.sh --build < /dev/null >> /share/Web/proxy.log 2>&1 &
                echo $! > /share/Web/proxy.pid
                echo "making proxies in the background: progress in Manage → Describe, detail in proxy.log" >> "$LOG"
            fi
            ;;
        proxy-remake)
            # A folder's proxies thrown away and made again (Manage → Describe →
            # Remake proxies), after the Proxy setting changed. Here, because this
            # machine may delete them: in Finder they belong to whoever owns the
            # footage. Only inside PROXIES, only a folder, never while a build runs.
            case "$QUERY" in ""|/*|*..*) echo "  refused remake of '$QUERY' (not a folder in the archive)" >> "$LOG" ;;
            *)
                if proxy_alive; then
                    echo "  not remade: proxies are being made right now (process $pid)" >> "$LOG"
                elif [ -d "/share/VIDEO/PROXIES/$QUERY" ]; then
                    n=$(find "/share/VIDEO/PROXIES/$QUERY" -type f -name '*.mp4' | wc -l)
                    rm -rf "/share/VIDEO/PROXIES/$QUERY"
                    echo "  threw away $n proxies of $QUERY — making them again with the setting in use" >> "$LOG"
                    PROXY_ONLY="$QUERY" sh /share/Web/proxy.sh --build < /dev/null >> /share/Web/proxy.log 2>&1 &
                    echo $! > /share/Web/proxy.pid
                else
                    echo "  $QUERY has no proxies to remake — making them" >> "$LOG"
                    PROXY_ONLY="$QUERY" sh /share/Web/proxy.sh --build < /dev/null >> /share/Web/proxy.log 2>&1 &
                    echo $! > /share/Web/proxy.pid
                fi ;;
            esac
            ;;
        proxy-stop)
            if proxy_alive; then
                kill "$pid"; echo "asked the proxy build to stop" >> "$LOG"
            else
                echo "no proxy build was running" >> "$LOG"
            fi
            ;;
        holding)
            # how much sits in the holding folder — what emptying it would give back
            du -sk /share/VIDEO/_duplicates 2>/dev/null | cut -f1 > /share/Web/holding-kb.txt
            echo "holding folder: $(du -sh /share/VIDEO/_duplicates 2>/dev/null | cut -f1)" >> "$LOG"
            ;;
        manifest)
            # size + path for every file, so the Mac can decide what is already
            # here without reading 1.2M files over SMB. Minutes, locally.
            echo "walking the share once — file list and search index together" >> "$LOG"
            build_index
            n=$(wc -l < /share/Web/manifest.tsv)
            if [ "$n" -lt 1000 ]; then
                echo "PROBLEM: far too few. Do not run the Mac against this." >> "$LOG"
                echo "  what find printed:" >> "$LOG"
                echo "    /share/VIDEO is: $(ls -ld /share/VIDEO 2>&1)" >> "$LOG"
                echo "    it really points at: $(readlink -f /share/VIDEO 2>&1)" >> "$LOG"
                echo "    top level there:" >> "$LOG"
                find -L /share/VIDEO -maxdepth 1 >> "$LOG" 2>&1
            fi
            ;;
        scan)
            # Czkawka's CLI lives in the same container as its GUI. /share/VIDEO is
            # mounted there as /storage, and /config is the volume we already read
            # results from. Hours for 41 TB — the lock keeps other jobs waiting.
            echo "hashing the whole share — this takes hours" >> "$LOG"
            "$DOCKER" exec czkawka czkawka_cli dup \
                -d /storage \
                -e /storage/_duplicates -e /storage/@Recycle \
                -f /config/results_duplicates.txt > /share/Web/scan-output.txt 2>&1
            tail -5 /share/Web/scan-output.txt >> "$LOG"
            CZK=/share/CACHEDEV1_DATA/Container/container-station-data/lib/docker/volumes/b01e685a5288fd37726297cc5c5c7501933c0e441cea07d9527a7a14c01ead5c/_data/results_duplicates.txt
            if [ -f "$CZK" ]; then
                cp "$CZK" /share/Web/results_duplicates.txt
                echo "scan done: $(grep -c '^\"' /share/Web/results_duplicates.txt) duplicate files listed" >> "$LOG"
            else
                echo "scan finished but no results file at $CZK" >> "$LOG"
            fi
            ;;
        update-scripts)
            # Only what the admin approved in Manage: each name with the
            # fingerprint it had then. A file changed since is refused.
            # Copied first (within the time limit), then the COPY is checked, so
            # what is installed is exactly what was approved, even if the file
            # on the share changes meanwhile.
            for s in $SCRIPTS; do
                name=${s%%:*}; want=${s#*:}
                case "$name" in *[!a-z0-9.-]*|.*|*/*|"") echo "  refused $name (not a script name)" >> "$LOG"; continue ;; esac
                case "$name" in *.sh) ;; *) echo "  refused $name (not a .sh)" >> "$LOG"; continue ;; esac
                tmp="/share/Web/.$name.new"
                if ! v cp "/share/VIDEO/_rushes/scripts/$name" "$tmp" || [ "$(sha256sum "$tmp" | cut -d' ' -f1)" != "$want" ]; then
                    rm -f "$tmp"; echo "  refused $name: it changed after it was approved (or could not be read)" >> "$LOG"; continue
                fi
                # renamed into place: a script that is running keeps its old copy
                chmod 755 "$tmp" && mv "$tmp" "/share/Web/$name" && echo "  installed $name" >> "$LOG" || echo "  could not install $name" >> "$LOG"
            done
            for s in $PAGES; do
                rel=${s%%:*}; want=${s#*:}
                page_ok "$rel" || { echo "  refused page $rel (not a page Rushes has)" >> "$LOG"; continue; }
                mkdir -p "/share/Web/$(dirname "$rel")"
                tmp="/share/Web/$rel.new"
                if ! v cp "$DROP/$rel" "$tmp" || [ "$(sha256sum "$tmp" | cut -d' ' -f1)" != "$want" ]; then
                    rm -f "$tmp"; echo "  refused page $rel: it changed after it was approved (or could not be read)" >> "$LOG"; continue
                fi
                chmod 644 "$tmp" && mv "$tmp" "/share/Web/$rel" && echo "  installed page $rel" >> "$LOG" \
                    && v rm -f "$DROP/$rel" || echo "  could not install page $rel" >> "$LOG"
            done
            touch /share/Web/survey-now          # what is still waiting, looked at again next minute
            ;;
        reset-breaker)
            # Try again (Manage): VIDEO may be reached by itself again.
            rm -f "$VTRIP" "$VSTALL"
            echo "  VIDEO may be reached again — the next minute tries it" >> "$LOG"
            touch /share/Web/survey-now
            ;;
        *)
            echo "unknown action: $ACTION" >> "$LOG"
            ;;
    esac

    echo "--- finished $(date '+%H:%M:%S') ---" >> "$LOG"
    echo "idle" > "$STATUS"
done

[ -f "$STATUS" ] || echo "idle" > "$STATUS"

# Reconcile newer inventories even when nobody has the browser open. A failed
# attempt retains the old catalog and is retried by the next scheduled run.
# Set RUSHES_URL when the web application is served from a different address.
# Paused, or VIDEO not answering: only what lives on Web is brought in
# (video=0); the descriptions and proxy checks wait for VIDEO.
IMP=import.php; may_v || IMP='import.php?video=0'
if command -v curl >/dev/null 2>&1; then
    SYNC=$(curl --silent --show-error --fail --max-time 3600 "${RUSHES_URL:-http://127.0.0.1}/db/$IMP" 2>&1)
elif command -v wget >/dev/null 2>&1; then
    SYNC=$(wget -q -O - "${RUSHES_URL:-http://127.0.0.1}/db/$IMP" 2>&1)
else
    SYNC="Search update needs curl or wget on the archive host"
fi
# Every minute it answers "current" when nothing changed. That is not news:
# written to the log each time, it buried the real jobs in a wall of it.
case "$SYNC" in
    ''|*'"state":"current"'*) ;;
    *) log "$(date '+%Y-%m-%d %H:%M:%S')  search update: $SYNC" ;;
esac

# RISKS.md #7: Rushes copies its database once a day (db-copy.sqlite, only when
# it checks out); here that copy goes onto VIDEO, one per weekday, so a week of
# them sits in _rushes/db-copies. Time-limited like every touch of VIDEO, with
# room for a big file: ten minutes.
DBC=$(head -1 /share/Web/db-copy.path 2>/dev/null)
case "$DBC" in /*/db-copy.sqlite) ;; *) DBC=/share/Web/db-copy.sqlite ;; esac
# A function, not "sh -c" with the path written into it: the path comes from a
# file PHP writes, and must never be read as shell code by this root script.
dbcopy() {
    mkdir -p /share/VIDEO/_rushes/db-copies \
        && cp "$DBC" "/share/VIDEO/_rushes/db-copies/rushes-$DAY.sqlite.part" \
        && mv -f "/share/VIDEO/_rushes/db-copies/rushes-$DAY.sqlite.part" "/share/VIDEO/_rushes/db-copies/rushes-$DAY.sqlite"
}
if [ -f "$DBC" ] && { [ ! -f /share/Web/db-copied ] || [ "$DBC" -nt /share/Web/db-copied ]; } && may_v; then
    DAY=$(date +%a); VL0=$VLIMIT; VLIMIT=600
    if v dbcopy; then
        touch /share/Web/db-copied
        log "$(date '+%Y-%m-%d %H:%M:%S')  database copied to _rushes/db-copies/rushes-$DAY.sqlite"
    fi
    VLIMIT=$VL0
fi

# Can anything private be downloaded? (HOW-IT-WORKS.md → Security.) Once a
# day, this machine's own web server is asked for the files .htaccess forbids.
# Any it hands out are listed in exposed.txt, and Overview says so in red.
if [ ! -f /share/Web/exposed.txt ] || [ -n "$(find /share/Web/exposed.txt -mmin +1440 2>/dev/null)" ]; then
    : > /share/Web/exposed.txt.new
    if command -v curl >/dev/null 2>&1; then
        for f in rushes.sqlite db-copy.sqlite .adminpass ingest-queue.tsv; do
            [ -f "/share/Web/$f" ] || continue
            code=$(curl -s -o /dev/null -m 5 -w '%{http_code}' "${RUSHES_URL:-http://127.0.0.1}/$f" 2>/dev/null)
            [ "$code" = 200 ] && echo "$f" >> /share/Web/exposed.txt.new
        done
    fi
    mv -f /share/Web/exposed.txt.new /share/Web/exposed.txt
fi
