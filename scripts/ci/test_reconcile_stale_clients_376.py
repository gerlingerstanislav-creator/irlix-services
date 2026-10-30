#!/usr/bin/env python3
"""SQLite-only regression tests for the one-time Clients inspect recovery."""
import datetime as dt
import importlib.util
import json
from pathlib import Path
import sqlite3
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).with_name('reconcile_stale_clients_376.py')
spec = importlib.util.spec_from_file_location('clients_376_recovery', SCRIPT)
recovery = importlib.util.module_from_spec(spec)
spec.loader.exec_module(recovery)


class Clients376RecoveryTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        base = Path(self.tmp.name)
        self.db_path = base / 'migration.sqlite'
        self.ops_path = base / 'console.json'
        self.snap_path = base / 'status.json'
        self.ops_path.write_text(json.dumps({'operation': {
            'id': recovery.OPERATION, 'current_run_id': recovery.RUN,
            'current_service': 'clients', 'status': 'failed',
            'updated_at': time.time() - 4000,
        }}))
        self.snap_path.write_text('{"state":"idle"}')
        self.old_heartbeat = (
            dt.datetime.now(dt.timezone.utc) - dt.timedelta(hours=2)
        ).strftime('%Y-%m-%d %H:%M:%S')
        with sqlite3.connect(self.db_path) as db:
            db.executescript("""
                CREATE TABLE migration_runs (
                    id INTEGER PRIMARY KEY, service TEXT, mode TEXT, status TEXT,
                    progress_phase TEXT, processed_count INTEGER, heartbeat_at TEXT,
                    updated_at TEXT, progress_message TEXT, error TEXT, finished_at TEXT
                );
                CREATE TABLE jobs (id INTEGER PRIMARY KEY);
                CREATE TABLE preserved_data (id INTEGER PRIMARY KEY, note TEXT);
                INSERT INTO preserved_data VALUES (1, 'keep');
            """)
            db.execute(
                "INSERT INTO migration_runs (id,service,mode,status,progress_phase,"
                "processed_count,heartbeat_at) VALUES (376,'clients','inspect','running',"
                "'safety',0,?)", (self.old_heartbeat,)
            )
        self.prev = (recovery.DB, recovery.COORDINATOR, recovery.SNAPSHOT_STATE)
        recovery.DB = self.db_path
        recovery.COORDINATOR = self.ops_path
        recovery.SNAPSHOT_STATE = self.snap_path
        self.addCleanup(self.restore)

    def restore(self):
        recovery.DB, recovery.COORDINATOR, recovery.SNAPSHOT_STATE = self.prev

    def invoke(self, option):
        with patch.object(sys, 'argv', [str(SCRIPT), option]):
            recovery.main()

    def read(self, query):
        with sqlite3.connect(self.db_path) as db:
            return db.execute(query).fetchone()

    def test_preflight_is_read_only(self):
        self.invoke('--preflight')
        self.assertEqual(self.read('SELECT status FROM migration_runs WHERE id=376'), ('running',))
        self.assertFalse(list(Path(self.tmp.name).glob('migration-before-*.sqlite')))

    def test_apply_backs_up_then_updates_only_orphan(self):
        self.invoke('--apply')
        self.assertEqual(self.read('SELECT status,mode FROM migration_runs WHERE id=376'), ('failed', 'inspect'))
        self.assertEqual(self.read('SELECT note FROM preserved_data WHERE id=1'), ('keep',))
        copies = list(Path(self.tmp.name).glob('migration-before-stale-clients-376-*.sqlite'))
        self.assertEqual(len(copies), 1)
        with sqlite3.connect(copies[0]) as copy:
            self.assertEqual(copy.execute('SELECT status FROM migration_runs WHERE id=376').fetchone(), ('running',))
        with self.assertRaisesRegex(RuntimeError, 'unexpected active migration runs'):
            self.invoke('--apply')

    def test_reject_queued_job(self):
        with sqlite3.connect(self.db_path) as db:
            db.execute('INSERT INTO jobs VALUES (1)')
        with self.assertRaisesRegex(RuntimeError, 'queue is not empty'):
            self.invoke('--preflight')

    def test_reject_other_active_run(self):
        with sqlite3.connect(self.db_path) as db:
            db.execute(
                "INSERT INTO migration_runs(id,service,mode,status) "
                "VALUES (377,'timesheets','migrate','queued')"
            )
        with self.assertRaisesRegex(RuntimeError, 'unexpected active migration runs'):
            self.invoke('--apply')
        self.assertFalse(list(Path(self.tmp.name).glob('migration-before-*.sqlite')))

    def test_reject_recent_heartbeat(self):
        now = dt.datetime.now(dt.timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
        with sqlite3.connect(self.db_path) as db:
            db.execute('UPDATE migration_runs SET heartbeat_at=? WHERE id=376', (now,))
        with self.assertRaisesRegex(RuntimeError, 'heartbeat is recent'):
            self.invoke('--preflight')

    def test_reject_running_coordinator(self):
        op = json.loads(self.ops_path.read_text())
        op['operation']['status'] = 'running'
        self.ops_path.write_text(json.dumps(op))
        with self.assertRaisesRegex(RuntimeError, 'coordinator identity'):
            self.invoke('--preflight')

    def test_reject_active_snapshot(self):
        self.snap_path.write_text('{"state":"running"}')
        with self.assertRaisesRegex(RuntimeError, 'snapshot or restore'):
            self.invoke('--preflight')


if __name__ == '__main__':
    unittest.main()
