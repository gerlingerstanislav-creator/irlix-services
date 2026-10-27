"""Static safety contracts for the opt-in storage pilot.

Only basic guardrails: passing these tests DOES NOT certify Docker, S3,
authorization, encryption, replication, consistency or backup restore.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]


class StoragePilotGuardrails(unittest.TestCase):
    def test_compose_isolated_and_requires_pinned_image(self):
        compose = (ROOT / "docker-compose.storage.pilot.yml").read_text()
        registry = (ROOT / "infra/ci/services.json").read_text()
        self.assertNotIn("docker-compose.storage.pilot.yml", registry)
        self.assertIn("STORAGE_S3_IMAGE:?", compose)
        self.assertIn("STORAGE_S3_DATA_DIR:?", compose)
        self.assertIn("STORAGE_S3_SECRET_KEY:?", compose)
        self.assertIn("STORAGE_ADMIN_PASSWORD:?", compose)
        self.assertIn("127.0.0.1:8333:8333", compose)
        self.assertNotRegex(compose, r"(?m)^\s*-\s*.*(?:8888|9333|23646):(?:8888|9333|23646)")
        self.assertNotIn("privileged: true", compose)
        self.assertNotIn("network_mode: host", compose)

    def test_preflight_must_prevent_root_disk_fallback(self):
        script = (ROOT / "scripts/storage/preflight-pilot.sh").read_text()
        self.assertIn("mountpoint -q", script)
        self.assertIn("@sha256:", script)
        self.assertIn("10485760", script)
        self.assertNotIn("mkdir -p", script)

    def test_copy_has_no_delete_and_verifies_payloads(self):
        script = (ROOT / "scripts/storage/cloud-copy-pilot.sh").read_text()
        self.assertRegex(script, r"rclone copy\b")
        self.assertIn("--immutable", script)
        self.assertRegex(script, r"rclone check\b")
        self.assertIn("--download", script)
        self.assertNotRegex(script, r"rclone (?:sync|purge|delete|moveto|move)\b")
        self.assertNotIn("--delete-excluded", script)

    def test_pilot_is_not_marked_as_real_backup(self):
        doc = (ROOT / "docs/STORAGE_ARCHITECTURE.md").read_text()
        self.assertIn("NOT production-ready", doc)
        self.assertIn("not the archive implementation", doc)
        self.assertIn("PostgreSQL metadata", doc)


if __name__ == "__main__":
    unittest.main()
