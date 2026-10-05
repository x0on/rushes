#!/bin/sh
# runner.sh — executes jobs queued by the admin page. Runs as root from cron,
# every minute. The web page never touches the filesystem itself: it drops a
# job file, this picks it up. That separation is the security model, and it
# holds only if nobody else can write to the Web share: this file runs as root,
# and anyone who can replace it, or drop a job in queue/, can act as root.
# Give write access to the Web share to the administrator only (INSTALL.md).
#
# Install:
#   mkdir -p /share/Web/queue && chmod 777 /share/Web/queue
#   echo "* * * * * sh /share/Web/runner.sh" >> /etc/config/crontab
#   crontab /etc/config/crontab && /etc/init.d/crond.sh restart
#
# Optional second line — the duplicate scan takes hours and should never run
# during the working day (Rushes does not add this itself). Sunday 02:00:
#   0 2 * * 0 printf 'ACTION=scan\n' > /share/Web/queue/weekly.job

# The off switch: a file called STOP in the web folder (Manage → What runs by
# itself → Stop, or File Station: Web → create a folder or file named STOP) and the runner does nothing at all, every
# minute, until it is removed. For a disk rebuild, or anything else where the
# archive machine must be left alone. Checked before anything else is touched.
# Where things are. The web folder is the one this script lives in when it
# holds settings.json; otherwise the QNAP's. The archive comes from Setup, via
# archive-path.txt (written by Rushes when settings are saved), checked here:
# an absolute path of plain characters, at least two folders deep, never a
# system folder, and a folder that exists. Anything else: the QNAP's.
WEB=/share/Web
d=$(dirname "$0")
case "$d" in *[!A-Za-z0-9._/-]*) ;; /?*) [ -f "$d/settings.json" ] && WEB=$d ;; esac
ARCH=$(head -1 "$WEB/archive-path.txt" 2>/dev/null)
case "$ARCH" in
    # only where data volumes live (QNAP /share, Synology /volume1, Linux
    # /srv /mnt /media /data), never a hidden folder, a QNAP system folder, or
    # the web folder
    *[!A-Za-z0-9._/-]*|*..*|*/.*|/mnt/HDA_ROOT*|/share/CACHEDEV*_DATA/.*|"$WEB"|"$WEB"/*) ARCH= ;;
    /share/?*|/volume[0-9]*/?*|/srv/?*|/mnt/?*|/media/?*|/data/?*) [ -d "$ARCH" ] || ARCH= ;;
    *) ARCH= ;;
esac
[ -n "$ARCH" ] || ARCH=/share/VIDEO
export WEB ARCH

[ -e $WEB/STOP ] && exit 0

Q=$WEB/queue
LOG=$WEB/job.log
log() { printf '%s\n' "$*" >> "$LOG"; }     # one line into the job log
STATUS=$WEB/job-status.txt
LOCKDIR=/tmp/.archive-runner.lock

mkdir -p "$Q"

# Heartbeat first, before the lock, so it keeps ticking even while a long job
# holds it. Without this the page cannot tell a busy runner from a dead one —
# which is exactly how a broken cron went unnoticed for forty minutes.
date '+%s' > $WEB/runner-alive.txt

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
# What one minute's turn costs the machine, said in the web folder (Manage shows
# it): the machine's load (1, 5, 15 min) and how long this turn took.
T0=$(date +%s)
trap 'rm -rf "$TICK"' EXIT
trap 'exit 130' INT TERM          # stopped: leave (the EXIT trap tidies up), never carry on

# Paused from Manage (Pause copying): paused is paused. Nothing is read from or
# written to VIDEO by itself — no status mirrored, no page deployed, no proxies
# started. Only what someone asks for in Manage still runs.
PAUSED=0
grep -q '"paused":true' $WEB/helper-control.json 2>/dev/null && PAUSED=1

# ── reaching VIDEO: with a time limit, and a breaker ────────────────────────
# Every step that reads or writes the VIDEO share goes through v(). It starts
# the step and waits at most VLIMIT seconds. A step that has not finished by
# then is walked away from — not waited for: a process stuck on a dying disk
# often cannot even be stopped — and counted. Three in a row and the breaker
# trips: nothing touches VIDEO by itself until a person presses Try again in
# Manage (which removes video-tripped.txt). A minute with no stall resets the count.
VLIMIT=${VLIMIT:-20}
VSTALL=$WEB/video-stalls.txt
VTRIP=$WEB/video-tripped.txt
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

may_v && v sh -c 'df -P $ARCH | tail -1 > $WEB/disk.txt'

# /tmp on a QNAP is a 64 MB RAM disk shared with the system. Give it room (it
# only uses memory for what is actually in it), and say how full it is.
tmp_kb=$(df -Pk /tmp | awk 'NR==2 {print $2}')
if [ -n "$tmp_kb" ] && [ "$tmp_kb" -lt 262144 ]; then
    mount -o remount,size=256M /tmp 2>/dev/null && echo "$(date '+%Y-%m-%d %H:%M:%S')  gave /tmp 256 MB (it had $((tmp_kb / 1024)) MB)" >> "$LOG"
