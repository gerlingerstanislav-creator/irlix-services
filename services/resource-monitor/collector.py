"""Read-only Docker/host metrics collector. No listener and no Docker mutation API."""
import concurrent.futures
import ctypes
import http.client
import json
import os
from pathlib import Path
import re
import socket
import sqlite3
import time
from urllib.parse import quote

INTERVAL = 10
DISK_INTERVAL = 300
RETENTION = 7 * 86400
API_VERSION = None


class DockerConnection(http.client.HTTPConnection):
    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect('/var/run/docker.sock')


def docker_get(path):
    # This process can only call these read endpoints. Never expose arbitrary paths.
    if not re.fullmatch(r'/containers/json\?[^\s]+|/containers/[a-f0-9]{64}/(?:json|stats\?stream=false&one-shot=true)|/system/df\?type=container&type=volume|/info', path):
        raise ValueError('Unsupported Docker read endpoint')
    global API_VERSION
    if API_VERSION is None:
        # Modern Engines may reject old API versions. Negotiate before starting parallel reads.
        version_connection = DockerConnection('localhost', timeout=4)
        try:
            version_connection.request('GET', '/version')
            response = version_connection.getresponse()
            if response.status != 200:
                raise RuntimeError('Docker API negotiation failed')
            version = json.load(response).get('ApiVersion', '')
            if not re.fullmatch(r'1\.\d{1,3}', version):
                raise RuntimeError('Unsupported Docker API version')
            API_VERSION = version
        finally:
            version_connection.close()
    connection = DockerConnection('localhost', timeout=15 if path.startswith('/system/df') else 4)
    try:
        connection.request('GET', '/v' + API_VERSION + path)
        response = connection.getresponse()
        if response.status != 200:
            raise RuntimeError('Docker metrics unavailable')
        return json.load(response)
    finally:
        connection.close()


def group_for(service):
    explicit = {'web': 'employees', 'portal': 'dashboard', 'cv-web': 'cv-converter',
                'cv-llm': 'cv-converter', 'employees-events': 'employees',
                'vacations-calendar-sync': 'vacations', 'migration-worker': 'migration',
                'migration-ops': 'migration', 'timesheets-reconcile': 'timesheets', 'resource-monitor': 'platform-core'}
    return explicit.get(service, service[:-4] if service.endswith('-web') else service)


def cpu_cores(stats, previous):
    cpu = stats.get('cpu_stats', {})
    total = cpu.get('cpu_usage', {}).get('total_usage', 0)
    system = cpu.get('system_cpu_usage', 0)
    online = cpu.get('online_cpus') or len(cpu.get('cpu_usage', {}).get('percpu_usage', [])) or 1
    now = (total, system)
    if previous is None or system <= previous[1] or total < previous[0]:
        return None, now
    return max(0, (total - previous[0]) / (system - previous[1]) * online), now


def memory_metrics(stats):
    memory = stats.get('memory_stats', {})
    usage = memory.get('usage', 0)
    detail = memory.get('stats', {})
    cache = detail.get('inactive_file', detail.get('total_inactive_file', detail.get('cache', 0)))
    return {'memory_bytes': usage, 'working_bytes': max(0, usage - cache), 'cache_bytes': min(usage, cache)}


def syscall_memory(expected_total):
    """Best-effort host RAM fallback when procfs is virtualized by the runtime.

    sysinfo is a host kernel syscall, independent of procfs mounts. It does not
    expose MemAvailable: free + buffer RAM is a conservative available estimate.
    """
    class Sysinfo(ctypes.Structure):
        _fields_ = [('uptime', ctypes.c_long), ('loads', ctypes.c_ulong * 3),
                    ('totalram', ctypes.c_ulong), ('freeram', ctypes.c_ulong),
                    ('sharedram', ctypes.c_ulong), ('bufferram', ctypes.c_ulong),
                    ('totalswap', ctypes.c_ulong), ('freeswap', ctypes.c_ulong),
                    ('procs', ctypes.c_ushort), ('pad', ctypes.c_ushort),
                    ('totalhigh', ctypes.c_ulong), ('freehigh', ctypes.c_ulong),
                    ('mem_unit', ctypes.c_uint), ('_f', ctypes.c_char * 20)]
    result = Sysinfo()
    libc = ctypes.CDLL(None, use_errno=True)
    if libc.sysinfo(ctypes.byref(result)) != 0:
        raise RuntimeError('Host sysinfo syscall failed')
    multiplier = result.mem_unit or 1
    total = result.totalram * multiplier
    if abs(total - expected_total) > max(16 * 1024 * 1024, expected_total * .02):
        raise RuntimeError('Host syscall memory does not match Docker Engine capacity')
    available = min(total, (result.freeram + result.bufferram) * multiplier)
    return {'memory_total': total, 'memory_available': available,
            'memory_used': total - available, 'memory_cache': result.bufferram * multiplier,
            'swap_total': result.totalswap * multiplier,
            'swap_used': (result.totalswap - result.freeswap) * multiplier}


