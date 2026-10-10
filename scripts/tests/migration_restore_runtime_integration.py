#!/usr/bin/env python3
"""Real Docker/PostgreSQL rollback rehearsal, isolated synthetic CI containers only."""
import uuid
import json, os, shutil, sqlite3, subprocess, tempfile, time
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]


def run(args, *, check=True):
    result = subprocess.run(args, cwd=REPO, capture_output=True, text=True)
    if check and result.returncode:
        raise RuntimeError('Synthetic Docker rollback test failed: '+result.stderr[-3000:])
    return result


def main():
    image = 'irlix-synthetic-restore-ops:'+str(os.getpid())
    project = 'syntheticrestore'+str(os.getpid())
    run(['docker','build','-f','services/migration-ops/Dockerfile','-t',image,'.'])
    with tempfile.TemporaryDirectory(prefix='synthetic-restore-') as temp:
        root=Path(temp); root.chmod(0o755); (root/'scripts').mkdir()
        for name in ('migration_diagnostics.py','migration_console_snapshot.py','migration_test_snapshot.py'):
            shutil.copyfile(REPO/'scripts'/name,root/'scripts'/name)
        (root/'data').mkdir(); (root/'snapshots').mkdir(); (root/'app').mkdir(); (root/'ops').mkdir(); (root/'documents').mkdir()
        (root/'data'/'migration-credential.key').write_text('synthetic-credential-key')
        with sqlite3.connect(root/'data'/'migration.sqlite') as db:
            db.execute('CREATE TABLE migration_runs(id INTEGER PRIMARY KEY,service TEXT,mode TEXT,status TEXT,created_at TEXT,started_at TEXT)')
        (root/'app'/'artisan').write_text('''<?php
if (getenv('SYNTHETIC_RUNTIME_TOKEN') !== 'deployed-runtime') { fwrite(STDERR,"synthetic deployed configuration missing\\n"); exit(73); }
if (!in_array($argv[1] ?? '', ['migrate','migrate:status'],true)) exit(74);
if (($argv[1] ?? '') === 'migrate' && file_exists('/app/fail-migrate')) { fwrite(STDERR,"synthetic migration failure\\n"); exit(75); }
echo "Synthetic schema command passed\\n";
''')
        run(['docker','run','--rm','-v',str(root)+':/fixture','alpine:3.22','chown','-R','0:0','/fixture/data','/fixture/snapshots','/fixture/ops','/fixture/documents'])
        services={
            'postgres':{'image':'postgres:17-alpine','environment':{'POSTGRES_PASSWORD':'synthetic-password','POSTGRES_USER':'postgres','POSTGRES_DB':'synthetic'},'healthcheck':{'test':['CMD-SHELL','pg_isready -U postgres'],'interval':'1s','timeout':'5s','retries':30}},
            'clients':{'image':'php:8.4-cli-alpine','working_dir':'/app','command':['php','-r','sleep(3600);'],'environment':{'SYNTHETIC_RUNTIME_TOKEN':'deployed-runtime'},'volumes':[str(root/'app')+':/app:ro']},
            'migration':{'image':'php:8.4-cli-alpine','command':['php','-r','sleep(3600);']},
            'migration-worker':{'image':'php:8.4-cli-alpine','command':['php','-r','sleep(3600);']},
        }
        services['vacations'] = dict(services['clients'])
        services['vacations-calendar-sync'] = dict(services['migration-worker'])
        compose_file=root/'docker-compose.yml'
        compose_file.write_text(json.dumps({'name':project,'services':services}))
        (root/'docker-compose.migration.yml').write_text('{"services":{}}')
        (root/'.env').write_text('COMPOSE_PROJECT_NAME='+project+'\n')
        compose=['docker','compose','-f',str(compose_file),'-p',project]
        def cid(service): return run([*compose,'ps','-a','-q',service]).stdout.strip()
        def sql(query): return run(['docker','exec',cid('postgres'),'psql','-U','postgres','-d','synthetic','-Atqc',query]).stdout.strip()
        def ops(action, scope='clients', sid='123'):
            return run(['docker','run','--rm','--read-only','--cap-drop','ALL','--security-opt','no-new-privileges:true',
                '--tmpfs','/tmp','-e','MIGRATION_DATA_PATH=/data','-e','MIGRATION_SNAPSHOT_BASE=/snapshots',
                '-e','MIGRATION_DIAGNOSTIC_ID='+uuid.uuid4().hex,
                '-e','MIGRATION_VACATIONS_FILES_PATH=/documents',
                '-v','/var/run/docker.sock:/var/run/docker.sock','-v',str(root)+':/opt/irlix-services:ro',
                '-v',str(root/'data')+':/data','-v',str(root/'snapshots')+':/snapshots','-v',str(root/'ops')+':/ops',
                '-v',str(root/'documents')+':/documents',
                '--entrypoint','python3',image,'scripts/migration_console_snapshot.py',action,scope,sid],check=False)
        def running(service): return run(['docker','inspect','--format','{{.State.Running}}',cid(service)]).stdout.strip()=='true'
        def fixture(code, *args):
            # Restored metadata/ops files are owned by root, including on a
            # non-root GitHub runner. Access them via the isolated fixture image.
            return run(['docker','run','--rm','-v',str(root/'data')+':/data','-v',str(root/'ops')+':/ops',
                '--entrypoint','python3',image,'-c',code,*args])
        try:
            run([*compose,'up','-d','--wait'])
            sql('CREATE SCHEMA clients; CREATE TABLE clients.synthetic_records(id integer PRIMARY KEY); INSERT INTO clients.synthetic_records VALUES(1)')
            assert ops('snapshot').returncode==0,'Checkpoint creation failed'
            apiStarted=run(['docker','inspect','--format','{{.State.StartedAt}}',cid('migration')]).stdout.strip()
            sql('INSERT INTO clients.synthetic_records VALUES(2)')
            # Base compose can drift from the deployed environment: reproduce that condition.
            services['clients']['environment']['SYNTHETIC_RUNTIME_TOKEN']='base-compose-drift'
            compose_file.write_text(json.dumps({'name':project,'services':services}))
            mismatch=run([*compose,'run','--rm','--no-deps','clients','php','artisan','migrate:status'],check=False)
            assert mismatch.returncode==73,'Synthetic base-compose drift was not reproduced'
            restored=ops('restore')
            assert restored.returncode==0,restored.stderr
            assert sql('SELECT count(*) FROM clients.synthetic_records')=='1','Database not restored'
            assert all(running(s) for s in ('clients','migration','migration-worker')),'Writer was left stopped'
            assert ops('restore').returncode==0,'Repeated restore failed'
            assert run(['docker','inspect','--format','{{.State.StartedAt}}',cid('migration')]).stdout.strip()==apiStarted,'Restore stopped the operational API'
            # A real dependent-service restore must clear the logical blocker even
            # if a later whole-SQLite restore has reintroduced its old migrate row.
            sql('CREATE SCHEMA vacations; CREATE TABLE vacations.synthetic_records(id integer PRIMARY KEY); INSERT INTO vacations.synthetic_records VALUES(1)')
            assert ops('snapshot','vacations','456').returncode==0,'Vacations checkpoint failed'
            sql('INSERT INTO vacations.synthetic_records VALUES(2)')
            old='2000-01-01 00:00:00'
            def add_run(when):
                fixture('import sqlite3,sys; db=sqlite3.connect("/data/migration.sqlite"); '
                    'db.execute("INSERT INTO migration_runs VALUES (1,?,?,?,?,?)",'
                    '("vacations","migrate","completed",sys.argv[1],sys.argv[1])); db.commit()',when)
            add_run(old)
            assert ops('restore').returncode!=0,'Unrestored dependent-service import was accepted'
            assert all(running(s) for s in ('clients','migration','migration-worker')),'Guard failure stopped writers'
            started=time.time()
            vacation_restore=ops('restore','vacations','456')
            assert vacation_restore.returncode==0,vacation_restore.stderr
            assert sql('SELECT count(*) FROM vacations.synthetic_records')=='1','Vacations were not restored'
            fixture('import sys;from pathlib import Path;Path("/ops/console.json").write_text(sys.argv[1])',
                json.dumps({'history':[{'id':'synthetic-restore','action':'restore',
                'scope':'vacations','snapshot_id':'456','status':'completed','started_at':started,'finished_at':time.time()}]}))
            add_run(old)
            restored=ops('restore')
            assert restored.returncode==0,restored.stderr
            add_run(time.strftime('%Y-%m-%d %H:%M:%S',time.gmtime(time.time()+2)))
            assert ops('restore').returncode!=0,'Reused ID of a new dependent-service import bypassed the guard'
            fixture('import sqlite3;from pathlib import Path;db=sqlite3.connect("/data/migration.sqlite");'
                'db.execute("DELETE FROM migration_runs");db.commit();Path("/ops/console.json").unlink()')
            # Rehearsal must reject an old archive over an added object without stopping writers.
            sql('CREATE TABLE clients.synthetic_new_object(id integer)')
            assert ops('restore').returncode!=0,'Incompatible checkpoint was accepted'
            assert all(running(s) for s in ('clients','migration','migration-worker')),'Preflight stopped writers'
            sql('DROP TABLE clients.synthetic_new_object')
            # A post-COMMIT failure is diagnosed and cannot silently start inconsistent writers.
            (root/'app'/'fail-migrate').touch()
            assert ops('restore').returncode!=0,'Synthetic migration failure was ignored'
            diagnostic=json.loads(run(['docker','run','--rm','-v',str(root/'snapshots')+':/checkpoint:ro','--entrypoint','python3',image,'-c',
                'import json;from pathlib import Path;p=Path("/checkpoint/console");d=json.loads((p/".restore-status.json").read_text());d["log_mode"]=(p/".restore-error.log").stat().st_mode & 0o777;print(json.dumps(d))']).stdout)
            assert diagnostic['stage']=='schema-upgrade' and diagnostic['database_committed'] and diagnostic['metadata_complete']
            assert not running('clients'),'Failed schema upgrade restarted writer'
            assert running('migration'),'Failed restore stopped the operational API'
            assert diagnostic['log_mode']==0o600
            assert len(diagnostic['diagnostic_id'])==32 and diagnostic['failed_stage']=='schema-upgrade'
            assert diagnostic['log_saved']
            print('Real Docker/PostgreSQL restore passed: deployed environment, repeated restore, dependent-service rollback proof, reused IDs, preflight safety and post-commit diagnostics.')
        finally:
            run([*compose,'down','-v','--remove-orphans'],check=False)
            run(['docker','run','--rm','-v',str(root)+':/fixture','alpine:3.22','chmod','-R','a+rwX','/fixture/data','/fixture/snapshots','/fixture/ops','/fixture/documents'],check=False)


if __name__=='__main__': main()
