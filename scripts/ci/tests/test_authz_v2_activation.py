"""Production local AuthZ V2 activation must be explicit and reversible."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[3]
SOURCE = ROOT / "scripts/authz/activate_local_v2.py"
spec = importlib.util.spec_from_file_location("authz_local_v2_switch", SOURCE)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class IndependentAuthzV2ActivationTests(unittest.TestCase):
    def test_feature_flags_append_when_not_present_in_dotenv(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / ".env"
            path.write_text("SOME_PRIVATE_CONFIG=unchanged\n")
            with patch.object(mod, "ENV_FILE", path), patch.object(mod, "ROOT", Path(temp)):
                mod.set_flag("IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED", True)
                self.assertEqual(mod.env_value("IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED"), "true")
                mod.set_flag("IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED", False)
                self.assertEqual(mod.env_value("IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED"), "false")
            self.assertEqual(path.read_text().count("IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED="), 1)
            self.assertIn("SOME_PRIVATE_CONFIG=unchanged", path.read_text())

    def test_unknown_or_duplicate_flags_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / ".env"
            path.write_text("IRLIX_FINANCE_LOCAL_AUTHZ_ENABLED=false\n"
                            "IRLIX_FINANCE_LOCAL_AUTHZ_ENABLED=true\n")
            with patch.object(mod, "ENV_FILE", path), patch.object(mod, "ROOT", Path(temp)):
                with self.assertRaisesRegex(RuntimeError, "Duplicate"):
                    mod.set_flag("IRLIX_FINANCE_LOCAL_AUTHZ_ENABLED", True)
                with self.assertRaisesRegex(RuntimeError, "Unexpected"):
                    mod.set_flag("KEYCLOAK_CLIENT_SECRET", True)

    def test_compose_recreates_only_one_service_without_deps(self):
        with patch.object(mod.subprocess, "run", return_value=subprocess.CompletedProcess([], 0)) as sp:
            mod.run_compose(["docker", "compose"], "finance")
        command = sp.call_args.args[0]
        self.assertEqual(command[-1], "finance")
        self.assertIn("--no-build", command)
        self.assertIn("--no-deps", command)
        self.assertIn("--force-recreate", command)
        self.assertNotIn("postgres", command)

    def test_no_secrets_or_legacy_restore_and_one_service_at_a_time(self):
        content = SOURCE.read_text()
        self.assertIn("for service in ORDER:", content)
        self.assertIn("for service in reversed(switched):", content)
        self.assertIn("run_compose(compose, service)", content)
        self.assertIn("verified_protected_snapshots()", content)
        self.assertIn("IRLIX_FINANCE_V2_DENIAL_IMPORT_DONE", content)
        self.assertNotIn("docker compose down", content)
        self.assertNotIn("pg_restore", content)
        self.assertNotIn("finance_permission_audit", content)

    def test_cutover_opt_in_requires_production_successful_deploy(self):
        workflow = (ROOT / ".github/workflows/ci.yml").read_text()
        marker = "- name: Activate independent AuthZ V2 service matrices in production"
        self.assertEqual(workflow.count(marker), 1)
        block = workflow.split(marker, 1)[1].split("      - name:", 1)[0]
        self.assertIn("github.ref == 'refs/heads/main'", block)
        self.assertIn("github.event_name == 'push'", block)
        self.assertIn("needs.deploy.result == 'success'", block)
        self.assertIn("[authz-v2-cutover]", block)
        self.assertIn("activate_local_v2.py", block)
        self.assertNotIn("docker compose down", block)


if __name__ == "__main__":
    unittest.main()
