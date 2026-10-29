import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch

MODULE = Path(__file__).resolve().parents[1] / "workflow_scope.py"
spec = importlib.util.spec_from_file_location("workflow_scope", MODULE)
scope = importlib.util.module_from_spec(spec)
spec.loader.exec_module(scope)


class SpecializedWorkflowSelectionTests(unittest.TestCase):
    def apps(self, mode, paths):
        return {entry["app"] for entry in scope.select(mode, set(paths))["matrix"]["include"]}

    def services(self, paths):
        return {entry["service"] for entry in scope.select("keycloak-authz", set(paths))["matrix"]["include"]}

    def test_unrelated_backend_does_not_rebuild_appearance_frontends(self):
        self.assertEqual(self.apps("ui-appearance-check", ["services/clients/app/Order.php"]), set())

    def test_specific_frontend_builds_only_its_own_app(self):
        self.assertEqual(self.apps("ui-appearance-check", ["apps/clients/src/App.vue"]), {"clients"})

    def test_shared_ui_builds_all_appearance_consumers(self):
        self.assertEqual(self.apps("ui-appearance-check", ["packages/ui/src/Button.vue"]), set(scope.UI_APPS))

    def test_shared_ui_tests_or_docs_do_not_build_all_apps(self):
        self.assertEqual(self.apps("ui-appearance-check", ["packages/ui/tests/theme.test.mjs"]), set())
        self.assertEqual(self.apps("ui-appearance-check", ["packages/ui/README.md"]), set())

    def test_keycloak_clients_change_skips_unaffected_admin_images(self):
        self.assertEqual(self.services(["services/clients/app/Http/Controllers/ClientsController.php"]), set())

    def test_keycloak_employee_change_builds_only_employees(self):
        self.assertEqual(self.services(["services/employees/app/Models/Employee.php"]), {"employees"})

    def test_keycloak_realm_change_rechecks_all_backend_images(self):
        self.assertEqual(self.services(["infra/keycloak/irlix-realm.json"]), set(scope.KEYCLOAK_BACKENDS))

    def test_client_history_backend_skips_frontend_builds(self):
        result = scope.select("client-history-ui-check", {"services/clients/app/Models/Client.php"})
        self.assertEqual(result["matrix"]["include"], [])
        self.assertTrue(result["php"])

    def test_client_history_ui_builds_clients_only(self):
        self.assertEqual(self.apps("client-history-ui-check", ["apps/clients/src/App.vue"]), {"clients"})

    def test_finance_backend_only_skips_frontend_builds(self):
        result = scope.select("finance-validation", {"services/clients/app/Support/Foo.php"})
        self.assertFalse(result["frontend"])
        self.assertTrue(result["backend"])
        self.assertTrue(result["clients_container"])
        self.assertTrue(result["finance_container"])

    def test_finance_frontend_only_skips_backend_containers(self):
        result = scope.select("finance-validation", {"apps/finance/src/App.vue"})
        self.assertTrue(result["finance_frontend"])
        self.assertFalse(result["clients_frontend"])
        self.assertFalse(result["backend"])
        self.assertFalse(result["finance_container"])

    @patch.object(scope.subprocess, "check_output", return_value="merge-sha exact-base-sha pr-sha\\n")
    def test_pr_merge_base_uses_first_parent_of_actual_checkout(self, command):
        self.assertEqual(scope.pr_merge_base(), "exact-base-sha")
        command.assert_called_once()

    @patch.object(scope.subprocess, "check_output", return_value="single-parent-sha ancestor-sha\\n")
    def test_pr_merge_base_rejects_non_merge_checkout(self, command):
        with self.assertRaisesRegex(RuntimeError, "two-parent"):
            scope.pr_merge_base()

    def test_workflows_request_checked_merge_base_not_stale_event(self):
        workflows = [".github/workflows/" + x + ".yml" for x in
                     ("ui-appearance-check", "keycloak-authz",
                      "client-history-ui-check", "finance-validation")]
        for name in workflows:
            yaml = (scope.ROOT / name).read_text()
            self.assertIn("--pr", yaml, name)
            self.assertNotIn('PR_BASE: ${{ github.event.pull_request.base.sha }}', yaml, name)
        ci = (scope.ROOT / ".github/workflows/ci.yml").read_text()
        self.assertGreaterEqual(ci.count('git rev-list --parents -n 1 HEAD'), 3)
        self.assertNotIn('PR_BASE_SHA: ${{ github.event.pull_request.base.sha }}', ci)

    def test_manual_runs_are_exhaustive(self):
        for mode in ("ui-appearance-check", "keycloak-authz",
                     "client-history-ui-check", "finance-validation"):
            result = scope.select(mode)
            self.assertTrue(all(value for key, value in result.items() if key != "matrix"))
            if "matrix" in result:
                self.assertTrue(result["matrix"]["include"])


if __name__ == "__main__":
    unittest.main()
