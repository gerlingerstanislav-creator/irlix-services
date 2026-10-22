import datetime as dt
import json
import os
import sqlite3
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import migration_retention as retention


class RetentionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.snapshots = self.root / 'snapshots' / 'console'
        self.snapshots.mkdir(parents=True)
        self.ops = self.root / 'ops'
        self.ops.mkdir()
        self.db = self.root / 'migration.sqlite'
        with sqlite3.connect(self.db) as db:
            db.execute('CREATE TABLE migration_runs(id INTEGER PRIMARY KEY,status TEXT)')
        (self.ops / 'console.json').write_text('{"operation":null}')
        (self.ops / 'status.json').write_text('{"state":"idle"}')
        for name, value in [('SNAPSHOTS', self.snapshots), ('OPS', self.ops),
                            ('DATA', self.db), ('POLICY', self.ops / 'snapshot-retention.json')]:
            patcher = patch.object(retention, name, value)
            patcher.start()
            self.addCleanup(patcher.stop)

    def point(self, scope, sid, held=False, valid=True):
        path = self.snapshots / scope / str(sid)
        path.mkdir(parents=True, exist_ok=True)
        (path / 'target.dump').write_bytes(b'dump')
        (path / 'metadata.sqlite').write_bytes(b'metadata')
        (path / 'manifest.json').write_text(json.dumps({
            'created_at_utc': (dt.datetime.now(dt.timezone.utc)
                               - dt.timedelta(minutes=20-sid)).isoformat(),
            'service': scope, 'snapshot_id': str(sid),
            'schemas': retention.SCOPES[scope], 'verification_version': 1,
            'checksums': {'target.dump': 'a', 'metadata.sqlite': 'b'} if valid else {},
        }))
        if held:
            (path / '.keep').touch()
        return path

    def test_defaults_to_two_per_scope_even_for_global(self):
        self.assertEqual(retention.get_count(), 2)
        for scope in retention.SCOPES:
            for n in range(1, 5):
                self.point(scope, n)
        result = retention.cleanup()
        self.assertEqual(result['removed'], 10)
        for scope in retention.SCOPES:
            self.assertEqual(sorted(p.name for p in (self.snapshots / scope).iterdir()), ['3', '4'])

    def test_custom_limit_persists_and_prunes_immediately(self):
        for i in range(1, 6):
            self.point('timesheets', i)
        result = retention.cleanup(3)
        self.assertEqual(result['removed'], 2)
        self.assertEqual(retention.get_count(), 3)
        self.assertEqual(json.loads(retention.POLICY.read_text())['count'], 3)
        self.point('timesheets', 6)
        result = retention.cleanup()
        self.assertEqual(result['removed'], 1)

    def test_protected_and_unverified_archives_are_preserved(self):
        self.point('clients', 1, held=True)
        self.point('clients', 2, valid=False)
        self.point('clients', 3)
        self.point('clients', 4)
        self.point('clients', 5)
        result = retention.cleanup()
        self.assertEqual(result['removed'], 1)
        self.assertEqual(result['skipped_protected'], 1)
        self.assertEqual(result['skipped_unverified'], 1)
        self.assertTrue((self.snapshots / 'clients' / '1').exists())
        self.assertTrue((self.snapshots / 'clients' / '2').exists())

    def test_active_run_refuses_change_and_deletion(self):
        for i in range(1, 5):
            self.point('employees', i)
        with sqlite3.connect(self.db) as db:
            db.execute("INSERT INTO migration_runs(status) VALUES ('running')")
        with self.assertRaises(RuntimeError):
            retention.cleanup(1)
        self.assertFalse(retention.POLICY.exists())
        self.assertEqual(len(list((self.snapshots / 'employees').iterdir())), 4)

    def test_invalid_policy_never_falls_back_to_destructive_default(self):
        retention.POLICY.write_text('not-json')
        with self.assertRaises(RuntimeError):
            retention.cleanup()
        for count in (0, 21, True, '2'):
            with self.assertRaises(ValueError):
                retention.validate_count(count)


if __name__ == '__main__':
    unittest.main()
