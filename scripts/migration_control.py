"""Opt-in partial migration coordinator and durable host-side audit archive.

SQLite is restored with checkpoints; this archive deliberately is not. A completed
run is exported before any subsequent stage, using an operation-scoped identity.
"""
import copy
import json
import os
import re
import sqlite3
import subprocess
import threading
import time
import uuid
from pathlib import Path

import migration_console as console

ARCHIVE = Path('/ops/control-reports')
MUTEX = threading.RLock()
MODES = ('inspect', 'dry-run', 'migrate', 'validate')
TERMINAL = (*console.TERMINAL, 'partial', 'cancelled')


class AuditError(RuntimeError):
    """Missing/ambiguous durable evidence forbids all further stages."""


def atomic(path, value, immutable=False):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    temporary = path.with_name('.' + path.name + '.' + uuid.uuid4().hex)
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(fd, 'w') as output:
            json.dump(value, output, ensure_ascii=False)
            output.flush()
            os.fsync(output.fileno())
        if immutable:
            os.link(temporary, path)  # fail rather than overwrite a previous proof
            temporary.unlink()
        else:
            os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        temporary.unlink(missing_ok=True)


def directory(operation):
    key = str(operation['id'])
    if not re.fullmatch(r'[0-9]+', key):
        raise ValueError('Некорректный ID операции')
    return ARCHIVE / key


def read(path, default=None):
    try:
        return json.loads(path.read_text())
    except FileNotFoundError:
        return default


def history():
    if not ARCHIVE.exists():
        return []
    result = [read(p / 'operation.json') for p in ARCHIVE.iterdir() if p.is_dir() and p.name.isdecimal()]
    return sorted((x for x in result if x), key=lambda x: x['started_at'], reverse=True)


def update(operation, **changes):
    with MUTEX:
        # A cancellation may arrive while a subprocess is completing. Merge the
        # durable request rather than overwriting it with a stale worker copy.
        previous = read(directory(operation) / 'operation.json', {})
        if previous.get('cancel_requested'):
            operation.update(cancel_requested=True, cancel_reason=previous.get('cancel_reason', ''))
        operation.update(changes, updated_at=time.time())
        snapshot = copy.deepcopy(operation)
        root = directory(operation)
        atomic(root / 'events' / (str(time.time_ns()) + '-' + uuid.uuid4().hex + '.json'), snapshot, immutable=True)
        atomic(root / 'operation.json', snapshot)
        console.update(operation)


def start(body):
    with MUTEX:
        if console.active():
            raise ValueError('Дождитесь завершения активной операции')
        # console.start acquires the same SQLite write lock as the old queue and
        # publishes the owner reservation before that lock is released.
        operation = console.start(body, 'migrate', control_mode=True)
        operation.update(control_mode=True, archive_version=1, archive_id=uuid.uuid4().hex,
                         service_results=[], cancel_requested=False, data_outcome='not_started',
                         report_available=False)
        try:
            if directory(operation).exists():
                raise AuditError('ID операции уже существует; повторите запрос')
            update(operation)
        except Exception as exc:
            # Reservation happened under the shared queue lock, but no worker
            # has been launched. Release it even when the audit disk is full.
            console.update(operation, status='failed', phase='finished', finished_at=time.time(),
                           data_outcome='not_started', report_available=False,
                           message='Архив операции недоступен; перенос не запускался')
            raise AuditError('Архив операции недоступен; перенос не запускался') from exc
        return operation


def cancel(body):
    with MUTEX:
        operation = console.state().get('operation') or {}
        if not operation.get('control_mode') or str(body.get('operation_id')) != str(operation.get('id')):
            raise ValueError('Активная операция управления не найдена')
        if operation.get('status') not in ('queued', 'running'):
            raise ValueError('Операция уже завершена')
        reason = body.get('reason')
        if not isinstance(reason, str) or not reason.strip() or len(reason.strip()) > 500:
            raise ValueError('Укажите причину остановки (до 500 символов)')
        reason = reason.strip()
        update(operation, cancel_requested=True, cancel_reason=reason,
               message='Остановка запрошена; текущий этап завершится безопасно')
        return operation


