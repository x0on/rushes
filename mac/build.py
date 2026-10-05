# Rushes — Media Management Software, by Alejandro Renteria.
# Source available: https://github.com/x0on/rushes — whoever finds this file on a computer can see what it is and who made it.
"""Builds Rushes Helper.app or Rushes Watcher.app, and its zip, from Linux.

  python3 mac/build.py           ->  mac/out/Rushes Helper.zip   (put it on the archive as _rushes/Rushes Helper.zip)
  python3 mac/build.py watcher   ->  mac/out/Rushes Watcher.zip  (for each editor's computer)

Two apps from one launcher and one front (rushes_helper.py); each carries only
its own work: the helper's comes from Rushes (signed releases), the Watcher's
(rushes_watcher.py) is inside it. Neither carries the other.

Runs on Linux or a Mac. Needs, next to this file or on the PATH:
  - the two python-build-standalone "install_only_stripped" tarballs named in PBS below
    (github.com/astral-sh/python-build-standalone/releases)
  - for Rushes Helper, the two static PHP tarballs named in PHP below (static-php.dev,
    the "common" build: dl.static-php.dev/static-php-cli/common/), for Rushes on a Mac
  - python3.12, to precompile the standard library
  - zig, to compile the launcher for both chips (pip install ziglang)
  - rcodesign, to sign it (github.com/indygreg/apple-platform-rs, apple-codesign)
"""
import os, plistlib, shutil, struct, subprocess, sys, tarfile, zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
TOP = HERE
OUT = os.path.join(TOP, "out")
WATCHER = sys.argv[1:] == ["watcher"]
NAME = "Rushes Watcher" if WATCHER else "Rushes Helper"
BUNDLE = "org.rushes.watcher" if WATCHER else "org.rushes.helper"
APP = os.path.join(OUT, NAME + ".app")
C = os.path.join(APP, "Contents")
PBS = "cpython-3.12.11+20250918-{}-apple-darwin-install_only_stripped.tar.gz"
ARCHES = {"arm64": "aarch64", "x86_64": "x86_64"}
PHP = "php-8.5.8-cli-macos-{}.tar.gz"
# Rushes on a Mac (HOW-IT-WORKS.md): the pages, without what only runs elsewhere
# (the NAS's scripts, the helper's code, which goes in on its own, below).
NOT_PAGES = (".htaccess", "router.php", "release.sig", "settings.json")     # settings: each Mac's own, never one from here
# One number for all of Rushes: the pages, the helper's code and both apps (app/VERSION).
VERSION = open(os.path.join(HERE, "..", "app", "VERSION")).read().strip()
RC = shutil.which("rcodesign") or next(os.path.join(r, "rcodesign") for r, _, fs in os.walk(TOP, followlinks=True) if "rcodesign" in fs)

# Parts of Python the helper never uses: the GUI toolkit, tests, the editor,
# pip, headers. About half the size.
DROP_LIB = ["test", "idlelib", "tkinter", "turtledemo", "ensurepip", "lib2to3", "venv",
            "pydoc_data", "unittest/test", "site-packages", "config-3.12-darwin", "turtle.py"]


def run(*a, **k):
    print("+", " ".join(a)); subprocess.run(a, check=True, **k)


def fat(parts, dest):
    """One executable for both chips: a fat header, then each slice at a 16 KB boundary."""
    cpu = {"arm64": (0x0100000C, 0), "x86_64": (0x01000007, 3)}
    blobs = [(a, open(p, "rb").read()) for a, p in parts]
    head = struct.pack(">II", 0xCAFEBABE, len(blobs)); body = b""
    for a, b in blobs:
        head += struct.pack(">IIIII", *cpu[a], 0x4000 + len(body), len(b), 14)
        body += b
        body += b"\0" * (-len(body) % 0x4000)
    with open(dest, "wb") as f:
        f.write(head.ljust(0x4000, b"\0") + body)
    os.chmod(dest, 0o755)


shutil.rmtree(APP, ignore_errors=True)        # the other app, built before, stays
os.makedirs(os.path.join(C, "MacOS")); os.makedirs(os.path.join(C, "Resources"))

