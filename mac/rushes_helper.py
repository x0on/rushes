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
NAME = os.environ.get("RUSHES_NAME") or ("Rushes Watcher" if "Rushes Watcher" in APP else "Rushes Helper")
WATCHER = NAME == "Rushes Watcher"
LOGS = os.path.join(HOME, "Library", "Logs", "Rushes Watcher" if WATCHER else "Rushes")
LABEL = "org.rushes.watcher" if WATCHER else "org.rushes.helper"
PLIST = os.path.join(HOME, "Library", "LaunchAgents", LABEL + ".plist")
HOMEAPP = os.path.join(HOME, "Applications", NAME + ".app")
WORKLOG = os.path.join(LOGS, "watcher.log" if WATCHER else "helper.log")      # what the work itself says
# Rushes Watcher's own notes: its pairing (config.json), what it is doing (now.json), Pause watching (paused)
WDIR = os.path.join(HOME, "Library", "Application Support", "Rushes Watcher")
# When Rushes was last asked for a newer app, and its answer (once a week, or Check for updates)
UPCHECK = os.path.join(WDIR if WATCHER else DIR, "update-check.json")
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
    """The background service runs the copy in Applications (in your home
    folder): a place that stays put — not Downloads, and not the temporary
    copy macOS runs a downloaded app from. Copied, then setup simply carries
    on in this same window."""
    here = os.path.realpath(APP)
    if here == os.path.realpath(HOMEAPP):
        return ""
    log(f"copying the app from {here} to {HOMEAPP}")
    os.makedirs(os.path.dirname(HOMEAPP), exist_ok=True)
    if os.path.exists(HOMEAPP):
        subprocess.run(["rm", "-rf", HOMEAPP])
    r = subprocess.run(["ditto", here, HOMEAPP], capture_output=True, text=True)
    if r.returncode:
        return r.stderr.strip() or "ditto failed"
    # It came from the download that was already allowed to open.
    subprocess.run(["xattr", "-dr", "com.apple.quarantine", HOMEAPP], capture_output=True)
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


