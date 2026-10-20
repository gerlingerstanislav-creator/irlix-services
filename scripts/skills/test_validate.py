#!/usr/bin/env python3
"""Regression tests: portable Skills must retain their directory boundaries."""
import hashlib
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

MODULE = Path(__file__).with_name("validate.py")
spec = importlib.util.spec_from_file_location("irlix_skills_validator", MODULE)
validator = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(validator)


class SkillLayoutValidationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="irlix-skill-layout-")
        self.addCleanup(self.temp.cleanup)
        self.skills = Path(self.temp.name) / "skills"
        self.skills.mkdir()
        previous_root = validator.SKILLS
        validator.SKILLS = self.skills
        self.addCleanup(lambda: setattr(validator, "SKILLS", previous_root))
        entries = []
        for slug in sorted(validator.EXPECTED):
            directory = self.skills / slug
            directory.mkdir()
            text = f"# {slug}\n"
            (directory / "SKILL.md").write_text(text, encoding="utf-8")
            payload = text.encode("utf-8")
            entries.append({
                "slug": slug,
                "files": [{
                    "path": f"{slug}/SKILL.md",
                    "bytes": len(payload),
                    "sha256": hashlib.sha256(payload).hexdigest(),
                }]
            })
        (self.skills / "MANIFEST.json").write_text(
            json.dumps({"schema": 1, "skills": entries}), encoding="utf-8"
        )
        (self.skills / "README.md").write_text("Skill index\n", encoding="utf-8")

    def test_valid_layout(self):
        self.assertEqual(validator.validate(), [])

    def test_flattened_browser_upload_is_rejected(self):
        (self.skills / "SKILL (1).md").write_text("collision", encoding="utf-8")
        errors = validator.validate()
        self.assertTrue(any("Untracked Skill file SKILL (1).md" in x for x in errors))

    def test_untracked_nested_file_is_rejected(self):
        slug = sorted(validator.EXPECTED)[0]
        (self.skills / slug / "unknown.md").write_text("unlisted", encoding="utf-8")
        self.assertTrue(any("Untracked Skill file" in x for x in validator.validate()))

    def test_modified_skill_fails_hash_check(self):
        slug = sorted(validator.EXPECTED)[0]
        (self.skills / slug / "SKILL.md").write_text("tampered", encoding="utf-8")
        self.assertTrue(any("Hash mismatch" in x for x in validator.validate()))


if __name__ == "__main__":
    unittest.main()