def host_metrics(proc, root, previous, expected_total=None):
    memory = {}
    for line in (proc / 'meminfo').read_text().splitlines():
        key, value = line.split(':', 1)
        memory[key] = int(value.strip().split()[0]) * 1024
    if expected_total is not None and abs(memory['MemTotal'] - expected_total) > max(16 * 1024 * 1024, expected_total * 0.02):
        raise RuntimeError('Host memory source does not match Docker Engine VM capacity')
    lines = (proc / 'stat').read_text().splitlines()
    ticks = list(map(int, lines[0].split()[1:9]))  # guest time is already in user/nice
    current = (sum(ticks), ticks[3] + ticks[4])
    busy = None
    if previous and current[0] > previous[0]:
        busy = 100 * (1 - (current[1] - previous[1]) / (current[0] - previous[0]))
    disk = os.statvfs(root)
    return {
        'cpu_count': sum(bool(re.match(r'^cpu\d+ ', line)) for line in lines),
        'cpu_percent': None if busy is None else max(0, min(100, busy)),
        'load_average': list(map(float, (proc / 'loadavg').read_text().split()[:3])),
        'memory_total': memory['MemTotal'], 'memory_available': memory['MemAvailable'],
        'memory_used': memory['MemTotal'] - memory['MemAvailable'],
        'memory_cache': max(0, memory.get('Cached', 0) + memory.get('SReclaimable', 0) - memory.get('Shmem', 0)),
        'swap_total': memory.get('SwapTotal', 0),
        'swap_used': memory.get('SwapTotal', 0) - memory.get('SwapFree', 0),
        'disk_total': disk.f_blocks * disk.f_frsize, 'disk_available': disk.f_bavail * disk.f_frsize,
    }, current


def aggregate(components):
    groups = {}
    for item in components:
        key = item['group']
        row = groups.setdefault(key, {'id': key, 'components': [], 'memory_bytes': 0, 'working_bytes': 0,
                                     'cache_bytes': 0, 'cpu_cores': 0, 'partial': False})
        row['components'].append(item)
        row['partial'] |= item.get('error', False)
        for field in ('memory_bytes', 'working_bytes', 'cache_bytes'):
            row[field] += item.get(field, 0)
        if item.get('state') == 'running' and item.get('cpu_cores') is None:
            row['cpu_cores'] = None
        elif row['cpu_cores'] is not None:
            row['cpu_cores'] += item.get('cpu_cores') or 0
    return sorted(groups.values(), key=lambda row: row['memory_bytes'], reverse=True)