# ── the launcher, for both chips ────────────────────────────────────────────
parts = []
for a, z in ARCHES.items():
    exe = os.path.join(OUT, f"launcher-{a}")
    run(sys.executable, "-m", "ziglang", "cc", "-target", f"{z}-macos.11.0", "-Os", "-Wl,-headerpad,0x1000",
        "-o", exe, os.path.join(HERE, "launcher.c"))
    parts.append((a, exe))
fat(parts, os.path.join(C, "MacOS", NAME))
for _, p in parts:
    os.remove(p)

# ── Python, one per chip, trimmed and precompiled ───────────────────────────
for a, z in ARCHES.items():
    dest = os.path.join(C, "Resources", f"python-{a}")
    with tarfile.open(os.path.join(TOP, PBS.format(z))) as t:
        t.extractall(OUT, filter="tar")
    os.rename(os.path.join(OUT, "python"), dest)
    for d in ("include", "share", "lib/pkgconfig"):
        shutil.rmtree(os.path.join(dest, d), ignore_errors=True)
    lib = os.path.join(dest, "lib")
    for f in os.listdir(lib):
        if f.startswith(("tcl", "tk", "itcl", "thread", "libtcl", "libtk")):
            p = os.path.join(lib, f); shutil.rmtree(p) if os.path.isdir(p) else os.remove(p)
    for f in os.listdir(os.path.join(dest, "bin")):
        if f not in ("python3", "python3.12"):
            os.remove(os.path.join(dest, "bin", f))
    std = os.path.join(lib, "python3.12")
    for d in DROP_LIB:
        p = os.path.join(std, d); shutil.rmtree(p) if os.path.isdir(p) else os.path.exists(p) and os.remove(p)
    site = os.path.join(std, "site-packages"); os.makedirs(site)
    # Python packages the helper uses beyond Python itself, as ready-made wheels
    # for this chip, next to this file (pip download --only-binary=:all:
    # --platform macosx_11_0_arm64 / macosx_10_9_x86_64 --python-version 3.12 xxhash).
    for w in [] if WATCHER else os.listdir(TOP):            # the Watcher needs nothing beyond Python
        if w.endswith(".whl") and ("-cp312-" in w) and ({"arm64": "arm64", "x86_64": "x86_64"}[a] in w or "universal2" in w):
            with zipfile.ZipFile(os.path.join(TOP, w)) as zf:
                zf.extractall(site)
            print(f"  {a}: {w}")
    dyn = os.path.join(std, "lib-dynload")
    for f in os.listdir(dyn):
        if f.startswith(("_tkinter", "_test", "_xxtest", "_ctypes_test")) or "xxlimited" in f:
            os.remove(os.path.join(dyn, f))
    # Unchecked-hash .pyc: never considered stale, so Python never tries to
    # rewrite them — the signed app stays exactly as signed.
    run("python3.12", "-m", "compileall", "-q", "-j0", "--invalidation-mode", "unchecked-hash", std)

# ── the rest of the app ─────────────────────────────────────────────────────
shutil.copy(os.path.join(HERE, "rushes_helper.py"), os.path.join(C, "Resources"))
# The Rushes mark for the menu bar (MenuIcon.svg, made into a PDF once; macOS colours it).
shutil.copy(os.path.join(HERE, "MenuIcon.pdf"), os.path.join(C, "Resources"))
if WATCHER:
    shutil.copy(os.path.join(HERE, "rushes_watcher.py"), os.path.join(C, "Resources"))
    # checks an update of the app itself (release.py → app_update)
    shutil.copy(os.path.join(HERE, "..", "app", "release.py"), os.path.join(C, "Resources"))
