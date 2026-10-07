"""Keep release checks bounded and cancellable without weakening their success gates."""
import re
import unittest
from pathlib import Path

WORKFLOW = Path(__file__).resolve().parents[3] / '.github/workflows/ci.yml'

class WorkflowLiveness(unittest.TestCase):
    def test_migration_http_preserves_container_environment(self):
        import json
        dockerfile = WORKFLOW.parents[2] / 'services/migration/Dockerfile'
        command = re.search(r"(?m)^CMD (.+)$", dockerfile.read_text())
        self.assertIsNotNone(command)
        self.assertIn('--no-reload', json.loads(command.group(1)))

    def test_every_job_has_explicit_time_limit(self):
        workflow = WORKFLOW.read_text()
        jobs = re.split(r"(?m)^  [a-z_]+:\n", workflow.split('jobs:\n', 1)[1])[1:]
        self.assertTrue(jobs)
        for job in jobs:
            match = re.search(r"(?m)^    timeout-minutes: (\d+)$", job)
            self.assertIsNotNone(match)
            self.assertLessEqual(int(match.group(1)), 75)

    def test_failure_gates_are_cancellable(self):
        workflow = WORKFLOW.read_text()
        self.assertNotIn('always()', workflow)
        self.assertGreaterEqual(workflow.count('!cancelled()'), 2)
        self.assertIn('test "$CHANGES_RESULT" = success && test "$BUILD_RESULT" = success', workflow)
        self.assertNotIn('continue-on-error:', workflow)

    def test_long_running_release_steps_are_bounded(self):
        workflow = WORKFLOW.read_text()
        for name in ['Configure SSH', 'Upload release and plan', 'Deploy from GHCR', 'Bootstrap Keycloak', 'Verify stand', 'Verify deployed Employees navigation in Chromium', 'Snapshot Employees before test migration']:
            self.assertRegex(workflow, r'- name: '+re.escape(name)+r'\n        timeout-minutes: \d+')
        self.assertIn('ServerAliveCountMax 3', workflow)
        self.assertIn('ConnectTimeout 15', workflow)