def disk_metrics(usage, project, root):
    """Docker writable layers and local root-disk volumes, each volume counted once.

    Shared cross-service/outside-project volumes, images, bind mounts and logs
    remain in the host remainder. Never return names, paths or Docker metadata.
    """
    root_device = os.stat(root).st_dev
    containers = usage.get('Containers') or []
    groups, owners, components, measured, members = {}, {}, {}, set(), {}
    for container in containers:
        labels = container.get('Labels') or {}
        own = labels.get('com.docker.compose.project') == project and labels.get('com.docker.compose.oneoff') != 'True'
        service = labels.get('com.docker.compose.service')
        group = group_for(service) if own and service else None
        mounts = container.get('Mounts') or []
        for mount in mounts:
            if mount.get('Type') == 'volume' and mount.get('Name'):
                owners.setdefault(mount['Name'], set()).add(group)
        if group is None:
            continue
        row = groups.setdefault(group, {'disk_bytes': 0, 'disk_partial': False, 'disk_volumes_bytes': 0})
        # ContainerSummary omits zero SizeRw in the df response (Go omitempty).
        size = container.get('SizeRw', 0)
        if not isinstance(size, (int, float)) or size < 0:
            row['disk_partial'] = True
            size = None
        else:
            row['disk_bytes'] += size
            measured.add(group)
        components[container['Id'][:12]] = size
        members.setdefault(group, []).append(container['Id'][:12])
        # Writable bind mounts aren't measured by Docker df. Signal incomplete attribution.
        if any(m.get('Type') == 'bind' and m.get('RW', True) for m in mounts):
            row['disk_partial'] = True
    image_owners = {}
    for container in containers:
        image_id = container.get('ImageID')
        if not image_id:
            continue
        labels = container.get('Labels') or {}
        owner = (group_for(labels['com.docker.compose.service'])
                 if labels.get('com.docker.compose.project') == project
                 and labels.get('com.docker.compose.service') else None)
        image_owners.setdefault(image_id, set()).add(owner)
    image_exclusive = 0
    for img in usage.get('Images') or []:
        owners_for_image = image_owners.get(img.get('Id'), set())
        size, shared = img.get('Size'), img.get('SharedSize')
        if len(owners_for_image) != 1 or None in owners_for_image:
            continue
        if not isinstance(size, (int, float)) or not isinstance(shared, (int, float)):
            continue
        if size < 0 or shared < 0 or size < shared:
            continue
        owner = next(iter(owners_for_image))
        exclusive = size - shared
        if owner in groups:
            groups[owner]['disk_bytes'] += exclusive
            groups[owner]['disk_images_bytes'] = groups[owner].get('disk_images_bytes', 0) + exclusive
            image_exclusive += exclusive
            measured.add(owner)
    volumes = {v['Name']: v for v in usage.get('Volumes') or []}
    for name, users in owners.items():
        known = users - {None}
        if len(users) != 1 or not known:
            for group in known:
                groups[group]['disk_partial'] = True
            continue
        group = next(iter(known))
        volume = volumes.get(name, {})
        size = (volume.get('UsageData') or {}).get('Size')
        mountpoint = volume.get('Mountpoint', '')
        try:
            path = Path(root) / mountpoint.lstrip('/')
            # The existing read-only host mount is used only for filesystem metadata.
            same_disk = mountpoint.startswith('/') and '..' not in Path(mountpoint).parts and os.stat(path).st_dev == root_device
        except OSError:
            same_disk = False
        if not same_disk or not isinstance(size, (int, float)) or size < 0:
            groups[group]['disk_partial'] = True
            continue
        groups[group]['disk_bytes'] += size
        groups[group]['disk_volumes_bytes'] += size
        measured.add(group)
    for group, row in groups.items():
        if group not in measured:
            row['disk_bytes'] = None
    return {'collected_at': int(time.time()), 'groups': groups, 'components': components, 'members': members,
            'partial': any(g['disk_partial'] for g in groups.values()),
            'images_exclusive_bytes': image_exclusive}


def open_history(path):
    db = sqlite3.connect(path, timeout=3)
    db.execute('PRAGMA journal_mode=DELETE')  # reader volume is read-only: no WAL/SHM writes
    db.execute('CREATE TABLE IF NOT EXISTS samples (ts INTEGER NOT NULL, service TEXT NOT NULL, '
               'memory REAL, working REAL, cpu REAL, PRIMARY KEY(ts, service))')
    db.commit()
    return db


def persist(db, snapshot):
    ts = snapshot['collected_at'] // 60 * 60
    rows = [(ts, '__host__', snapshot['host']['memory_used'], snapshot['host']['memory_used'],
             snapshot['host']['cpu_percent'])]
    for row in snapshot['services']:
        if not row['partial']:
            rows.append((ts, row['id'], row['memory_bytes'], row['working_bytes'], row['cpu_cores']))
    # Keep one observed sample per minute, with accurate collection time in current.json.
    with db:
        db.executemany('INSERT OR IGNORE INTO samples VALUES (?, ?, ?, ?, ?)', rows)
        db.execute('DELETE FROM samples WHERE ts < ?', (ts - RETENTION,))