fi
df -P /tmp | tail -1 > $WEB/tmp-disk.txt

# The built-in helper: when Setup says the helper runs on this machine, keep
# it running. Started again within a minute if it stops; its output goes to
# /share/Web/helper.log, which is kept small.
RUSHES="${RUSHES_URL:-http://127.0.0.1}"
copyhelper() {
    rm -rf "$WEB/helper-code.new"; mkdir -p "$WEB/helper-code.new" || return 1
    for n in ingest.py transfer_state.py analyze.py release.py release.sig; do
        [ -f "$ARCH/_rushes/$n" ] && cp "$ARCH/_rushes/$n" "$WEB/helper-code.new/$n"
    done
    return 0
}
if [ "$(curl -fsS --max-time 5 "$RUSHES/db/helper.php?builtin" 2>/dev/null)" = "yes" ]; then
    PY=$(command -v python3 2>/dev/null)
    pid=$(cat $WEB/helper.pid 2>/dev/null)
    if [ -z "$PY" ]; then
        echo "no-python" > $WEB/helper-builtin.txt
    elif [ -n "$pid" ] && grep -q ingest.py "/proc/$pid/cmdline" 2>/dev/null; then
        # that number really is the helper (a number can be given to another program later)
        echo "running" > $WEB/helper-builtin.txt
    elif ! may_v; then
        # Paused, or VIDEO stopped answering: the helper lives on VIDEO, so it
        # is not started from there now. It starts again after Resume / Try again.
        echo "waiting" > $WEB/helper-builtin.txt
    else
        # It runs with full rights, so only a signed release (release.sig,
        # checked by the release.py installed here as an approved script),
        # from a copy in the web folder: what was checked is what runs.
        HC=$WEB/helper-code
        if [ ! -f $WEB/release.py ]; then
            echo "unsigned" > $WEB/helper-builtin.txt
            echo "$(date '+%Y-%m-%d %H:%M:%S')  built-in helper not started: release.py is not installed (Manage → What runs by itself → Check now, then Install it)" >> "$LOG"
        elif ! v copyhelper; then
            echo "waiting" > $WEB/helper-builtin.txt
        elif ! "$PY" $WEB/release.py verify "$HC.new" "$HC" >> "$LOG" 2>&1; then
            echo "unsigned" > $WEB/helper-builtin.txt
            echo "$(date '+%Y-%m-%d %H:%M:%S')  built-in helper not started: the code in _rushes is not a signed release" >> "$LOG"
        else
            rm -rf "$HC"; mv "$HC.new" "$HC"
            RUSHES_LOG=$WEB/helper.log "$PY" -u $HC/ingest.py --watch --url "$RUSHES" --service >> $WEB/helper.log 2>&1 &
            echo $! > $WEB/helper.pid
            echo "started" > $WEB/helper-builtin.txt
            echo "$(date '+%Y-%m-%d %H:%M:%S')  started the built-in helper (signed release)" >> "$LOG"
        fi
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
proxy_alive() { pid=$(cat $WEB/proxy.pid 2>/dev/null); [ -n "$pid" ] && grep -q proxy.sh "/proc/$pid/cmdline" 2>/dev/null; }
NEXT=$(head -1 $WEB/proxy-next.txt 2>/dev/null | tr -cd 'A-Za-z0-9 _./&(),+\200-\377-' | cut -c1-200)
case "$NEXT" in *..*) NEXT="" ;; esac
if [ -n "$NEXT" ] && may_v; then
    if ! proxy_alive; then
        rm -f $WEB/proxy-next.txt
        PROXY_ONLY="$NEXT" sh $WEB/proxy.sh --build < /dev/null >> $WEB/proxy.log 2>&1 &
        echo $! > $WEB/proxy.pid
        echo "$(date '+%Y-%m-%d %H:%M:%S')  making proxies for $NEXT" >> "$LOG"
    fi
fi

