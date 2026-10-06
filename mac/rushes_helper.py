# Rushes — Media Management Software, by Alejandro Renteria.
# Source available: https://github.com/x0on/rushes — whoever finds this file on a computer can see what it is and who made it.
"""Rushes Helper and Rushes Watcher — the Mac app around each.

One file, two apps (mac/build.py helper|watcher): the app's name, which the
launcher passes on, says which one this is. Rushes Helper copies footage into
the archive; Rushes Watcher, on each editor's computer, keeps projects and
what they use (rushes_watcher.py does the work).

Opened from Finder, it shows one window that stays open from start to end:
the first time, setup as steps (where Rushes is, the background service, the
one switch in System Settings macOS needs a person to turn on) ending on
"All set"; after that, what the helper is doing and its switches. Started by
macOS with arguments, it runs the helper, with no window.

The window is the app's own (see launcher.c); this file serves the page in it,
on this computer only, behind a random key. What it sets up or changes (install,
pairing, the switches, Try again, errors) is written to
~/Library/Logs/Rushes/setup.log. Nothing is written inside the app itself.
"""
import http.server
import json
import os
import platform
import plistlib
import re
import shutil
import subprocess
import sys
import threading
import time
import urllib.parse
import urllib.error
import urllib.request

APP = os.environ.get("RUSHES_APP") or os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
HOME = os.path.expanduser("~")
DIR = os.path.join(HOME, "Library", "Application Support", "Rushes")
# "Rushes" (called "Rushes Helper", org.rushes.helper, before 0.12) or "Rushes Watcher"
NAME = os.environ.get("RUSHES_NAME") or ("Rushes Watcher" if "Rushes Watcher" in APP else "Rushes")
WATCHER = NAME == "Rushes Watcher"
LOGS = os.path.join(HOME, "Library", "Logs", "Rushes Watcher" if WATCHER else "Rushes")
LABEL = "org.rushes.watcher" if WATCHER else "org.rushes.app"
# Before 0.12 the app was Rushes Helper, org.rushes.helper: its background service is retired when Rushes is set up
OLD_PLIST = os.path.join(HOME, "Library", "LaunchAgents", "org.rushes.helper.plist")
PLIST = os.path.join(HOME, "Library", "LaunchAgents", LABEL + ".plist")


def _home_app():
    """Where the app lives for good, the copy the background service runs: where the
    person put it (Applications, or Applications in the home folder: dragged there from
    the disk image), or else Applications when this Mac lets it be written, or else
    Applications in the home folder (a Mac where the person is not an administrator).
    One copy, never two."""
    here = os.path.realpath(APP)
    tops = ("/Applications", os.path.join(HOME, "Applications"))
    if os.path.dirname(here) in tops:
        return here
    top = tops[0] if os.access(tops[0], os.W_OK) else tops[1]
    return os.path.join(top, NAME + ".app")


HOMEAPP = _home_app()
WORKLOG = os.path.join(LOGS, "watcher.log" if WATCHER else "helper.log")      # what the work itself says
# Rushes Watcher's own notes: its pairing (config.json), what it is doing (now.json), Pause watching (paused)
WDIR = os.path.join(HOME, "Library", "Application Support", "Rushes Watcher")
# When Rushes was last asked for a newer app, and its answer (once a week, or Check for updates)
UPCHECK = os.path.join(WDIR if WATCHER else DIR, "update-check.json")
UPDATED = os.path.join(WDIR if WATCHER else DIR, "update-result.txt")   # how the last update went (release.app_update)
FILES = ("ingest.py", "transfer_state.py", "analyze.py", "release.py")
# The helper writes this when a share stopped answering three times (DEVELOPING.md, the six rules
# rule 4): it then touches no share until Try again here removes it.
STOPPED = os.path.join(HOME, "archive-pilot", "stopped.txt")
# Pairing (HOW-IT-WORKS.md → Pairing): the ID Rushes gave this Mac, sent with every request.
HELPER_ID = os.path.join(HOME, "archive-pilot", "helper-id")
TCC = os.path.join(HOME, "Library", "Application Support", "com.apple.TCC", "TCC.db")
LAN_PANE = ("x-apple.systempreferences:com.apple.settings.PrivacySecurity.extension?Privacy_LocalNetwork",
            "x-apple.systempreferences:com.apple.preference.security?Privacy_LocalNetwork")
FDA_PANE = ("x-apple.systempreferences:com.apple.settings.PrivacySecurity.extension?Privacy_AllFiles",
            "x-apple.systempreferences:com.apple.preference.security?Privacy_AllFiles")
UID = str(os.getuid())
# Rushes itself, when the archive is a drive on this Mac (HOW-IT-WORKS.md → Rushes on a Mac):
# the pages and what they keep (web), and which drive, which port, other devices or not (local.json).
RES = os.path.dirname(os.path.abspath(__file__))       # inside the app: the pages, PHP, runner.py, router.php
WEB = os.path.join(DIR, "web")
LOCAL = os.path.join(DIR, "local.json")
PORT = 8642


def log(msg):
    line = time.strftime("%Y-%m-%d %H:%M:%S  ") + msg
    print(line)
    try:
        os.makedirs(LOGS, exist_ok=True)
        with open(os.path.join(LOGS, "setup.log"), "a") as f:
            f.write(line + "\n")
    except OSError:
        pass


# ── what is true right now ──────────────────────────────────────────────────
def has_full_disk_access():
    """Asked by a fresh process each time: macOS answers the question for new
    file opens, and a new process is sure not to be holding an old answer."""
    r = subprocess.run([sys.executable, "-c", f"open({TCC!r}, 'rb').read(1)"], capture_output=True)
    return r.returncode == 0


def service_points_here():
    try:
        with open(PLIST, "rb") as f:
            p = plistlib.load(f)
        return p.get("ProgramArguments", [""])[0].startswith(HOMEAPP + "/")
    except (OSError, plistlib.InvalidFileException):
        return False


def other_copy():
    """Another copy of this app that the background service runs (set up from there
    before, such as Applications in the home folder before 0.12.2): this one, where the
    person put it, takes its place, and that copy goes to the Trash. "" when there is none."""
    try:
        with open(PLIST, "rb") as f:
            prog = plistlib.load(f).get("ProgramArguments", [""])[0]
    except (OSError, plistlib.InvalidFileException):
        return ""
    app = prog.split("/Contents/MacOS/")[0]
    return app if app.endswith("/" + NAME + ".app") and os.path.realpath(app) != os.path.realpath(HOMEAPP) else ""


def was_helper():
    """The background service was set up under the app's old name, Rushes Helper (before 0.12)."""
    if WATCHER:
        return False
    for p in (OLD_PLIST, PLIST):
        try:
            with open(p, "rb") as f:
                if "/Rushes Helper.app/" in plistlib.load(f).get("ProgramArguments", [""])[0] or p == OLD_PLIST:
                    return True
        except (OSError, plistlib.InvalidFileException):
            pass
    return False


def retire_old_helper():
    """Rushes Helper's own background service (org.rushes.helper, before 0.12) stops for good:
    Rushes, with its own ID, takes its place. Its plist goes to the Trash, never just deleted."""
    if WATCHER or not os.path.exists(OLD_PLIST):
        return
    launchctl("bootout", f"gui/{UID}/org.rushes.helper")
    try:
        os.rename(OLD_PLIST, os.path.join(HOME, ".Trash", f"org.rushes.helper {int(time.time())}.plist"))
        log("Rushes Helper's background service is retired: Rushes takes its place")
    except OSError as e:
        log(f"Rushes Helper's background service could not be moved to the Trash ({e})")


def service_running():
    """(loaded, pid): whether macOS has the background service, and its process."""
    r = launchctl("print", f"gui/{UID}/{LABEL}")
    m = re.search(r"\bpid = (\d+)", r.stdout)
    return r.returncode == 0, int(m.group(1)) if m else 0


def read_text(path, binary=False):
    with open(path, "rb" if binary else "r") as f:
        return f.read()


def read_json(path):
    try:
        with open(path) as f:
            return json.load(f)
    except (OSError, ValueError):
        return {}


def local():
    """Rushes on this Mac: {"archive", "port", "others"}, or {} when Rushes is somewhere else."""
    c = {} if WATCHER else read_json(LOCAL)
    return c if c.get("archive") else {}


def local_url():
    return f"http://127.0.0.1:{local().get('port') or PORT}"


def local_up():
    """Rushes on this Mac answering: None when Rushes is elsewhere, else whether its door opens."""
    if not local():
        return None
    import socket
    try:
        socket.create_connection(("127.0.0.1", local().get("port") or PORT), 0.3).close()
        return True
    except OSError:
        return False


def local_name():
    """This Mac on the network (name.local), for other devices."""
    try:
        n = subprocess.run(["scutil", "--get", "LocalHostName"], capture_output=True, text=True, timeout=5).stdout.strip()
    except Exception:
        n = ""
    return (n or platform.node().split(".")[0]) + ".local"


def saved_url():
    try:
        if WATCHER:
            return read_json(os.path.join(WDIR, "config.json")).get("url", "")
        return open(os.path.join(DIR, "url")).read().strip()
    except (OSError, ValueError):
        return ""


def app_version():
    """This app's version: one number for all of Rushes (app/VERSION, in its Info.plist)."""
    try:
        with open(os.path.join(APP, "Contents", "Info.plist"), "rb") as f:
            return plistlib.load(f).get("CFBundleShortVersionString", "?")
    except (OSError, plistlib.InvalidFileException):
        return "dev"


def watcher_now():
    """What Rushes Watcher says it is doing (rushes_watcher.py, now.json)."""
    return read_json(os.path.join(WDIR, "now.json"))


def reachable(url):
    """(ok, why, blocked): blocked means macOS itself refused — Local Network is off."""
    try:
        with urllib.request.urlopen(url.rstrip("/") + "/db/helper.php?hash", timeout=8) as r:
            return r.status == 200 and b"ingest.py" in r.read(), "", False
    except Exception as e:
        why = getattr(e, "reason", e)
        return False, str(why), getattr(why, "errno", None) == 65      # EHOSTUNREACH


def open_pane(pane):
    if subprocess.run(["open", pane[0]]).returncode:
        subprocess.run(["open", pane[1]])


def guess_url():
    """Where the app was downloaded from says where Rushes is. Then the
    clipboard (Setup has a Copy button for it). Then nothing: the person types it."""
    def from_where(path):
        r = subprocess.run(["xattr", "-px", "com.apple.metadata:kMDItemWhereFroms", path],
                           capture_output=True, text=True)
        if r.returncode:
            return ""
        try:
            for u in plistlib.loads(bytes.fromhex("".join(r.stdout.split()))):
                if "/db/helper.php" in u:
                    return u.split("/db/helper.php")[0]
        except Exception:
            pass
        return ""
    downloads = os.path.join(HOME, "Downloads")
    try:
        for p in [APP] + [os.path.join(downloads, f) for f in sorted(os.listdir(downloads) if os.path.isdir(downloads) else [])
                          if f.startswith(NAME)]:
            u = from_where(p)
            if u:
                return u
        clip = subprocess.run(["pbpaste"], capture_output=True, text=True).stdout.strip()
    except OSError:
        return ""
    if clip.startswith(("http://", "https://")) and "\n" not in clip and len(clip) < 200:
        return clip.split("/db/")[0].rstrip("/")
    return ""


# ── the helper's own files, kept outside the app so they can update ─────────
def fetch_files(url):
    """The helper's code, from Rushes: each file must match the fingerprint
    Rushes lists for it (helper.php?hash) and be valid Python, or nothing goes in."""
    import hashlib
    os.makedirs(DIR, exist_ok=True)
    with urllib.request.urlopen(f"{url}/db/helper.php?hash", timeout=20) as r:
        want = json.loads(r.read().decode("utf-8", "replace")) or {}
    got = {}
    for f in FILES:                  # every file checked before any goes in: never half an update
        if not want.get(f):          # not listed until someone presses Check now in Manage
            raise RuntimeError("Rushes has not listed the helper's files yet, so they cannot be checked. "
                               "In Rushes, Manage → What runs by itself → Check now, then try again.")
        with urllib.request.urlopen(f"{url}/db/helper.php?code={f}", timeout=60) as r:
            data = r.read()
        if hashlib.sha256(data).hexdigest() != want[f]:
            raise RuntimeError(f"{f} from Rushes does not match its fingerprint; nothing was installed. Try again in a minute.")
        compile(data, f, "exec")                 # a broken download never goes in
        got[f] = data
    # A signed release, checked with the release.py inside this app (which macOS
    # checks is signed by its author), never one downloaded with it.
    import release
    with urllib.request.urlopen(f"{url}/db/helper.php?code=release.sig", timeout=20) as r:
        sig = r.read().decode("utf-8", "replace")
    try:
        mine = open(os.path.join(DIR, "release.sig")).read() if os.path.exists(os.path.join(DIR, "release.sig")) else ""
        release.check(got, sig, not_before=release.made_of(mine))
    except ValueError as e:
        raise RuntimeError(f"the helper's code on Rushes is not a signed release ({e}); nothing was installed.")
    got["release.sig"] = sig.encode()
    for f, data in got.items():
        with open(os.path.join(DIR, f + ".new"), "wb") as fh:
            fh.write(data)
        os.replace(os.path.join(DIR, f + ".new"), os.path.join(DIR, f))
    with open(os.path.join(DIR, "url"), "w") as fh:
        fh.write(url + "\n")


def write_json(path, d):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path + ".new", "w") as f:
        json.dump(d, f, indent=1)
    os.replace(path + ".new", path)


