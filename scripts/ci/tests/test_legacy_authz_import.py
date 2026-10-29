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
        deploy = (ROOT / "scripts/deploy-from-ghcr.sh").read_text()
        self.assertEqual(subprocess.run(["sh", "-n", str(script)], check=False).returncode, 0)
        self.assertIn('finance|equipment)', script.read_text())
        self.assertIn('chmod 600 "$destination/schema.dump"', script.read_text())
        self.assertLess(deploy.index("scripts/authz/backup-local-matrices.sh"),
                        deploy.index("php artisan migrate --force"))

    def test_no_local_activation_in_import(self):
        for path in [
            ROOT / "services/finance/database/migrations/2026_10_29_000001_import_legacy_finance_grants.php",
            ROOT / "services/equipment/database/migrations/2026_10_29_000001_import_legacy_equipment_grants.php",
            ROOT / "scripts/authz/backup-local-matrices.sh",
        ]:
            self.assertNotIn("LOCAL_AUTHZ_ENABLED=true", path.read_text())


if __name__ == "__main__":
    unittest.main()
