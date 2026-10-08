"""Read-only Docker/host metrics collector. No listener and no Docker mutation API."""
import concurrent.futures
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
RETENTION = 7 * 86400
API_VERSION = None


class DockerConnection(http.client.HTTPConnection):
    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect('/var/run/docker.sock')


def docker_get(path):
    # This process can only call these read endpoints. Never expose arbitrary paths.
    if not re.fullmatch(r'/containers/json\?[^\s]+|/containers/[a-f0-9]{64}/(?:json|stats\?stream=false&one-shot=true)', path):
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
    connection = DockerConnection('localhost', timeout=4)
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


def host_metrics(proc, root, previous):
    memory = {}
    for line in (proc / 'meminfo').read_text().splitlines():
        key, value = line.split(':', 1)
        memory[key] = int(value.strip().split()[0]) * 1024
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
        host, self.previous_host = host_metrics(self.proc, self.root, self.previous_host)
        with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
            components = list(pool.map(self.component, containers))
        live = {c['Id'] for c in containers}
        self.details = {key: value for key, value in self.details.items() if key in live}
        self.previous_cpu = {key: value for key, value in self.previous_cpu.items() if key in live}
        return {'collected_at': int(time.time()), 'interval_seconds': INTERVAL,
                'host': host, 'services': aggregate(components),
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
        except Exception:
            print('Resource collection failed; last snapshot retained', flush=True)
        time.sleep(max(1, INTERVAL - (time.monotonic() - started)))


if __name__ == '__main__':
    main()
