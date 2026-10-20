#!/usr/bin/env python3
"""Read-only inventory of a JS/Vue project, producing cautious JSON heuristics.

No network, package installs, writes, env-file reads, credentials, source lines or full source dump.
Only bounded paths, line numbers and matched *category* are emitted. Not a security scanner.
"""
from __future__ import annotations
import argparse
import json
import os
import re
import sys
from pathlib import Path

EXCLUDED_DIRS = {'node_modules','.git','dist','build','coverage','.nuxt','.output','.next','vendor','.cache','playwright-report','test-results'}
SCANNABLE = {'.vue','.ts','.tsx','.js','.jsx','.mjs','.css','.scss'}
LIMIT_FILES = 2500
MAX_FILE_BYTES = 1_000_000
MAX_HINTS = 80
RULES = (
    ('raw-html', re.compile(r'\bv-html\s*=|\binnerHTML\s*='), 'Review trust/sanitization before rendering HTML.'),
    ('index-key', re.compile(r'(?::key|v-bind:key)\s*=\s*["\'](?:index|idx|i)["\']'), 'Possible unstable list identity after sort/filter.'),
    ('storage-access', re.compile(r'\b(?:localStorage|sessionStorage)\s*\.'), 'Check whether this stores credentials or only harmless UI preferences.'),
    ('direct-fetch', re.compile(r'\bfetch\s*\('), 'Check existing API client and cancellation/error handling.'),
)


def inventory(root: Path) -> dict:
    root = root.expanduser().resolve()
    if not root.is_dir():
        raise ValueError(f'Project directory does not exist: {root}')
    package_path = root / 'package.json'
    manifest: dict = {}
    warnings: list[str] = []
    if package_path.exists():
        try:
            pkg = json.loads(package_path.read_text('utf-8'))
            if not isinstance(pkg, dict):
                raise ValueError('package.json is not an object')
            manifest = pkg
        except (OSError, UnicodeError, json.JSONDecodeError, ValueError) as exc:
            warnings.append(f'Unable to parse package.json: {type(exc).__name__}')
    else:
        warnings.append('No package.json at selected project root.')
    deps = dict(manifest.get('dependencies') or {})
    dev_deps = dict(manifest.get('devDependencies') or {})
    interesting = ['vue','@vue/compiler-sfc','vue-router','pinia','vite','typescript','vitest','@vue/test-utils','@playwright/test','keycloak-js','axios','@tanstack/vue-query']
    dependency_versions = {key:(deps.get(key) or dev_deps.get(key)) for key in interesting if (deps.get(key) or dev_deps.get(key))}
    # Package ranges are not proof of installed versions.
    lockfiles = [x for x in ('package-lock.json','pnpm-lock.yaml','yarn.lock','bun.lockb','bun.lock') if (root / x).exists()]
    config_files = [x for x in ('vite.config.ts','vite.config.js','tsconfig.json','vitest.config.ts','playwright.config.ts','.nvmrc','AGENTS.md','CONTRIBUTING.md') if (root / x).exists()]
    style_indicators = []
    hints: list[dict] = []
    file_count = 0
    scanned_count = 0
    stop_scan = False
    for directory, dirs, files in os.walk(root, topdown=True, followlinks=False):
        dirs[:] = [d for d in dirs if d not in EXCLUDED_DIRS and not (Path(directory) / d).is_symlink()]
        for file_name in files:
            path = Path(directory) / file_name
            if path.is_symlink() or path.suffix not in SCANNABLE:
                continue
            file_count += 1
            if file_count > LIMIT_FILES:
                warnings.append(f'Scan capped at {LIMIT_FILES} source files.')
                stop_scan = True
                break
            try:
                if path.stat().st_size > MAX_FILE_BYTES:
                    continue
                rel = path.relative_to(root).as_posix()
                if any(part in ('tokens','design-system','ui','components') for part in path.relative_to(root).parts):
                    if len(style_indicators) < 20:
                        style_indicators.append(rel)
                content = path.read_text('utf-8', errors='replace')
                scanned_count += 1
                for n, line in enumerate(content.splitlines(), 1):
                    for code, rx, explanation in RULES:
                        if rx.search(line) and len(hints) < MAX_HINTS:
                            hints.append({'file':rel,'line':n,'kind':code,'review':explanation})
            except OSError:
                warnings.append(f'Skipped unreadable source file: {path.name}')
        if stop_scan:
            break
    return {
        'project_root':str(root), 'warning':'Heuristic inventory only; never proof of a bug, security or visual verification.',
        'package_scripts': sorted((manifest.get('scripts') or {}).keys()),
        'manifest_dependency_ranges':dependency_versions,
        'lockfiles':lockfiles, 'config_files':config_files,
        'possible_design_system_files':style_indicators,
        'count_source_files_seen':file_count, 'count_source_files_scanned':scanned_count,
        'bounded_review_hints':hints,
        'warnings':warnings,
    }


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('project_root', type=Path)
    args = ap.parse_args()
    try:
        print(json.dumps(inventory(args.project_root), indent=2, ensure_ascii=False))
    except (OSError, ValueError) as exc:
        print(f'Audit aborted: {exc}', file=sys.stderr)
        return 2
    return 0


if __name__ == '__main__':
    sys.exit(main())
