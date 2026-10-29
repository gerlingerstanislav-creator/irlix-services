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



    def test_physical_bucket_classification_is_disjoint(self):
        examples = {
            ('var', 'lib', 'docker', 'overlay2', 'layer'): 'docker-overlay',
            ('var', 'lib', 'docker', 'volumes', 'postgres'): 'docker-volumes',
            ('var', 'lib', 'docker', 'containers', 'id'): 'docker-containers',
            ('var', 'lib', 'docker', 'buildkit'): 'docker-buildkit',
            ('var', 'lib', 'docker', 'image'): 'docker-other',
            ('opt', 'irlix-services', '.ci', 'migration-test-snapshots', 'console'): 'migration-snapshots',
            ('opt', 'irlix-services', '.ci', 'output'): 'ci',
            ('opt', 'irlix-services', 'apps'): 'deployment',
            ('var', 'log', 'journal'): 'system-logs',
            ('var', 'cache', 'apt'): 'system-cache',
            ('tmp', 'file'): 'system-temp',
            ('var', 'lib', 'systemd'): 'system-data',
            ('usr', 'lib'): 'os-packages',
            ('etc', 'hosts'): 'os-config',
            ('home', 'user'): 'user-files',
            ('lost+found',): 'other',
        }
        for path, expected in examples.items():
            self.assertEqual(HOST.physical_category(path), expected)
        self.assertEqual(set(examples.values()), set(HOST.PHYSICAL_IDS))

    def test_physical_inventory_counts_hardlinks_once_and_reconciles(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            volumes = root/'var'/'lib'/'docker'/'volumes'
            volumes.mkdir(parents=True)
            (root/'etc').mkdir()
            blob = volumes/'data.bin'
            blob.write_bytes(b'x' * 8192)
            (root/'etc'/'hardlink').hardlink_to(blob)
            (root/'etc'/'symlink').symlink_to(blob)
            result = HOST.physical_inventory(root, HOST.Budget())
            physical = result['filesystem']
            sizes = {row['id']: row['bytes'] for row in result['physical_sections']}
            self.assertFalse(physical['partial'])
            self.assertEqual(sum(sizes.values()), physical['classified_bytes'])
            self.assertEqual(physical['used_bytes'] + physical['available_bytes'] +
                             physical['reserved_bytes'], physical['total_bytes'])
            self.assertEqual(physical['classified_bytes'] + physical['unclassified_bytes'] -
                             physical['overlap_bytes'], physical['used_bytes'])
            # The same allocated 8 KiB inode appears in precisely one bucket.
            self.assertLessEqual(sizes['docker-volumes'] + sizes['os-config'],
                                 physical['classified_bytes'])
            self.assertGreaterEqual(sizes['docker-volumes'] + sizes['os-config'], blob.stat().st_blocks * 512)

    def test_physical_scan_budget_exhaustion_is_explicit(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for n in range(10):
                (root/str(n)).write_bytes(b'123')
            result = HOST.physical_inventory(root, HOST.Budget(max_entries=2))
            self.assertTrue(result['filesystem']['partial'])
            self.assertEqual(sum(x['bytes'] for x in result['physical_sections']),
                             result['filesystem']['classified_bytes'])
            self.assertGreater(result['filesystem']['unclassified_bytes'], 0)


if __name__ == '__main__':
    unittest.main()
