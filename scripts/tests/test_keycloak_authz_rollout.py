"""Safety regression for production Keycloak authorization activation scripts.

These textual invariants deliberately guard the shell-level compose commands
that caused production Keycloak/Postgres dependency recreation and cURL 7.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]


class AuthorizationRolloutSafety(unittest.TestCase):
    def test_activation_never_restarts_compose_dependencies(self):
        script = (ROOT / "scripts/activate-keycloak-authorization.sh").read_text()
        commands = [line for line in script.splitlines()
                    if "$COMPOSE up -d" in line]
        self.assertGreaterEqual(len(commands), 4)
        self.assertTrue(all("--no-deps" in line for line in commands),
                        "Every authorization up --force-recreate needs --no-deps")
        self.assertFalse(any("--no-deps" not in line and
                             "--force-recreate" in line for line in commands))

    def test_rollback_never_restarts_compose_dependencies(self):
        script = (ROOT / "scripts/rollback-keycloak-authorization.sh").read_text()
        commands = [line for line in script.splitlines()
                    if "$COMPOSE up -d" in line]
        self.assertEqual(len(commands), 2)
        self.assertTrue(all("--no-deps" in line for line in commands))

    def test_readiness_and_only_transient_requeue_precede_role_writes(self):
        script = (ROOT / "scripts/activate-keycloak-authorization.sh").read_text()
        readiness = script.index("php bin/authz-preflight.php")
        requeue = script.index("php bin/authz-requeue-transient.php")
        writer = script.index("upsert_env IRLIX_AUTH_SYNC_WRITE_ENABLED true")
        recreate = script.index("--no-deps --force-recreate employees-authorization-sync")
        self.assertLess(readiness, requeue)
        self.assertLess(requeue, writer)
        self.assertLess(writer, recreate)
        self.assertIn("if [ \"$kc_ready\" != true ]; then", script)
        self.assertIn("exit 1", script[readiness:writer])

        preflight = (ROOT / "services/employees/bin/authz-preflight.php").read_text()
        self.assertIn("->post(", preflight)  # token acquisition
        self.assertIn("->get(", preflight)   # read-only realm API
        self.assertNotIn("->put(", preflight)
        self.assertNotIn("->delete(", preflight)

        queue = (ROOT / "services/employees/bin/authz-requeue-transient.php").read_text()
        self.assertIn("->where('status', 'retry')", queue)
        self.assertIn("->where('last_error', 'like', '%cURL error 7:%')", queue)
        self.assertIn("->where('available_at', '>', now())", queue)
        self.assertIn("->update(['available_at' => now(), 'updated_at' => now()])", queue)
        self.assertNotIn("employee_access_roles", queue)

    def test_authz_runtime_scripts_are_included_and_linted_in_employees_image(self):
        dockerfile = (ROOT / "services/employees/Dockerfile").read_text()
        for script in ("authz-status.php", "authz-preflight.php", "authz-requeue-transient.php"):
            with self.subTest(script=script):
                self.assertIn(f"COPY bin/{script} /app/bin/{script}", dockerfile)
                self.assertIn(f"php -l /app/bin/{script}", dockerfile)
        # Avoid the regression where a repository PHP file passed CI
        # but was absent from the production employees image.

    def test_user_profile_marker_is_not_written_and_effective_roles_are_verified(self):
        sync = (ROOT / "services/employees/app/Support/KeycloakAuthorizationSync.php").read_text()
        migration = (ROOT / "services/employees/database/migrations/2026_10_21_230000_store_keycloak_managed_roles_in_employees.php").read_text()
        status = (ROOT / "services/employees/bin/authz-status.php").read_text()
        integration = (ROOT / "scripts/ci/keycloak_authz_smoke.py").read_text()

        self.assertNotIn("$this->api('PUT', \"/users/{$userId}\"", sync)
        self.assertIn("role-mappings/realm/composite", sync)
        self.assertIn("Keycloak effective role read-back mismatch", sync)
        self.assertIn("'managed_roles' => json_encode($roles", sync)
        self.assertIn("$table->jsonb('managed_roles')", migration)
        self.assertIn("status='pending'", migration)
        self.assertIn("$unverifiedLinked === 0", status)
        self.assertIn("orWhereNull('q.managed_roles')", status)
        self.assertIn("minimal_reps =", integration)
        self.assertIn("/role-mappings/realm/composite", integration)

    def test_curl_7_retries_have_short_bounded_backoff(self):
        script = (ROOT / "services/employees/bin/sync-authorization.php").read_text()
        self.assertIn("ConnectionException", script)
        self.assertIn("'cURL error 7:'", script)
        self.assertRegex(script, r"min\(30,\s*5 \* \(2 \*\* min\(\$attempts,\s*3\)\)\)")
        self.assertIn("min(3600, 5 * (2 ** min($attempts, 9)))", script)


if __name__ == "__main__":
    unittest.main()
