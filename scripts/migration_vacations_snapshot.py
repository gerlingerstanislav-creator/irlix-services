#!/usr/bin/env python3
"""Private Vacations test-migration snapshot on the deployment host."""

import argparse
import datetime as dt
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
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


def run(args, *, stdout=None, input_file=None):
    result = subprocess.run(args, cwd=ROOT, stdin=input_file, stdout=stdout or subprocess.PIPE,
                            stderr=subprocess.PIPE, check=False)
    if result.returncode:
        raise RuntimeError(f'{args[0]} failed ({result.returncode}): {result.stderr.decode(errors="replace")[:1800]}')
    return result.stdout.decode().strip() if stdout is None else None


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


def migration_volume():
    if os.environ.get('MIGRATION_DATA_PATH'):
        volume = Path(os.environ['MIGRATION_DATA_PATH'])
        if not (volume / 'migration.sqlite').is_file():
            raise RuntimeError('Migration metadata volume is missing')
        return volume
    path = run(['docker', 'inspect', '--format',
                '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}', container('migration')])
    volume = Path(path)
    if not volume.is_dir() or not (volume / 'migration.sqlite').is_file():
        raise RuntimeError('Migration metadata volume is missing')
    return volume


def digest(path):
    value = hashlib.sha256()
    with path.open('rb') as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b''):
            value.update(chunk)
    return value.hexdigest()


def main(snapshot_id):
    if os.geteuid() != 0 or not snapshot_id.isdigit():
        raise RuntimeError('Run as root with a numeric snapshot ID')
    BASE.mkdir(mode=0o700, parents=True, exist_ok=True)
    with (BASE / '.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        destination = BASE / snapshot_id
        if destination.exists():
            raise RuntimeError('Snapshot already exists; it will not be replaced')
        if postgres_sql(EXTERNAL_FKS) != '0':
            raise RuntimeError('Another schema has a foreign key into vacations; schema-only restore is unsafe')
        volume = migration_volume()
        destination.mkdir(mode=0o700)
        stopped = False
        complete = False
        try:
            compose('stop', 'migration-worker', 'migration', 'vacations')
            stopped = True
            metadata = volume / 'migration.sqlite'
            with sqlite3.connect(f'file:{metadata}?mode=ro', uri=True) as db:
                active = db.execute("SELECT count(*) FROM migration_runs WHERE status IN ('queued','running')").fetchone()[0]
                last_run = db.execute('SELECT coalesce(max(id), 0) FROM migration_runs').fetchone()[0]
                if active:
                    raise RuntimeError('A migration run is active; snapshot refused')
                if db.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
                    raise RuntimeError('Migration metadata DB integrity check failed')
            dump = destination / 'vacations.dump'
            with dump.open('wb') as output:
                run(['docker', 'exec', container('postgres'), 'sh', '-c',
                     'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -n vacations -Fc'], stdout=output)
            with dump.open('rb') as source:
                run(['docker', 'exec', '-i', container('postgres'), 'pg_restore', '--list'], input_file=source)
            probe = 'migration_vacations_probe_' + snapshot_id
            run(['docker', 'exec', container('postgres'), 'sh', '-c',
                 'PGPASSWORD="$POSTGRES_PASSWORD" createdb -U "$POSTGRES_USER" "$1"', 'sh', probe])
            try:
                with dump.open('rb') as source:
                    run(['docker', 'exec', '-i', container('postgres'), 'sh', '-c',
                         'PGPASSWORD="$POSTGRES_PASSWORD" pg_restore -U "$POSTGRES_USER" -d "$1" '
                         '--clean --if-exists --exit-on-error --single-transaction', 'sh', probe], input_file=source)
            finally:
                run(['docker', 'exec', container('postgres'), 'sh', '-c',
                     'PGPASSWORD="$POSTGRES_PASSWORD" dropdb -U "$POSTGRES_USER" --if-exists "$1"', 'sh', probe])
            archive = destination / 'migration-data.tar.gz'
            with tarfile.open(archive, 'w:gz') as tar:
                for entry in volume.iterdir():
                    tar.add(entry, arcname=entry.name)
            manifest = {
                'snapshot_id': snapshot_id,
                'service': 'vacations',
                'created_at_utc': dt.datetime.now(dt.timezone.utc).isoformat(),
                'last_migration_run_id': last_run,
                'release_sha': os.environ.get('GITHUB_SHA', 'unknown'),
                'vacations_dump_sha256': digest(dump),
                'migration_data_sha256': digest(archive),
                'scope': 'vacations PostgreSQL schema + private migration metadata volume',
            }
            (destination / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
            os.chmod(destination / 'manifest.json', 0o600)
            complete = True
        finally:
            if stopped:
                compose('start', 'vacations')
                compose('start', 'migration')
                compose('start', 'migration-worker')
            if not complete:
                shutil.rmtree(destination, ignore_errors=True)
        run(['sh', 'scripts/verify-migration.sh'])
        print(f'Vacations snapshot ready: {snapshot_id}. No data exported to CI.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('snapshot_id')
    args = parser.parse_args()
    try:
        main(args.snapshot_id)
    except Exception as exc:
        print(f'Vacations migration snapshot failed: {exc}', file=sys.stderr)
        sys.exit(1)