def cancelled(operation):
    latest = read(directory(operation) / 'operation.json', {})
    if latest.get('cancel_requested'):
        operation.update(cancel_requested=True, cancel_reason=latest.get('cancel_reason', ''))
        return True
    return False


def artisan(*args):
    result = subprocess.run(['docker', 'exec', console.common.container('migration'), 'php', 'artisan', *args],
                            cwd=console.common.ROOT, capture_output=True, text=True, timeout=300)
    if result.returncode:
        raise RuntimeError('Служебная команда не выполнена; требуется проверка migration-ops')
    # Artisan can prepend framework notices. Reports themselves must be one JSON object.
    return json.loads(result.stdout.strip().splitlines()[-1])


def export_run(operation, run):
    # A host restart may happen after the immutable write but before the owner
    # head acquired archive_key. Reuse that exact verified proof, never overwrite.
    for path in (directory(operation) / 'runs').glob('*-' + str(run['id']) + '.json'):
        existing = read(path)
        identity = existing.get('run', existing)
        if (str(existing.get('operation_id')) == str(operation['id'])
                and str(identity.get('id')) == str(run['id']) and identity.get('service') == run['service']
                and identity.get('mode') == run['mode']):
            run['archive_key'] = path.stem
            return existing
    try:
        report = artisan('migration:control-report', str(run['id']), str(operation['id']))
    except Exception as exc:
        raise AuditError('Полный отчёт не сохранён; продолжение заблокировано') from exc
    reported = report.get('run', report)
    if (str(report.get('operation_id')) != str(operation['id'])
            or str(reported.get('id')) != str(run['id']) or reported.get('service') != run['service']
            or reported.get('mode') != run['mode']):
        raise AuditError('Отчёт запуска не соответствует этапу; продолжение заблокировано')
    key = f"{len(operation['runs']):04d}-{run['id']}"
    try:
        atomic(directory(operation) / 'runs' / (key + '.json'), report, immutable=True)
    except Exception as exc:
        raise AuditError('Полный отчёт не сохранён; продолжение заблокировано') from exc
    run['archive_key'] = key
    return report


def enqueue(service, mode, operation, sid):
    value = artisan('migration:console-enqueue', service, mode, str(operation['id']), str(sid), '--control')
    run = {'id': int(value['run_id']), 'service': service, 'mode': mode, 'status': 'queued'}
    runs = [*operation['runs'], run]
    update(operation, runs=runs, current_run_id=run['id'])
    deadline = time.monotonic() + 7200
    while time.monotonic() < deadline:
        row = console.run_record(run['id'])
        if row and row['status'] in TERMINAL:
            run.update({key: row[key] for key in ('id', 'service', 'mode', 'status')})
            if row.get('error'):
                run['error'] = 'Этап завершён с ошибкой; подробности в защищённом журнале'
            exported = export_run(operation, run)
            update(operation, runs=runs)
            if mode == 'migrate' and run['status'] == 'completed':
                inventory = exported.get('inventory')
                incomplete = not isinstance(inventory, dict) or not inventory.get('complete', False)
                remaining = any(any(table.get('source_outcomes', {}).get(key, 0) not in (0, None)
                                    for key in ('metadata_only', 'deferred', 'failed_unique', 'unprocessed'))
                                for table in exported.get('tables', []))
                if incomplete or remaining:
                    # Completed worker stages do not imply complete source coverage.
                    run['coverage_status'] = 'partial'
                    update(operation, runs=runs)
                    return 'partial'
            return run['status']
        # Do not cancel/terminate a live worker. Queue ownership remains reserved.
        time.sleep(1)
    raise RuntimeError('Исход этапа неизвестен; дождитесь завершения worker и проверьте журнал')


