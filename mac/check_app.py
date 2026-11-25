#!/usr/bin/env python3
# Rushes — Media Management Software, by Alejandro Renteria.
"""Is everything that should be inside a built Rushes really inside it?

  python3 mac/check_app.py "Rushes 0.15.3.zip" [report.txt]      (or a Rushes.app folder)

Looks into the finished app itself, not at what the build meant to put there: a part that was missing beside
build.py once went unnoticed for weeks (xxHash, October–November 2026: the app fell back to another fingerprint,
wrote no ASC MHL record and checked no copy later, and said nothing). Each line is PASS or FAIL; one FAIL and it
exits 1. build.py runs it on what it has just built and keeps the lines beside the zip as the build's report;
GitHub runs it again on every published release (.github/workflows/release-check.yml), so a release that lacks
a part shows red there, where it cannot be missed."""
import io, json, os, sys, zipfile

HERE = os.path.dirname(os.path.abspath(__file__))


def names_of(path):
    """Every file in the app, as paths from Contents/ (a zip, or the .app folder itself)."""
    if os.path.isdir(path):
        out = {}
        for r, _, fs in os.walk(path):
            for f in fs:
                p = os.path.join(r, f); out[os.path.relpath(p, path).replace(os.sep, "/")] = p
        return out, lambda n: open(out[n], "rb").read()
    z = zipfile.ZipFile(path)
    top = next(n for n in z.namelist() if n.endswith(".app/Contents/Info.plist")).rsplit("Contents/", 1)[0]
    return {n[len(top):]: n for n in z.namelist() if n.startswith(top) and not n.endswith("/")}, lambda n: z.read(top + n)


def check(path, parts=None):
    names, read = names_of(path)
    R = "Contents/Resources/"
    watcher = R + "rushes_watcher.py" in names
    parts = parts or json.load(open(os.path.join(HERE, "parts.json"), encoding="utf-8"))["parts"]
    if watcher: parts = [x for x in parts if x["name"] in ("CPython", "python-build-standalone")]
    lines = []
    def say(ok, what): lines.append(("PASS " if ok else "FAIL ") + what)
    say(R + "rushes_helper.py" in names, "the app's own code is inside")
    for chip in ("arm64", "x86_64"):
        say(any(n.startswith(f"{R}python-{chip}/bin/python3") for n in names), f"Python for {chip}")
        if not watcher:
            say(f"{R}php-{chip}" in names, f"PHP for {chip}")
            so = [n for n in names if n.startswith(f"{R}python-{chip}/lib/python3.12/site-packages/xxhash/_xxhash") and n.endswith(".so")]
            say(bool(so), f"xxHash for {chip}: the fingerprint copies are proven with (XXH3-128), ASC MHL records, Check copies")
    for x in parts:
        if x["where"] == "in the app" and x.get("license_file"):
            n = R + "LICENSES/" + x["license_file"]
            say(n in names and len(read(n)) > 200, f"license text of {x['name']} (LICENSES/{x['license_file']})")
    say(R + "CREDITS.md" in names, "CREDITS.md")
    if not watcher:
        v = read(R + "pages/VERSION").decode().strip() if R + "pages/VERSION" in names else ""
        say(bool(v), f"Rushes' pages, version {v or '?'}")
        for f in ("pages/db/find.php", "pages/db/viewer.js", "runner.py", "ingest.py", "analyze.py", "release.py", "release.sig"):
            say(R + f in names, f)
        if R + "release.sig" in names:                     # the helper's code is the signed release, byte for byte
            import hashlib
            sig = read(R + "release.sig").decode().splitlines()
            for l in sig:
                h, _, f = l.partition(" ")
                if len(h) == 64 and f:
                    say(R + f in names and hashlib.sha256(read(R + f)).hexdigest() == h, f"{f} is the signed one")
    return lines


if __name__ == "__main__":
    if len(sys.argv) < 2: sys.exit(__doc__)
    lines = check(sys.argv[1])
    text = f"{os.path.basename(sys.argv[1])}: what is inside it\n" + "\n".join(lines) + "\n"
    bad = [l for l in lines if l.startswith("FAIL")]
    text += f"\n{len(bad)} FAILED of {len(lines)}\n" if bad else f"\nall {len(lines)} checks pass\n"
    print(text, end="")
    if len(sys.argv) > 2: open(sys.argv[2], "w", encoding="utf-8").write(text)
    sys.exit(1 if bad else 0)
