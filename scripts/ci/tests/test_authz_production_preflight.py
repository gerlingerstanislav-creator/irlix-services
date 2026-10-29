"""Safe, opt-in production matrix preflight must never imply cutover acceptance."""
from pathlib import Path
from unittest.mock import patch
import importlib.util
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[3]
SOURCE = ROOT / "scripts/authz/production_preflight.py"
spec = importlib.util.spec_from_file_location("authz_preflight", SOURCE)
preflight = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preflight)


class ProductionPreflightTests(unittest.TestCase):
    @staticmethod
    def ready():
        return {
            "jwt_gate_consistent": True,
            "keycloak_role_sync": "ready",
            "all_local_flags_disabled": True,
            "missing_critical_grants": {},
            "local_v2": {name: {
                "flag": "disabled",
                "matrix": {"status": "ok", "rows": 2},
                "critical_coverage": {"status": "ok", "missing_grants": []},
            } for name in preflight.SERVICE_ORDER},
        }

    def test_seven_service_readiness_must_be_complete(self):
        class Probe:
            def collect(self, compose, sudo):
                return payload
        payload = self.ready()
        self.assertEqual(set(preflight.check_readiness(Probe(), ["docker", "compose"])),
                         set(preflight.SERVICE_ORDER))
        for field in ("jwt_gate_consistent", "all_local_flags_disabled"):
            payload = self.ready()
            payload[field] = False
            with self.assertRaises(RuntimeError):
                preflight.check_readiness(Probe(), ["docker", "compose"])
        payload = self.ready()
        payload["local_v2"]["finance"]["matrix"]["status"] = "unverified"
        with self.assertRaises(RuntimeError):
            preflight.check_readiness(Probe(), ["docker", "compose"])
        payload = self.ready()
        del payload["local_v2"]["clients"]
        with self.assertRaises(RuntimeError):
            preflight.check_readiness(Probe(), ["docker", "compose"])

    def test_isolated_restore_always_uses_new_database(self):
        with tempfile.TemporaryDirectory() as tmp:
            archive = Path(tmp) / "matrix.dump"
            archive.write_bytes(b"private archive")
            commands = []
            def command(compose, script, *args, **kwargs):
                commands.append((script, args, kwargs.get("stdin") is not None))
                return True
            with patch.object(preflight, "container_command", side_effect=command), \
                 patch.object(preflight, "restored_matrix_count", return_value=22):
                preflight.restore_into_disposable_database(
                    ["docker", "compose"], archive, "clients", "client_contour_permissions", 22)
            self.assertEqual(len(commands), 4)
            self.assertIn("CREATE DATABASE", commands[0][0])
            self.assertIn("CREATE SCHEMA", commands[1][0])
            self.assertIn("pg_restore", commands[2][0])
            self.assertTrue(commands[2][2])
            self.assertIn("DROP DATABASE", commands[3][0])
            self.assertTrue(all(c[1][0] == commands[0][1][0] for c in commands))

    def test_failure_restores_no_live_data_and_drops_test_database(self):
        with tempfile.TemporaryDirectory() as tmp:
            archive = Path(tmp) / "matrix.dump"
            archive.write_bytes(b"private archive")
            commands = []
            def command(compose, script, *args, **kwargs):
                commands.append(script)
                return "pg_restore" not in script
            with patch.object(preflight, "container_command", side_effect=command):
                with self.assertRaisesRegex(RuntimeError, "restore failed"):
                    preflight.restore_into_disposable_database(
                        ["docker", "compose"], archive, "finance", "finance_role_permissions", 8)
            self.assertIn("DROP DATABASE", commands[-1])

    def test_restore_row_count_must_match_source(self):
        with tempfile.TemporaryDirectory() as tmp:
            archive = Path(tmp) / "matrix.dump"
            archive.write_bytes(b"private archive")
            with patch.object(preflight, "container_command", return_value=True), \
                 patch.object(preflight, "restored_matrix_count", return_value=7):
                with self.assertRaisesRegex(RuntimeError, "restore failed"):
                    preflight.restore_into_disposable_database(
                        ["docker", "compose"], archive, "finance", "finance_role_permissions", 8)

    def test_no_implicit_enable_or_acceptance(self):
        script = SOURCE.read_text()
        self.assertIn('"signed_role_object_e2e": "NOT TESTED"', script)
        self.assertIn('"per_service_live_flag_rollback": "NOT TESTED"', script)
        self.assertIn('"cutover_accepted": False', script)
        for forbidden in ("--force-recreate", "docker compose up", "IRLIX_FINANCE_LOCAL_AUTHZ_ENABLED=true"):
            self.assertNotIn(forbidden, script)

    def test_production_trigger_is_opt_in_and_sha_bound(self):
        workflow = (ROOT / ".github/workflows/ci.yml").read_text()
        marker = "- name: Protected AuthZ V2 snapshot and isolated restore preflight"
        self.assertEqual(workflow.count(marker), 1)
        step = workflow.split(marker, 1)[1].split("      - name:", 1)[0]
        self.assertIn("github.ref == 'refs/heads/main'", step)
        self.assertIn("github.event_name == 'push'", step)
        self.assertIn("[authz-preflight]", step)
        self.assertIn("needs.deploy.result == 'skipped'", step)
        self.assertIn("needs.deploy.result == 'success'", step)
        self.assertIn("< scripts/authz/production_preflight.py", step)
        self.assertIn("sudo -n python3 -", step)
        self.assertNotIn("docker compose up", step)


if __name__ == "__main__":
    unittest.main()
