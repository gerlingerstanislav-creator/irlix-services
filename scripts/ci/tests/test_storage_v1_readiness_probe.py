"""Readiness probe must never disclose credentials or enable V1."""
import contextlib
import importlib.util
import io
import pathlib
import subprocess
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).resolve().parents[2]
SOURCE = ROOT / "storage/inspect-v1-readiness.py"
spec = importlib.util.spec_from_file_location("storage_cutover_probe", SOURCE)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class ReadinessProbeTests(unittest.TestCase):
    @patch.object(mod.os, "geteuid", return_value=0)
    @patch.object(mod.subprocess, "run")
    def test_disabled_v1_reports_only_sanitized_fields(self, run, _):
        run.side_effect = [
            subprocess.CompletedProcess([], 0, "", ""),
            subprocess.CompletedProcess([], 0, '{"enabled":false,"db_configured":false,"schema":"not_configured","password":"never-print"}', ""),
            subprocess.CompletedProcess([], 0, '{"enabled":false,"token":"never-print"}', ""),
        ]
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            mod.main()
        self.assertIn('"db_configured": false', out.getvalue())
        self.assertIn("NOT TESTED", out.getvalue())
        self.assertNotIn("never-print", out.getvalue())
        self.assertEqual(run.call_count, 3)

    def test_probe_uses_read_only_schema_existence_query(self):
        self.assertIn("to_regclass('storage.objects')", mod.CHECK_API)
        self.assertIn("to_regclass('storage.file_versions')", mod.CHECK_API)
        self.assertNotIn("CREATE TABLE", mod.CHECK_API)
        self.assertNotIn("ALTER TABLE", mod.CHECK_API)
        self.assertNotIn("DROP TABLE", mod.CHECK_API)

    def test_verifier_runs_probe_even_without_storage_image_change(self):
        source = (ROOT / "ci/verify_plan.py").read_text()
        self.assertIn("inspect-v1-readiness.py", source)
        self.assertLess(source.index("inspect-v1-readiness.py"),
                        source.index("for service in sorted(selected):"))


if __name__ == "__main__":
    unittest.main()
