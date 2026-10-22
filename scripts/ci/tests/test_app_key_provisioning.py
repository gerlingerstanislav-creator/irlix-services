"""Offline safety checks for server-managed Laravel APP_KEY rollout."""
import base64
import importlib.util
from pathlib import Path
import unittest

PATH = Path(__file__).resolve().parents[2] / "provision-app-keys.py"
spec = importlib.util.spec_from_file_location("provision_app_keys", PATH)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class AppKeyProvisioningTest(unittest.TestCase):
    def setUp(self):
        self.old = "base64:" + base64.b64encode(b"a" * 32).decode()
        self.other = "base64:" + base64.b64encode(b"b" * 32).decode()

    def stub(self, project, aliases):
        if "timesheets-reconcile" in aliases:
            return [self.old, self.other]  # alias drift must not break old data
        return [self.old]

    def test_first_rollout_rotates_and_remembers_old_values(self):
        content = "TIMESHEETS_APP_KEY=PROVISION_ON_SERVER\nOTHER_VAR=unchanged\n"
        result, counts = module.provision(content, "irlix-services", self.stub)
        saved = module.env_values(result)
        self.assertTrue(module.valid_key(saved["TIMESHEETS_APP_KEY"]))
        self.assertNotEqual(saved["TIMESHEETS_APP_KEY"], self.old)
        self.assertIn(self.old, saved["TIMESHEETS_APP_PREVIOUS_KEYS"])
        self.assertIn(self.other, saved["TIMESHEETS_APP_PREVIOUS_KEYS"])
        self.assertEqual(counts["TIMESHEETS"], 2)
        self.assertIn("OTHER_VAR=unchanged", result)

    def test_idempotent_across_redeploys(self):
        result, _ = module.provision("", "irlix-services", self.stub)
        again, _ = module.provision(result, "irlix-services", self.stub)
        self.assertEqual(result, again)

    def test_reject_unknown_key_and_duplicate_env(self):
        with self.assertRaises(RuntimeError):
            module.provision("EMPLOYEES_APP_KEY=unknown-value\n", "irlix-services", self.stub)
        with self.assertRaises(RuntimeError):
            module.provision("EMPLOYEES_APP_KEY=one\nEMPLOYEES_APP_KEY=two\n", "irlix-services", self.stub)

    def test_old_key_source_failure_does_not_commit_changes(self):
        def failure(project, aliases):
            raise RuntimeError("cannot inspect")
        with self.assertRaises(RuntimeError):
            module.provision("", "irlix-services", failure)

    def test_existing_managed_key_uses_previous_alias_keys(self):
        current = module.new_key()
        result, _ = module.provision(
            f"TIMESHEETS_APP_KEY={current}\nTIMESHEETS_APP_PREVIOUS_KEYS={self.old}\n",
            "irlix-services", self.stub
        )
        val = module.env_values(result)
        self.assertEqual(val["TIMESHEETS_APP_KEY"], current)
        self.assertEqual(val["TIMESHEETS_APP_PREVIOUS_KEYS"].split(","), [self.old, self.other])


    def test_compose_has_no_literal_application_keys(self):
        root = PATH.parents[1]
        for path in ("docker-compose.yml", "docker-compose.override.yml",
                     "docker-compose.equipment.yml"):
            with self.subTest(file=path):
                data = (root / path).read_text(encoding="utf-8")
                self.assertNotRegex(data, r"(?m)^\s+APP_KEY:\s+base64:")
                lines = data.splitlines()
                for index, line in enumerate(lines):
                    if line.strip().startswith("APP_KEY:"):
                        self.assertLess(index + 1, len(lines))
                        self.assertIn("APP_PREVIOUS_KEYS:", lines[index + 1])
                for line in lines:
                    if line.strip().startswith("APP_PREVIOUS_KEYS:"):
                        self.assertRegex(line, r"^ *APP_PREVIOUS_KEYS: \$\{[A-Z_]+_APP_PREVIOUS_KEYS:-\}$")

    def test_key_format_and_unique_service_keys(self):
        result, _ = module.provision("", "irlix-services", lambda project, aliases: [])
        val = module.env_values(result)
        keys = [val[name + "_APP_KEY"] for name in module.SERVICES]
        self.assertEqual(len(set(keys)), len(module.SERVICES))
        self.assertTrue(all(map(module.valid_key, keys)))


if __name__ == "__main__":
    unittest.main()
