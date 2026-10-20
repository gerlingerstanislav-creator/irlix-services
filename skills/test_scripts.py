"""Package quality tests; intentionally uses stdlib only."""
from __future__ import annotations
import json
import sys
import tempfile
import unittest
from pathlib import Path

SKILL_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SKILL_ROOT / 'scripts'))
from validate_skill import validate
from audit_frontend import inventory


class PackageValidationTests(unittest.TestCase):
    def test_real_skill_valid(self):
        self.assertEqual(validate(SKILL_ROOT), [])

    def test_bad_name_and_missing_reference_reported(self):
        with tempfile.TemporaryDirectory(prefix='fake-skill-') as tmp:
            root = Path(tmp) / 'Bad_Name'
            root.mkdir()
            (root / 'SKILL.md').write_text('---\nname: Bad_Name\ndescription: Test\n---\nRead [missing](references/missing.md).\n',encoding='utf8')
            problems = validate(root)
            self.assertTrue(any('name' in problem for problem in problems), problems)
            self.assertTrue(any('Invalid local link' in problem for problem in problems), problems)

    def test_safe_audit_no_env_dump_and_read_only(self):
        with tempfile.TemporaryDirectory(prefix='vue-project-') as tmp:
            root = Path(tmp)
            (root / 'package.json').write_text(json.dumps({'dependencies':{'vue':'^3.5.0'},'scripts':{'test':'vitest'}}),encoding='utf8')
            (root / '.env').write_text('VITE_PASSWORD=secret_password_goes_here',encoding='utf8')
            src = root / 'src'
            src.mkdir()
            (src / 'Record.vue').write_text('<template><p v-html="remoteData" /></template>\n',encoding='utf8')
            before = (src / 'Record.vue').read_bytes()
            result = inventory(root)
            self.assertEqual(result['manifest_dependency_ranges']['vue'],'^3.5.0')
            self.assertEqual(result['bounded_review_hints'][0]['kind'],'raw-html')
            self.assertNotIn('secret_password_goes_here',json.dumps(result))
            self.assertEqual((src / 'Record.vue').read_bytes(),before)

    def test_audit_missing_directory(self):
        with self.assertRaises(ValueError):
            inventory(Path('/this/nonexistent/path/for/testing'))


if __name__ == '__main__':
    unittest.main()
