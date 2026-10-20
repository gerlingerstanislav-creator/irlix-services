#!/usr/bin/env python3
"""Validate repository skillpack paths, hashes, required files, and local links."""
from __future__ import annotations

import hashlib
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SKILLS = ROOT / "skills"
EXPECTED = {
    "senior-business-system-analyst",
    "senior-product-uiux-designer",
    "senior-javascript-vue-frontend-engineer",
    "senior-php-laravel-backend-engineer",
    "senior-qa-engineer",
}
LINK = re.compile(r"(?<!!)\[[^\]]*\]\(([^)]+)\)")


def validate() -> list[str]:
    errors = []
    manifest_file = SKILLS / "MANIFEST.json"
    if not manifest_file.is_file():
        return ["Missing skills/MANIFEST.json: bundle import not yet completed"]
    manifest = json.loads(manifest_file.read_text(encoding="utf-8"))
    entries = manifest.get("skills", [])
    slugs = {s.get("slug") for s in entries}
    if slugs != EXPECTED:
        errors.append("Wrong set of Skills: " + str(slugs))
    declared = set()
    for item in entries:
        slug = item["slug"]
        if slug not in EXPECTED:
            continue
        skill = SKILLS / slug / "SKILL.md"
        if not skill.is_file():
            errors.append("Missing " + str(skill))
        for entry in item.get("files", []):
            rel = entry.get("path", "")
            if rel in declared:
                errors.append("Duplicate manifest path " + rel)
            declared.add(rel)
            path = SKILLS / rel
            if (not rel.startswith(slug + "/") or
                    not path.resolve().is_relative_to((SKILLS / slug).resolve())):
                errors.append("Unexpected manifest path " + rel)
                continue
            if not path.is_file():
                errors.append("Missing " + rel)
                continue
            data = path.read_bytes()
            if len(data) != entry.get("bytes"):
                errors.append("Size mismatch " + rel)
            if hashlib.sha256(data).hexdigest() != entry.get("sha256"):
                errors.append("Hash mismatch " + rel)
        if skill.is_file():
            text = skill.read_text(encoding="utf-8")
            for link in LINK.findall(text):
                relative = link.split("#", 1)[0].split(" ", 1)[0]
                if not relative or ":" in relative or relative.startswith("/"):
                    continue
                destination = (skill.parent / relative).resolve()
                if not destination.is_relative_to(skill.parent.resolve()) or not destination.exists():
                    errors.append(f"{slug}: broken local link {relative}")
    # Reject the flattened browser upload and files accidentally omitted from
    # the SHA256 inventory. The root may contain only its index and manifest.
    allowed = declared | {"MANIFEST.json", "README.md"}
    actual = {
        path.relative_to(SKILLS).as_posix()
        for path in SKILLS.rglob("*")
        if path.is_file()
    }
    for rel in sorted(actual - allowed):
        errors.append("Untracked Skill file " + rel)
    return errors


if __name__ == "__main__":
    failures = validate()
    if failures:
        for problem in failures:
            print("FAIL:", problem, file=sys.stderr)
        sys.exit(1)
    print("PASS: five portable Skills, references and SHA256 manifest")
