# Rushes — Media Management Software, by Alejandro Renteria.
# Source available: https://github.com/x0on/rushes — whoever finds this file on a computer can see what it is and who made it.
"""Signed releases of the helper's code (HOW-IT-WORKS.md → Updates).

The helper's code updates itself, and the helper built into the archive
machine runs with full rights. So a new version is accepted only when it
carries a signature (release.sig, beside the files in _rushes) made with the
Rushes release key, which never leaves its owner's computer. Whoever controls
the network, the Rushes server or the archive share, without that key, cannot
change what runs, nor put back an older release: a helper takes only a release
made at the same time as its own, or later.

release.sig is plain text, readable by anyone:

    rushes-release 1
    made <when it was signed, in seconds since 1970>
    <sha256> analyze.py
    <sha256> ingest.py
    <sha256> release.py
    <sha256> transfer_state.py
    sig <Ed25519 signature of every line above, in hex>

    python3 release.py keygen <keyfile>         a new key (prints its public half)
    python3 release.py sign <folder> <keyfile>  sign the files in <folder>
    python3 release.py verify <folder> [<old>]  exit 0 only if <folder> is signed (and, given the
                                                folder it replaces, not older than that)
    python3 release.py --selftest

Ed25519 is written out here (RFC 8032), because the Python on a NAS or inside
Rushes Helper has no library for it. It is slow, a few milliseconds per check,
which is all it needs to be.
"""
import hashlib
import os
import sys
import time

# The Rushes release key, public half. Its private half is kept by the author.
# A fork that signs its own releases puts its own public key here.
PUBLIC = "a1752f2a93d4e93e044611088f350958c6321dddbeb457f02b91c56f8b1ecf6c"
FILES = ("analyze.py", "ingest.py", "release.py", "transfer_state.py")
# The certificate both Mac apps are signed with (its fingerprint): an app update
# is taken only when the new app is signed with exactly this one.
APP_CERT_SHA256 = "74bc21677be17b1aa348e67ea1d5912ddca4a27f1d133264ad43f5d4c988b6c5"

