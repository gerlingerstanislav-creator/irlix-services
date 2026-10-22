"""Host-side storage maintenance integration checks.

Keep host deploy scripts out of migration-ops Docker images. This suite runs in
the repository-level CI planner checks rather than the container's unit suite.
"""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]
MAINTENANCE = ROOT / "scripts" / "server-storage-maintenance.sh"


class HostStorageMaintenanceTests(unittest.TestCase):
    def test_maintenance_supports_host_compose_v1_and_v2(self):
        script = MAINTENANCE.read_text(encoding="utf-8")
        self.assertIn("docker compose version", script)
        self.assertIn('docker-compose -f "$BASE/docker-compose.yml"', script)
        self.assertIn("migration_retention.py prune", script)


if __name__ == "__main__":
    unittest.main()
