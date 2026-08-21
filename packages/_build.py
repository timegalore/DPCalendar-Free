"""Build a Joomla installable pkg_dpcalendar zip from this repository."""

from __future__ import annotations

import io
import os
import re
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PACKAGES = Path(__file__).resolve().parent
VERSION = "10.11.2.3"
PREVIOUS_PACKAGE = PACKAGES / "pkg_dpcalendar-10.11.2.1.zip"
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


def add_directory(zf: zipfile.ZipFile, src: Path, arc_prefix: str = "") -> None:
    for file in sorted(src.rglob("*")):
        if not file.is_file() or should_skip(file):
            continue
        rel = file.relative_to(src).as_posix()
        arcname = f"{arc_prefix}/{rel}" if arc_prefix else rel
        zf.write(file, arcname)


def copy_missing_from_zip(zf: zipfile.ZipFile, source: zipfile.ZipFile, prefixes: tuple[str, ...]) -> int:
    existing = set(zf.namelist())
    added = 0
    for name in source.namelist():
        if name.endswith("/"):
            continue
        if not name.startswith(prefixes):
            continue
        if name in existing:
            continue
        zf.writestr(name, source.read(name))
        added += 1
    return added


def bump_manifest(xml: str) -> str:
    xml = re.sub(r"<version>[^<]+</version>", f"<version>{VERSION}</version>", xml)
    xml = re.sub(r"<creationDate>[^<]+</creationDate>", "<creationDate>2026-08-21</creationDate>", xml)
    return xml


def build_component(previous: zipfile.ZipFile) -> bytes:
    buffer = io.BytesIO()
    src = ROOT / "com_dpcalendar"
    with zipfile.ZipFile(buffer, "w", zipfile.ZIP_DEFLATED) as zf:
        add_directory(zf, src)
        previous_component = zipfile.ZipFile(io.BytesIO(previous.read("com_dpcalendar.zip")))
        added_vendor = copy_missing_from_zip(zf, previous_component, ("admin/vendor/",))
        added_media = copy_missing_from_zip(zf, previous_component, ("media/js/", "media/css/"))
        print(f"  com_dpcalendar: +{added_vendor} vendor files, +{added_media} compiled media files")
    return buffer.getvalue()


def build_plugin(name: str, previous: zipfile.ZipFile) -> bytes:
    buffer = io.BytesIO()
    src = ROOT / name
    with zipfile.ZipFile(buffer, "w", zipfile.ZIP_DEFLATED) as zf:
        add_directory(zf, src)
        previous_plugin = zipfile.ZipFile(io.BytesIO(previous.read(f"{name}.zip")))
        added_media = copy_missing_from_zip(zf, previous_plugin, ("media/",))
        if added_media:
            print(f"  {name}: +{added_media} compiled media files")
    return buffer.getvalue()


def build_installer(previous: zipfile.ZipFile) -> bytes:
    buffer = io.BytesIO()
    previous_plugin = zipfile.ZipFile(io.BytesIO(previous.read("plg_installer_dpcalendar.zip")))
    with zipfile.ZipFile(buffer, "w", zipfile.ZIP_DEFLATED) as zf:
        for name in previous_plugin.namelist():
            if name.endswith("/"):
                continue
            data = previous_plugin.read(name)
            if name.endswith("dpcalendar.xml"):
                data = bump_manifest(data.decode("utf-8")).encode("utf-8")
            zf.writestr(name, data)
    return buffer.getvalue()


def main() -> None:
    if not PREVIOUS_PACKAGE.exists():
        raise SystemExit(f"Previous package not found: {PREVIOUS_PACKAGE}")

    previous = zipfile.ZipFile(PREVIOUS_PACKAGE)
    members: dict[str, bytes] = {}

    print("Building component...")
    members["com_dpcalendar.zip"] = build_component(previous)

    print("Building plugins...")
    for plugin in PLUGINS:
        members[f"{plugin}.zip"] = build_plugin(plugin, previous)

    print("Building installer plugin...")
    members["plg_installer_dpcalendar.zip"] = build_installer(previous)

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