def pages_in():
    """The pages inside this app, put in the web folder where they differ. Only
    the pages: what Rushes keeps there (settings, database, lists) stays."""
    src = os.path.join(RES, "pages")
    for root, _, files in os.walk(src):
        for f in files:
            if f == "settings.json":                     # this Mac's own, written by Setup: never replaced
                continue
            a = os.path.join(root, f); b = os.path.join(WEB, os.path.relpath(a, src))
            data = read_text(a, binary=True)
            try:
                if read_text(b, binary=True) == data:
                    continue
            except OSError:
                pass
            os.makedirs(os.path.dirname(b), exist_ok=True)
            with open(b + ".new", "wb") as h:
                h.write(data)
            os.replace(b + ".new", b)


def server():
    """Rushes itself, on this Mac: PHP's own web server for the pages (router.php
    is its door) and the minute's work (runner.py). Started by the background
    helper, and gone with it."""
    import signal
    sys.path.insert(0, RES)
    import runner
    pages_in()
    os.makedirs(os.path.join(DIR, "sessions"), exist_ok=True)
    php = os.path.join(RES, "php-" + ("arm64" if platform.machine() == "arm64" else "x86_64"))
    tz = os.path.realpath("/etc/localtime").partition("zoneinfo/")[2] or "UTC"
    ini = {"post_max_size": "0", "memory_limit": "512M", "max_execution_time": "0", "display_errors": "0",
           "log_errors": "1", "error_log": os.path.join(WEB, "php-errors.log"), "date.timezone": tz, "session.save_path": os.path.join(DIR, "sessions"),
           # signed in on a phone stays signed in for a month, not 24 minutes
           "session.gc_maxlifetime": "2592000", "session.cookie_lifetime": "2592000"}
    parent, run, p, bound, started, nxt = os.getppid(), None, None, None, 0.0, time.time() + 5   # the first minute once it answers
    def stop():
        if p and p.poll() is None:
            p.terminate()
            try: p.wait(5)
            except subprocess.TimeoutExpired: p.kill()
    signal.signal(signal.SIGTERM, lambda *a: (stop(), sys.exit(0)))
    try:
        while os.getppid() == parent:                    # the helper that started it is gone: so is this
            c = local()
            want = ("0.0.0.0" if c.get("others") else "127.0.0.1", int(c.get("port") or PORT))
            if p and (p.poll() is not None or bound != want):
                if p.poll() is not None:
                    print(f"{time.strftime('%Y-%m-%d %H:%M:%S')}  the web server stopped ({p.returncode}); started again")
                stop(); p = None
            if not p and time.time() - started > 10:     # one that cannot start (port in use) is tried every 10 s
                started, bound = time.time(), want
                p = subprocess.Popen([php, *sum((["-d", f"{k}={v}"] for k, v in ini.items()), []),
                                      "-S", f"{want[0]}:{want[1]}", "-t", WEB, os.path.join(RES, "router.php")],
                                     # every request is a line on its own output: not kept (errors go to php-errors.log)
                                     stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=dict(os.environ, PHP_CLI_SERVER_WORKERS="8",
                                                                        RUSHES_OTHERS="1" if c.get("others") else "0"))
                print(f"{time.strftime('%Y-%m-%d %H:%M:%S')}  Rushes at http://{want[0]}:{want[1]} (archive: {c.get('archive')})", flush=True)
            if time.time() >= nxt:
                nxt = time.time() + 60
                run = run or runner.Runner(WEB, local_url())
                run.url = local_url()
                run.minute()
            time.sleep(2)
    finally:
        stop()


def launchctl(*args):
    try:
        return subprocess.run(["launchctl", *args], capture_output=True, text=True)
    except OSError as e:
        return subprocess.CompletedProcess(args, 1, "", str(e))


def install_service(url):
    retire_old_helper()
    os.makedirs(os.path.dirname(PLIST), exist_ok=True)
    os.makedirs(LOGS, exist_ok=True)
    plist = {
        "Label": LABEL,
        "ProgramArguments": [os.path.join(HOMEAPP, "Contents", "MacOS", NAME)]
                            + (["--service"] if WATCHER else ["--watch", "--url", url, "--service"]),
        # Login Items shows it by the app's name, with the icon, instead of a bare program.
        "AssociatedBundleIdentifiers": [LABEL],
        "RunAtLoad": True, "KeepAlive": True, "ThrottleInterval": 30,
        "ProcessType": "Interactive",            # it shows its icon in the menu bar: never out of sight
        "LimitLoadToSessionType": "Aqua",        # only while someone is logged in to see it
        # The Watcher writes its own log; what reaches here is only what Python says when it fails.
        "StandardOutPath": os.path.join(LOGS, "service.out" if WATCHER else "helper.log"),
        "StandardErrorPath": os.path.join(LOGS, "service.out" if WATCHER else "helper.log"),
    }
    with open(PLIST + ".new", "wb") as f:
        plistlib.dump(plist, f)
    os.replace(PLIST + ".new", PLIST)
    return start_service()


def start_service():
    launchctl("enable", f"gui/{UID}/{LABEL}")           # undoes stop_service's "disable"
    launchctl("bootout", f"gui/{UID}/{LABEL}")          # the old one, whatever it was
    for _ in range(5):
        r = launchctl("bootstrap", f"gui/{UID}", PLIST)
        if r.returncode == 0:
            return True
        time.sleep(1)                                   # the old one can take a moment to go
    log(f"could not start the service: {r.stderr.strip()}")
    return False


def stop_service():
    """Stops it and keeps it stopped, also after a restart, until started again here.
    bootout alone only stops it now: the plist says RunAtLoad, so macOS would start
    it again at the next login. "disable" is what makes macOS leave it alone."""
    launchctl("disable", f"gui/{UID}/{LABEL}")
    r = launchctl("bootout", f"gui/{UID}/{LABEL}")
    return r.returncode == 0 or not service_running()[0]


def computer_name():
    try:
        return subprocess.run(["scutil", "--get", "ComputerName"], capture_output=True, text=True, timeout=5).stdout.strip() or platform.node()
    except Exception:
        return platform.node()


def restart_service():
    launchctl("kickstart", "-k", f"gui/{UID}/{LABEL}")


def copy_to_applications():
    """The background service runs the copy in Applications (HOMEAPP: where the
    person put it, or Applications): a place that stays put — not Downloads, not the
    disk image, and not the temporary copy macOS runs a downloaded app from. A copy
    the service ran before, anywhere else, goes to the Trash. Copied, then setup simply carries
    on in this same window."""
    here, old = os.path.realpath(APP), other_copy()        # old: asked before the service is pointed here
    if here != os.path.realpath(HOMEAPP):
        log(f"copying the app from {here} to {HOMEAPP}")
        os.makedirs(os.path.dirname(HOMEAPP), exist_ok=True)
        if os.path.exists(HOMEAPP):
            subprocess.run(["rm", "-rf", HOMEAPP])
        r = subprocess.run(["ditto", here, HOMEAPP], capture_output=True, text=True)
        if r.returncode:
            return r.stderr.strip() or "ditto failed"
        # It came from the download that was already allowed to open.
        subprocess.run(["xattr", "-dr", "com.apple.quarantine", HOMEAPP], capture_output=True)
    # Never two copies: the one the service ran before (anywhere else), and Rushes Helper
    # (its name before 0.12), go to the Trash, so neither is ever opened by mistake
    olds = [old] if old else []
    if not WATCHER:
        olds += [os.path.join(top, "Rushes Helper.app") for top in ("/Applications", os.path.join(HOME, "Applications"))]
    for was in olds:
        if os.path.isdir(was) and os.path.realpath(was) != os.path.realpath(HOMEAPP):
            try:
                os.rename(was, os.path.join(HOME, ".Trash", f"{os.path.basename(was)[:-4]} {int(time.time())}.app"))
                log(f"the other copy, {was}, went to the Trash: {NAME} runs from {HOMEAPP}")
            except OSError as e:
                log(f"the other copy, {was}, is still there ({e}): it can go to the Trash")
    return ""


def remove_service():
    launchctl("bootout", f"gui/{UID}/{LABEL}")
    try:
        os.remove(PLIST)
    except OSError:
        pass
    log("removed the background service")


# ── Rushes: what the helper is doing, and its switches ──────────────────────
def helper_id():
    try:
        with open(HELPER_ID) as f:
            return f.read().strip()
    except OSError:
        return ""


def mac_user():
    """Who is signed in to this Mac, by the name macOS shows (Activity says who turned a switch)."""
    try:
        import pwd
        e = pwd.getpwuid(os.getuid())
        return (e.pw_gecos.split(",")[0] or e.pw_name).strip()
    except (ImportError, KeyError):
        return ""


def rushes(url, path, data=None, timeout=6):
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    req = urllib.request.Request(url.rstrip("/") + path, data=body,
                                 headers={"X-Rushes-Helper": helper_id(), "X-Rushes-Who": urllib.parse.quote(mac_user())})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read().decode("utf-8", "replace"))
    except urllib.error.HTTPError as e:              # Rushes' own words, when it gives them
        try: raise RuntimeError(json.loads(e.read().decode("utf-8", "replace"))["error"]) from None
        except (ValueError, KeyError): raise e from None


def log_tail(n=12):
    """The helper's own words, newest last: what it did lately."""
    try:
        with open(WORKLOG, "rb") as f:
            f.seek(0, 2); f.seek(max(0, f.tell() - 16000))
            lines = f.read().decode("utf-8", "replace").splitlines()
    except OSError:
        return []
    return [l for l in lines if l.strip()][-n:]


def diagnostics(url):
    """Everything someone helping would ask for, in one plain text file on the
    Desktop, for the person to read before sending it anywhere — or not at all.
    Versions, switches, what the helper said lately. It names folders and files
    (that is what the log is about) and holds no footage. It should hold no
    passwords — none are written on purpose — but it copies the logs as they
    are, so it is for the person to read first. Nothing is sent: where it goes
    next is the person's choice."""
    import hashlib
    lines = []
    def add(title, value=""):
        lines.append(f"{title}: {value}" if value != "" else f"\n── {title} ──")
    try:
        with open(os.path.join(APP, "Contents", "Info.plist"), "rb") as f:
            ver = plistlib.load(f).get("CFBundleShortVersionString", "?")
    except (OSError, plistlib.InvalidFileException):
        ver = "?"
    add("Rushes diagnostics", time.strftime("%Y-%m-%d %H:%M:%S"))
    add("What this is", f"written by {NAME} when you pressed Collect diagnostics; nothing was sent anywhere")
    add(NAME, f"{ver} · {APP}")
    add("macOS", f"{platform.mac_ver()[0]} · {platform.machine()}")
    add("Rushes server", url or "(not set up)")
    loaded, pid = service_running()
    add("Running in the background", f"yes (process {pid})" if loaded else "no")
    add("Full Disk Access", "yes" if has_full_disk_access() else "no")
    for f in () if WATCHER else FILES:
        try: add(f, hashlib.sha256(open(os.path.join(DIR, f), "rb").read()).hexdigest()[:12])
        except OSError: add(f, "missing")
    try: add("Drives connected", ", ".join(sorted(os.listdir("/Volumes"))))
    except OSError: pass
    if WATCHER:
        add("What it says it is doing", json.dumps(watcher_now(), ensure_ascii=False))
        add("Watching paused", "yes" if os.path.exists(os.path.join(WDIR, "paused")) else "no")
    if not WATCHER:
        add("Rushes' switches for this helper")
        try: lines.append(json.dumps(rushes(url, "/db/helper.php?control"), indent=1))
        except Exception as e: lines.append(f"(Rushes did not answer: {e})")
        add("What this Mac is doing, as Rushes sees it")
        try:
            st = rushes(url, "/db/state.php")
            lines.append(json.dumps({k: st.get(k) for k in ("copy", "helper", "transfer", "conditions")}, indent=1, ensure_ascii=False))
        except Exception as e: lines.append(f"(Rushes did not answer: {e})")
    for name, n in ((os.path.basename(WORKLOG), 300), ("setup.log", 80)):
        add(f"The last {n} lines of {name}")
        try:
            with open(os.path.join(LOGS, name), "rb") as f:
                f.seek(0, 2); f.seek(max(0, f.tell() - 200000))
                lines += f.read().decode("utf-8", "replace").splitlines()[-n:]
        except OSError:
            lines.append("(none)")
    out = os.path.join(HOME, "Desktop", time.strftime("Rushes diagnostics %Y-%m-%d %H.%M.txt"))
    with open(out, "w") as f:
        f.write("\n".join(lines) + "\n")
    subprocess.run(["open", "-R", out])          # shown in Finder, to read first
    log(f"diagnostics written to {out}")
    return out


