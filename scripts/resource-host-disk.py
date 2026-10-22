#!/usr/bin/env python3
"""Read-only, bounded host disk inventory. Fixed categories only, no file names exposed."""
import json
import os
from pathlib import Path
import stat
import subprocess
import time

BASE = Path('/opt/irlix-services')
DOCKER = Path('/var/lib/docker')
# Mutually exclusive roots. Entries inside excluded subdirectories are sampled separately.
SECTIONS = (
    ('migration-employees', 'migration', BASE/'.ci/migration-test-snapshots/console/employees', ()),
    ('migration-vacations', 'migration', BASE/'.ci/migration-test-snapshots/console/vacations', ()),
    ('migration-clients', 'migration', BASE/'.ci/migration-test-snapshots/console/clients', ()),
    ('migration-timesheets', 'migration', BASE/'.ci/migration-test-snapshots/console/timesheets', ()),
    ('migration-all', 'migration', BASE/'.ci/migration-test-snapshots/console/all', ()),
    ('migration-console-other', 'migration', BASE/'.ci/migration-test-snapshots/console',
     ('employees', 'vacations', 'clients', 'timesheets', 'all')),
    ('migration-legacy', 'migration', BASE/'.ci/migration-test-snapshots', ('console',)),
    ('ci-other', None, BASE/'.ci', ('migration-test-snapshots',)),
    ('deployment', None, BASE, ('.ci',)),
    ('docker-container-files', None, DOCKER/'containers', ()),
    ('docker-buildkit', None, DOCKER/'buildkit', ()),
    ('system-logs', None, Path('/var/log'), ()),
    ('system-cache', None, Path('/var/cache'), ()),
    ('system-tmp', None, Path('/tmp'), ()),
)
MAX_ENTRIES = 300000
MAX_SECONDS = 90


class Budget:
    def __init__(self, max_entries=MAX_ENTRIES, max_seconds=MAX_SECONDS):
        self.remaining = max_entries
        self.deadline = time.monotonic() + max_seconds
        self.seen = set()

    def check(self):
        if self.remaining <= 0 or time.monotonic() >= self.deadline:
            raise TimeoutError('Disk scan budget exhausted')
        self.remaining -= 1


def measure(root, device, budget, excluded=()):
    """Allocated bytes on root FS; skip symlinks, other devices and hardlink duplicates.

    Missing directories are not reported as zero. On timeout/I/O errors, measured
    bytes are a lower bound and partial=True. Exclusions apply to root's children.
    """
    try:
        top = root.lstat()
    except FileNotFoundError:
        return None
    if not stat.S_ISDIR(top.st_mode) or top.st_dev != device:
        return None
    size, partial = 0, False
    stack = [(root, True)]
    while stack:
        path, is_root = stack.pop()
        try:
            budget.check()
            meta = path.lstat()
            if meta.st_dev != device or stat.S_ISLNK(meta.st_mode):
                continue
            identity = (meta.st_dev, meta.st_ino)
            if identity in budget.seen:
                continue
            budget.seen.add(identity)
            if not (stat.S_ISDIR(meta.st_mode) or stat.S_ISREG(meta.st_mode)):
                continue
            size += meta.st_blocks * 512
            if stat.S_ISDIR(meta.st_mode):
                with os.scandir(path) as children:
                    for child in children:
                        if is_root and child.name in excluded:
                            continue
                        stack.append((Path(child.path), False))
        except (OSError, TimeoutError):
            partial = True
            if budget.remaining <= 0 or time.monotonic() >= budget.deadline:
                break
    return {'bytes': size, 'partial': partial}


def inventory(sections=SECTIONS, filesystem=Path('/'), budget=None):
    disk = os.statvfs(filesystem)
    root_device = filesystem.stat().st_dev
    budget = budget or Budget()
    output, partial = [], False
    for section_id, owner, path, exclude in sections:
        value = measure(path, root_device, budget, exclude)
        if value is None:
            continue
        output.append({'id': section_id, 'service': owner, **value})
        partial |= value['partial']
    return {'collected_at': int(time.time()), 'disk_total': disk.f_blocks * disk.f_frsize,
            'sections': output, 'partial': partial}


def main():
    # Same existing volume as the read-only resource monitor collector.
    mount = subprocess.check_output(
        ['docker', 'volume', 'inspect', '--format', '{{.Mountpoint}}',
         'irlix-services_resource_monitor_data'], text=True, timeout=8).strip()
    directory = Path(mount)
    if not directory.is_absolute() or directory.is_symlink() or not directory.is_dir():
        raise ValueError('Invalid resource monitor volume')
    result = inventory()
    temporary = directory/'host-disk.tmp'
    with temporary.open('w') as target:
        json.dump(result, target, separators=(',', ':'))
        target.flush()
        os.fsync(target.fileno())
    os.chmod(temporary, 0o644)
    temporary.replace(directory/'host-disk.json')


if __name__ == '__main__':
    main()