def result(operation, service, status, message, **context):
    rows = [r for r in operation['service_results'] if r['service'] != service]
    allowed = {key: context.get(key) for key in ('stage','stage_status','run_id','data_outcome','processed_count','total')
               if key in context}
    update(operation, service_results=[*rows, {'service': service, 'status': status, 'message': message, **allowed}])


def live_operation(operation):
    """Read-only counters, identity-bound to the current host owner.

    Never query a historical run merely by its reusable SQLite integer ID.
    Missing identity or unavailable metadata yields the last durable facts.
    """
    value = copy.deepcopy(operation)
    if value.get('status') not in ('queued', 'running'):
        return value
    current = value.get('current_run_id')
    run = next((item for item in value.get('runs', []) if item['id'] == current and not item.get('archive_key')), None)
    if not run:
        return value
    try:
        with sqlite3.connect(f'file:{os.environ.get("MIGRATION_DATA_PATH", "/data")}/migration.sqlite?mode=ro', uri=True) as db:
            db.row_factory = sqlite3.Row
            db.execute('BEGIN')
            contexts = db.execute("SELECT context FROM migration_run_events WHERE migration_run_id=? AND event='console'", (current,))
            if not any(str(json.loads(row['context'] or '{}').get('operation_id')) == str(value['id']) for row in contexts):
                return value
            row = db.execute('SELECT * FROM migration_runs WHERE id=? AND service=? AND mode=?', (current, run['service'], run['mode'])).fetchone()
            if not row:
                return value
            allowed = ('status', 'progress_phase', 'processed_count', 'success_count', 'warning_count', 'conflict_count',
                       'heartbeat_at', 'started_at', 'finished_at', 'created_at')
            run.update({key: row[key] for key in allowed if key in row.keys()})
            columns = {row['name'] for row in db.execute('PRAGMA table_info(migration_table_progress)')}
            fields = [key for key in ('table_name', 'state', 'total', 'read_count', 'processed_count', 'success_count',
                                      'error_count', 'warning_count', 'ready_count', 'blocked_count', 'updated_at') if key in columns]
            run['tables'] = [dict(row) for row in db.execute(f'SELECT {",".join(fields)} FROM migration_table_progress WHERE migration_run_id=? ORDER BY id', (current,))] if fields else []
            totals = [item.get('total') for item in run['tables']]
            run['total'] = sum(totals) if totals and all(item is not None for item in totals) else None
            run['read_count'] = sum(item.get('read_count', 0) for item in run['tables'])
            for counter in ('processed_count', 'success_count', 'ready_count', 'blocked_count'):
                # Run-level legacy counters count diagnostic occurrences too;
                # table progress tracks exact distinct source rows.
                run[counter] = sum(item.get(counter, 0) for item in run['tables'])
            run['live'] = True
            value['live_updated_at'] = time.time()
    except (sqlite3.Error, ValueError, TypeError):
        pass
    return value