class Collector:
    def __init__(self, data, proc, root, project):
        self.data, self.proc, self.root, self.project = data, proc, root, project
        self.previous_host = None
        self.previous_cpu = {}
        self.details = {}
        self.disk_pool = concurrent.futures.ThreadPoolExecutor(max_workers=1)
        self.disk_future = None
        self.disk_attempt = None
        self.disk = None
        self.disk_error = False
        self.host_capacity = None
        self.host_capacity_at = None

    def host_memory_capacity(self):
        if self.host_capacity_at is None or time.monotonic() - self.host_capacity_at >= 60:
            capacity = docker_get('/info').get('MemTotal')
            if not isinstance(capacity, int) or capacity <= 0:
                raise RuntimeError('Docker Engine returned no valid host memory capacity')
            self.host_capacity = capacity
            self.host_capacity_at = time.monotonic()
        return self.host_capacity

    def update_disk(self, services):
        if self.disk_future is not None and self.disk_future.done():
            try:
                self.disk = self.disk_future.result()
                self.disk_error = False
            except Exception:
                self.disk_error = True
            self.disk_future = None
        if self.disk_future is None and (self.disk_attempt is None or time.monotonic() - self.disk_attempt >= DISK_INTERVAL):
            self.disk_attempt = time.monotonic()
            self.disk_future = self.disk_pool.submit(lambda: disk_metrics(
                docker_get('/system/df?type=image&type=container&type=volume'), self.project, self.root))
        metadata = {'collected_at': self.disk['collected_at'] if self.disk else None,
                    'interval_seconds': DISK_INTERVAL, 'error': self.disk_error,
                    'stale': bool(self.disk and time.time() - self.disk['collected_at'] > DISK_INTERVAL + 60),
                    'partial': bool(self.disk and self.disk['partial']),
                    'images_exclusive_bytes': self.disk.get('images_exclusive_bytes', 0) if self.disk else None}
        for service in services:
            data = self.disk['groups'].get(service['id']) if self.disk else None
            service.update(data or {'disk_bytes': None, 'disk_volumes_bytes': None, 'disk_partial': True})
            # Changed containers make the cached service total incomplete until next disk sample.
            missing = bool(self.disk and any(c['id'] not in self.disk['components'] for c in service['components']))
            if self.disk and service['id'] in self.disk.get('members', {}):
                missing |= set(self.disk['members'][service['id']]) != {c['id'] for c in service['components']}
            if missing:
                service.update(disk_bytes=None, disk_partial=True)
            if data is None or missing:
                metadata['partial'] = bool(self.disk)
            for component in service['components']:
                component['disk_bytes'] = self.disk['components'].get(component['id']) if self.disk else None
        return metadata

    def component(self, container):
        identity = container['Id']
        service = container['Labels']['com.docker.compose.service']
        item = {'id': identity[:12], 'service': service, 'group': group_for(service),
                'state': container.get('State', 'unknown')}
        try:
            cached = self.details.get(identity)
            if cached is None or time.monotonic() - cached[0] >= 60:
                info = docker_get('/containers/' + identity + '/json')
                config = info.get('HostConfig', {})
                limit = config.get('NanoCpus', 0) / 1e9
                if not limit and config.get('CpuQuota', 0) > 0:
                    limit = config['CpuQuota'] / (config.get('CpuPeriod') or 100000)
                detail = {'memory_limit': config.get('Memory', 0) or None,
                          'cpu_limit': limit or None, 'restarts': info.get('RestartCount', 0),
                          'oom_killed': info.get('State', {}).get('OOMKilled', False)}
                self.details[identity] = (time.monotonic(), detail)
            item.update(self.details[identity][1])
            if item['state'] == 'running':
                stats = docker_get('/containers/' + identity + '/stats?stream=false&one-shot=true')
                item.update(memory_metrics(stats))
                item['cpu_cores'], self.previous_cpu[identity] = cpu_cores(stats, self.previous_cpu.get(identity))
                item['pids'] = stats.get('pids_stats', {}).get('current')
            else:
                self.previous_cpu.pop(identity, None)
                item.update(memory_bytes=0, working_bytes=0, cache_bytes=0, cpu_cores=0, pids=0)
        except Exception:
            item['error'] = True  # never leak Docker inspect/environment or host error details
        return item

    def collect(self):
        filters = quote(json.dumps({'label': ['com.docker.compose.project=' + self.project,
                                              'com.docker.compose.service']}))
        containers = docker_get('/containers/json?all=true&filters=' + filters)
        containers = [c for c in containers if c.get('Labels', {}).get('com.docker.compose.oneoff') != 'True']
        if not containers:
            raise RuntimeError('No project containers found')
        # Keep CPU, disk and container snapshots independent of host RAM discovery.
        # A virtualized procfs (e.g. a 96 MiB collector cgroup) is not VM RAM.
        host, self.previous_host = host_metrics(self.proc, self.root, self.previous_host)
        try:
            capacity = self.host_memory_capacity()
        except Exception:
            capacity = None
        memory_fields = ('memory_total', 'memory_available', 'memory_used',
                         'memory_cache', 'swap_total', 'swap_used')
        # Host sampler runs directly on the VM, outside the 96 MiB cgroup.
        # Trust only recent values matching the Docker Engine host capacity.
        host_sample = None
        if capacity is not None:
            try:
                sample = json.loads((self.data / 'host-memory.json').read_text())
                total = sample['memory_total']
                available = sample['memory_available']
                timestamp = sample['collected_at']
                if (type(total) is int and type(available) is int and type(timestamp) is int
                    and 0 <= time.time() - timestamp < 45
                    and 0 <= available <= total
                    and abs(total - capacity) <= max(16 * 1024 * 1024, capacity * .02)
                    and all(type(sample.get(key)) is int and sample[key] >= 0
                            for key in memory_fields)):
                    host_sample = {key: sample[key] for key in memory_fields}
            except (OSError, ValueError, KeyError, TypeError):
                pass
        if host_sample is not None:
            host.update(host_sample)
            host['memory_source'] = 'host'
        elif capacity is None:
            # Docker info is unavailable: refuse to advertise a cgroup limit as VM capacity.
            host.update({field: None for field in memory_fields})
            host['memory_unavailable'] = True
        elif abs(host['memory_total'] - capacity) > max(16 * 1024 * 1024, capacity * .02):
            try:
                replacement, _ = host_metrics(Path(self.root) / 'proc', self.root, None, capacity)
                host.update({field: replacement[field] for field in memory_fields})
            except (OSError, KeyError, ValueError, RuntimeError):
                try:
                    host.update(syscall_memory(capacity))
                    host['memory_estimated'] = True
                except (OSError, ValueError, RuntimeError):
                    host.update({field: None for field in memory_fields})
                    # Docker Engine still supplies the verified total capacity.
                    host['memory_total'] = capacity
                    host['memory_unavailable'] = True
        with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
            components = list(pool.map(self.component, containers))
        live = {c['Id'] for c in containers}
        self.details = {key: value for key, value in self.details.items() if key in live}
        self.previous_cpu = {key: value for key, value in self.previous_cpu.items() if key in live}
        services = aggregate(components)
        disk = self.update_disk(services)
        return {'collected_at': int(time.time()), 'interval_seconds': INTERVAL,
                'host': host, 'services': services, 'disk': disk,
                'partial': any(c.get('error') for c in components)}


