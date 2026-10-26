"""Regression tests: HTTP redirect HTML cannot masquerade as Brandbook SPA."""
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from verify_plan import verify_brandbook  # noqa: E402


def response(cmd, *, gate="302", redirect="http://services.lan/?brandbook=1",
             origin_status="200", svg_status="200"):
    if "-D" in cmd:
        return ("HTTP/1.1 " + gate + " Found\r\n"
                "Location: " + redirect + "\r\n"
                "Content-Type: text/html\r\n\r\n"
                "<html>nginx redirect</html>\n" + gate)
    path = cmd[-1]
    status = svg_status if path.endswith(".svg") else origin_status
    body = "<svg></svg>" if path.endswith(".svg") else "<html>actual app</html>"
    return body + "\n" + status


class BrandbookSmokeTests(unittest.TestCase):
    @patch("verify_plan.subprocess.check_output")
    def test_requires_gate_and_real_origin_pages(self, fetch):
        fetch.side_effect = lambda cmd, **_: response(cmd)
        verify_brandbook("services.lan")
        self.assertEqual(fetch.call_count, 5)
        self.assertEqual(fetch.call_args_list[0].args[0][-1],
                         "http://127.0.0.1/brandbook/")
        self.assertEqual(fetch.call_args_list[-1].args[0][-1],
                         "http://127.0.0.1:8105/downloads/irlix-logo-dark-background.svg")

    @patch("verify_plan.subprocess.check_output")
    def test_wrong_gate_target_fails(self, fetch):
        fetch.side_effect = lambda cmd, **_: response(cmd, redirect="/missing/")
        with self.assertRaisesRegex(AssertionError, "Portal SSO"):
            verify_brandbook("services.lan")

    @patch("verify_plan.subprocess.check_output")
    def test_origin_404_with_html_must_fail(self, fetch):
        fetch.side_effect = lambda cmd, **_: response(cmd, origin_status="404")
        with self.assertRaisesRegex(AssertionError, "expected 200, got 404"):
            verify_brandbook("services.lan")

    @patch("verify_plan.subprocess.check_output")
    def test_download_404_must_fail(self, fetch):
        fetch.side_effect = lambda cmd, **_: response(cmd, svg_status="404")
        with self.assertRaisesRegex(AssertionError, "expected 200, got 404"):
            verify_brandbook("services.lan")


if __name__ == "__main__":
    unittest.main()
