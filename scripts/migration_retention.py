#!/usr/bin/env python3
"""Per-scope retention for verified console checkpoints. Host-only, fail-closed."""
import argparse
import datetime as dt
import fcntl
import json
import os
import shutil
import sqlite3
from pathlib import Path

SNAPSHOTS = Path(os.environ.get('MIGRATION_SNAPSHOT_BASE', '/snapshots')) / 'console'
OPS = Path('/ops')
DATA = Path(os.environ.get('MIGRATION_DATA_PATH', '/data')) / 'migration.sqlite'
POLICY = OPS / 'snapshot-retention.json'
SCOPES = {s: (s,) for s in ('employees', 'vacations', 'clients', 'timesheets')}
SCOPES['all'] = ('employees', 'vacations', 'clients', 'timesheets')
DEFAULT_COUNT, MIN_COUNT, MAX_COUNT = 2, 1, 20
ALLOWED_FILES = {'target.dump', 'metadata.sqlite', 'migration-credential.key',
                 'vacations-files.tar.gz', 'manifest.json', 'restored', '.keep'}


def validate_count(value):
    if type(value) is not int or not MIN_COUNT <= value <= MAX_COUNT:
        raise ValueError('Количество точек отката должно быть целым числом от 1 до 20.')
    return value


def get_count():
    try:
        policy = json.loads(POLICY.read_text())
    except FileNotFoundError:
        return DEFAULT_COUNT
    except (OSError, ValueError) as exc:
        raise RuntimeError('Настройка хранения повреждена; автоматическое удаление запрещено.') from exc
    if not isinstance(policy, dict):
        raise RuntimeError('Неверный формат политики хранения.')
    try:
        return validate_count(policy.get('count'))
    except ValueError as exc:
        raise RuntimeError('Неверное значение политики хранения; очистка запрещена.') from exc


def public_policy():
    return {'count': get_count(), 'min': MIN_COUNT, 'max': MAX_COUNT}


def read_json(path):
    try:
        value = json.loads(path.read_text())
        if not isinstance(value, dict):
            raise RuntimeError('Некорректное состояние сервиса переноса.')
        return value
    except (OSError, ValueError) as exc:
        raise RuntimeError('Невозможно подтвердить отсутствие активных операций.') from exc


def require_idle(db):
    owner = read_json(OPS / 'console.json').get('operation')
    if owner is not None and not isinstance(owner, dict):
        raise RuntimeError('Некорректное состояние координатора.')
    status = read_json(OPS / 'status.json')
    restore_marker = SNAPSHOTS / '.restore-status.json'
    if restore_marker.exists():
        restore = read_json(restore_marker)
        if restore.get('state') in ('queued', 'running'):
            raise RuntimeError('Не завершена операция восстановления; очистка запрещена.')
    if (owner and owner.get('status') in ('queued', 'running')) or status.get('state') in ('queued', 'running'):
        raise RuntimeError('Активна миграция, создание точки или откат.')
    if db.execute("SELECT COUNT(*) FROM migration_runs WHERE status IN ('queued','running')").fetchone()[0]:
        raise RuntimeError('Активен worker миграции.')


def verified_point(scope, folder, now):
    if not folder.name.isascii() or not folder.name.isdecimal() or folder.is_symlink() or not folder.is_dir():
        return None
    manifest = folder / 'manifest.json'
    if manifest.is_symlink() or not manifest.is_file():
        return None
    try:
        info = read_json(manifest)
        created = dt.datetime.fromisoformat(str(info['created_at_utc']).replace('Z', '+00:00'))
        if (created.tzinfo is None or created > now or info.get('service') != scope
                or str(info.get('snapshot_id')) != folder.name
                or tuple(info.get('schemas', ())) != SCOPES[scope]
                or info.get('verification_version') != 1):
            return None
        checksums = info.get('checksums')
        if (not isinstance(checksums, dict)
                or any(name not in checksums or not (folder / name).is_file()
                       or (folder / name).is_symlink() for name in ('target.dump', 'metadata.sqlite'))):
            return None
        for file in folder.iterdir():
            if (file.is_symlink() or not file.is_file() or file.name not in ALLOWED_FILES
                    or file.stat().st_nlink != 1):
                return None
        return created
    except (OSError, KeyError, TypeError, ValueError, RuntimeError):
        return None


def cleanup(count=None):
    """Prune older verified points in each scope. Unverified/held points are never removed."""
    if count is not None:
        validate_count(count)
    if not SNAPSHOTS.is_dir() or SNAPSHOTS.is_symlink():
        raise RuntimeError('Каталог снимков недоступен или некорректен.')
    with (SNAPSHOTS / '.lock').open('a') as mutex:
        fcntl.flock(mutex, fcntl.LOCK_EX | fcntl.LOCK_NB)
        # Same migration_runs write lock as enqueue; never delete during any worker stage.
        with sqlite3.connect(f'file:{DATA}?mode=rw', uri=True, timeout=3) as db:
            db.execute('BEGIN IMMEDIATE')
            require_idle(db)
            effective = get_count() if count is None else count
            # Do not persist a limit before the idle checks and filesystem validation.
            result = {'count': effective, 'removed': 0, 'removed_by_scope': {},
                      'retained_by_scope': {}, 'skipped_protected': 0, 'skipped_unverified': 0}
            now = dt.datetime.now(dt.timezone.utc)
            candidates = {}
            for scope in SCOPES:
                parent = SNAPSHOTS / scope
                if parent.is_symlink():
                    raise RuntimeError('Некорректный каталог сервиса; очистка запрещена.')
                if not parent.exists():
                    candidates[scope] = []
                    result['retained_by_scope'][scope] = 0
                    continue
                if not parent.is_dir():
                    raise RuntimeError('Каталог сервиса недоступен; очистка запрещена.')
                verified = []
                for folder in parent.iterdir():
                    if not folder.is_dir() and not folder.is_symlink():
                        continue
                    created = verified_point(scope, folder, now)
                    if created is None:
                        result['skipped_unverified'] += 1
                    else:
                        verified.append((created, folder.name, folder))
                verified.sort(reverse=True)
                candidates[scope] = verified[effective:]
                result['retained_by_scope'][scope] = min(len(verified), effective)
            if count is not None:
                OPS.mkdir(parents=True, exist_ok=True)
                temporary = OPS / ('snapshot-retention-' + str(os.getpid()) + '.tmp')
                fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
                try:
                    with os.fdopen(fd, 'w') as output:
                        json.dump({'version': 1, 'count': effective}, output)
                        output.flush()
                        os.fsync(output.fileno())
                    os.replace(temporary, POLICY)
                finally:
                    temporary.unlink(missing_ok=True)
            for scope, older in candidates.items():
                removed = 0
                for _, _, folder in older:
                    # .keep explicitly protects a manually held recovery point.
                    if (folder / '.keep').exists():
                        result['skipped_protected'] += 1
                        continue
                    # Directory identity and contents cannot be substituted via symlinks.
                    if folder.parent != SNAPSHOTS / scope or verified_point(scope, folder, now) is None:
                        result['skipped_unverified'] += 1
                        continue
                    shutil.rmtree(folder)
                    removed += 1
                result['removed_by_scope'][scope] = removed
                result['removed'] += removed
            return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=('prune',))
    args = parser.parse_args()
    try:
        print(json.dumps(cleanup(), sort_keys=True))
    except (RuntimeError, BlockingIOError, OSError, sqlite3.Error) as exc:
        print('Retention safely skipped: ' + type(exc).__name__ + ': ' + str(exc))
        raise SystemExit(2)


if __name__ == '__main__':
    main()
