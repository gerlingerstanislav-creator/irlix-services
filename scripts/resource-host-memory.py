#!/usr/bin/env python3
"""Atomic host-side RAM sampler: no network access or sensitive fields."""
import json
import os
from pathlib import Path
import subprocess
import time

def parse_meminfo(text):
    m = {}
    for line in text.splitlines():
        if ':' not in line: continue
        key, value = line.split(':', 1)
        parts = value.strip().split()
        if parts and parts[0].isdigit():
            m[key] = int(parts[0]) * (1024 if len(parts)>1 and parts[1]=='kB' else 1)
    total, available = m['MemTotal'], m['MemAvailable']
    if total <= 0 or not 0 <= available <= total: raise ValueError('Invalid host RAM')
    swap, free = m.get('SwapTotal',0), m.get('SwapFree',0)
    if free > swap: raise ValueError('Invalid swap')
    return dict(memory_total=total,memory_available=available,memory_used=total-available,
                memory_cache=max(0,m.get('Cached',0)+m.get('SReclaimable',0)-m.get('Shmem',0)),
                swap_total=swap,swap_used=swap-free)

def main():
    # The compose project name is fixed by the deployment contract.
    out = subprocess.check_output(['docker','volume','inspect','--format','{{.Mountpoint}}',
                                   'irlix-services_resource_monitor_data'],text=True,timeout=8).strip()
    directory = Path(out)
    if not directory.is_absolute() or not directory.is_dir(): raise ValueError('Invalid volume')
    data = dict(collected_at=int(time.time()), **parse_meminfo(Path('/proc/meminfo').read_text()))
    temporary = directory / 'host-memory.tmp'
    with temporary.open('w') as f:
        json.dump(data,f,separators=(',',':'))
        f.flush()
        os.fsync(f.fileno())
    os.chmod(temporary,0o644)
    temporary.replace(directory / 'host-memory.json')

if __name__ == '__main__':
    main()