def build_report(operation):
    operation = live_operation(operation)
    root = directory(operation)
    runs = [read(p) for p in sorted((root / 'runs').glob('*.json'))] if (root / 'runs').exists() else []
    for run in operation.get('runs', []):
        if run.get('live'):
            runs.append({**run, 'run_id': run['id'], 'operation_id': operation['id'], 'run': run,
                         'diagnostics': {'items': [], 'groups': []}, 'root_cause_groups': [], 'events': []})
    inventories, latest, diagnostics = {}, {}, {}
    for exported in runs:
        service = exported.get('service') or (exported.get('run') or {}).get('service')
        latest[service] = exported
        inventory = exported.get('inventory')
        if isinstance(inventory, dict):
            inventories[service] = inventory
        elif isinstance(inventory, list):
            inventories[service] = inventory
        # The headline uses one diagnostic-bearing stage per service. Keeping
        # earlier stages in the export must not multiply the same source rows.
        if exported.get('root_cause_groups') or exported.get('conflicts'):
            diagnostics[service] = exported
    groups = []
    for service, exported in diagnostics.items():
        provided = exported.get('root_cause_groups')
        if isinstance(provided, list):
            groups.extend({**group, 'service': service, 'stage': exported.get('mode') or (exported.get('run') or {}).get('mode'),
                           'run_id': exported.get('run_id') or (exported.get('run') or {}).get('id')}
                          for group in provided)
        else:
            fallback = {}
            conflicts = exported.get('conflicts', [])
            if isinstance(conflicts, dict):
                conflicts = conflicts.get('data', [])
            for conflict in conflicts:
                key = tuple(str(conflict.get(field, '')) for field in ('table', 'code', 'severity', 'reason'))
                entry = fallback.setdefault(key, {'service': service, 'table': conflict.get('table'),
                    'code': conflict.get('code') or 'unclassified', 'severity': conflict.get('severity'),
                    'reason': conflict.get('reason'), 'count': 0, 'stage': (exported.get('run') or {}).get('mode')})
                entry['count'] += 1
            groups.extend(fallback.values())
    groups.sort(key=lambda item: (item.get('severity') != 'error', -int(item.get('count', 0))))
    indexed, impacts = {}, {}
    for service, exported in diagnostics.items():
        for item in exported.get('diagnostics', {}).get('items', []):
            if item.get('legacy_id') is not None:
                key = (service, str(item.get('table')), str(item['legacy_id']))
                indexed.setdefault(key, []).append(item)
    for service, exported in diagnostics.items():
        for item in exported.get('diagnostics', {}).get('items', []):
            dependency = (item.get('context') or {}).get('dependency')
            if not isinstance(dependency, dict) or dependency.get('legacy_id') is None or not dependency.get('table'):
                continue
            key = (dependency.get('service', service), str(dependency['table']), str(dependency['legacy_id']))
            edge = impacts.setdefault(key, {'dependency_service': key[0], 'dependency_table': key[1],
                'dependency_legacy_id': key[2], 'cause': dependency.get('cause'), 'dependent_rows': set(),
                'dependent_tables': set(), 'stage': exported.get('mode') or (exported.get('run') or {}).get('mode')})
            if item.get('legacy_id') is not None:
                edge['dependent_rows'].add((service, str(item.get('table')), str(item['legacy_id'])))
            edge['dependent_tables'].add(service + '.' + str(item.get('table')))
    dependency_impacts = []
    for key, edge in impacts.items():
        root_items = indexed.get(key, [])
        root_keys = {(item.get('code'), item.get('severity'), item.get('message')) for item in root_items}
        roots = [group for group in groups if group.get('service') == key[0] and str(group.get('table')) == key[1]
                 and (group.get('code'), group.get('severity'), group.get('message')) in root_keys]
        # An exact row identity is evidence of an immediate dependency. It does
        # not prove that this row is the ultimate root of a longer chain.
        edge.update(blocked_unique=len(edge['dependent_rows']), root_groups=roots,
                    root_status='matched_immediate_diagnostic' if roots else 'unresolved_dependency',
                    dependent_rows=[{'service': row[0], 'table': row[1], 'legacy_id': row[2]} for row in sorted(edge['dependent_rows'])],
                    dependent_tables=sorted(edge['dependent_tables']))
        dependency_impacts.append(edge)
    inventory = [item for value in inventories.values() for item in (value if isinstance(value, list) else [value])]
    expected_services = list(operation.get('services') or
                             (console.SERVICES if operation.get('scope') == 'all' else [operation.get('scope')]))
    expected_services = [service for service in expected_services if service]
    unavailable_services = []
    for service in expected_services:
        service_inventory = inventories.get(service)
        service_items = service_inventory if isinstance(service_inventory, list) else [service_inventory]
        if not service_inventory or not all(item.get('complete', False) for item in service_items):
            unavailable_services.append(service)
    unavailable = len(unavailable_services)
    archived_outcome_services = {exported.get('service') or (exported.get('run') or {}).get('service')
                                 for exported in runs
                                 if not exported.get('live')
                                 and (exported.get('mode') or (exported.get('run') or {}).get('mode')) == 'migrate'}
    missing_outcome_services = [service for service in expected_services if service not in archived_outcome_services]
    incomplete_outcome_services = [
        service for service in expected_services
        if (exported := next((item for item in runs
                             if not item.get('live')
                             if (item.get('service') or (item.get('run') or {}).get('service')) == service
                             and (item.get('mode') or (item.get('run') or {}).get('mode')) == 'migrate'), None))
        and (exported.get('status') or (exported.get('run') or {}).get('status')) != 'completed'
    ]
    unarchived = [{key: item.get(key) for key in ('id', 'service', 'mode', 'status')}
                  for item in operation.get('runs', []) if not item.get('archive_key')]
    archive_complete = not unarchived
    outcome_runs = {}
    for exported in runs:
        service = exported.get('service') or (exported.get('run') or {}).get('service')
        if (exported.get('mode') or (exported.get('run') or {}).get('mode')) == 'migrate':
            outcome_runs[service] = exported
    outcome_runs = {**latest, **outcome_runs}
    unprocessed_tables, unprocessed_rows, unknown_totals = 0, 0, 0
    for exported in outcome_runs.values():
        for table in exported.get('tables', []):
            remaining = table.get('source_outcomes', {}).get('unprocessed')
            if remaining is None:
                unknown_totals += 1
            elif remaining > 0:
                unprocessed_tables += 1
                unprocessed_rows += remaining
    coverage = {'expected_services': expected_services,
                'inventory_complete': bool(expected_services) and unavailable == 0,
                'incomplete_inventories': unavailable,
                'missing_inventory_services': [service for service in expected_services if service not in inventories],
                'archived_outcome_complete': bool(expected_services) and not missing_outcome_services,
                'missing_archived_outcome_services': missing_outcome_services,
                'incomplete_outcome_services': incomplete_outcome_services,
                'archive_complete': archive_complete,
                'unprocessed_tables': unprocessed_tables, 'unprocessed_rows': unprocessed_rows,
                'unknown_table_totals': unknown_totals}
    fates = {}
    for item in inventory:
        for table in item.get('tables', []):
            for column in table.get('columns', []):
                fate = (column.get('mapping') or {}).get('fate', 'unmapped')
                fates[fate] = fates.get(fate, 0) + 1
    coverage['field_fates'] = fates
    interpretation_counts = {}
    for item in inventory:
        for table in item.get('tables', []):
            for column in table.get('columns', []):
                for observed in column.get('values') or []:
                    status = (observed.get('interpretation') or {}).get('status', 'not_reviewed')
                    interpretation_counts[status] = interpretation_counts.get(status, 0) + 1
    coverage['value_interpretations'] = interpretation_counts
    coverage['semantic_complete'] = (coverage['inventory_complete'] and coverage['archived_outcome_complete']
                                     and not incomplete_outcome_services
                                     and archive_complete and not unknown_totals and not unprocessed_rows
                                     and all(fates.get(key, 0) == 0 for key in ('unmapped', 'deferred', 'metadata_only'))
                                     and all(interpretation_counts.get(key, 0) == 0 for key in ('unknown', 'not_reviewed')))
    def group_key(group):
        return tuple(str(group.get(key, '')) for key in ('service', 'table', 'code', 'severity', 'reason', 'message'))
    previous = next((item for item in history() if item['id'] != operation['id']
                     and item.get('scope') == operation.get('scope') and item['started_at'] < operation['started_at']
                     and (directory(item) / 'report.json').exists()), None)
    comparison = None
    if previous:
        old = read(directory(previous) / 'report.json', {}).get('root_cause_groups', [])
        before = {group_key(group): group for group in old}
        after = {group_key(group): group for group in groups}
        comparison = {'operation_id': previous['id'], 'added': [after[key] for key in after.keys() - before.keys()],
                      'resolved': [before[key] for key in before.keys() - after.keys()],
                      'changed': [{'group': after[key], 'previous_count': before[key].get('count', 0),
                                   'count_delta': after[key].get('count', 0) - before[key].get('count', 0)}
                                  for key in after.keys() & before.keys() if after[key].get('count') != before[key].get('count')]}
    last_phase = operation.get('last_phase') or operation.get('phase')
    last_service = operation.get('last_service') or operation.get('current_service')
    phase_label = f"{last_service}: {last_phase}" if last_service else str(last_phase)
    summary = f"Перенос #{operation['id']}: {operation['status']}; последний этап {phase_label}; данные: {operation.get('data_outcome', 'unknown')}."
    if operation.get('message'):
        summary += f" Сообщение: {operation['message']}"
    summary += f" Начало {operation.get('started_at')}; конец {operation.get('finished_at', 'в работе')}; версия архива {operation.get('archive_version', 1)}."
    summary += "\n" + '; '.join(f"{item['service']}: {item['status']}" for item in operation.get('service_results', []))
    summary += f"\nСнимки: {json.dumps(operation.get('snapshot_ids', {}), ensure_ascii=False)}. Автоматический откат не выполнялся."
    summary += f"\nИнвентарь: {'полный' if coverage['inventory_complete'] else 'неполный/не подтверждён'}; необработано {unprocessed_rows} строк в {unprocessed_tables} таблицах; неизвестный итог {unknown_totals} таблиц."
    summary += f" Поля: не описано {fates.get('unmapped', 0)}, отложено {fates.get('deferred', 0)}, только metadata {fates.get('metadata_only', 0)}."
    summary += f" Значения: неизвестно {interpretation_counts.get('unknown', 0)}, не проверено {interpretation_counts.get('not_reviewed', 0)}."
    for group in groups[:12]:
        summary += f"\n{group.get('service')}/{group.get('table', '?')} [{group.get('stage', '?')}]: {group.get('code')} ({group.get('severity', '?')}): {group.get('count', 0)} событий, {group.get('affected_rows', '?')} строк."
    summary += f"\nПолный JSON: {len(runs)} этапов, {len(groups)} групп; в тексте скрыто {max(0, len(groups) - 12)} групп. Строки между этапами не суммируются."
    if dependency_impacts:
        summary += f" Зависимости: {len(dependency_impacts)} связей; не подтверждена причина у {sum(item['root_status'] == 'unresolved_dependency' for item in dependency_impacts)}."
    if operation.get('cancel_requested'):
        summary += '\nОстановка: ' + operation.get('cancel_reason', '')
    if unarchived:
        identities = ', '.join(f"#{item.get('id')} {item.get('service')}/{item.get('mode')} ({item.get('status')})"
                               for item in unarchived)
        summary += f"\nПолный отчёт не подтверждён для {len(unarchived)} этапов: {identities}; отсутствие записей не доказывает отсутствие ошибок."
    return {'schema_version': 1, 'operation': copy.deepcopy(operation), 'runs': runs, 'root_cause_groups': groups,
            'archive_complete': archive_complete, 'unarchived_runs': unarchived,
            'dependency_impacts': dependency_impacts,
            'diagnostic_semantics': 'Latest diagnostic-bearing stage per service; earlier stages are retained in runs. Counts across stages are not summed.',
            'inventory': inventory, 'coverage': coverage, 'comparison': comparison, 'chat_summary': summary,
            'events': [read(p) for p in sorted((root / 'events').glob('*.json'))]}


