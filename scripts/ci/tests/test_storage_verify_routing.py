"""Regression: Storage uses its dedicated authorization and S3 smoke path."""
from pathlib import Path
import unittest

VERIFY = Path(__file__).resolve().parents[1] / "verify_plan.py"


class StorageVerifyRouting(unittest.TestCase):
    def test_storage_does_not_enter_generic_json_health_check(self):
        source = VERIFY.read_text()
        self.assertIn("'storage-drive','storage-api'", source)
        self.assertIn("verify_storage_pilot(host)", source)
        self.assertIn('"storage-api"', source)
        self.assertIn('health = json.loads(body)', source)

    def test_canonical_storage_gate_smoke(self):
        source = (VERIFY.parent.parent / "verify-stand.sh").read_text()
        # Storage V1 intentionally exposes only a static shell. Object bytes
        # and listings must still be rejected by the separate server gates.
        self.assertIn('check_body "Anonymous Storage static SPA" http://127.0.0.1/storage/', source)
        self.assertIn("http://127.0.0.1/storage/v1 200", source)
        self.assertIn('storage_asset="$(curl_stand -fsS http://127.0.0.1/storage/', source)
        self.assertIn('check_status "Anonymous Storage static JS"', source)
        self.assertIn("http://127.0.0.1/api/storage/items 302", source)
        self.assertIn("http://127.0.0.1/api/storage/v1/items 401", source)
        self.assertIn("http://127.0.0.1/_storage_pilot_gate 404", source)
        self.assertIn("http://127.0.0.1/_storage_v1_gate 404", source)
        self.assertIn("http://127.0.0.1:8110/ 302", source)
        self.assertNotIn("http://127.0.0.1/storage/ 302", source)
        self.assertNotIn("http://127.0.0.1:8110/_storage_pilot_gate", source)


if __name__ == "__main__":
    unittest.main()
