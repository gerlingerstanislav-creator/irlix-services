"""Guardrails for real production Storage SSO navigation verification."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]


class StorageAuthenticatedNavigationChecks(unittest.TestCase):
    def test_full_session_gate_is_in_release_smoke(self):
        script = (ROOT / "scripts/ci/verify_plan.py").read_text()
        self.assertIn("def verify_storage_authenticated_navigation(", script)
        self.assertIn("verify_storage_authenticated_navigation(compose, sudo, host)", script)
        self.assertIn("tests/storage_pilot_session_runtime.php", script)
        self.assertIn("urllib.request.HTTPRedirectHandler", script)
        self.assertIn('"http://127.0.0.1/storage/"', script)
        self.assertIn('"http://127.0.0.1/api/storage/items?folder="', script)
        self.assertIn('assert status == 200', script)

    def test_employees_runtime_copies_storage_gate_and_auth_smoke(self):
        dockerfile = (ROOT / "services/employees/Dockerfile").read_text()
        self.assertIn(
            "COPY app/Support/StoragePilotGate.php /app/app/Support/StoragePilotGate.php",
            dockerfile,
        )
        self.assertIn(
            "COPY tests/storage_pilot_session_runtime.php /app/tests/storage_pilot_session_runtime.php",
            dockerfile,
        )

    def test_never_write_session_cookie_to_logs(self):
        script = (ROOT / "scripts/ci/verify_plan.py").read_text()
        php = (ROOT / "services/employees/tests/storage_pilot_session_runtime.php").read_text()
        self.assertIn("capture_output=True", script)
        self.assertNotIn("print(secret_cookie)", script)
        self.assertNotIn("echo $token", php)
        self.assertIn("fwrite(STDOUT, $token);", php)
        self.assertIn("isHttpOnly()", php)


if __name__ == "__main__":
    unittest.main()
