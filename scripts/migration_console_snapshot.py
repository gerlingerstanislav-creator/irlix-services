#!/usr/bin/env python3
"""Consistent console checkpoints; no archive leaves the deployment host."""
import argparse, datetime as dt, fcntl, json, os, shutil, sqlite3, sys, tarfile, tempfile, re, traceback, contextlib
from pathlib import Path
import migration_test_snapshot as common
import migration_diagnostics as diagnostics
import uuid
import math

SCOPES = {s: (s,) for s in ('employees', 'vacations', 'clients', 'timesheets')}
SCOPES['all'] = ('employees', 'vacations', 'clients', 'timesheets')
BASE = Path(os.environ.get('MIGRATION_SNAPSHOT_BASE', '/snapshots')) / 'console'
HISTORY = Path('/ops/console.json')
READ_ONLY_MODES = ('inspect', 'dry-run', 'validate')


def timestamp(value):
    if isinstance(value, (int, float)):
        return value
    parsed = dt.datetime.fromisoformat(value.replace('Z', '+00:00'))
    return (parsed if parsed.tzinfo else parsed.replace(tzinfo=dt.timezone.utc)).timestamp()


def service_state(db, service):
    """Stable import identity plus service-owned mapping/override contents.

    Run IDs alone are insufficient: restoring SQLite allows their reuse.
    Read-only reports, conflicts and progress do not represent imported data.
    """
    tables = {r[0] for r in db.execute("SELECT name FROM sqlite_master WHERE type='table'")}
    state = {}
    for table in ('migration_runs', 'migration_mappings', 'migration_overrides'):
        if table not in tables:
            state[table] = []
            continue
        columns = [r[1] for r in db.execute(f'PRAGMA table_info({table})')]
        if table == 'migration_runs':
            selected = [c for c in ('id', 'mode', 'started_at', 'created_at') if c in columns]
            condition = " AND (mode IS NULL OR mode NOT IN ('inspect','dry-run','validate'))" if 'mode' in columns else ''
        else:
            selected = [c for c in columns if c not in ('id', 'migration_run_id')]
            condition = ''
        rows = db.execute(f'SELECT {",".join(selected)} FROM {table} WHERE service=?{condition}', (service,)).fetchall()
        state[table] = sorted([list(r) for r in rows], key=lambda r: json.dumps(r, sort_keys=True))
    return state


def imported_after(history, service, since):
    # An `all` operation can start before a per-service checkpoint and import
    # dependants later. Its final update, not only its start, must be considered.
    return any(op.get('action') == 'migrate' and max(
        timestamp(op['started_at']), timestamp(op.get('updated_at', op['started_at']))) >= since
        and any(r.get('service') == service and r.get('mode') not in READ_ONLY_MODES
                for r in op.get('runs', [])) for op in history)


