"""Prevent unrelated releases from being blocked by pilot Storage disk gate.

The actual production guard is extracted and evaluated with harmless stub
commands. No Docker, privileged host commands or disks are touched.
"""
import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[3]
DEPLOY = ROOT / "scripts/deploy-from-ghcr.sh"


class StoragePilotDeploymentGuardTests(unittest.TestCase):
    def guard(self) -> str:
        source = DEPLOY.read_text(encoding="utf-8")
        start = "# BEGIN STORAGE PILOT TARGET GUARD"
        end = "# END STORAGE PILOT TARGET GUARD"
        self.assertEqual(source.count(start), 1)
        self.assertEqual(source.count(end), 1)
        fragment = source.split(start, 1)[1].split(end, 1)[0]
        self.assertIn("$SUDO sh scripts/storage/provision-pilot.sh", fragment)
        return fragment.replace(
            "$SUDO sh scripts/storage/provision-pilot.sh",
            'printf "PILOT_PREFLIGHT_RAN\\n"',
        )

    def evaluate(self, full: bool, services: str):
        snippet = self.guard()
        env = os.environ.copy()
        env["FULL"] = "true" if full else "false"
        env["DEPLOY_SERVICES"] = services
        result = subprocess.run(
            ["sh", "-eu", "-c", snippet],
            capture_output=True, text=True, env=env, check=True,
        )
        return result.stdout

    def test_storage_capacity_guard_is_selective(self):
        for affected in ("clients timesheets finance equipment vacations recruitment specialists",
                         "portal", "employees-web", "storage-drive", "storage-web", ""):
            with self.subTest(affected=affected):
                self.assertNotIn("PILOT_PREFLIGHT_RAN", self.evaluate(False, affected))
        for storage in ("storage-api", "storage-s3"):
            with self.subTest(storage=storage):
                self.assertIn("PILOT_PREFLIGHT_RAN", self.evaluate(False, storage))
        self.assertIn("PILOT_PREFLIGHT_RAN", self.evaluate(True, "clients"))
        self.assertIn("PILOT_PREFLIGHT_RAN", self.evaluate(True, ""))

    def test_storage_capacity_threshold_is_never_weakened(self):
        provisioning = (ROOT / "scripts/storage/provision-pilot.sh").read_text()
        self.assertIn('-lt 8388608', provisioning)
        self.assertIn('exit 1', provisioning)
        self.assertIn("mkdir -p", provisioning)
        source = DEPLOY.read_text()
        self.assertLess(source.index("END STORAGE PILOT TARGET GUARD"),
                        source.index("compose_up_or_diagnose $DEPLOY_SERVICES"))

    def test_scripts_parse(self):
        for script in (DEPLOY, ROOT / "scripts/storage/provision-pilot.sh"):
            result = subprocess.run(["sh", "-n", str(script)], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == "__main__":
    unittest.main()
