"""Regression: Storage uses its dedicated authorization and S3 smoke path."""
from pathlib import Path
import unittest

VERIFY = Path(__file__).resolve().parents[1] / "verify_plan.py"


class StorageVerifyRouting(unittest.TestCase):
    def test_storage_does_not_enter_generic_json_health_check(self):
        source = VERIFY.read_text()
        self.assertIn("'storage-drive','storage-api'", source)
        self.assertIn("verify_storage_pilot(host)", source)
        self.assertIn('"storage-filestash", "storage-api"', source)
        self.assertIn('health = json.loads(body)', source)


if __name__ == "__main__":
    unittest.main()
