"""Keep release checks bounded and cancellable without weakening their success gates."""
import re
import unittest
from pathlib import Path

WORKFLOW = Path(__file__).resolve().parents[3] / '.github/workflows/ci.yml'

class WorkflowLiveness(unittest.TestCase):
    def test_deploy_after_skipped_optional_rollback_check_keeps_success_gate(self):
        job = WORKFLOW.read_text().split('  deploy:\n', 1)[1].split('    steps:', 1)[0]
        condition = re.search(r'(?m)^    if: (.+)$', job).group(1)
        # Explicit status function avoids GitHub's implicit success() on skipped ancestors.
        self.assertIn('!cancelled()', condition)
        self.assertIn("needs.changes.result == 'success'", condition)
        self.assertIn("needs.verified.result == 'success'", condition)
        self.assertIn("github.ref == 'refs/heads/main'", condition)
        self.assertIn("needs.changes.outputs.deploy == 'true'", condition)

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
        for name in ['Configure SSH', 'Upload release and plan', 'Transfer verified GHCR images to server', 'Deploy verified images', 'Bootstrap Keycloak', 'Verify stand', 'Verify deployed Employees navigation in Chromium', 'Snapshot Employees before test migration']:
            self.assertRegex(workflow, r'- name: '+re.escape(name)+r'\n(?:        if: .+\n)?        timeout-minutes: \d+')
        self.assertIn('ServerAliveCountMax 3', workflow)
        self.assertIn('ConnectTimeout 15', workflow)

    def test_production_browser_requires_successful_deploy(self):
        workflow = WORKFLOW.read_text()
        self.assertIn("- name: Verify deployed Employees navigation in Chromium\n"
                      "        if: needs.deploy.result == 'success'", workflow)

    def test_ghcr_pull_has_bounded_retry_and_fails_before_container_recreation(self):
        deploy = (WORKFLOW.parents[2] / "scripts/deploy-from-ghcr.sh").read_text()
        self.assertIn("pull_attempt=1", deploy)
        self.assertIn('if [ "$pull_attempt" -ge 4 ]; then', deploy)
        self.assertIn('exit 1', deploy[deploy.index('pull_attempt=1'):deploy.index('compose_up_or_diagnose postgres')])
        self.assertLess(deploy.index('pull_attempt=1'), deploy.index('compose_up_or_diagnose postgres'))

    def test_ghcr_login_retries_dns_failure_before_pull_and_container_changes(self):
        deploy = (WORKFLOW.parents[2] / "scripts/deploy-from-ghcr.sh").read_text()
        login_retry = deploy.index("login_attempt=1")
        image_pull = deploy.index("pull_attempt=1")
        first_container_start = deploy.index("compose_up_or_diagnose postgres")
        self.assertLess(login_retry, image_pull)
        self.assertLess(image_pull, first_container_start)
        section = deploy[login_retry:image_pull]
        self.assertIn('while ! printf \'%s\' "$GHCR_TOKEN" | $SUDO docker login ghcr.io', section)
        self.assertIn('if [ "$login_attempt" -ge 4 ]; then', section)
        self.assertIn('exit 1', section)
        self.assertIn('login_attempt=$((login_attempt + 1))', section)
        self.assertNotIn('/etc/resolv.conf', section)

    def test_ci_runner_delivers_verified_images_without_server_registry_dns(self):
        workflow = WORKFLOW.read_text()
        transfer = (WORKFLOW.parents[2] / "scripts/ci/transfer-release-images.sh").read_text()
        deploy = (WORKFLOW.parents[2] / "scripts/deploy-from-ghcr.sh").read_text()
        self.assertLess(workflow.index("Transfer verified GHCR images to server"),
                        workflow.index("Deploy verified images"))
        self.assertIn("IRLIX_IMAGES_PRELOADED=1 bash -s", workflow)
        self.assertIn("python3 scripts/ci/service_plan.py image-refs", transfer)
        self.assertIn("docker pull \"$image\"", transfer)
        self.assertIn("docker save \"$image\" | gzip -1 | ssh", transfer)
        self.assertIn("docker load", transfer)
        self.assertIn("set -euo pipefail", transfer)
        self.assertIn('if [ "${IRLIX_IMAGES_PRELOADED:-}" = "1" ]; then', deploy)
        self.assertIn('docker image inspect "$image"', deploy)
        self.assertIn('if [ -n "$DEPLOY_SERVICES" ] && [ "${IRLIX_IMAGES_PRELOADED:-}" != "1" ]; then', deploy)
        self.assertNotIn("docker volume prune", transfer)
        self.assertNotIn("docker system prune", transfer)
        self.assertNotIn("/etc/resolv.conf", transfer)
