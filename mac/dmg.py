# Rushes — Media Management Software, by Alejandro Renteria.
# Source available: https://github.com/x0on/rushes — whoever finds this file on a computer can see what it is and who made it.
"""Packs a signed Rushes.app as a disk image, the way Mac apps usually come: open
it, and its window shows Rushes, an arrow, and Applications to drag it onto.

  python3 mac/dmg.py "mac/out/Rushes.app" "Rushes 0.12.1.dmg"

Runs on Linux (no hdiutil, which only a Mac has): the volume is written as an
ISO 9660 image with Rock Ridge (names, permissions, the symlinks inside the app
and the Applications shortcut kept as they are; macOS mounts it like any disk
image), then made a compressed .dmg (UDIF) by libdmg-hfsplus's dmg, as Bitcoin
Core's Mac releases were. Run it after the app is signed: the app goes in as it is.

Needs: pip install pycdlib ds-store mac-alias pillow (pillow only to draw the
background), and the dmg program on the PATH or beside this file as dmg-<arch>
(github.com/fanquake/libdmg-hfsplus, built with zlib).
"""
import datetime, io, os, platform, shutil, subprocess, sys, tempfile

import pycdlib
from ds_store import DSStore
from mac_alias import Alias, VolumeInfo, TargetInfo

W, H = 640, 400                       # the window, in points
ICON_AT = {"app": (170, 190), "Applications": (470, 190)}


def background(path):
    """The window's picture: an arrow from Rushes to Applications, and one line saying so."""
    from PIL import Image, ImageDraw, ImageFont
    for scale, name in ((1, path), (2, path.replace(".png", "@2x.png"))):
        im = Image.new("RGB", (W * scale, H * scale), (246, 245, 242))
        d = ImageDraw.Draw(im)
        y = ICON_AT["app"][1] * scale
        x0, x1 = (ICON_AT["app"][0] + 80) * scale, (ICON_AT["Applications"][0] - 80) * scale
        d.line([(x0, y), (x1 - 14 * scale, y)], fill=(47, 125, 116), width=4 * scale)
        d.polygon([(x1, y), (x1 - 18 * scale, y - 11 * scale), (x1 - 18 * scale, y + 11 * scale)], fill=(47, 125, 116))
        try:
            f = ImageFont.truetype("DejaVuSans.ttf", 15 * scale)
        except OSError:
            f = ImageFont.load_default()
        t = "Drag Rushes onto Applications to install it"
        w = d.textlength(t, font=f)
        d.text(((W * scale - w) / 2, 320 * scale), t, fill=(107, 106, 102), font=f)
        im.save(name)


def ds_store(path, volume, app):
    """Finder's notes for the window: its size, icon view, where each icon sits, the picture."""
    now = datetime.datetime.now(datetime.timezone.utc)
    alias = Alias()
    alias.volume = VolumeInfo(volume, now, b"H+", 5, 0, b"\0\0")
    alias.volume.posix_path = "/Volumes/" + volume
    alias.target = TargetInfo(0, "background.png", 0, 0, now, b"PNGf", b"\0\0\0\0")
    alias.target.carbon_path = f"{volume}:.background:\0background.png"
    alias.target.posix_path = "/.background/background.png"
    with DSStore.open(path, "w+") as d:
        d["."]["bwsp"] = {"WindowBounds": f"{{{{200, 200}}, {{{W}, {H}}}}}", "ShowStatusBar": False, "ShowToolbar": False,
                          "ShowSidebar": False, "ShowPathbar": False, "ShowTabView": False, "ContainerShowSidebar": False}
        d["."]["icvp"] = {"viewOptionsVersion": 1, "backgroundType": 2, "backgroundImageAlias": alias.to_bytes(),
                          "iconSize": 112.0, "textSize": 13.0, "labelOnBottom": True, "showIconPreview": True,
                          "showItemInfo": False, "arrangeBy": "none", "gridSpacing": 100.0, "gridOffsetX": 0.0, "gridOffsetY": 0.0}
        d["."]["vSrn"] = ("long", 1)
        d[app]["Iloc"] = ICON_AT["app"]
        d["Applications"]["Iloc"] = ICON_AT["Applications"]


def iso(src, out, volume):
    """The folder src as an ISO 9660 volume with Rock Ridge: real names, modes and symlinks."""
    c = pycdlib.PyCdlib()
    c.new(interchange_level=4, rock_ridge="1.09", vol_ident=volume[:32])
    n = [0]
    def name(isdir):                              # ISO names are only placeholders: Rock Ridge has the real ones
        n[0] += 1
        return f"{'D' if isdir else 'F'}{n[0]:07d}"
    def walk(here, iso_dir):
        for e in sorted(os.scandir(here), key=lambda e: e.name):
            st = os.lstat(e.path)
            p = iso_dir.rstrip("/") + "/" + name(e.is_dir(follow_symlinks=False))
            if e.is_symlink():
                c.add_symlink(p, rr_symlink_name=e.name, rr_path=os.readlink(e.path))
            elif e.is_dir():
                c.add_directory(p, rr_name=e.name, file_mode=0o040755)
                walk(e.path, p)
            else:                                 # opened only while written: an app has thousands of files
                c.add_file(e.path, p, rr_name=e.name, file_mode=0o100755 if st.st_mode & 0o111 else 0o100644)
    walk(src, "/")
    c.write(out)
    c.close()


def tool():
    here = os.path.dirname(os.path.abspath(__file__))
    for p in (shutil.which("dmg"), os.path.join(here, "dmg-" + platform.machine())):
        if p and os.access(p, os.X_OK):
            return p
    sys.exit("the dmg program is missing (libdmg-hfsplus): put it on the PATH or beside this file as dmg-" + platform.machine())


def main(app, out):
    app = os.path.abspath(app.rstrip("/")); name = os.path.basename(app)
    volume = os.path.splitext(os.path.basename(out))[0]          # "Rushes 0.12.1": the window's title says the version
    with tempfile.TemporaryDirectory() as t:
        s = os.path.join(t, "v"); os.makedirs(os.path.join(s, ".background"))
        print(f"copying {name} …"); shutil.copytree(app, os.path.join(s, name), symlinks=True)
        os.symlink("/Applications", os.path.join(s, "Applications"))
        background(os.path.join(s, ".background", "background.png"))
        ds_store(os.path.join(s, ".DS_Store"), volume, name)
        print("writing the volume …"); iso(s, os.path.join(t, "v.iso"), volume)
        print("compressing it as a .dmg …")
        subprocess.run([tool(), os.path.join(t, "v.iso"), out], check=True, stdout=subprocess.DEVNULL)
    print(f"{out}: {os.path.getsize(out) / 1e6:.1f} MB")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    main(*sys.argv[1:])
