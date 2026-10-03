#!/usr/bin/env python3
"""Allowlisted snapshot/restore runner, reachable only over a shared Unix socket."""

import http.server
import json
import os
from pathlib import Path
import secrets
import socketserver
import subprocess
import threading
import time

ROOT = Path('/opt/irlix-services')
SNAPSHOTS = Path('/snapshots')
OPS = Path('/ops')
SOCKET = OPS / 'migration-ops.sock'
STATUS = OPS / 'status.json'
LOCK = threading.Lock()


def read_status():
    try:
        return json.loads(STATUS.read_text())
    except (OSError, ValueError):
        return {'state': 'idle'}


def save_status(value):
    temporary = STATUS.with_suffix('.tmp')
    temporary.write_text(json.dumps(value, ensure_ascii=False))
    os.replace(temporary, STATUS)


def snapshots():
    result = []
    for path in SNAPSHOTS.iterdir():
        if not path.name.isdecimal() or not (path / 'manifest.json').is_file():
            continue
        try:
            manifest = json.loads((path / 'manifest.json').read_text())
            result.append({
                'id': path.name,
                'created_at': manifest['created_at_utc'],
                'restored': (path / 'restored').exists(),
                'last_migration_run_id': manifest['last_migration_run_id'],
            })
        except (OSError, ValueError, KeyError):
            continue
    return sorted(result, key=lambda item: item['created_at'], reverse=True)[:30]


def execute(operation):
    save_status({**operation, 'state': 'running'})
    script = 'migration_test_snapshot.py' if operation['action'] == 'snapshot' else 'migration_test_rollback.py'
    args = ['python3', str(ROOT / 'scripts' / script), operation['snapshot_id']]
    env = {**os.environ, 'MIGRATION_SNAPSHOT_BASE': str(SNAPSHOTS), 'MIGRATION_DATA_PATH': '/data'}
    try:
        result = subprocess.run(args, cwd=ROOT, env=env, capture_output=True, text=True, timeout=3600)
        if result.returncode:
            # Keep bounded diagnostics. Secrets and archive contents must never enter API output.
            save_status({**operation, 'state': 'failed', 'finished_at': time.time(),
                         'message': 'Операция не выполнена. Проверьте журнал migration-ops на стенде.'})
            print(f"{operation['action']} {operation['snapshot_id']} failed: {result.stderr[-1200:]}", flush=True)
        else:
            save_status({**operation, 'state': 'completed', 'finished_at': time.time(),
                         'message': 'Снимок готов.' if operation['action'] == 'snapshot' else 'Откат завершён.'})
            print(f"{operation['action']} {operation['snapshot_id']} completed", flush=True)
    except Exception as exc:
        save_status({**operation, 'state': 'failed', 'finished_at': time.time(),
                     'message': 'Операция прервана. Проверьте журнал migration-ops на стенде.'})
        print(f"{operation['action']} {operation['snapshot_id']} interrupted: {exc}", flush=True)


class Handler(http.server.BaseHTTPRequestHandler):
    def reply(self, code, payload):
        body = json.dumps(payload, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)
        self.wfile.flush()

    def do_GET(self):
        if self.path != '/state':
            return self.reply(404, {'message': 'Not found'})
        return self.reply(200, {'operation': read_status(), 'snapshots': snapshots()})

    def do_POST(self):
        if self.path not in ('/snapshot', '/restore'):
            return self.reply(404, {'message': 'Not found'})
        if int(self.headers.get('Content-Length', '0')) > 256:
            return self.reply(413, {'message': 'Request too large'})
        try:
            body = json.loads(self.rfile.read(int(self.headers.get('Content-Length', '0'))) or b'{}')
            if not isinstance(body, dict):
                raise ValueError('Expected an object')
        except (ValueError, TypeError):
            return self.reply(400, {'message': 'Invalid JSON'})
        with LOCK:
            if read_status().get('state') in ('queued', 'running'):
                return self.reply(409, {'message': 'Операция со снимком уже выполняется.'})
            action = 'snapshot' if self.path == '/snapshot' else 'restore'
            snapshot_id = str(int(time.time() * 1000)) + f'{secrets.randbelow(1000):03d}' if action == 'snapshot' else str(body.get('snapshot_id', ''))
            if not snapshot_id.isdecimal() or (action == 'restore' and not any(s['id'] == snapshot_id and not s['restored'] for s in snapshots())):
                return self.reply(422, {'message': 'Снимок не найден или уже восстановлен.'})
            operation = {'id': snapshot_id, 'action': action, 'snapshot_id': snapshot_id,
                         'state': 'queued', 'started_at': time.time()}
            save_status(operation)
            self.reply(202, {'operation': operation})
            threading.Thread(target=execute, args=(operation,), daemon=True).start()

    def log_message(self, format, *args):
        pass


class Server(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True


if __name__ == '__main__':
    OPS.mkdir(parents=True, exist_ok=True)
    SNAPSHOTS.mkdir(parents=True, exist_ok=True)
    SOCKET.unlink(missing_ok=True)
    # A restart cannot prove an interrupted restore succeeded.
    previous = read_status()
    if previous.get('state') in ('queued', 'running'):
        save_status({**previous, 'state': 'failed', 'message': 'Операция прервана перезапуском migration-ops.'})
    with Server(str(SOCKET), Handler) as server:
        os.chmod(SOCKET, 0o600)
        server.serve_forever()
