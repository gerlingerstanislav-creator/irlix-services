"""Host-side coordinator survives API/worker stops during checkpoints."""
import fcntl, json, os, secrets, shutil, sqlite3, subprocess, threading, time
from pathlib import Path
import migration_test_snapshot as common

STATE = Path('/ops/console.json')
SNAPSHOTS = Path(os.environ.get('MIGRATION_SNAPSHOT_BASE', '/snapshots')) / 'console'
SERVICES = ('employees', 'vacations', 'clients', 'timesheets')
TERMINAL = ('completed', 'conflicts', 'failed')


def state():
    try:
        return json.loads(STATE.read_text())
    except FileNotFoundError:
        return {'operation': None, 'history': []}


def save(value):
    tmp = STATE.with_suffix('.tmp')
    tmp.write_text(json.dumps(value, ensure_ascii=False))
    os.replace(tmp, STATE)


def active():
    return (state().get('operation') or {}).get('status') in ('queued', 'running')


def update(operation, **changes):
    operation.update(changes, updated_at=time.time())
    value = state()
    value['operation'] = operation.copy()
    value['history'] = [operation.copy(), *[h for h in value.get('history', []) if h['id'] != operation['id']]][:50]
    save(value)


def checkpoints(scope):
    result = []
    root = SNAPSHOTS / scope
    if not root.exists():
        return result
    for p in root.iterdir():
        if not p.name.isdecimal() or not (p / 'manifest.json').is_file():
            continue
        try:
            m = json.loads((p / 'manifest.json').read_text())
            if m.get('service') == scope:
                result.append({'id': p.name, 'scope': scope, 'created_at': m['created_at_utc'],
                    'restored': (p / 'restored').exists(), 'schemas': m['schemas'],
                    'compatible': tuple(m['schemas']) == (SERVICES if scope == 'all' else (scope,))})
        except (OSError, ValueError, KeyError):
            continue
    return sorted(result, key=lambda x: x['created_at'], reverse=True)[:50]