def main():
    data = Path(os.environ.get('RESOURCE_MONITOR_DATA', '/data'))
    data.mkdir(parents=True, exist_ok=True)
    collector = Collector(data, Path('/host/proc'), '/host/root', os.environ.get('RESOURCE_MONITOR_PROJECT', 'irlix-services'))
    db = open_history(data / 'history.sqlite')
    last_minute = None
    while True:
        started = time.monotonic()
        try:
            snapshot = collector.collect()
            if snapshot['collected_at'] // 60 != last_minute:
                persist(db, snapshot)
                last_minute = snapshot['collected_at'] // 60
            temporary = data / 'current.tmp'
            temporary.write_text(json.dumps(snapshot, separators=(',', ':')))
            temporary.replace(data / 'current.json')
        except Exception as exc:
            # Only fixed error categories are logged. Do not expose Docker/env/paths.
            known = ('Host memory source does not match Docker Engine VM capacity',
                     'Host syscall memory does not match Docker Engine capacity',
                     'Host sysinfo syscall failed',
                     'Docker Engine returned no valid host memory capacity',
                     'Docker metrics unavailable', 'Docker API negotiation failed')
            reason = str(exc) if str(exc) in known else type(exc).__name__
            print(f'Resource collection failed ({reason}); last snapshot retained', flush=True)
        time.sleep(max(1, INTERVAL - (time.monotonic() - started)))


if __name__ == '__main__':
    main()
