#!/usr/bin/env python3
"""Allowlisted snapshot/restore runner, reachable only over a shared Unix socket."""

import http.server, json, os, secrets, shutil, socketserver, subprocess, threading, time
from pathlib import Path
import migration_console as console
import migration_control as control
import migration_retention as retention
import migration_diagnostics as diagnostics
import uuid
import traceback

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


def operation_diagnostics(operation, detail):
    key=operation['diagnostic_id']
    child=diagnostics.failed(detail,key)
    return {**{'diagnostic_id':key,'scope':operation['service'],
        'snapshot_id':operation['snapshot_id'],'stage':'execution','database_outcome':'unknown'},
        **child}


def execute(operation):
    operation = {**operation, 'diagnostic_id': uuid.uuid4().hex}
    save_status({**operation,'state':'running'})
    service=operation['service']; action=operation['action']
    if service=='employees': script='migration_test_snapshot.py' if action=='snapshot' else 'migration_test_rollback.py'
    else: script='migration_vacations_snapshot.py' if action=='snapshot' else 'migration_vacations_rollback.py'
    args=['python3',str(ROOT/'scripts'/script),operation['snapshot_id']]
    env={**os.environ,'MIGRATION_SNAPSHOT_BASE':str(SNAPSHOTS),'MIGRATION_DATA_PATH':'/data', 'MIGRATION_DIAGNOSTIC_ID':operation['diagnostic_id']}
    try:
        result=subprocess.run(args,cwd=ROOT,env=env,capture_output=True,text=True,timeout=3600)
        diagnostic=diagnostics.read(operation['diagnostic_id'])
        operation={**operation, 'diagnostics':diagnostic}
        if result.returncode:
            operation['diagnostics']=operation_diagnostics(operation, (result.stderr or '') + '\n' + (result.stdout or ''))
            save_status({**operation,'state':'failed','finished_at':time.time(),'message':'Операция не выполнена. Проверьте журнал migration-ops на стенде.'})
            print(f"{service} {action} failed; diagnostic {operation['diagnostic_id']}",flush=True)
        else:
            save_status({**operation,'state':'completed','finished_at':time.time(),'message':'Снимок готов.' if action=='snapshot' else 'Откат завершён.'})
            print(f"{service} {action} {operation['snapshot_id']} completed",flush=True)
    except Exception as exc:
        detail=traceback.format_exc()
        if isinstance(exc,subprocess.TimeoutExpired): detail += '\n' + str(exc.stdout or '') + '\n' + str(exc.stderr or '')
        operation['diagnostics']=operation_diagnostics(operation,detail)
        save_status({**operation,'state':'failed','finished_at':time.time(),'message':'Операция прервана. Проверьте журнал migration-ops на стенде.'})
        print(f"{service} {action} {operation['snapshot_id']} interrupted; diagnostic {operation['diagnostic_id']}",flush=True)


