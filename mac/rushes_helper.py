# Rushes — Media Management Software, by Alejandro Renteria.
# Open source: https://github.com/x0on/rushes — whoever finds this file on a computer can see what it is and who made it.
"""Rushes Helper — the Mac app around the helper.

Opened from Finder, it shows one window that stays open from start to end:
the first time, setup as steps (where Rushes is, the background service, the
one switch in System Settings macOS needs a person to turn on) ending on
"All set"; after that, what the helper is doing and its switches. Started by
macOS with arguments, it runs the helper, with no window.

The window is the app's own (see launcher.c); this file serves the page in it,
on this computer only, behind a random key. Everything it does is written to
~/Library/Logs/Rushes/setup.log. Nothing is written inside the app itself.
"""
import http.server
import json
import os
import plistlib
import re
import subprocess
import sys
import threading
import time
import urllib.parse
import urllib.request

APP = os.environ.get("RUSHES_APP") or os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
HOME = os.path.expanduser("~")
DIR = os.path.join(HOME, "Library", "Application Support", "Rushes")
LOGS = os.path.join(HOME, "Library", "Logs", "Rushes")
LABEL = "org.rushes.helper"
PLIST = os.path.join(HOME, "Library", "LaunchAgents", LABEL + ".plist")
HOMEAPP = os.path.join(HOME, "Applications", "Rushes Helper.app")
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
    try:
        for p in [APP] + [os.path.join(downloads, f) for f in sorted(os.listdir(downloads) if os.path.isdir(downloads) else [])
                          if f.startswith("Rushes Helper")]:
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
    try:
        return subprocess.run(["launchctl", *args], capture_output=True, text=True)
    except OSError as e:
        return subprocess.CompletedProcess(args, 1, "", str(e))


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
    return start_service()


def start_service():
    launchctl("bootout", f"gui/{UID}/{LABEL}")          # the old one, whatever it was
    for _ in range(5):
        r = launchctl("bootstrap", f"gui/{UID}", PLIST)
        if r.returncode == 0:
            return True
        time.sleep(1)                                   # the old one can take a moment to go
    log(f"could not start the service: {r.stderr.strip()}")
    return False


def stop_service():
    """Stops it and keeps it stopped, also after a restart, until started again here."""
    r = launchctl("bootout", f"gui/{UID}/{LABEL}")
    return r.returncode == 0 or not service_running()[0]


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
def rushes(url, path, data=None):
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    with urllib.request.urlopen(url.rstrip("/") + path, data=body, timeout=6) as r:
        return json.loads(r.read().decode("utf-8", "replace"))


def log_tail(n=12):
    """The helper's own words, newest last: what it did lately."""
    try:
        with open(os.path.join(LOGS, "helper.log"), "rb") as f:
            f.seek(0, 2); f.seek(max(0, f.tell() - 16000))
            lines = f.read().decode("utf-8", "replace").splitlines()
    except OSError:
        return []
    return [l for l in lines if l.strip()][-n:]