def assert_other_services_unchanged(db, scope, checkpoint):
    """Use confirmed restores outside SQLite, which itself is rewound on restore.

    Missing history/archives cannot prove a rollback. Never trust the `restored`
    marker: it is written before restart and can survive a later failed attempt.
    """
    if scope == 'all':
        return
    manifest = json.loads((checkpoint / 'manifest.json').read_text())
    cutoff = timestamp(manifest['created_at_utc'])
    try:
        history = json.loads(HISTORY.read_text()).get('history', [])
    except FileNotFoundError:
        history = []
    with sqlite3.connect(f'file:{checkpoint / "metadata.sqlite"}?mode=ro', uri=True) as baseline:
        for service in set(SCOPES['all']) - set(SCOPES[scope]):
            expected = service_state(baseline, service)
            restores = [op for op in history if op.get('action') == 'restore'
                        and op.get('scope') in (service, 'all')
                        and timestamp(op['started_at']) >= cutoff]
            latest = max(restores, key=lambda op: timestamp(op['started_at']), default=None)
            if latest is None:
                if service_state(db, service) != expected or imported_after(history, service, cutoff):
                    raise RuntimeError('Another service changed after checkpoint; restore refused')
                continue
            if latest.get('status') != 'completed' or not latest.get('finished_at'):
                raise RuntimeError('Another service restore is not confirmed; restore refused')
            sid = str(latest.get('snapshot_id', ''))
            restored_scope = latest['scope']
            if not sid.isascii() or not sid.isdecimal():
                raise RuntimeError('Another service restore proof is invalid')
            point = BASE / restored_scope / sid
            proof = json.loads((point / 'manifest.json').read_text())
            if (proof.get('snapshot_id') != sid or proof.get('service') != restored_scope
                    or tuple(proof.get('schemas', ())) != SCOPES[restored_scope]
                    or common.digest(point / 'metadata.sqlite') != proof.get('checksums', {}).get('metadata.sqlite')):
                raise RuntimeError('Another service restore proof checksum mismatch')
            with sqlite3.connect(f'file:{point / "metadata.sqlite"}?mode=ro', uri=True) as restored:
                if restored.execute('PRAGMA quick_check').fetchone()[0] != 'ok' or service_state(restored, service) != expected:
                    raise RuntimeError('Another service was restored to a different baseline; restore refused')
            # A failed import can leave partial data too. Compare wall-clock identities,
            # not IDs, and include imports retained only in the coordinator history.
            since = timestamp(latest['started_at'])
            db.row_factory = sqlite3.Row
            for row in db.execute('SELECT * FROM migration_runs WHERE service=?', (service,)):
                row = dict(row)
                if row.get('mode') in READ_ONLY_MODES:
                    continue
                if not row.get('created_at') or not row.get('started_at') or max(
                        timestamp(row['created_at']), timestamp(row['started_at'])) >= math.floor(since):
                    raise RuntimeError('Another service migrated after its restore; restore refused')
            if imported_after(history, service, since):
                raise RuntimeError('Another service migrated after its restore; restore refused')


def foreign_keys(scope):
    quoted = ','.join("'" + s + "'" for s in SCOPES[scope])
    return common.postgres_sql(f"""SELECT count(*) FROM pg_constraint c
    JOIN pg_class s ON s.oid=c.conrelid JOIN pg_namespace sn ON sn.oid=s.relnamespace
    JOIN pg_class d ON d.oid=c.confrelid JOIN pg_namespace dn ON dn.oid=d.relnamespace
    WHERE c.contype='f' AND dn.nspname IN ({quoted}) AND sn.nspname NOT IN ({quoted})""")


def writers(scope):
    return [s for s in ('employees', 'employees-events', 'vacations', 'vacations-calendar-sync', 'clients', 'timesheets')
            if ('employees' in SCOPES[scope] and s.startswith('employees')) or ('vacations' in SCOPES[scope] and s == 'vacations-calendar-sync') or s in SCOPES[scope]]


def assert_idle(volume, scope, last=None, checkpoint=None):
    with sqlite3.connect(f'file:{volume / "migration.sqlite"}?mode=ro', uri=True) as db:
        if db.execute("SELECT count(*) FROM migration_runs WHERE status IN ('queued','running')").fetchone()[0]:
            raise RuntimeError('A migration run is active')
        if db.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
            raise RuntimeError('Metadata integrity check failed')
        if checkpoint is not None:
            assert_other_services_unchanged(db, scope, checkpoint)
        elif last is not None:
            placeholders = ','.join('?' for _ in SCOPES[scope])
            if db.execute(f'SELECT count(*) FROM migration_runs WHERE id>? AND service NOT IN ({placeholders})',
                          (last, *SCOPES[scope])).fetchone()[0]:
                raise RuntimeError('Another service ran after checkpoint; restore refused')
        return db.execute('SELECT coalesce(max(id),0) FROM migration_runs').fetchone()[0]


