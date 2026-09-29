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

Q=/share/Web/queue
LOG=/share/Web/job.log
STATUS=/share/Web/job-status.txt
LOCKDIR=/tmp/.archive-runner.lock

mkdir -p "$Q"

# Heartbeat first, before the lock, so it keeps ticking even while a long job
# holds it. Without this the page cannot tell a busy runner from a dead one —
# which is exactly how a broken cron went unnoticed for forty minutes.
date '+%s' > /share/Web/runner-alive.txt
df -P /share/VIDEO | tail -1 > /share/Web/disk.txt

# The Mac writes ingest progress onto the VIDEO share (the only place both
# machines can reach). Mirror it into the web folder so the page can read it
# without the Mac needing any extra mount or service.
cp /share/VIDEO/_rushes/ingest-status.tsv   /share/Web/ 2>/dev/null
cp /share/VIDEO/_rushes/ingest-sections.tsv /share/Web/ 2>/dev/null
cp /share/VIDEO/_rushes/ingest-history.tsv  /share/Web/ 2>/dev/null

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
if [ -d "$DROP" ]; then
    find "$DROP" -type f 2>/dev/null | while read -r f; do
        rel=${f#$DROP/}
        case "$rel" in
            */*/*)      log "  refused $rel (too deep)";        rm -f "$f"; continue ;;
            db/*|*/*)   case "$rel" in db/*) ;; *) log "  refused $rel (unknown folder)"; rm -f "$f"; continue ;; esac ;;
        esac
        case "$rel" in
            *.php|*.html|*.js|*.css|*.json|db/*.php) ;;
            *) log "  refused $rel (not a page)"; rm -f "$f"; continue ;;
        esac
        mkdir -p "/share/Web/$(dirname "$rel")"
        if cp "$f" "/share/Web/$rel"; then
            chmod 644 "/share/Web/$rel"
            log "  deployed $rel ($(wc -c < "$f") bytes)"
            rm -f "$f"
        else
            log "  could not deploy $rel"
        fi
    done
fi

# Transfers add files incrementally through landed.php. A finished folder no
# longer triggers another full archive walk.

# ponytail: mkdir is the portable atomic lock; busybox has no flock
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
    rm -f "$job"

    # ---- validate everything; never trust the queue file ----
    case "$KEEP_SIDE" in project|card|short|oldest) ;; *) KEEP_SIDE=project ;; esac
    case "$STILLS" in 1) ;; *) STILLS=0 ;; esac
    # exclusions are plain relative folder names; strip anything else
    EXCLUDE=$(printf '%s' "$EXCLUDE" | tr -cd 'A-Za-z0-9 ,_./-')
    QUERY=$(printf '%s' "$QUERY" | tr -cd 'A-Za-z0-9 _./-' | cut -c1-200)
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
        proxy-plan)
            sh /share/Web/proxy.sh >> "$LOG" 2>&1
            ;;
        proxy-build)
            # Hours to days depending on how much is missing, but resumable:
            # every proxy that already exists is skipped, so stopping and
            # starting again costs nothing.
            sh /share/Web/proxy.sh --build >> "$LOG" 2>&1
            refresh_state
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
            docker exec czkawka czkawka_cli dup \
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
if command -v curl >/dev/null 2>&1; then
    curl --silent --show-error --fail --max-time 3600 "${RUSHES_URL:-http://127.0.0.1}/db/import.php" >> "$LOG" 2>&1
elif command -v wget >/dev/null 2>&1; then
    wget -q -O - "${RUSHES_URL:-http://127.0.0.1}/db/import.php" >> "$LOG" 2>&1
else
    echo "Search update needs curl or wget on the archive host" >> "$LOG"
fi
