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


def postgres_sql(sql):
    return run(['docker', 'exec', container('postgres'), 'sh', '-c',
                'PGPASSWORD="$POSTGRES_PASSWORD" psql -X -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atqc "$1"',
                'sh', sql])


def digest(path):
    value = hashlib.sha256()
    with path.open('rb') as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b''):
            value.update(chunk)
    return value.hexdigest()


def volume_path():
    if os.environ.get('MIGRATION_DATA_PATH'):
        volume = Path(os.environ['MIGRATION_DATA_PATH'])
        if not (volume / 'migration.sqlite').is_file():
            raise RuntimeError('Migration metadata volume is missing')
        return volume
    path = run(['docker', 'inspect', '--format',
                '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}', container('migration')])
    volume = Path(path)
    if not (volume / 'migration.sqlite').is_file():
        raise RuntimeError('Migration metadata volume is missing')
    return volume


def validate_snapshot(snapshot_id):
    snapshot = BASE / snapshot_id
    manifest = json.loads((snapshot / 'manifest.json').read_text())
    dump = snapshot / 'vacations.dump'
    metadata = snapshot / 'migration-data.tar.gz'
    if manifest.get('snapshot_id') != snapshot_id or manifest.get('service') != 'vacations':
        raise RuntimeError('Snapshot ID or service mismatch')
    if digest(dump) != manifest['vacations_dump_sha256'] or digest(metadata) != manifest['migration_data_sha256']:
        raise RuntimeError('Snapshot checksum mismatch')
    if (snapshot / 'restored').exists():
        raise RuntimeError('This snapshot has already been restored')
    if postgres_sql(EXTERNAL_FKS) != '0':
        raise RuntimeError('Another schema references vacations; schema-only restore is unsafe')
    with tarfile.open(metadata, 'r:gz') as tar:
        members = tar.getmembers()
        if any(member.name.startswith('/') or '..' in Path(member.name).parts
               or not (member.isfile() or member.isdir()) for member in members):
            raise RuntimeError('Unexpected path in metadata snapshot')
    return snapshot, manifest, dump, metadata, members
