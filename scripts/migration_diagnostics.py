"""Per-attempt private logs and allowlisted public migration diagnostics."""
import json
import os
import re
import time
from pathlib import Path

PUBLIC_FIELDS = ('diagnostic_id', 'scope', 'snapshot_id', 'stage', 'failed_stage',
                 'state', 'database_outcome', 'database_committed', 'metadata_complete',
                 'schemas_upgraded', 'error_code', 'reason', 'updated_at', 'log_saved')
CAUSES = (
    ('timed out', 'OPERATION_TIMEOUT', 'Превышено время ожидания операции; требуется проверка её исхода.'),
    ('resource temporarily unavailable', 'OPERATION_LOCKED', 'Операция со снимком уже заблокирована другим процессом.'),
    ('another service', 'OTHER_SERVICE_CHANGED', 'После точки отката запускался перенос другого сервиса.'),
    ('external foreign key', 'EXTERNAL_DEPENDENCY', 'Другой сервис ссылается на восстанавливаемую схему.'),
    ('another schema references', 'EXTERNAL_DEPENDENCY', 'Другой сервис ссылается на восстанавливаемую схему.'),
    ('cannot drop', 'SCHEMA_DEPENDENCY', 'Удалению объектов старой схемы мешают существующие зависимости.'),
    ('already exists', 'OBJECT_EXISTS', 'Объект из снимка уже существует в текущей схеме.'),
    ('checksum', 'CHECKSUM_MISMATCH', 'Контрольная сумма файла снимка не совпадает.'),
    ('snapshot validation failed', 'CHECKSUM_MISMATCH', 'Проверка состава или контрольных сумм снимка не прошла.'),
    ('schema set differs', 'SCHEMA_SET_MISMATCH', 'Состав схем снимка отличается от выбранного контура.'),
    ('invalid checkpoint', 'INVALID_CHECKPOINT', 'Снимок не соответствует выбранному сервису или ID.'),
    ('incomplete checkpoint', 'INCOMPLETE_CHECKPOINT', 'В снимке отсутствуют обязательные файлы.'),
    ('active migration', 'ACTIVE_MIGRATION', 'Есть активный перенос; откат заблокирован.'),
    ('migration run is active', 'ACTIVE_MIGRATION', 'Есть активный перенос; откат заблокирован.'),
    ('integrity check failed', 'METADATA_INTEGRITY', 'Проверка целостности журнала миграции не прошла.'),
    ('unsafe', 'UNSAFE_ARCHIVE', 'Архив содержит недопустимые пути или типы файлов.'),
    ('permission denied', 'PERMISSION_DENIED', 'Недостаточно прав на выполнение операции.'),
    ('connection refused', 'CONNECTION_REFUSED', 'Соединение с целевым процессом отклонено.'),
    ('password authentication failed', 'DATABASE_AUTH_FAILED', 'База данных отклонила авторизацию.'),
    ('no such image', 'IMAGE_MISSING', 'Локальный образ сервиса не найден.'),
    ('container is missing', 'CONTAINER_MISSING', 'Контейнер сервиса не найден или неоднозначен.'),
    ('image/network', 'RUNTIME_UNSUPPORTED', 'Окружение или сеть действующего контейнера не поддерживается.'),
    ('environment format', 'RUNTIME_UNSUPPORTED', 'Формат окружения действующего контейнера не поддерживается.'),
    ('volume is missing', 'VOLUME_MISSING', 'Обязательный том данных не найден.'),
    ('no such file', 'FILE_MISSING', 'Обязательный файл не найден.'),
    ('read-only file system', 'READ_ONLY_FILESYSTEM', 'Файловая система недоступна для записи.'),
    ('unknown flag', 'CLI_OPTION_UNSUPPORTED', 'Версия утилиты не поддерживает используемый параметр.'),
    ('lock timeout', 'DATABASE_LOCK_TIMEOUT', 'Превышено время ожидания блокировки базы данных.'),
    ('does not exist', 'SCHEMA_OBJECT_MISSING', 'В базе отсутствует объект, необходимый для восстановления.'),
)


def classify(detail):
    # Never return SQL, connection strings, row values or raw subprocess output.
    for needle, code, reason in CAUSES:
        if needle in detail.lower():
            return {'error_code': code, 'reason': reason}
    sqlstate = re.search(r'SQLSTATE\[([A-Z0-9]{5})\]', detail)
    if sqlstate:
        return {'error_code': 'SQLSTATE_' + sqlstate[1], 'reason': 'База данных отклонила операцию. Подробности сохранены в серверном логе.'}
    return {'error_code': 'UNCLASSIFIED', 'reason': 'Причина требует проверки полного серверного лога.'}


def directory():
    return Path(os.environ.get('MIGRATION_SNAPSHOT_BASE', '/snapshots')) / 'diagnostics'


def current_id():
    value = os.environ.get('MIGRATION_DIAGNOSTIC_ID', '')
    return value if re.fullmatch(r'[a-f0-9]{32}', value) else None


def read(diagnostic_id=None):
    key = diagnostic_id or current_id()
    if not key or not re.fullmatch(r'[a-f0-9]{32}', key):
        return {}
    try:
        value = json.loads((directory() / (key + '.json')).read_text())
        return {field: value[field] for field in PUBLIC_FIELDS if field in value}
    except (OSError, ValueError):
        return {}


def record(stage, *, attempt_id=None, **fields):
    key = attempt_id or current_id()
    if not key or not re.fullmatch(r'[a-f0-9]{32}', key):
        return {}
    value = {**read(key), **fields, 'diagnostic_id': key, 'stage': stage, 'updated_at': time.time()}
    value = {field: value[field] for field in PUBLIC_FIELDS if field in value}
    try:
        root = directory(); root.mkdir(mode=0o700, parents=True, exist_ok=True)
        temp = root / (key + '.tmp')
        fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, 'w') as output:
            os.fchmod(output.fileno(), 0o600)
            json.dump(value, output, ensure_ascii=False)
        os.replace(temp, root / (key + '.json'))
    except OSError:
        # Diagnostics must never change transaction outcome or recovery policy.
        return {}
    return value


def private_log_for(key, detail):
    if not key or not re.fullmatch(r'[a-f0-9]{32}', key):
        return False
    try:
        root = directory(); root.mkdir(mode=0o700, parents=True, exist_ok=True)
        fd = os.open(root / (key + '.log'), os.O_WRONLY | os.O_CREAT | os.O_APPEND, 0o600)
        with os.fdopen(fd, 'w') as output:
            os.fchmod(output.fileno(), 0o600)
            output.write(detail + '\n')
        return True
    except OSError:
        return False


def private_log(detail):
    return private_log_for(current_id(), detail)


def command_failure(result):
    def decode(value):
        return value.decode(errors='replace') if isinstance(value, bytes) else value or ''
    detail = 'Exit code: ' + str(result.returncode) + '\nstdout:\n' + decode(result.stdout) + '\nstderr:\n' + decode(result.stderr)
    saved = private_log(detail)
    state = read()
    if state:
        record(state['stage'], log_saved=saved, **classify(detail))


def failed(detail, diagnostic_id=None):
    key = diagnostic_id or current_id()
    state = read(key)
    saved = private_log_for(key, detail)
    cause = classify(detail)
    if cause['error_code'] == 'UNCLASSIFIED' and state.get('error_code'):
        cause = {field: state[field] for field in ('error_code', 'reason')}
    return record(state.get('stage', 'execution'), attempt_id=key, state='failed',
                  failed_stage=state.get('failed_stage', state.get('stage', 'execution')),
                  log_saved=saved or state.get('log_saved', False), **cause)