def report(operation_id):
    stub = {'id': str(operation_id)}
    root = directory(stub)
    frozen = read(root / 'report.json')
    if frozen:
        return frozen
    operation = read(root / 'operation.json')
    if not operation:
        raise FileNotFoundError()
    return build_report(operation)


def finish(operation, status, message):
    if status == 'completed' and not build_report(operation)['coverage']['semantic_complete']:
        status = 'partial'
        message = 'Этапы завершены; полное покрытие исходных данных не подтверждено'
        if operation.get('data_outcome') == 'changed':
            operation['data_outcome'] = 'partial'
    update(operation, last_phase=operation.get('phase'), last_service=operation.get('current_service'),
           status=status, phase='finished', finished_at=time.time(), message=message, report_available=True)
    atomic(directory(operation) / 'report.json', build_report(operation), immutable=True)


def execute(operation):
    try:
        update(operation, status='running')
        if cancelled(operation):
            finish(operation, 'cancelled', 'Остановлено до начала этапов; откат не выполнялся')
            return
        if operation['scope'] == 'all':
            console.checkpoint(operation, 'all')
            update(operation)
        for service in operation['services']:
            if cancelled(operation):
                break
            stage = 'snapshot'
            stage_status = 'running'
            try:
                # Snapshot failures are service-local. No data stage starts without proof.
                update(operation, phase=stage, current_service=service, message=f'{service}: {stage}')
                sid = console.checkpoint(operation, service)
                stage_status = 'completed'
                update(operation)
                partial = False
                for mode in MODES:
                    if cancelled(operation):
                        break
                    stage = mode
                    stage_status = 'running'
                    update(operation, phase=mode, current_service=service, message=f'{service}: {mode}')
                    previous_outcome = operation.get('data_outcome', 'not_started')
                    if mode == 'migrate':
                        update(operation, data_outcome='unknown')
                    status = enqueue(service, mode, operation, sid)
                    stage_status = status
                    if mode == 'migrate':
                        outcome = 'partial' if status != 'completed' else 'changed'
                        if previous_outcome in ('unknown', 'partial'):
                            outcome = previous_outcome
                        update(operation, data_outcome=outcome)
                    if status in ('conflicts', 'partial'):
                        partial = True
                    elif status != 'completed':
                        raise RuntimeError('Этап завершён с ошибкой; следующие этапы сервиса пропущены')
                latest_run = next((item for item in reversed(operation.get('runs', [])) if item.get('service') == service), None)
                result(operation, service, 'partial' if partial or cancelled(operation) else 'completed',
                       'Часть строк требует решения' if partial else ('Остановлено между этапами' if cancelled(operation) else 'Этапы завершены'),
                       stage=stage, stage_status=stage_status, run_id=(latest_run or {}).get('id'),
                       data_outcome=operation.get('data_outcome'))
            except AuditError:
                raise
            except Exception as exc:
                # Raw child output is private. CLI only emits our safe coordinator messages.
                latest_run = next((item for item in reversed(operation.get('runs', []))
                                   if item.get('service') == service and item.get('mode') == stage), None)
                result(operation, service, 'failed',
                       str(exc) if type(exc) is RuntimeError else 'Этап не выполнен; проверьте migration-ops',
                       stage=stage, stage_status=(latest_run or {}).get('status', 'failed'),
                       run_id=(latest_run or {}).get('id'), data_outcome=operation.get('data_outcome'),
                       processed_count=(latest_run or {}).get('processed_count'),
                       total=(latest_run or {}).get('total'))
                if operation.get('data_outcome') in ('changed', 'unknown'):
                    update(operation, data_outcome='partial' if operation['data_outcome'] == 'changed' else 'unknown')
                # A timed-out worker may still be live: never enqueue another service.
                with sqlite3.connect(f'file:{os.environ.get("MIGRATION_DATA_PATH", "/data")}/migration.sqlite?mode=ro', uri=True) as db:
                    if db.execute("SELECT count(*) FROM migration_runs WHERE status IN ('queued','running')").fetchone()[0]:
                        raise RuntimeError('Worker ещё активен; продолжение заблокировано до подтверждения исхода')
        if cancelled(operation):
            finish(operation, 'cancelled', 'Остановлено между этапами; автоматический откат не выполнялся')
        else:
            statuses = [row['status'] for row in operation['service_results']]
            final = 'completed' if all(s == 'completed' for s in statuses) else ('failed' if all(s == 'failed' for s in statuses) else 'partial')
            if final == 'partial' and operation['data_outcome'] == 'changed':
                operation['data_outcome'] = 'partial'
            finish(operation, final, 'Этапы завершены; результат по каждому сервису сохранён')
    except Exception:
        finish(operation, 'failed', 'Продолжение заблокировано; проверьте migration-ops и состояние worker')


