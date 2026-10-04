#!/usr/bin/env python3
"""Restore a Vacations migration snapshot after explicit operator confirmation."""
import argparse, fcntl, hashlib, json, os, shutil, sqlite3, subprocess, sys, tarfile
from pathlib import Path

ROOT=Path('/opt/irlix-services')
BASE=Path(os.environ.get('MIGRATION_SNAPSHOT_BASE',str(ROOT/'.ci/migration-test-snapshots')))/'vacations'
FK_SQL="""SELECT count(*) FROM pg_constraint c JOIN pg_class s ON s.oid=c.conrelid JOIN pg_namespace sn ON sn.oid=s.relnamespace JOIN pg_class d ON d.oid=c.confrelid JOIN pg_namespace dn ON dn.oid=d.relnamespace WHERE c.contype='f' AND dn.nspname='vacations' AND sn.nspname<>'vacations'"""

def run(a,stdin=None):
 r=subprocess.run(a,cwd=ROOT,stdin=stdin,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 if r.returncode: raise RuntimeError(r.stderr.decode(errors='replace')[-1200:])
 return r.stdout.decode().strip()

def compose(*a):
 b=['docker','compose'] if subprocess.run(['docker','compose','version'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode==0 else ['docker-compose']
 return run([*b,'-f','docker-compose.yml','-f','docker-compose.migration.yml','--env-file','.env',*a])

def cid(name):
 v=compose('ps','-q',name)
 if not v: raise RuntimeError(f'{name} container is missing')
 return v

def digest(p):
 h=hashlib.sha256()
 with p.open('rb') as f:
  for c in iter(lambda:f.read(1048576),b''): h.update(c)
 return h.hexdigest()

def volume():
 p=Path(os.environ['MIGRATION_DATA_PATH']) if os.environ.get('MIGRATION_DATA_PATH') else Path(run(['docker','inspect','--format','{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}',cid('migration')]))
 if not (p/'migration.sqlite').is_file(): raise RuntimeError('Migration metadata volume is missing')
 return p

def main(sid):
 if os.geteuid()!=0 or not sid.isdigit(): raise RuntimeError('numeric snapshot ID and root required')
 BASE.mkdir(mode=0o700,parents=True,exist_ok=True)
 with (BASE/'.lock').open('w') as lock:
  fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  snap=BASE/sid; m=json.loads((snap/'manifest.json').read_text()); dump=snap/'vacations.dump'; arc=snap/'migration-data.tar.gz'
  if m.get('snapshot_id')!=sid or m.get('service')!='vacations' or digest(dump)!=m['vacations_dump_sha256'] or digest(arc)!=m['migration_data_sha256']: raise RuntimeError('snapshot validation failed')
  if (snap/'restored').exists(): raise RuntimeError('snapshot already restored')
  fk=run(['docker','exec',cid('postgres'),'sh','-c','PGPASSWORD="$POSTGRES_PASSWORD" psql -X -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atqc "$1"','sh',FK_SQL])
  if fk!='0': raise RuntimeError('external foreign key into vacations blocks restore')
  v=volume()
  with sqlite3.connect(f'file:{v/"migration.sqlite"}?mode=ro',uri=True) as db:
   if db.execute("SELECT count(*) FROM migration_runs WHERE status IN ('queued','running')").fetchone()[0]: raise RuntimeError('active migration exists')
   if db.execute('SELECT count(*) FROM migration_runs WHERE id>? AND service<>?',(m['last_migration_run_id'],'vacations')).fetchone()[0]: raise RuntimeError('another service migrated after snapshot')
  with tarfile.open(arc,'r:gz') as t:
   members=t.getmembers()
   if any(x.name.startswith('/') or '..' in Path(x.name).parts or not(x.isfile() or x.isdir()) for x in members): raise RuntimeError('unsafe metadata archive')
  compose('stop','migration-worker','migration','vacations')
  tmp=v/f'.vacations-restore-{sid}'
  try:
   with dump.open('rb') as f: run(['docker','exec','-i',cid('postgres'),'sh','-c','PGPASSWORD="$POSTGRES_PASSWORD" pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --exit-on-error --single-transaction'],f)
   shutil.rmtree(tmp,ignore_errors=True); tmp.mkdir()
   with tarfile.open(arc,'r:gz') as t: t.extractall(tmp,members=members)
   for name in ('migration.sqlite','migration.sqlite-wal','migration.sqlite-shm','migration-credential.key'):
    dst=v/name; src=tmp/name; dst.unlink(missing_ok=True)
    if src.exists(): os.replace(src,dst)
   shutil.rmtree(tmp,ignore_errors=True)
   with sqlite3.connect(f'file:{v/"migration.sqlite"}?mode=ro',uri=True) as db:
    if db.execute('PRAGMA quick_check').fetchone()[0]!='ok': raise RuntimeError('restored metadata DB integrity check failed')
   (snap/'restored').write_text('restored\n')
  except Exception:
   shutil.rmtree(tmp,ignore_errors=True); print('restore interrupted; services remain stopped',file=sys.stderr); raise
  compose('start','vacations'); compose('start','migration'); compose('start','migration-worker')
  run(['sh','scripts/verify-migration.sh']); print(f'Vacations restored from {sid}')

if __name__=='__main__':
 p=argparse.ArgumentParser(); p.add_argument('snapshot_id'); a=p.parse_args()
 try: main(a.snapshot_id)
 except Exception as e: print(f'Vacations rollback failed: {e}',file=sys.stderr); sys.exit(1)
