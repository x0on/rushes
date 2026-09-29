"""Rushes Helper — the Mac app around the helper.

Opened from Finder, it sets itself up: where Rushes is, a background service
that starts at login, and the one switch in System Settings macOS needs a
person to turn on. Started by macOS with arguments, it runs the helper.

Everything it does is written to ~/Library/Logs/Rushes/setup.log, and every
step says what happened. Nothing is written inside the app itself.
"""
import os
import plistlib
import subprocess
import sys
import time
import urllib.request

APP = os.environ.get("RUSHES_APP") or os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
HOME = os.path.expanduser("~")
DIR = os.path.join(HOME, "Library", "Application Support", "Rushes")
LOGS = os.path.join(HOME, "Library", "Logs", "Rushes")
LABEL = "org.rushes.helper"
PLIST = os.path.join(HOME, "Library", "LaunchAgents", LABEL + ".plist")
HOMEAPP = os.path.join(HOME, "Applications", "Rushes Helper.app")
ICON = os.path.join(APP, "Contents", "Resources", "AppIcon.icns")
FILES = ("ingest.py", "transfer_state.py")
TCC = os.path.join(HOME, "Library", "Application Support", "com.apple.TCC", "TCC.db")
LAN_PANE = ("x-apple.systempreferences:com.apple.settings.PrivacySecurity.extension?Privacy_LocalNetwork",
            "x-apple.systempreferences:com.apple.preference.security?Privacy_LocalNetwork")
FDA_PANE = ("x-apple.systempreferences:com.apple.settings.PrivacySecurity.extension?Privacy_AllFiles",
            "x-apple.systempreferences:com.apple.preference.security?Privacy_AllFiles")
UID = str(os.getuid())


def log(msg):
    line = time.strftime("%Y-%m-%d %H:%M:%S  ") + msg
    print(line)
    try:
        os.makedirs(LOGS, exist_ok=True)
        with open(os.path.join(LOGS, "setup.log"), "a") as f:
            f.write(line + "\n")
    except OSError:
        pass


# ── talking to the person: plain macOS dialogs, with the app's icon ──────────
def _q(s):
    return '"' + str(s).replace("\\", "\\\\").replace('"', '\\"') + '"'


def dialog_script(text, buttons, default=None, answer=None, wait=None):
    s = (f"activate\nset r to display dialog {_q(text)} with title \"Rushes Helper\""
         f" with icon (POSIX file {_q(ICON)})"
         f" buttons {{{', '.join(_q(b) for b in buttons)}}} default button {_q(default or buttons[-1])}")
    if answer is not None:
        s += f" default answer {_q(answer)}"
    if wait:
        s += f" giving up after {int(wait)}"
    s += "\nreturn (button returned of r)"
    if answer is not None:
        s += " & linefeed & (text returned of r)"
    return s


def ask(text, buttons=("OK",), default=None, answer=None):
    """The button pressed (and the text typed, if asked for). None when closed with Cancel."""
    r = subprocess.run(["osascript", "-e", dialog_script(text, buttons, default, answer)],
                       capture_output=True, text=True)
    if r.returncode:
        return None
    out = r.stdout.rstrip("\n")
    if answer is not None:
        b, _, t = out.partition("\n")
        return b, t.strip()
    return out


def say(text):
    ask(text, ("OK",))


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


def saved_url():
    try:
        return open(os.path.join(DIR, "url")).read().strip()
    except OSError:
        return ""


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
    for p in [APP] + [os.path.join(downloads, f) for f in sorted(os.listdir(downloads) if os.path.isdir(downloads) else [])
                      if f.startswith("Rushes Helper")]:
        u = from_where(p)
        if u:
            return u
    clip = subprocess.run(["pbpaste"], capture_output=True, text=True).stdout.strip()
    if clip.startswith(("http://", "https://")) and "\n" not in clip and len(clip) < 200:
        return clip.split("/db/")[0].rstrip("/")
    return ""


# ── the helper's own files, kept outside the app so they can update ─────────
def fetch_files(url):
    os.makedirs(DIR, exist_ok=True)
    for f in FILES:
        with urllib.request.urlopen(f"{url}/db/helper.php?code={f}", timeout=60) as r:
            data = r.read()
        compile(data, f, "exec")                 # a broken download never goes in
        with open(os.path.join(DIR, f + ".new"), "wb") as fh:
            fh.write(data)
        os.replace(os.path.join(DIR, f + ".new"), os.path.join(DIR, f))
    with open(os.path.join(DIR, "url"), "w") as fh:
        fh.write(url + "\n")


def launchctl(*args):
    return subprocess.run(["launchctl", *args], capture_output=True, text=True)


