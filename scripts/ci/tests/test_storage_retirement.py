"""Guardrails for retiring only the obsolete Filestash UI during a release."""
from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[3]


class FilestashRetirementTests(unittest.TestCase):
    def test_only_own_drive_and_s3_are_deployed(self):
        registry = json.loads((ROOT / "infra/ci/services.json").read_text())
        components = {x["id"]: x for x in registry["components"]}
        self.assertNotIn("storage-filestash", components)
        self.assertNotIn("storage-web", [x["service"] for x in components.values()])
        self.assertEqual(components["storage"]["service"], "storage-drive")
        self.assertEqual(components["storage-api"]["service"], "storage-api")
        self.assertEqual(components["storage-s3"]["service"], "storage-s3")
        compose = (ROOT / "docker-compose.storage.yml").read_text()
        self.assertIn("  storage-s3:", compose)
        self.assertIn("  storage-api:", compose)
        self.assertIn("  storage-drive:", compose)
        self.assertNotIn("  storage-web:", compose)
        self.assertNotIn("storage_filestash_state:", compose)
        images = (ROOT / "docker-compose.images.yml").read_text()
        self.assertNotIn("  storage-web:", images)
        self.assertNotIn("irlix-storage-web", images)

    def test_live_map_has_no_stale_filestash_node_or_dependency(self):
        source = (ROOT / "apps/portal/src/serviceMapData.js").read_text()
        self.assertNotIn("storage-filestash", source)
        self.assertIn("['storage','storage-api']", source)
        self.assertIn("['storage-api','storage-s3-api']", source)
        self.assertIn("['storage-api','employees-api']", source)

    def test_release_safely_retires_only_legacy_dedicated_state(self):
        deploy = (ROOT / "scripts/deploy-from-ghcr.sh").read_text()
        script = (ROOT / "scripts/storage/retire-filestash.sh").read_text()
        self.assertIn('sh scripts/storage/retire-filestash.sh', deploy)
        self.assertIn('[ "$service" = storage-drive ]', deploy)
        self.assertIn("http://127.0.0.1:8115/", script)
        self.assertIn("http://127.0.0.1:8114/health", script)
        self.assertIn("label=com.docker.compose.service=storage-web", script)
        self.assertIn("storage_filestash_state", script)
        self.assertIn("com.docker.compose.project", script)
        self.assertIn("com.docker.compose.volume", script)
        self.assertNotIn("docker volume prune", script)
        self.assertNotIn("docker system prune", script)
        self.assertNotIn("docker rm -f", script)
        self.assertNotIn("storage-s3", script)
        self.assertNotIn("STORAGE_S3_DATA_DIR", script)

    def test_no_old_bootstrap_or_test_hooks(self):
        deploy = (ROOT / "scripts/deploy-from-ghcr.sh").read_text()
        verifier = (ROOT / "scripts/ci/verify_plan.py").read_text()
        self.assertNotIn("ensure-filestash-pilot.py", deploy)
        self.assertNotIn("ensure-filestash-pilot.py", verifier)
        self.assertNotIn("8111/", verifier)
        self.assertNotIn("storage-filestash", verifier)
        self.assertFalse((ROOT / "infra/storage/filestash/Dockerfile").exists())
        self.assertFalse((ROOT / "scripts/storage/ensure-filestash-pilot.py").exists())


if __name__ == "__main__":
    unittest.main()