# ── Ed25519 (RFC 8032, section 5.1) ─────────────────────────────────────────
P = 2 ** 255 - 19
L = 2 ** 252 + 27742317777372353535851937790883648493
D = -121665 * pow(121666, P - 2, P) % P
SQRT_M1 = pow(2, (P - 1) // 4, P)


def _add(a, b):
    A = (a[1] - a[0]) * (b[1] - b[0]) % P
    B = (a[1] + a[0]) * (b[1] + b[0]) % P
    C = 2 * a[3] * b[3] * D % P
    Dd = 2 * a[2] * b[2] % P
    E, F, G, H = B - A, Dd - C, Dd + C, B + A
    return (E * F % P, G * H % P, F * G % P, E * H % P)


def _mul(s, pt):
    q = (0, 1, 1, 0)
    while s:
        if s & 1:
            q = _add(q, pt)
        pt = _add(pt, pt)
        s >>= 1
    return q


def _encode(pt):
    zi = pow(pt[2], P - 2, P)
    x, y = pt[0] * zi % P, pt[1] * zi % P
    return (y | ((x & 1) << 255)).to_bytes(32, "little")


def _decode(s):
    if len(s) != 32:
        raise ValueError("not a point")
    y = int.from_bytes(s, "little")
    sign, y = y >> 255, y & ((1 << 255) - 1)
    if y >= P:
        raise ValueError("not a point")
    xx = (y * y - 1) * pow(D * y * y + 1, P - 2, P) % P
    x = pow(xx, (P + 3) // 8, P)
    if (x * x - xx) % P:
        x = x * SQRT_M1 % P
    if (x * x - xx) % P:
        raise ValueError("not a point")
    if x == 0 and sign:
        raise ValueError("not a point")
    if (x & 1) != sign:
        x = P - x
    return (x, y, 1, x * y % P)


_BY = 4 * pow(5, P - 2, P) % P
BASE = _decode(_BY.to_bytes(32, "little"))


def _expand(seed):
    h = hashlib.sha512(seed).digest()
    a = int.from_bytes(h[:32], "little")
    a &= (1 << 254) - 8
    a |= 1 << 254
    return a, h[32:]


def public_key(seed):
    return _encode(_mul(_expand(seed)[0], BASE))


def sign(seed, msg):
    a, prefix = _expand(seed)
    pub = _encode(_mul(a, BASE))
    r = int.from_bytes(hashlib.sha512(prefix + msg).digest(), "little") % L
    R = _encode(_mul(r, BASE))
    h = int.from_bytes(hashlib.sha512(R + pub + msg).digest(), "little") % L
    return R + ((r + h * a) % L).to_bytes(32, "little")


def verify(pub, msg, sig):
    try:
        if len(sig) != 64:
            return False
        A, R = _decode(pub), _decode(sig[:32])
        s = int.from_bytes(sig[32:], "little")
        if s >= L:
            return False
        h = int.from_bytes(hashlib.sha512(sig[:32] + pub + msg).digest(), "little") % L
        return _encode(_mul(s, BASE)) == _encode(_add(R, _mul(h, A)))
    except ValueError:
        return False


# ── releases ────────────────────────────────────────────────────────────────
def body(hashes, made):
    """The signed part: when, then one line per file, in name order."""
    return (f"rushes-release 1\nmade {int(made)}\n" + "".join(f"{hashes[n]} {n}\n" for n in sorted(hashes))).encode()


def read_sig(text, public=None):
    """-> ({name: sha256}, made) from a release.sig whose signature is good, or
    raises ValueError saying why not."""
    lines = text.strip("\n").split("\n")
    if len(lines) < 3 or lines[0] != "rushes-release 1" or not lines[1].startswith("made ") \
            or not lines[-1].startswith("sig "):
        raise ValueError("release.sig is not in the expected form")
    try:
        made = int(lines[1][5:])
    except ValueError:
        raise ValueError("release.sig is not in the expected form")
    hashes = {}
    for l in lines[2:-1]:
        h, _, n = l.partition(" ")
        if len(h) != 64 or n not in FILES or n in hashes:
            raise ValueError(f"release.sig lists something unexpected: {l[:80]}")
        hashes[n] = h
    try:
        sig = bytes.fromhex(lines[-1][4:].strip())
        pub = bytes.fromhex(public or PUBLIC)
    except ValueError:
        raise ValueError("release.sig, or the release key, is not readable")
    if not verify(pub, body(hashes, made), sig):
        raise ValueError("release.sig is not signed with the Rushes release key")
    return hashes, made


def made_of(text, public=None):
    """When a good release.sig was made; 0 if it is missing or not good."""
    try:
        return read_sig(text, public)[1]
    except ValueError:
        return 0


def check(files, sig_text, public=None, not_before=0):
    """files: {name: bytes}. Raises ValueError unless each one is exactly what
    a good release.sig lists, and the release is not older than not_before."""
    hashes, made = read_sig(sig_text, public)
    if made < not_before:
        raise ValueError("it is an older release than the one already here")
    for n, data in files.items():
        if hashes.get(n) != hashlib.sha256(data).hexdigest():
            raise ValueError(f"{n} is not the signed version")
    return hashes


# ── the Mac apps, updated from Rushes ───────────────────────────────────────
def version_tuple(v):
    """'0.9.2' -> (0, 9, 2); anything else is (0,), older than every version."""
    import re
    n = re.findall(r"\d+", v or "")
    return tuple(int(x) for x in n[:3]) if n else (0,)


def app_update(url, app, say=print, check_only=False):
    """Rushes Helper or Rushes Watcher (the app at `app`), updated to the version
    Rushes has: one number for all of Rushes, so when Rushes is newer than this
    app, the app on the archive is too. The new app is downloaded from Rushes,
    unpacked beside, and taken only if it is that version, the same app, and
    signed with the Rushes author's certificate (macOS checks the signature).
    Then a small script, on its own, puts it in place of this one and starts it
    again: the settings, pairing and macOS permissions stay, the same signed app.
    -> the newer version ("" when there is none). Raises RuntimeError saying why not."""
    import plistlib, shutil, subprocess, tempfile, urllib.request
    with open(os.path.join(app, "Contents", "Info.plist"), "rb") as f:
        info = plistlib.load(f)
    mine, bundle, name = info.get("CFBundleShortVersionString", "0"), info["CFBundleIdentifier"], info["CFBundleExecutable"]
    with urllib.request.urlopen(url.rstrip("/") + "/db/helper.php?version", timeout=20) as r:
        theirs = r.read().decode("utf-8", "replace").strip()
    if version_tuple(theirs) <= version_tuple(mine):
        return ""
    if check_only:
        return theirs
    say(f"updating {name} {mine} → {theirs}: downloading it from Rushes …")
    tmp = tempfile.mkdtemp(prefix="rushes-update-")
    try:
        zf = os.path.join(tmp, "app.zip")
        which = "watcher" if bundle.endswith(".watcher") else "helper"
        with urllib.request.urlopen(url.rstrip("/") + "/db/helper.php?app=" + which, timeout=600) as r, open(zf, "wb") as f:
            shutil.copyfileobj(r, f)
        if subprocess.run(["ditto", "-x", "-k", zf, os.path.join(tmp, "x")], capture_output=True).returncode:
            raise RuntimeError("the app from Rushes could not be unpacked")
        new = os.path.join(tmp, "x", name + ".app")
        with open(os.path.join(new, "Contents", "Info.plist"), "rb") as f:
            got = plistlib.load(f)
        if got.get("CFBundleIdentifier") != bundle or got.get("CFBundleShortVersionString") != theirs:
            raise RuntimeError(f"the app on the archive is {got.get('CFBundleShortVersionString')}, not {theirs}: "
                               "it is put there with each new version (Install day / Manage)")
        if subprocess.run(["codesign", "--verify", "--deep", "--strict", new], capture_output=True).returncode:
            raise RuntimeError("the app from Rushes is not intact (its signature does not verify)")
        subprocess.run(["codesign", "-d", "--extract-certificates=" + os.path.join(tmp, "cert"), new], capture_output=True)
        try:
            cert = hashlib.sha256(open(os.path.join(tmp, "cert0"), "rb").read()).hexdigest()
        except OSError:
            cert = ""
        if cert != APP_CERT_SHA256:
            raise RuntimeError("the app from Rushes is not signed by the Rushes author's certificate")
    except RuntimeError:
        shutil.rmtree(tmp, ignore_errors=True); raise
    except Exception as e:
        shutil.rmtree(tmp, ignore_errors=True); raise RuntimeError(f"could not get it from Rushes ({e})")
    # On its own (a session of its own), since starting the app again ends this
    # process: the old app aside, the new one in, started; the old one back if not.
    q = lambda s: "'" + s.replace("'", "'\\''") + "'"
    script = os.path.join(tmp, "swap.sh")
    with open(script, "w") as f:
        f.write(f"""sleep 2
A={q(app)}; N={q(new)}
rm -rf "$A.old"
if mv "$A" "$A.old" && mv "$N" "$A"; then
  xattr -dr com.apple.quarantine "$A" 2>/dev/null
  rm -rf "$A.old"; echo "$(date '+%Y-%m-%d %H:%M:%S')  updated to {theirs} ✓"
else
  [ -d "$A" ] || mv "$A.old" "$A"; echo "$(date '+%Y-%m-%d %H:%M:%S')  ! could not put {theirs} in place; {mine} kept"
fi
launchctl kickstart -k gui/$(id -u)/{bundle}
rm -rf {q(tmp)}
""")
    log = os.path.expanduser(os.path.join("~", "Library", "Logs", "Rushes Watcher" if which == "watcher" else "Rushes", "setup.log"))
    with open(log, "a") as out:
        subprocess.Popen(["/bin/sh", script], stdout=out, stderr=out, start_new_session=True)
    say(f"{name} {theirs} checked (signed by the Rushes author) — it is put in place and starts again in a few seconds")
    return theirs


def _files_in(folder):
    return {n: open(os.path.join(folder, n), "rb").read() for n in FILES if os.path.isfile(os.path.join(folder, n))}


def main(argv):
    if argv[:1] == ["--selftest"]:
        return selftest()
    if argv[:1] == ["keygen"] and len(argv) == 2:
        seed = os.urandom(32)
        fd = os.open(argv[1], os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        os.write(fd, seed.hex().encode() + b"\n"); os.close(fd)
        print(f"private key written to {argv[1]} (keep it private; it never goes in the repository)")
        print(f"public key, for PUBLIC in release.py: {public_key(seed).hex()}")
        return 0
    if argv[:1] == ["sign"] and len(argv) == 3:
        seed = bytes.fromhex(open(argv[2]).read().strip())
        files = _files_in(argv[1])
        if set(files) != set(FILES):
            print(f"missing in {argv[1]}: {', '.join(sorted(set(FILES) - set(files)))}"); return 1
        hashes = {n: hashlib.sha256(d).hexdigest() for n, d in files.items()}
        made = int(time.time())
        text = body(hashes, made).decode() + "sig " + sign(seed, body(hashes, made)).hex() + "\n"
        read_sig(text, public_key(seed).hex())
        with open(os.path.join(argv[1], "release.sig"), "w") as f:
            f.write(text)
        print(f"signed {len(files)} files ✓ → {os.path.join(argv[1], 'release.sig')}")
        if public_key(seed).hex() != PUBLIC:
            print("  note: this key is not the one in release.py; helpers will refuse it")
        return 0
    if argv[:1] == ["verify"] and len(argv) in (2, 3):
        try:
            files = _files_in(argv[1])
            old = 0
            if len(argv) == 3:
                try: old = made_of(open(os.path.join(argv[2], "release.sig")).read())
                except OSError: pass
            hashes = check(files, open(os.path.join(argv[1], "release.sig")).read(), not_before=old)
            if set(hashes) != set(files):
                raise ValueError("release.sig and the files beside it do not match")
        except (OSError, ValueError) as e:
            print(f"not a signed release: {e}"); return 1
        print("signed release ✓"); return 0
    print(__doc__); return 2


def selftest():
    # RFC 8032, test 1 and test 2
    s1 = bytes.fromhex("9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60")
    assert public_key(s1).hex() == "d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a"
    assert sign(s1, b"").hex() == ("e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bac"
                                   "c61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b")
    s2 = bytes.fromhex("4ccd089b28ff96da9db6c346ec114e0f5b8a319f35aba624da8cf6ed4fb8a6fb")
    sig2 = sign(s2, b"\x72")
    assert sig2.hex().startswith("92a009a9f0d4cab8720e820b5f642540a2b27b5416503f8fb3762223ebdb69da")
    assert verify(public_key(s2), b"\x72", sig2) and not verify(public_key(s2), b"\x73", sig2)
    assert not verify(public_key(s1), b"\x72", sig2)
    # a release: good, a file changed, a line added, another key
    files = {n: n.encode() * 3 for n in FILES}
    hashes = {n: hashlib.sha256(d).hexdigest() for n, d in files.items()}
    text = body(hashes, 1000).decode() + "sig " + sign(s1, body(hashes, 1000)).hex() + "\n"
    pub = public_key(s1).hex()
    assert check(files, text, pub) == hashes and made_of(text, pub) == 1000
    try:
        check(files, text, pub, not_before=1001); raise AssertionError("accepted an older release")
    except ValueError:
        pass
    assert made_of(text.replace("made 1000", "made 2000"), pub) == 0           # the date is signed too
    for bad_files, bad_text, key in [(dict(files, **{"ingest.py": b"evil"}), text, pub),
                                     (files, text.replace("made 1000\n", "made 1000\n" + "0" * 64 + " ingest.py\n"), pub),
                                     (files, text, public_key(s2).hex())]:
        try:
            check(bad_files, bad_text, key); raise AssertionError("accepted a bad release")
        except ValueError:
            pass
    print("release: all checks pass")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
