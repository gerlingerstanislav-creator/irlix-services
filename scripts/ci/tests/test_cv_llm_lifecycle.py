"""Contract + fake-Docker tests for safe, host-only CV model resource control."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[3]
CONTROLLER = ROOT / "scripts/reconcile-cv-llm.sh"
INSTALL = ROOT / "scripts/install-cv-llm-controller.sh"
DEPLOY = ROOT / "scripts/deploy-from-ghcr.sh"


class CvLlmLifecycleTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.root = self.base / "app"
        (self.root / "scripts/ci").mkdir(parents=True)
        (self.root / ".env").write_text("CV_LLM_PROVIDER=local\n")
        (self.root / "scripts/ci/service_plan.py").write_text(
            "import sys\n"
            "assert sys.argv[1] == 'compose-args'\n"
            "print('docker compose')\n"
        )
        self.bin = self.base / "bin"
        self.bin.mkdir()
        id_fake = self.bin / "id"
        id_fake.write_text("#!/bin/sh\n[ \"$1\" = -u ] && { echo 0; exit 0; }\nexit 1\n")
        id_fake.chmod(0o755)
        docker = self.bin / "docker"
        docker.write_text("""#!/usr/bin/env python3
import os
from pathlib import Path
import sys
a = sys.argv[1:]
state = Path(os.environ['CV_TEST_STATE'])
log = Path(os.environ['CV_TEST_LOG'])
provider = os.environ.get('CV_TEST_PROVIDER', 'gigachat')
if a[:1] == ['ps'] and any('service=cv-converter' in arg for arg in a):
    if os.environ.get('CV_TEST_API', 'up') == 'up':
        print('api-container')
    sys.exit(0)
if a[:1] == ['ps'] and any('service=cv-llm' in arg for arg in a):
    if state.read_text() == 'running':
        print('llm-container')
    sys.exit(0)
if a[:3] == ['compose','exec','-T']:
    if provider == 'error':
        sys.exit(1)
    print(provider)
    sys.exit(0)
if a[:2] == ['compose','stop']:
    if a[-1] != 'cv-llm':
        sys.exit(2)
    log.write_text(log.read_text() + 'stop\\n')
    state.write_text('stopped')
    sys.exit(0)
if a[:2] == ['compose','up']:
    if a[-1] != 'cv-llm':
        sys.exit(2)
    log.write_text(log.read_text() + 'start\\n')
    state.write_text('running')
    sys.exit(0)
print('Unexpected fake Docker invocation:', a, file=sys.stderr)
sys.exit(3)
""")
        docker.chmod(0o755)
        self.state = self.base / "state"
        self.log = self.base / "docker.log"
        self.log.write_text("")

    def reconcile(self, provider, state, api="up"):
        self.state.write_text(state)
        self.log.write_text("")
        env = {
            **os.environ,
            "PATH": str(self.bin) + os.pathsep + os.environ.get("PATH", ""),
            "IRLIX_SERVICES_ROOT": str(self.root),
            "CV_TEST_PROVIDER": provider,
            "CV_TEST_STATE": str(self.state),
            "CV_TEST_LOG": str(self.log),
            "CV_TEST_API": api,
        }
        result = subprocess.run(
            ["sh", str(CONTROLLER)], env=env, capture_output=True, text=True, timeout=15
        )
        return result, self.state.read_text(), self.log.read_text()

    def test_gigachat_stops_running_local_model(self):
        result, state, log = self.reconcile("gigachat", "running")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(state, "stopped")
        self.assertEqual(log, "stop\n")

    def test_external_provider_never_starts_model(self):
        for provider in ("gigachat", "yandex", "mws", "openai_compatible"):
            with self.subTest(provider=provider):
                result, state, log = self.reconcile(provider, "stopped")
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(state, "stopped")
                self.assertEqual(log, "")

    def test_local_starts_model_only_when_stopped(self):
        result, state, log = self.reconcile("local", "stopped")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(state, "running")
        self.assertEqual(log, "start\n")
        result, state, log = self.reconcile("local", "running")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(log, "")

    def test_invalid_provider_fails_closed_without_stopping_or_starting(self):
        result, state, log = self.reconcile("unknown", "running")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(state, "running")
        self.assertEqual(log, "")

    def test_unreadable_persisted_provider_fails_closed(self):
        result, state, log = self.reconcile("error", "running")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(state, "running")
        self.assertEqual(log, "")

    def test_no_api_defers_without_impacting_model(self):
        result, state, log = self.reconcile("gigachat", "running", api="down")
        self.assertEqual(result.returncode, 0)
        self.assertEqual(state, "running")
        self.assertEqual(log, "")

    def test_shells_are_parseable_and_lifecycle_integrated(self):
        for script in (CONTROLLER, INSTALL, DEPLOY):
            with self.subTest(script=script.name):
                subprocess.run(["sh", "-n", str(script)], check=True)
        compose = (ROOT / "docker-compose.cv.yml").read_text()
        self.assertNotIn("cv-llm: { condition: service_started }", compose)
        deploy = DEPLOY.read_text()
        self.assertIn("sh scripts/install-cv-llm-controller.sh", deploy)
        self.assertIn("local LLM stopped ($active_cv_provider selected)", deploy)
        self.assertIn("CV local model must be stopped", deploy)
        # No provider-agnostic health request that wakes the local model.
        before_local = deploy.split('case "$active_cv_provider" in', 1)[1].split("local)", 1)[0]
        self.assertNotIn("/api/health/llm", before_local)
        self.assertIn("local)", deploy)
        self.assertIn('from app.settings import load_settings', CONTROLLER.read_text())
        controller = CONTROLLER.read_text()
        self.assertNotIn("docker volume rm", controller)
        self.assertNotIn("docker rm", controller)
        self.assertNotIn("docker socket", controller.lower())
        installer = INSTALL.read_text()
        self.assertIn("OnUnitInactiveSec=20s", installer)
        self.assertIn("NoNewPrivileges=true", installer)
        self.assertIn("systemctl start irlix-cv-llm-reconcile.service", installer)


if __name__ == "__main__":
    unittest.main()
