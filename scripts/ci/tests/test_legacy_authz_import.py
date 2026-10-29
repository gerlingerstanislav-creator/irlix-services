"""Regression protection for deterministic, non-destructive old-rights imports."""
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[3]


class LegacyAuthzImportTests(unittest.TestCase):
    def test_finance_and_equipment_do_not_override_manual_matrix_rows(self):
        paths = {
            "finance": ROOT / "services/finance/database/migrations/2026_10_29_000001_import_legacy_finance_grants.php",
            "equipment": ROOT / "services/equipment/database/migrations/2026_10_29_000001_import_legacy_equipment_grants.php",
        }
        for service, path in paths.items():
            with self.subTest(service=service):
                text = path.read_text()
                self.assertIn("insertOrIgnore(", text)
                self.assertNotIn("updateOrCreate(", text)
                self.assertNotIn("truncate(", text)
                self.assertNotIn("delete(", text)
                self.assertNotIn("platform-admin','permission'", text)
                self.assertIn("DB::transaction(", text)

    def test_finance_preserves_existing_manual_denials(self):
        role = (ROOT / "services/finance/app/Services/FinanceRoleAuthorization.php").read_text()
        gate = (ROOT / "services/finance/app/Http/Middleware/KeycloakBearer.php").read_text()
        self.assertIn("function intersectLegacy(", role)
        self.assertIn("legacy['permissions'][$permission]", role)
        self.assertIn("legacy['services']['finance']", role)
        self.assertIn("FinanceRoleAuthorization::intersectLegacy(", gate)
        self.assertIn("FinanceRoleAuthorization::currentIdentityMatches(", gate)

    def test_equipment_does_not_restore_explicit_service_deny(self):
        role = (ROOT / "services/equipment/app/Services/EquipmentRoleAuthorization.php").read_text()
        gate = (ROOT / "services/equipment/app/Http/Middleware/KeycloakBearer.php").read_text()
        self.assertIn("function legacyAdmission(", role)
        self.assertIn("current['services']['equipment']", role)
        self.assertIn("EquipmentRoleAuthorization::legacyAdmission(", gate)
        self.assertIn("EquipmentRoleAuthorization::currentIdentityMatches(", gate)

    def test_host_only_snapshot_before_migration(self):
        script = ROOT / "scripts/authz/backup-local-matrices.sh"
        helper = (ROOT / "scripts/authz/backup_local_matrices.py").read_text()
        deploy = (ROOT / "scripts/deploy-from-ghcr.sh").read_text()
        self.assertEqual(subprocess.run(["sh", "-n", str(script)], check=False).returncode, 0)
        self.assertIn("exec python3 scripts/authz/backup_local_matrices.py", script.read_text())
        for service in ("finance", "equipment", "timesheets", "vacations",
                        "recruitment", "clients", "specialists"):
            self.assertIn('"' + service + '": (', helper)
        self.assertIn("pg_restore", helper)
        self.assertIn("pg_dump", helper)
        self.assertIn("os.geteuid()", helper)
        self.assertIn("path.chmod(0o600)", helper)
        self.assertLess(deploy.index("scripts/authz/backup-local-matrices.sh"),
                        deploy.index("php artisan migrate --force"))

    def test_personal_and_hr_baselines_are_scoped_and_do_not_grant_everyone_all(self):
        baseline = {
            "timesheets": ("2026_10_29_000001_import_legacy_personal_grants.php", "'scope'=>'own'"),
            "vacations": ("2026_10_29_000001_import_legacy_personal_grants.php", "'scope'=>'own'"),
            "recruitment": ("2026_10_29_000001_import_legacy_hr_grants.php", "'scope'=>'all'"),
        }
        for service, (filename, expected_scope) in baseline.items():
            with self.subTest(service=service):
                source = (ROOT / "services" / service / "database/migrations" / filename).read_text()
                self.assertIn("insertOrIgnore(", source)
                self.assertNotIn("updateOrCreate(", source)
                self.assertIn(expected_scope, source)
                self.assertNotIn("'role'=>'org:irlix','permission'=>'recruitment.", source)
                gate = (ROOT / "services" / service / "app/Http/Middleware/ServiceEntitlement.php").read_text()
                self.assertIn("data.services." + service, gate)

    def test_specialists_catalog_mutations_remain_admin_only(self):
        seed = (ROOT / "services/specialists/database/migrations/"
                "2026_10_29_000001_import_legacy_manager_grants.php").read_text()
        self.assertNotIn("'specialists.catalog.manage'=>'all'", seed)
        route = (ROOT / "services/specialists/routes/api.php").read_text()
        self.assertIn("in_array('platform-admin', $roles, true)", route)

    def test_private_clients_timesheets_policy_transfer_is_owner_isolated(self):
        src = (ROOT / "services/clients/bin/export-timesheets-legacy-policy.php").read_text()
        dest = (ROOT / "services/timesheets/bin/import-clients-legacy-permissions.php").read_text()
        orchestration = (ROOT / "scripts/authz/transfer_clients_timesheets.py").read_text()
        deploy = (ROOT / "scripts/deploy-from-ghcr.sh").read_text()
        self.assertIn("ClientContourPermission::query()", src)
        self.assertIn("->where('permission','like','timesheets.%')", src)
        self.assertIn("insertOrIgnore(", dest)
        self.assertIn("Existing V2 rule conflicts with source legacy policy", dest)
        self.assertNotIn("updateOrCreate(", dest)
        self.assertIn("if (!$record['allowed'] && !$record['denied']) continue", dest)
        self.assertIn("'department-manager'=>'head:*'", dest)
        self.assertIn("subprocess.run(", orchestration)
        self.assertIn("capture_output=True", orchestration)
        self.assertNotIn("print(source.stdout)", orchestration)
        self.assertLess(deploy.index("php artisan migrate --force"),
                        deploy.index("scripts/authz/transfer_clients_timesheets.py"))
        self.assertIn("COPY bin /app/bin", (ROOT / "services/clients/Dockerfile").read_text())
        self.assertIn("COPY bin /app/bin", (ROOT / "services/timesheets/Dockerfile").read_text())

    def test_no_local_activation_in_import(self):
        for path in [
            ROOT / "services/finance/database/migrations/2026_10_29_000001_import_legacy_finance_grants.php",
            ROOT / "services/equipment/database/migrations/2026_10_29_000001_import_legacy_equipment_grants.php",
            ROOT / "scripts/authz/backup-local-matrices.sh",
        ]:
            self.assertNotIn("LOCAL_AUTHZ_ENABLED=true", path.read_text())


if __name__ == "__main__":
    unittest.main()