def vacation_files():
    mounted = os.environ.get('MIGRATION_VACATIONS_FILES_PATH')
    if mounted:
        target = Path(mounted)
        if not target.is_dir():
            raise RuntimeError('Vacations document volume is missing')
        return target
    path = common.run(['docker', 'inspect', '--format', '{{range .Mounts}}{{if eq .Destination "/app/storage/app/vacations"}}{{.Source}}{{end}}{{end}}', common.container('vacations')])
    target = Path(path)
    if not path or not target.is_dir():
        raise RuntimeError('Vacations document volume is missing')
    return target


def restore_documents(archive, target):
    with tarfile.open(archive, 'r:gz') as tar:
        members = tar.getmembers()
        if any(m.name.startswith('/') or '..' in Path(m.name).parts or not (m.isfile() or m.isdir()) for m in members):
            raise RuntimeError('Unsafe document archive')
        staging = target / '.document-restore'
        shutil.rmtree(staging, ignore_errors=True)
        staging.mkdir(mode=0o700)
        try:
            tar.extractall(staging, members=members, filter='data')
            for path in target.iterdir():
                if path == staging:
                    continue
                if path.is_dir() and not path.is_symlink():
                    shutil.rmtree(path)
                else:
                    path.unlink()
            for path in staging.iterdir():
                os.replace(path, target / path.name)
        finally:
            shutil.rmtree(staging, ignore_errors=True)



def preflight_restore(scope, sid, archive):
    """Rehearse --clean against today's schema without stopping any writer."""
    cid = common.container('postgres')
    probe = 'migration_restore_probe_' + sid
    with tempfile.TemporaryFile() as current:
        common.run(['docker', 'exec', cid, 'sh', '-c',
            'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --schema-only -Fc "$@"',
            'sh', *[arg for schema in SCOPES[scope] for arg in ('-n', schema)]], stdout=current)
        common.run(['docker', 'exec', cid, 'sh', '-c',
            'PGPASSWORD="$POSTGRES_PASSWORD" createdb -U "$POSTGRES_USER" "$1"', 'sh', probe])
        try:
            current.seek(0)
            common.run(['docker', 'exec', '-i', cid, 'sh', '-c',
                'PGPASSWORD="$POSTGRES_PASSWORD" pg_restore -U "$POSTGRES_USER" -d "$1" --exit-on-error --single-transaction',
                'sh', probe], input_file=current)
            with archive.open('rb') as source:
                common.run(['docker', 'exec', '-i', cid, 'sh', '-c',
                    'PGPASSWORD="$POSTGRES_PASSWORD" pg_restore -U "$POSTGRES_USER" -d "$1" --clean --if-exists --exit-on-error --single-transaction',
                    'sh', probe], input_file=source)
        except Exception as exc:
            raise RuntimeError('RESTORE_PREFLIGHT_FAILED: checkpoint cannot restore over current schema; services were not stopped') from exc
        finally:
            common.run(['docker', 'exec', cid, 'sh', '-c',
                'PGPASSWORD="$POSTGRES_PASSWORD" dropdb -U "$POSTGRES_USER" --if-exists "$1"', 'sh', probe])


def deployed_runtime(service):
    cid = common.compose('ps', '-q', service)
    if not re.fullmatch(r'[0-9a-f]{12,64}', cid):
        raise RuntimeError('Deployed service container is missing or ambiguous')
    runtime = json.loads(common.run(['docker', 'inspect', '--format',
        '{{json .}}', cid]))
    image = runtime.get('Image', '')
    networks = list(runtime.get('NetworkSettings', {}).get('Networks', {}))
    config = runtime.get('Config', {})
    env = config.get('Env', [])
    if not re.fullmatch(r'sha256:[0-9a-f]{64}', image) or len(networks) != 1:
        raise RuntimeError('Unsupported deployed migration image/network')
    if not all(isinstance(v, str) and '=' in v and '\n' not in v and '\r' not in v for v in env):
        raise RuntimeError('Unsupported deployed environment format')
    return {'container': cid, 'image': image, 'network': networks[0], 'env': env,
            'workdir': config.get('WorkingDir') or '/app', 'user': config.get('User') or ''}


