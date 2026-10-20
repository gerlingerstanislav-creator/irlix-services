import sys
from pathlib import Path
import subprocess
import tempfile
import json
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import verify_plan as v


class FinanceVerificationTests(unittest.TestCase):
    def test_finance_and_direct_source_releases_verify_finance(self):
        for service in ("finance", "finance-web", "clients", "timesheets", "employees"):
            with self.subTest(service=service):
                self.assertEqual(v.verification_services([service]), {service, "finance", "finance-web"})

    def test_unrelated_frontend_and_worker_releases_stay_selective(self):
        for services in ([], ["clients-web"], ["timesheets-web"], ["employees-events"],
                         ["vacations", "recruitment", "portal"]):
            with self.subTest(services=services):
                self.assertEqual(v.verification_services(services), set(services))

    def test_source_only_plan_probes_finance_health_and_integrations(self):
        components = [c for c in v.registry()["components"] if c["id"] in ("clients", "finance", "finance-web")]
        for finance_status in ("ok", "unavailable"):
            with self.subTest(finance_status=finance_status), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                (root / ".ci").mkdir()
                (root / ".ci/deploy-plan.json").write_text(json.dumps({"services": ["clients"], "verify": []}))
                (root / ".env").write_text("IRLIX_PUBLIC_URL=https://example.invalid\n")

                def output(command, **kwargs):
                    if "ps" in command:
                        return "synthetic-container"
                    if "inspect" in command:
                        return "true"
                    if "%{http_code}" in command:
                        return "401"
                    if command[-1].endswith("/finance/"):
                        return "<html>synthetic</html>"
                    return json.dumps({"status": finance_status if command[-1].endswith("/finance/health") else "ok"})

                with patch.object(v, "ROOT", root), patch.object(v, "registry", return_value={"components": components}), \
                        patch.object(v, "compose_command", return_value=["docker", "compose"]), \
                        patch.object(v.subprocess, "check_output", side_effect=output) as request, \
                        patch.object(v.subprocess, "run") as run:
                    if finance_status == "ok":
                        v.main()
                        self.assertEqual(run.call_count, 1)
                        self.assertIn("tests/finance_integrations_smoke.php", run.call_args.args[0])
                    else:
                        with self.assertRaises(AssertionError):
                            v.main()
                        run.assert_not_called()
                    self.assertTrue(any(call.args[0][-1].endswith("/finance/health") for call in request.call_args_list))
                    if finance_status == "ok":
                        self.assertTrue(any(call.args[0][-1].endswith("/finance/") for call in request.call_args_list))

    @patch.object(v.subprocess, "run")
    @patch.object(v.subprocess, "check_output", return_value="401")
    def test_anonymous_checks_discard_bodies_then_run_in_container(self, request, run):
        v.verify_finance(["docker", "compose"], [], "example.invalid")
        self.assertEqual(request.call_count, 4)
        for call in request.call_args_list:
            command = call.args[0]
            self.assertIn("/dev/null", command)
            self.assertNotIn("-L", command)
            self.assertIn("--max-time", command)
            self.assertNotIn("Authorization", " ".join(command))
        run.assert_called_once_with(
            ["docker", "compose", "exec", "-T", "finance", "php",
             "tests/finance_integrations_smoke.php"], cwd=v.ROOT,
            stdin=subprocess.DEVNULL, check=True)

    @patch.object(v.subprocess, "run")
    def test_unexpected_anonymous_status_fails_closed(self, run):
        for status in ("200", "302", "403", "404", "500", "000"):
            with self.subTest(status=status), patch.object(v.subprocess, "check_output", return_value=status):
                with self.assertRaises(AssertionError):
                    v.verify_finance(["docker", "compose"], [], "example.invalid")
        run.assert_not_called()


if __name__ == "__main__":
    unittest.main()