class Handler(http.server.BaseHTTPRequestHandler):
    def reply(self,code,payload):
        body=json.dumps(payload,ensure_ascii=False).encode(); self.send_response(code); self.send_header('Content-Type','application/json; charset=utf-8'); self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body); self.wfile.flush()

    def route(self):
        parts=[p for p in self.path.split('/') if p]
        if parts and parts[0]=='vacations': return 'vacations','/'+('/'.join(parts[1:]))
        return 'employees',self.path

    def do_GET(self):
        service,path=self.route()
        if self.path=='/control/state': return self.reply(200,{**control.payload(),'snapshot_operation':read_status()})
        if self.path.startswith('/control/operations/') and (self.path.endswith('/report') or self.path.endswith('/summary')):
            parts=self.path.split('/')
            if len(parts)!=5: return self.reply(404,{'message':'Not found'})
            try:
                value = control.summary(parts[3]) if parts[4]=='summary' else control.report(parts[3])
                return self.reply(200,value)
            except ValueError: return self.reply(422,{'message':'Некорректный ID операции'})
            except FileNotFoundError: return self.reply(404,{'message':'Отчёт не найден'})
            except Exception as exc:
                # Unexpected full-report failures must never terminate a server
                # request without JSON, or disclose private legacy source values.
                print('Control full report failed: '+type(exc).__name__,flush=True)
                return self.reply(503,{'message':'Доказательный отчёт временно недоступен.',
                                       'code':'REPORT_AGGREGATION_UNAVAILABLE'})
        if self.path=='/console/state': return self.reply(200,{**console.payload(),'snapshot_operation':read_status()})
        if path!='/state': return self.reply(404,{'message':'Not found'})
        status=read_status()
        operation=status if status.get('service','employees')==service else {'state':'idle'}
        return self.reply(200,{'operation':operation,'snapshots':snapshots(service)})

    def do_POST(self):
        service,path=self.route()
        if path not in ('/snapshot','/restore','/console/start','/console/restore','/console/snapshot','/control/start','/control/cancel'): return self.reply(404,{'message':'Not found'})
        if int(self.headers.get('Content-Length','0'))>4096: return self.reply(413,{'message':'Request too large'})
        try:
            body=json.loads(self.rfile.read(int(self.headers.get('Content-Length','0'))) or b'{}')
            if not isinstance(body,dict): raise ValueError()
        except (ValueError,TypeError): return self.reply(400,{'message':'Invalid JSON'})
        with LOCK:
            if path=='/control/cancel':
                try: return self.reply(202,{'operation':control.cancel(body)})
                except ValueError as exc: return self.reply(422,{'message':str(exc)})
            if console.active() or read_status().get('state') in ('queued','running'): return self.reply(409,{'message':'Операция со снимком уже выполняется.'})
            if path=='/control/start':
                try: operation=control.start(body)
                except ValueError as exc: return self.reply(422,{'message':str(exc)})
                except control.AuditError as exc: return self.reply(503,{'message':str(exc)})
                self.reply(202,{'operation':operation})
                threading.Thread(target=control.execute,args=(operation,),daemon=True).start()
                return
            if path.startswith('/console/'):
                try: operation=console.start(body, {'/console/start':'migrate', '/console/restore':'restore', '/console/snapshot':'snapshot'}[path])
                except ValueError as exc: return self.reply(422,{'message':str(exc)})
                self.reply(202,{'operation':operation})
                thread=threading.Thread(target=console.execute,args=(operation,),daemon=True); thread.start()
                return
            action='snapshot' if path=='/snapshot' else 'restore'
            sid=str(int(time.time()*1000))+f'{secrets.randbelow(1000):03d}' if action=='snapshot' else str(body.get('snapshot_id',''))
            if not sid.isdecimal() or (action=='restore' and not any(s['id']==sid for s in snapshots(service))): return self.reply(422,{'message':'Снимок не найден.'})
            operation={'id':sid,'service':service,'action':action,'snapshot_id':sid,'state':'queued','started_at':time.time()}
            save_status(operation); self.reply(202,{'operation':operation})
            timer=threading.Timer(1.0,execute,args=(operation,)); timer.daemon=True; timer.start()

    def do_PUT(self):
        if self.path != '/console/snapshot-retention':
            return self.reply(404, {'message': 'Not found'})
        try:
            length = int(self.headers.get('Content-Length', '0'))
            if length <= 0 or length > 256:
                return self.reply(413, {'message': 'Некорректный размер запроса.'})
            body = json.loads(self.rfile.read(length))
            if not isinstance(body, dict):
                raise ValueError('Некорректные параметры.')
            count = retention.validate_count(body.get('count'))
        except (ValueError, TypeError, json.JSONDecodeError) as exc:
            return self.reply(422, {'message': str(exc) or 'Некорректная настройка.'})
        with LOCK:
            try:
                return self.reply(200, retention.cleanup(count))
            except (RuntimeError, BlockingIOError, sqlite3.Error) as exc:
                return self.reply(409, {'message': str(exc)})
            except OSError as exc:
                print('Retention operation error: ' + type(exc).__name__, flush=True)
                return self.reply(503, {'message': 'Не удалось обновить правило или выполнить очистку.'})

    def do_DELETE(self):
        if self.path.startswith('/console/snapshots/'):
            parts = self.path.split('/')
            if len(parts) != 5: return self.reply(422, {'message':'Некорректный путь бэкапа.'})
            with LOCK:
                if console.active() or read_status().get('state') in ('queued','running'): return self.reply(409, {'message':'Дождитесь завершения активной операции.'})
                try: return self.reply(200, console.delete_checkpoint(parts[3], parts[4]))
                except ValueError as exc: return self.reply(422, {'message':str(exc)})
                except FileNotFoundError: return self.reply(404, {'message':'Бэкап не найден.'})
                except (RuntimeError, BlockingIOError): return self.reply(409, {'message':'Дождитесь завершения активной операции.'})
                except Exception as exc:
                    print(f'Console backup deletion failed: {type(exc).__name__}', flush=True)
                    return self.reply(500, {'message':'Не удалось удалить бэкап.'})
        service,path=self.route(); prefix='/snapshot/'
        if not path.startswith(prefix): return self.reply(404,{'message':'Not found'})
        sid=path[len(prefix):]
        if not sid.isdecimal(): return self.reply(422,{'message':'Некорректный ID снимка.'})
        with LOCK:
            if console.active() or read_status().get('state') in ('queued','running'): return self.reply(409,{'message':'Операция со снимком уже выполняется.'})
            target=base(service)/sid
            if not target.is_dir() or not (target/'manifest.json').is_file(): return self.reply(404,{'message':'Точка отката не найдена.'})
            try: shutil.rmtree(target)
            except OSError as exc:
                print(f'delete {service} snapshot {sid} failed: {exc}',flush=True); return self.reply(500,{'message':'Не удалось удалить точку отката.'})
            status=read_status()
            if status.get('service','employees')==service and str(status.get('snapshot_id',''))==sid: save_status({'state':'idle'})
            return self.reply(200,{'deleted':True,'snapshot_id':sid,'service':service})

    def log_message(self,format,*args): pass


class Server(socketserver.ThreadingMixIn,socketserver.UnixStreamServer): daemon_threads=True

if __name__=='__main__':
    OPS.mkdir(parents=True,exist_ok=True); SNAPSHOTS.mkdir(parents=True,exist_ok=True); SOCKET.unlink(missing_ok=True)
    control.recover()
    console.recover()
    previous=read_status()
    if previous.get('state') in ('queued','running'): save_status({**previous,'state':'failed','message':'Операция прервана перезапуском migration-ops.'})
    # Apply the default (2) to existing snapshots immediately after a safe deploy,
    # not only after the first new checkpoint or the next nightly job.
    try:
        result = retention.cleanup()
        print('Migration checkpoint startup retention: removed=' + str(result['removed']), flush=True)
    except (RuntimeError, BlockingIOError, OSError, sqlite3.Error) as exc:
        print('Migration checkpoint startup retention deferred: ' + type(exc).__name__, flush=True)
    with Server(str(SOCKET),Handler) as server:
        os.chmod(SOCKET,0o600); server.serve_forever()
