"""Production Clients V2 finalization diagnostics must never reveal private rows."""
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch
import subprocess

ROOT = Path(__file__).resolve().parents[3]
spec = importlib.util.spec_from_file_location(
    "clients_v2_finalizer_transport", ROOT / "scripts/authz/transfer_production_heads_clients.py"
)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class ClientsV2FinalizerDiagnosticsTests(unittest.TestCase):
    def test_allowlisted_static_finalizer_reason(self):
        raw = b"Warning: opaque private client information\n[clients-v2-finalize] Unreconciled Clients policy difference\n"
        self.assertEqual(mod.safe_reason(raw), "Unreconciled Clients policy difference")

    def test_private_stderr_not_reflected(self):
        private = b"role=nonpublic-sensitive-label, amount=12345"
        self.assertEqual(mod.safe_reason(private), "Unclassified Clients finalization error")

    def test_transport_errors_fail_closed_without_printing_stderr(self):
        def fake_run(command, **kwargs):
            if "employees" in command:
                return subprocess.CompletedProcess(command, 0, stdout=b'{"version":1,"production_aliases":["backend"]}', stderr=b'')
            return subprocess.CompletedProcess(command, 1, stdout=b'PRIVATE-POLICY-ROW',
                stderr=b'PRIVATE-EMPLOYEE-ID=123\n[clients-v2-finalize] Missing V2 role permission after import\n')
        with patch.object(mod.subprocess, "check_output", return_value="docker compose -f compose.yml"), patch.object(
            mod.subprocess, "run", side_effect=fake_run
        ):
            with self.assertRaisesRegex(RuntimeError, "Missing V2 role permission after import") as err:
                mod.run()
            self.assertNotIn("PRIVATE-", str(err.exception))

    def test_source_export_error_is_distinguished(self):
        with patch.object(mod.subprocess, "check_output", return_value="docker compose"), patch.object(
            mod.subprocess, "run", return_value=subprocess.CompletedProcess([], 1,
                 stdout=b'', stderr=b'real sensitive data')
        ):
            with self.assertRaisesRegex(RuntimeError, "Employees production department aliases unavailable or invalid"):
                mod.run()

    def test_active_head_alias_export_error_is_classified_without_private_payload(self):
        with patch.object(mod.subprocess, "check_output", return_value="docker compose"), patch.object(
            mod.subprocess, "run", return_value=subprocess.CompletedProcess([], 1,
                stdout=b'PRIVATE-ROLE', stderr=b'PRIVATE EMPLOYEE ID\\n[clients-head-export] missing-managed-alias\\n')
        ):
            with self.assertRaisesRegex(RuntimeError, "Production department with an assigned manager needs a canonical alias") as caught:
                mod.run()
            self.assertNotIn("PRIVATE", str(caught.exception))

    def test_employees_only_release_resumes_one_time_clients_cleanup(self):
        deploy = (ROOT / "scripts/deploy-from-ghcr.sh").read_text()
        self.assertIn('clients|employees) clients_v2_finalize=true', deploy)
        self.assertIn('IRLIX_CLIENTS_V2_ROLE_CLEANUP_DONE', deploy)
        self.assertLess(deploy.index('transfer_clients_timesheets.py'),
                        deploy.index('transfer_production_heads_clients.py'))
        self.assertLess(deploy.index('transfer_production_heads_clients.py'),
                        deploy.index('upsert_env IRLIX_CLIENTS_V2_ROLE_CLEANUP_DONE true'))


if __name__ == "__main__":
    unittest.main()
