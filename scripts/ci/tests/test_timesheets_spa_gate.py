"""Regression: Timesheets production smoke must respect the Design System SSO gate."""
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[3]


class TimesheetsSpaGateTests(unittest.TestCase):
    def test_anonymous_design_system_deep_links_require_redirect(self):
        script = (ROOT / "scripts/verify-timesheets.sh").read_text(encoding="utf-8")
        direct = script.split('echo "[routing] direct SPA routes"', 1)[1].split(
            'echo "[routing] direct SPA routes OK"', 1
        )[0]
        protected = script.split('echo "[routing] protected Design System routes"', 1)[1].split(
            'echo "[routing] protected Design System routes OK"', 1
        )[0]
        for route in ("design-system/components", "design-system/navigation"):
            self.assertNotIn(route, direct, "protected route cannot require anonymous 200")
            self.assertIn(route, protected)
        self.assertIn('[ "$code" = 200 ]', direct)
        self.assertIn('[ "$code" = 302 ]', protected)
        self.assertIn("curl_stand", protected)


if __name__ == "__main__":
    unittest.main()