def install_service(url):
    os.makedirs(os.path.dirname(PLIST), exist_ok=True)
    os.makedirs(LOGS, exist_ok=True)
    plist = {
        "Label": LABEL,
        "ProgramArguments": [os.path.join(HOMEAPP, "Contents", "MacOS", "Rushes Helper"),
                             "--watch", "--url", url, "--service"],
        # Login Items shows it as Rushes Helper, with the icon, instead of a bare program.
        "AssociatedBundleIdentifiers": [LABEL],
        "RunAtLoad": True, "KeepAlive": True, "ThrottleInterval": 30,
        "ProcessType": "Background",
        "StandardOutPath": os.path.join(LOGS, "helper.log"),
        "StandardErrorPath": os.path.join(LOGS, "helper.log"),
    }
    with open(PLIST + ".new", "wb") as f:
        plistlib.dump(plist, f)
    os.replace(PLIST + ".new", PLIST)
    launchctl("bootout", f"gui/{UID}/{LABEL}")          # the old one, whatever it was
    for _ in range(5):
        r = launchctl("bootstrap", f"gui/{UID}", PLIST)
        if r.returncode == 0:
            return True
        time.sleep(1)                                   # the old one can take a moment to go
    log(f"could not start the service: {r.stderr.strip()}")
    return False


def restart_service():
    launchctl("kickstart", "-k", f"gui/{UID}/{LABEL}")


# ── setup, as the person sees it ────────────────────────────────────────────
WELCOME = """Rushes Helper copies footage into your Rushes archive in the background, and Rushes → Manage shows everything it does.

It carries its own copy of Python — the free, open-source programming language the helper is written in, and one of the most widely used in the world. That copy lives inside this app and nothing else uses it, so nothing on this Mac is changed or needs updating.

Setting up takes a minute: where Rushes is, and two permissions from macOS — to talk to your network (press Allow when it asks), and one switch in System Settings."""

FDA = """One switch left: Full Disk Access.

macOS keeps apps away from network drives and other disks until you allow it. Rushes Helper needs that to read footage from the source and write it into the archive — nothing more.

Next, System Settings opens at Full Disk Access, and Finder shows Rushes Helper. Turn Rushes Helper on in the list. If it is not in the list, drag it from the Finder window into the list (or press + and pick it from Applications in your home folder).

If System Settings opens somewhere else, type Full Disk Access into its search field, top left.

Then come back here: this window notices the switch by itself."""


def move_to_applications():
    """Run from ~/Applications, so the background service has a place that
    stays put — not Downloads, and not the temporary copy macOS runs a
    downloaded app from."""
    here = os.path.realpath(APP)
    if here == os.path.realpath(HOMEAPP):
        return False
    log(f"copying the app from {here} to {HOMEAPP}")
    os.makedirs(os.path.dirname(HOMEAPP), exist_ok=True)
    if os.path.exists(HOMEAPP):
        subprocess.run(["rm", "-rf", HOMEAPP])
    r = subprocess.run(["ditto", here, HOMEAPP], capture_output=True, text=True)
    if r.returncode:
        say(f"Could not copy Rushes Helper into Applications in your home folder:\n\n{r.stderr.strip()}")
        sys.exit(1)
    # It came from the download that was already allowed to open.
    subprocess.run(["xattr", "-dr", "com.apple.quarantine", HOMEAPP], capture_output=True)
    subprocess.run(["open", "-n", HOMEAPP])
    log("opened the copy in Applications; this one stops here")
    return True


def show_fda():
    """Settings at Full Disk Access, and the app in Finder to drag into the list."""
    open_pane(FDA_PANE)
    subprocess.run(["open", "-R", HOMEAPP])


def wait_for_access():
    """A waiting window that closes itself when the switch is on. Its button
    takes you back to the right place, as often as needed."""
    while True:
        w = subprocess.Popen(["osascript", "-e", dialog_script(
            "Waiting for Full Disk Access to be turned on for Rushes Helper …\n\n"
            "Lost the place? Press Show me where. If System Settings does not land on it, type "
            "Full Disk Access into its search field, top left.\n\n"
            "This window closes by itself when it is on.",
            ("Stop waiting", "Show me where"), default="Show me where", wait=1800)],
            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True)
        try:
            while w.poll() is None:
                if has_full_disk_access():
                    return True
                time.sleep(2)
        finally:
            if w.poll() is None:
                w.terminate()
        if (w.stdout.read() or "").strip() != "Show me where":
            return has_full_disk_access()
        log("showing Full Disk Access again")
        show_fda()


