"""Image preflight must never accept an unavailable GHCR tag as verified."""
import importlib.util
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

MODULE = Path(__file__).resolve().parents[1] / "image_preflight.py"
spec = importlib.util.spec_from_file_location("image_preflight", MODULE)
preflight = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preflight)


class ImagePreflightTests(unittest.TestCase):
    def test_all_verified_images_need_no_build_matrix(self):
        refs = {"include": [
            {"id": "employees", "image_ref": "ghcr.io/x/employees:src-a"},
            {"id": "clients", "image_ref": "ghcr.io/x/clients:src-b"},
        ]}
        result = preflight.select(refs, checker=lambda _: True)
        self.assertFalse(result["needs_build"])
        self.assertEqual([x["id"] for x in result["reused"]], ["employees", "clients"])
        self.assertEqual(result["build_matrix"]["include"],
                         [{"id": "not-required", "skip": True}])

    def test_only_missing_image_enters_build_matrix(self):
        refs = {"include": [
            {"id": "employees", "image_ref": "ghcr.io/x/employees:src-a"},
            {"id": "clients", "image_ref": "ghcr.io/x/clients:src-b"},
        ]}
        result = preflight.select(refs, checker=lambda ref: ref.endswith("src-a"))
        self.assertTrue(result["needs_build"])
        self.assertEqual([x["id"] for x in result["missing"]], ["clients"])
        self.assertEqual([x["id"] for x in result["build_matrix"]["include"]], ["clients"])

    def test_placeholder_is_never_checked_or_built(self):
        result = preflight.select({"include": [{"id": "not-required", "skip": True}]},
                                  checker=lambda _: self.fail("unexpected manifest check"))
        self.assertFalse(result["needs_build"])
        self.assertEqual(result["selected"], [])

    @patch.object(preflight.subprocess, "run")
    def test_failed_manifest_check_requires_build(self, run):
        run.return_value = subprocess.CompletedProcess([], 1)
        self.assertFalse(preflight.available("ghcr.io/x/a:src-b"))
        run.assert_called_once()

    @patch.object(preflight.subprocess, "run", side_effect=subprocess.TimeoutExpired("docker", 35))
    def test_manifest_timeout_requires_build(self, _):
        self.assertFalse(preflight.available("ghcr.io/x/a:src-b"))


if __name__ == "__main__":
    unittest.main()
