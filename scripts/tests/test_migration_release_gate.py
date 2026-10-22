"""Fail-closed production migration release preflight regression tests."""
import importlib.util
import json
import sqlite3
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / "ci" / "check_migration_release_safety.py"
spec = importlib.util.spec_from_file_location("migration_release_gate", SCRIPT)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class MigrationReleaseGateTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.ops = self.root / "ops"
        self.ops.mkdir()
        self.data = self.root / "migration.sqlite"
        self.patches = [patch.object(gate, "OPS", self.ops), patch.object(gate, "DATA", self.data)]
        for item in self.patches:
            item.start()
            self.addCleanup(item.stop)
        self.write("console.json", {"operation": {"id": "123", "status": "completed"}})
        self.write("status.json", {"state": "idle"})
        with sqlite3.connect(self.data) as db:
            db.execute("CREATE TABLE migration_runs (id INTEGER PRIMARY KEY, status TEXT)")
            db.execute("INSERT INTO migration_runs(status) VALUES ('completed')")

    def write(self, filename, value):
        (self.ops / filename).write_text(json.dumps(value), encoding="utf-8")

    def test_idle_operation_and_worker_allow_release(self):
        gate.check()

    def test_running_or_queued_coordinator_blocks_release(self):
        for status in ("running", "queued"):
            with self.subTest(status=status):
                self.write("console.json", {"operation": {"status": status}})
                with self.assertRaises(gate.UnsafeState):
                    gate.check()

    def test_running_checkpoint_blocks_release(self):
        self.write("status.json", {"state": "running"})
        with self.assertRaises(gate.UnsafeState):
            gate.check()

    def test_active_worker_rows_block_even_when_coordinator_is_idle(self):
        with sqlite3.connect(self.data) as db:
            db.execute("INSERT INTO migration_runs(status) VALUES ('running')")
        with self.assertRaises(gate.UnsafeState):
            gate.check()

    def test_missing_or_corrupt_state_fails_closed(self):
        (self.ops / "console.json").unlink()
        with self.assertRaises(gate.UnsafeState):
            gate.check()
        (self.ops / "console.json").write_text("{invalid json")
        with self.assertRaises(gate.UnsafeState):
            gate.check()

    def test_missing_metadata_db_fails_closed(self):
        self.data.unlink()
        with self.assertRaises(gate.UnsafeState):
            gate.check()


if __name__ == "__main__":
    unittest.main()