def rushes(url, path, data=None, timeout=6):
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    req = urllib.request.Request(url.rstrip("/") + path, data=body, headers={"X-Rushes-Helper": helper_id()})
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
        add("What the helper is doing, as Rushes sees it")
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
        self.s = {"step": "welcome", "url": saved_url(), "busy": "", "error": "", "said": "",
                  "done": [], "waiting": False, "lan_asked": False}
        if self.s["url"] and service_points_here() and has_full_disk_access():
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
        s.update(name=NAME, watcher=WATCHER)
        if s["step"] == "home":
            s.update(self.home())
        return s

    def newer(self, now=False):
        """A newer Rushes Helper (or Watcher) on Rushes, offered, never installed by
        itself: the person decides. Rushes is asked once a week (remembered on
        this computer), or at once with Check for updates. Only for the copy in
        Applications, which is the one the background service runs."""
        c = read_json(UPCHECK)
        if now or time.time() - c.get("at", 0) > 7 * 86400:
            c["at"] = time.time()                        # a failed question waits a week too, unless asked
            try:
                import release
                same = os.path.realpath(APP) == os.path.realpath(HOMEAPP)
                c["newer"] = release.app_update(self.s["url"], APP, check_only=True) if same else ""
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
                 else f"Up to date: {NAME} {app_version()} is the same version as Rushes.")

    def update_app(self):
        import release
        v = release.app_update(self.s["url"], APP, say=lambda m: (log(m), self.set(said=m)))
        if not v:
            self.set(said=f"Already up to date: {NAME} {app_version()}, the same as Rushes.")

    def home(self):
        loaded, pid = service_running()
        h = {"running": loaded, "pid": pid, "log": log_tail(), "app": HOMEAPP, "logfile": WORKLOG,
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
            h["local"] = {"archive": c["archive"], "others": bool(c.get("others")), "port": c.get("port") or PORT,
                          "there": os.path.isdir(c["archive"]), "name": local_name()}
        try:
            with open(STOPPED) as f:
                h["stopped"] = f.read().partition("\n")[2].strip() or "a share stopped answering"
        except OSError:
            pass
        # Paired or not is asked first and on its own: the box to type the code
        # in is shown even when the rest of Rushes is slow to answer.
        try: h["pairing"] = rushes(self.s["url"], "/db/pair.php", timeout=15).get("pairing", "")
        except Exception: h["pairing"] = ""              # not known right now: the box stays
        try:
            c = rushes(self.s["url"], "/db/helper.php?control")
            h["paused"], h["no_reconnect"] = bool(c.get("paused")), bool(c.get("no_reconnect"))
            h["describe_paused"] = bool(c.get("describe_paused"))
            h["check_paused"] = bool(c.get("check_paused"))
            st = rushes(self.s["url"], "/db/state.php")
            h["now"] = st.get("copy") or {}
            h["describing"] = ((st.get("helper") or {}).get("describe")) or {}
            h["rushes"] = True
        except Exception as e:
            h["rushes"] = False; h["rushes_why"] = str(getattr(e, "reason", e))
        return h

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
        s.setdefault("archive", {}).update(label=os.path.basename(arch), local=arch, web=WEB, url=url, as_seen_from_helper=arch)
        s.setdefault("helper", {})["mode"] = "built_in"
        s["holding"] = {"duplicates": arch + "/_duplicates", "cache": arch + "/_duplicates/_media-cache"}
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
        self.set(said="Paired ✓ Rushes gives work to this Mac only. Any other helper is refused, and Rushes names it.")

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
            raise RuntimeError(f"Could not copy {NAME} into Applications in your home folder: " + err)
        did(f"{NAME} is in Applications, in your home folder")
        if WATCHER:
            # Its code is inside the app; only where Rushes is, kept for it (paired in its window, next).
            os.makedirs(WDIR, exist_ok=True)
            cfg = read_json(os.path.join(WDIR, "config.json"))
            cfg["url"] = url
            with open(os.path.join(WDIR, "config.json.new"), "w") as f:
                json.dump(cfg, f)
            os.replace(os.path.join(WDIR, "config.json.new"), os.path.join(WDIR, "config.json"))
        elif local():
            did("Rushes and the helper's code are inside this app: nothing to download")
        else:
            fetch_files(url)
            did("Downloaded the helper from Rushes")
        if not install_service(url):
            raise RuntimeError("macOS would not start the background service. The detail is in ~/Library/Logs/Rushes/setup.log")
        did("Background service installed and started — it starts by itself when you log in")
        if has_full_disk_access():
            restart_service(); self.set(step="all-set")
        else:
            self.set(step="fda", waiting=False); self._watch_access()

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


PAGE = r"""<!doctype html><html><head><meta charset="utf-8"><title>Rushes Helper</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#f6f5f2;--card:#fff;--fg:#1d1d1b;--muted:#6b6a66;--line:#e3e1dc;--accent:#2f7d74;--accent-fg:#fff;--ok:#2f7d4a;--warn:#9a6a12;--bad:#b3261e}
@media (prefers-color-scheme:dark){:root{--bg:#1c1c1b;--card:#252523;--fg:#ecebe7;--muted:#a3a19b;--line:#3a3936;--accent:#4fa396;--accent-fg:#0d1f1c;--ok:#6cc08a;--warn:#e2b04a;--bad:#f08a80}}
*{box-sizing:border-box}html,body{margin:0;height:100%}
body{font:14px/1.5 -apple-system,BlinkMacSystemFont,"Helvetica Neue",sans-serif;background:var(--bg);color:var(--fg);display:flex;-webkit-user-select:none;user-select:none}
nav{width:190px;padding:26px 16px;border-right:1px solid var(--line)}
nav h1{font-size:15px;margin:0 0 22px}nav h1 small{display:block;font-weight:400;color:var(--muted);font-size:12px}
nav ol{list-style:none;margin:0;padding:0}nav li{padding:7px 0 7px 26px;position:relative;color:var(--muted)}
nav li:before{content:"";position:absolute;left:4px;top:12px;width:10px;height:10px;border-radius:50%;border:2px solid var(--line)}
nav li.on{color:var(--fg);font-weight:600}nav li.on:before{border-color:var(--accent);background:var(--accent)}
nav li.ok{color:var(--fg)}nav li.ok:before{border-color:var(--ok);background:var(--ok)}
main{flex:1;display:flex;flex-direction:column;min-width:0}
.body{flex:1;overflow:auto;padding:28px 32px}
h2{font-size:20px;margin:0 0 12px}p{margin:0 0 12px}.muted{color:var(--muted)}
.foot{display:flex;gap:10px;justify-content:flex-end;align-items:center;padding:14px 22px;border-top:1px solid var(--line)}
.foot .left{margin-right:auto;display:flex;gap:8px}.foot button{white-space:nowrap}
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
<nav><h1>Rushes Helper<small>Rushes Media Management Software</small></h1><ol id="steps"></ol></nav>
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
  $('steps').innerHTML = s.step === 'home' || s.step === 'remove' || s.step === 'removed'
    ? '<li class="on">This Mac</li>'
    : STEPS.map((x, i) => '<li class="' + (i < setupStep ? 'ok' : i === setupStep ? 'on' : '') + '">' + x[1] + '</li>').join('');
  let b = '', f = '';
  const err = s.error ? '<p class="err">' + esc(s.error) + '</p>' : '';
  switch (s.step) {
  case 'welcome':
    b = '<h2>Set up Rushes Helper</h2>' + (s.watcher
      ? '<p>Rushes Watcher keeps every Premiere project you save, wherever you keep it on this Mac, with the files it uses, in your Rushes archive, without you pressing anything. ' +
        'It sends the files a project uses that the archive does not have yet (music, stock, downloads, graphics, voiceover: only what you imported), and makes an <b>Output</b> folder beside each project: export the finished work there and it is kept too. ' +
        'When you quit Premiere, a dated copy of each project you saved is kept, pointing at the archive. Your files on this Mac are only read, never moved or changed.</p>' +
        '<p>Its icon in the menu bar shows what it is doing, with its switches. While it runs, the icon is there.</p>'
      : '<p>Rushes Helper copies footage into your Rushes archive in the background, and Rushes → Manage shows everything it does.</p>') +
      '<p>It carries its own copy of Python — the free, open-source programming language it is written in. That copy lives inside this app and nothing else uses it, so nothing on this Mac is changed or needs updating.</p>' +
      '<p>Setting up takes a minute, in this window: where Rushes is, and two permissions from macOS — to talk to your network (press Allow when macOS asks), and one switch in System Settings. The last step tells you when everything is done.</p>';
    f = btn('Cancel', 'done') + btn('Set up', 'start', true); break;
  case 'address':
    b = '<h2>Where is Rushes?</h2><p>The address you open Rushes at in the browser. Rushes → Setup shows it, with a Copy button.</p>' +
      '<input id="url" placeholder="http://" value="' + esc(typed != null ? typed : s.url) + '">' +
      '<p class="muted" style="margin-top:10px">If macOS asks whether Rushes Helper may find and connect to devices on your local network, press Allow.</p>' +
      (s.watcher ? '' : '<div class="box" style="margin-top:18px"><div class="row"><div class="t"><b>Or: the archive is a drive on this Mac</b><small>No server: ' +
        'Rushes runs inside this app, and you open it from the menu bar icon (Open Rushes). Choose the drive, or a folder on one. ' +
        'Other devices can be let in later, with a password.</small></div>' + btn('Choose the drive…', 'local-pick', false, !!s.busy) + '</div></div>') + err + busy;
    f = btn('Cancel', 'done') + btn('Next', 'address', true, !!s.busy); break;
  case 'network':
    b = '<h2>macOS is not letting Rushes Helper talk to your network yet</h2>' +
      '<p>If it asked whether Rushes Helper may “find and connect to devices on your local network”, press Allow, then Try again.</p>' +
      '<p>If it did not ask: open Local Network settings, turn on Rushes Helper, then Try again.</p>' + err + busy;
    f = btn('Cancel', 'done') + btn('Open Local Network settings', 'network-settings') + btn('Try again', 'retry', true, !!s.busy); break;
  case 'install':
    b = '<h2>Installing</h2><ul class="did">' + (s.done || []).map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>' +
      (s.busy ? '<p style="margin-top:10px"><span class="spin"></span>Working …</p>' : '') + err;
    f = s.error ? btn('Try again', 'retry', true) : ''; break;
  case 'fda':
    b = '<h2>One switch left: Full Disk Access</h2>' +
      '<p>macOS keeps apps away from network drives and other disks until you allow it. ' + (s.watcher ? 'Rushes Watcher needs that to read your projects and the files they use, wherever they are on this Mac — nothing more.'
        : 'Rushes Helper needs that to read footage from the source and write it into the archive — nothing more.') + '</p>' +
      '<p><b>Open System Settings</b> below: it opens at Full Disk Access, and Finder shows Rushes Helper. Turn Rushes Helper on in the list. If it is not in the list, drag it from the Finder window into the list (or press + and pick it from Applications in your home folder).</p>' +
      '<p class="muted">If System Settings opens somewhere else, type Full Disk Access into its search field, top left.</p>' +
      (s.waiting ? '<div class="box"><span class="spin"></span>Waiting for the switch … this window moves on by itself the moment it is on.</div>' : '');
    f = btn('Later', 'later') + btn(s.waiting ? 'Show me where again' : 'Open System Settings', 'fda-open', true); break;
  case 'later':
    b = '<h2>Not finished yet</h2><p>Rushes Helper is installed and running, but it cannot reach the drives until Full Disk Access is on.</p><p>Open Rushes Helper again any time to finish; it starts right at that step.</p>';
    f = btn('Done', 'done', true); break;
  case 'all-set':
    b = '<div class="big">✓</div><h2>All set</h2>' +
      (s.watcher ? '<p>Rushes Watcher is set up. It runs in the background, starts when you log in, and its icon is in the menu bar.</p>' +
        '<p><b>One thing left: pair it with Rushes.</b> In Rushes → Setup → Editors\' work, press Add an editor\'s computer, and type the six numbers on the next screen.</p>'
      : '<p>Rushes Helper is set up. It runs in the background, starts when you log in, restarts itself if it stops, and keeps itself up to date from Rushes.</p>' +
        '<p>Rushes → Manage shows what it is doing — and so does this app: open it again any time to see it working, pause it, or change its settings.</p>') +
      '<p class="muted">macOS may show a notice that Rushes Helper can run in the background — that is this.</p>';
    f = s.watcher ? btn('Pair with Rushes', 'back-home', true) : btn('Open Rushes', 'open-rushes') + btn('Done', 'done', true); break;
  case 'home': b = s.watcher ? whome(s) : home(s); f = '<span class="left">' + btn('Remove…', 'remove') + '</span>' + btn('Show the log', 'show-log') + btn('Open Rushes', 'open-rushes') + btn('Done', 'done', true); break;
  case 'remove':
    b = '<h2>Remove Rushes Helper?</h2><p>It stops, and no longer starts at login. Anything half-copied stays where it is and carries on if you set it up again.</p>';
    f = btn('Cancel', 'back-home') + btn('Remove', 'remove-yes', true); break;
  case 'removed':
    b = '<div class="big">✓</div><h2>Removed</h2><p>It will not start again.</p><p>To finish, drag Rushes Helper from Applications (in your home folder) to the Trash, and switch it off in Full Disk Access. Its notes stay until you delete them: ' +
      (s.watcher ? '~/Library/Application Support/Rushes Watcher (its pairing and what it delivered) and ~/Library/Logs/Rushes Watcher. Projects keep their “Rushes backups” folders.'
        : '~/archive-pilot (its progress and pairing), ~/Library/Application Support/Rushes and ~/Library/Logs/Rushes.') + '</p>';
    f = btn('Done', 'done', true); break;
  }
  if (['welcome', 'home', 'all-set'].includes(s.step)) f = (f.includes('class="left"') ? f.replace('<span class="left">', '<span class="left"><button data-credits="1">What it is made of</button> ')
    : '<span class="left"><button data-credits="1">What it is made of</button></span>' + f);
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
const PHASE = {copying:'Copying', looking:'Looking for new footage', waiting:'Waiting', tracing:'Matching earlier copies to their originals',
  analysing:'Describing footage', tidying:'Tidying up', idle:'Nothing to describe', paused:'Paused', blocked:'Stopped: needs you', done:'Finished', stopped:'Stopped', planned:'Planned', proving:'Checking copies (reading only)'};
function home(s) {
  const n = s.now || {}, on = s.running;
  const state = !on ? '<span class="dot"></span><b>Stopped</b> — it does nothing until you turn it on below.'
    : s.stopped ? '<span class="dot warn"></span><b>Stopped by itself</b> — ' + esc(s.stopped) + '<div style="margin-top:8px">' + btn('Try again', 'try-again', true, !!s.busy) + '</div>'
    : s.paused ? '<span class="dot warn"></span><b>Paused</b> — running, but not starting any work.'
    : '<span class="dot ok"></span><b>Running in the background</b>' + (s.pid ? ' <span class="muted">· process ' + s.pid + '</span>' : '');
  const now = !s.rushes ? '<p class="muted">Rushes cannot be reached right now (' + esc(s.rushes_why) + '), so what it is doing and four of the switches are not available. The log below still shows its work.</p>'
    : n.phase ? '<p><b>' + esc(PHASE[n.phase] || n.phase) + '</b>' + (n.source ? ' · ' + esc(n.source.split('/').pop()) : '') + '</p>' +
        (n.note ? '<p class="muted">' + esc(n.note) + '</p>' : '') + (n.file ? '<p class="muted">now: ' + esc(n.file.split('/').pop()) + '</p>' : '')
    : '<p class="muted">Nothing to do right now.</p>';
  const sw = (on_, off, title, sub, dis) => '<div class="row"><div class="t">' + title + '<small>' + sub + '</small></div>' +
    '<button class="sw' + (on_ ? ' on' : '') + '" data-do="' + (on_ ? off[1] : off[0]) + '"' + (dis ? ' disabled' : '') + ' title="' + (on_ ? 'Turn off' : 'Turn on') + '"></button></div>';
  const L = s.local, here = L ? '<div class="box"><div class="row"><div class="t"><b>Rushes runs on this Mac</b><small>The archive: ' + esc(L.archive) +
      (L.there ? '' : ' — <b>not connected right now</b>: search still works from the last list; connect the drive to see it change') +
      '. Rushes runs while this does (Run in the background, below); Open Rushes is in the menu bar.</small></div>' + btn('Open Rushes', 'open-rushes') + '</div>' +
      sw(L.others, ['others-on', 'others-off'], 'Let other devices open Rushes', L.others
        ? 'On: phones and computers on your network, or on your Tailscale, open http://' + esc(L.name) + ':' + esc(L.port) + ' (or this Mac\'s Tailscale address, port ' + esc(L.port) + ') and sign in with Rushes\' password.'
        : 'Off: only this Mac. On: others sign in with Rushes\' password, once you have set your own in Rushes → Manage.') + '</div>' : '';
  return '<h2>Rushes Helper on this Mac</h2><div class="box">' + state + '</div>' + here + upd(s) +
    '<div class="box"><div class="muted" style="margin-bottom:6px">What it is doing</div>' + now + '</div>' +
    // what went wrong, said where it happened (a wrong pairing code, a switch Rushes refused, …)
    (s.error ? '<p class="err">Did not happen: ' + esc(s.error) + '</p>' : '') +
    (s.said ? '<p class="said">' + esc(s.said) + '</p>' : '') + (s.busy ? '<p><span class="spin"></span>' + esc(s.busy) + '</p>' : '') +
    // The place to type the code: always there until this Mac is the paired one.
    (s.pairing !== 'this' ? '<div class="box"><div class="row"><div class="t">' +
      (s.pairing === 'other' ? '<b>Rushes is paired with another helper</b><small>This Mac is given no work and touches nothing. To use this Mac instead, '
        : s.pairing === 'none' ? '<b>Not paired yet</b><small>Rushes gives work to any helper on the network until one is paired; two at once would copy over each other. To pair this one, '
        : '<b>Pair this Mac</b><small>Rushes did not say whether this Mac is paired (it is slow to answer right now). If Rushes → Setup → 04 says no helper is paired, or names another computer, ') +
      'open Rushes → Setup → Pair a helper and type the six numbers here. The helper paired before is refused from then on.</small></div></div>' +
      '<div class="row"><input id="paircode" inputmode="numeric" maxlength="7" placeholder="123456" style="width:9em" value="' + esc(paircode) + '">' +
      btn('Pair', 'pair', false, !!s.busy) + ' ' + btn('Paste the code from Rushes', 'paste-pair', true, !!s.busy) + '</div></div>'
      : s.pairing === 'this' ? '<p class="muted" style="font-size:12.5px">Paired with Rushes ✓ — it gives work to this Mac only.</p>' : '') +
    '<div class="box">' +
      sw(on, ['service-on', 'service-off'], 'Run in the background', 'Off stops it completely, also after a restart, until you turn it on here.') +
      sw(on && !s.paused, ['resume', 'pause'], 'Copy footage', 'Off pauses copying at its next safe point; nothing is lost. Rushes → Manage has the same switch.', !on || !s.rushes) +
      sw(on && !s.describe_paused, ['describe-resume', 'describe-pause'], 'Describe footage',
        'The second lane, beside copying: this Mac\'s chip reads each shot and what is said. Off pauses it; files already described are kept.' +
        (s.describing && s.describing.phase === 'analysing' ? ' Now: ' + esc(s.describing.label || '') + (s.describing.of ? ' · ' + esc(s.describing.n) + ' of ' + esc(s.describing.of) : '') : ''), !on || !s.rushes) +
      sw(on && !s.check_paused, ['check-resume', 'check-pause'], 'Check copies',
        'When there is nothing to copy: older copies are read again beside their originals (once), then every file in the archive now and then, against its fingerprint. Reading only. Off pauses it; where it got to is kept.', !on || !s.rushes) +
      sw(!s.no_reconnect, ['reconnect-on', 'reconnect-off'], 'Reconnect network drives by itself', 'When a drive drops, it connects it again once the server answers. Off: you connect drives in Finder.', !s.rushes) +
    '</div>' +
    // Support: said here, where the person at this Mac sees it, so nobody has
    // to wonder whether someone can reach in. There is no way in.
    '<div class="box">' +
      '<div class="row"><div class="t">Diagnostics<small>Everything someone helping you would ask for, in one text file on your Desktop, ' +
        'to read before you send it to anyone: versions, switches, and what the helper said lately. It names folders and files; ' +
        'it holds no footage, and no passwords are written to it on purpose — read it first. Nothing is sent.</small></div>' + btn('Collect diagnostics', 'diagnostics', false, !!s.busy) + '</div>' +
      '<div class="row"><div class="t">Ask for help<small>Opens a new issue for Rushes on GitHub, where help is asked for in the open, ' +
        'and saves the diagnostics on your Desktop. GitHub issues are public: read the file, and attach it only if nothing in it is private.</small></div>' +
        btn('Ask for help', 'ask-help', false, !!s.busy) + '</div>' +
      '<div class="row"><div class="t">Support access<small>None. There is no way for anyone — the author, IT or anyone else — to connect to this Mac through Rushes Helper. ' +
        'Help happens in the open, on GitHub, with what you choose to share.</small></div></div>' +
    '</div>' +
    '<p class="muted" style="font-size:12.5px">Updates: the helper keeps its copying and describing code the same as your Rushes server\'s (' + esc(s.url) +
      ', never anywhere else), between jobs, when Rushes says it has changed. This app itself updates only when you press Update (the menu bar icon says when there is one). Nothing else can reach this Mac through it.</p>' +
    '<div class="box"><div class="muted" style="margin-bottom:6px">What it did lately <span style="float:right">Rushes: ' + esc(s.url) + '</span></div><pre>' +
      esc((s.log || []).join('\n') || 'Nothing written yet.') + '</pre></div>';
}
// A newer version on Rushes: one button, it puts itself in place and starts again.
const upd = s => s.newer ? '<div class="box"><div class="row"><div class="t"><b>Rushes ' + esc(s.newer) + ' is ready</b><small>This is ' +
  esc(s.version) + '. The update comes from your Rushes, signed by its author; settings, pairing and permissions stay.</small></div>' +
  btn('Update to ' + s.newer, 'update-app', true, !!s.busy) + '</div></div>' : '';
// Rushes Watcher on this computer: everything it shows is on this computer.
const WATCHING = {idle: 'Idle — no editing program open', watching: 'Watching — an editing program is open', delivering: 'Sending a project\'s files to Rushes',
  pointing: 'Pointing projects at the archive', offline: 'Cannot reach Rushes', unpaired: 'Not paired with Rushes yet', paused: 'Paused'};
function whome(s) {
  const n = s.now || {}, on = s.running;
  const state = !on ? '<span class="dot"></span><b>Stopped</b> — it does nothing until you turn it on below.'
    : s.paused ? '<span class="dot warn"></span><b>Paused</b> — running, but it looks at no project.'
    : '<span class="dot ok"></span><b>Running in the background</b>' + (s.pid ? ' <span class="muted">· process ' + s.pid + '</span>' : '');
  const sw = (on_, ids, title, sub, dis) => '<div class="row"><div class="t">' + title + '<small>' + sub + '</small></div>' +
    '<button class="sw' + (on_ ? ' on' : '') + '" data-do="' + (on_ ? ids[1] : ids[0]) + '"' + (dis ? ' disabled' : '') + '></button></div>';
  return '<h2>Rushes Watcher on this Mac</h2><div class="box">' + state + '</div>' + upd(s) +
    '<div class="box"><div class="muted" style="margin-bottom:6px">What it is doing</div><p><b>' + esc(WATCHING[n.state] || n.state || 'Starting') + '</b>' +
      (n.note ? ' · ' + esc(n.note) : '') + '</p></div>' +
    (s.error ? '<p class="err">Did not happen: ' + esc(s.error) + '</p>' : '') +
    (s.said ? '<p class="said">' + esc(s.said) + '</p>' : '') + (s.busy ? '<p><span class="spin"></span>' + esc(s.busy) + '</p>' : '') +
    (!s.paired ? '<div class="box"><div class="row"><div class="t"><b>Not paired yet</b><small>It delivers nothing until it is. In Rushes → Setup → Editors\' work, ' +
      'press Add an editor\'s computer, and type the six numbers here. (A code for the helper does not work here, so an editor\'s computer never takes the helper\'s place.)</small></div></div>' +
      '<div class="row"><input id="paircode" inputmode="numeric" maxlength="7" placeholder="123456" style="width:9em" value="' + esc(paircode) + '">' +
      btn('Pair', 'pair', false, !!s.busy) + ' ' + btn('Paste the code from Rushes', 'paste-pair', true, !!s.busy) + '</div></div>'
      : '<p class="muted" style="font-size:12.5px">Paired with Rushes ✓ — Rushes lists this computer in Setup → Editors\' work.</p>') +
    '<div class="box">' +
      sw(on, ['service-on', 'service-off'], 'Run in the background', 'Off stops it completely, also after a restart, until you turn it on here. Its icon goes with it.') +
      sw(!s.paused, ['watch-resume', 'watch-pause'], 'Watch projects', 'Off: it looks at no project; nothing already delivered changes. The menu bar icon has the same switch.', !on) +
    '</div>' +
    '<div class="box">' +
      '<div class="row"><div class="t">Diagnostics<small>Versions, switches and what it said lately, in one text file on your Desktop, to read before you send it to anyone. Nothing is sent.</small></div>' +
        btn('Collect diagnostics', 'diagnostics', false, !!s.busy) + '</div>' +
      '<div class="row"><div class="t">Ask for help<small>Opens a new issue for Rushes on GitHub, where help is asked for in the open. Issues are public: read the diagnostics before you attach them.</small></div>' +
        btn('Ask for help', 'ask-help', false, !!s.busy) + '</div>' +
      '<div class="row"><div class="t">Support access<small>None. There is no way for anyone to connect to this Mac through Rushes Watcher.</small></div></div>' +
    '</div>' +
    '<div class="box"><div class="muted" style="margin-bottom:6px">What it did lately <span style="float:right">Rushes: ' + esc(s.url) + '</span></div><pre>' +
      esc((s.log || []).join('\n') || 'Nothing written yet.') + '</pre></div>';
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
                return self._send(200, PAGE.replace("Rushes Helper", NAME), "text/html")
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
ROLES = ("Rushes Helper", "Rushes Watcher")
_label = lambda n: "org.rushes.watcher" if n == "Rushes Watcher" else "org.rushes.helper"
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
                  if i.get("do") not in ("open-rushes", "ask-help")]        # those once, in the first section
        if o["state"] == "attention" and me["state"] != "attention":
            me.update(state="attention", tip=o["tip"])
    me["items"] = items + [{"sep": True}, {"label": "Quit " + " and ".join(names), "do": "quit-all"}]
    return me


def own_menu(w):
    s = dict(w.s); s.update(w.home())
    info = lambda t: {"label": t}
    # the last lines it wrote, each from its start: the date goes (the time stays), a long one ends in "…"
    day = lambda l: l[11:] if re.match(r"\d{4}-\d\d-\d\d \d", l) else l
    items, lately = [], [(lambda t: t if len(t) <= 80 else t[:79] + "…")(day(l)) for l in (s.get("log") or [])[-4:]]
    said = s.get("said") if time.time() - w.said_at < 30 else ""
    if WATCHER:
        n = s.get("now") or {}
        st = "paused" if s.get("paused") else ("unpaired" if not s.get("paired") else n.get("state") or "idle")
        icon = {"paused": "pause.circle", "unpaired": "exclamationmark.triangle", "offline": "wifi.exclamationmark",
                "watching": "eye", "delivering": "arrow.up.circle", "pointing": "arrow.triangle.branch"}.get(st, "film")
        head = WATCHING.get(st, st) + (f" · {n['note']}" if n.get("note") and st not in ("idle", "paused") else "")
        items += [info(head)] + ([info("✓ " + said)] if said else []) + [{"sep": True}, info("Lately:")]
        items += [info("   " + l) for l in lately] or [info("   nothing yet")]
        items += [{"sep": True},
                  {"label": "Watch projects", "do": "watch-resume" if s.get("paused") else "watch-pause", "on": not s.get("paused")}]
        if not s.get("paired"):
            items.append({"label": f"Pair with Rushes… (in the window)", "do": "open-window"})
    else:
        n, up = s.get("now") or {}, s.get("rushes")
        busy = n.get("phase") in ("copying", "looking", "tracing", "analysing", "tidying", "delivering", "proving")
        icon = ("exclamationmark.triangle" if s.get("stopped") or n.get("phase") == "blocked" else "wifi.exclamationmark" if not up
                else "pause.circle" if s.get("paused") else "arrow.triangle.2.circlepath" if busy else "film")
        head = (f"Stopped by itself — {s['stopped']}" if s.get("stopped") else f"Cannot reach Rushes ({s.get('rushes_why', '')})" if not up
                else "Paused — copying starts nothing new" if s.get("paused") else
                PHASE.get(n.get("phase"), n.get("phase") or "Waiting — nothing queued")
                + (f" · {os.path.basename(n['source'].rstrip('/'))}" if n.get("source") else "")
                + (f" · {n.get('copied', 0):,} of {n['of']:,}" if n.get("of") else ""))
        items += [info(head)]
        d = s.get("describing") or {}
        if d.get("phase") == "analysing":
            items.append(info(f"Describing {d.get('label', '')}" + (f" · {d.get('n')} of {d.get('of')}" if d.get("of") else "")))
        if said: items.append(info("✓ " + said))
        items += [{"sep": True}, info("Lately:")] + ([info("   " + l) for l in lately] or [info("   nothing yet")]) + [{"sep": True}]
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
        if s.get("pairing") != "this":
            items.append({"label": "Pair with Rushes… (in the window)", "do": "open-window"})
    items += [{"sep": True}, info(f"{NAME} {app_version()} · Rushes: {s.get('url') or 'not set up'}")]
    items.append({"label": f"Update to {s['newer']}", "do": "update-app"} if s.get("newer")
                 else {"label": "Check for updates", "do": "check-updates"})
    items += [
              {"label": "Open Rushes", "do": "open-rushes"}, {"label": "Show the log", "do": "show-log"},
              {"label": "Collect diagnostics", "do": "diagnostics"}, {"label": "Ask for help…", "do": "ask-help"},
              {"label": f"Open {NAME}…", "do": "open-window"}, {"sep": True},
              {"label": f"Quit {NAME}", "do": "quit"}]
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
