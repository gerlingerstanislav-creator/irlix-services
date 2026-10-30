#!/usr/bin/env python3
"""One-time, fail-closed reconciliation of orphaned Clients inspect #376.

Run inside migration-ops. The workflow verifies the idle queue, stops the
migration worker, backs up ONLY the migration metadata SQLite database, then
marks exactly one abandoned inspect record failed. Never alters source/target
service data, snapshots, mappings, or migration queue entries.
"""
import datetime as dt
import json
import os
from pathlib import Path
import sqlite3
import sys
import time

DB = Path('/data/migration.sqlite')
COORDINATOR = Path('/ops/console.json')
SNAPSHOT_STATE = Path('/ops/status.json')
RUN = 376
OPERATION = '1793299883565271'
MIN_STALE_SECONDS = 1800


def require(condition, message):
    if not condition:
        raise RuntimeError('REFUSE: ' + message)


def read_coordinator():
    state = json.loads(COORDINATOR.read_text(encoding='utf-8'))
    op = state.get('operation') or {}
    require(
        str(op.get('id')) == OPERATION
        and str(op.get('current_run_id')) == str(RUN)
        and op.get('status') == 'failed'
        and op.get('current_service') == 'clients',
        'coordinator identity, service or state changed',
    )
    require(time.time() - float(op.get('updated_at', 0)) >= MIN_STALE_SECONDS,
            'coordinator failure is too recent')
    snapshot = json.loads(SNAPSHOT_STATE.read_text(encoding='utf-8'))
    require(snapshot.get('state') not in ('queued', 'running'),
            'snapshot or restore is in progress')
    return op


def stale_heartbeat(value):
    if value is None:
        return True
    try:
        parsed = dt.datetime.fromisoformat(str(value).replace('Z', '+00:00'))
        if parsed.tzinfo is None:
            parsed = parsed.replace(tzinfo=dt.timezone.utc)
        return (dt.datetime.now(dt.timezone.utc) - parsed).total_seconds() >= MIN_STALE_SECONDS
    except (ValueError, TypeError, OverflowError) as exc:
        raise RuntimeError('REFUSE: heartbeat timestamp cannot be verified') from exc


def inspect(conn):
    conn.row_factory = sqlite3.Row
    active = conn.execute(
        "SELECT id, service, mode, status, progress_phase, processed_count, "
        "heartbeat_at, updated_at FROM migration_runs "
        "WHERE status IN ('queued','running')"
    ).fetchall()
    require(len(active) == 1 and active[0]['id'] == RUN,
            'unexpected active migration runs')
    row = active[0]
    require(row['service'] == 'clients' and row['mode'] == 'inspect'
            and row['status'] == 'running' and row['progress_phase'] == 'safety',
            'not the expected abandoned Clients inspect')
    require(stale_heartbeat(row['heartbeat_at']),
            'run heartbeat is recent')
    require(conn.execute('SELECT COUNT(*) FROM jobs').fetchone()[0] == 0,
            'migration job queue is not empty')
    return dict(row)


def backup_metadata():
    target = DB.with_name('migration-before-stale-clients-376-' + str(time.time_ns()) + '.sqlite')
    fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    os.close(fd)
    try:
        with sqlite3.connect('file:' + str(DB) + '?mode=ro', uri=True) as source:
            with sqlite3.connect(str(target)) as dest:
                source.backup(dest)
        require(target.stat().st_size > 0, 'SQLite metadata backup is empty')
        with sqlite3.connect('file:' + str(target) + '?mode=ro', uri=True) as db:
            require(db.execute('PRAGMA quick_check').fetchone()[0] == 'ok',
                    'SQLite metadata backup integrity check failed')
    except Exception:
        # Keep the incomplete copy for investigation; do not proceed.
        raise RuntimeError('REFUSE: protected SQLite metadata backup failed')
    return target


def main():
    require(sys.argv[1:] in (['--preflight'], ['--apply']),
            'provide --preflight or --apply explicitly')
    require(DB.is_file() and not DB.is_symlink(),
            'metadata database is missing or is a symlink')
    read_coordinator()
    with sqlite3.connect(str(DB), timeout=10, isolation_level=None) as conn:
        conn.execute('PRAGMA busy_timeout=10000')
        conn.execute('BEGIN IMMEDIATE')
        observed = inspect(conn)
        conn.execute('ROLLBACK')
        if sys.argv[1:] == ['--preflight']:
            print('RECOVERY_PREFLIGHT_PASS: only orphaned Clients inspect #376; coordinator failed; queue empty; heartbeat stale.')
            return

        # The workflow must have quiesced the sole worker before --apply.
        backup = backup_metadata()
        read_coordinator()
        conn.execute('BEGIN IMMEDIATE')
        current = inspect(conn)
        require(current == observed, 'run state changed after backup')
        now = dt.datetime.now(dt.timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
        result = conn.execute(
            "UPDATE migration_runs SET status='failed', progress_phase='failed', "
            "progress_message=?, error=?, finished_at=?, updated_at=? "
            "WHERE id=? AND service='clients' AND mode='inspect' "
            "AND status='running' AND progress_phase='safety' AND heartbeat_at IS ?",
            ('Orphaned read-only inspect reconciled after coordinator failure.',
             'STALE_INSPECT_RECONCILED: failed coordinator, idle queue, stopped worker, metadata backup',
             now, now, RUN, observed['heartbeat_at']),
        )
        require(result.rowcount == 1, 'conditional reconciliation did not affect exactly one run')
        conn.execute('COMMIT')
    print('RECOVERY_DONE: Clients inspect #376 marked failed; backup=' + backup.name
          + '; source/target data unchanged.')


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print('RECOVERY_BLOCKED: ' + str(exc), file=sys.stderr)
        sys.exit(2)
