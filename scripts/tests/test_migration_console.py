import importlib, json, os, sqlite3, sys, tempfile, unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import migration_console as console
import migration_console_snapshot as snapshot
import migration_diagnostics as diagnostics


class CoordinatorTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.patch = patch.object(console, 'STATE', self.root / 'console.json')
        self.patch.start()
        db = sqlite3.connect(self.root / 'migration.sqlite')
        db.execute('CREATE TABLE migration_runs (id INTEGER PRIMARY KEY, service TEXT, mode TEXT, status TEXT, error TEXT)')
        db.commit(); db.close()
        self.env = patch.dict(os.environ, MIGRATION_DATA_PATH=str(self.root), MIGRATION_SNAPSHOT_BASE=str(self.root / 'snapshots'))
        self.env.start()
    def tearDown(self):
        self.env.stop(); self.patch.stop(); self.temp.cleanup()
    def operation(self):
        return console.start({'scope':'all','confirm':True,'requested_by':'synthetic-admin'}, 'migrate')
    def test_sequence_creates_global_and_individual_checkpoints_before_data(self):
        op = self.operation(); calls = []
        def checkpoint(op, scope):
            calls.append(('snapshot', scope)); return '1'
        def enqueue(service, mode, op, sid):
            calls.append((service, mode))
        with patch.object(console, 'checkpoint', side_effect=checkpoint), patch.object(console, 'enqueue', side_effect=enqueue):
            console.execute(op)
        self.assertEqual(calls, [('snapshot','all'), *[call for service in console.SERVICES for call in [('snapshot',service), *[(service,m) for m in ('inspect','dry-run','migrate','validate')]]]])
        self.assertEqual(console.state()['operation']['status'], 'completed')
    def test_manual_service_checkpoint_does_not_require_legacy_access_or_start_import(self):
        for scope in (*console.SERVICES, 'all'):
            op = console.start({'scope':scope}, 'snapshot')
            with patch.object(console, 'checkpoint', return_value='123') as checkpoint, patch.object(console, 'enqueue') as enqueue:
                console.execute(op)
            checkpoint.assert_called_once_with(op, scope)
            enqueue.assert_not_called()
            self.assertEqual(console.state()['operation']['status'], 'completed')
    def test_new_service_writers_and_global_schema_set(self):
        self.assertEqual(snapshot.SCOPES['all'], console.SERVICES)
        self.assertEqual(snapshot.writers('clients'), ['clients'])
        self.assertEqual(snapshot.writers('timesheets'), ['timesheets'])
        self.assertIn('vacations-calendar-sync', snapshot.writers('vacations'))
    def test_snapshot_failure_never_runs_import(self):
        with patch.object(console, 'checkpoint', side_effect=RuntimeError('snapshot failed')), patch.object(console, 'enqueue') as enqueue:
            console.execute(self.operation())
        enqueue.assert_not_called()
        self.assertEqual(console.state()['operation']['status'], 'failed')
    def test_dry_run_error_blocks_import_and_dependants(self):
        calls = []
        def enqueue(service, mode, *args):
            calls.append((service, mode))
            if mode == 'dry-run': raise RuntimeError('conflicts')
        with patch.object(console, 'checkpoint', return_value='1'), patch.object(console, 'enqueue', side_effect=enqueue):
            console.execute(self.operation())
        self.assertEqual(calls, [('employees','inspect'),('employees','dry-run')])
    def test_existing_old_page_job_blocks_reservation(self):
        with sqlite3.connect(self.root / 'migration.sqlite') as db:
            db.execute("INSERT INTO migration_runs VALUES (1,'employees','inspect','queued',NULL)")
        with self.assertRaises(ValueError): self.operation()
        self.assertFalse(console.active())
    def test_confirmation_and_scope_are_required(self):
        for body in ({'scope':'all'}, {'scope':'unknown','confirm':True}, {'scope':'all','confirm':'true'}):
            with self.assertRaises(ValueError): console.start(body,'migrate')
    def test_restore_reports_safe_failure_state_without_exposing_database_error(self):
        from subprocess import CompletedProcess
        for marker, expected in [('RESTORE_PREFLIGHT_FAILED','до остановки'),('RESTORE_TARGET_UNCHANGED','сервисы запущены'),('RESTORE_RECOVERY_REQUIRED','заблокирован')]:
            with self.subTest(marker=marker):
                operation={'id':1,'action':'restore','scope':'clients','snapshot_id':'123'}
                with patch.object(console.subprocess,'run',return_value=CompletedProcess([],1,stderr=marker+' synthetic private database error')):
                    console.execute(operation)
                state=console.state()['operation']
                self.assertEqual(state['status'],'failed')
                self.assertIn(expected,state['message'])
                self.assertNotIn('synthetic private',state['message'])

    def test_restart_marks_interruption_without_replaying(self):
        self.operation()
        console.recover()
        self.assertEqual(console.state()['operation']['status'],'failed')
        self.assertEqual(len(console.state()['history']),1)


