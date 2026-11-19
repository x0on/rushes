#!/usr/bin/env python3
"""The Premiere panel as one file to double-click: python3 premiere/pack.py <out.ccx>
A .ccx is a zip of this folder with the manifest at its top (Creative Cloud installs it); its version is Rushes' (app/VERSION)."""
import json, os, sys, zipfile
here = os.path.dirname(os.path.abspath(__file__))
m = json.load(open(os.path.join(here, "manifest.json")))
m["version"] = open(os.path.join(here, "..", "app", "VERSION")).read().strip()
with zipfile.ZipFile(sys.argv[1], "w", zipfile.ZIP_DEFLATED) as z:
    z.writestr("manifest.json", json.dumps(m, indent=2))
    for f in ["index.html", "index.js"] + ["icons/" + x for x in sorted(os.listdir(os.path.join(here, "icons")))]:
        z.write(os.path.join(here, f), f)
print(f"{sys.argv[1]}: Rushes panel {m['version']} for Premiere {m['host']['minVersion']} and newer")
