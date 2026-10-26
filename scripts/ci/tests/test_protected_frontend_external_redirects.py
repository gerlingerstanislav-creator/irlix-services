"""Protected frontend 401 must produce a browser Location, never an internal SPA rewrite."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]

class ProtectedFrontendRedirectTests(unittest.TestCase):
    def test_host_and_portal_fallback_use_real_external_redirects(self):
        for file, suffix in (
            ("infra/nginx/irlix-services.conf", ""),
            ("apps/portal/nginx.conf", "_fallback"),
        ):
            with self.subTest(config=file):
                conf = (ROOT / file).read_text(encoding="utf-8")
                self.assertNotIn("error_page 401 =302 /?", conf)
                for service, name, query in (
                    ("brandbook", "brandbook", "brandbook=1"),
                    ("design-system", "design_system", "design-system=1&return=$uri"),
                ):
                    location = f"@{name}_login{suffix}"
                    self.assertRegex(
                        conf,
                        re.compile(
                            rf"location /{re.escape(service)}/\s*{{"
                            rf"(?:(?!\n    }}).)*?auth_request [^;]+;"
                            rf"(?:(?!\n    }}).)*?error_page 401 = {location};",
                            re.S,
                        ),
                    )
                    self.assertRegex(
                        conf,
                        re.compile(
                            rf"location {location}\s*{{[^}}]*"
                            rf"return 302 /\?{re.escape(query)};",
                            re.S,
                        ),
                    )
                    self.assertIn("internal;", conf, "auth_request gates remain internal")

if __name__ == "__main__":
    unittest.main()