def delete_checkpoint(scope, sid):
    if scope not in (*SERVICES, 'all') or not sid.isascii() or not sid.isdecimal():
        raise ValueError('Некорректный контур или ID бэкапа')
    root = SNAPSHOTS / scope
    target = root / sid
    if root.is_symlink() or target.is_symlink() or not (target / 'manifest.json').is_file():
        raise FileNotFoundError('Бэкап не найден')
    manifest = json.loads((target / 'manifest.json').read_text())
    if manifest.get('service') != scope or manifest.get('snapshot_id') != sid:
        raise ValueError('Бэкап не соответствует выбранному сервису')
    # Share the checkpoint script lock and hold the queue write lock through removal.
    with (SNAPSHOTS / '.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with sqlite3.connect(Path(os.environ.get('MIGRATION_DATA_PATH', '/data')) / 'migration.sqlite', timeout=5) as db:
            db.execute('BEGIN IMMEDIATE')
            if active() or db.execute("SELECT count(*) FROM migration_runs WHERE status IN ('queued','running')").fetchone()[0]:
                raise RuntimeError('Дождитесь завершения активной операции')
            shutil.rmtree(target)
    return {'deleted': True, 'snapshot_id': sid, 'scope': scope}


def payload():
    return {**state(), 'snapshots': {s: checkpoints(s) for s in (*SERVICES, 'all')}}


def run_record(run_id):
    with sqlite3.connect(f'file:{os.environ.get("MIGRATION_DATA_PATH", "/data")}/migration.sqlite?mode=ro', uri=True) as db:
        db.row_factory = sqlite3.Row
        row = db.execute('SELECT id,service,mode,status,error FROM migration_runs WHERE id=?', (run_id,)).fetchone()
        return dict(row) if row else None


def enqueue(service, mode, operation, snapshot_id):
    # Pass only allowlisted values; no shell and no credentials in argv/output.
    result = subprocess.run(['docker', 'exec', common.container('migration'), 'php', 'artisan',
        'migration:console-enqueue', service, mode, str(operation['id']), str(snapshot_id)],
        cwd=common.ROOT, capture_output=True, text=True, timeout=90)
    if result.returncode:
        raise RuntimeError('Не удалось запустить этап. ' + result.stdout[-700:])
    line = result.stdout.strip().splitlines()[-1]
    run_id = int(json.loads(line)['run_id'])
    runs = [*operation.get('runs', []), {'id': run_id, 'service': service, 'mode': mode, 'status': 'queued'}]
    update(operation, runs=runs, current_run_id=run_id)
    deadline = time.monotonic() + 7200
    while time.monotonic() < deadline:
        row = run_record(run_id)
        if row and row['status'] in TERMINAL:
            runs[-1] = row
            update(operation, runs=runs)
            if row['status'] != 'completed':
                raise RuntimeError(f'{service}: {mode} — {row["status"]}. Продолжение очереди остановлено; см. журнал запуска #{run_id}.')
            return
        time.sleep(1)
    raise RuntimeError('Превышено время ожидания. Проверьте worker; повторный запуск автоматически не выполняется.')


def checkpoint(operation, scope):
    sid = str(int(time.time() * 1000)) + f'{secrets.randbelow(1000):03d}'
    update(operation, phase='snapshot', current_service=scope, message='Создаём и проверяем точку отката')
    result = subprocess.run(['python3', str(common.ROOT / 'scripts/migration_console_snapshot.py'), 'snapshot', scope, sid],
        cwd=common.ROOT, capture_output=True, text=True, timeout=3600)
    if result.returncode:
        raise RuntimeError('Точка отката не создана. Перенос не запущен; проверьте migration-ops.')
    update(operation, snapshot_ids={**operation.get('snapshot_ids', {}), scope: sid})
    return sid


def execute(operation):
    try:
        update(operation, status='running')
        if operation['action'] == 'restore':
            update(operation, phase='restore', message='Восстанавливаем выбранную точку отката')
            result = subprocess.run(['python3', str(common.ROOT / 'scripts/migration_console_snapshot.py'),
                'restore', operation['scope'], operation['snapshot_id']], cwd=common.ROOT,
                capture_output=True, text=True, timeout=3600)
            if result.returncode:
                raise RuntimeError('Откат не выполнен. Проверьте migration-ops; при ошибке восстановления сервисы остаются остановленными.')
        elif operation['action'] == 'snapshot':
            checkpoint(operation, operation['scope'])
        else:
            if operation['scope'] == 'all':
                checkpoint(operation, 'all')
            for service in operation['services']:
                sid = checkpoint(operation, service)
                for mode in ('inspect', 'dry-run', 'migrate', 'validate'):
                    update(operation, phase=mode, current_service=service, message=f'{service}: {mode}')
                    enqueue(service, mode, operation, sid)
        update(operation, status='completed', phase='finished', finished_at=time.time(),
            message={'restore':'Откат завершён', 'snapshot':'Точка отката создана', 'migrate':'Перенос и проверка завершены'}[operation['action']])
    except Exception as exc:
        update(operation, status='failed', finished_at=time.time(), message=str(exc))
        print(f'Console operation {operation["id"]} failed: {exc}', flush=True)


def start(body, action):
    scope = body.get('scope')
    if scope not in (*SERVICES, 'all'):
        raise ValueError('Неизвестный контур переноса')
    if action == 'restore':
        sid = str(body.get('snapshot_id', ''))
        if body.get('confirmation') != 'RESTORE ' + scope.upper():
            raise ValueError('Введите точное подтверждение RESTORE ' + scope.upper())
        if not any(s['id'] == sid and not s['restored'] and s['compatible'] for s in checkpoints(scope)):
            raise ValueError('Точка отката не найдена или уже восстановлена')
    elif action == 'migrate' and body.get('confirm') is not True:
        raise ValueError('Требуется подтверждение переноса')
    operation = {'id': str(int(time.time() * 1000)) + f'{secrets.randbelow(1000):03d}',
        'action': action, 'scope': scope, 'services': list(SERVICES) if scope == 'all' else [scope],
        'status': 'queued', 'phase': 'queued', 'started_at': time.time(), 'runs': [], 'snapshot_ids': {},
        'message': 'Ожидает запуска', 'snapshot_id': body.get('snapshot_id'),
        'requested_by': body.get('requested_by')}
    # Same SQLite write lock used by queueRun: no old-page run can race the reservation.
    with sqlite3.connect(Path(os.environ.get('MIGRATION_DATA_PATH', '/data')) / 'migration.sqlite', timeout=5) as db:
        db.execute('BEGIN IMMEDIATE')
        if db.execute("SELECT count(*) FROM migration_runs WHERE status IN ('queued','running')").fetchone()[0]:
            raise ValueError('Дождитесь завершения активного переноса')
        update(operation)
    return operation


def recover():
    operation = state().get('operation')
    if operation and operation.get('status') in ('queued', 'running'):
        update(operation, status='failed', message='Операция прервана перезапуском migration-ops. Проверьте журнал; автоматический повтор отключён.', finished_at=time.time())