else:
    # Checks that the helper's code it downloads is a signed release (app/release.py),
    # and brings that code with it (installed when newer than the one in place).
    for f in ("release.py", "ingest.py", "transfer_state.py", "analyze.py", "release.sig"):
        shutil.copy(os.path.join(HERE, "..", "app", f), os.path.join(C, "Resources"))
    # Rushes itself, for an archive on a drive of this Mac: its pages, its door
    # (router.php), its minute's work (runner.py), and PHP for each chip.
    for f in ("runner.py", "router.php", "dedupe.sh", "verify.sh"):
        shutil.copy(os.path.join(HERE, "..", "app", f), os.path.join(C, "Resources"))
    shutil.copytree(os.path.join(HERE, "..", "app"), os.path.join(C, "Resources", "pages"),
                    ignore=lambda d, names: [n for n in names if n in NOT_PAGES or n == "__pycache__" or n.endswith((".py", ".sh", ".tsv", ".sqlite", ".db"))])
    for a, z in ARCHES.items():
        with tarfile.open(os.path.join(TOP, PHP.format(z))) as t:
            m = t.getmember("php"); m.name = f"php-{a}"
            t.extract(m, os.path.join(C, "Resources"), filter="tar")
        os.chmod(os.path.join(C, "Resources", f"php-{a}"), 0o755)
shutil.copy(os.path.join(HERE, "AppIcon.icns"), os.path.join(C, "Resources"))
# What Rushes is made of, and whose each part is: shown in the app's window.
shutil.copy(os.path.join(HERE, "..", "CREDITS.md") if os.path.exists(os.path.join(HERE, "..", "CREDITS.md"))
            else os.path.join(HERE, "CREDITS.md"), os.path.join(C, "Resources"))
with open(os.path.join(C, "Info.plist"), "wb") as f:
    plistlib.dump({
        "CFBundleIdentifier": BUNDLE,
        "CFBundleName": NAME, "CFBundleDisplayName": NAME,
        "CFBundleExecutable": NAME, "CFBundleIconFile": "AppIcon",
        "CFBundlePackageType": "APPL", "CFBundleInfoDictionaryVersion": "6.0",
        "CFBundleShortVersionString": VERSION, "CFBundleVersion": VERSION,
        "LSMinimumSystemVersion": "11.0",
        "LSUIElement": True,                     # no Dock icon in the background (its icon is in the menu bar); the window adds one while open
        # The window shows a page this app serves on this computer only (127.0.0.1).
        "NSAppTransportSecurity": {"NSAllowsLocalNetworking": True},
        # Without this line macOS refuses local-network connections without asking ("No route to host").
        "NSLocalNetworkUsageDescription": ("Rushes Watcher talks to Rushes on your network: it says which files your "
                                           "projects use, and what it is doing." if WATCHER else
                                           "Rushes Helper talks to Rushes on your network: it asks what to copy "
                                           "and reports what it is doing."),
        "NSHumanReadableCopyright": "Rushes Media Management Software · by Alejandro Renteria · source available, github.com/x0on/rushes",
    }, f)

# ── sign and pack ───────────────────────────────────────────────────────────
# With the author's certificate (RUSHES_SIGN_KEY / RUSHES_SIGN_CERT, kept
# privately, never in this repo) the app says who made it, and every build
# carries the same identity, so macOS keeps the permissions granted to the last
# one. Without them: ad hoc, which is nobody, and a new identity every build.
KEY, CERT = os.environ.get("RUSHES_SIGN_KEY"), os.environ.get("RUSHES_SIGN_CERT")
run(RC, "sign", *(["--pem-file", KEY, "--pem-file", CERT] if KEY and CERT else []), APP)
info = subprocess.run([RC, "print-signature-info", os.path.join(C, "MacOS", NAME)], capture_output=True, text=True).stdout
# both chips signed as the app, each sealing the Info.plist and the resources
assert info.count(f"identifier: {BUNDLE}") == 2 and info.count("Resources (3)") == 2, info[:2000]
if KEY and CERT:
    assert "Alejandro Renteria" in info, "signed, but not with the author's certificate"
print("signed by:", "the author's certificate" if KEY and CERT else "nobody (ad hoc)")
z = os.path.join(OUT, NAME + ".zip")
run("zip", "-qry", z, NAME + ".app", cwd=OUT)
print(f"\n{z}: {os.path.getsize(z) / 1e6:.1f} MB")