def diagnostics(url):
    """Everything someone helping would ask for, in one plain text file on the
    Desktop, for the person to read before sending it anywhere — or not at all.
    Versions, switches, what the helper said lately. It names folders and files
    (that is what the log is about); it holds no footage and no passwords, and
    nothing is sent: where it goes next is the person's choice."""
    import platform, hashlib
    lines = []
    def add(title, value=""):
        lines.append(f"{title}: {value}" if value != "" else f"\n── {title} ──")
    try:
        with open(os.path.join(APP, "Contents", "Info.plist"), "rb") as f:
            ver = plistlib.load(f).get("CFBundleShortVersionString", "?")
    except (OSError, plistlib.InvalidFileException):
        ver = "?"
    add("Rushes diagnostics", time.strftime("%Y-%m-%d %H:%M:%S"))
    add("What this is", "written by Rushes Helper when you pressed Collect diagnostics; nothing was sent anywhere")
    add("Rushes Helper", f"{ver} · {APP}")
    add("macOS", f"{platform.mac_ver()[0]} · {platform.machine()}")
    add("Rushes server", url or "(not set up)")
    loaded, pid = service_running()
    add("Running in the background", f"yes (process {pid})" if loaded else "no")
    add("Full Disk Access", "yes" if has_full_disk_access() else "no")
    for f in FILES + ("analyze.py",):
        try: add(f, hashlib.sha256(open(os.path.join(DIR, f), "rb").read()).hexdigest()[:12])
        except OSError: add(f, "missing")
    try: add("Drives connected", ", ".join(sorted(os.listdir("/Volumes"))))
    except OSError: pass
    add("Rushes' switches for this helper")
    try: lines.append(json.dumps(rushes(url, "/db/helper.php?control"), indent=1))
    except Exception as e: lines.append(f"(Rushes did not answer: {e})")
    add("What the helper is doing, as Rushes sees it")
    try:
        st = rushes(url, "/db/state.php")
        lines.append(json.dumps({k: st.get(k) for k in ("copy", "helper", "transfer", "conditions")}, indent=1, ensure_ascii=False))
    except Exception as e: lines.append(f"(Rushes did not answer: {e})")
    for name, n in (("helper.log", 300), ("setup.log", 80)):
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
    this state every second and draws it; slow work runs in a thread and moves
    the state on, so the window never freezes and never goes away mid-way."""

    def __init__(self):
        self.lock = threading.Lock()
        self.s = {"step": "welcome", "url": saved_url(), "busy": "", "error": "", "said": "",
                  "done": [], "waiting": False, "lan_asked": False}
        if self.s["url"] and service_points_here() and has_full_disk_access():
            self.s["step"] = "home"
        elif service_points_here() and self.s["url"]:
            self.s["step"] = "fda"                       # came back to finish the last switch
            self._watch_access()
        self.quit = threading.Event()

    def set(self, **kw):
        with self.lock:
            self.s.update(kw)

    def state(self):
        with self.lock:
            s = dict(self.s)
        if s["step"] == "home":
            s.update(self.home())
        return s

    def home(self):
        loaded, pid = service_running()
        h = {"running": loaded, "pid": pid, "log": log_tail(), "app": HOMEAPP, "logfile": os.path.join(LOGS, "helper.log")}
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
            subprocess.run(["open", s["url"] + "/db/admin.php"])
        elif do == "show-log":
            subprocess.run(["open", "-a", "Console", os.path.join(LOGS, "helper.log")])
        elif do == "remove":
            self.set(step="remove")
        elif do == "remove-yes":
            remove_service(); self.set(step="removed")
        elif do == "back-home":
            self.set(step="home", said="")
        elif do == "done":
            self.quit.set()
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
        elif do in ("pause", "resume", "describe-pause", "describe-resume", "reconnect-off", "reconnect-on", "check-pause", "check-resume", "nudge"):
            self.run("Asking Rushes …", lambda: self.switch(do))

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
            "check-resume": "Checking resumed ✓ It carries on whenever there is nothing to copy.",
            "nudge": "Asked ✓ It stops waiting and looks again now."}[do])
        log(f"switch: {do}")

    def check(self, url):
        ok, why, blocked = reachable(url)
        if blocked:
            log(f"macOS refused the connection to {url}: Local Network is off for Rushes Helper")
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
            raise RuntimeError("Could not copy Rushes Helper into Applications in your home folder: " + err)
        did("Rushes Helper is in Applications, in your home folder")
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
let S = {}, typed = null, sent = '', credits = null;
const $ = id => document.getElementById(id);
const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
async function act(d, extra) {
  sent = d;
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
    b = '<h2>Set up Rushes Helper</h2>' +
      '<p>Rushes Helper copies footage into your Rushes archive in the background, and Rushes → Manage shows everything it does.</p>' +
      '<p>It carries its own copy of Python — the free, open-source programming language the helper is written in. That copy lives inside this app and nothing else uses it, so nothing on this Mac is changed or needs updating.</p>' +
      '<p>Setting up takes a minute, in this window: where Rushes is, and two permissions from macOS — to talk to your network (press Allow when macOS asks), and one switch in System Settings. The last step tells you when everything is done.</p>';
    f = btn('Cancel', 'done') + btn('Set up', 'start', true); break;
  case 'address':
    b = '<h2>Where is Rushes?</h2><p>The address you open Rushes at in the browser. Setup → 04 Helper in Rushes shows it, with a Copy button.</p>' +
      '<input id="url" placeholder="http://" value="' + esc(typed != null ? typed : s.url) + '">' +
      '<p class="muted" style="margin-top:10px">If macOS asks whether Rushes Helper may find and connect to devices on your local network, press Allow.</p>' + err + busy;
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
      '<p>macOS keeps apps away from network drives and other disks until you allow it. Rushes Helper needs that to read footage from the source and write it into the archive — nothing more.</p>' +
      '<p><b>Open System Settings</b> below: it opens at Full Disk Access, and Finder shows Rushes Helper. Turn Rushes Helper on in the list. If it is not in the list, drag it from the Finder window into the list (or press + and pick it from Applications in your home folder).</p>' +
      '<p class="muted">If System Settings opens somewhere else, type Full Disk Access into its search field, top left.</p>' +
      (s.waiting ? '<div class="box"><span class="spin"></span>Waiting for the switch … this window moves on by itself the moment it is on.</div>' : '');
    f = btn('Later', 'later') + btn(s.waiting ? 'Show me where again' : 'Open System Settings', 'fda-open', true); break;
  case 'later':
    b = '<h2>Not finished yet</h2><p>Rushes Helper is installed and running, but it cannot reach the drives until Full Disk Access is on.</p><p>Open Rushes Helper again any time to finish; it starts right at that step.</p>';
    f = btn('Done', 'done', true); break;
  case 'all-set':
    b = '<div class="big">✓</div><h2>All set</h2>' +
      '<p>Rushes Helper is set up. It runs in the background, starts when you log in, restarts itself if it stops, and keeps itself up to date from Rushes.</p>' +
      '<p>Rushes → Manage shows what it is doing — and so does this app: open it again any time to see it working, pause it, or change its settings.</p>' +
      '<p class="muted">macOS may show a notice that Rushes Helper can run in the background — that is this.</p>';
    f = btn('Open Rushes', 'open-rushes') + btn('Done', 'done', true); break;
  case 'home': b = home(s); f = '<span class="left">' + btn('Remove…', 'remove') + '</span>' + btn('Show the log', 'show-log') + btn('Open Rushes', 'open-rushes') + btn('Done', 'done', true); break;
  case 'remove':
    b = '<h2>Remove Rushes Helper?</h2><p>It stops, and no longer starts at login. Anything half-copied stays where it is and carries on if you set it up again.</p>';
    f = btn('Cancel', 'back-home') + btn('Remove', 'remove-yes', true); break;
  case 'removed':
    b = '<div class="big">✓</div><h2>Removed</h2><p>It will not start again.</p><p>To finish, drag Rushes Helper from Applications (in your home folder) to the Trash, and switch it off in Full Disk Access.</p>';
    f = btn('Done', 'done', true); break;
  }
  if (['welcome', 'home', 'all-set'].includes(s.step)) f = (f.includes('class="left"') ? f.replace('<span class="left">', '<span class="left"><button data-credits="1">What it is made of</button> ')
    : '<span class="left"><button data-credits="1">What it is made of</button></span>' + f);
  const keep = $('url') && document.activeElement === $('url');
  $('body').innerHTML = b; $('foot').innerHTML = f;
  if ($('url')) { $('url').oninput = e => typed = e.target.value; if (keep) { $('url').focus(); } $('url').onkeydown = e => { if (e.key === 'Enter') act('address', {url: $('url').value}); }; }
  document.querySelectorAll('[data-credits]').forEach(x => x.onclick = async () => {
    try { credits = await (await fetch(K + 'credits')).text(); } catch (e) { credits = 'Could not read the list.'; }
    drawCredits();
  });
  document.querySelectorAll('[data-do]').forEach(x => x.onclick = () => {
    const d = x.dataset.do; x.disabled = true;
    act(d, d === 'address' ? {url: $('url').value} : null);
  });
}
const PHASE = {copying:'Copying', looking:'Looking for new footage', waiting:'Waiting', tracing:'Matching earlier copies to their originals',
  analysing:'Describing footage', tidying:'Tidying up', paused:'Paused', blocked:'Stopped: needs you', done:'Finished', stopped:'Stopped', planned:'Planned', proving:'Checking copies (reading only)'};
function home(s) {
  const n = s.now || {}, on = s.running;
  const state = !on ? '<span class="dot"></span><b>Stopped</b> — it does nothing until you turn it on below.'
    : s.paused ? '<span class="dot warn"></span><b>Paused</b> — running, but not starting any work.'
    : '<span class="dot ok"></span><b>Running in the background</b>' + (s.pid ? ' <span class="muted">· process ' + s.pid + '</span>' : '');
  const now = !s.rushes ? '<p class="muted">Rushes cannot be reached right now (' + esc(s.rushes_why) + '), so what it is doing and two of the switches are not available. The log below still shows its work.</p>'
    : n.phase ? '<p><b>' + esc(PHASE[n.phase] || n.phase) + '</b>' + (n.source ? ' · ' + esc(n.source.split('/').pop()) : '') + '</p>' +
        (n.note ? '<p class="muted">' + esc(n.note) + '</p>' : '') + (n.file ? '<p class="muted">now: ' + esc(n.file.split('/').pop()) + '</p>' : '')
    : '<p class="muted">Nothing to do right now.</p>';
  const sw = (on_, off, title, sub, dis) => '<div class="row"><div class="t">' + title + '<small>' + sub + '</small></div>' +
    '<button class="sw' + (on_ ? ' on' : '') + '" data-do="' + (on_ ? off[1] : off[0]) + '"' + (dis ? ' disabled' : '') + ' title="' + (on_ ? 'Turn off' : 'Turn on') + '"></button></div>';
  return '<h2>Rushes Helper on this Mac</h2><div class="box">' + state + '</div>' +
    '<div class="box"><div class="muted" style="margin-bottom:6px">What it is doing</div>' + now + '</div>' +
    (s.said ? '<p class="said">' + esc(s.said) + '</p>' : '') + (s.busy ? '<p><span class="spin"></span>' + esc(s.busy) + '</p>' : '') +
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
    // to wonder whether someone can reach in. Off, and not built yet.
    '<div class="box">' +
      '<div class="row"><div class="t">Diagnostics<small>Everything someone helping you would ask for, in one text file on your Desktop, ' +
        'to read before you send it to anyone: versions, switches, and what the helper said lately. It names folders and files; ' +
        'it holds no footage and no passwords. Nothing is sent.</small></div>' + btn('Collect diagnostics', 'diagnostics', false, !!s.busy) + '</div>' +
      '<div class="row"><div class="t">Support access<small>Off. There is no way for anyone — the author, IT or anyone else — to connect to this Mac through Rushes Helper. ' +
        'When support sessions exist they will be off by default, turned on only here by you, time-limited, shown on screen while open, and closed with one button.</small></div>' +
      '<button class="sw" disabled title="Not built yet: nobody can turn this on"></button></div>' +
    '</div>' +
    '<p class="muted" style="font-size:12.5px">Updates: Rushes Helper keeps its own code the same as your Rushes server\'s (' + esc(s.url) +
      ', never anywhere else), checking every few minutes and only between jobs. Nothing else can reach this Mac through it.</p>' +
    '<div class="box"><div class="muted" style="margin-bottom:6px">What it did lately <span style="float:right">Rushes: ' + esc(s.url) + '</span></div><pre>' +
      esc((s.log || []).join('\n') || 'Nothing written yet.') + '</pre></div>';
}
async function poll() {
  try { S = await (await fetch(K + 'state')).json(); draw(); } catch (e) {}
}
poll(); setInterval(poll, 1500);
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
                return self._send(200, PAGE, "text/html")
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
    if "--window" in a:
        i = a.index("--window")
        sys.exit(serve(int(a[i + 1]), a[i + 2]))
    if "--service" in a or "--watch" in a:
        sys.exit(service(a))
    print("Rushes Helper: open it from Finder to see its window.")
