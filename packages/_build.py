"""Build a Joomla installable pkg_dpcalendar zip from this repository."""

from __future__ import annotations

import io
import os
import re
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PACKAGES = Path(__file__).resolve().parent
VERSION = "10.11.2.5"
CREATION_DATE = "2026-09-15"
PREVIOUS_PACKAGE = PACKAGES / "pkg_dpcalendar-10.11.2.1.zip"
OFFICIAL_PACKAGE = PACKAGES / "DPCalendar-Free_10_12_0.zip"
OUTPUT = PACKAGES / f"pkg_dpcalendar-{VERSION}.zip"

SKIP_NAMES = {".gitkeep", ".gitignore", ".gitattributes", ".DS_Store", "Thumbs.db"}
SKIP_DIR_NAMES = {".git", "__pycache__"}
SKIP_SUFFIXES = {".pyc"}

PLUGINS = [
    "plg_actionlog_dpcalendar",
    "plg_content_dpcalendar",
    "plg_editors-xtd_dpcalendar",
    "plg_fields_dpcalendar",
    "plg_privacy_dpcalendar",
    "plg_quickicon_dpcalendar",
    "plg_user_dpcalendar",
]


def should_skip(path: Path) -> bool:
    if path.name in SKIP_NAMES or path.suffix in SKIP_SUFFIXES:
        return True
    return any(part in SKIP_DIR_NAMES for part in path.parts)


def add_directory(members: dict[str, bytes], src: Path, arc_prefix: str = "") -> None:
    for file in sorted(src.rglob("*")):
        if not file.is_file() or should_skip(file):
            continue
        rel = file.relative_to(src).as_posix()
        arcname = f"{arc_prefix}/{rel}" if arc_prefix else rel
        members[arcname] = file.read_bytes()


def overlay_from_zip(
    members: dict[str, bytes],
    source: zipfile.ZipFile,
    prefixes: tuple[str, ...],
    overwrite: bool,
) -> int:
    added = 0
    for name in source.namelist():
        if name.endswith("/"):
            continue
        if not name.startswith(prefixes):
            continue
        if not overwrite and name in members:
            continue
        members[name] = source.read(name)
        added += 1
    return added


def write_zip(members: dict[str, bytes]) -> bytes:
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w", zipfile.ZIP_DEFLATED) as zf:
        for name in sorted(members):
            zf.writestr(name, members[name])
    return buffer.getvalue()


def bump_manifest(xml: str) -> str:
    xml = re.sub(r"<version>[^<]+</version>", f"<version>{VERSION}</version>", xml)
    xml = re.sub(r"<creationDate>[^<]+</creationDate>", f"<creationDate>{CREATION_DATE}</creationDate>", xml)
    return xml


def build_component(previous: zipfile.ZipFile, official: zipfile.ZipFile) -> bytes:
    members: dict[str, bytes] = {}
    add_directory(members, ROOT / "com_dpcalendar")
    official_component = zipfile.ZipFile(io.BytesIO(official.read("com_dpcalendar.zip")))
    previous_component = zipfile.ZipFile(io.BytesIO(previous.read("com_dpcalendar.zip")))
    vendor = overlay_from_zip(members, official_component, ("admin/vendor/",), overwrite=True)
    media = overlay_from_zip(members, official_component, ("media/js/", "media/css/"), overwrite=True)
    filled = overlay_from_zip(
        members,
        previous_component,
        ("admin/vendor/", "media/js/", "media/css/"),
        overwrite=False,
    )
    print(f"  com_dpcalendar: {vendor} vendor from 10.12.0, {media} compiled media from 10.12.0, +{filled} filled from previous")
    return write_zip(members)


def build_plugin(name: str, previous: zipfile.ZipFile, official: zipfile.ZipFile) -> bytes:
    members: dict[str, bytes] = {}
    add_directory(members, ROOT / name)
    official_plugin = zipfile.ZipFile(io.BytesIO(official.read(f"{name}.zip")))
    previous_plugin = zipfile.ZipFile(io.BytesIO(previous.read(f"{name}.zip")))
    media = overlay_from_zip(members, official_plugin, ("media/",), overwrite=True)
    filled = overlay_from_zip(members, previous_plugin, ("media/",), overwrite=False)
    if media or filled:
        print(f"  {name}: {media} media from 10.12.0, +{filled} filled from previous")
    return write_zip(members)


def build_installer(previous: zipfile.ZipFile, official: zipfile.ZipFile) -> bytes:
    source = official if "plg_installer_dpcalendar.zip" in official.namelist() else previous
    plugin = zipfile.ZipFile(io.BytesIO(source.read("plg_installer_dpcalendar.zip")))
    members: dict[str, bytes] = {}
    for name in plugin.namelist():
        if name.endswith("/"):
            continue
        data = plugin.read(name)
        if name.endswith("dpcalendar.xml"):
            data = bump_manifest(data.decode("utf-8")).encode("utf-8")
        members[name] = data
    return write_zip(members)


def main() -> None:
    if not PREVIOUS_PACKAGE.exists():
        raise SystemExit(f"Previous package not found: {PREVIOUS_PACKAGE}")
    if not OFFICIAL_PACKAGE.exists():
        raise SystemExit(f"Official package not found: {OFFICIAL_PACKAGE}")

    previous = zipfile.ZipFile(PREVIOUS_PACKAGE)
    official = zipfile.ZipFile(OFFICIAL_PACKAGE)
    members: dict[str, bytes] = {}

    print("Building component...")
    members["com_dpcalendar.zip"] = build_component(previous, official)

    print("Building plugins...")
    for plugin in PLUGINS:
        members[f"{plugin}.zip"] = build_plugin(plugin, previous, official)

    print("Building installer plugin...")
    members["plg_installer_dpcalendar.zip"] = build_installer(previous, official)

    members["pkg_dpcalendar.xml"] = (ROOT / "pkg_dpcalendar.xml").read_bytes()
    members["script.php"] = (ROOT / "script.php").read_bytes()
    members["License.txt"] = (ROOT / "License.txt").read_bytes()
    members["deleted.php"] = (ROOT / "deleted.php").read_bytes()

    print(f"Writing {OUTPUT.name}...")
    with zipfile.ZipFile(OUTPUT, "w", zipfile.ZIP_DEFLATED) as package:
        for name in (
            "com_dpcalendar.zip",
            "deleted.php",
            "License.txt",
            "pkg_dpcalendar.xml",
            "plg_actionlog_dpcalendar.zip",
            "plg_content_dpcalendar.zip",
            "plg_editors-xtd_dpcalendar.zip",
            "plg_fields_dpcalendar.zip",
            "plg_installer_dpcalendar.zip",
            "plg_privacy_dpcalendar.zip",
            "plg_quickicon_dpcalendar.zip",
            "plg_user_dpcalendar.zip",
            "script.php",
        ):
            package.writestr(name, members[name])

    size_mb = OUTPUT.stat().st_size / (1024 * 1024)
    print(f"Created {OUTPUT} ({size_mb:.2f} MB)")


if __name__ == "__main__":
    os.chdir(ROOT)
    main()
