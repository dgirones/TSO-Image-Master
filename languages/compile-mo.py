#!/usr/bin/env python3
"""
Compile tso-image-master-*.po to .mo in this directory.
Requires: pip install polib
Run: py -3 languages/compile-mo.py
"""
from pathlib import Path

try:
	import polib
except ImportError:
	raise SystemExit("Install polib first: py -3 -m pip install polib") from None

here = Path(__file__).resolve().parent
for po in sorted(here.glob("tso-image-master-*.po")):
	mo = po.with_suffix(".mo")
	polib.pofile(str(po)).save_as_mofile(str(mo))
	print("OK", mo.name)