class BackupDeletionTests(unittest.TestCase):
    def setUp(self):
        CoordinatorTests.setUp(self)
        self.backups = self.root / 'checkpoints'
        self.snapshot_patch = patch.object(console, 'SNAPSHOTS', self.backups)
        self.snapshot_patch.start()
        for scope in ('employees', 'vacations', 'all'):
            target = self.backups / scope / '123'
            target.mkdir(parents=True)
            (target / 'manifest.json').write_text(json.dumps({'snapshot_id':'123', 'service':scope}))
            (target / 'target.dump').write_text('synthetic archive')
    def tearDown(self):
        self.snapshot_patch.stop()
        CoordinatorTests.tearDown(self)
    def operation(self):
        return CoordinatorTests.operation(self)
    def test_restored_compatible_checkpoint_can_be_reserved_again(self):
        point=self.backups/'vacations'/'123'
        (point/'restored').write_text('restored')
        (point/'manifest.json').write_text(json.dumps({'snapshot_id':'123','service':'vacations','schemas':['vacations'],'created_at_utc':'2026-01-01T00:00:00Z'}))
        body={'scope':'vacations','snapshot_id':'123','confirmation':'RESTORE VACATIONS'}
        for _ in range(2):
            op=console.start(body,'restore')
            self.assertEqual(op['snapshot_id'],'123')
            console.recover()
        with self.assertRaises(ValueError): console.start({**body,'confirmation':'wrong'},'restore')
    def test_deletes_only_requested_service_and_preserves_metadata_history(self):
        self.operation(); console.recover()
        history = console.state()['history']
        result = console.delete_checkpoint('employees','123')
        self.assertTrue(result['deleted'])
        self.assertFalse((self.backups/'employees'/'123').exists())
        self.assertTrue((self.backups/'vacations'/'123').exists())
        self.assertTrue((self.backups/'all'/'123').exists())
        self.assertEqual(console.state()['history'], history)
    def test_active_console_blocks_delete(self):
        self.operation()
        with self.assertRaises(RuntimeError): console.delete_checkpoint('employees','123')
        self.assertTrue((self.backups/'employees'/'123').exists())
    def test_active_legacy_run_blocks_delete(self):
        with sqlite3.connect(self.root/'migration.sqlite') as db:
            db.execute("INSERT INTO migration_runs VALUES (1,'vacations','inspect','queued',NULL)")
        with self.assertRaises(RuntimeError): console.delete_checkpoint('employees','123')
        self.assertTrue((self.backups/'employees'/'123').exists())
    def test_allowlist_and_traversal_rejected(self):
        for scope,sid in [('unknown','123'),('employees','../123'),('employees','１２３')]:
            with self.assertRaises(ValueError): console.delete_checkpoint(scope,sid)
        self.assertTrue((self.backups/'employees'/'123').exists())
    def test_missing_and_wrong_scope_archive_rejected(self):
        with self.assertRaises(FileNotFoundError): console.delete_checkpoint('employees','999')
        (self.backups/'employees'/'123'/'manifest.json').write_text(json.dumps({'snapshot_id':'123','service':'vacations'}))
        with self.assertRaises(ValueError): console.delete_checkpoint('employees','123')
    def test_symlink_and_locked_archive_rejected(self):
        (self.backups/'employees'/'456').symlink_to(self.backups/'vacations'/'123',target_is_directory=True)
        with self.assertRaises(FileNotFoundError): console.delete_checkpoint('employees','456')
        import fcntl
        with (self.backups/'.lock').open('a') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            with self.assertRaises(BlockingIOError): console.delete_checkpoint('employees','123')
        self.assertTrue((self.backups/'employees'/'123').exists())


class BackupEndpointTests(unittest.TestCase):
    def request(self, path, status=None):
        import migration_ops_server as ops
        handler = object.__new__(ops.Handler)
        handler.path = path
        responses = []
        handler.reply = lambda code,body: responses.append((code,body))
        with patch.object(ops,'read_status',return_value=status or {'state':'idle'}), patch.object(console,'active',return_value=False), patch.object(console,'delete_checkpoint',return_value={'deleted':True}) as delete:
            handler.do_DELETE()
        return responses,delete
    def test_console_delete_routes_scope_and_id(self):
        responses,delete = self.request('/console/snapshots/vacations/123')
        self.assertEqual(responses,[(200,{'deleted':True})])
        delete.assert_called_once_with('vacations','123')
    def test_active_legacy_snapshot_blocks_console_delete(self):
        responses,delete = self.request('/console/snapshots/employees/123',{'state':'running'})
        self.assertEqual(responses[0][0],409)
        delete.assert_not_called()
    def test_malformed_path_never_reaches_archive(self):
        responses,delete = self.request('/console/snapshots/employees/123/extra')
        self.assertEqual(responses[0][0],422)
        delete.assert_not_called()


