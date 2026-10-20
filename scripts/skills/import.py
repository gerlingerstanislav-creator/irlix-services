#!/usr/bin/env python3
"""Import the five owner-supplied ChatGPT Skill ZIPs into portable repo folders.

Usage (from any working directory):
    python scripts/skills/import.py --dry-run /path/to/*.zip
    python scripts/skills/import.py /path/to/*.zip

The importer does not execute code from the uploaded archives and has no network access.
Review the archive contents before committing to a public repository.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import shutil
import stat
import sys
import tempfile
from pathlib import Path, PurePosixPath
from zipfile import ZipFile, BadZipFile

REPO = Path(__file__).resolve().parents[2]
EXPECTED = {
    "senior-business-system-analyst",
    "senior-product-uiux-designer",
    "senior-javascript-vue-frontend-engineer",
    "senior-php-laravel-backend-engineer",
    "senior-qa-engineer",
}
MAX_FILE_BYTES = 8 * 1024 * 1024
MAX_TOTAL_BYTES = 24 * 1024 * 1024
MAX_FILES = 1000


def safe_extract(source: Path, target: Path) -> tuple[str, int]:
    written = 0
    with ZipFile(source) as archive:
        paths = [item for item in archive.infolist() if not item.is_dir()]
        if not paths or len(paths) > MAX_FILES:
            raise ValueError(f"{source.name}: empty archive or too many files")
        if sum(p.file_size for p in paths) > MAX_TOTAL_BYTES:
            raise ValueError(f"{source.name}: uncompressed size limit exceeded")
        roots = set()
        for item in paths:
            name = item.filename
            rel = PurePosixPath(name)
            if (not name or name.startswith("/") or "\\" in name or
                    "." in rel.parts or ".." in rel.parts or len(rel.parts) < 2):
                raise ValueError(f"{source.name}: unsafe archive path: {name!r}")
            root = rel.parts[0]
            if root not in EXPECTED:
                raise ValueError(f"{source.name}: unexpected skill directory: {root}")
            roots.add(root)
            if item.file_size > MAX_FILE_BYTES or stat.S_ISLNK(item.external_attr >> 16):
                raise ValueError(f"{source.name}: large file or symlink: {name}")
            if len(name) > 240:
                raise ValueError(f"{source.name}: file path too long: {name}")
        if len(roots) != 1:
            raise ValueError(f"{source.name}: expected exactly one skill root")
        root = next(iter(roots))
        if f"{root}/SKILL.md" not in {i.filename for i in paths}:
            raise ValueError(f"{source.name}: SKILL.md missing")
        for item in paths:
            rel = PurePosixPath(item.filename)
            dest = target.joinpath(*rel.parts)
            if dest.exists():
                raise ValueError(f"{source.name}: duplicate file: {item.filename}")
            dest.parent.mkdir(parents=True, exist_ok=True)
            with archive.open(item) as src, dest.open("wb") as dst:
                shutil.copyfileobj(src, dst)
            written += 1
    return root, written


def build_manifest(target: Path) -> dict:
    skills = []
    for slug in sorted(EXPECTED):
        directory = target / slug
        files = [
            {
                "path": f.relative_to(target).as_posix(),
                "sha256": hashlib.sha256(f.read_bytes()).hexdigest(),
                "bytes": f.stat().st_size,
            }
            for f in sorted(directory.rglob("*"))
            if f.is_file()
        ]
        skills.append({"slug": slug, "files": files})
    return {"schema": 1, "source": "user-supplied ChatGPT Skill ZIP exports", "skills": skills}


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("archives", nargs="+", type=Path)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--target", type=Path, default=REPO / "skills")
    options = parser.parse_args(argv)
    target = options.target.resolve()
    if target == REPO or not (target == REPO / "skills" or
                                (REPO / "skills") in target.parents):
        parser.error("target must be within the repository skills/ directory")
    with tempfile.TemporaryDirectory(prefix="irlix-skills-") as folder:
        staging = Path(folder)
        counts = {}
        for path in options.archives:
            try:
                slug, n = safe_extract(path, staging)
            except (BadZipFile, FileNotFoundError, OSError, ValueError) as e:
                parser.error(str(e))
            if slug in counts:
                parser.error(f"duplicate skill bundle {slug}")
            counts[slug] = n
        missing = EXPECTED - counts.keys()
        if missing:
            parser.error("missing skill bundles: " + ", ".join(sorted(missing)))
        manifest = build_manifest(staging)
        report = {item["slug"]: len(item["files"]) for item in manifest["skills"]}
        print("Validated skill bundles:", json.dumps(report, ensure_ascii=False))
        if options.dry_run:
            print("Dry run: no files written.")
            return 0
        if any((target / slug).exists() for slug in EXPECTED):
            parser.error("target already contains a skill; move existing bundles before re-import")
        target.mkdir(parents=True, exist_ok=True)
        for slug in sorted(EXPECTED):
            shutil.copytree(staging / slug, target / slug)
        (target / "MANIFEST.json").write_text(
            json.dumps(manifest, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )
        print("Imported all bundles into", target)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
