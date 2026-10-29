"""Manual irreversible deletion is only activated in the restricted Storage pilot.

This test guards the difference between the operator-authorized test mode and
a general production default. It does not execute real file deletion.
"""
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[3]
DEPLOY = (ROOT / "scripts/deploy-from-ghcr.sh").read_text()
COMPOSE = (ROOT / "docker-compose.storage.yml").read_text()
API = (ROOT / "services/storage-api/app/v1_api.py").read_text()
UI = (ROOT / "apps/storage/src/StorageV1.vue").read_text()


class StorageDebugPurgeActivationTests(unittest.TestCase):
    def test_pilot_flag_has_a_fail_closed_compose_default(self):
        self.assertIn(
            "STORAGE_V1_PERMANENT_DELETE_ENABLED: "
            "${STORAGE_V1_PERMANENT_DELETE_ENABLED:-0}",
            COMPOSE,
        )
        self.assertIn('os.environ.get("STORAGE_V1_PERMANENT_DELETE_ENABLED", "0") == "1"', API)

    def test_one_time_activation_requires_an_enabled_restricted_pilot(self):
        self.assertIn('if [ "$storage_debug_target" = true ] &&', DEPLOY)
        self.assertIn("grep -q '^STORAGE_V1_SQLITE_DEBUG_BOOTSTRAPPED=1$' .env", DEPLOY)
        self.assertIn("grep -q '^STORAGE_V1_ENABLED=1$' .env", DEPLOY)
        self.assertIn("grep -q '^STORAGE_V1_ACCESS_ENABLED=1$' .env", DEPLOY)
        self.assertIn("! $SUDO grep -q '^STORAGE_V1_TEST_PURGE_BOOTSTRAPPED=1$' .env", DEPLOY)
        self.assertEqual(DEPLOY.count("upsert_env STORAGE_V1_PERMANENT_DELETE_ENABLED 1"), 1)
        self.assertIn("upsert_env STORAGE_V1_TEST_PURGE_BOOTSTRAPPED 1", DEPLOY)
        self.assertIn("storage-api", DEPLOY)
        self.assertEqual(subprocess.run(["sh", "-n", str(ROOT / "scripts/deploy-from-ghcr.sh")],
                                        check=False).returncode, 0)

    def test_action_remains_in_trash_with_exact_name_confirmation(self):
        self.assertIn("mode.value === 'trash' && result.permanent_delete_enabled === true", UI)
        self.assertIn("item.kind === 'file'", UI)
        self.assertIn("purgeConfirmName.value !== item.name", UI)
        self.assertIn('if (!item || busy.value || purgeConfirmName.value !== item.name) return;', UI)
        self.assertIn('if (item[1] != "file" or item[6] != "trashed")', API.replace("if item[1] != \"file\" or item[6] != \"trashed\":", 'if (item[1] != "file" or item[6] != "trashed")'))


if __name__ == "__main__":
    unittest.main()
