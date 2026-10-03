#!/usr/bin/env python3
"""Restore one Employees test-migration snapshot after an explicit operator decision."""

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
BASE = ROOT / '.ci' / 'migration-test-snapshots'
EXTERNAL_FKS = """
SELECT count(*) FROM pg_constraint c
JOIN pg_class src ON src.oid = c.conrelid
JOIN pg_namespace src_ns ON src_ns.oid = src.relnamespace
JOIN pg_class dst ON dst.oid = c.confrelid
JOIN pg_namespace dst_ns ON dst_ns.oid = dst.relnamespace
WHERE c.contype = 'f' AND dst_ns.nspname = 'employees'
  AND src_ns.nspname <> 'employees'
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
    path = run(['docker', 'inspect', '--format',
                '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}', container('migration')])
    volume = Path(path)
    if not (volume / 'migration.sqlite').is_file():
        raise RuntimeError('Migration metadata volume is missing')
    return volume


def main(snapshot_id):
    if os.geteuid() != 0 or not snapshot_id.isdigit():
        raise RuntimeError('Run as root with a numeric snapshot ID')
    with (BASE / '.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        snapshot = BASE / snapshot_id
        manifest = json.loads((snapshot / 'manifest.json').read_text())
        dump, metadata = snapshot / 'employees.dump', snapshot / 'migration-data.tar.gz'
        if manifest['snapshot_id'] != snapshot_id or digest(dump) != manifest['employees_dump_sha256'] \
                or digest(metadata) != manifest['migration_data_sha256']:
            raise RuntimeError('Snapshot ID or checksum mismatch')
        if (snapshot / 'restored').exists():
            raise RuntimeError('This snapshot has already been restored')
        if postgres_sql(EXTERNAL_FKS) != '0':
            raise RuntimeError('Another schema references employees; schema-only restore is unsafe')
        with tarfile.open(metadata, 'r:gz') as tar:
            members = tar.getmembers()
            if any(member.name.startswith('/') or '..' in Path(member.name).parts
                   or not (member.isfile() or member.isdir())
                   for member in members):
                raise RuntimeError('Unexpected path in metadata snapshot')
        volume = volume_path()
        with sqlite3.connect(f'file:{volume / "migration.sqlite"}?mode=ro', uri=True) as db:
            last = manifest['last_migration_run_id']
            active = db.execute("SELECT count(*) FROM migration_runs WHERE status IN ('queued','running')").fetchone()[0]
            other = db.execute('SELECT count(*) FROM migration_runs WHERE id > ? AND service <> ?',
                               (last, 'employees')).fetchone()[0]
            if active or other:
                raise RuntimeError('An active or another-service migration run exists after the snapshot')
        # Stop the only service allowed to write employees schema, plus both migration processes.
        compose('stop', 'migration-worker', 'migration', 'employees')
        try:
            with dump.open('rb') as source:
                run(['docker', 'exec', '-i', container('postgres'), 'sh', '-c',
                     'PGPASSWORD="$POSTGRES_PASSWORD" PGOPTIONS="-c lock_timeout=5000" '
                     'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" '
                     '--clean --if-exists --exit-on-error --single-transaction -n employees'], input_file=source)
            for name in ('migration.sqlite', 'migration.sqlite-wal', 'migration.sqlite-shm',
                         'migration-credential.key'):
                (volume / name).unlink(missing_ok=True)
            with tarfile.open(metadata, 'r:gz') as tar:
                tar.extractall(volume, members=members)
            with sqlite3.connect(f'file:{volume / "migration.sqlite"}?mode=ro', uri=True) as db:
                if db.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
                    raise RuntimeError('Restored metadata DB integrity check failed')
            (snapshot / 'restored').write_text('restored\n')
        except Exception:
            print('Restore interrupted; services remain stopped. Snapshot is intact for recovery.', file=sys.stderr)
            raise
        compose('start', 'employees')
        compose('start', 'migration')
        compose('start', 'migration-worker')
        run(['sh', 'scripts/verify-migration.sh'])
        print(f'Employees schema and migration metadata restored from snapshot {snapshot_id}.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('snapshot_id')
    args = parser.parse_args()
    try:
        main(args.snapshot_id)
    except Exception as exc:
        print(f'Migration test rollback failed: {exc}', file=sys.stderr)
        sys.exit(1)
