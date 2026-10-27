"""Filestash pilot config bootstrapping regression tests (no Docker/secrets)."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "scripts/storage/ensure-filestash-pilot.py"
spec = importlib.util.spec_from_file_location("filestash_pilot", SCRIPT)
bootstrap = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bootstrap)


class FilestashPilotBootstrapTests(unittest.TestCase):
    def test_first_activation_preserves_admin_and_creates_s3_direct_pilot(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "config.json"
            path.write_text(json.dumps({
                "auth": {"admin": "synthetic-admin-hash"},
                "general": {"secret_key": "synthetic-existing-value"},
                "connections": [],
            }))
            expected = bootstrap.desired_config("irlix-storage-pilot")
            self.assertTrue(bootstrap.ensure_file(path, expected, False))
            saved = json.loads(path.read_text())
            self.assertEqual(saved["auth"]["admin"], "synthetic-admin-hash")
            self.assertEqual(saved["general"]["secret_key"], "synthetic-existing-value")
            self.assertEqual(saved["connections"], [{"type": "s3", "label": "irlix-pilot"}])
            self.assertTrue(bootstrap.compatible(saved, expected))
            mapped = json.loads(saved["middleware"]["attribute_mapping"]["params"])["irlix-pilot"]
            self.assertEqual(mapped["endpoint"], "http://storage-s3:8333")
            self.assertEqual(mapped["path"], "/irlix-storage-pilot/")
            self.assertNotIn("access_key_id", mapped)
            self.assertNotIn("secret_access_key", mapped)
            self.assertFalse(bootstrap.ensure_file(path, expected, True))
            self.assertTrue((path.parent / "config.json.pre-irlix-pilot.bak").exists())

    def test_existing_other_backend_cannot_be_overwritten(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "config.json"
            original = {"connections": [{"type": "sftp", "label": "custom"}]}
            path.write_text(json.dumps(original))
            with self.assertRaises(RuntimeError):
                bootstrap.ensure_file(path, bootstrap.desired_config("irlix-storage-pilot"), False)
            self.assertEqual(json.loads(path.read_text()), original)

    def test_unconfigured_instance_fails_check_instead_of_showing_login_error(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "config.json"
            path.write_text('{"connections":[]}')
            with self.assertRaises(RuntimeError):
                bootstrap.ensure_file(path, bootstrap.desired_config("irlix-storage-pilot"), True)

    def test_release_invokes_bootstrap_for_storage_and_not_for_unrelated_changes(self):
        script = (ROOT / "scripts/deploy-from-ghcr.sh").read_text()
        self.assertIn("storage-s3|storage-web", script)
        self.assertIn("ensure-filestash-pilot.py --env-file", script)
        compose = (ROOT / "docker-compose.storage.yml").read_text()
        self.assertIn('CONFIG_ENCRYPT: "false"', compose)
        self.assertIn("AWS_ACCESS_KEY_ID:", compose)
        self.assertIn("AWS_SECRET_ACCESS_KEY:", compose)


if __name__ == "__main__":
    unittest.main()
