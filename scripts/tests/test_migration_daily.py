import datetime as dt
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import migration_daily as daily


class DailyMigrationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.state_path = Path(self.temp.name) / 'daily.json'
        self.patches = [
            patch.object(daily, 'STATE', self.state_path),
            patch.dict(os.environ, {'MIGRATION_DAILY_ENABLED': 'true'}),
            patch.object(daily.console, 'active', return_value=False),
        ]
        for item in self.patches:
            item.start()

    def tearDown(self):
        for item in reversed(self.patches):
            item.stop()
        self.temp.cleanup()

    def due(self, hour=23, minute=1, day=1):
        return dt.datetime(2026, 11, day, hour, minute, tzinfo=dt.timezone.utc)

    def test_requires_enabled_and_correct_utc_window(self):
        invoked = []
        factory = lambda: invoked.append(1) or {'id': '123'}
        with patch.dict(os.environ, {'MIGRATION_DAILY_ENABLED': 'false'}):
            self.assertIsNone(daily.reserve(self.due(), factory))
        self.assertIsNone(daily.reserve(self.due(hour=22), factory))
        self.assertIsNone(daily.reserve(self.due(minute=5), factory))
        self.assertEqual(invoked, [])
        self.assertFalse(self.state_path.exists())

    def test_single_durable_reservation_prevents_restart_replay(self):
        op = {'id': '123', 'action': 'snapshot'}
        calls = []
        def create():
            calls.append(1)
            return op
        self.assertEqual(daily.reserve(self.due(), create), op)
        self.assertEqual(daily.read()['status'], 'snapshot_running')
        self.assertIsNone(daily.reserve(self.due(minute=2), create))
        self.assertEqual(len(calls), 1)
        self.assertEqual(daily.reserve(self.due(day=2), create), op)
        self.assertEqual(len(calls), 2)

    def test_busy_owner_and_invalid_ledger_fail_closed(self):
        with patch.object(daily.console, 'active', return_value=True):
            self.assertIsNone(daily.reserve(self.due(), lambda: self.fail('busy')))
        self.state_path.write_text('not a JSON object')
        with self.assertRaises(RuntimeError):
            daily.reserve(self.due(), lambda: self.fail('ledger corrupt'))
        self.assertEqual(daily.public_state()['status'], 'ledger_unavailable')

    def test_checkpoint_must_finish_and_verify_before_target_write(self):
        daily.record(date='2026-11-01', status='snapshot_running')
        snap = {'id': '123', 'action': 'snapshot'}
        with (patch.object(daily.console, 'execute') as snapshot_exec,
              patch.object(daily.console, 'state',
                           return_value={'operation': {'id': '123',
                               'status': 'failed'}}),
              patch.object(daily.control, 'start') as migrate_start):
            daily.run_reserved(snap)
        snapshot_exec.assert_called_once_with(snap)
        migrate_start.assert_not_called()
        self.assertEqual(daily.read()['status'], 'checkpoint_failed')

    def test_success_keeps_correlation_and_does_not_retry(self):
        daily.record(date='2026-11-01', status='snapshot_running')
        snap = {'id': '123', 'action': 'snapshot'}
        operation = {'id': '456', 'status': 'queued'}
        with (patch.object(daily.console, 'execute'),
              patch.object(daily.console, 'state', return_value={
                  'operation': {'id': '123', 'status': 'completed',
                                'snapshot_ids': {'all': '999'}}}),
              patch.object(daily.console, 'checkpoints', return_value=[
                  {'id': '999', 'compatible': True}]),
              patch.object(daily.control, 'start', return_value=operation) as start,
              patch.object(daily.control, 'execute') as execute,
              patch.object(daily.control, 'directory',
                           return_value=Path(self.temp.name)),
              patch.object(daily.control, 'read',
                           side_effect=lambda path, default=None: (
                               {'id': '456', 'status': 'completed'}
                               if str(path).endswith('operation.json')
                               else json.loads(self.state_path.read_text())
                               if self.state_path.exists() else default))):
            daily.run_reserved(snap)
        start.assert_called_once_with({'scope': 'all', 'confirm': True,
                                        'requested_by': 'migration-daily'})
        execute.assert_called_once_with(operation)
        self.assertEqual(daily.read()['status'], 'completed')
        self.assertEqual(daily.read()['checkpoint_id'], '999')
        self.assertEqual(daily.read()['operation_id'], '456')

    def test_failed_checkpoint_verification_never_imports(self):
        daily.record(date='2026-11-01', status='snapshot_running')
        with (patch.object(daily.console, 'execute'),
              patch.object(daily.console, 'state', return_value={
                  'operation': {'id': '123', 'status': 'completed',
                                'snapshot_ids': {'all': '999'}}}),
              patch.object(daily.console, 'checkpoints', return_value=[]),
              patch.object(daily.control, 'start') as start):
            daily.run_reserved({'id': '123'})
        start.assert_not_called()
        self.assertEqual(daily.read()['status'], 'checkpoint_unverified')


if __name__ == '__main__':
    unittest.main()