class CheckpointSafetyTests(unittest.TestCase):
    def test_restore_of_individual_scope_refuses_other_service_run(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp)
            with sqlite3.connect(root/'migration.sqlite') as db:
                db.execute('CREATE TABLE migration_runs (id INTEGER PRIMARY KEY,service TEXT,status TEXT)')
                db.execute("INSERT INTO migration_runs VALUES (2,'vacations','completed')")
            with self.assertRaisesRegex(RuntimeError,'Another service'):
                snapshot.assert_idle(root,'employees',1)
            self.assertEqual(snapshot.assert_idle(root,'all',1),2)
    def test_old_global_checkpoint_is_visible_but_cannot_restore_four_service_scope(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp); target=root/'all'/'123';target.mkdir(parents=True)
            (target/'manifest.json').write_text(json.dumps({'snapshot_id':'123','service':'all','schemas':['employees','vacations'],'created_at_utc':'2026-01-01T00:00:00Z'}))
            with patch.object(console,'SNAPSHOTS',root):
                points=console.checkpoints('all')
                self.assertEqual(len(points),1)
                self.assertFalse(points[0]['compatible'])
                with self.assertRaises(ValueError): console.start({'scope':'all','snapshot_id':'123','confirmation':'RESTORE ALL'},'restore')
    def test_active_run_blocks_snapshot_and_restore(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp)
            with sqlite3.connect(root/'migration.sqlite') as db:
                db.execute('CREATE TABLE migration_runs (id INTEGER PRIMARY KEY,service TEXT,status TEXT)')
                db.execute("INSERT INTO migration_runs VALUES (1,'employees','running')")
            with self.assertRaisesRegex(RuntimeError,'active'):
                snapshot.assert_idle(root,'all')
    def test_document_archive_restore_and_traversal_guard(self):
        import tarfile, io
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp); target=root/'files'; target.mkdir()
            (target/'current.pdf').write_bytes(b'synthetic current')
            archive=root/'documents.tar.gz'
            with tarfile.open(archive,'w:gz') as tar:
                info=tarfile.TarInfo('attachments/Synthetic application.pdf'); data=b'synthetic previous'; info.size=len(data);tar.addfile(info,io.BytesIO(data))
            snapshot.restore_documents(archive,target)
            self.assertEqual((target/'attachments'/'Synthetic application.pdf').read_bytes(),b'synthetic previous')
            self.assertFalse((target/'current.pdf').exists())
            with tarfile.open(archive,'w:gz') as tar:
                info=tarfile.TarInfo('../escape');info.size=1;tar.addfile(info,io.BytesIO(b'x'))
            with self.assertRaisesRegex(RuntimeError,'Unsafe'):
                snapshot.restore_documents(archive,target)
            self.assertTrue((target/'attachments'/'Synthetic application.pdf').exists())

    def test_vacations_snapshot_reads_mounted_documents_without_host_path(self):
        import tarfile
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp); volume=root/'metadata'; volume.mkdir(); files=root/'documents'; files.mkdir()
            (files/'Synthetic application.pdf').write_bytes(b'synthetic document')
            with sqlite3.connect(volume/'migration.sqlite') as db:
                db.execute('CREATE TABLE migration_runs (id INTEGER PRIMARY KEY, service TEXT, status TEXT)')
            def command(args, **kwargs):
                if kwargs.get('stdout'):
                    kwargs['stdout'].write(b'synthetic database dump')
                return ''
            with patch.dict(os.environ, MIGRATION_VACATIONS_FILES_PATH=str(files)), patch.object(snapshot,'BASE',root/'points'), patch.object(snapshot.os,'geteuid',return_value=0), patch.object(snapshot.common,'migration_volume',return_value=volume), patch.object(snapshot,'foreign_keys',return_value='0'), patch.object(snapshot.common,'container',return_value='synthetic-container'), patch.object(snapshot.common,'run',side_effect=command) as run, patch.object(snapshot.common,'compose'):
                snapshot.main('snapshot','vacations','123')
                self.assertFalse(any(call.args[0][:2]==['docker','inspect'] for call in run.call_args_list))
            point=root/'points'/'vacations'/'123'
            with tarfile.open(point/'vacations-files.tar.gz') as tar:
                self.assertEqual(tar.extractfile('Synthetic application.pdf').read(),b'synthetic document')
            manifest=json.loads((point/'manifest.json').read_text())
            self.assertEqual(manifest['checksums']['vacations-files.tar.gz'],snapshot.common.digest(point/'vacations-files.tar.gz'))
            with patch.dict(os.environ, MIGRATION_VACATIONS_FILES_PATH=str(root/'absent')), patch.object(snapshot.common,'run') as run:
                with self.assertRaisesRegex(RuntimeError,'document volume is missing'):
                    snapshot.vacation_files()
                run.assert_not_called()

    def test_same_verified_checkpoint_restores_twice_and_keeps_guards(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp); point=root/'all'/'123';point.mkdir(parents=True)
            volume=root/'volume';volume.mkdir()
            with sqlite3.connect(point/'metadata.sqlite') as db:
                db.execute('CREATE TABLE migration_runs (id INTEGER PRIMARY KEY, service TEXT, status TEXT)')
            import shutil
            shutil.copyfile(point/'metadata.sqlite',volume/'migration.sqlite')
            (point/'target.dump').write_text('synthetic archive')
            (point/'manifest.json').write_text(json.dumps({'snapshot_id':'123','service':'all','schemas':snapshot.SCOPES['all'],'last_migration_run_id':0,'checksums':{name:snapshot.common.digest(point/name) for name in ('target.dump','metadata.sqlite')}}))
            with patch.object(snapshot,'BASE',root), patch.object(snapshot.os,'geteuid',return_value=0), patch.object(snapshot.common,'migration_volume',return_value=volume), patch.object(snapshot,'foreign_keys',return_value='0'), patch.object(snapshot.common,'container',return_value='synthetic-postgres'), patch.object(snapshot.common,'run') as run, patch.object(snapshot.common,'compose'), patch.object(snapshot,'preflight_restore'), patch.object(snapshot,'deployed_runtime',return_value={}), patch.object(snapshot,'migrate_restored_schema') as migrate:
                snapshot.main('restore','all','123')
                snapshot.main('restore','all','123')
                self.assertEqual(run.call_count,2)
                self.assertEqual([c.args[0] for c in migrate.call_args_list if not c.kwargs.get('check_only')],list(snapshot.SCOPES['all'])*2)
                self.assertTrue((point/'restored').exists())
                (point/'target.dump').write_text('corrupted after restore')
                with self.assertRaisesRegex(RuntimeError,'checksum'): snapshot.main('restore','all','123')
                self.assertEqual(run.call_count,2)
    def test_restore_failure_resumes_only_before_commit_or_on_confirmed_abort(self):
        for failure, resumes in [('docker failed (1): RESTORE_TRANSACTION_ABORTED\nsynthetic error', True), ('Docker connection lost', False), ('metadata copy failed', False)]:
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as temp:
                root=Path(temp); point=root/'clients'/'123';point.mkdir(parents=True)
                volume=root/'volume';volume.mkdir()
                for target in (volume/'migration.sqlite',point/'metadata.sqlite'):
                    with sqlite3.connect(target) as db:
                        db.execute('CREATE TABLE migration_runs (id INTEGER PRIMARY KEY, service TEXT, status TEXT)')
                (point/'target.dump').write_text('synthetic archive')
                (point/'manifest.json').write_text(json.dumps({'snapshot_id':'123','service':'clients','schemas':['clients'],'created_at_utc':'2026-01-01T00:00:00Z','last_migration_run_id':0,'checksums':{name:snapshot.common.digest(point/name) for name in ('target.dump','metadata.sqlite')}}))
                command_error = None if failure == 'metadata copy failed' else RuntimeError(failure)
                copy_error = RuntimeError(failure) if command_error is None else None
                with patch.object(snapshot,'BASE',root), patch.object(snapshot.os,'geteuid',return_value=0), patch.object(snapshot.common,'migration_volume',return_value=volume), patch.object(snapshot,'foreign_keys',return_value='0'), patch.object(snapshot,'preflight_restore'), patch.object(snapshot,'deployed_runtime',return_value={}), patch.object(snapshot,'migrate_restored_schema'), patch.object(snapshot.common,'container',return_value='synthetic-postgres'), patch.object(snapshot.common,'run',side_effect=command_error), patch.object(snapshot.shutil,'copyfile',side_effect=copy_error), patch.object(snapshot.common,'compose') as compose:
                    with self.assertRaises(RuntimeError): snapshot.main('restore','clients','123')
                starts=[c for c in compose.call_args_list if c.args[0]=='start']
                self.assertEqual(bool(starts),resumes)
                self.assertFalse((point/'restored').exists())

    def test_runtime_check_failure_never_stops_writers_or_restores_database(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp);point=root/'clients'/'123';point.mkdir(parents=True);volume=root/'volume';volume.mkdir()
            for target in (volume/'migration.sqlite',point/'metadata.sqlite'):
                with sqlite3.connect(target) as db: db.execute('CREATE TABLE migration_runs(id INTEGER PRIMARY KEY,service TEXT,status TEXT)')
            (point/'target.dump').write_text('synthetic dump')
            (point/'manifest.json').write_text(json.dumps({'snapshot_id':'123','service':'clients','schemas':['clients'],'created_at_utc':'2026-01-01T00:00:00Z','last_migration_run_id':0,'checksums':{name:snapshot.common.digest(point/name) for name in ('target.dump','metadata.sqlite')}}))
            with patch.object(snapshot,'BASE',root), patch.object(snapshot.os,'geteuid',return_value=0), patch.object(snapshot.common,'migration_volume',return_value=volume), patch.object(snapshot,'foreign_keys',return_value='0'), patch.object(snapshot,'preflight_restore'), patch.object(snapshot,'deployed_runtime',return_value={}), patch.object(snapshot,'migrate_restored_schema',side_effect=RuntimeError('synthetic runtime failure')), patch.object(snapshot.common,'compose') as compose, patch.object(snapshot.common,'run') as run:
                with self.assertRaises(RuntimeError): snapshot.main('restore','clients','123')
            compose.assert_not_called();run.assert_not_called()
            diagnostic=json.loads((root/'.restore-status.json').read_text())
            self.assertEqual(diagnostic['stage'],'runtime-check');self.assertFalse(diagnostic['database_committed'])
            self.assertEqual(diagnostic['database_outcome'],'unmodified')

    def test_public_diagnostics_never_return_private_traceback_or_environment(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp)
            (root/'.restore-status.json').write_text(json.dumps({'scope':'clients','stage':'schema-upgrade','env':'synthetic-private-environment','traceback':'synthetic-private-error'}))
            with patch.object(console,'SNAPSHOTS',root): result=console.restore_diagnostics()
            self.assertEqual(result,{'scope':'clients','stage':'schema-upgrade'})

    def test_preflight_rehearsal_failure_cleans_probe_without_stopping_services(self):
        with tempfile.TemporaryDirectory() as temp:
            archive=Path(temp)/'target.dump';archive.write_bytes(b'synthetic checkpoint')
            calls=[]
            def command(args,**kwargs):
                calls.append(args)
                if '--clean' in ' '.join(args): raise RuntimeError('new synthetic table blocks DROP SCHEMA')
                return ''
            with patch.object(snapshot.common,'container',return_value='synthetic-postgres'), patch.object(snapshot.common,'run',side_effect=command), patch.object(snapshot.common,'compose') as compose:
                with self.assertRaisesRegex(RuntimeError,'RESTORE_PREFLIGHT_FAILED'):
                    snapshot.preflight_restore('clients','123',archive)
            compose.assert_not_called()
            self.assertIn('dropdb',' '.join(calls[-1]))
            self.assertTrue(any('--schema-only' in ' '.join(c) for c in calls))

    def test_schema_migrations_clone_deployed_environment_without_compose_reconstruction(self):
        runtime={'container':'a'*64,'image':'sha256:'+'b'*64,'network':'synthetic_network',
                 'env':['SYNTHETIC_CONFIG=deployed'], 'workdir':'/app','user':'123'}
        calls=[]
        def command(args,**kwargs):
            calls.append(args)
            environment=Path(args[args.index('--env-file')+1])
            self.assertEqual(environment.read_text(),'SYNTHETIC_CONFIG=deployed\n')
            self.assertEqual(environment.stat().st_mode & 0o777,0o600)
            return ''
        with patch.object(snapshot.common,'run',side_effect=command), patch.object(snapshot.common,'compose') as compose:
            snapshot.migrate_restored_schema('clients',runtime,check_only=True)
            snapshot.migrate_restored_schema('clients',runtime)
        compose.assert_not_called()
        self.assertIn('migrate:status',calls[0])
        self.assertIn('migrate',calls[1]);self.assertIn('--force',calls[1])
        self.assertNotIn('--force',calls[0])
        self.assertIn(runtime['image'],calls[1])
        self.assertEqual(calls[1][calls[1].index('--volumes-from')+1],runtime['container'])
        self.assertFalse(Path(calls[1][calls[1].index('--env-file')+1]).exists())

    def test_deployed_runtime_refuses_missing_container_and_ambiguous_network(self):
        with patch.object(snapshot.common,'compose',return_value=''):
            with self.assertRaisesRegex(RuntimeError,'missing'): snapshot.deployed_runtime('clients')
        runtime={'Image':'sha256:'+'b'*64,'Config':{'Env':['SYNTHETIC_CONFIG=deployed'],'WorkingDir':'/app'},'NetworkSettings':{'Networks':{'synthetic_one':{},'synthetic_two':{}}}}
        with patch.object(snapshot.common,'compose',return_value='a'*64), patch.object(snapshot.common,'run',return_value=json.dumps(runtime)):
            with self.assertRaisesRegex(RuntimeError,'network'): snapshot.deployed_runtime('clients')

    def test_restore_checksum_failure_never_touches_target(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp); snap=root/'all'/'123';snap.mkdir(parents=True)
            (snap/'manifest.json').write_text(json.dumps({'snapshot_id':'123','service':'all','checksums':{'target.dump':'bad','metadata.sqlite':'bad'}}))
            (snap/'target.dump').write_text('corrupt')
            (snap/'metadata.sqlite').write_text('corrupt')
            with patch.object(snapshot,'BASE',root), patch.object(snapshot.os,'geteuid',return_value=0), patch.object(snapshot.common,'migration_volume',return_value=root), patch.object(snapshot,'foreign_keys',return_value='0'), patch.object(snapshot.common,'compose') as compose:
                with self.assertRaisesRegex(RuntimeError,'checksum'): snapshot.main('restore','all','123')
            compose.assert_not_called()


class RestoredServiceGuardTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.volume = self.root / 'volume'; self.volume.mkdir()
        self.base_patch = patch.object(snapshot, 'BASE', self.root / 'console'); self.base_patch.start()
        self.history_patch = patch.object(snapshot, 'HISTORY', self.root / 'console.json'); self.history_patch.start()
        self.make_db(self.volume / 'migration.sqlite')
        self.target = self.point('employees', '100')
        self.history = []

    def tearDown(self):
        self.history_patch.stop(); self.base_patch.stop(); self.temp.cleanup()

    def make_db(self, path):
        with sqlite3.connect(path) as db:
            db.execute('CREATE TABLE migration_runs(id INTEGER PRIMARY KEY,service TEXT,mode TEXT,status TEXT,created_at TEXT,started_at TEXT)')
            db.execute('CREATE TABLE migration_mappings(id INTEGER PRIMARY KEY,service TEXT,legacy_id TEXT,target_id TEXT)')

    def point(self, scope, sid):
        point = snapshot.BASE / scope / sid; point.mkdir(parents=True)
        self.make_db(point / 'metadata.sqlite')
        self.seal(point, scope, sid)
        return point

    def seal(self, point, scope, sid):
        (point / 'manifest.json').write_text(json.dumps({'snapshot_id':sid,'service':scope,
            'schemas':snapshot.SCOPES[scope], 'created_at_utc':'2026-01-01T00:00:00Z',
            'checksums':{'metadata.sqlite':snapshot.common.digest(point / 'metadata.sqlite')}}))

    def run_record(self, service='vacations', mode='migrate', when='2026-01-02 00:00:00', status='completed', run_id=20):
        with sqlite3.connect(self.volume / 'migration.sqlite') as db:
            db.execute('INSERT INTO migration_runs VALUES (?,?,?,?,?,?)', (run_id,service,mode,status,when,when))

    def restore_record(self, service, sid, status='completed', when='2026-01-03T00:00:00Z'):
        self.history.append({'id':sid,'action':'restore','scope':service,'snapshot_id':sid,
            'status':status,'started_at':snapshot.timestamp(when),'finished_at':snapshot.timestamp(when)+30})
        snapshot.HISTORY.write_text(json.dumps({'history':self.history}))

    def check(self):
        snapshot.assert_idle(self.volume, 'employees', 10, self.target)

    def test_read_only_modes_do_not_block_but_any_active_run_does(self):
        for i, mode in enumerate(snapshot.READ_ONLY_MODES):
            self.run_record(mode=mode,run_id=20+i)
        self.check()
        self.run_record(mode='validate',status='running',run_id=23)
        with self.assertRaisesRegex(RuntimeError, 'active'): self.check()

    def test_clients_and_vacations_restored_allow_stale_reintroduced_runs(self):
        for i, service in enumerate(('vacations','clients')):
            self.run_record(service=service,run_id=20+i)
            self.point(service,str(200+i)); self.restore_record(service,str(200+i))
        self.check()

    def test_unrestored_failed_and_unknown_modes_still_block(self):
        for mode in ('migrate','future-mode'):
            with self.subTest(mode=mode):
                self.run_record(mode=mode,status='failed')
                with self.assertRaisesRegex(RuntimeError,'Another service'): self.check()
                with sqlite3.connect(self.volume / 'migration.sqlite') as db: db.execute('DELETE FROM migration_runs')

    def test_failed_restore_and_old_restored_marker_are_not_proof(self):
        self.run_record(); point=self.point('vacations','200')
        (point / 'restored').write_text('restored')
        self.restore_record('vacations','200')
        self.restore_record('vacations','200',status='failed',when='2026-01-04T00:00:00Z')
        with self.assertRaisesRegex(RuntimeError,'not confirmed'): self.check()

    def test_new_import_after_restore_blocks_even_with_reused_lower_id(self):
        self.point('vacations','200'); self.restore_record('vacations','200')
        self.run_record(run_id=1,when='2026-01-04 00:00:00',status='failed')
        with self.assertRaisesRegex(RuntimeError,'after its restore'): self.check()

    def test_reused_id_without_restore_is_detected_by_identity(self):
        self.run_record(run_id=1)
        with self.assertRaisesRegex(RuntimeError,'Another service'): self.check()

    def test_import_retained_only_in_console_history_still_blocks(self):
        self.point('vacations','200'); self.restore_record('vacations','200')
        self.history.append({'action':'migrate','started_at':snapshot.timestamp('2026-01-04T00:00:00Z'),
            'runs':[{'service':'vacations','mode':'migrate','status':'failed'}]})
        snapshot.HISTORY.write_text(json.dumps({'history':self.history}))
        with self.assertRaisesRegex(RuntimeError,'after its restore'): self.check()

    def test_other_restore_erasing_run_does_not_hide_unrestored_import(self):
        self.history.append({'action':'migrate','started_at':snapshot.timestamp('2025-12-31T00:00:00Z'),
            'updated_at':snapshot.timestamp('2026-01-02T00:00:00Z'),
            'runs':[{'service':'clients','mode':'migrate','status':'failed'}]})
        snapshot.HISTORY.write_text(json.dumps({'history':self.history}))
        with self.assertRaisesRegex(RuntimeError,'Another service'): self.check()

    def test_restore_to_different_mapping_baseline_blocks(self):
        point=self.point('vacations','200')
        with sqlite3.connect(point / 'metadata.sqlite') as db:
            db.execute("INSERT INTO migration_mappings VALUES (1,'vacations','legacy','target')")
        self.seal(point,'vacations','200'); self.restore_record('vacations','200')
        with self.assertRaisesRegex(RuntimeError,'different baseline'): self.check()

    def test_missing_or_corrupt_proof_does_not_unlock(self):
        point=self.point('vacations','200'); self.restore_record('vacations','200')
        (point / 'metadata.sqlite').write_text('corrupt')
        with self.assertRaisesRegex(RuntimeError,'checksum'): self.check()
        (point / 'manifest.json').unlink()
        with self.assertRaises(FileNotFoundError): self.check()

    def test_read_only_run_after_successful_restore_does_not_block(self):
        self.point('vacations','200'); self.restore_record('vacations','200')
        self.run_record(mode='validate',when='2026-01-04 00:00:00')
        self.check()

    def test_second_precision_run_cannot_bypass_fractional_restore_time(self):
        self.point('vacations','200'); self.restore_record('vacations','200')
        self.history[0]['started_at'] += .5
        snapshot.HISTORY.write_text(json.dumps({'history':self.history}))
        self.run_record(when='2026-01-03 00:00:00')
        with self.assertRaisesRegex(RuntimeError,'after its restore'): self.check()

    def test_incomplete_completed_record_is_not_restore_proof(self):
        self.point('vacations','200'); self.restore_record('vacations','200')
        self.history[0].pop('finished_at')
        snapshot.HISTORY.write_text(json.dumps({'history':self.history}))
        with self.assertRaisesRegex(RuntimeError,'not confirmed'): self.check()

    def test_new_mapping_after_restore_is_not_mistaken_for_reintroduced_metadata(self):
        self.point('vacations','200'); self.restore_record('vacations','200')
        with sqlite3.connect(self.volume / 'migration.sqlite') as db:
            db.execute('ALTER TABLE migration_mappings ADD COLUMN updated_at TEXT')
            db.execute("INSERT INTO migration_mappings VALUES (1,'vacations','old','target','2026-01-02 00:00:00')")
        self.check()
        with sqlite3.connect(self.volume / 'migration.sqlite') as db:
            db.execute("UPDATE migration_mappings SET updated_at='2026-01-04 00:00:00'")
        with self.assertRaisesRegex(RuntimeError,'metadata changed'): self.check()

    def test_global_restore_can_prove_other_services_and_timezone_is_preserved(self):
        self.point('all','200'); self.restore_record('all','200')
        self.run_record(service='clients')
        self.check()
        self.assertEqual(snapshot.timestamp('2026-01-01T04:00:00+04:00'), snapshot.timestamp('2026-01-01T00:00:00Z'))


class DiagnosticTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name)
        self.env=patch.dict(os.environ,MIGRATION_SNAPSHOT_BASE=str(self.root),MIGRATION_DIAGNOSTIC_ID='a'*32)
        self.env.start()
    def tearDown(self):
        self.env.stop();self.temp.cleanup()
    def test_failed_command_keeps_full_private_output_and_safe_cause(self):
        from subprocess import CompletedProcess
        diagnostics.record('preflight',scope='employees',snapshot_id='123',state='running',database_outcome='unmodified')
        raw='private password=synthetic-secret\n'+'x'*6000+'\ncannot drop schema employees because other objects depend on it'
        diagnostics.command_failure(CompletedProcess([],1,stdout=b'private stdout',stderr=raw.encode()))
        diagnostics.failed('RESTORE_PREFLIGHT_FAILED')
        state=diagnostics.read()
        self.assertEqual(state['error_code'],'SCHEMA_DEPENDENCY')
        self.assertEqual(state['failed_stage'],'preflight')
        self.assertEqual(state['database_outcome'],'unmodified')
        self.assertNotIn('synthetic-secret',json.dumps(state))
        log=self.root/'diagnostics'/('a'*32+'.log')
        self.assertIn(raw,log.read_text());self.assertIn('private stdout',log.read_text())
        self.assertEqual(log.stat().st_mode & 0o777,0o600)
        self.assertEqual((log.parent/('a'*32+'.json')).stat().st_mode & 0o777,0o600)
    def test_repeated_attempt_has_own_report_without_old_commit_flags(self):
        diagnostics.record('metadata',database_committed=True,database_outcome='committed')
        diagnostics.failed('permission denied')
        with patch.dict(os.environ,MIGRATION_DIAGNOSTIC_ID='b'*32):
            diagnostics.record('validation',database_committed=False,database_outcome='unmodified')
            diagnostics.failed('Checksum mismatch')
        self.assertEqual(diagnostics.read('a'*32)['database_outcome'],'committed')
        self.assertEqual(diagnostics.read('b'*32)['error_code'],'CHECKSUM_MISMATCH')
        self.assertFalse(diagnostics.read('b'*32)['database_committed'])
    def test_classifier_keeps_sqlstate_without_sql_or_row_values(self):
        state=diagnostics.classify('SQLSTATE[23505] INSERT synthetic_private_employee')
        self.assertEqual(state['error_code'],'SQLSTATE_23505')
        self.assertNotIn('synthetic_private',json.dumps(state))
    def test_log_failure_does_not_mask_restore_failure(self):
        with patch.object(diagnostics.os,'open',side_effect=PermissionError('synthetic failure')):
            self.assertEqual(diagnostics.failed('cannot drop synthetic object'),{})
    def test_failed_stage_survives_service_restart_stage(self):
        diagnostics.record('start',failed_stage='database',database_outcome='rolled_back')
        state=diagnostics.failed('RESTORE_TARGET_UNCHANGED')
        self.assertEqual(state['failed_stage'],'database')
        self.assertEqual(state['database_outcome'],'rolled_back')
    def test_invalid_diagnostic_id_cannot_escape_directory(self):
        for key in ('../secret','１２３','a'*31):
            self.assertEqual(diagnostics.read(key),{})
            self.assertFalse(diagnostics.private_log_for(key,'private'))
    def test_legacy_runner_preserves_child_stage_and_capture(self):
        import migration_ops_server as ops
        from subprocess import CompletedProcess
        def child(*args,**kwargs):
            with patch.dict(os.environ,**kwargs['env']):
                diagnostics.record('database',scope='employees',snapshot_id='123',database_outcome='unknown')
                diagnostics.failed('cannot drop synthetic table')
            return CompletedProcess([],1,stdout='synthetic stdout',stderr='synthetic full error')
        states=[]
        with patch.object(ops,'SNAPSHOTS',self.root),patch.object(ops,'save_status',side_effect=states.append),patch.object(ops.subprocess,'run',side_effect=child):
            ops.execute({'id':'123','service':'employees','action':'restore','snapshot_id':'123'})
        report=states[-1]['diagnostics']
        self.assertEqual(report['failed_stage'],'database')
        self.assertEqual(report['database_outcome'],'unknown')
        self.assertEqual(report['error_code'],'SCHEMA_DEPENDENCY')
        self.assertNotIn('synthetic full error',json.dumps(states[-1]))
        self.assertIn('synthetic full error',(self.root/'diagnostics'/(report['diagnostic_id']+'.log')).read_text())
    def test_coordinator_retains_attempt_diagnostics_in_history_for_same_snapshot(self):
        from subprocess import CompletedProcess
        def child(*args,**kwargs):
            with patch.dict(os.environ,**kwargs['env']):
                diagnostics.record('preflight',scope='employees',snapshot_id='123',database_outcome='unmodified')
                diagnostics.command_failure(CompletedProcess([],1,stdout='synthetic stdout',stderr='cannot drop synthetic object'))
                diagnostics.failed('RESTORE_PREFLIGHT_FAILED')
            return CompletedProcess([],1,stdout='',stderr='RESTORE_PREFLIGHT_FAILED')
        with patch.object(console,'STATE',self.root/'console.json'),patch.object(console.subprocess,'run',side_effect=child):
            for key in ('1','2'):
                console.execute({'id':key,'action':'restore','scope':'employees','snapshot_id':'123'})
            history=console.state()['history']
        self.assertEqual(len(history),2)
        ids={op['diagnostics']['diagnostic_id'] for op in history}
        self.assertEqual(len(ids),2)
        for op in history:
            self.assertEqual(op['diagnostics']['error_code'],'SCHEMA_DEPENDENCY')
            self.assertEqual(op['diagnostics']['failed_stage'],'preflight')
            self.assertTrue((self.root/'diagnostics'/(op['diagnostics']['diagnostic_id']+'.log')).is_file())

    def test_early_restore_error_is_recorded_before_snapshot_validation(self):
        with patch.object(snapshot,'BASE',self.root/'console'),patch.object(snapshot.common,'migration_volume',side_effect=RuntimeError('Migration metadata volume is missing')),patch.object(snapshot.os,'geteuid',return_value=0):
            with self.assertRaisesRegex(RuntimeError,'volume is missing'):
                snapshot.main('restore','employees','123')
        report=diagnostics.read()
        self.assertEqual(report['failed_stage'],'validation')
        self.assertEqual(report['error_code'],'VOLUME_MISSING')
        self.assertEqual(report['database_outcome'],'unmodified')

if __name__=='__main__': unittest.main()
