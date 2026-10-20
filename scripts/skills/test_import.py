#!/usr/bin/env python3
"""Safe regression checks for importing user-supplied ZIP bundles."""
import importlib.util
import io
import tempfile
import unittest
import zipfile
from pathlib import Path

FILE = Path(__file__).with_name("import.py")
spec = importlib.util.spec_from_file_location("irlix_skill_import", FILE)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(module)


class ImportTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="irlix-import-test-")
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)

    def create_zip(self, name, files):
        path = self.root / name
        with zipfile.ZipFile(path, "w") as archive:
            for item, body in files.items():
                archive.writestr(item, body)
        return path

    def test_safe_extract_all_five(self):
        stage = self.root / "stage"
        roots = []
        for slug in sorted(module.EXPECTED):
            path = self.create_zip(slug + ".zip", {slug + "/SKILL.md": "# test\n"})
            root, count = module.safe_extract(path, stage)
            roots.append(root)
            self.assertEqual(count, 1)
        self.assertEqual(set(roots), module.EXPECTED)
        self.assertEqual(len(module.build_manifest(stage)["skills"]), 5)

    def test_path_traversal_rejected(self):
        slug = "senior-qa-engineer"
        zip_path = self.create_zip("unsafe.zip", {
            slug + "/SKILL.md": "ok",
            slug + "/../../outside.md": "bad"
        })
        with self.assertRaises(ValueError):
            module.safe_extract(zip_path, self.root / "output")
        self.assertFalse((self.root / "outside.md").exists())

    def test_missing_skill_entry_rejected(self):
        slug = "senior-qa-engineer"
        zip_path = self.create_zip("missing.zip", {slug + "/README.md": "missing"})
        with self.assertRaises(ValueError):
            module.safe_extract(zip_path, self.root / "output")


if __name__ == "__main__":
    unittest.main()