def migrate_restored_schema(service, runtime, *, check_only=False):
    # Clone the deployed environment/network/volumes, not a reconstructed base compose.
    # Credentials stay in a private local tempfile read by the Docker CLI, never logs.
    with tempfile.NamedTemporaryFile(mode='w', suffix='.env') as environment:
        environment.write('\n'.join(runtime['env']) + '\n')
        environment.flush()
        args = ['docker', 'run', '--rm', '--pull', 'never', '--network', runtime['network'],
                '--env-file', environment.name, '--volumes-from', runtime['container'],
                '--workdir', runtime['workdir'], '--entrypoint', 'php']
        if runtime['user']: args += ['--user', runtime['user']]
        args += [runtime['image'], 'artisan', 'migrate:status' if check_only else 'migrate']
        if not check_only: args.append('--force')
        common.run([*args, '--no-interaction'])


def restore_status(scope, sid, stage, **fields):
    diagnostics.record(stage, scope=scope, snapshot_id=sid, **fields)
    path = BASE / '.restore-status.json'
    try: previous = json.loads(path.read_text())
    except (OSError, ValueError): previous = {}
    if previous.get('scope') != scope or previous.get('snapshot_id') != sid or stage == 'validation': previous = {}
    value = {**previous, 'scope': scope, 'snapshot_id': sid, 'stage': stage,
             'updated_at': dt.datetime.now(dt.timezone.utc).isoformat(), **fields}
    tmp = path.with_suffix('.tmp')
    with tmp.open('w') as output:
        tmp.chmod(0o600)
        json.dump(value, output)
    os.replace(tmp, path)
    # Migration API reads only this safe status, not the private traceback.
    operational = Path('/ops')
    if operational.is_dir():
        mirror = operational / 'restore-status.tmp'
        with mirror.open('w') as output:
            mirror.chmod(0o644)
            json.dump(value, output)
        os.replace(mirror, operational / 'restore-status.json')



