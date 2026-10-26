"""Regression coverage for Design System SSO gate and actual frontend identity."""
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from verify_plan import verify_design_system  # noqa: E402


def response(cmd, *, gate="302", redirect="http://services.lan/?design-system=1&return=/design-system/",
             origin_status="200", origin_title="IRLIX Design System"):
    if "-D" in cmd:
        return (f"HTTP/1.1 {gate} Found\r\n"
                f"Location: {redirect}\r\n\r\n{gate}")
    return f"<html><title>{origin_title}</title></html>\n{origin_status}"


class DesignSystemSmokeTests(unittest.TestCase):
    @patch("verify_plan.subprocess.check_output")
    def test_sso_gate_and_real_origin_pages(self, fetch):
        fetch.side_effect = lambda cmd, **_: response(cmd)
        verify_design_system("services.lan")
        self.assertEqual(fetch.call_count, 4)
        self.assertEqual(fetch.call_args_list[0].args[0][-1],
                         "http://127.0.0.1/design-system/")
        self.assertEqual(fetch.call_args_list[-1].args[0][-1],
                         "http://127.0.0.1:8083/navigation/")

    @patch("verify_plan.subprocess.check_output")
    def test_public_design_system_is_rejected(self, fetch):
        fetch.side_effect = lambda cmd, **_: response(cmd, gate="200")
        with self.assertRaisesRegex(AssertionError, "expected 302"):
            verify_design_system("services.lan")

    @patch("verify_plan.subprocess.check_output")
    def test_wrong_login_redirect_is_rejected(self, fetch):
        fetch.side_effect = lambda cmd, **_: response(cmd, redirect="/")
        with self.assertRaisesRegex(AssertionError, "Portal SSO"):
            verify_design_system("services.lan")

    @patch("verify_plan.subprocess.check_output")
    def test_portal_html_cannot_masquerade_as_design_system(self, fetch):
        fetch.side_effect = lambda cmd, **_: response(cmd, origin_title="IRLIX Dashboard")
        with self.assertRaisesRegex(AssertionError, "wrong frontend"):
            verify_design_system("services.lan")

    @patch("verify_plan.subprocess.check_output")
    def test_origin_404_is_rejected(self, fetch):
        fetch.side_effect = lambda cmd, **_: response(cmd, origin_status="404")
        with self.assertRaisesRegex(AssertionError, "expected 200, got 404"):
            verify_design_system("services.lan")


if __name__ == "__main__":
    unittest.main()
