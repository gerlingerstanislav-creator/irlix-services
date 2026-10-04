#!/usr/bin/env python3
"""Restore a Vacations migration snapshot on the deployment host."""

import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import tarfile

ROOT = Path('/opt/irlix-services')
BASE = Path(os.environ.get('MIGRATION_SNAPSHOT_BASE', str(ROOT / '.ci' / 'migration-test-snapshots'))) / 'vacations'
EXTERNAL_FKS = """
SELECT count(*) FROM pg_constraint c
JOIN pg_class src ON src.oid = c.conrelid
JOIN pg_namespace src_ns ON src_ns.oid = src.relnamespace
JOIN pg_class dst ON dst.oid = c.confrelid
JOIN pg_namespace dst_ns ON dst_ns.oid = dst.relnamespace
WHERE c.contype = 'f' AND dst_ns.nspname = 'vacations'
  AND src_ns.nspname <> 'vacations'
"""


def run(args, *, input_file=None):
    result = subprocess.run(args, cwd=ROOT, stdin=input_file, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, check=False)
    if result.returncode:
        raise RuntimeError(f'{args[0]} failed ({result.returncode}): {result.stderr.decode(errors="replace")[-1200:]}')
    return result.stdout.decode().strip()


def compose(*args):
    binary = ['docker', 'compose'] if subprocess.run(['docker', 'compose', 'version'],
                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0 else ['docker-compose']
    return run([*binary, '-f', 'docker-compose.yml', '-f', 'docker-compose.migration.yml', '--env-file', '.env', *args])


def container(name):
    cid = compose('ps', '-q', name)
    if not cid:
        raise RuntimeError(f'{name} container is missing')
    return cid


def digest(path):
    value = hashlib.sha256()
    with path.open('rb') as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b''):
            value.update(chunk)
    return value.hexdigest()
