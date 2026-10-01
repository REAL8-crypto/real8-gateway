#!/usr/bin/env python3
"""Build the exact WordPress.org ZIP; development files never enter it."""
from pathlib import Path
import re
import zipfile

root = Path(__file__).resolve().parents[1]
version = re.search(r"\* Version: ([\d.]+)", (root / "real8-gateway.php").read_text())[1]
stable_tag = re.search(r"^Stable tag: ([\d.]+)$", (root / "readme.txt").read_text(), re.MULTILINE)
constant = re.search(r"define\('REAL8_GATEWAY_VERSION', '([\d.]+)'\)", (root / "real8-gateway.php").read_text())
if not stable_tag or stable_tag[1] != version or not constant or constant[1] != version:
    raise ValueError("Plugin header, version constant and readme stable tag must match")
destination = root / "dist" / f"real8-gateway-{version}.zip"
destination.parent.mkdir(exist_ok=True)
files = [root / name for name in ("real8-gateway.php", "readme.txt", "LICENSE", "THIRD-PARTY-NOTICES.txt")]
for directory in ("includes", "assets/js", "assets/css", "languages"):
    files.extend(p for p in (root / directory).rglob("*") if p.is_file())
with zipfile.ZipFile(destination, "w", zipfile.ZIP_DEFLATED) as archive:
    for path in sorted(files):
        entry = zipfile.ZipInfo("real8-gateway/" + str(path.relative_to(root)), (2026, 10, 1, 0, 0, 0))
        entry.compress_type = zipfile.ZIP_DEFLATED
        entry.external_attr = 0o100644 << 16
        archive.writestr(entry, path.read_bytes())
print(destination)
