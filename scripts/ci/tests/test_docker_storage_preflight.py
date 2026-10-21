"""Guard non-destructive cleanup of Docker image storage before cumulative deployment."""
import unittest
from pathlib import Path

SCRIPT = (Path(__file__).resolve().parents[3] / "scripts/deploy-from-ghcr.sh").read_text()

class DockerStoragePreflight(unittest.TestCase):
    def test_cleanup_only_on_low_space_before_pull(self):
        self.assertIn("docker info --format '{{.DockerRootDir}}'", SCRIPT)
        self.assertIn('-lt 8388608', SCRIPT)
        self.assertLess(SCRIPT.index('docker image prune --all --force'), SCRIPT.index('$COMPOSE pull $DEPLOY_SERVICES'))
        self.assertLess(SCRIPT.index('docker builder prune --all --force'), SCRIPT.index('$COMPOSE pull $DEPLOY_SERVICES'))

    def test_does_not_remove_containers_volumes_or_live_data(self):
        self.assertNotIn('docker volume prune', SCRIPT)
        self.assertNotIn('docker system prune', SCRIPT)
        self.assertNotIn('docker container prune', SCRIPT)
        self.assertNotIn('docker rm -f', SCRIPT)
        self.assertIn('docker image prune --all --force', SCRIPT)
        self.assertIn('docker builder prune --all --force', SCRIPT)
