#!/usr/bin/env python3
"""One-shot, fail-closed reconciliation of abandoned Timesheets dry-run #278.

Execute only within the migration-ops container, after outer deployment probe
confirmed no PHP processes running in the migration-worker container.
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
RUN = 278
OPERATION = '1792833696185068'

def main():
    state = json.loads(COORDINATOR.read_text())
    op = state.get('operation') or {}
    if (str(op.get('id')) != OPERATION or op.get('status') != 'failed'
            or str(op.get('current_run_id')) != str(RUN)):
        raise RuntimeError('REFUSE: unexpected coordinator identity/state')
    if time.time() - float(op.get('updated_at', 0)) < 1800:
        raise RuntimeError('REFUSE: coordinator failure too recent')
    if not DB.is_file() or DB.is_symlink():
        raise RuntimeError('REFUSE: metadata database unavailable')
    with sqlite3.connect(str(DB), timeout=10, isolation_level=None) as conn:
        conn.row_factory = sqlite3.Row
        conn.execute('PRAGMA busy_timeout=10000')
        conn.execute('BEGIN IMMEDIATE')
        rows = conn.execute("SELECT id,service,mode,status,processed_count,heartbeat_at,updated_at "
                            "FROM migration_runs WHERE status IN ('queued','running')").fetchall()
        if (len(rows) != 1 or rows[0]['id'] != RUN
                or rows[0]['service'] != 'timesheets'
                or rows[0]['mode'] != 'dry-run' or rows[0]['status'] != 'running'):
            conn.execute('ROLLBACK')
            raise RuntimeError('REFUSE: unexpected active migration runs')
        row = rows[0]
        if row['heartbeat_at'] is not None:
            conn.execute('ROLLBACK')
            raise RuntimeError('REFUSE: heartbeat exists; manual review required')
        jobs = conn.execute("SELECT count(*) FROM jobs").fetchone()[0]
        if jobs:
            conn.execute('ROLLBACK')
            raise RuntimeError('REFUSE: queue is not empty')
        backup = DB.with_name('migration-before-stale-278-' + str(int(time.time())) + '.sqlite')
        if backup.exists():
            conn.execute('ROLLBACK')
            raise RuntimeError('REFUSE: backup already exists')
        fd = os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        os.close(fd)
        with sqlite3.connect(str(backup)) as dest:
            conn.backup(dest)
        # Only reconcile the exact orphaned record. No source/target rows or mappings changed.
        now = dt.datetime.now(dt.timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
        result = conn.execute("UPDATE migration_runs SET status='failed', progress_phase='failed', "
                              "progress_message=?, error=?, finished_at=?, updated_at=? "
                              "WHERE id=? AND status='running' AND heartbeat_at IS NULL",
                              ('Abandoned worker after coordinator failure; confirmed no PHP migration process.',
                               'STALE_WORKER_RUN_RECONCILED: coordinator failed; no PHP process or queued job',
                               now, now, RUN))
        if result.rowcount != 1:
            conn.execute('ROLLBACK')
            raise RuntimeError('REFUSE: run state changed during recovery')
        conn.execute('COMMIT')
    print('RECOVERY_DONE: run=278 status=failed; SQLite backup persisted privately; no source/target mutation.')

if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print('RECOVERY_BLOCKED: ' + str(exc), file=sys.stderr)
        sys.exit(2)