def setup():
    log(f"opened: {APP}")
    if move_to_applications():
        return 0
    url = saved_url()
    ready = url and service_points_here() and has_full_disk_access()

    if ready:
        b = ask("Rushes Helper is set up and running in the background.\n\n"
                f"Rushes: {url}\nIts log: ~/Library/Logs/Rushes/helper.log",
                ("Remove…", "Open Rushes", "Done"), default="Done")
        if b == "Open Rushes":
            subprocess.run(["open", url + "/db/admin.php"])
        elif b == "Remove…":
            return remove()
        return 0

    if ask(WELCOME, ("Cancel", "Set up"), default="Set up") != "Set up":
        log("setup cancelled at the start"); return 0
    # The first try makes macOS ask about Local Network now, before the address
    # question, instead of failing the address silently.
    url = url or guess_url()
    if url:
        reachable(url)

    # 1 · where Rushes is
    while True:
        a = ask("Where is Rushes? The address you open it at in the browser.\n\n"
                "Setup → 04 Helper in Rushes has it, with a Copy button.",
                ("Cancel", "Next"), default="Next", answer=url or "http://")
        if not a or a[0] != "Next":
            log("setup cancelled at the address"); return 0
        url = a[1].strip().rstrip("/").split("/db/")[0]
        if "://" not in url:
            url = "http://" + url
        ok, why, blocked = reachable(url)
        if ok:
            break
        if blocked:
            log(f"macOS refused the connection to {url}: Local Network is off for Rushes Helper")
            if ask("macOS is not letting Rushes Helper talk to your network yet.\n\n"
                   "If it asked whether Rushes Helper may “find and connect to devices on your local network”, "
                   "press Allow, then Next again.\n\n"
                   "If it did not ask: open Local Network settings, turn on Rushes Helper, then Next again.",
                   ("Try again", "Open Local Network settings"), default="Open Local Network settings") \
                    == "Open Local Network settings":
                open_pane(LAN_PANE)
            continue
        log(f"could not reach {url}: {why}")
        say(f"Could not reach Rushes at\n{url}\n\n{why or 'It answered, but not like Rushes.'}\n\n"
            "Check the address, and that this Mac is on the same network.")
    log(f"Rushes is at {url}")

    # 2 · the helper itself, and the service that runs it
    try:
        fetch_files(url)
    except Exception as e:
        say(f"Could not download the helper from Rushes:\n\n{e}"); return 1
    log("helper downloaded")
    if not install_service(url):
        say("macOS would not start the background service. The detail is in\n"
            "~/Library/Logs/Rushes/setup.log"); return 1
    log("background service installed and started")

    # 3 · the switch only a person can turn on
    if not has_full_disk_access():
        if ask(FDA, ("Later", "Open System Settings"), default="Open System Settings") != "Open System Settings":
            say("Rushes Helper is installed, but cannot reach the drives until Full Disk Access is on.\n\n"
                "Open Rushes Helper again any time to finish.")
            log("stopped before Full Disk Access"); return 0
        show_fda()
        log("waiting for Full Disk Access")
        if not wait_for_access():
            say("Full Disk Access is not on yet.\n\nOpen Rushes Helper again any time to finish.")
            log("gave up waiting for Full Disk Access"); return 0
        log("Full Disk Access is on")
        restart_service()                                # so the running helper has it too

    b = ask("✓ Rushes Helper is set up.\n\n"
            "It runs in the background, starts when you log in, restarts itself if it stops, "
            "and keeps itself up to date from Rushes. Rushes → Manage shows what it is doing.\n\n"
            "macOS may show a notice that Rushes Helper can run in the background — that is this.",
            ("Open Rushes", "Done"), default="Done")
    if b == "Open Rushes":
        subprocess.run(["open", url + "/db/admin.php"])
    log("setup finished")
    return 0


def remove():
    if ask("Remove Rushes Helper?\n\nIt stops, and no longer starts at login. Anything half-copied "
           "stays where it is and carries on if you set it up again.",
           ("Cancel", "Remove"), default="Cancel") != "Remove":
        return 0
    launchctl("bootout", f"gui/{UID}/{LABEL}")
    try:
        os.remove(PLIST)
    except OSError:
        pass
    log("removed the background service")
    say("✓ Removed. It will not start again.\n\nTo finish, drag Rushes Helper from Applications "
        "(in your home folder) to the Trash, and switch it off in Full Disk Access.")
    return 0


# ── run by macOS: the helper itself ─────────────────────────────────────────
def service(args):
    missing = [f for f in FILES if not os.path.exists(os.path.join(DIR, f))]
    if missing:
        url = args[args.index("--url") + 1] if "--url" in args else saved_url()
        try:
            fetch_files(url)
            print(f"downloaded {', '.join(missing)} from Rushes")
        except Exception as e:
            print(f"the helper's files are missing and Rushes cannot be reached ({e}) — trying again shortly")
            time.sleep(60)
            return 1
    ingest = os.path.join(DIR, "ingest.py")
    os.execv(sys.executable, [sys.executable, "-u", ingest, *args])


if __name__ == "__main__":
    a = sys.argv[1:]
    sys.exit(service(a) if "--service" in a or "--watch" in a else setup())
