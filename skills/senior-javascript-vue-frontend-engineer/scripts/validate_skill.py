#!/usr/bin/env python3
"""Validate an Agent Skills package using only Python's standard library.

This structural validator intentionally does not assert semantic/professional quality.
"""
from __future__ import annotations
import argparse
import re
import sys
from pathlib import Path

NAME = re.compile(r"[a-z0-9]+(?:-[a-z0-9]+)*\Z")
FRONT = re.compile(r"\A---\r?\n(.*?)\r?\n---\r?\n", re.DOTALL)
LINK = re.compile(r"\]\(([^)]+)\)")


def validate(root: Path) -> list[str]:
    errors: list[str] = []
    root = root.resolve()
    if not root.is_dir():
        return [f"Not a directory: {root}"]
    manifests = [p for p in root.rglob('*') if p.is_file() and p.name.lower() == 'skill.md']
    if len(manifests) != 1 or manifests[0] != root / 'SKILL.md':
        errors.append('Exactly one root SKILL.md is required (case-insensitive).')
        return errors
    content = (root / 'SKILL.md').read_text(encoding='utf-8-sig')
    match = FRONT.match(content)
    if not match:
        errors.append('SKILL.md must start with --- YAML frontmatter --- then content.')
        return errors
    metadata: dict[str, str] = {}
    for ln in match.group(1).splitlines():
        if not ln.strip() or ln.lstrip().startswith('#'):
            continue
        if ':' not in ln or ln[:1].isspace():
            errors.append(f'Invalid frontmatter line: {ln[:70]}')
            continue
        k, v = ln.split(':', 1)
        if k in metadata:
            errors.append(f'Duplicate frontmatter key: {k}')
        metadata[k.strip()] = v.strip().strip('"\'')
    name = metadata.get('name', '')
    desc = metadata.get('description', '')
    if not name or len(name) > 64 or not NAME.fullmatch(name):
        errors.append('name must use <=64 lowercase letters, digits, single hyphen separators.')
    if name != root.name:
        errors.append('name must match top-level directory name.')
    if not desc or len(desc) > 1024:
        errors.append('description must be nonempty and <=1024 characters.')
    if len(content[match.end():].strip()) == 0:
        errors.append('Instructions body must not be empty.')
    for file in [root / 'SKILL.md', *root.glob('references/*.md')]:
        src = file.read_text(encoding='utf-8')
        for dest in LINK.findall(src):
            # Ignore external and anchor links, only check file references.
            dest = dest.strip().split(' ', 1)[0]
            if dest.startswith(('https://','http://','#','mailto:')):
                continue
            candidate = (file.parent / dest.split('#', 1)[0]).resolve()
            if not candidate.is_relative_to(root) or not candidate.is_file():
                errors.append(f'Invalid local link from {file.relative_to(root)}: {dest}')
    return errors


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('skill_dir', type=Path)
    ns = ap.parse_args()
    errors = validate(ns.skill_dir)
    if errors:
        print('INVALID SKILL:')
        for error in errors:
            print(' -', error)
        return 1
    print('VALID STRUCTURE:', ns.skill_dir.resolve())
    return 0


if __name__ == '__main__':
    sys.exit(main())