@contextlib.contextmanager
def metadata_guard():
    if not Path('/ops').is_dir():
        yield
        return
    with Path('/ops/migration-metadata.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        yield

def main(action, scope, sid):
    try:
        _main(action, scope, sid)
    except Exception as exc:
        if action == 'restore':
            diagnostic = diagnostics.failed(traceback.format_exc())
            path = BASE / '.restore-status.json'
            try: state = json.loads(path.read_text())
            except (OSError, ValueError): state = {}
            if not isinstance(exc, BlockingIOError) and state.get('scope') == scope and state.get('snapshot_id') == sid:
                # Keep the old operator path as well as immutable per-attempt logs.
                try:
                    with (BASE / '.restore-error.log').open('w') as log:
                        os.chmod(log.name, 0o600)
                        log.write(traceback.format_exc())
                except OSError: pass
                cause = diagnostics.classify(traceback.format_exc())
                restore_status(scope, sid, state['stage'], state='failed', error_type=type(exc).__name__,
                               **{k:v for k,v in (diagnostic or cause).items()
                                  if k in ('diagnostic_id','failed_stage','error_code','reason','log_saved')})
        raise

def _main(action, scope, sid):
    if os.geteuid() != 0 or scope not in SCOPES or not sid.isdecimal():
        raise RuntimeError('Root, allowlisted scope and numeric checkpoint ID required')
    root = BASE / scope
    root.mkdir(mode=0o700, parents=True, exist_ok=True)
    with (BASE / '.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        destination = root / sid
        if action == 'restore':
            restore_status(scope, sid, 'validation', state='running', database_committed=False, database_outcome='unmodified', metadata_complete=False, schemas_upgraded=False)
        volume = common.migration_volume()
        if foreign_keys(scope) != '0':
            raise RuntimeError('External foreign keys block schema checkpoint/restore')
        if action == 'snapshot':
            if destination.exists():
                raise RuntimeError('Checkpoint exists')
            assert_idle(volume, scope)
            destination.mkdir(mode=0o700)
            stopped = False
            try:
                common.compose('stop', 'migration-worker', 'migration', *writers(scope))
                stopped = True
                last = assert_idle(volume, scope)
                dump = destination / 'target.dump'
                with dump.open('wb') as output:
                    common.run(['docker', 'exec', common.container('postgres'), 'sh', '-c',
                        'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc "$@"',
                        'sh', *[arg for s in SCOPES[scope] for arg in ('-n', s)]], stdout=output)
                probe = 'migration_console_probe_' + sid
                common.run(['docker', 'exec', common.container('postgres'), 'sh', '-c',
                    'PGPASSWORD="$POSTGRES_PASSWORD" createdb -U "$POSTGRES_USER" "$1"', 'sh', probe])
                try:
                    with dump.open('rb') as source:
                        common.run(['docker', 'exec', '-i', common.container('postgres'), 'sh', '-c',
                            'PGPASSWORD="$POSTGRES_PASSWORD" pg_restore -U "$POSTGRES_USER" -d "$1" --exit-on-error --single-transaction',
                            'sh', probe], input_file=source)
                finally:
                    common.run(['docker', 'exec', common.container('postgres'), 'sh', '-c',
                        'PGPASSWORD="$POSTGRES_PASSWORD" dropdb -U "$POSTGRES_USER" --if-exists "$1"', 'sh', probe])
                with sqlite3.connect(f'file:{volume / "migration.sqlite"}?mode=ro', uri=True) as source, \
                     sqlite3.connect(destination / 'metadata.sqlite') as target:
                    source.backup(target)
                key = volume / 'migration-credential.key'
                if key.exists():
                    shutil.copyfile(key, destination / key.name)
                if 'vacations' in SCOPES[scope]:
                    with tarfile.open(destination / 'vacations-files.tar.gz', 'w:gz') as tar:
                        for entry in vacation_files().iterdir():
                            if entry.is_symlink():
                                raise RuntimeError('Document symlink cannot be checkpointed')
                            tar.add(entry, arcname=entry.name)
                files = [p.name for p in destination.iterdir() if p.is_file()]
                manifest = {'snapshot_id': sid, 'service': scope, 'schemas': SCOPES[scope],
                    'created_at_utc': dt.datetime.now(dt.timezone.utc).isoformat(),
                    'last_migration_run_id': last, 'checksums': {f: common.digest(destination / f) for f in files}}
                (destination / 'manifest.json').write_text(json.dumps(manifest) + '\n')
                for p in destination.iterdir():
                    p.chmod(0o600)
            except Exception:
                shutil.rmtree(destination, ignore_errors=True)
                raise
            finally:
                if stopped:
                    common.compose('start', *writers(scope), 'migration', 'migration-worker')
        else:
            restore_status(scope, sid, 'validation', state='running', database_committed=False, database_outcome='unmodified', metadata_complete=False, schemas_upgraded=False)
            with metadata_guard():
                manifest = json.loads((destination / 'manifest.json').read_text())
                if manifest.get('snapshot_id') != sid or manifest.get('service') != scope:
                    raise RuntimeError('Invalid checkpoint')
                checksums = manifest.get('checksums', {})
                if not {'target.dump', 'metadata.sqlite'}.issubset(checksums):
                    raise RuntimeError('Incomplete checkpoint')
                allowed = {'target.dump', 'metadata.sqlite', 'migration-credential.key', 'vacations-files.tar.gz'}
                if any(f not in allowed or common.digest(destination / f) != sha for f, sha in checksums.items()):
                    raise RuntimeError('Checkpoint checksum mismatch')
                if tuple(manifest.get('schemas', ())) != SCOPES[scope]:
                    raise RuntimeError('Checkpoint schema set differs from current scope; restore refused')
                assert_idle(volume, scope, manifest['last_migration_run_id'], destination)
                restore_status(scope, sid, 'preflight')
                preflight_restore(scope, sid, destination / 'target.dump')
                restore_status(scope, sid, 'runtime-check')
                runtimes = {service: deployed_runtime(service) for service in SCOPES[scope]}
                for service, runtime in runtimes.items():
                    migrate_restored_schema(service, runtime, check_only=True)
                restore_started = False
                try:
                    restore_status(scope, sid, 'stop')
                    common.compose('stop', 'migration-worker', *writers(scope))
                    assert_idle(volume, scope, manifest['last_migration_run_id'], destination)
                    cid = common.container('postgres')
                    with (destination / 'target.dump').open('rb') as source:
                        restore_started = True
                        restore_status(scope, sid, 'database', database_outcome='unknown')
                        common.run(['docker', 'exec', '-i', cid, 'sh', '-c',
                            'output=$(PGPASSWORD="$POSTGRES_PASSWORD" PGOPTIONS="-c lock_timeout=5000" pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --exit-on-error --single-transaction 2>&1); status=$?; '
                            'if [ "$status" -ne 0 ]; then printf "%s\\n" RESTORE_TRANSACTION_ABORTED >&2; fi; '
                            'printf "%s\\n" "$output" >&2; exit "$status"'], input_file=source)
                except Exception as exc:
                    # A transport failure can hide a successful COMMIT. Only a confirmed
                    # pg_restore failure guarantees rollback; otherwise require recovery.
                    if not restore_started or re.match(r'^docker failed \(\d+\): RESTORE_TRANSACTION_ABORTED\n', str(exc)):
                        restore_status(scope, sid, 'start', failed_stage='database' if restore_started else 'stop', database_outcome='rolled_back' if restore_started else 'unmodified')
                        common.compose('start', *writers(scope), 'migration', 'migration-worker')
                        raise RuntimeError('RESTORE_TARGET_UNCHANGED: services restarted; checkpoint was not applied') from exc
                    raise RuntimeError('RESTORE_RECOVERY_REQUIRED: database outcome unknown; writers remain stopped') from exc
                restore_status(scope, sid, 'metadata', database_committed=True, database_outcome='committed')
                for name in ('migration.sqlite-wal', 'migration.sqlite-shm'):
                    (volume / name).unlink(missing_ok=True)
                for src, dst in [('metadata.sqlite', 'migration.sqlite'), ('migration-credential.key', 'migration-credential.key')]:
                    if (destination / src).exists():
                        tmp = volume / (dst + '.restore')
                        shutil.copyfile(destination / src, tmp)
                        tmp.chmod(0o600)
                        os.replace(tmp, volume / dst)
                restore_status(scope, sid, 'documents', metadata_complete=True)
                if 'vacations-files.tar.gz' in checksums:
                    restore_documents(destination / 'vacations-files.tar.gz', vacation_files())
                assert_idle(volume, scope)
                restore_status(scope, sid, 'schema-upgrade')
                for service in SCOPES[scope]:
                    migrate_restored_schema(service, runtimes[service])
                restore_status(scope, sid, 'start', schemas_upgraded=True)
                (destination / 'restored').write_text('restored\n')
                common.compose('start', *writers(scope), 'migration', 'migration-worker')
                restore_status(scope, sid, 'completed', state='completed')


if __name__ == '__main__':
    os.environ.setdefault('MIGRATION_DIAGNOSTIC_ID', uuid.uuid4().hex)
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('action', choices=['snapshot', 'restore'])
    p.add_argument('scope', choices=SCOPES)
    p.add_argument('snapshot_id')
    a = p.parse_args()
    try:
        main(a.action, a.scope, a.snapshot_id)
    except Exception as exc:
        print(f'Console checkpoint operation failed: {exc}', file=sys.stderr)
        sys.exit(1)
