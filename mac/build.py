# Rushes — Media Management Software, by Alejandro Renteria.
# Open source: https://github.com/x0on/rushes — whoever finds this file on a computer can see what it is and who made it.
"""Builds Rushes Helper.app and its zip, from Linux.

  python3 mac/build.py   ->  mac/out/Rushes Helper.zip  (put it on the archive as _rushes/Rushes Helper.zip)

Runs on Linux or a Mac. Needs, next to this file or on the PATH:
  - the two python-build-standalone "install_only_stripped" tarballs named in PBS below
    (github.com/astral-sh/python-build-standalone/releases)
  - python3.12, to precompile the standard library
  - zig, to compile the launcher for both chips (pip install ziglang)
  - rcodesign, to sign it (github.com/indygreg/apple-platform-rs, apple-codesign)
"""
import os, plistlib, shutil, struct, subprocess, sys, tarfile

HERE = os.path.dirname(os.path.abspath(__file__))
TOP = HERE
OUT = os.path.join(TOP, "out")
APP = os.path.join(OUT, "Rushes Helper.app")
C = os.path.join(APP, "Contents")
PBS = "cpython-3.12.11+20250918-{}-apple-darwin-install_only_stripped.tar.gz"
ARCHES = {"arm64": "aarch64", "x86_64": "x86_64"}
VERSION = "1.0"
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


shutil.rmtree(OUT, ignore_errors=True)
os.makedirs(os.path.join(C, "MacOS")); os.makedirs(os.path.join(C, "Resources"))

# ── the launcher, for both chips ────────────────────────────────────────────
parts = []
for a, z in ARCHES.items():
    exe = os.path.join(OUT, f"launcher-{a}")
    run(sys.executable, "-m", "ziglang", "cc", "-target", f"{z}-macos.11.0", "-Os", "-Wl,-headerpad,0x1000",
        "-o", exe, os.path.join(HERE, "launcher.c"))
    parts.append((a, exe))
fat(parts, os.path.join(C, "MacOS", "Rushes Helper"))
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
    os.makedirs(os.path.join(std, "site-packages"))
    dyn = os.path.join(std, "lib-dynload")
    for f in os.listdir(dyn):
        if f.startswith(("_tkinter", "_test", "_xxtest", "_ctypes_test")) or "xxlimited" in f:
            os.remove(os.path.join(dyn, f))
    # Unchecked-hash .pyc: never considered stale, so Python never tries to
    # rewrite them — the signed app stays exactly as signed.
    run("python3.12", "-m", "compileall", "-q", "-j0", "--invalidation-mode", "unchecked-hash", std)

# ── the rest of the app ─────────────────────────────────────────────────────
shutil.copy(os.path.join(HERE, "rushes_helper.py"), os.path.join(C, "Resources"))
shutil.copy(os.path.join(HERE, "AppIcon.icns"), os.path.join(C, "Resources"))
# What Rushes is made of, and whose each part is: shown in the app's window.
shutil.copy(os.path.join(HERE, "..", "CREDITS.md") if os.path.exists(os.path.join(HERE, "..", "CREDITS.md"))
            else os.path.join(HERE, "CREDITS.md"), os.path.join(C, "Resources"))
with open(os.path.join(C, "Info.plist"), "wb") as f:
    plistlib.dump({
        "CFBundleIdentifier": "org.rushes.helper",
        "CFBundleName": "Rushes Helper", "CFBundleDisplayName": "Rushes Helper",
        "CFBundleExecutable": "Rushes Helper", "CFBundleIconFile": "AppIcon",
        "CFBundlePackageType": "APPL", "CFBundleInfoDictionaryVersion": "6.0",
        "CFBundleShortVersionString": VERSION, "CFBundleVersion": VERSION,
        "LSMinimumSystemVersion": "11.0",
        "LSUIElement": True,                     # no Dock icon in the background; the window adds one while open
        # The window shows a page this app serves on this computer only (127.0.0.1).
        "NSAppTransportSecurity": {"NSAllowsLocalNetworking": True},
        # Without this line macOS refuses local-network connections without asking ("No route to host").
        "NSLocalNetworkUsageDescription": "Rushes Helper talks to Rushes on your network: it asks what to copy "
                                          "and reports what it is doing.",
        "NSHumanReadableCopyright": "Rushes Media Management Software · by Alejandro Renteria · open source, github.com/x0on/rushes",
    }, f)

# ── sign and pack ───────────────────────────────────────────────────────────
# With the author's certificate (RUSHES_SIGN_KEY / RUSHES_SIGN_CERT, kept
# privately, never in this repo) the app says who made it, and every build
# carries the same identity, so macOS keeps the permissions granted to the last
# one. Without them: ad hoc, which is nobody, and a new identity every build.
KEY, CERT = os.environ.get("RUSHES_SIGN_KEY"), os.environ.get("RUSHES_SIGN_CERT")
run(RC, "sign", *(["--pem-file", KEY, "--pem-file", CERT] if KEY and CERT else []), APP)
info = subprocess.run([RC, "print-signature-info", os.path.join(C, "MacOS", "Rushes Helper")], capture_output=True, text=True).stdout
# both chips signed as org.rushes.helper, each sealing the Info.plist and the resources
assert info.count("identifier: org.rushes.helper") == 2 and info.count("Resources (3)") == 2, info[:2000]
if KEY and CERT:
    assert "Alejandro Renteria" in info, "signed, but not with the author's certificate"
print("signed by:", "the author's certificate" if KEY and CERT else "nobody (ad hoc)")
z = os.path.join(OUT, "Rushes Helper.zip")
run("zip", "-qry", z, "Rushes Helper.app", cwd=OUT)
print(f"\n{z}: {os.path.getsize(z) / 1e6:.1f} MB")
