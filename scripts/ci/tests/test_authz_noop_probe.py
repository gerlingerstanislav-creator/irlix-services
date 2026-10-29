"""Read-only AuthZ audit works on script-only main releases.

No change to Docker/Compose, application feature flags or server files.
"""
import pathlib
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[3]
WORKFLOW = ROOT / ".github/workflows/ci.yml"


class ProductionAuthzNoopProbeTests(unittest.TestCase):
    def test_opt_in_probe_runs_without_deploy(self):
        text = WORKFLOW.read_text()
        marker = "- name: Read-only production AuthZ diagnostics for script-only release"
        self.assertEqual(text.count(marker), 1)
        section = text.split(marker, 1)[1].split("      - name:", 1)[0]
        self.assertIn("needs.deploy.result == 'skipped'", section)
        self.assertIn("[authz-probe]", section)
        self.assertIn("< scripts/authz/inspect_runtime.py", section)
        self.assertIn('python3 - "$@"', section)
        self.assertIn('sudo -n python3 - "$@"', section)
        self.assertNotIn("docker compose up", section)
        self.assertNotIn("artisan migrate", section)
        self.assertNotIn("IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED=true", section)
        self.assertNotIn("scp ", section)
        # Auth-only probe may execute even when release deploy was intentionally
        # skipped; the normal deployment remains gated independently.
        self.assertIn("needs.deploy.result == 'skipped'", text)

    def test_runtime_probe_remains_aggregation_only(self):
        script = (ROOT / "scripts/authz/inspect_runtime.py").read_text()
        self.assertIn("legacy_timesheets_coverage", script)
        self.assertIn("employee_personal_view_own_in_legacy", script)
        self.assertIn("signed_role_object_e2e", script)
        self.assertIn("NOT TESTED", script)
        for forbidden in ("artisan migrate", "docker compose up", "delete from",
                          "truncate table", "IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED=true"):
            self.assertNotIn(forbidden, script)

if __name__ == "__main__":
    unittest.main()
