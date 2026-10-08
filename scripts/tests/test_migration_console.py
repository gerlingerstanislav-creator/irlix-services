import importlib, json, os, sqlite3, sys, tempfile, unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import migration_console as console
import migration_console_snapshot as snapshot


class CoordinatorTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.patch = patch.object(console, 'STATE', self.root / 'console.json')
        self.patch.start()
        db = sqlite3.connect(self.root / 'migration.sqlite')
        db.execute('CREATE TABLE migration_runs (id INTEGER PRIMARY KEY, service TEXT, mode TEXT, status TEXT, error TEXT)')
        db.commit(); db.close()
        self.env = patch.dict(os.environ, MIGRATION_DATA_PATH=str(self.root))
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
            with patch.object(snapshot,'BASE',root), patch.object(snapshot.os,'geteuid',return_value=0), patch.object(snapshot.common,'migration_volume',return_value=volume), patch.object(snapshot,'foreign_keys',return_value='0'), patch.object(snapshot.common,'container',return_value='synthetic-postgres'), patch.object(snapshot.common,'run') as run, patch.object(snapshot.common,'compose'):
                snapshot.main('restore','all','123')
                snapshot.main('restore','all','123')
                self.assertEqual(run.call_count,2)
                self.assertTrue((point/'restored').exists())
                (point/'target.dump').write_text('corrupted after restore')
                with self.assertRaisesRegex(RuntimeError,'checksum'): snapshot.main('restore','all','123')
                self.assertEqual(run.call_count,2)
    def test_restore_checksum_failure_never_touches_target(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp); snap=root/'all'/'123';snap.mkdir(parents=True)
            (snap/'manifest.json').write_text(json.dumps({'snapshot_id':'123','service':'all','checksums':{'target.dump':'bad','metadata.sqlite':'bad'}}))
            (snap/'target.dump').write_text('corrupt')
            (snap/'metadata.sqlite').write_text('corrupt')
            with patch.object(snapshot,'BASE',root), patch.object(snapshot.os,'geteuid',return_value=0), patch.object(snapshot.common,'migration_volume',return_value=root), patch.object(snapshot,'foreign_keys',return_value='0'), patch.object(snapshot.common,'compose') as compose:
                with self.assertRaisesRegex(RuntimeError,'checksum'): snapshot.main('restore','all','123')
            compose.assert_not_called()

if __name__=='__main__': unittest.main()
