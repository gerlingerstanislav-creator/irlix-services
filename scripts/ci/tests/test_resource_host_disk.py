import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location('resource_host_disk', ROOT/'scripts/resource-host-disk.py')
HOST = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(HOST)


class HostDiskTest(unittest.TestCase):
    def test_mutually_exclusive_categories_and_hardlinks(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            nested = base/'snapshots'/'console'/'timesheets'
            nested.mkdir(parents=True)
            (base/'ci').mkdir()
            shared = nested/'data.bin'
            shared.write_bytes(b'1234')
            (nested/'same-inode.bin').hardlink_to(shared)
            (base/'ci'/'link').symlink_to(shared)
            (base/'snapshots'/'old.bin').write_bytes(b'4567')
            sections = [
                ('timesheets', 'migration', nested, ()),
                ('legacy', 'migration', base/'snapshots', ('console',)),
                ('ci', None, base/'ci', ()),
                ('deployment', None, base, ('ci','snapshots')),
            ]
            result = HOST.inventory(sections, base)
            self.assertEqual([item['id'] for item in result['sections']],
                             ['timesheets','legacy','ci','deployment'])
            self.assertEqual(result['sections'][0]['bytes'],
                             HOST.measure(nested, base.stat().st_dev, HOST.Budget())['bytes'])
            self.assertFalse(result['partial'])
            self.assertEqual(len(json.dumps(result).split('data.bin')), 1)

    def test_missing_and_symlink_roots_are_not_zero(self):
        with tempfile.TemporaryDirectory() as directory:
            base=Path(directory)
            (base/'target').mkdir()
            (base/'shortcut').symlink_to(base/'target')
            result=HOST.inventory([('missing',None,base/'none',()),
                                   ('shortcut',None,base/'shortcut',())],base)
            self.assertEqual(result['sections'], [])

    def test_partial_scan_under_budget(self):
        with tempfile.TemporaryDirectory() as directory:
            base=Path(directory)
            for n in range(8): (base/str(n)).write_text('x')
            data=HOST.inventory([('test',None,base,())],base,HOST.Budget(max_entries=2))
            self.assertTrue(data['partial'])
            self.assertTrue(data['sections'][0]['partial'])
            self.assertGreaterEqual(data['sections'][0]['bytes'],0)

    def test_contract_has_unique_ids_and_disjoint_root_exclusions(self):
        self.assertEqual(len(set(s[0] for s in HOST.SECTIONS)),len(HOST.SECTIONS))
        self.assertTrue(all(not s[2].is_relative_to(s2[2]) or
            s[2].relative_to(s2[2]).parts[0] in s2[3]
            for s in HOST.SECTIONS for s2 in HOST.SECTIONS if s != s2 and
            s[2].is_relative_to(s2[2])))


if __name__ == '__main__':
    unittest.main()