# ── the window ──────────────────────────────────────────────────────────────
class Window:
    """Everything the page shows, and what its buttons do. The page asks for
    this state 1.5 seconds after each answer and draws it; slow work runs in a thread and moves
    the state on, so the window never freezes and never goes away mid-way."""

    def __init__(self):
        self.lock = threading.Lock()
        self.quit = threading.Event()                    # before any thread that watches it
        self.remote, self.asking, self.asked = {}, None, 0.0     # what Rushes last said (ask_rushes)
        self.activity_at = 0.0
        self.s = {"step": "welcome", "url": saved_url(), "busy": "", "error": "", "said": "",
                  "done": [], "waiting": False, "lan_asked": False}
        if self.s["url"] and not WATCHER and not service_points_here() and was_helper():
            # Set up before 0.12, as Rushes Helper: this app takes its place, with the same settings
            self.s.update(step="install", done=["Rushes Helper is called Rushes now: it takes its place"])
            threading.Thread(target=lambda: self.install_quietly(self.s["url"]), daemon=True).start()
        elif self.s["url"] and other_copy():
            # Set up from another copy (Applications in the home folder, before 0.12.2): this copy,
            # where the person put it, takes its place with the same settings and permissions
            self.s.update(step="install", done=[f"{NAME} runs from {os.path.dirname(HOMEAPP)} now: the copy in {os.path.dirname(other_copy())} goes to the Trash"])
            threading.Thread(target=lambda: self.install_quietly(self.s["url"]), daemon=True).start()
        elif self.s["url"] and service_points_here() and has_full_disk_access():
            self.s["step"] = "home"
        elif service_points_here() and self.s["url"]:
            self.s["step"] = "fda"                       # came back to finish the last switch
            self._watch_access()

    def set(self, **kw):
        with self.lock:
            self.s.update(kw)

    def state(self):
        with self.lock:
            s = dict(self.s)
        s.update(name=NAME, watcher=WATCHER, set_up=bool(s["url"]) and service_points_here(),
                 local_up=None if WATCHER else local_up())     # Open Rushes waits until Rushes answers
        if s["step"] == "home":
            s.update(self.home())
        return s

    def source(self):
        """Where a newer app is looked for: Rushes running inside this app has nobody
        to ask but GitHub's releases (""); otherwise the Rushes it works with."""
        return "" if not WATCHER and local() else self.s["url"]

    def newer(self, now=False):
        """A newer Rushes (or Watcher), offered, never installed by itself: the person
        decides. Asked once a week (remembered on this computer), or at once with
        Check for updates. Only for the copy in Applications, which is the one the
        background service runs."""
        c = read_json(UPCHECK)
        if now or time.time() - c.get("at", 0) > 7 * 86400:
            c["at"] = time.time()                        # a failed question waits a week too, unless asked
            try:
                import release
                same = os.path.realpath(APP) == os.path.realpath(HOMEAPP)
                c["newer"] = release.app_update(self.source(), APP, check_only=True) if same else ""
            except Exception as e:
                if now: raise
            try:
                os.makedirs(os.path.dirname(UPCHECK), exist_ok=True)
                with open(UPCHECK + ".new", "w") as f:
                    json.dump(c, f)
                os.replace(UPCHECK + ".new", UPCHECK)
            except OSError:
                pass
        v = c.get("newer", "")
        try:
            import release
            return v if v and release.version_tuple(v) > release.version_tuple(app_version()) else ""
        except ImportError:
            return ""

    def check_updates(self):
        v = self.newer(now=True)
        self.set(said=f"Rushes {v} is ready. Update to {v} when you choose: nothing changes until you do." if v
                 else f"Up to date: {NAME} {app_version()} is the newest release." if not self.source()
                 else f"Up to date: {NAME} {app_version()} is the same version as Rushes.")

    def update_app(self):
        import release
        v = release.app_update(self.source(), APP, say=lambda m: (log(m), self.set(said=m)), result=UPDATED)
        if not v:
            self.set(said=f"Already up to date: {NAME} {app_version()}.")

    def home(self):
        loaded, pid = service_running()
        h = {"running": loaded, "pid": pid, "log": log_tail(60), "app": HOMEAPP, "logfile": WORKLOG, "local_up": local_up(),
             "newer": self.newer(), "version": app_version()}
        if WATCHER:
            # Everything is on this computer: what it says it is doing, and its switch.
            n = watcher_now()
            paired = bool(read_json(os.path.join(WDIR, "config.json")).get("id"))
            h.update(now=n, paired=paired, paused=os.path.exists(os.path.join(WDIR, "paused")),
                     rushes=n.get("state") != "offline", rushes_why=n.get("note", ""))
            return h
        if local():
            c = local()
            # Setup (02) can move the archive: settings.json says where it is now
            c["archive"] = (read_json(os.path.join(WEB, "settings.json")).get("archive") or {}).get("local") or c["archive"]
            h["local"] = {"archive": c["archive"], "others": bool(c.get("others")), "port": c.get("port") or PORT,
                          "there": os.path.isdir(c["archive"]), "name": local_name()}
        try:
            with open(STOPPED) as f:
                h["stopped"] = f.read().partition("\n")[2].strip() or "a share stopped answering"
        except OSError:
            pass
        # What Rushes says is asked in the background, never while the window waits:
        # a Rushes that does not answer (a NAS that is off) made every look at this
        # window take 20 seconds. Until the first answer, it says it is asking.
        with self.lock:
            asking = self.asking and self.asking.is_alive()
            if not asking and time.time() - self.asked > 3:
                self.asked = time.time()
                self.asking = threading.Thread(target=self.ask_rushes, daemon=True); self.asking.start()
            h.update(self.remote or {"rushes": None})
        return h

    def ask_rushes(self):
        r = {}
        # Paired or not is asked first and on its own: the box to type the code
        # in is shown even when the rest of Rushes is slow to answer.
        try: r["pairing"] = rushes(self.s["url"], "/db/pair.php", timeout=15).get("pairing", "")
        except Exception: r["pairing"] = ""              # not known right now: the box stays
        try:
            c = rushes(self.s["url"], "/db/helper.php?control")
            r["paused"], r["no_reconnect"] = bool(c.get("paused")), bool(c.get("no_reconnect"))
            r["describe_paused"] = bool(c.get("describe_paused"))
            r["check_paused"] = bool(c.get("check_paused"))
            st = rushes(self.s["url"], "/db/state.php")
            r["now"] = st.get("copy") or {}
            a, d = st.get("archive") or {}, st.get("disk") or {}
            r["stats"] = {"files": a.get("files", 0), "bytes": a.get("bytes", 0), "free": d.get("free", 0)}
            r["describing"] = ((st.get("helper") or {}).get("describe")) or {}
            r["rushes"] = True
        except Exception as e:
            r["rushes"] = False; r["rushes_why"] = str(getattr(e, "reason", e))
        # Activity and the editors' computers: asked every 15 s, kept between (the page asks every 1.5)
        with self.lock:
            old = self.remote or {}
        if r.get("rushes") and time.time() - self.activity_at > 15:
            try:
                a = rushes(self.s["url"], "/db/activity.php?n=150")
                r["activity"], r["watchers"] = a.get("events") or [], a.get("watchers") or []
                self.activity_at = time.time()
            except Exception:
                pass
        for k in ("activity", "watchers"):
            if k not in r and k in old:
                r[k] = old[k]
        with self.lock:
            self.remote = r

    def run(self, what, fn):
        """Slow work: shown as busy, never twice at once."""
        with self.lock:
            if self.s["busy"]:
                return
            self.s["busy"] = what; self.s["error"] = ""
        def go():
            try:
                fn()
            except Exception as e:
                log(f"{what}: {e}")
                self.set(error=str(e))
            finally:
                self.set(busy="")
        threading.Thread(target=go, daemon=True).start()

    # the buttons
    def act(self, do, a):
        s = self.s
        if do == "start":
            url = s["url"] or guess_url()
            self.set(step="address", url=url, error="")
            if url and not s["lan_asked"]:
                # The first try makes macOS ask about Local Network now, while
                # the person is looking, instead of failing the address silently.
                self.set(lan_asked=True)
                threading.Thread(target=reachable, args=(url,), daemon=True).start()
        elif do in ("address", "retry"):
            url = (a.get("url") or s["url"]).strip().rstrip("/").split("/db/")[0]
            if "://" not in url:
                url = "http://" + url
            self.set(url=url)
            self.run("Looking for Rushes at " + url + " …", lambda: self.check(url))
        elif do == "network-settings":
            open_pane(LAN_PANE)
        elif do == "fda-open":
            self.show_fda(); self.set(waiting=True)
        elif do == "later":
            self.set(step="later")
        elif do == "open-rushes" and local_up() is False:
            self.set(said="Rushes is still starting on this Mac. It opens in a moment: Open Rushes is ready when it answers.")
        elif do == "open-rushes":
            subprocess.run(["open", s["url"] + ("/db/admin.php#projects" if WATCHER else "/" if local() else "/db/admin.php")])
        elif do == "local-pick":
            self.run("Choose the archive's drive or folder in the window macOS opens …", self.pick_local)
        elif do in ("others-on", "others-off") and local():
            c = read_json(LOCAL); c["others"] = do == "others-on"
            write_json(LOCAL, c)
            log(f"switch: {do}")
            self.set(said=f"On ✓ Phones and computers on your network (or Tailscale) open http://{local_name()}:{c.get('port') or PORT} "
                          "and sign in with Rushes' password. Set your own in Rushes → Manage first: until then they are refused."
                     if c["others"] else "Off ✓ Only this Mac opens Rushes now.")
        elif do == "reveal-archive" and local():
            # the archive, in Finder (where Setup last put it)
            subprocess.run(["open", (read_json(os.path.join(WEB, "settings.json")).get("archive") or {}).get("local") or local()["archive"]])
        elif do == "open-setup":
            subprocess.run(["open", (local_url() if local() else s["url"]) + "/setup.php"])
        elif do == "show-log":
            subprocess.run(["open", "-a", "Console", WORKLOG])
        elif do == "open-window":
            subprocess.run(["open", "-n", APP])          # its window, as an instance of its own
        elif do == "quit":
            # From the menu bar: like turning off Run in the background. It stays
            # off, also after a restart, until it is turned on in its window.
            log("quit from the menu bar")
            stop_service()
        elif do in ("watch-pause", "watch-resume") and WATCHER:
            p = os.path.join(WDIR, "paused")
            if do == "watch-pause":
                os.makedirs(WDIR, exist_ok=True); open(p, "w").close()
                self.set(said="Watching paused ✓ It looks at no project until you turn it on again. Nothing already delivered changes.")
            else:
                try: os.remove(p)
                except OSError: pass
                self.set(said="Watching ✓ Projects saved from now on are kept again.")
            log(f"switch: {do}")
        elif do == "remove":
            self.set(step="remove")
        elif do == "remove-yes":
            remove_service(); self.set(step="removed")
        elif do == "try-again":
            try: os.remove(STOPPED)
            except OSError: pass
            log("Try again: the helper may touch the shares again")
            self.set(said="Asked ✓ It looks at the shares again within a minute. If one still does not answer, it stops again after three tries and says so here.")
        elif do == "pair":
            code = "".join(ch for ch in str(a.get("code", "")) if ch.isdigit())
            self.run("Pairing with Rushes …", lambda: self.pair(code))
        elif do == "paste-pair":
            # The code copied in Rushes (its Copy button), read from the clipboard only now, when asked.
            clip = subprocess.run(["pbpaste"], capture_output=True, text=True).stdout.strip()
            if not re.fullmatch(r"\d{3} ?\d{3}", clip):
                self.set(error="The clipboard does not hold a pairing code. In Rushes → Setup, press Copy beside the six numbers, then Paste here.")
            else:
                self.set(error="")
                self.run("Pairing with Rushes …", lambda: self.pair(clip.replace(" ", "")))
        elif do == "check-updates":
            self.run("Asking Rushes …", self.check_updates)
        elif do == "update-app":
            self.run(f"Updating {NAME} …", self.update_app)
        elif do == "change-where" and not WATCHER:
            # Set up already, against a Rushes elsewhere (a NAS): its address, or a drive on this Mac instead
            self.set(step="address", error="", said="")
        elif do == "back-home":
            self.set(step="home", said="")
        elif do == "done":
            self.quit.set()
        elif do == "ask-help":
            self.run("Collecting diagnostics …", lambda: self.ask_help())
        elif do == "diagnostics":
            self.run("Collecting diagnostics …", lambda: self.set(said="Saved ✓ " + os.path.basename(diagnostics(s["url"])) +
                     " is on your Desktop, shown in Finder. Read it first; nothing was sent anywhere."))
        # switches, once set up: each says what it did
        elif do == "service-off":
            self.run("Stopping …", lambda: self.set(said="Stopped ✓ It will not run, not even after a restart, until you turn it on here."
                                                    if stop_service() else "Could not stop it — see setup.log."))
        elif do == "service-on":
            self.run("Starting …", lambda: self.set(said="Running ✓ It carries on where it left off."
                                                    if start_service() else "Could not start it — see setup.log."))
        elif do in ("pause", "resume", "describe-pause", "describe-resume", "reconnect-off", "reconnect-on", "check-pause", "check-resume"):
            self.run("Asking Rushes …", lambda: self.switch(do))

    def pick_local(self):
        """The archive is a drive (or a folder) on this Mac: Rushes runs inside this app (HOW-IT-WORKS.md → Rushes on a Mac)."""
        r = subprocess.run(["osascript", "-e", 'POSIX path of (choose folder with prompt "The archive: the drive or folder '
                            'Rushes looks after" default location "/Volumes")'], capture_output=True, text=True)
        if r.returncode:
            return                                       # Cancel: nothing changes
        arch = r.stdout.strip().rstrip("/") or "/"
        sys.path.insert(0, RES)
        import runner
        if not runner.archive_ok(arch, WEB):
            raise RuntimeError(f"{arch} cannot be the archive: choose a drive, or a folder on one, "
                               "not a system folder or the whole startup disk.")
        pages_in()
        s = read_json(os.path.join(WEB, "settings.json")) or read_json(os.path.join(RES, "pages", "settings.example.json"))
        s.pop("_", None)
        url = local_url() if local() else f"http://127.0.0.1:{PORT}"
        s.setdefault("archive", {}).update(label=os.path.basename(arch), local=arch, web=WEB, url=url, as_seen_from_helper=arch, runs_on="mac")
        s.setdefault("helper", {})["mode"] = "built_in"
        s["holding"] = {"duplicates": arch + "/_Recently Removed", "cache": arch + "/_Recently Removed/_media-cache"}
        # Free space: a NAS's floor (5 TB) would stop a laptop drive at once. Copying stops
        # below 2% of the drive (20 GB to 500 GB) and Overview warns below 5% (50 GB to 1 TB).
        lim, total = s.setdefault("limits", {}), shutil.disk_usage(arch).total
        if lim.get("disk_stop_free") is None: lim["disk_stop_free"] = max(20 << 30, min(total // 50, 500 << 30))
        if lim.get("disk_warn_free") is None: lim["disk_warn_free"] = max(50 << 30, min(total // 20, 1 << 40))
        write_json(os.path.join(WEB, "settings.json"), s)
        # the helper is this app: paired with this Rushes from the start
        if not os.path.exists(os.path.join(WEB, "helper-id.php")):
            with open(os.path.join(WEB, "helper-id.php"), "w") as f:
                f.write(f"<?php return ['id' => '{os.urandom(16).hex()}', 'host' => 'this Mac', 'at' => {int(time.time())}];\n")
            os.chmod(os.path.join(WEB, "helper-id.php"), 0o600)
        c = read_json(LOCAL); c.update(archive=arch, port=c.get("port") or PORT)
        write_json(LOCAL, c)
        os.makedirs(DIR, exist_ok=True)
        with open(os.path.join(DIR, "url"), "w") as f:
            f.write(url + "\n")
        log(f"Rushes runs on this Mac, archive: {arch}")
        self.set(url=url, step="install", done=[f"Rushes runs inside this app, archive: {arch}"])
        self.install(url)

    def pair(self, code):
        """The code Rushes → Setup shows, for this Mac's ID. The background
        helper restarts to send it; the one paired before is refused from now."""
        if WATCHER:
            sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
            import rushes_watcher                        # the one beside this file, inside the signed app
            rushes_watcher.pair(self.s["url"], code)
            if service_running()[0]:
                restart_service()
            self.set(said="Paired ✓ This computer can deliver to Rushes now. Rushes lists it in Setup → Editors' work.")
            return
        r = rushes(self.s["url"], "/db/pair.php", {"code": code, "host": computer_name()}, timeout=30)
        os.makedirs(os.path.dirname(HELPER_ID), exist_ok=True)
        with open(HELPER_ID + ".new", "w") as f:
            f.write(r["id"] + "\n")
        os.chmod(HELPER_ID + ".new", 0o600); os.replace(HELPER_ID + ".new", HELPER_ID)
        log("paired with Rushes")
        if service_running()[0]:
            restart_service()
        self.set(said="Paired ✓ Rushes gives work to this Mac only. Any other Mac is refused, and Rushes names it.")

    def ask_help(self):
        """Help is asked for in the open: a new GitHub issue for Rushes, filled in
        with what to say, and the diagnostics on the Desktop to read first and
        attach only if nothing in it is private (issues are public)."""
        out = diagnostics(self.s["url"])
        try:
            with open(os.path.join(APP, "Contents", "Info.plist"), "rb") as f:
                ver = plistlib.load(f).get("CFBundleShortVersionString", "?")
        except (OSError, plistlib.InvalidFileException):
            ver = "?"
        body = ("**What happened**\n\n\n**What I expected**\n\n\n"
                f"{NAME} {ver} · macOS {platform.mac_ver()[0]} · {platform.machine()}\n\n"
                f"Diagnostics: {NAME} saved them on my Desktop. (Read the file first — issues are public — "
                "and drag it here only if nothing in it is private.)")
        subprocess.run(["open", "https://github.com/x0on/rushes/issues/new?" +
                        urllib.parse.urlencode({"title": "Help: ", "body": body})])
        self.set(said="A new GitHub issue is open in your browser, and " + os.path.basename(out) +
                 " is on your Desktop. Issues are public: read the file before you attach it. The issue page's address "
                 f"carries this {NAME}'s version, the macOS version and the processor type; nothing else was sent.")

    def switch(self, do):
        r = rushes(self.s["url"], "/db/helper.php", {"action": do})
        if r.get("error"):
            self.set(said="Did not happen: " + r["error"]); return
        self.set(said={
            "pause": "Copying paused ✓ It stops at its next safe point; nothing is lost. Describing is not affected.",
            "resume": "Copying resumed ✓ It carries on within a few seconds.",
            "describe-pause": "Describing paused ✓ It stops at its next safe point; files already described are kept. Copying is not affected.",
            "describe-resume": "Describing resumed ✓ It carries on with the next file.",
            "reconnect-off": "Off ✓ It no longer connects dropped network drives by itself — no more “problem connecting” windows. Connect them in Finder; it carries on once they are back.",
            "reconnect-on": "On ✓ It connects dropped network drives again by itself, only when the server answers.",
            "check-pause": "Checking paused ✓ It stops after the file it is reading; where it got to is kept.",
            "check-resume": "Checking resumed ✓ It carries on whenever there is nothing to copy."}[do])
        log(f"switch: {do}")

    def check(self, url):
        ok, why, blocked = reachable(url)
        if blocked:
            log(f"macOS refused the connection to {url}: Local Network is off for {NAME}")
            self.set(step="network"); return
        if not ok:
            log(f"could not reach {url}: {why}")
            self.set(step="address", error=f"Could not reach Rushes at {url}: {why or 'it answered, but not like Rushes'}. "
                                           "Check the address, and that this Mac is on the same network."); return
        log(f"Rushes is at {url}")
        self.set(step="install", done=[])
        self.install(url)

    def install(self, url):
        def did(line):
            log(line)
            with self.lock:
                self.s["done"] = self.s["done"] + [line]
        err = copy_to_applications()
        if err:
            raise RuntimeError(f"Could not copy {NAME} into {os.path.dirname(HOMEAPP)}: " + err)
        did(f"{NAME} is in {'Applications' if HOMEAPP.startswith('/Applications/') else 'Applications, in your home folder'}")
        if WATCHER:
            # Its code is inside the app; only where Rushes is, kept for it (paired in its window, next).
            os.makedirs(WDIR, exist_ok=True)
            cfg = read_json(os.path.join(WDIR, "config.json"))
            cfg["url"] = url
            with open(os.path.join(WDIR, "config.json.new"), "w") as f:
                json.dump(cfg, f)
            os.replace(os.path.join(WDIR, "config.json.new"), os.path.join(WDIR, "config.json"))
        elif local():
            did("Rushes and everything it needs are inside this app: nothing to download")
        else:
            fetch_files(url)
            did("Downloaded the copying and describing code from Rushes")
        if not install_service(url):
            raise RuntimeError("macOS would not start the background service. The detail is in ~/Library/Logs/Rushes/setup.log")
        did("Background service installed and started — it starts by itself when you log in")
        if has_full_disk_access():
            restart_service(); self.set(step="all-set")
        else:
            self.set(step="fda", waiting=False); self._watch_access()

    def install_quietly(self, url):
        try:
            self.install(url)
        except Exception as e:
            log(f"install: {e}"); self.set(error=str(e))

    def show_fda(self):
        """Settings at Full Disk Access, and the app in Finder to drag into the list."""
        open_pane(FDA_PANE)
        subprocess.run(["open", "-R", HOMEAPP])

    def _watch_access(self):
        def watch():
            while self.s["step"] == "fda" and not self.quit.is_set():
                if has_full_disk_access():
                    log("Full Disk Access is on")
                    restart_service()                    # so the running helper has it too
                    self.set(step="all-set"); return
                time.sleep(2)
        threading.Thread(target=watch, daemon=True).start()


PAGE = r"""<!doctype html><html><head><meta charset="utf-8"><title>%NAME%</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#f6f5f2;--card:#fff;--fg:#1d1d1b;--muted:#6b6a66;--line:#e3e1dc;--accent:#2f7d74;--accent-fg:#fff;--ok:#2f7d4a;--warn:#9a6a12;--bad:#b3261e;--side:#efede8;--sel:#dde8e5;--okbg:#dcefe2;--badbg:#f6dcd9}
@media (prefers-color-scheme:dark){:root{--bg:#1c1c1b;--card:#252523;--fg:#ecebe7;--muted:#a3a19b;--line:#3a3936;--accent:#4fa396;--accent-fg:#0d1f1c;--ok:#6cc08a;--warn:#e2b04a;--bad:#f08a80;--side:#222220;--sel:#2c3a37;--okbg:#1f3a29;--badbg:#45211e}}
*{box-sizing:border-box}html,body{margin:0;height:100%}
body{font:14px/1.5 -apple-system,BlinkMacSystemFont,"Helvetica Neue",sans-serif;background:var(--bg);color:var(--fg);display:flex;-webkit-user-select:none;user-select:none}
nav{width:210px;flex:none;padding:22px 12px 14px;border-right:1px solid var(--line);background:var(--side);display:flex;flex-direction:column}
nav h1{padding:0 4px}
nav h1{font-size:15px;margin:0 0 22px}nav h1 small{display:block;font-weight:400;color:var(--muted);font-size:12px}
nav ol{list-style:none;margin:0;padding:0}nav li{padding:7px 0 7px 26px;position:relative;color:var(--muted)}
nav li:before{content:"";position:absolute;left:4px;top:12px;width:10px;height:10px;border-radius:50%;border:2px solid var(--line)}
nav li.on{color:var(--fg);font-weight:600}nav li.on:before{border-color:var(--accent);background:var(--accent)}
nav li.ok{color:var(--fg)}nav li.ok:before{border-color:var(--ok);background:var(--ok)}
nav li.pg{padding:7px 10px;margin:1px 0;border-radius:7px;color:var(--fg);cursor:pointer;display:flex;align-items:center;gap:8px}
nav li.pg:before{display:none}nav li.pg:hover{background:rgba(127,127,127,.12)}nav li.pg.on{background:var(--sel);font-weight:600}
nav li.pg .pill{margin-left:auto;font-size:11px;background:var(--accent);color:var(--accent-fg);border-radius:9px;padding:0 7px;font-weight:600}
#navtop:empty,#navfoot:empty{display:none}#navtop{margin:0 0 16px}#navtop button{width:100%;padding:9px 12px;font-size:14.5px}
#navfoot{margin-top:auto;padding:10px 4px 0;border-top:1px solid var(--line);font-size:12px;color:var(--muted)}
#navfoot .lnk{padding:0;font-size:12px;color:var(--accent)}
.foot:empty{display:none}
.lnk{border:0;background:none;padding:0;color:var(--accent);cursor:pointer;font:inherit}.lnk:hover{text-decoration:underline}.lnk.danger{color:var(--bad)}
.sub{color:var(--muted);margin:-6px 0 18px}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:0 0 14px}.stats div{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:10px 14px}
.stats b{display:block;font-size:18px;font-variant-numeric:tabular-nums}.stats small{color:var(--muted)}
.feed{list-style:none;margin:0;padding:0}.feed li{display:flex;gap:10px;padding:7px 0;border-top:1px solid var(--line);margin:0;align-items:flex-start}
.feed li:first-child{border-top:0;padding-top:0}.feed time{color:var(--muted);font-variant-numeric:tabular-nums;width:42px;flex:none}
.feed .t{flex:1;min-width:0}.feed .day{color:var(--muted);font-size:12px;font-weight:600;padding:14px 0 2px;border:0}.feed .day:first-child{padding-top:0}
.ico{width:18px;height:18px;border-radius:50%;flex:none;display:grid;place-items:center;font-size:10px;background:var(--line);color:var(--muted);margin-top:1px}
.ico.ok{background:var(--okbg);color:var(--ok)}.ico.bad{background:var(--badbg);color:var(--bad)}
.chips{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px}.chips button{border-radius:14px;padding:2px 11px;font-size:12.5px}
.chips button.on{background:var(--fg);color:var(--bg);border-color:var(--fg)}
.head{display:flex;align-items:center;gap:12px}.head .t{flex:1}
main{flex:1;display:flex;flex-direction:column;min-width:0}
.body{flex:1;overflow:auto;padding:28px 32px}
h2{font-size:20px;margin:0 0 12px}p{margin:0 0 12px}.muted{color:var(--muted)}
.foot{display:flex;gap:10px;justify-content:flex-end;align-items:center;padding:14px 22px;border-top:1px solid var(--line)}
.foot .left{margin-right:auto;display:flex;gap:2px;flex-wrap:wrap}.foot button{white-space:nowrap}
.foot .lnk{border:0;background:none;padding:4px 7px;color:var(--muted);font-size:12.5px;font-weight:400}
.foot .lnk:hover{color:var(--fg);text-decoration:underline}.foot .lnk.danger:hover{color:var(--bad)}
button{font:inherit;padding:7px 16px;border-radius:7px;border:1px solid var(--line);background:var(--card);color:var(--fg);cursor:pointer}
button.go{background:var(--accent);border-color:var(--accent);color:var(--accent-fg);font-weight:600}
button:disabled{opacity:.5;cursor:default}
input{font:inherit;width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:7px;background:var(--card);color:var(--fg);-webkit-user-select:text;user-select:text}
.box{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px 16px;margin:0 0 14px}
.err{color:var(--bad)}.said{color:var(--ok)}
.spin{display:inline-block;width:11px;height:11px;border:2px solid var(--line);border-top-color:var(--accent);border-radius:50%;animation:s .9s linear infinite;vertical-align:-1px;margin-right:6px}
@keyframes s{to{transform:rotate(360deg)}}
ul.did{list-style:none;padding:0;margin:0}ul.did li{padding:3px 0}ul.did li:before{content:"✓ ";color:var(--ok)}
.row{display:flex;align-items:center;gap:12px;padding:10px 0;border-top:1px solid var(--line)}.row:first-child{border-top:0}
.row .t{flex:1}.row .t small{display:block;color:var(--muted)}
.sw{appearance:none;-webkit-appearance:none;width:38px;height:22px;border-radius:11px;background:var(--line);position:relative;cursor:pointer;flex:none;border:0;padding:0}
.sw:after{content:"";position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;background:#fff;transition:left .15s}
.sw.on{background:var(--accent)}.sw.on:after{left:18px}
.dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--muted);margin-right:6px}.dot.ok{background:var(--ok)}.dot.warn{background:var(--warn)}
pre{font:12px/1.45 ui-monospace,Menlo,monospace;white-space:pre-wrap;margin:0;color:var(--muted);-webkit-user-select:text;user-select:text}
h3{font-size:14px;margin:18px 0 6px}h4{font-size:13.5px;margin:16px 0 6px}ul,ol{margin:0 0 12px;padding-left:20px}li{margin:3px 0}table{width:100%;border-collapse:collapse;font-size:12.5px;-webkit-user-select:text;user-select:text}
th,td{text-align:left;vertical-align:top;padding:6px 8px;border-top:1px solid var(--line)}th{color:var(--muted);font-weight:500}
.big{font-size:40px;line-height:1;margin:6px 0 14px;color:var(--ok)}
</style></head><body>
<nav><h1>%NAME%<small>Rushes Media Management Software</small></h1><div id="navtop"></div><ol id="steps"></ol><div id="navfoot"></div></nav>
<main><div class="body" id="body"></div><div class="foot" id="foot"></div></main>
<script>
const K = location.pathname;       // the page's own key, needed for every question
const STEPS = [['welcome','Welcome'],['address','Where Rushes is'],['install','Installing'],['fda','Full Disk Access'],['all-set','All set']];
let S = {}, typed = null, credits = null, paircode = '';
const $ = id => document.getElementById(id);
const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
async function act(d, extra) {
  try { await fetch(K + 'act', {method: 'POST', body: JSON.stringify(Object.assign({do: d}, extra || {}))}); } catch (e) {}
  if (d === 'done') { document.body.innerHTML = ''; return; }
  poll();
}
// Open Rushes, only once Rushes answers: "Starting Rushes …" until then (Rushes on this Mac)
function openBtn(s, go) { return s.local_up === false ? btn('Starting Rushes …', 'open-rushes', go, true) : btn('Open Rushes', 'open-rushes', go); }
// What is seldom pressed is a quiet link, not a button that looks as important as Done
function lnk(label, d, cls) { return '<button class="lnk' + (cls ? ' ' + cls : '') + '" data-do="' + d + '">' + esc(label) + '</button>'; }
function btn(label, d, go, dis) { return '<button' + (go ? ' class="go"' : '') + (dis ? ' disabled' : '') + ' data-do="' + d + '">' + esc(label) + '</button>'; }
// What Rushes is made of: every part, what it does, its license. Opens over
// any screen and goes back to it.
function drawCredits() {
  $('body').innerHTML = md(credits);
  $('foot').innerHTML = '<button class="go" id="back">Back</button>';
  $('back').onclick = () => { credits = null; draw(); };
}
// Just enough Markdown for CREDITS.md: headings, paragraphs, lists, tables, bold.
function md(t) {
  const inl = s => esc(s).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
  const out = []; let cur = null;
  const close = () => {
    if (!cur) return;
    if (cur.tag === 'p') out.push('<p>' + inl(cur.text) + '</p>');
    else if (cur.tag === 'table') out.push('<table>' + cur.rows.join('') + '</table>');
    else out.push('<' + cur.tag + '>' + cur.items.map(x => '<li>' + inl(x) + '</li>').join('') + '</' + cur.tag + '>');
    cur = null;
  };
  t.split('\n').forEach(l => {
    let m;
    if (!l.trim()) return close();
    if ((m = l.match(/^(#{1,3}) (.*)/))) { close(); const h = 'h' + (m[1].length + 1); out.push('<' + h + '>' + inl(m[2]) + '</' + h + '>'); return; }
    if (l.startsWith('|')) {
      if (/^\|[-| ]+\|$/.test(l)) return;
      const c = l.split('|').slice(1, -1).map(x => x.trim());
      if (!cur || cur.tag !== 'table') { close(); cur = {tag: 'table', rows: ['<tr>' + c.map(x => '<th>' + inl(x) + '</th>').join('') + '</tr>']}; }
      else cur.rows.push('<tr>' + c.map(x => '<td>' + inl(x) + '</td>').join('') + '</tr>');
      return;
    }
    if ((m = l.match(/^(-|\d+\.) (.*)/))) {
      const tag = m[1] === '-' ? 'ul' : 'ol';
      if (!cur || cur.tag !== tag) { close(); cur = {tag: tag, items: []}; }
      cur.items.push(m[2]); return;
    }
    if (cur && (cur.tag === 'ul' || cur.tag === 'ol') && /^\s/.test(l)) { cur.items[cur.items.length - 1] += ' ' + l.trim(); return; }
    if (cur && cur.tag === 'p') { cur.text += ' ' + l.trim(); return; }
    close(); cur = {tag: 'p', text: l.trim()};
  });
  close();
  return out.join('');
}
function draw() {
  if (credits != null) return;              // reading the list: the screen underneath waits
  const s = S, busy = s.busy ? '<p><span class="spin"></span>' + esc(s.busy) + '</p>' : '';
  const setupStep = STEPS.findIndex(x => x[0] === (s.step === 'network' ? 'address' : s.step === 'later' ? 'fda' : s.step));
  // Set up: the side panel is the menu (its pages); setting up: it shows the steps
  const atHome = s.step === 'home', pages = s.watcher ? WPAGES : PAGES;
  if (atHome && !pages.some(x => x[0] === page)) page = 'ov';
  $('navtop').innerHTML = atHome ? openBtn(s, true) : '';
  $('navfoot').innerHTML = atHome ? 'Version ' + esc(s.version) + ' · <button class="lnk" data-credits="1">What it is made of</button>' : '';
  $('steps').innerHTML = atHome ? pages.map(x => '<li class="pg' + (x[0] === page ? ' on' : '') + '" data-page="' + x[0] + '">' + x[1] +
      (x[0] === 'help' && s.newer ? '<span class="pill">update</span>' : '') + '</li>').join('')
    : s.step === 'remove' || s.step === 'removed' ? ''
    : STEPS.map((x, i) => '<li class="' + (i < setupStep ? 'ok' : i === setupStep ? 'on' : '') + '">' + x[1] + '</li>').join('');
  let b = '', f = '';
  const err = s.error ? '<p class="err">' + esc(s.error) + '</p>' : '';
  switch (s.step) {
  case 'welcome':
    b = '<h2>Set up %NAME%</h2>' + (s.watcher
      ? '<p>Rushes Watcher keeps every Premiere project you save, wherever you keep it on this Mac, with the files it uses, in your Rushes archive, without you pressing anything. ' +
        'It sends the files a project uses that the archive does not have yet (music, stock, downloads, graphics, voiceover: only what you imported), and makes an <b>Output</b> folder beside each project: export the finished work there and it is kept too. ' +
        'When you quit Premiere, a dated copy of each project you saved is kept, pointing at the archive. Your files on this Mac are only read, never moved or changed.</p>' +
        '<p>Its icon in the menu bar shows what it is doing, with its switches. While it runs, the icon is there.</p>'
      : '<p>Rushes looks after your footage: it finds it (Search), brings cards and drives in, keeps copies checked, and tidies the archive. ' +
        'The archive can be a drive on this Mac, or a Rushes server on your network. Its icon in the menu bar shows what it is doing.</p>') +
      '<p>It carries its own copy of Python — the free, open-source programming language it is written in. That copy lives inside this app and nothing else uses it, so nothing on this Mac is changed or needs updating.</p>' +
      '<p>Setting up takes a minute, in this window: where Rushes is, and two permissions from macOS — to talk to your network (press Allow when macOS asks), and one switch in System Settings. The last step tells you when everything is done.</p>';
    f = btn('Cancel', 'done') + btn('Set up', 'start', true); break;
  case 'address':
    b = '<h2>Where is Rushes?</h2><p>The address you open Rushes at in the browser. Rushes → Setup shows it, with a Copy button.</p>' +
      '<input id="url" placeholder="http://" value="' + esc(typed != null ? typed : s.url) + '">' +
      '<p class="muted" style="margin-top:10px">If macOS asks whether %NAME% may find and connect to devices on your local network, press Allow.</p>' +
      (s.watcher ? '' : '<div class="box" style="margin-top:18px"><div class="row"><div class="t"><b>Or: the archive is a drive on this Mac</b><small>No server: ' +
        'Rushes runs inside this app, and you open it from the menu bar icon (Open Rushes). Choose the drive, or a folder on one. ' +
        'Other devices can be let in later, with a password.</small></div>' + btn('Choose the drive…', 'local-pick', false, !!s.busy) + '</div></div>') + err + busy;
    f = btn('Cancel', s.set_up ? 'back-home' : 'done') + btn('Next', 'address', true, !!s.busy); break;
  case 'network':
    b = '<h2>macOS is not letting %NAME% talk to your network yet</h2>' +
      '<p>If it asked whether %NAME% may “find and connect to devices on your local network”, press Allow, then Try again.</p>' +
      '<p>If it did not ask: open Local Network settings, turn on %NAME%, then Try again.</p>' + err + busy;
    f = btn('Cancel', 'done') + btn('Open Local Network settings', 'network-settings') + btn('Try again', 'retry', true, !!s.busy); break;
  case 'install':
    b = '<h2>Installing</h2><ul class="did">' + (s.done || []).map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>' +
      (s.busy ? '<p style="margin-top:10px"><span class="spin"></span>Working …</p>' : '') + err;
    f = s.error ? btn('Try again', 'retry', true) : ''; break;
  case 'fda':
    b = '<h2>One switch left: Full Disk Access</h2>' +
      '<p>macOS keeps apps away from network drives and other disks until you allow it. ' + (s.watcher ? 'Rushes Watcher needs that to read your projects and the files they use, wherever they are on this Mac — nothing more.'
        : 'Rushes needs that to reach your drives: to read cards and drives when footage comes in, and to keep the archive and check its copies — nothing more.') + '</p>' +
      '<p><b>Open System Settings</b> below: it opens at Full Disk Access, and Finder shows %NAME%. Turn %NAME% on in the list. If it is not in the list, drag it from the Finder window into the list (or press + and pick it from Applications).</p>' +
      '<p class="muted">If System Settings opens somewhere else, type Full Disk Access into its search field, top left.</p>' +
      (s.waiting ? '<div class="box"><span class="spin"></span>Waiting for the switch … this window moves on by itself the moment it is on.</div>' : '');
    f = btn('Later', 'later') + btn(s.waiting ? 'Show me where again' : 'Open System Settings', 'fda-open', true); break;
  case 'later':
    b = '<h2>Not finished yet</h2><p>%NAME% is installed and running, but it cannot reach the drives until Full Disk Access is on.</p><p>Open %NAME% again any time to finish; it starts right at that step.</p>';
    f = btn('Done', 'done', true); break;
  case 'all-set':
    b = '<div class="big">✓</div><h2>All set</h2>' +
      (s.watcher ? '<p>Rushes Watcher is set up. It runs in the background, starts when you log in, and its icon is in the menu bar.</p>' +
        '<p><b>One thing left: pair it with Rushes.</b> In Rushes → Setup → Editors\' work, press Add an editor\'s computer, and type the six numbers on the next screen.</p>'
      : s.local ? '<p>Rushes is set up. It runs in the background, starts when you log in, and restarts itself if it stops.</p>' +
        '<p><b>Open Rushes</b> for Search, Ingest and Manage, in your browser: here, or from the menu bar icon. Open this window again any time to see what Rushes is doing, and what happened.</p>'
      : '<p>Rushes is set up on this Mac. It does your Rushes server\'s copying and describing in the background, starts when you log in, restarts itself if it stops, and keeps that code the same as the server\'s.</p>' +
        '<p>Rushes → Manage shows what it is doing, and so does this window: open it again any time.</p>') +
      '<p class="muted">macOS may show a notice that %NAME% can run in the background — that is this.</p>';
    f = s.watcher ? btn('Pair with Rushes', 'back-home', true) : btn('Done', 'done') + openBtn(s, true); break;
  // No row of buttons: the window is a place you visit, closed with its red dot. Open Rushes is in the side panel.
  case 'home': b = s.watcher ? whome(s) : home(s); f = ''; break;
  case 'remove':
    b = '<h2>Remove %NAME% from this Mac?</h2><p>It stops, and no longer starts at login. ' + (s.watcher ? 'Projects already sent stay in the archive.' : 'The archive and its footage stay; anything half-copied carries on if you set it up again.') + '</p>';
    f = btn('Cancel', 'back-home') + btn('Remove', 'remove-yes', true); break;
  case 'removed':
    b = '<div class="big">✓</div><h2>Removed</h2><p>It will not start again.</p><p>To finish, drag %NAME% from Applications to the Trash, and switch it off in Full Disk Access. Its notes stay until you delete them: ' +
      (s.watcher ? '~/Library/Application Support/Rushes Watcher (its pairing and what it delivered) and ~/Library/Logs/Rushes Watcher. Projects keep their “Rushes backups” folders.'
        : '~/archive-pilot (its progress and pairing), ~/Library/Application Support/Rushes and ~/Library/Logs/Rushes.') + '</p>';
    f = btn('Done', 'done', true); break;
  }
  const made = '<button class="lnk" data-credits="1">What it is made of</button>';
  if (['welcome', 'all-set'].includes(s.step)) f = (f.includes('class="left"') ? f.replace('<span class="left">', '<span class="left">' + made)
    : '<span class="left">' + made + '</span>' + f);
  const keep = $('url') && document.activeElement === $('url');
  const keepCode = $('paircode') && document.activeElement === $('paircode');
  $('body').innerHTML = b; $('foot').innerHTML = f;
  if ($('paircode')) { $('paircode').oninput = e => paircode = e.target.value; if (keepCode) $('paircode').focus();
    $('paircode').onkeydown = e => { if (e.key === 'Enter') act('pair', {code: $('paircode').value}); }; }
  if ($('url')) { $('url').oninput = e => typed = e.target.value; if (keep) { $('url').focus(); } $('url').onkeydown = e => { if (e.key === 'Enter') act('address', {url: $('url').value}); }; }
  document.querySelectorAll('[data-credits]').forEach(x => x.onclick = async () => {
    try { credits = await (await fetch(K + 'credits')).text(); } catch (e) { credits = 'Could not read the list.'; }
    drawCredits();
  });
  document.querySelectorAll('[data-do]').forEach(x => x.onclick = () => {
    const d = x.dataset.do; x.disabled = true;
    act(d, d === 'address' ? {url: $('url').value} : d === 'pair' ? {code: $('paircode').value} : null);
  });
}
// The pages of the side panel, once set up (draw: the nav). Which one is open stays here, in the window.
const PAGES = [['ov', 'Overview'], ['act', 'Activity'], ['work', 'Work'], ['arch', 'Archive'], ['dev', 'Other devices'], ['help', 'Help']];
const WPAGES = [['ov', 'Overview'], ['act', 'Activity'], ['work', 'Work'], ['help', 'Help']];
let page = 'ov', kind = 'all', raw = false;
document.addEventListener('click', e => {
  const t = e.target.closest('[data-page],[data-kind],[data-raw]');
  if (!t) return;
  if (t.dataset.page) { page = t.dataset.page; raw = false; credits = null; draw(); $('body').scrollTop = 0; }
  else if (t.dataset.kind) { kind = t.dataset.kind; draw(); }
  else { raw = !raw; draw(); }
});
const size = b => b >= 1e12 ? (b / 1e12).toFixed(2) + ' TB' : b >= 1e9 ? Math.round(b / 1e9).toLocaleString() + ' GB' : Math.round(b / 1e6).toLocaleString() + ' MB';
const ago = t => { const m = Math.round((Date.now() / 1000 - t) / 60);
  return !t ? 'not yet' : m < 2 ? 'just now' : m < 60 ? m + ' min ago' : m < 1440 ? Math.round(m / 60) + ' h ago' : Math.round(m / 1440) + ' days ago'; };
const base = p => String(p || '').replace(/\/+$/, '').split('/').pop();
const sw = (on_, ids, title, sub, dis) => '<div class="row"><div class="t">' + title + '<small>' + sub + '</small></div>' +
  '<button class="sw' + (on_ ? ' on' : '') + '" data-do="' + (on_ ? ids[1] : ids[0]) + '"' + (dis ? ' disabled' : '') + ' title="' + (on_ ? 'Turn off' : 'Turn on') + '"></button></div>';
// What a button did, or did not do, said where it happened: on every page
const said = s => (s.error ? '<p class="err">Did not happen: ' + esc(s.error) + '</p>' : '') +
  (s.said ? '<p class="said">' + esc(s.said) + '</p>' : '') + (s.busy ? '<p><span class="spin"></span>' + esc(s.busy) + '</p>' : '');
const head = (h, sub) => '<h2>' + h + '</h2><p class="sub">' + sub + '</p>';
// A newer version on Rushes: one button, it puts itself in place and starts again.
const upd = s => s.newer ? '<div class="box"><div class="row"><div class="t"><b>Rushes ' + esc(s.newer) + ' is ready</b><small>This is ' +
  esc(s.version) + '. ' + (s.local ? 'It comes from Rushes\'s releases on GitHub' : 'It comes from your Rushes') +
  ', and is taken only if signed by its author. Settings, pairing and permissions stay; nothing to download or open.</small></div>' +
  btn('Update to ' + s.newer, 'update-app', true, !!s.busy) + '</div></div>' : '';

// Activity (db/activity.php): what came in, what went out, what changed, and who did it
const ICO = {in: ['↓', 'ok'], out: ['↑', ''], changed: ['•', ''], check: ['✓', 'ok'], problem: ['!', 'bad'], people: ['☺', '']};
const KINDS = [['all', 'Everything'], ['in', 'In'], ['out', 'Out'], ['changed', 'Changes'], ['check', 'Checks'], ['problem', 'Problems']];
function feed(evs, days) {
  let day = '', out = '';
  const ymd = t => { const d = new Date(t); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  const today = ymd(Date.now()), yday = ymd(Date.now() - 864e5);     // this Mac's own days, as Rushes writes its times
  evs.forEach(r => {
    const d = String(r.at).slice(0, 10), i = ICO[r.kind] || ICO.changed;
    if (days && d !== day) { day = d; out += '<li class="day">' + (d === today ? 'Today' : d === yday ? 'Yesterday' : esc(d)) + '</li>'; }
    out += '<li><time>' + esc(String(r.at).slice(11, 16)) + '</time><span class="ico ' + i[1] + '">' + i[0] + '</span><div class="t">' +
      (r.who ? '<b>' + esc(r.who) + '</b> · ' : '') + esc(r.text) + '</div></li>';
  });
  return '<ul class="feed">' + out + '</ul>';
}
const rawLog = s => '<div class="box"' + (raw ? '' : ' hidden') + '><div class="muted" style="margin-bottom:6px">The technical log, newest last <span style="float:right">' +
  lnk('Open the whole file', 'show-log') + '</span></div><pre>' + esc((s.log || []).join('\n') || 'Nothing written yet.') + '</pre></div>';
const rawLink = () => '<p class="muted" style="font-size:12.5px"><button class="lnk" data-raw="1">' + (raw ? 'Hide the technical log' : 'Show the technical log') +
  '</button> · for when something is wrong: every step, as this Mac wrote it</p>';

const PHASE = {copying:'Copying', looking:'Looking for new footage', waiting:'Waiting', tracing:'Matching earlier copies to their originals',
  analysing:'Describing footage', tidying:'Tidying up', idle:'Nothing to describe', paused:'Paused', blocked:'Stopped: needs you', done:'Finished', stopped:'Stopped', planned:'Planned', proving:'Checking copies (reading only)'};
function home(s) {
  const n = s.now || {}, on = s.running, L = s.local;
  const state = !on ? '<span class="dot"></span><b>Stopped</b> — Rushes does nothing until you turn it on in Work.'
    : s.stopped ? '<span class="dot warn"></span><b>Stopped by itself</b> — ' + esc(s.stopped) + '<div style="margin-top:8px">' + btn('Try again', 'try-again', true, !!s.busy) + '</div>'
    : s.paused ? '<span class="dot warn"></span><b>Paused</b> — running, but not starting any work. Work turns it back on.'
    : '<span class="dot ok"></span><b>Rushes is running</b>';
  const where = L ? 'Archive: ' + esc(base(L.archive)) + (L.there ? '' : ' — <b>not plugged in</b>: Search still shows its files, marked not plugged in')
    : 'Connected to Rushes at ' + esc(s.url);
  const now = s.rushes === null ? '<span class="spin"></span>Asking Rushes …'
    : !s.rushes ? '<span class="muted">Rushes cannot be reached right now (' + esc(s.rushes_why) + '). Activity\'s technical log still shows this Mac\'s work.</span>'
    : n.phase ? '<b>' + esc(PHASE[n.phase] || n.phase) + '</b>' + (n.source ? ' · ' + esc(base(n.source)) : '') +
        (n.note ? '<div class="muted">' + esc(n.note) + '</div>' : '') + (n.file ? '<div class="muted">now: ' + esc(base(n.file)) + '</div>' : '')
    : '<span class="muted">Nothing to do right now.</span>';
  const st = s.stats, ev = s.activity || [];
  const unpaired = !L && s.rushes !== null && s.pairing !== 'this';
  switch (page) {
  case 'act':
    const shown = ev.filter(r => kind === 'all' || r.kind === kind || (kind === 'changed' && r.kind === 'people'));
    return head('Activity', 'What came in, what went out, what changed, and who did it. Newest first.') + said(s) +
      '<div class="chips">' + KINDS.map(k => '<button data-kind="' + k[0] + '"' + (k[0] === kind ? ' class="on"' : '') + '>' + k[1] + '</button>').join('') + '</div>' +
      '<div class="box">' + (s.activity === undefined ? (s.rushes === false ? '<p class="muted">Rushes cannot be reached right now, so its activity is not here. The technical log below still shows this Mac\'s work.</p>'
        : '<p class="muted"><span class="spin"></span>Asking Rushes …</p>')
        : shown.length ? feed(shown, true) : '<p class="muted">Nothing ' + (kind === 'all' ? 'yet.' : 'of this kind yet.') + '</p>') + '</div>' +
      '<p class="muted" style="font-size:12.5px">Names are the ones people gave Rushes in their browser, and editors\' computers by their pairing. Rushes → Manage → Activity shows the same.</p>' +
      rawLink() + rawLog(s);
  case 'work':
    return head('Work', 'What Rushes is allowed to do. Each switch pauses at a safe point; nothing is lost.') + said(s) + '<div class="box">' +
      sw(on, ['service-on', 'service-off'], 'Run in the background', 'Starts when you log in and restarts itself if it stops. Off stops Rushes completely, also after a restart, until you turn it on here.') +
      sw(on && !s.paused, ['resume', 'pause'], 'Copy footage', 'Cards and drives brought into the archive. Rushes → Manage has the same switch.', !on || !s.rushes) +
      sw(on && !s.describe_paused, ['describe-resume', 'describe-pause'], 'Describe footage',
        'This Mac\'s chip reads each shot and what is said, so Search finds it. Files already described are kept.' +
        (s.describing && s.describing.phase === 'analysing' ? ' Now: ' + esc(s.describing.label || '') + (s.describing.of ? ' · ' + esc(s.describing.n) + ' of ' + esc(s.describing.of) : '') : ''), !on || !s.rushes) +
      sw(on && !s.check_paused, ['check-resume', 'check-pause'], 'Check copies',
        'When there is nothing to copy: copies are read again against their originals, then every file now and then against its fingerprint. Reading only.', !on || !s.rushes) +
      (L ? '' : sw(!s.no_reconnect, ['reconnect-on', 'reconnect-off'], 'Reconnect network drives by itself', 'When a drive drops, it connects it again once the server answers. Off: you connect drives in Finder.', !s.rushes)) +
    '</div>';
  case 'arch':
    return head('Archive', 'Where your footage lives.') + said(s) + (L
      ? '<div class="box"><div class="head"><div class="t"><span class="dot' + (L.there ? ' ok' : ' warn') + '"></span><b>' + esc(base(L.archive)) + '</b><small class="muted" style="display:block">' +
          esc(L.archive) + ' · ' + (L.there ? 'plugged in' + (st && st.free ? ' · ' + size(st.free) + ' free' : '') : 'not plugged in: plug it in and Rushes carries on by itself') + '</small></div>' +
          btn('Show in Finder', 'reveal-archive', false, !L.there) + '</div></div>' +
        '<div class="box"><div class="head"><div class="t">Move Rushes somewhere else<small class="muted" style="display:block">Another drive on this Mac, or a Rushes server on your network. Your footage is not touched.</small></div>' +
          btn('Change…', 'change-where') + '</div></div>'
      : '<div class="box"><div class="head"><div class="t"><span class="dot' + (s.rushes ? ' ok' : ' warn') + '"></span><b>A Rushes server</b><small class="muted" style="display:block">' + esc(s.url) +
          ' · ' + (s.rushes ? 'answering' : s.rushes === null ? 'asking …' : 'not answering right now') + '. This Mac does its copying and describing.</small></div>' + btn('Change…', 'change-where') + '</div></div>') +
      '<p style="margin-top:28px;font-size:12.5px"><button class="lnk danger" data-do="remove">Remove Rushes from this Mac…</button> <span class="muted">· it stops and no longer starts at login; the archive and its footage stay.</span></p>';
  case 'dev':
    const ws = s.watchers || [];
    return head('Other devices', 'Phones and computers that may open Rushes, and editors\' computers sending their projects.') + said(s) +
      (L ? '<div class="box">' + sw(L.others, ['others-on', 'others-off'], 'Let other devices open Rushes', L.others
          ? 'On: phones and computers on your network, or on your Tailscale, open <b>http://' + esc(L.name) + ':' + esc(L.port) + '</b> (or this Mac\'s Tailscale address, port ' + esc(L.port) + ') and sign in with Rushes\' password.'
          : 'Off: only this Mac. On: others sign in with Rushes\' password, once you have set your own in Rushes → Manage.') + '</div>' : '') +
      (unpaired ? pairBox(s) : !L && s.pairing === 'this' ? '<p class="muted" style="font-size:12.5px">Paired with Rushes ✓ — it gives work to this Mac only.</p>' : '') +
      '<div class="box"><div class="muted" style="margin-bottom:6px">Editors\' computers (Rushes Watcher)</div>' +
      (s.watchers === undefined ? '<p class="muted">' + (s.rushes === false ? 'Rushes cannot be reached right now.' : '<span class="spin"></span>Asking Rushes …') + '</p>'
        : ws.length ? ws.map(w => '<div class="row"><div class="t"><b>' + esc(w.name) + '</b>' + (w.host && w.host !== w.name ? ' <span class="muted">· ' + esc(w.host) + '</span>' : '') +
            '<small>' + (w.seen ? 'Last heard from ' + ago(w.seen) + (w.state ? ' · ' + esc(w.state) : '') : 'Added ' + ago(w.added) + ', not heard from yet') + '</small></div>' +
            '<span class="dot' + (w.seen && Date.now() / 1000 - w.seen < 900 ? ' ok' : '') + '"></span></div>').join('')
        : '<p class="muted">None yet. Each editor\'s Mac gets Rushes Watcher; Rushes → Setup → Editors\' work adds it.</p>') +
      '<div class="row">' + btn('Open Setup', 'open-setup') + '</div></div>';
  case 'help':
    return head('Help', 'Help happens in the open, with only what you choose to share.') + said(s) + upd(s) + '<div class="box">' +
      '<div class="row"><div class="t">Updates<small>' + (L ? 'Rushes and all its parts come inside this app, signed by its author. Check for updates looks at Rushes\'s releases on GitHub (github.com/x0on/rushes); it changes only when you press Update.'
        : 'This Mac keeps its copying and describing code the same as your Rushes server\'s (' + esc(s.url) + ', never anywhere else). The app itself changes only when you press Update.') +
        ' This is ' + esc(s.version) + '.</small></div>' + btn('Check for updates', 'check-updates', false, !!s.busy) + '</div>' +
      '<div class="row"><div class="t">Collect diagnostics<small>One text file on your Desktop, to read before you send it to anyone: versions, switches, what Rushes did lately. ' +
        'It names folders and files; it holds no footage and no passwords. Nothing is sent.</small></div>' + btn('Collect', 'diagnostics', false, !!s.busy) + '</div>' +
      '<div class="row"><div class="t">Ask for help<small>Opens a new issue for Rushes on GitHub, where help is asked for in the open, and saves the diagnostics on your Desktop. ' +
        'GitHub issues are public: attach the file only if nothing in it is private.</small></div>' + btn('Ask for help', 'ask-help', false, !!s.busy) + '</div>' +
      '<div class="row"><div class="t">Support access<small>None. Nobody — the author, IT or anyone else — can connect to this Mac through Rushes.</small></div></div>' +
    '</div>';
  default:
    return head('Overview', 'How Rushes is doing, at a glance.') + said(s) + upd(s) +
      '<div class="box">' + state + '<div class="muted" style="margin-top:4px">' + where + '</div></div>' +
      (st ? '<div class="stats"><div><b>' + Number(st.files || 0).toLocaleString() + '</b><small>files in the archive</small></div><div><b>' + size(st.bytes || 0) +
        '</b><small>of footage</small></div><div><b>' + (st.free ? size(st.free) : '—') + '</b><small>free' + (L ? ' on ' + esc(base(L.archive)) : ' in the archive') + '</small></div></div>' : '') +
      '<div class="box"><div class="muted" style="margin-bottom:6px">Doing now</div>' + now + '</div>' +
      (unpaired ? '<div class="box"><div class="head"><div class="t"><b>Not paired with Rushes yet</b><small class="muted" style="display:block">Rushes gives this Mac no work until it is: Other devices has the place for the code.</small></div>' +
        '<button data-page="dev">Pair…</button></div></div>' : '') +
      '<div class="box"><div class="head" style="margin-bottom:8px"><div class="t muted">Lately</div><button class="lnk" data-page="act">See all activity →</button></div>' +
        (ev.length ? feed(ev.slice(0, 4), false) : '<p class="muted">' + (s.activity === undefined ? 'Asking Rushes …' : 'Nothing yet.') + '</p>') + '</div>';
  }
}
// The place to type the code, until this Mac is the one Rushes gives work to (a Rushes server, not on this Mac)
function pairBox(s) {
  return '<div class="box"><div class="row"><div class="t">' +
    (s.pairing === 'other' ? '<b>Rushes gives its work to another Mac</b><small>This Mac is given no work and touches nothing. To use this Mac instead, '
      : s.pairing === 'none' ? '<b>Not paired yet</b><small>Rushes gives work to any Mac on the network until one is paired; two at once would copy over each other. To pair this one, '
      : '<b>Pair this Mac</b><small>Rushes did not say whether this Mac is paired (it is slow to answer right now). If Rushes → Setup says none is paired, or names another computer, ') +
    'open Rushes → Setup → Pair a Mac and type the six numbers here. The Mac paired before is refused from then on.</small></div></div>' +
    '<div class="row"><input id="paircode" inputmode="numeric" maxlength="7" placeholder="123456" style="width:9em" value="' + esc(paircode) + '">' +
    btn('Pair', 'pair', false, !!s.busy) + ' ' + btn('Paste the code from Rushes', 'paste-pair', true, !!s.busy) + '</div></div>';
}
// Rushes Watcher on this computer: everything it shows is on this computer.
const WATCHING = {idle: 'Idle — no editing program open', watching: 'Watching — an editing program is open', delivering: 'Sending a project\'s files to Rushes',
  pointing: 'Pointing projects at the archive', offline: 'Cannot reach Rushes', unpaired: 'Not paired with Rushes yet', paused: 'Paused'};
function whome(s) {
  const n = s.now || {}, on = s.running;
  const state = !on ? '<span class="dot"></span><b>Stopped</b> — it does nothing until you turn it on in Work.'
    : s.paused ? '<span class="dot warn"></span><b>Paused</b> — running, but it looks at no project.'
    : '<span class="dot ok"></span><b>Running in the background</b>';
  const lines = (s.log || []).slice().reverse();
  switch (page) {
  case 'act':
    return head('Activity', 'What this computer sent to Rushes, in its own words. Newest first.') + said(s) +
      '<div class="box">' + (lines.length ? '<ul class="feed">' + lines.map(l => '<li><div class="t">' + esc(l) + '</div></li>').join('') + '</ul>' : '<p class="muted">Nothing yet.</p>') + '</div>' +
      '<p class="muted" style="font-size:12.5px">Rushes → Manage → Activity shows what every editor\'s computer sent, with everything else that happened. ' + lnk('Open the whole log file', 'show-log') + '</p>';
  case 'work':
    return head('Work', 'What Rushes Watcher is allowed to do.') + said(s) + '<div class="box">' +
      sw(on, ['service-on', 'service-off'], 'Run in the background', 'Starts when you log in. Off stops it completely, also after a restart, until you turn it on here. Its icon goes with it.') +
      sw(!s.paused, ['watch-resume', 'watch-pause'], 'Watch projects', 'Off: it looks at no project; nothing already delivered changes. The menu bar icon has the same switch.', !on) +
    '</div>';
  case 'help':
    return head('Help', 'Help happens in the open, with only what you choose to share.') + said(s) + upd(s) + '<div class="box">' +
      '<div class="row"><div class="t">Updates<small>This app changes only when you press Update. This is ' + esc(s.version) + '.</small></div>' + btn('Check for updates', 'check-updates', false, !!s.busy) + '</div>' +
      '<div class="row"><div class="t">Collect diagnostics<small>Versions, switches and what it said lately, in one text file on your Desktop, to read before you send it to anyone. Nothing is sent.</small></div>' +
        btn('Collect', 'diagnostics', false, !!s.busy) + '</div>' +
      '<div class="row"><div class="t">Ask for help<small>Opens a new issue for Rushes on GitHub, where help is asked for in the open. Issues are public: read the diagnostics before you attach them.</small></div>' +
        btn('Ask for help', 'ask-help', false, !!s.busy) + '</div>' +
      '<div class="row"><div class="t">Support access<small>None. There is no way for anyone to connect to this Mac through Rushes Watcher.</small></div></div>' +
    '</div>';
  default:
    return head('Overview', 'Keeps every project you save, with the files it uses, in your Rushes archive.') + said(s) + upd(s) +
      '<div class="box">' + state + '<div class="muted" style="margin-top:4px">Rushes at ' + esc(s.url) + '</div></div>' +
      '<div class="box"><div class="muted" style="margin-bottom:6px">Doing now</div><b>' + esc(WATCHING[n.state] || n.state || 'Starting') + '</b>' + (n.note ? ' · ' + esc(n.note) : '') + '</div>' +
      (!s.paired ? '<div class="box"><div class="row"><div class="t"><b>Not paired yet</b><small>It sends nothing until it is. In Rushes → Setup → Editors\' work, ' +
        'press Add an editor\'s computer, and type the six numbers here. (A code for the Mac that copies does not work here, so an editor\'s computer never takes its place.)</small></div></div>' +
        '<div class="row"><input id="paircode" inputmode="numeric" maxlength="7" placeholder="123456" style="width:9em" value="' + esc(paircode) + '">' +
        btn('Pair', 'pair', false, !!s.busy) + ' ' + btn('Paste the code from Rushes', 'paste-pair', true, !!s.busy) + '</div></div>'
        : '<p class="muted" style="font-size:12.5px">Paired with Rushes ✓ — Rushes lists this computer in Other devices and in Setup → Editors\' work.</p>') +
      '<div class="box"><div class="head" style="margin-bottom:8px"><div class="t muted">Lately</div><button class="lnk" data-page="act">See all →</button></div>' +
        (lines.length ? '<ul class="feed">' + lines.slice(0, 4).map(l => '<li><div class="t">' + esc(l) + '</div></li>').join('') + '</ul>' : '<p class="muted">Nothing yet.</p>') + '</div>';
  }
}
// Asks again 1.5 s after each answer, never while the last question is still out.
let polling = false;
async function poll() {
  if (polling) return;
  polling = true;
  try { S = await (await fetch(K + 'state')).json(); draw(); } catch (e) {}
  polling = false;
}
(function again() { poll().then(function () { setTimeout(again, 1500); }); })();
</script></body></html>"""


def serve(port, key):
    w = Window()
    log(f"window opened ({w.s['step']})")

    class H(http.server.BaseHTTPRequestHandler):
        def log_message(self, *a):
            pass

        def _send(self, code, body, kind="application/json"):
            data = body.encode() if isinstance(body, str) else body
            self.send_response(code)
            self.send_header("Content-Type", kind + "; charset=utf-8")
            self.send_header("Cache-Control", "no-store")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)

        def do_GET(self):
            if self.path == f"/{key}/":
                return self._send(200, PAGE.replace("%NAME%", NAME), "text/html")
            if self.path == f"/{key}/credits":
                try:
                    return self._send(200, open(os.path.join(APP, "Contents", "Resources", "CREDITS.md"), "rb").read(), "text/plain")
                except OSError:
                    return self._send(200, "The list is missing from this copy of the app. It is also at github.com/x0on/rushes (CREDITS.md).", "text/plain")
            if self.path == f"/{key}/state":
                return self._send(200, json.dumps(w.state()))
            self._send(404, "{}")

        def do_POST(self):
            if self.path != f"/{key}/act":
                return self._send(404, "{}")
            try:
                a = json.loads(self.rfile.read(min(int(self.headers.get("Content-Length") or 0), 10000)) or b"{}")
                w.act(str(a.get("do", "")), a)
            except Exception as e:
                log(f"window: {e}")
            self._send(200, "{}")

    srv = http.server.ThreadingHTTPServer(("127.0.0.1", port), H)
    srv.daemon_threads = True
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    w.quit.wait()
    time.sleep(0.3)                        # the Done answer reaches the page first
    log("window closed")
    return 0


# ── the menu bar icon: what it says, and what its choices do ────────────────
# The launcher draws the icon and the menu (launcher.c); this answers it, on
# this computer only, behind the same kind of random key as the window. The
# menu holds every switch someone changes day to day, as Tailscale's does:
# each acts at once and can be turned back. What cannot be undone (Remove,
# pairing) is in the window, which asks twice.
PHASE = {"copying": "Copying", "looking": "Looking for new footage", "waiting": "Waiting — nothing queued",
         "tracing": "Matching earlier copies", "analysing": "Describing footage", "tidying": "Tidying up",
         "delivering": "Taking in an editor's delivery", "paused": "Paused from Manage", "blocked": "Stopped: needs you",
         "done": "Finished", "stopped": "Stopped", "proving": "Checking copies (reading only)"}
WATCHING = {"idle": "Idle — no editing program open", "watching": "Watching — an editing program is open",
            "delivering": "Sending a project's files to Rushes", "pointing": "Keeping projects on the NAS",
            "paused": "Paused — it looks at no project", "offline": "Cannot reach Rushes",
            "unpaired": "Not paired with Rushes yet"}


# ── one Rushes icon on a Mac ────────────────────────────────────────────────
# A Mac can run more than one Rushes app (Rushes Helper, Rushes Watcher; Rushes
# itself, later): each does its own work, but the menu bar has one Rushes icon.
# The first of them that runs draws it, with a section for each other one; the
# others hide theirs. Quit one and the next draws it, so there is always one.
ROLES = ("Rushes", "Rushes Watcher")
_label = lambda n: "org.rushes.watcher" if n == "Rushes Watcher" else "org.rushes.app"
_running = {"at": 0.0, "names": [NAME]}
def running_roles():
    """The Rushes apps running in the background on this Mac, in ROLES order (macOS asked at most every 20 s)."""
    if time.time() - _running["at"] > 20:
        _running.update(at=time.time(), names=[n for n in ROLES if n == NAME or
                                               launchctl("print", f"gui/{UID}/{_label(n)}").returncode == 0])
    return _running["names"]


_roles = {}
def role(name):
    """Another Rushes app's menu, from this same file as that app reads it: its name decides its folders."""
    if name not in _roles:
        import importlib.util
        app = f"/Applications/{name}.app"
        try:      # where that app really is: its background service says
            with open(os.path.join(HOME, "Library", "LaunchAgents", _label(name) + ".plist"), "rb") as f:
                app = os.path.abspath(os.path.join(plistlib.load(f)["ProgramArguments"][0], "..", "..", ".."))
        except Exception:
            pass
        was = {k: os.environ.get(k) for k in ("RUSHES_NAME", "RUSHES_APP")}
        os.environ.update(RUSHES_NAME=name, RUSHES_APP=app)
        try:
            spec = importlib.util.spec_from_file_location("rushes_role_" + name.split()[-1].lower(), os.path.abspath(__file__))
            m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
        finally:
            for k, v in was.items():
                if v is None: os.environ.pop(k, None)
                else: os.environ[k] = v
        w = m.Window(); w.said_at = 0
        _roles[name] = (m, w)
    return _roles[name]


def menu_state(w):
    me = own_menu(w)
    names = running_roles()
    if names[0] != NAME:
        me["hide"] = True                 # another Rushes app draws the one icon, with this one in it
        return me
    others = [n for n in names if n != NAME]
    if not others:
        return me
    items = [{"label": NAME.upper()}] + me["items"][:-2]       # all but the last line and its Quit
    for n in others:
        m, ow = role(n)
        o = m.own_menu(ow); tag = n.split()[-1].lower() + "-"
        items += [{"sep": True}, {"label": n.upper()}]
        items += [dict(i, do=tag + i["do"]) if "do" in i else i for i in o["items"][:-2]
                  if i.get("do") != "open-rushes" and "items" not in i]   # Open Rushes and Help once, in the first section
        if o["state"] == "attention" and me["state"] != "attention":
            me.update(state="attention", tip=o["tip"])
    me["items"] = items + [{"sep": True}, {"label": "Quit " + " and ".join(names), "do": "quit-all"}]
    return me


def update_said():
    """How the last update went, said for half an hour after it (the update script writes it)."""
    try:
        if time.time() - os.path.getmtime(UPDATED) > 1800:
            return ""
        ok, v, *why = open(UPDATED).read().rstrip("\n").split("\t")
    except (OSError, ValueError):
        return ""
    if ok == "ok":
        return f"✓ Updated to {v}"
    return (f"! {v} could not be put in place ({' '.join(why)[:60]}); {app_version()} kept. If macOS asked about it, "
            "allow Rushes in System Settings → Privacy & Security → App Management, then Update again.")


def own_menu(w):
    """The menu bar menu, top to bottom: what it is doing now and the last thing that
    happened; opening Rushes; the switches; Help (rarely used, in a submenu); its
    version and updates; Quit."""
    s = dict(w.s); s.update(w.home())
    info = lambda t: {"label": t}
    short = lambda t, n=70: t if len(t) <= n else t[:n - 1] + "…"
    said = s.get("said") if time.time() - w.said_at < 30 else ""
    err = s.get("error") if time.time() - getattr(w, "error_at", 0) < 60 else ""
    items, busy = [], False
    if WATCHER:
        n = s.get("now") or {}
        st = "paused" if s.get("paused") else ("unpaired" if not s.get("paired") else n.get("state") or "idle")
        icon = {"paused": "pause.circle", "unpaired": "exclamationmark.triangle", "offline": "wifi.exclamationmark",
                "watching": "eye", "delivering": "arrow.up.circle", "pointing": "arrow.triangle.branch"}.get(st, "film")
        head = WATCHING.get(st, st) + (f" · {n['note']}" if n.get("note") and st not in ("idle", "paused") else "")
        busy = st == "delivering"
        items += [info(head)] + ([info("✓ " + said)] if said else [])
        # its last line that says something happened (its log is its activity), the date gone, the time kept
        last = [l for l in (s.get("log") or []) if re.match(r"\d{4}-\d\d-\d\d \d", l)][-1:]
        items += [info("   Last: " + short(l[11:16] + "  " + l[20:].strip())) for l in last]
        items += [{"sep": True}, {"label": "Open Rushes", "do": "open-rushes"}, {"label": f"Show the {NAME} window", "do": "open-window"},
                  {"sep": True},
                  {"label": "Watch projects", "do": "watch-resume" if s.get("paused") else "watch-pause", "on": not s.get("paused")}]
        if not s.get("paired"):
            items.append({"label": f"Pair with Rushes… (in the window)", "do": "open-window"})
    else:
        n, up = s.get("now") or {}, s.get("rushes")
        busy = n.get("phase") in ("copying", "looking", "tracing", "analysing", "tidying", "delivering", "proving")
        icon = ("exclamationmark.triangle" if s.get("stopped") or n.get("phase") == "blocked" else "wifi.exclamationmark" if not up
                else "pause.circle" if s.get("paused") else "arrow.triangle.2.circlepath" if busy else "film")
        head = (f"Stopped by itself — {s['stopped']}" if s.get("stopped") else "Asking Rushes …" if up is None
                else f"Cannot reach Rushes ({s.get('rushes_why', '')})" if not up
                else "Paused — copying starts nothing new" if s.get("paused") else
                PHASE.get(n.get("phase"), n.get("phase") or "Waiting — nothing queued")
                + (f" · {os.path.basename(n['source'].rstrip('/'))}" if n.get("source") else "")
                + (f" · {n.get('copied', 0):,} of {n['of']:,}" if n.get("of") else ""))
        items += [info(head)]
        d = s.get("describing") or {}
        if d.get("phase") == "analysing":
            items.append(info(f"Describing {d.get('label', '')}" + (f" · {d.get('n')} of {d.get('of')}" if d.get("of") else "")))
        if said: items.append(info("✓ " + said))
        # the last thing that happened, from Activity (what people and Rushes did), not its log
        for e in (s.get("activity") or [])[:1]:
            at = str(e.get("at", ""))
            when = at[11:16] if at[:10] == time.strftime("%Y-%m-%d") else at[5:10].replace("-", "/")
            items.append(info("   Last: " + short(f"{when}  {e.get('text', '')}" + (f" ({e['who']})" if e.get("who") else ""))))
        items += [{"sep": True},
                  {"label": "Starting Rushes …"} if s.get("local_up") is False else {"label": "Open Rushes", "do": "open-rushes"},
                  {"label": f"Show the {NAME} window", "do": "open-window"}, {"sep": True}]
        # a switch: ticked when on; choosing it turns it the other way (Rushes keeps these, so not while unreachable)
        sw = lambda label, on_, ids: dict({"label": label, "on": bool(on_)}, **({"do": ids[1] if on_ else ids[0]} if up else {}))
        items += [sw("Copy footage", not s.get("paused"), ("resume", "pause")),
                  sw("Describe footage", not s.get("describe_paused"), ("describe-resume", "describe-pause")),
                  sw("Check copies", not s.get("check_paused"), ("check-resume", "check-pause")),
                  sw("Reconnect network drives", not s.get("no_reconnect"), ("reconnect-on", "reconnect-off"))]
        if s.get("local"):                               # Rushes on this Mac: its one switch
            o = s["local"]["others"]
            items.append({"label": "Let other devices open Rushes", "on": o, "do": "others-off" if o else "others-on"})
        if s.get("stopped"):
            items.append({"label": "Try again", "do": "try-again"})
        if s.get("pairing") != "this" and not s.get("local"):
            items.append({"label": "Pair with Rushes… (in the window)", "do": "open-window"})
    items += [info("! " + short(err, 90))] if err else []
    items += [info(u) for u in [update_said()] if u]
    items += [{"sep": True},
              {"label": "Help", "items": [{"label": "Show the log", "do": "show-log"},
                                          {"label": "Collect diagnostics", "do": "diagnostics"},
                                          {"label": "Ask for help…", "do": "ask-help"}]},
              {"label": f"Update to {s['newer']}", "do": "update-app"} if s.get("newer")
              else {"label": f"{NAME} {app_version()} — Check for updates", "do": "check-updates"},
              {"sep": True},
              {"label": f"Quit {NAME}" + (" — stops what it is doing" if busy else ""), "do": "quit"}]
    # what the launcher's icon shows (the Rushes mark): dimmed when paused or cut off, "!" when it needs you
    state = {"pause.circle": "paused", "wifi.exclamationmark": "offline", "exclamationmark.triangle": "attention",
             "film": "ok"}.get(icon, "busy")
    return {"icon": icon, "state": state, "tip": f"{NAME} — {head}", "items": items}


def menu(port, key):
    w = Window()
    w.said_at = 0
    seen = {"said": ""}
    cache = {"at": 0.0, "m": None}
    lock = threading.Lock()

    def fresh(force=False):
        with lock:
            # Asked of Rushes when the menu is opened, and otherwise every 30 s for the icon.
            if force or time.time() - cache["at"] > 30:
                if w.s.get("said") != seen["said"]:
                    seen["said"] = w.s.get("said"); w.said_at = time.time()
                if w.s.get("error") != seen.get("error"):
                    seen["error"] = w.s.get("error"); w.error_at = time.time()
                try: cache["m"] = menu_state(w)
                except Exception as e:
                    cache["m"] = {"icon": "exclamationmark.triangle", "tip": f"{NAME}: {e}",
                                  "items": [{"label": f"Could not read its state ({e})"}, {"sep": True},
                                            {"label": f"Open {NAME}…", "do": "open-window"}, {"label": f"Quit {NAME}", "do": "quit"}]}
                cache["at"] = time.time()
            return cache["m"]

    class H(http.server.BaseHTTPRequestHandler):
        def log_message(self, *a):
            pass

        def do_GET(self):
            u = urllib.parse.urlsplit(self.path)
            if u.path == f"/{key}/menu":
                body = json.dumps(fresh(force="fresh" in u.query and time.time() - cache["at"] > 2)).encode()
            elif u.path == f"/{key}/do":
                a = urllib.parse.parse_qs(u.query).get("a", [""])[0]
                log(f"menu: {a}")
                other = next((n for n in ROLES if n != NAME and a.startswith(n.split()[-1].lower() + "-")), None)
                if a == "quit-all":                                # every Rushes app on this Mac, as one
                    for n in running_roles():
                        if n != NAME: role(n)[1].act("quit", {})
                    w.act("quit", {})
                elif other:                                        # a choice in another app's section
                    ow = role(other)[1]; ow.act(a[len(other.split()[-1]) + 1:], {}); ow.said_at = time.time()
                else:
                    w.act(a, {})
                _running["at"] = 0
                time.sleep(0.3)                                    # most choices answer at once
                cache["at"] = 0
                body = b"{}"
            else:
                self.send_response(404); self.end_headers(); return
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

    srv = http.server.ThreadingHTTPServer(("127.0.0.1", port), H)
    srv.daemon_threads = True
    srv.serve_forever()


# ── run by macOS: the work itself ───────────────────────────────────────────
def service(args):
    if WATCHER:
        # Its code is the one inside this signed app; nothing is downloaded.
        os.execv(sys.executable, [sys.executable, "-u", os.path.join(os.path.dirname(os.path.abspath(__file__)), "rushes_watcher.py"), "run"])
    here = os.path.dirname(os.path.abspath(__file__))
    if local():
        # Rushes on this Mac: started beside the helper, in its own process, and gone with it.
        os.makedirs(LOGS, exist_ok=True)
        with open(os.path.join(LOGS, "server.log"), "a") as out:
            subprocess.Popen([sys.executable, "-u", os.path.abspath(__file__), "--server"],
                             stdin=subprocess.DEVNULL, stdout=out, stderr=out)
        for _ in range(60):                              # the helper asks Rushes nothing before it answers
            if local_up():
                break
            time.sleep(0.5)
    # The helper's code that came inside this app (a signed release, checked
    # here as any update is) goes in when it is newer than the one installed,
    # so a new app brings its code with it, even before Rushes can be asked.
    try:
        import release
        sig = read_text(os.path.join(here, "release.sig"))
        mine = read_text(os.path.join(DIR, "release.sig")) if os.path.exists(os.path.join(DIR, "release.sig")) else ""
        if release.made_of(sig) > release.made_of(mine):
            got = {f: read_text(os.path.join(here, f), binary=True) for f in FILES}
            release.check(got, sig)
            os.makedirs(DIR, exist_ok=True)
            for f, data in list(got.items()) + [("release.sig", sig.encode())]:
                with open(os.path.join(DIR, f + ".new"), "wb") as fh:
                    fh.write(data)
                os.replace(os.path.join(DIR, f + ".new"), os.path.join(DIR, f))
            print(f"installed the helper's code that came with this app (signed {time.strftime('%Y-%m-%d %H:%M', time.localtime(release.made_of(sig)))})")
    except FileNotFoundError:
        pass                                             # an app without the code inside: it comes from Rushes
    except Exception as e:
        print(f"the helper's code inside this app was not installed ({e}); the one in place is kept")
    # The helper checks its own updates with the release.py beside it. One
    # installed before signed releases has none: it gets the one inside this
    # app (which macOS checks is signed), never one downloaded.
    if os.path.exists(os.path.join(DIR, "ingest.py")) and not os.path.exists(os.path.join(DIR, "release.py")):
        try: shutil.copy(os.path.join(here, "release.py"), os.path.join(DIR, "release.py"))
        except OSError: pass
    missing = [f for f in FILES if not os.path.exists(os.path.join(DIR, f))]
    if missing:
        url = args[args.index("--url") + 1] if "--url" in args else saved_url()
        try:
            fetch_files(url)
            print(f"downloaded {', '.join(missing)} from Rushes")
        except Exception as e:
            # Rule 4: asked less and less, 1 minute, then 5, then every 15
            # (macOS starts this again 30 s after it ends).
            n = 0
            try: n = int(open(os.path.join(DIR, ".fetch-tries")).read())
            except (OSError, ValueError): pass
            wait = (60, 300)[n] if n < 2 else 900
            try: open(os.path.join(DIR, ".fetch-tries"), "w").write(str(n + 1))
            except OSError: pass
            why = str(e) if "signed release" in str(e) else f"the helper's files are missing and Rushes cannot be reached ({e})"
            print(f"{why} — trying again in {wait // 60} min")
            time.sleep(wait)
            return 1
        try: os.remove(os.path.join(DIR, ".fetch-tries"))
        except OSError: pass
    ingest = os.path.join(DIR, "ingest.py")
    # Its output goes to helper.log, opened for appending, so the helper can
    # keep the file small itself (trim_own_log in ingest.py).
    log = os.path.join(LOGS, "helper.log")
    try:
        if "--service" not in args:
            raise OSError("run by hand: output stays where it is")
        os.makedirs(LOGS, exist_ok=True)
        fd = os.open(log, os.O_WRONLY | os.O_APPEND | os.O_CREAT, 0o644)
        os.dup2(fd, 1); os.dup2(fd, 2); os.close(fd)
        os.environ["RUSHES_LOG"] = log
    except OSError:
        pass
    os.execv(sys.executable, [sys.executable, "-u", ingest, *args])


if __name__ == "__main__":
    a = sys.argv[1:]
    if "--window" in a:
        i = a.index("--window")
        sys.exit(serve(int(a[i + 1]), a[i + 2]))
    if "--menu" in a:
        i = a.index("--menu")
        sys.exit(menu(int(a[i + 1]), a[i + 2]))
    if "--server" in a:
        sys.exit(server())
    if "--service" in a or "--watch" in a:
        sys.exit(service(a))
    print(f"{NAME}: open it from Finder to see its window.")
