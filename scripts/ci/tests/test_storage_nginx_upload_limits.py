"""Regression guard: Nginx auth_request must accept the file route's upload body size.

Nginx's internal auth_request subrequests inherit request properties before
proxy_pass_request_body off takes effect. A default 1m limit on the gate can
reject 2-3 MiB files with 413, surfaced to the browser as opaque HTTP 500.
"""
import re
import unittest
from pathlib import Path

CONFIG = Path(__file__).resolve().parents[3] / "infra/nginx/irlix-services.conf"


def location_block(contents: str, location: str) -> str:
    pattern = r"(?ms)^[ \t]*location[ \t]+" + re.escape(location) + r"[ \t]*\{(.*?)^[ \t]*\}"
    match = re.search(pattern, contents)
    if match is None:
        raise AssertionError(f"Missing nginx location {location}")
    return match.group(1)


class StorageUploadNginxLimitsTests(unittest.TestCase):
    def test_auth_gate_and_upload_endpoint_have_matching_limits(self):
        config = CONFIG.read_text()
        for endpoint, gate in (
            ("/api/storage/v1/", "/_storage_v1_gate"),
            ("/api/storage/", "/_storage_pilot_gate"),
        ):
            with self.subTest(endpoint=endpoint):
                public = location_block(config, endpoint)
                internal = location_block(config, "=" + " " + gate)
                self.assertRegex(public, r"(?m)^\s*client_max_body_size\s+11m\s*;")
                self.assertRegex(internal, r"(?m)^\s*client_max_body_size\s+11m\s*;")
                self.assertIn("auth_request " + gate + ";", public)
                self.assertRegex(internal, r"(?m)^\s*internal\s*;")
                self.assertRegex(internal, r"(?m)^\s*proxy_pass_request_body\s+off\s*;")
                self.assertRegex(internal, r'(?m)^\s*proxy_set_header\s+Content-Length\s+""\s*;')


if __name__ == "__main__":
    unittest.main()
