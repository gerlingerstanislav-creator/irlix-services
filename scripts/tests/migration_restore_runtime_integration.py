#!/usr/bin/env python3
"""Real Docker/PostgreSQL rollback rehearsal, isolated synthetic CI containers only."""
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
        for name in ('migration_console_snapshot.py','migration_test_snapshot.py'):
            shutil.copyfile(REPO/'scripts'/name,root/'scripts'/name)
        (root/'data').mkdir(); (root/'snapshots').mkdir(); (root/'app').mkdir(); (root/'ops').mkdir()
        (root/'data'/'migration-credential.key').write_text('synthetic-credential-key')
        with sqlite3.connect(root/'data'/'migration.sqlite') as db:
            db.execute('CREATE TABLE migration_runs(id INTEGER PRIMARY KEY,service TEXT,status TEXT)')
        (root/'app'/'artisan').write_text('''<?php
if (getenv('SYNTHETIC_RUNTIME_TOKEN') !== 'deployed-runtime') { fwrite(STDERR,"synthetic deployed configuration missing\\n"); exit(73); }
if (!in_array($argv[1] ?? '', ['migrate','migrate:status'],true)) exit(74);
if (($argv[1] ?? '') === 'migrate' && file_exists('/app/fail-migrate')) { fwrite(STDERR,"synthetic migration failure\\n"); exit(75); }
echo "Synthetic schema command passed\\n";
''')
        run(['docker','run','--rm','-v',str(root)+':/fixture','alpine:3.22','chown','-R','0:0','/fixture/data','/fixture/snapshots','/fixture/ops'])
        services={
            'postgres':{'image':'postgres:17-alpine','environment':{'POSTGRES_PASSWORD':'synthetic-password','POSTGRES_USER':'postgres','POSTGRES_DB':'synthetic'},'healthcheck':{'test':['CMD-SHELL','pg_isready -U postgres'],'interval':'1s','timeout':'5s','retries':30}},
            'clients':{'image':'php:8.4-cli-alpine','working_dir':'/app','command':['php','-r','sleep(3600);'],'environment':{'SYNTHETIC_RUNTIME_TOKEN':'deployed-runtime'},'volumes':[str(root/'app')+':/app:ro']},
            'migration':{'image':'php:8.4-cli-alpine','command':['php','-r','sleep(3600);']},
            'migration-worker':{'image':'php:8.4-cli-alpine','command':['php','-r','sleep(3600);']},
        }
        compose_file=root/'docker-compose.yml'
        compose_file.write_text(json.dumps({'name':project,'services':services}))
        (root/'docker-compose.migration.yml').write_text('{"services":{}}')
        (root/'.env').write_text('COMPOSE_PROJECT_NAME='+project+'\n')
        compose=['docker','compose','-f',str(compose_file),'-p',project]
        def cid(service): return run([*compose,'ps','-a','-q',service]).stdout.strip()
        def sql(query): return run(['docker','exec',cid('postgres'),'psql','-U','postgres','-d','synthetic','-Atqc',query]).stdout.strip()
        def ops(action):
            return run(['docker','run','--rm','--read-only','--cap-drop','ALL','--security-opt','no-new-privileges:true',
                '--tmpfs','/tmp','-e','MIGRATION_DATA_PATH=/data','-e','MIGRATION_SNAPSHOT_BASE=/snapshots',
                '-v','/var/run/docker.sock:/var/run/docker.sock','-v',str(root)+':/opt/irlix-services:ro',
                '-v',str(root/'data')+':/data','-v',str(root/'snapshots')+':/snapshots','-v',str(root/'ops')+':/ops',
                '--entrypoint','python3',image,'scripts/migration_console_snapshot.py',action,'clients','123'],check=False)
        def running(service): return run(['docker','inspect','--format','{{.State.Running}}',cid(service)]).stdout.strip()=='true'
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
            print('Real Docker/PostgreSQL restore passed: deployed environment, repeated restore, preflight safety and post-commit diagnostics.')
        finally:
            run([*compose,'down','-v','--remove-orphans'],check=False)
            run(['docker','run','--rm','-v',str(root)+':/fixture','alpine:3.22','chmod','-R','a+rwX','/fixture/data','/fixture/snapshots','/fixture/ops'],check=False)


if __name__=='__main__': main()
