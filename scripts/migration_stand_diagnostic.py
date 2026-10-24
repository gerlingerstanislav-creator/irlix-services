#!/usr/bin/env python3
"""Read-only, bounded migration health evidence for GitHub Actions.

Run inside migration-ops with already mounted /ops and /data. No network,
no SQL mutations, no secrets, no source rows, no worker process termination.
"""
import datetime as dt
import json
import os
from pathlib import Path
import sqlite3
import time

NOW = time.time()
BASE = Path('/ops')
DATABASE = Path('/data/migration.sqlite')


def age(value):
    try:
        return round(NOW - float(value), 1) if value is not None else None
    except (TypeError, ValueError):
        return None


def display(tag, fields):
    print(json.dumps({'check': tag, **fields}, ensure_ascii=False, sort_keys=True))


def main():
    op_path = BASE / 'console.json'
    try:
        state = json.loads(op_path.read_text())
        op = state.get('operation') or {}
        display('coordinator', {
            'id': str(op.get('id', '')),
            'status': op.get('status'),
            'phase': op.get('phase'),
            'service': op.get('current_service'),
            'run_id': op.get('current_run_id'),
            'updated_age_seconds': age(op.get('updated_at')),
            'started_age_seconds': age(op.get('started_at')),
            'cancel_requested': bool(op.get('cancel_requested')),
        })
    except (OSError, ValueError) as exc:
        display('coordinator_unavailable', {'reason': type(exc).__name__})
        op = {}

    try:
        with sqlite3.connect(f'file:{DATABASE}?mode=ro', uri=True, timeout=2) as db:
            db.row_factory = sqlite3.Row
            db.execute('PRAGMA query_only=ON')
            db.execute('PRAGMA busy_timeout=1500')
            rows = db.execute("""SELECT id,service,mode,status,progress_phase,
                processed_count,success_count,warning_count,conflict_count,
                heartbeat_at,started_at,finished_at
                FROM migration_runs WHERE status IN ('running','queued')
                ORDER BY id DESC LIMIT 10""").fetchall()
            display('active_runs', {'count_visible': len(rows)})
            for row in rows:
                r = dict(row)
                display('run', {
                    'id': r['id'], 'service': r['service'], 'mode': r['mode'],
                    'status': r['status'], 'progress_phase': r['progress_phase'],
                    'processed': r['processed_count'], 'success': r['success_count'],
                    'warnings': r['warning_count'], 'conflicts': r['conflict_count'],
                    'heartbeat_age_seconds': age(r['heartbeat_at']),
                    'started_age_seconds': age(r['started_at']),
                })
            for table in ('jobs', 'failed_jobs'):
                exists = db.execute("SELECT count(*) FROM sqlite_master WHERE type='table' AND name=?", (table,)).fetchone()[0]
                if not exists:
                    display(table, {'available': False})
                    continue
                if table == 'jobs':
                    row = db.execute("SELECT count(*) AS total, sum(case when reserved_at is not null then 1 else 0 end) AS reserved FROM jobs WHERE queue='migration'").fetchone()
                    display('queue', {'pending_or_reserved': row['total'], 'reserved': row['reserved'] or 0})
                else:
                    display('failed_jobs', {'count': db.execute('SELECT count(*) FROM failed_jobs').fetchone()[0]})
    except (sqlite3.Error, OSError) as exc:
        display('sqlite_unavailable', {'reason': type(exc).__name__})
    print('ASSESSMENT: Coordinator status alone does not prove a live worker. '
          'Heartbeat/progress and container state must agree. No automatic unlock or kill performed.')


if __name__ == '__main__':
    main()