def recover():
    # No replay: a process restart cannot prove whether an API write committed.
    for operation in history():
        if operation.get('status') in ('queued', 'running'):
            for run in operation.get('runs', []):
                if run.get('archive_key'):
                    continue
                try:
                    row = console.run_record(run['id'])
                    if row and row['status'] in TERMINAL:
                        run['status'] = row['status']
                        export_run(operation, run)
                except Exception:
                    # Preserve an explicit archive gap; never substitute a run
                    # that reused this ID after restored metadata.
                    pass
            if operation.get('phase') == 'migrate':
                operation['data_outcome'] = 'unknown'
            finish(operation, 'interrupted', 'Migration-ops перезапущен; исход активного этапа требует проверки')


def payload():
    legacy = console.payload()
    owner = legacy.get('operation') or {}
    rows = history()
    restores = {}
    for path in (console.STATE.parent / 'control-reports' / 'restore-history').glob('*/*.json'):
        value = read(path)
        key = str(value['id'])
        if value.get('updated_at', 0) >= restores.get(key, {}).get('updated_at', 0):
            restores[key] = value
    for value in legacy.get('history', []):
        if value.get('action') == 'restore':
            restores.setdefault(str(value['id']), value)
    evidence = []
    for value in restores.values():
        diagnostic = value.get('diagnostics') or {}
        matched = (str(diagnostic.get('snapshot_id')) == str(value.get('snapshot_id'))
                   and diagnostic.get('scope') == value.get('scope'))
        confirmed = (value.get('status') == 'completed' and matched
                     and diagnostic.get('stage') == 'completed' and diagnostic.get('state') == 'completed'
                     and all(diagnostic.get(key) is True for key in ('database_committed', 'metadata_complete', 'schemas_upgraded')))
        stages_completed = confirmed
        verification = value.get('verification') or {}
        verified = (verification.get('diagnostic_id') == value.get('diagnostic_id')
                    and verification.get('scope') == value.get('scope')
                    and str(verification.get('snapshot_id')) == str(value.get('snapshot_id'))
                    and all(verification.get(key) is True for key in ('checkpoint_verification_available',
                        'database_baseline_matches', 'metadata_matches', 'credential_key_matches', 'documents_match',
                        'final_database_matches', 'final_metadata_matches', 'runtime_started')))
        confirmed = stages_completed and verified
        evidence.append({'operation_id': value['id'], 'scope': value.get('scope'), 'snapshot_id': value.get('snapshot_id'),
                         'status': value.get('status'), 'started_at': value.get('started_at'),
                         'confirmed': confirmed, 'database_outcome': diagnostic.get('database_outcome', 'unknown') if matched else 'unknown',
                         'stage': diagnostic.get('stage') if matched else None,
                         'stages_completed': stages_completed, 'verification': verification,
                         'verification_status': 'verified' if confirmed else ('stages_completed_unverified' if stages_completed else 'unverified')})
    evidence.sort(key=lambda value: value.get('started_at', 0), reverse=True)
    for scope, points in legacy['snapshots'].items():
        for point in points:
            proof = next((item for item in evidence if item['scope'] == scope and str(item['snapshot_id']) == str(point['id'])), None)
            point['restored'] = bool(proof and proof['confirmed'])
            point['restore_outcome'] = proof['database_outcome'] if proof else 'unknown'
    selected = owner if owner.get('control_mode') else (rows[0] if rows else None)
    return {'operation': live_operation(selected) if selected else None, 'history': rows,
            'console_operation': owner or None, 'console_history': legacy.get('history', []),
            'snapshots': legacy['snapshots'], 'restore_status': legacy['restore_status'], 'restore_history': evidence}
