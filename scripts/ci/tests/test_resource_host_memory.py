import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location('resource_host_memory', ROOT / 'scripts/resource-host-memory.py')
HOST = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(HOST)


class HostMemoryTest(unittest.TestCase):
    def test_host_memory_uses_memavailable_and_kb(self):
        text = ('MemTotal: 8388608 kB\nMemAvailable: 2411724 kB\n'
                'Cached: 409600 kB\nSReclaimable: 10240 kB\nShmem: 5120 kB\n'
                'SwapTotal: 1024000 kB\nSwapFree: 512000 kB\n')
        v = HOST.parse_meminfo(text)
        self.assertEqual(v['memory_total'], 8388608*1024)
        self.assertEqual(v['memory_used'], (8388608-2411724)*1024)
        self.assertEqual(v['memory_cache'], (409600+10240-5120)*1024)
        self.assertEqual(v['swap_used'], 512000*1024)

    def test_reject_invalid_memory(self):
        for data in ('MemTotal: 12 kB\nMemAvailable: 13 kB',
                     'MemTotal: 0 kB\nMemAvailable: 0 kB',
                     'MemTotal: 12 kB\n',
                     'MemTotal: 12 kB\nMemAvailable: 1 kB\nSwapTotal: 2 kB\nSwapFree: 3 kB'):
            with self.subTest(data=data), self.assertRaises((ValueError,KeyError)):
                HOST.parse_meminfo(data)


if __name__ == '__main__':
    unittest.main()