# ── pages dropped into _rushes/deploy ───────────────────────────────────────
# The one way a new page reaches the web folder from the archive share. Only
# these extensions, only files, and only one folder deep (db/): this folder is
# writable by anyone who can write to the share, so nothing in it is put in
# place without an admin's approval (below).
DROP=$ARCH/_rushes/deploy
# ── what is waiting to be installed ─────────────────────────────────────────
# Updated scripts (_rushes/scripts) and pages (_rushes/deploy) are never put in
# place by themselves: a page runs on this machine's web server and a script as
# root, so both wait for an admin's "Install it" in Manage, exactly as approved
# (HOW-IT-WORKS.md → Updates — pages used to go live within a minute of being dropped).
# What is waiting, with fingerprints, and the fingerprint of the helper's code,
# is looked at here when asked (below), within the time limit, and kept in the
# web folder — so no page ever has to read VIDEO itself.
page_ok() {      # only these, only one folder deep, only db/
    case "$1" in */*/*|.*|*/.*) return 1 ;; db/*|*/*) case "$1" in db/*) ;; *) return 1 ;; esac ;; esac
    case "$1" in *.php|*.html|*.js|*.css|*.json|favicon.ico|apple-touch-icon.png) return 0 ;; esac
    return 1
}
survey() {
    out=$WEB/waiting.tsv.new; : > "$out"
    for f in $ARCH/_rushes/scripts/*.sh $ARCH/_rushes/scripts/release.py; do
        [ -f "$f" ] || continue; n=${f##*/}
        case "$n" in *[!a-z0-9.-]*|.*) continue ;; esac
        h=$(sha256sum "$f" | cut -d' ' -f1); have=$(sha256sum "$WEB/$n" 2>/dev/null | cut -d' ' -f1)
        [ "$h" != "$have" ] && printf 'script\t%s\t%s\t%s\n' "$n" "$h" "$(stat -c %Y "$f")" >> "$out"
    done
    [ -d "$DROP" ] && find "$DROP" -type f 2>/dev/null | while read -r f; do
        rel=${f#$DROP/}; page_ok "$rel" || continue
        printf 'page\t%s\t%s\t%s\n' "$rel" "$(sha256sum "$f" | cut -d' ' -f1)" "$(stat -c %Y "$f")" >> "$out"
    done
    printf 'helper\t%s\n' "$(sha256sum $ARCH/_rushes/ingest.py 2>/dev/null | cut -c1-12)" >> "$out"
    # the helper's own files, whole fingerprints: helpers ask for these to update
    # themselves (helper.php?hash), so that question never reads VIDEO
    for n in ingest.py transfer_state.py analyze.py release.py; do
        [ -f "$ARCH/_rushes/$n" ] && printf 'helperfile\t%s\t%s\n' "$n" "$(sha256sum "$ARCH/_rushes/$n" | cut -d' ' -f1)" >> "$out"
    done
    mv "$out" $WEB/waiting.tsv
}
# New versions of Rushes' own files are looked for only when asked: Manage →
# "Check for updates" (survey-now), right after an install, or when there is
# no list yet. They only ever arrive because a person put them in _rushes, so
# looking on a timer would read VIDEO for nothing.
if may_v && { [ ! -f $WEB/waiting.tsv ] || [ -f $WEB/survey-now ]; }; then
    rm -f $WEB/survey-now
    v survey
fi


# What this turn cost the machine (load, seconds), and how its disks are: a
# RAID that is rebuilding or missing a disk, from the system's own account
# (/proc/mdstat, read only). Manage shows both.
printf '%s\t%s\t%s\n' "$(date +%s)" "$(cut -d' ' -f1-3 /proc/loadavg 2>/dev/null)" "$(( $(date +%s) - T0 ))" > $WEB/load.txt
[ -r /proc/mdstat ] && grep -E '^md|\[[U_]+\]|recovery|resync|reshape|check' /proc/mdstat > $WEB/raid.txt.new 2>/dev/null && mv -f $WEB/raid.txt.new $WEB/raid.txt
rm -rf "$TICK"; trap - EXIT INT TERM       # this minute's share work is done; long jobs have their own lock
# ponytail: mkdir is the portable atomic lock (busybox has no flock). The
# number inside lets a lock left by a run that was killed be taken over,
# instead of blocking everything until the machine restarts.
takelock() {     # $1 the lock, $2 what it is called in the log
    if ! mkdir "$1" 2>/dev/null; then
        old=$(cat "$1/pid" 2>/dev/null)
        if [ -n "$old" ]; then kill -0 "$old" 2>/dev/null && return 1
        elif [ -n "$(find "$1" -maxdepth 0 -mmin -2 2>/dev/null)" ]; then return 1     # just made, number not written yet
        fi
        rm -rf "$1"; mkdir "$1" 2>/dev/null || return 1
        log "$(date '+%Y-%m-%d %H:%M:%S')  took over $2 from a run that had stopped"
    fi
    echo $$ > "$1/pid"
}

