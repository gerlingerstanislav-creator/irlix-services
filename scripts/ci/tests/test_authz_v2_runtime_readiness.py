"""AuthZ evidence must be read-only, fail-safe and devoid of sensitive values."""
import contextlib
import importlib.util
import io
import json
import pathlib
import subprocess
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).resolve().parents[2]
SOURCE = ROOT / "authz/inspect_runtime.py"
spec = importlib.util.spec_from_file_location("authz_runtime_probe", SOURCE)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class AuthzReadinessTests(unittest.TestCase):
    def test_observed_flags_come_from_running_container_not_env_file(self):
        payload = [{
            "State": {"Running": True},
            "Config": {"Env": [
                "IRLIX_AUTHZ_JWT_ENABLED=true",
                "IRLIX_FINANCE_LOCAL_AUTHZ_ENABLED=false",
                "FINANCE_DB_PASSWORD=must-not-be-printed",
                "KEYCLOAK_CLIENT_SECRET=private-value",
            ]},
        }]
        with patch.object(mod, "invoke", return_value=subprocess.CompletedProcess(
                [], 0, json.dumps(payload), "")):
            env = mod.effective_env([], "synthetic-container")
        self.assertEqual(mod.flag(env, "IRLIX_AUTHZ_JWT_ENABLED"), "enabled")
        self.assertEqual(mod.flag(env, "IRLIX_FINANCE_LOCAL_AUTHZ_ENABLED"), "disabled")
        self.assertEqual(mod.flag(env, "IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED"), "unverified")
        self.assertNotIn("FINANCE_DB_PASSWORD", mod.collect.__code__.co_consts)

    def test_stopped_or_invalid_containers_never_claim_active_policy(self):
        for raw in ("[]", "{}", "not-json",
                    '[{"State":{"Running":false},"Config":{"Env":["IRLIX_AUTHZ_JWT_ENABLED=true"]}}]'):
            with self.subTest(raw=raw), patch.object(mod, "invoke",
                    return_value=subprocess.CompletedProcess([], 0, raw, "")):
                self.assertIsNone(mod.effective_env([], "missing"))

    def test_aggregate_matrix_only_accepts_nonnegative_integer_counts(self):
        for body, expected in (
            ('{"status":"ok","rows":9,"allow_rows":4,"explicit_deny_rows":2}', "ok"),
            ('{"status":"ok","rows":2,"allow_rows":3,"explicit_deny_rows":0}', "unverified"),
            ('{"status":"ok","rows":1,"allow_rows":true,"explicit_deny_rows":0}', "unverified"),
            ('{"status":"ok","rows":1,"allow_rows":-1,"explicit_deny_rows":0}', "unverified"),
            ('{"status":"error","password":"private-value"}', "unverified"),
        ):
            with self.subTest(body=body), patch.object(mod, "invoke",
                    return_value=subprocess.CompletedProcess([], 0, body, "")):
                self.assertEqual(mod.matrix_counts([], ["docker", "compose"],
                    "finance", "FinanceRolePermission")["status"], expected)

    def test_local_activation_never_counts_as_cutover_acceptance(self):
        with patch.object(mod, "container_id", side_effect=lambda s, c, name: "id-"+name), \
             patch.object(mod, "effective_env", side_effect=lambda s, cid: {
                 "IRLIX_AUTHZ_JWT_ENABLED": "true",
                 **{name: "true" for name, _ in mod.MATRICES.values()}
             }), \
             patch.object(mod, "matrix_counts",
                          return_value={"status":"ok","rows":1,
                                        "allow_rows":1,"explicit_deny_rows":0}), \
             patch.object(mod, "role_sync", return_value="ready"):
            result = mod.collect(["docker","compose"], [])
        self.assertTrue(result["jwt_gate_consistent"])
        self.assertTrue(result["all_local_flags_enabled"])
        self.assertFalse(result["cutover_accepted"])
        self.assertEqual(result["signed_role_object_e2e"], "NOT TESTED")
        self.assertEqual(result["protected_matrix_backups"], "NOT TESTED")

    def test_probe_output_never_forwards_secrets_or_raw_roles(self):
        safe = {"format":"irlix-authz-runtime-readiness-v1",
                "jwt_gate_consistent":True,"all_local_flags_disabled":True}
        with patch.object(mod, "collect", return_value=safe), \
             patch.object(mod.os, "geteuid", return_value=0):
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                mod.main(["docker","compose"])
        self.assertIn("[authz-v2-readiness]", output.getvalue())
        self.assertNotIn("employee-id:", output.getvalue())
        self.assertNotIn("password", output.getvalue().lower())

    def test_release_checker_runs_runtime_probe_even_with_no_changed_services(self):
        source = (ROOT / "ci/verify_plan.py").read_text()
        self.assertIn("scripts/authz/inspect_runtime.py", source)
        self.assertLess(source.index("scripts/authz/inspect_runtime.py"),
                        source.index("for service in sorted(selected):"))
        self.assertNotIn("CREATE TABLE", mod.MATRIX_COUNTS_PHP.upper())
        self.assertNotIn("UPDATE ", mod.MATRIX_COUNTS_PHP.upper())
        self.assertNotIn("DELETE ", mod.MATRIX_COUNTS_PHP.upper())


if __name__ == "__main__":
    unittest.main()
