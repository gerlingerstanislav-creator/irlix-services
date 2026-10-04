#!/usr/bin/env python3
"""Allowlisted snapshot/restore runner, reachable only over a shared Unix socket."""

import http.server, json, os, secrets, shutil, socketserver, subprocess, threading, time
from pathlib import Path

ROOT=Path('/opt/irlix-services'); SNAPSHOTS=Path('/snapshots'); OPS=Path('/ops')
SOCKET=OPS/'migration-ops.sock'; STATUS=OPS/'status.json'; LOCK=threading.Lock()


def read_status():
    try: return json.loads(STATUS.read_text())
    except (OSError,ValueError): return {'state':'idle'}


def save_status(value):
    tmp=STATUS.with_suffix('.tmp'); tmp.write_text(json.dumps(value,ensure_ascii=False)); os.replace(tmp,STATUS)


def base(service):
    return SNAPSHOTS if service=='employees' else SNAPSHOTS/service


def snapshots(service='employees'):
    root=base(service); root.mkdir(parents=True,exist_ok=True); result=[]
    for path in root.iterdir():
        if not path.name.isdecimal() or not (path/'manifest.json').is_file(): continue
        try:
            manifest=json.loads((path/'manifest.json').read_text())
            if service!='employees' and manifest.get('service')!=service: continue
            result.append({'id':path.name,'created_at':manifest['created_at_utc'],'restored':(path/'restored').exists(),'last_migration_run_id':manifest['last_migration_run_id']})
        except (OSError,ValueError,KeyError): continue
    return sorted(result,key=lambda item:item['created_at'],reverse=True)[:30]


def execute(operation):
    save_status({**operation,'state':'running'})
    service=operation['service']; action=operation['action']
    if service=='employees': script='migration_test_snapshot.py' if action=='snapshot' else 'migration_test_rollback.py'
    else: script='migration_vacations_snapshot.py' if action=='snapshot' else 'migration_vacations_rollback.py'
    args=['python3',str(ROOT/'scripts'/script),operation['snapshot_id']]
    env={**os.environ,'MIGRATION_SNAPSHOT_BASE':str(SNAPSHOTS),'MIGRATION_DATA_PATH':'/data'}
    try:
        result=subprocess.run(args,cwd=ROOT,env=env,capture_output=True,text=True,timeout=3600)
        if result.returncode:
            save_status({**operation,'state':'failed','finished_at':time.time(),'message':'Операция не выполнена. Проверьте журнал migration-ops на стенде.'})
            print(f"{service} {action} {operation['snapshot_id']} failed: {result.stderr[-1200:]}",flush=True)
        else:
            save_status({**operation,'state':'completed','finished_at':time.time(),'message':'Снимок готов.' if action=='snapshot' else 'Откат завершён.'})
            print(f"{service} {action} {operation['snapshot_id']} completed",flush=True)
    except Exception as exc:
        save_status({**operation,'state':'failed','finished_at':time.time(),'message':'Операция прервана. Проверьте журнал migration-ops на стенде.'})
        print(f"{service} {action} {operation['snapshot_id']} interrupted: {exc}",flush=True)


class Handler(http.server.BaseHTTPRequestHandler):
    def reply(self,code,payload):
        body=json.dumps(payload,ensure_ascii=False).encode(); self.send_response(code); self.send_header('Content-Type','application/json; charset=utf-8'); self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body); self.wfile.flush()

    def route(self):
        parts=[p for p in self.path.split('/') if p]
        if parts and parts[0]=='vacations': return 'vacations','/'+('/'.join(parts[1:]))
        return 'employees',self.path

    def do_GET(self):
        service,path=self.route()
        if path!='/state': return self.reply(404,{'message':'Not found'})
        status=read_status()
        operation=status if status.get('service',service)==service else {'state':'idle'}
        return self.reply(200,{'operation':operation,'snapshots':snapshots(service)})

    def do_POST(self):
        service,path=self.route()
        if path not in ('/snapshot','/restore'): return self.reply(404,{'message':'Not found'})
        if int(self.headers.get('Content-Length','0'))>256: return self.reply(413,{'message':'Request too large'})
        try:
            body=json.loads(self.rfile.read(int(self.headers.get('Content-Length','0'))) or b'{}')
            if not isinstance(body,dict): raise ValueError()
        except (ValueError,TypeError): return self.reply(400,{'message':'Invalid JSON'})
        with LOCK:
            if read_status().get('state') in ('queued','running'): return self.reply(409,{'message':'Операция со снимком уже выполняется.'})
            action='snapshot' if path=='/snapshot' else 'restore'
            sid=str(int(time.time()*1000))+f'{secrets.randbelow(1000):03d}' if action=='snapshot' else str(body.get('snapshot_id',''))
            if not sid.isdecimal() or (action=='restore' and not any(s['id']==sid and not s['restored'] for s in snapshots(service))): return self.reply(422,{'message':'Снимок не найден или уже восстановлен.'})
            operation={'id':sid,'service':service,'action':action,'snapshot_id':sid,'state':'queued','started_at':time.time()}
            save_status(operation); self.reply(202,{'operation':operation})
            timer=threading.Timer(1.0,execute,args=(operation,)); timer.daemon=True; timer.start()

    def do_DELETE(self):
        service,path=self.route(); prefix='/snapshot/'
        if not path.startswith(prefix): return self.reply(404,{'message':'Not found'})
        sid=path[len(prefix):]
        if not sid.isdecimal(): return self.reply(422,{'message':'Некорректный ID снимка.'})
        with LOCK:
            if read_status().get('state') in ('queued','running'): return self.reply(409,{'message':'Операция со снимком уже выполняется.'})
            target=base(service)/sid
            if not target.is_dir() or not (target/'manifest.json').is_file(): return self.reply(404,{'message':'Точка отката не найдена.'})
            try: shutil.rmtree(target)
            except OSError as exc:
                print(f'delete {service} snapshot {sid} failed: {exc}',flush=True); return self.reply(500,{'message':'Не удалось удалить точку отката.'})
            status=read_status()
            if status.get('service')==service and str(status.get('snapshot_id',''))==sid: save_status({'state':'idle'})
            return self.reply(200,{'deleted':True,'snapshot_id':sid,'service':service})

    def log_message(self,format,*args): pass


class Server(socketserver.ThreadingMixIn,socketserver.UnixStreamServer): daemon_threads=True

if __name__=='__main__':
    OPS.mkdir(parents=True,exist_ok=True); SNAPSHOTS.mkdir(parents=True,exist_ok=True); SOCKET.unlink(missing_ok=True)
    previous=read_status()
    if previous.get('state') in ('queued','running'): save_status({**previous,'state':'failed','message':'Операция прервана перезапуском migration-ops.'})
    with Server(str(SOCKET),Handler) as server:
        os.chmod(SOCKET,0o600); server.serve_forever()