# The upkeep that must not wait behind a long job (a duplicate scan takes
# hours): the search update, the daily database copy, the private-file check.
# It has its own lock, so it runs every minute whether or not a job is busy,
# and never twice at once.
MAINT=/tmp/.archive-runner-upkeep
upkeep() {
    # Logs that only grow: past 5 MB the oldest part goes, and the newest 1 MB
    # stays, in the same file, so a program writing to it carries on. (Records
    # that are needed for undo, or that Rushes reads from where it left off,
    # are not here: dedupe-moves.tsv, cache-moves.tsv, proxy-made.tsv.)
    for f in helper.log proxy.log proxy-built.tsv proxy-built.tsv.err proxy-speed.tsv proxy-failed.tsv; do
        f=$WEB/$f
        [ -f "$f" ] && [ "$(wc -c < "$f")" -gt 5000000 ] || continue
        tail -c 1000000 "$f" | sed 1d > "$f.tmp" && cat "$f.tmp" > "$f"; rm -f "$f.tmp"
        log "$(date '+%Y-%m-%d %H:%M:%S')  trimmed $(basename "$f") to its newest 1 MB"
    done
    # One line per proxy run, per folder: only each folder's last one is read.
    f=$WEB/proxy-folders.tsv
    if [ -f "$f" ] && [ "$(wc -c < "$f")" -gt 1000000 ]; then
        awk -F'\t' '{ if (!($1 in last)) order[++n] = $1; last[$1] = $0 }
                     END { for (i = 1; i <= n; i++) print last[order[i]] }' "$f" > "$f.tmp" && cat "$f.tmp" > "$f"; rm -f "$f.tmp"
    fi

    # Reconcile newer inventories even when nobody has the browser open. A failed
    # attempt retains the old catalog and is retried by the next scheduled run.
    # Set RUSHES_URL when the web application is served from a different address.
    # Two halves: what lives in the web folder, however long it takes; then what
    # reads VIDEO (new descriptions, the prepare list's proxy check), only when
    # VIDEO may be read, and through v() like every other touch of VIDEO, so a
    # dying disk is walked away from and counted towards the breaker.
    IMP_URL="${RUSHES_URL:-http://127.0.0.1}/db/import.php"
    if command -v curl >/dev/null 2>&1; then
        SYNC=$(curl --silent --show-error --fail --max-time 3600 "$IMP_URL?part=web" 2>&1)
        importv() { curl --silent --show-error --fail --max-time 110 "$IMP_URL?part=video" > $WEB/import-video.out 2>&1; }
        if may_v; then
            VL0=$VLIMIT; VLIMIT=${IMPORT_LIMIT:-120}
            v importv && SYNC="$SYNC $(cat $WEB/import-video.out 2>/dev/null)"
            VLIMIT=$VL0
        fi
    elif command -v wget >/dev/null 2>&1; then
        SYNC=$(wget -q -O - "$IMP_URL?part=web" 2>&1)
    else
        SYNC="Search update needs curl or wget on the archive host"
    fi
    # Every minute it answers "current" when nothing changed. That is not news:
    # written to the log each time, it buried the real jobs in a wall of it.
    case "$SYNC" in
        ''|*'"state":"current"'*) ;;
        *) log "$(date '+%Y-%m-%d %H:%M:%S')  search update: $SYNC" ;;
    esac

    # HOW-IT-WORKS.md → Projects in and out: once a day, the project folders
    # Rushes says have slept long enough (and are kept in the archive) are
    # moved aside on the Projects share, into "_Moved aside"; a Bring it back
    # pressed in Manage is done within a minute. A rename on the same share:
    # nothing is copied, nothing is deleted, nothing is written over.
    PROJ=$(head -1 "$WEB/projects-path.txt" 2>/dev/null)
    case "$PROJ" in
        *[!A-Za-z0-9._/-]*|*..*|*/.*|/mnt/HDA_ROOT*|"$WEB"|"$WEB"/*|"$ARCH"|"$ARCH"/*) PROJ= ;;
        /share/?*|/volume[0-9]*/?*|/srv/?*|/mnt/?*|/media/?*|/data/?*) [ -d "$PROJ" ] || PROJ= ;;
        *) PROJ= ;;
    esac
    if [ -n "$PROJ" ] && { [ -s $WEB/projects-back.txt ] || [ "$(cat $WEB/projects-day.txt 2>/dev/null)" != "$(date +%F)" ]; }; then
        date +%F > $WEB/projects-day.txt
        PURL="${RUSHES_URL:-http://127.0.0.1}/db/projects.php"
        curl -fsS --max-time 30 "$PURL?plan" > $WEB/projects-plan.tsv 2>/dev/null || : > $WEB/projects-plan.tsv
        : > $WEB/projects-moved.tsv
        T=$(printf '\t')
        while IFS="$T" read -r what rel; do
            case "$rel" in ''|/*|*..*|.*|*/.*) continue ;; esac
            [ "$what" = aside ] && [ "$PAUSED" = 1 ] && continue     # paused: nothing moves by itself (Bring it back still does)
            case "$what" in
                aside) from="$PROJ/$rel"; to="$PROJ/_Moved aside/$rel" ;;
                back)  from="$PROJ/_Moved aside/$rel"; to="$PROJ/$rel" ;;
                *) continue ;;
            esac
            if [ ! -d "$from" ]; then why="not there"
            elif [ -e "$to" ]; then why="something is already where it would go; left where it was"
            elif mkdir -p "$(dirname "$to")" && mv "$from" "$to"; then why=ok
            else why="could not be moved"; fi
            printf '%s\t%s\t%s\n' "$what" "$rel" "$why" >> $WEB/projects-moved.tsv
            log "$(date '+%Y-%m-%d %H:%M:%S')  project folder $rel: $([ "$what" = aside ] && echo 'moved aside' || echo 'brought back') — $why"
        done < $WEB/projects-plan.tsv
        [ -s $WEB/projects-moved.tsv ] && curl -fsS --max-time 30 --data-urlencode "action=moved" \
            --data-urlencode "lines@$WEB/projects-moved.tsv" "$PURL" >/dev/null 2>&1
    fi

    # HOW-IT-WORKS.md → Rushes' own backups: Rushes copies its database once a day (db-copy.sqlite, only when
    # it checks out); here that copy goes onto VIDEO, one per weekday, so a week of
    # them sits in _rushes/db-copies. Time-limited like every touch of VIDEO, with
    # room for a big file: ten minutes.
    DBC=$(head -1 $WEB/db-copy.path 2>/dev/null)
    case "$DBC" in /*/db-copy.sqlite) ;; *) DBC=$WEB/db-copy.sqlite ;; esac
    # A function, not "sh -c" with the path written into it: the path comes from a
    # file PHP writes, and must never be read as shell code by this root script.
    dbcopy() {
        mkdir -p $ARCH/_rushes/db-copies \
            && cp "$DBC" "$ARCH/_rushes/db-copies/rushes-$DAY.sqlite.part" \
            && mv -f "$ARCH/_rushes/db-copies/rushes-$DAY.sqlite.part" "$ARCH/_rushes/db-copies/rushes-$DAY.sqlite"
    }
    if [ -f "$DBC" ] && { [ ! -f $WEB/db-copied ] || [ "$DBC" -nt $WEB/db-copied ]; } && may_v; then
        DAY=$(date +%a); VL0=$VLIMIT; VLIMIT=600
        if v dbcopy; then
            touch $WEB/db-copied
            log "$(date '+%Y-%m-%d %H:%M:%S')  database copied to _rushes/db-copies/rushes-$DAY.sqlite"
        fi
        VLIMIT=$VL0
    fi

    # Can anything private be downloaded? (HOW-IT-WORKS.md → Security.) Once a
    # day, this machine's own web server is asked for the files .htaccess forbids.
    # Any it hands out are listed in exposed.txt, and Overview says so in red.
    if [ ! -f $WEB/exposed.txt ] || [ -n "$(find $WEB/exposed.txt -mmin +1440 2>/dev/null)" ]; then
        : > $WEB/exposed.txt.new
        if command -v curl >/dev/null 2>&1; then
            for f in rushes.sqlite db-copy.sqlite ingest-queue.tsv helper-refused.tsv activity.tsv; do
                [ -f "$WEB/$f" ] || continue
                code=$(curl -s -o /dev/null -m 5 -w '%{http_code}' "${RUSHES_URL:-http://127.0.0.1}/$f" 2>/dev/null)
                [ "$code" = 200 ] && echo "$f" >> $WEB/exposed.txt.new
            done
        fi
        mv -f $WEB/exposed.txt.new $WEB/exposed.txt
    fi
}
upkeep_once() {
    takelock "$MAINT" "the upkeep lock" || return 0     # an earlier minute's upkeep is still at it
    upkeep; rm -rf "$MAINT"
    # A whole minute without a stall, its upkeep included: the count starts again.
    [ "$VSTALLED" = 0 ] && [ -f "$VSTALL" ] && rm -f "$VSTALL"
    return 0
}

# One job runner at a time. A minute that finds a job still running does the
# upkeep and leaves.
if ! takelock "$LOCKDIR" "the job lock"; then
    upkeep_once; exit 0
fi
trap 'rm -rf "$LOCKDIR"; [ "$(cat "$MAINT/pid" 2>/dev/null)" = "$$" ] && rm -rf "$MAINT"' EXIT
trap 'exit 130' INT TERM

# keep the log from growing forever
[ -f "$LOG" ] && [ "$(wc -c < "$LOG")" -gt 2000000 ] && tail -c 500000 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"


# QNAP's busybox find has no -printf, so it wrote an empty file and said
# nothing. An empty manifest is worse than none: it tells the Mac the archive
# is empty and every file is new. Try the fast way, check it worked, fall back.
build_manifest() {
    M=$WEB/manifest.tsv.new
    date +%s > $WEB/manifest-started.txt.new
    T=$(printf '\t')
    if find -L $ARCH -type f -not -path '*/@Recycle/*' \
        -printf '%s\t%p\n' > "$M" 2>/dev/null && [ -s "$M" ]; then
        method='find -printf'
    elif find -L $ARCH -type f -not -path '*/@Recycle/*' \
        -exec stat -c "%s${T}%n" {} + > "$M" 2>/dev/null && [ -s "$M" ]; then
        method='find -exec stat'
    else
        # Check the walk and every stat; a partial inventory must not replace
        # the last complete snapshot just because it contains some rows.
        P=$WEB/manifest-paths.tmp
        find -L $ARCH -type f -not -path '*/@Recycle/*' > "$P" 2>/dev/null || return 1
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
    # The same guard as the search catalogue's (sync.php): a list less than half
    # the size of the last one is a share that answered partly, not an archive
    # that lost half its files. The last list stays; the new one is kept aside.
    old=$(wc -l < $WEB/manifest.tsv 2>/dev/null || echo 0); new=$(wc -l < "$M")
    if [ "$old" -gt 1000 ] && [ "$new" -lt $((old / 2)) ]; then
        mv -f "$M" $WEB/manifest-rejected.tsv; rm -f $WEB/manifest-started.txt.new
        echo "refused: $new files listed where the last list had $old — kept the last list (the new one is manifest-rejected.tsv)"
        return 1
    fi
    mv $WEB/manifest-started.txt.new $WEB/manifest-started.txt
    mv "$M" $WEB/manifest.tsv
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
    m=$(build_manifest) || { echo "File list not replaced (${m:-the scan was interrupted}); keeping the previous one" >> "$LOG"; return 1; }
    n=$(wc -l < $WEB/manifest.tsv)
    if [ "$n" -lt 1000 ]; then
        echo "  file list came back with only $n files — keeping the old search index" >> "$LOG"
        echo "  (the archive should have hundreds of thousands; something is wrong)" >> "$LOG"
        return 1
    fi
    cut -f2 $WEB/manifest.tsv > $WEB/index.txt.new
    i=$(wc -l < $WEB/index.txt.new)
    if [ "$i" -eq "$n" ]; then
        mv $WEB/index.txt.new $WEB/index.txt
        echo "  file list and search index: $n files, one pass (via $m)" >> "$LOG"
    else
        rm -f $WEB/index.txt.new
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
    du -sk $ARCH/_duplicates 2>/dev/null | cut -f1 > $WEB/holding-kb.txt
    df -P $ARCH | tail -1 > $WEB/disk.txt
    echo "  free space: $(df -h $ARCH | tail -1 | awk '{print $4}')" >> "$LOG"
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
    # A folder or clip inside VIDEO: letters (accented ones too: their UTF-8
    # bytes are kept), digits and a few marks; never "..", which would climb out.
    QUERY=$(printf '%s' "$QUERY" | tr -cd 'A-Za-z0-9 _./&(),+\200-\377-' | cut -c1-200)
    case "$QUERY" in *..*) log "  refused a folder with .. in it"; QUERY=""; ACTION=refused ;; esac
    case "$DEST" in
        $ARCH/*) case "$DEST" in *..*) DEST=$ARCH/_duplicates ;; esac ;;
        *) DEST=$ARCH/_duplicates ;;
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
            KEEP_SIDE="$KEEP_SIDE" DEST="$DEST" sh $WEB/dedupe.sh >> "$LOG" 2>&1
            ;;
        apply)
            KEEP_SIDE="$KEEP_SIDE" DEST="$DEST" sh $WEB/dedupe.sh --apply >> "$LOG" 2>&1
            refresh_state
            ;;
        undo)
            sh $WEB/dedupe.sh --undo >> "$LOG" 2>&1
            refresh_state
            ;;
        organize-undo)
            sh $WEB/organize.sh --undo >> "$LOG" 2>&1
            refresh_state
            ;;
        reindex)
            echo "walking the share once — file list and search index together" >> "$LOG"
            build_index
            ;;
        cacheclean)
            echo "moving cache files to $ARCH/_duplicates/_media-cache ..." >> "$LOG"
            n=0
            # cache-moves.tsv grows (never emptied here), so "cache-undo" can put
            # back everything still in the holding folder, from every clean-up
            while IFS= read -r f; do
                [ -f "$f" ] || continue
                # Never from the recycle bin (that would undelete it) or from the
                # holding folder itself, whichever list this came from.
                case "$f" in $ARCH/*) ;; *) continue ;; esac
                case "$f" in */@Recycle/*|$ARCH/_duplicates/*|*/../*) continue ;; esac
                rel=${f#$ARCH/}
                d="$ARCH/_duplicates/_media-cache/$rel"
                if mkdir -p "$(dirname "$d")" && mv -n "$f" "$d"; then
                    printf '%s\t%s\n' "$f" "$d" >> $WEB/cache-moves.tsv
                    n=$((n + 1))
                fi
            done < $WEB/cache-files.txt
            echo "moved $n cache files" >> "$LOG"
            refresh_state
            ;;
        cache-undo)
            # Every cache file still in the holding folder goes back where it was.
            echo "putting cache files back from the holding folder ..." >> "$LOG"
            n=0
            [ -f $WEB/cache-moves.tsv ] && while IFS="$(printf '\t')" read -r src dst; do
                [ -f "$dst" ] && [ ! -e "$src" ] || continue
                mkdir -p "$(dirname "$src")" && mv -n "$dst" "$src" && n=$((n + 1))
            done < $WEB/cache-moves.tsv
            echo "put back $n cache files" >> "$LOG"
            refresh_state
            ;;
        verify)
            # Proof before deletion. Walks every move and checks, on disk now,
            # that the copy we kept is still there at the exact size the scan
            # measured. A filename search cannot do this: cameras reuse names,
            # so 58 files called DJI_0002.MOV is normal and means nothing.
            # With QUERY set, explains one file instead of checking all of them.
            if [ -n "$QUERY" ]; then
                sh $WEB/verify.sh "$QUERY" >> "$LOG" 2>&1
            else
                sh $WEB/verify.sh >> "$LOG" 2>&1
            fi
            ;;
        df)
            df -h $ARCH >> "$LOG" 2>&1
            ;;
        gpu-test)
            [ -n "$DOCKER" ] || echo "Container Station's docker was not found, so the container part of this test cannot run." >> "$LOG"
            # Can this machine make video on its video chip? Changes nothing: it
            # makes a 20-second test picture and keeps none of it. It tries the
            # NAS's own ffmpeg, then a complete one in a container
            # (linuxserver/ffmpeg) with each of Intel's two drivers — i965 for
            # chips from before 2015, iHD for newer — and software for comparison.
            # The result also goes to the VIDEO share, where the helper side can read it.
            out=$WEB/gpu-test.txt
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
                done < $WEB/manifest.tsv
                if [ -n "$clip" ]; then
                    echo "With a real clip: ${clip#$ARCH/}"
                    echo "  $("$DOCKER" run --rm -v $ARCH:$ARCH:ro linuxserver/ffmpeg -hide_banner -i "$clip" 2>&1 | grep -m1 'Video:' | sed 's/^ *//' | cut -c1-150)"
                    busy() { awk '/^cpu /{print $2+$3+$4+$7+$8, $2+$3+$4+$5+$6+$7+$8}' /proc/stat; }
                    real() {
                        lab=$1; shift; set -- $(busy) "$@"; b0=$1; t0=$2; shift 2; s0=$(date +%s)
                        if msg=$("$@" 2>&1); then
                            set -- $(busy); echo "  $lab: WORKS — 30 s of footage in $(( $(date +%s) - s0 )) s, processor $(( 100 * ($1 - b0) / ($2 - t0 + 1) ))% busy"
                        else echo "  $lab: does not work — $(printf '%s' "$msg" | tail -2 | tr '\n' ' ' | cut -c1-200)"; fi
                    }
                    RUN="$DOCKER run --rm -v $ARCH:$ARCH:ro"
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
            cp "$out" $ARCH/_rushes/gpu-test.txt 2>/dev/null
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
            out=$WEB/proxy-test.txt
            T=$ARCH/_rushes/proxy-test
            src="$ARCH/$QUERY"
            clip=""
            if [ -f "$src" ]; then clip=$src
            elif [ -d "$src" ]; then
                clip=$(find "$src" -type f 2>/dev/null | grep -iE '\.(mp4|mov|mxf|mts|m4v)$' | grep -v '/PROXIES/' \
                       | while IFS= read -r f; do printf '%s\t%s\n' "$(stat -c %s "$f" 2>/dev/null || echo 0)" "$f"; done \
                       | sort -rn | head -1 | cut -f2-)
            fi
            D="$DOCKER run --rm --cpu-shares 256 -v $ARCH:$ARCH --device /dev/dri:/dev/dri -e LIBVA_DRIVER_NAME=i965 --entrypoint /usr/local/bin/ffmpeg linuxserver/ffmpeg -hide_banner -nostdin -y"
            {
                echo "Proxy settings test · $(date '+%Y-%m-%d %H:%M')"
                if [ -z "$DOCKER" ]; then echo "Container Station's docker was not found, so nothing could be made."
                elif [ -z "$clip" ]; then echo "No video found at ${QUERY:-(nothing chosen)}."
                else
                    mkdir -p "$T" && rm -f "$T"/*
                    W=$WEB/proxy-test; mkdir -p "$W" && rm -f "$W"/*     # the stills again, for the page to show side by side
                    name=$(basename "$clip"); name=${name%.*}
                    info=$($D -i "$clip" 2>&1)
                    dur=$(printf '%s' "$info" | sed -n 's/.*Duration: \([0-9]*\):\([0-9]*\):\([0-9]*\).*/\1 \2 \3/p' | head -1 | awk '{print $1*3600+$2*60+$3}')
                    at=$(( ${dur:-0} > 45 ? ${dur:-0} * 3 / 10 : 0 ))       # 30% in: past the start, where the camera settles
                    echo "clip: ${clip#$ARCH/}"
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
                    chown -R "$(stat -c %u:%g $ARCH)" "$T" 2>/dev/null
                    echo "Look at them in _rushes/proxy-test on VIDEO: each clip, and a still from each at the same moment."
                    [ -f $WEB/proxy.pid ] && echo "(Proxies were being made meanwhile, so the times are slower than on a quiet chip.)"
                fi
            } > "$out" 2>&1
            cat "$out" >> "$LOG"
            ;;
        proxy-plan)
            PROXY_ONLY="$QUERY" sh $WEB/proxy.sh >> "$LOG" 2>&1
            ;;
        proxy-build)
            # Hours to days, so in the background: the runner stays free for
            # everything else (deploys, search, other jobs). Resumable: every
            # proxy already made is skipped, so stopping and starting costs nothing.
            if proxy_alive; then
                echo "proxies are already being made (process $pid, detail in proxy.log)" >> "$LOG"
            else
                PROXY_ONLY="$QUERY" sh $WEB/proxy.sh --build < /dev/null >> $WEB/proxy.log 2>&1 &
                echo $! > $WEB/proxy.pid
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
                elif [ -d "$ARCH/PROXIES/$QUERY" ]; then
                    n=$(find "$ARCH/PROXIES/$QUERY" -type f -name '*.mp4' | wc -l)
                    rm -rf "$ARCH/PROXIES/$QUERY"
                    echo "  threw away $n proxies of $QUERY — making them again with the setting in use" >> "$LOG"
                    PROXY_ONLY="$QUERY" sh $WEB/proxy.sh --build < /dev/null >> $WEB/proxy.log 2>&1 &
                    echo $! > $WEB/proxy.pid
                else
                    echo "  $QUERY has no proxies to remake — making them" >> "$LOG"
                    PROXY_ONLY="$QUERY" sh $WEB/proxy.sh --build < /dev/null >> $WEB/proxy.log 2>&1 &
                    echo $! > $WEB/proxy.pid
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
            du -sk $ARCH/_duplicates 2>/dev/null | cut -f1 > $WEB/holding-kb.txt
            echo "holding folder: $(du -sh $ARCH/_duplicates 2>/dev/null | cut -f1)" >> "$LOG"
            ;;
        manifest)
            # size + path for every file, so the Mac can decide what is already
            # here without reading 1.2M files over SMB. Minutes, locally.
            echo "walking the share once — file list and search index together" >> "$LOG"
            build_index
            n=$(wc -l < $WEB/manifest.tsv)
            if [ "$n" -lt 1000 ]; then
                echo "PROBLEM: far too few. Do not run the Mac against this." >> "$LOG"
                echo "  what find printed:" >> "$LOG"
                echo "    $ARCH is: $(ls -ld $ARCH 2>&1)" >> "$LOG"
                echo "    it really points at: $(readlink -f $ARCH 2>&1)" >> "$LOG"
                echo "    top level there:" >> "$LOG"
                find -L $ARCH -maxdepth 1 >> "$LOG" 2>&1
            fi
            ;;
        scan)
            # Czkawka (a duplicate finder) runs in a container called czkawka,
            # with the archive mounted as /storage (INSTALL.md). Its results are
            # copied out of the container with docker cp, wherever Docker keeps
            # them. Hours for a big archive; other jobs wait (the upkeep does not).
            if [ -z "$DOCKER" ] || ! "$DOCKER" inspect czkawka >/dev/null 2>&1; then
                echo "no container called czkawka on this machine — see INSTALL.md → Duplicates" >> "$LOG"
            else
                echo "hashing the whole share — this takes hours" >> "$LOG"
                "$DOCKER" exec czkawka czkawka_cli dup \
                    -d /storage \
                    -e /storage/_duplicates -e /storage/@Recycle \
                    -f /config/results_duplicates.txt > $WEB/scan-output.txt 2>&1
                tail -5 $WEB/scan-output.txt >> "$LOG"
                if "$DOCKER" cp czkawka:/config/results_duplicates.txt $WEB/results_duplicates.txt.new 2>> "$LOG"; then
                    mv -f $WEB/results_duplicates.txt.new $WEB/results_duplicates.txt
                    echo "scan done: $(grep -c '^\"' $WEB/results_duplicates.txt) duplicate files listed" >> "$LOG"
                else
                    rm -f $WEB/results_duplicates.txt.new
                    echo "scan finished but its results could not be copied out of the container" >> "$LOG"
                fi
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
                case "$name" in *.sh|release.py) ;; *) echo "  refused $name (not a script Rushes has)" >> "$LOG"; continue ;; esac
                tmp="$WEB/.$name.new"
                if ! v cp "$ARCH/_rushes/scripts/$name" "$tmp" || [ "$(sha256sum "$tmp" | cut -d' ' -f1)" != "$want" ]; then
                    rm -f "$tmp"; echo "  refused $name: it changed after it was approved (or could not be read)" >> "$LOG"; continue
                fi
                # renamed into place: a script that is running keeps its old copy
                chmod 755 "$tmp" && mv "$tmp" "$WEB/$name" && echo "  installed $name" >> "$LOG" || echo "  could not install $name" >> "$LOG"
            done
            for s in $PAGES; do
                rel=${s%%:*}; want=${s#*:}
                page_ok "$rel" || { echo "  refused page $rel (not a page Rushes has)" >> "$LOG"; continue; }
                mkdir -p "$WEB/$(dirname "$rel")"
                tmp="$WEB/$rel.new"
                if ! v cp "$DROP/$rel" "$tmp" || [ "$(sha256sum "$tmp" | cut -d' ' -f1)" != "$want" ]; then
                    rm -f "$tmp"; echo "  refused page $rel: it changed after it was approved (or could not be read)" >> "$LOG"; continue
                fi
                chmod 644 "$tmp" && mv "$tmp" "$WEB/$rel" && echo "  installed page $rel" >> "$LOG" \
                    && v rm -f "$DROP/$rel" || echo "  could not install page $rel" >> "$LOG"
            done
            touch $WEB/survey-now          # what is still waiting, looked at again next minute
            ;;
        reset-breaker)
            # Try again (Manage): VIDEO may be reached by itself again.
            rm -f "$VTRIP" "$VSTALL"
            echo "  VIDEO may be reached again — the next minute tries it" >> "$LOG"
            touch $WEB/survey-now
            ;;
        refused)
            ;;                                   # said above, when it was checked
        *)
            echo "unknown action: $ACTION" >> "$LOG"
            ;;
    esac

    echo "--- finished $(date '+%H:%M:%S') ---" >> "$LOG"
    echo "idle" > "$STATUS"
done

[ -f "$STATUS" ] || echo "idle" > "$STATUS"

upkeep_once
