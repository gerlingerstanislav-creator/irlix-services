#!/usr/bin/env python3
"""Validate this installable skill package and its local relative references."""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
REQUIRED = [
    "SKILL.md", "references/01-discovery.md", "references/02-risk-design.md",
    "references/03-surfaces.md", "references/04-authorization.md",
    "references/05-automation.md", "references/06-evidence-reporting.md",
    "references/07-methodology-sources.md", "assets/qa-templates.md",
    "assets/bug-report.md", "assets/release-assessment.md",
    "assets/qa-checklist.md", "scripts/coverage_audit.py",
    "scripts/generate_boundaries.py", "examples/README.md"
]


def main():
    issues = []
    for path in REQUIRED:
        if not (ROOT / path).is_file():
            issues.append(f"Missing required: {path}")
    skill = ROOT / "SKILL.md"
    if skill.is_file():
        contents = skill.read_text(encoding="utf-8")
        if not contents.startswith("---\n") or contents.count("\n---\n") < 1:
            issues.append("SKILL.md missing YAML frontmatter")
        if not re.search(r"(?m)^name: senior-qa-engineer$", contents):
            issues.append("Invalid skill name")
        if not re.search(r"(?m)^description: >-$", contents):
            issues.append("Missing discoverable description")
        if len(contents.splitlines()) > 500:
            issues.append("SKILL.md exceeds 500 lines; move details to references")
        for name in ("requirements", "negative", "authorization", "evidence", "regression"):
            if name not in contents.lower():
                issues.append(f"Missing core skill concept: {name}")
        for target in set(re.findall(r"(?<![\w/])(?:references|assets|examples|scripts)/[\w.\-/]+", contents)):
            if not (ROOT / target).is_file():
                issues.append(f"Broken file reference: {target}")
    for p in ROOT.rglob("*.md"):
        if not p.read_text(encoding="utf-8").strip():
            issues.append(f"Empty markdown: {p.relative_to(ROOT)}")
    if issues:
        print("\n".join("FAIL: " + issue for issue in issues))
        return 1
    print(f"PASS: SKILL.md metadata, {len(REQUIRED)} required files, references and structure")
    return 0


if __name__ == "__main__":
    sys.exit(main())
