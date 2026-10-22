import json
import os
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import migration_console as console
import migration_control as control
import migration_console_snapshot as snapshot


class ControlTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.patches = [patch.object(console, 'STATE', self.root / 'console.json'),
                        patch.object(console, 'SNAPSHOTS', self.root / 'snapshots'),
                        patch.object(control, 'ARCHIVE', self.root / 'control-reports'),
                        patch.dict(os.environ, MIGRATION_DATA_PATH=str(self.root))]
        for item in self.patches:
            item.start()
        with sqlite3.connect(self.root / 'migration.sqlite') as db:
            db.execute('CREATE TABLE migration_runs (id INTEGER PRIMARY KEY, service TEXT, mode TEXT, status TEXT, error TEXT)')

    def tearDown(self):
        for item in reversed(self.patches):
            item.stop()
        self.temp.cleanup()

    def start(self, scope='all'):
        return control.start({'scope': scope, 'confirm': True})

    def run_stage(self, service, mode, operation, sid):
        run = {'id': len(operation['runs']) + 1, 'service': service, 'mode': mode, 'status': 'completed'}
        operation['runs'].append(run)
        return 'completed'

    def test_report_summary_failure_keeps_operation_readable_without_false_evidence(self):
        op = self.start('clients')
        with patch.object(control, 'report', side_effect=RuntimeError('private DSN credential')):
            summary = control.summary(op['id'])
        self.assertEqual(str(summary['operation']['id']), str(op['id']))
        self.assertEqual(summary['report_error_code'], 'REPORT_AGGREGATION_UNAVAILABLE')
        self.assertTrue(summary['report_degraded'])
        self.assertFalse(summary['archive_complete'])
        self.assertFalse(summary['coverage']['semantic_complete'])
        self.assertEqual(summary['runs'], [])
        self.assertNotIn('private DSN credential', json.dumps(summary))

    def test_report_summary_removes_raw_evidence_arrays_without_mutating_full_archive(self):
        op = self.start('clients')
        source = {'operation': {'id': op['id']}, 'coverage': {'semantic_complete': False},
                  'runs': [{'service': 'clients', 'diagnostics': {'items': [{'secret': 'only-full'}],
                   'groups': [{'code': 'SAFE'}]}}], 'events': [{'payload': 'only-full'}]}
        with patch.object(control, 'report', return_value=source):
            summary = control.summary(op['id'])
        self.assertNotIn('items', summary['runs'][0]['diagnostics'])
        self.assertNotIn('events', summary)
        self.assertEqual(summary['runs'][0]['diagnostics']['groups'][0]['code'], 'SAFE')
        self.assertIn('items', source['runs'][0]['diagnostics'])

    def test_completed_worker_without_archive_is_never_reported_completed(self):
        op = self.start('all')
        # Synthetic analogue of a worker-completed dry-run whose proof was not
        # archived. Other services must not inherit its completion.
        op['runs'] = [{'id': 17, 'service': 'clients', 'mode': 'dry-run', 'status': 'completed'}]
        control.update(op, status='failed', phase='finished', last_phase='dry-run',
                       last_service='clients', current_service='clients')
        report = control.build_report(op)
        clients = next(row for row in report['service_evidence'] if row['service'] == 'clients')
        self.assertEqual(clients['status'], 'failed')
        self.assertEqual(clients['stage'], 'dry-run')
        self.assertEqual(clients['stage_status'], 'completed')
        self.assertEqual(clients['run_id'], 17)
        self.assertEqual(clients['write_state'], 'not_started')
        self.assertFalse(clients['evidence']['stage_archive'])
        self.assertIn('missing_archive_key', clients['reason'])
        self.assertIn('Сервис clients: failed', report['chat_summary'])
        self.assertEqual(next(row for row in report['service_evidence']
                              if row['service'] == 'timesheets')['status'], 'unknown')

    def test_unarchived_completed_run_outside_failure_service_is_not_success(self):
        op = self.start('all')
        op['runs'] = [{'id': 17, 'service': 'employees', 'mode': 'inspect', 'status': 'completed'}]
        control.update(op, status='failed', phase='finished', last_phase='snapshot', last_service='clients',
                       current_service='clients')
        evidence = control.build_report(op)['service_evidence']
        self.assertEqual(next(row for row in evidence if row['service'] == 'employees')['status'], 'unknown')

    def test_report_export_reasons_are_classified_without_exposing_private_error(self):
        op = self.start('clients')
        run = {'id': 17, 'service': 'clients', 'mode': 'dry-run', 'status': 'completed'}
        op['runs'] = [run]
        with patch.object(control, 'artisan', side_effect=subprocess.TimeoutExpired('private-password-DSN', 300)):
            with self.assertRaises(control.AuditError) as failure:
                control.export_run(op, run)
        self.assertEqual(failure.exception.code, 'REPORT_EXPORT_TIMEOUT')
        self.assertNotIn('private-password-DSN', str(failure.exception))

    def test_export_failure_captures_safe_php_fingerprint_without_source_values(self):
        op = self.start('clients')
        run = {'id': 130, 'service': 'clients', 'mode': 'dry-run', 'status': 'completed'}
        op['runs'] = [run]
        response = subprocess.CompletedProcess(
            ['php', 'artisan'], 1,
            stdout='private-password-DSN\nCONTROL_REPORT_FAILURE|JsonException|5|console.php|215\n',
            stderr='secret credential payload')
        with (patch.object(control.console.common, 'container', return_value='synthetic-migration'),
              patch.object(control.subprocess, 'run', return_value=response)):
            with self.assertRaises(control.AuditError) as failure:
                control.export_run(op, run)
        self.assertEqual(failure.exception.code, 'REPORT_EXPORT_COMMAND_FAILED')
        message = str(failure.exception)
        self.assertIn('PHP=JsonException, code=5, at=console.php:215', message)
        self.assertNotIn('private-password-DSN', message)
        self.assertNotIn('secret credential', message)
        self.assertIsNone(run.get('archive_key'))
        self.assertFalse(list((control.directory(op) / 'runs').glob('*.json')))

    def test_export_worker_sigkill_is_classified_without_private_stderr(self):
        op = self.start('clients')
        run = {'id': 130, 'service': 'clients', 'mode': 'dry-run', 'status': 'completed'}
        op['runs'] = [run]
        response = subprocess.CompletedProcess(['docker', 'exec'], 137,
                                               stdout='', stderr='secret server output')
        with (patch.object(control.console.common, 'container', return_value='synthetic-migration'),
              patch.object(control.subprocess, 'run', return_value=response)):
            with self.assertRaises(control.AuditError) as failure:
                control.export_run(op, run)
        self.assertEqual(failure.exception.code, 'REPORT_EXPORT_COMMAND_FAILED')
        self.assertIn('SIGKILL', str(failure.exception))
        self.assertNotIn('secret server output', str(failure.exception))

    def test_audit_export_failure_records_service_and_stage_before_abort(self):
        op = self.start('clients')
        def fake_enqueue(service, mode, operation, snapshot_id):
            run = {'id': 17 if mode == 'dry-run' else 16,
                   'service': service, 'mode': mode, 'status': 'completed'}
            operation['runs'].append(run)
            if mode == 'dry-run':
                raise control.AuditError('Не удалось экспортировать доказательства этапа',
                                         code='REPORT_EXPORT_TIMEOUT')
            return 'completed'
        with patch.object(console, 'checkpoint', side_effect=AssertionError('No checkpoint during migration')), patch.object(control, 'enqueue', side_effect=fake_enqueue):
            control.execute(op)
        report = control.report(op['id'])
        result = report['operation']['service_results'][0]
        self.assertEqual(report['operation']['status'], 'failed')
        self.assertEqual(result['service'], 'clients')
        self.assertEqual(result['status'], 'failed')
        self.assertEqual(result['stage'], 'dry-run')
        self.assertEqual(result['run_id'], 17)
        self.assertEqual(result['failure_code'], 'REPORT_EXPORT_TIMEOUT')
        evidence = next(row for row in report['service_evidence'] if row['service'] == 'clients')
        self.assertEqual(evidence['write_state'], 'not_started')
        self.assertIn('[REPORT_EXPORT_TIMEOUT]', evidence['reason'])
        self.assertEqual(op['snapshot_ids'], {})
        self.assertIn('dry-run', report['chat_summary'])

    def test_historical_export_failure_uses_private_log_without_leaking_details(self):
        op = self.start('timesheets')
        private = control.directory(op) / 'private'
        private.mkdir(parents=True)
        # Historical #186 is immutable; a later deploy may classify its saved
        # stdout/stderr without re-running the migration.
        (private / 'export-186.log').write_text(
            'Exit code: 255\\nstderr:\\nOCI runtime exec failed: exec failed: '
            'unable to start container process; private://password=example')
        safe = control.safe_export_failure_evidence(op['id'], 186)
        self.assertEqual(safe['signature'], 'OCI_EXEC_FAILURE')
        self.assertTrue(safe['private_log_present'])
        self.assertNotIn('private://', str(safe))
        fake = {'operation': op, 'chat_summary': 'Synthetic evidence', 'service_evidence': [
            {'service': 'timesheets', 'run_id': 186, 'failure_code': 'REPORT_EXPORT_COMMAND_FAILED'}]}
        with patch.object(control, 'report', return_value=fake):
            operator = control.summary(op['id'])
        self.assertIn('OCI_EXEC_FAILURE', operator['chat_summary'])
        self.assertIn('#186', operator['chat_summary'])
        self.assertNotIn('password=example', operator['chat_summary'])

    def test_export_failure_signatures_distinguish_memory_and_unknown(self):
        op = self.start('timesheets')
        private = control.directory(op) / 'private'
        private.mkdir(parents=True)
        file = private / 'export-186.log'
        file.write_text('stderr:\\nPHP Fatal error: Allowed memory size of 536870912 bytes exhausted')
        self.assertEqual(control.safe_export_failure_evidence(op['id'],186)['signature'],
                         'MEMORY_EXHAUSTION_EVIDENCE')
        file.write_text('stderr:\\nundocumented private SQL password token')
        self.assertEqual(control.safe_export_failure_evidence(op['id'],186)['signature'],
                         'UNCLASSIFIED_EXPORT_EXIT')
        self.assertEqual(control.safe_export_failure_evidence(op['id'],'../186')['signature'],
                         'INVALID_RUN_ID')

    def test_docker_state_evidence_is_bounded_and_safe(self):
        fake = subprocess.CompletedProcess(['docker','inspect'],0,
            stdout=json.dumps({'Status':'exited','OOMKilled':True,'ExitCode':137,
                'Secret':'private password'}))
        with patch.object(control.console.common,'container',return_value='migration-synthetic'), \\
             patch.object(control.subprocess,'run',return_value=fake):
            state = control.docker_export_runtime_state()
        self.assertEqual(state, {'inspect':'available','status':'exited','oom_killed':True,'exit_code':137})
        self.assertNotIn('Secret', str(state))

    def test_export_transport_255_is_safe_and_its_private_log_is_separated(self):
        op = self.start('timesheets')
        run = {'id': 172, 'service':'timesheets', 'mode':'dry-run', 'status':'conflicts'}
        op['runs'] = [run]
        response = subprocess.CompletedProcess(
            ['docker', 'exec'], 255, stdout='private DSN+SQL with sensitive value', stderr='internal SECRET marker')
        with patch.object(control.console.common, 'container', return_value='synthetic-migration'), \
             patch.object(control.subprocess, 'run', return_value=response):
            with self.assertRaises(control.AuditError) as failure:
                control.export_run(op, run)
        evidence = failure.exception.export_failure
        self.assertEqual(evidence['exit_code'], 255)
        self.assertEqual(evidence['component'], 'migration:control-report via docker exec')
        self.assertEqual(evidence['runtime_hint'], 'DOCKER_EXEC_TRANSPORT_UNAVAILABLE')
        self.assertFalse(evidence['php_marker_present'])
        self.assertTrue(evidence['private_log_saved'])
        private = control.directory(op)/'private'/'export-172.log'
        self.assertTrue(private.exists())
        self.assertEqual(private.stat().st_mode & 0o777, 0o600)
        self.assertIn('internal SECRET marker', private.read_text())
        self.assertNotIn('internal SECRET marker', str(failure.exception))
        control.result(op, 'timesheets', 'failed', str(failure.exception), stage='dry-run',
                       stage_status='conflicts', run_id=172, failure_code=failure.exception.code, export_failure=evidence)
        control.update(op, status='failed', phase='finished', finished_at=100)
        report = control.build_report(op)
        self.assertIn('DOCKER_EXEC_TRANSPORT_UNAVAILABLE', report['chat_summary'])
        self.assertIn('run=172', report['chat_summary'])
        self.assertNotIn('private DSN', report['chat_summary'])
        self.assertNotIn('internal SECRET marker', report['chat_summary'])

    def test_last_write_excludes_dry_run_and_requires_matching_archived_proof(self):
        op = self.start('timesheets')
        op['runs'] = [{'id': 7, 'service':'timesheets','mode':'dry-run',
                       'status':'completed', 'archive_key':'0001-7'},
                      {'id': 8, 'service':'timesheets','mode':'migrate',
                       'status':'completed', 'archive_key':'0002-8'}]
        archive = control.directory(op)/'runs'
        archive.mkdir(parents=True)
        (archive/'0001-7.json').write_text(json.dumps({'operation_id':op['id'],
            'run':{'id':7,'service':'timesheets','mode':'dry-run'}}))
        self.assertEqual(control.last_migrations([op]), {})
        (archive/'0002-8.json').write_text(json.dumps({'operation_id':op['id'],
            'run':{'id':8,'service':'timesheets','mode':'migrate','finished_at':'2026-10-21T09:00:00Z'}}))
        self.assertEqual(control.last_migrations([op])['timesheets']['operation_id'], op['id'])
        self.assertIsInstance(control.last_migrations([op])['timesheets']['at'], float)

    def test_owner_shared_guard_and_existing_worker_guard(self):
        op = self.start()
        self.assertTrue(console.active())
        self.assertTrue(console.state()['operation']['control_mode'])
        with self.assertRaises(ValueError):
            self.start()
        control.finish(op, 'completed', 'done')
        with sqlite3.connect(self.root / 'migration.sqlite') as db:
            db.execute("INSERT INTO migration_runs VALUES (1,'clients','migrate','running',NULL)")
        with self.assertRaises(ValueError):
            self.start()

    def test_start_archive_disk_failure_releases_reservation_without_data_changes(self):
        with patch.object(control, 'atomic', side_effect=OSError('synthetic full disk')):
            with self.assertRaises(control.AuditError):
                self.start('clients')
        self.assertFalse(console.active())
        aborted = console.state()['operation']
        self.assertEqual(aborted['status'], 'failed')
        self.assertEqual(aborted['data_outcome'], 'not_started')
        self.assertFalse(aborted['report_available'])
        self.assertEqual(aborted['runs'], [])
        self.start('clients')

    def test_completed_stages_with_unmapped_field_coverage_have_partial_headline(self):
        op = self.start('clients')
        run = {'id': 1, 'service': 'clients', 'mode': 'migrate', 'status': 'completed'}
        op['runs'] = [run]
        full = {'operation_id': op['id'], 'run': run, 'service': 'clients', 'mode': 'migrate',
                'inventory': {'service': 'clients', 'complete': True, 'tables': [
                    {'name': 'clients', 'columns': [{'name': 'unknown', 'mapping': {'fate': 'unmapped'}}]}]},
                'tables': [{'source_outcomes': {'unprocessed': 0}}]}
        with patch.object(control, 'artisan', return_value=full):
            control.export_run(op, run)
        control.update(op, data_outcome='changed')
        control.finish(op, 'completed', 'stages done')
        self.assertEqual(op['status'], 'partial')
        self.assertEqual(control.report(op['id'])['runs'][0]['run']['status'], 'completed')

    def test_scoped_coverage_requires_every_service_inventory_and_archived_migrate_outcome(self):
        op = self.start('all')
        employee_inspect = {'id': 1, 'service': 'employees', 'mode': 'inspect', 'status': 'completed'}
        client_migrate = {'id': 2, 'service': 'clients', 'mode': 'migrate', 'status': 'failed'}
        op['runs'] = [employee_inspect, client_migrate]
        employee_report = {'operation_id': op['id'], 'run': dict(employee_inspect), 'service': 'employees',
                           'mode': 'inspect', 'inventory': {'service': 'employees', 'complete': True, 'tables': []}}
        with patch.object(control, 'artisan', return_value=employee_report):
            control.export_run(op, employee_inspect)
        control.update(op, phase='migrate', current_service='clients', status='failed',
                       data_outcome='unknown', message='Этап не выполнен; проверьте migration-ops')
        report = control.build_report(op)
        self.assertFalse(report['archive_complete'])
        self.assertFalse(report['coverage']['inventory_complete'])
        self.assertFalse(report['coverage']['archived_outcome_complete'])
        self.assertFalse(report['coverage']['semantic_complete'])
        self.assertIn('clients', report['coverage']['missing_inventory_services'])
        self.assertIn('clients', report['coverage']['missing_archived_outcome_services'])
        self.assertEqual(report['coverage']['unprocessed_rows'], 0)
        self.assertIn('#2 clients/migrate (failed)', report['chat_summary'])
        self.assertIn('clients: migrate', report['chat_summary'])
        self.assertIn('migration-ops', report['chat_summary'])

    def test_complete_inventories_do_not_hide_an_unarchived_failed_outcome(self):
        op = self.start('all')
        runs = []
        for index, service in enumerate(console.SERVICES, start=1):
            run = {'id': index, 'service': service, 'mode': 'inspect', 'status': 'completed'}
            runs.append(run)
            exported = {'operation_id': op['id'], 'run': dict(run), 'service': service, 'mode': 'inspect',
                        'inventory': {'service': service, 'complete': True, 'tables': []}}
            with patch.object(control, 'artisan', return_value=exported):
                control.export_run(op, run)
        failed = {'id': 5, 'service': 'clients', 'mode': 'migrate', 'status': 'failed'}
        runs.append(failed)
        control.update(op, runs=runs, phase='migrate', current_service='clients', status='failed')
        report = control.build_report(op)
        self.assertTrue(report['coverage']['inventory_complete'])
        self.assertFalse(report['coverage']['archived_outcome_complete'])
        self.assertFalse(report['coverage']['semantic_complete'])
        self.assertFalse(report['archive_complete'])

    def test_live_migrate_run_does_not_count_as_archived_outcome(self):
        op = self.start('clients')
        inspect = {'id': 1, 'service': 'clients', 'mode': 'inspect', 'status': 'completed'}
        inspect_report = {'operation_id': op['id'], 'run': dict(inspect), 'service': 'clients', 'mode': 'inspect',
                          'inventory': {'service': 'clients', 'complete': True, 'tables': []}}
        op['runs'] = [inspect]
        with patch.object(control, 'artisan', return_value=inspect_report):
            control.export_run(op, inspect)
        control.update(op, status='running', phase='migrate', current_service='clients', current_run_id=2,
                       runs=[*op['runs'], {'id': 2, 'service': 'clients', 'mode': 'migrate',
                                            'status': 'running', 'live': True}])
        report = control.build_report(op)
        self.assertFalse(report['coverage']['archived_outcome_complete'])
        self.assertEqual(report['coverage']['missing_archived_outcome_services'], ['clients'])
        self.assertFalse(report['coverage']['semantic_complete'])
        self.assertFalse(report['archive_complete'])

    def test_partial_runs_continue_services_and_stages(self):
        calls = []
        def run(service, mode, operation, sid):
            calls.append((service, mode))
            self.run_stage(service, mode, operation, sid)
            return 'conflicts' if mode == 'dry-run' else 'completed'
        op = self.start()
        with patch.object(console, 'checkpoint', side_effect=AssertionError('No snapshot during import')), patch.object(control, 'enqueue', side_effect=run):
            control.execute(op)
        self.assertEqual(calls, [(s, m) for s in console.SERVICES for m in control.MODES])
        self.assertEqual(control.report(op['id'])['operation']['status'], 'partial')
        self.assertEqual(op['data_outcome'], 'partial')

    def test_failed_service_does_not_hide_other_service_results(self):
        def run(service, mode, operation, sid):
            return 'failed' if service == 'employees' else self.run_stage(service, mode, operation, sid)
        op = self.start()
        with patch.object(console, 'checkpoint', return_value='123'), patch.object(control, 'enqueue', side_effect=run):
            control.execute(op)
        self.assertEqual([r['status'] for r in op['service_results']], ['failed', 'completed', 'completed', 'completed'])
        self.assertEqual(op['status'], 'partial')

    def test_cancel_between_stages_retains_completed_work_and_reason(self):
        op = self.start('clients')
        calls = []
        def run(service, mode, operation, sid):
            calls.append(mode)
            self.run_stage(service, mode, operation, sid)
            control.cancel({'operation_id': op['id'], 'reason': 'synthetic requested stop'})
            return 'completed'
        with patch.object(console, 'checkpoint', return_value='123'), patch.object(control, 'enqueue', side_effect=run):
            control.execute(op)
        self.assertEqual(calls, ['inspect'])
        self.assertEqual(op['status'], 'cancelled')
        self.assertEqual(op['data_outcome'], 'not_started')
        self.assertIn('synthetic requested stop', control.report(op['id'])['chat_summary'])

    def test_cancel_requires_actual_nonempty_string_reason(self):
        op = self.start('clients')
        for body in ({}, *({'reason': value} for value in (None, True, False, 42, [], {}, '', '   ', 'x' * 501))):
            with self.subTest(body=body), self.assertRaises(ValueError):
                control.cancel({'operation_id': op['id'], **body})
        self.assertFalse(console.state()['operation']['cancel_requested'])
        cancelled = control.cancel({'operation_id': op['id'], 'reason': '  synthetic requested stop  '})
        self.assertEqual(cancelled['cancel_reason'], 'synthetic requested stop')

    def test_archive_complete_and_run_id_reuse_after_metadata_restore(self):
        for _ in range(2):
            op = self.start('clients')
            run = {'id': 1, 'service': 'clients', 'mode': 'inspect', 'status': 'completed'}
            op['runs'] = [run]
            full = {'operation_id': op['id'], 'run': dict(run), 'events': list(range(1201)),
                    'inventory': {'service': 'clients', 'complete': True, 'tables': [{'name': str(i)} for i in range(801)]},
                    'conflicts': [{'code': 'missing'} for _ in range(605)]}
            with patch.object(control, 'artisan', return_value=full):
                control.export_run(op, run)
            control.finish(op, 'partial', 'synthetic')
            # SQLite/console rewind cannot erase the host archive.
            console.STATE.write_text(json.dumps({'operation': None, 'history': []}))
            report = control.report(op['id'])
            self.assertEqual(len(report['runs'][0]['events']), 1201)
            self.assertEqual(len(report['inventory'][0]['tables']), 801)
            self.assertEqual(report['root_cause_groups'][0]['count'], 605)
        self.assertEqual(len(control.history()), 2)
        self.assertNotEqual(control.history()[0]['archive_id'], control.history()[1]['archive_id'])

    def test_terminal_report_immutable(self):
        op = self.start('clients')
        control.finish(op, 'failed', 'original')
        with self.assertRaises(FileExistsError):
            control.atomic(control.directory(op) / 'report.json', {'changed': True}, immutable=True)
        self.assertEqual(control.report(op['id'])['operation']['message'], 'original')

    def test_headline_latest_stage_does_not_multiply_source_rows(self):
        op = self.start('clients')
        for mode in ('inspect', 'dry-run', 'migrate', 'validate'):
            run = {'id': len(op['runs']) + 1, 'service': 'clients', 'mode': mode, 'status': 'completed'}
            op['runs'].append(run)
            full = {'operation_id': op['id'], 'run': dict(run), 'service': 'clients', 'mode': mode,
                    'inventory': {'service': 'clients', 'complete': True, 'tables': []},
                    'root_cause_groups': [] if mode == 'validate' else [
                        {'table': 'clients', 'code': 'missing', 'severity': 'error', 'message': 'missing reference', 'count': 20, 'affected_rows': 10},
                        {'table': 'members', 'code': 'missing', 'severity': 'warning', 'message': 'missing source', 'count': 30, 'affected_rows': 15}]}
            with patch.object(control, 'artisan', return_value=full):
                control.export_run(op, run)
        report = control.build_report(op)
        self.assertEqual(len(report['runs']), 4)
        self.assertEqual(len(report['inventory']), 1)
        self.assertEqual(len(report['root_cause_groups']), 2)
        self.assertEqual(sum(g['affected_rows'] for g in report['root_cause_groups']), 25)
        self.assertTrue(all(g['stage'] == 'migrate' for g in report['root_cause_groups']))

    def test_terminal_completed_stage_with_unprocessed_rows_is_partial(self):
        op = self.start('clients')
        run = {'id': 1, 'service': 'clients', 'mode': 'migrate', 'status': 'completed'}
        full = {'operation_id': op['id'], 'run': run, 'inventory': {'service': 'clients', 'complete': True, 'tables': []},
                'tables': [{'source_outcomes': {'unprocessed': 5}}]}
        with patch.object(control, 'artisan', side_effect=[{'run_id': 1}, full]), \
                patch.object(console, 'run_record', return_value={**run, 'error': 'postgres://private secret'}):
            status = control.enqueue('clients', 'migrate', op, '123')
        self.assertEqual(status, 'partial')
        self.assertNotIn('postgres://private', json.dumps(console.state()))

    def test_report_identity_mismatch_blocks_archive(self):
        op = self.start('clients')
        run = {'id': 1, 'service': 'clients', 'mode': 'migrate', 'status': 'completed'}
        op['runs'] = [run]
        with patch.object(control, 'artisan', return_value={'run': {**run, 'service': 'employees'}}):
            with self.assertRaises(control.AuditError):
                control.export_run(op, run)

    def test_restart_missing_export_is_explicitly_incomplete(self):
        op = self.start('clients')
        control.update(op, phase='migrate', data_outcome='unknown', runs=[{'id': 1, 'service': 'clients', 'mode': 'migrate', 'status': 'running'}])
        with patch.object(console, 'run_record', return_value=None):
            control.recover()
        report = control.report(op['id'])
        self.assertFalse(report['archive_complete'])
        self.assertEqual(len(report['unarchived_runs']), 1)

    def test_archive_key_without_file_is_not_complete_evidence(self):
        op = self.start('clients')
        run = {'id': 1, 'service': 'clients', 'mode': 'migrate', 'status': 'completed'}
        op['runs'] = [run]
        full = {'operation_id': op['id'], 'run': dict(run), 'service': 'clients', 'mode': 'migrate',
                'inventory': {'service': 'clients', 'complete': True, 'tables': []}, 'tables': []}
        with patch.object(control, 'artisan', return_value=full):
            control.export_run(op, run)
        archive = control.directory(op) / 'runs' / (run['archive_key'] + '.json')
        archive.unlink()
        report = control.build_report(op)
        self.assertFalse(report['archive_complete'])
        self.assertEqual(report['unarchived_runs'][0]['reason'], 'missing_archive_file')
        self.assertFalse(report['coverage']['semantic_complete'])

    def test_failed_unique_rows_block_semantic_completion_even_when_unprocessed_is_zero(self):
        op = self.start('clients')
        run = {'id': 1, 'service': 'clients', 'mode': 'migrate', 'status': 'completed'}
        op['runs'] = [run]
        full = {'operation_id': op['id'], 'run': dict(run), 'service': 'clients', 'mode': 'migrate',
                'inventory': {'service': 'clients', 'complete': True, 'tables': []},
                'tables': [{'table_name': 'clients', 'total': 1, 'processed_count': 1,
                            'source_outcomes': {'domain_written': 0, 'reference_matched': 0,
                                                'metadata_only': 0, 'deferred': 0,
                                                'failed_unique': 1, 'unprocessed': 0}}]}
        with patch.object(control, 'artisan', return_value=full):
            control.export_run(op, run)
        report = control.build_report(op)
        self.assertEqual(report['coverage']['failed_unique_rows'], 1)
        self.assertFalse(report['coverage']['semantic_complete'])

    def test_mid_write_failure_keeps_exact_stage_and_unknown_write_outcome(self):
        op = self.start('all')
        next_id = 0

        def run(service, mode, operation, sid):
            nonlocal next_id
            next_id += 1
            record = {'id': next_id, 'service': service, 'mode': mode,
                      'status': 'failed' if service == 'employees' and mode == 'migrate' else 'completed'}
            operation['runs'].append(record)
            if record['status'] == 'failed':
                return 'failed'
            return 'completed'

        with patch.object(console, 'checkpoint', return_value='123'), patch.object(control, 'enqueue', side_effect=run):
            control.execute(op)

        employee = next(item for item in op['service_results'] if item['service'] == 'employees')
        self.assertEqual(employee['status'], 'failed')
        self.assertEqual(employee['stage'], 'migrate')
        self.assertEqual(employee['stage_status'], 'failed')
        self.assertIsNotNone(employee['run_id'])
        self.assertEqual([item['status'] for item in op['service_results'][1:]], ['completed', 'completed', 'completed'])
        report = control.report(op['id'])
        evidence = next(item for item in report['service_evidence'] if item['service'] == 'employees')
        self.assertEqual(evidence['stage'], 'migrate')
        self.assertEqual(evidence['write_state'], 'unknown')
        self.assertIn('Нет архивного доказательства этапа', evidence['reason'])
        self.assertIn('неизвестное', evidence['consequence'].lower())
        self.assertIn('Сервис employees: failed; этап отказа/последний этап: migrate', report['chat_summary'])

    def test_coverage_separates_expected_excluded_and_undocumented_rows(self):
        op = self.start('clients')
        run = {'id': 1, 'service': 'clients', 'mode': 'migrate', 'status': 'completed'}
        op['runs'] = [run]
        full = {
            'operation_id': op['id'], 'run': dict(run), 'service': 'clients', 'mode': 'migrate',
            'inventory': {'service': 'clients', 'complete': True, 'tables': [
                {'schema': 'public', 'name': 'clients', 'total': 10, 'expectation': {'status': 'expected'}, 'columns': []},
                {'schema': 'public', 'name': 'notes', 'total': 40, 'expectation': {'status': 'excluded'}, 'columns': []},
                {'schema': 'public', 'name': 'mystery', 'total': 7, 'expectation': {'status': 'undocumented'}, 'columns': []},
            ]},
            'tables': [
                {'table_name': 'clients', 'total': 10, 'processed_count': 7, 'expectation': {'status': 'expected'},
                 'source_outcomes': {'domain_written': 7, 'reference_matched': 0, 'metadata_only': 0, 'deferred': 0, 'failed_unique': 0, 'unprocessed': 3}},
                {'table_name': 'notes', 'total': 40, 'processed_count': 0, 'expectation': {'status': 'excluded'},
                 'source_outcomes': {'domain_written': 0, 'reference_matched': 0, 'metadata_only': 40, 'deferred': 0, 'failed_unique': 0, 'unprocessed': 40}},
                {'table_name': 'mystery', 'total': 7, 'processed_count': 0, 'expectation': {'status': 'undocumented'},
                 'source_outcomes': {'domain_written': 0, 'reference_matched': 0, 'metadata_only': 0, 'deferred': 0, 'failed_unique': 0, 'unprocessed': 7}},
            ],
        }
        with patch.object(control, 'artisan', return_value=full):
            control.export_run(op, run)
        report = control.build_report(op)
        coverage = report['coverage']
        self.assertEqual(coverage['unprocessed_rows'], 50)
        self.assertEqual(coverage['expected_unprocessed_rows'], 3)
        self.assertEqual(coverage['excluded_unprocessed_rows'], 40)
        self.assertEqual(coverage['undocumented_unprocessed_rows'], 7)
        self.assertEqual(coverage['table_expectations'], {'expected': 1, 'excluded': 1, 'undocumented': 1})
        self.assertIn('из них ожидаемых к доменному переносу 3, сознательно исключённых 40, с неописанным решением 7', report['chat_summary'])
        self.assertFalse(coverage['semantic_complete'])

    def test_consciously_excluded_rows_do_not_by_themselves_make_semantic_coverage_incomplete(self):
        op = self.start('clients')
        run = {'id': 1, 'service': 'clients', 'mode': 'migrate', 'status': 'completed'}
        op['runs'] = [run]
        full = {
            'operation_id': op['id'], 'run': dict(run), 'service': 'clients', 'mode': 'migrate',
            'inventory': {'service': 'clients', 'complete': True, 'tables': [
                {'schema': 'public', 'name': 'notes', 'total': 40, 'expectation': {'status': 'excluded'}, 'columns': []},
            ]},
            'tables': [
                {'table_name': 'notes', 'total': 40, 'processed_count': 0, 'expectation': {'status': 'excluded'},
                 'source_outcomes': {'domain_written': 0, 'reference_matched': 0, 'metadata_only': 40, 'deferred': 0, 'failed_unique': 0, 'unprocessed': 40}},
            ],
        }
        with patch.object(control, 'artisan', return_value=full):
            control.export_run(op, run)
        report = control.build_report(op)
        self.assertEqual(report['coverage']['excluded_unprocessed_rows'], 40)
        self.assertEqual(report['coverage']['expected_unprocessed_rows'], 0)
        self.assertTrue(report['coverage']['semantic_complete'])

    def test_snapshot_failure_proves_write_was_not_started(self):
        op = self.start('clients')
        control.result(op, 'clients', 'failed', 'Точка отката не создана', stage='snapshot', stage_status='failed')
        control.update(op, status='failed', phase='snapshot', current_service='clients', data_outcome='not_started')
        report = control.build_report(op)
        evidence = next(item for item in report['service_evidence'] if item['service'] == 'clients')
        self.assertEqual(evidence['stage'], 'snapshot')
        self.assertEqual(evidence['write_state'], 'not_started')
        self.assertIn('до этапа записи', evidence['consequence'])

    def test_live_counters_require_matching_operation_event_and_do_not_freeze(self):
        op = self.start('clients')
        control.update(op, status='running', phase='migrate', current_run_id=1,
                       runs=[{'id': 1, 'service': 'clients', 'mode': 'migrate', 'status': 'queued'}])
        with sqlite3.connect(self.root / 'migration.sqlite') as db:
            db.execute("INSERT INTO migration_runs VALUES (1,'clients','migrate','running',NULL)")
            db.execute('ALTER TABLE migration_runs ADD COLUMN processed_count INTEGER DEFAULT 99')
            db.execute('CREATE TABLE migration_run_events (migration_run_id INTEGER,event TEXT,context TEXT)')
            db.execute('CREATE TABLE migration_table_progress (id INTEGER PRIMARY KEY,migration_run_id INTEGER,table_name TEXT,state TEXT,total INTEGER,processed_count INTEGER,read_count INTEGER)')
            db.execute("INSERT INTO migration_table_progress VALUES (1,1,'clients','processing',100,27,31)")
            db.execute("INSERT INTO migration_run_events VALUES (1,'console',?)", (json.dumps({'operation_id': 'other'}),))
        self.assertEqual(control.payload()['operation']['runs'][0]['status'], 'queued')
        with sqlite3.connect(self.root / 'migration.sqlite') as db:
            db.execute('UPDATE migration_run_events SET context=?', (json.dumps({'operation_id': op['id']}),))
        live = control.payload()['operation']['runs'][0]
        self.assertEqual(live['status'], 'running')
        self.assertEqual(live['tables'][0]['processed_count'], 27)
        self.assertEqual(live['processed_count'], 27)
        self.assertEqual(live['total'], 100)
        self.assertEqual(control.report(op['id'])['runs'][0]['read_count'], 31)
        self.assertNotIn('live', control.read(control.directory(op) / 'operation.json')['runs'][0])

    def test_document_proof_detects_changed_and_extra_files(self):
        files = self.root / 'files'
        files.mkdir()
        (files / 'one.txt').write_text('first')
        expected = snapshot.document_fingerprint(files)
        (files / 'one.txt').write_text('second')
        self.assertNotEqual(snapshot.document_fingerprint(files), expected)
        (files / 'one.txt').write_text('first')
        (files / 'extra.txt').write_text('extra')
        self.assertNotEqual(snapshot.document_fingerprint(files), expected)

    def test_fingerprint_streams_row_multiset_and_sequence_state(self):
        catalog = '\n'.join([json.dumps(['clients', 'clients', 'r']), json.dumps(['clients', 'clients_id_seq', 'S'])])
        def stream(args, stdout):
            stdout.write(b'a' * 32 + b'\n' + b'b' * 32 + b'\n')
        with patch.object(snapshot.common, 'postgres_sql', side_effect=[catalog, '[9,true]']), \
                patch.object(snapshot.common, 'container', return_value='synthetic'), patch.object(snapshot.common, 'run', side_effect=stream):
            proof = snapshot.target_fingerprint('clients')
        self.assertEqual(proof['clients.clients']['rows'], 2)
        self.assertEqual(proof['clients.clients_id_seq']['state'], [9, True])

    def test_restore_completed_flags_without_content_proof_remain_unverified(self):
        diagnostic = {'scope': 'clients', 'snapshot_id': '123', 'stage': 'completed', 'state': 'completed',
                      'database_outcome': 'committed', 'database_committed': True, 'metadata_complete': True, 'schemas_upgraded': True}
        op = {'id': '991', 'action': 'restore', 'scope': 'clients', 'snapshot_id': '123', 'started_at': 1,
              'status': 'completed', 'diagnostic_id': 'a' * 32, 'diagnostics': diagnostic}
        console.update(op)
        self.assertTrue(control.payload()['restore_history'][0]['stages_completed'])
        self.assertFalse(control.payload()['restore_history'][0]['confirmed'])
        verification = {key: True for key in ('checkpoint_verification_available', 'database_baseline_matches', 'metadata_matches',
            'credential_key_matches', 'documents_match', 'final_database_matches', 'final_metadata_matches', 'runtime_started')}
        verification.update(diagnostic_id=op['diagnostic_id'], scope='clients', snapshot_id='123')
        console.update(op, verification=verification)
        self.assertTrue(control.payload()['restore_history'][0]['confirmed'])
        console.update(op, verification={**verification, 'final_database_matches': False})
        self.assertFalse(control.payload()['restore_history'][0]['confirmed'])

    def test_archive_failure_stops_all_services(self):
        op = self.start()
        with patch.object(console, 'checkpoint', return_value='123'), patch.object(control, 'enqueue', side_effect=control.AuditError('archive failed')) as run:
            control.execute(op)
        self.assertEqual(run.call_count, 1)
        self.assertEqual(op['status'], 'failed')

    def test_restart_does_not_replay_or_guess_data_outcome(self):
        op = self.start('clients')
        control.update(op, phase='migrate', data_outcome='unknown')
        control.recover()
        report = control.report(op['id'])
        self.assertEqual(report['operation']['status'], 'interrupted')
        self.assertEqual(report['operation']['data_outcome'], 'unknown')

    def test_history_exceeds_legacy_window(self):
        for i in range(54):
            op = self.start('clients')
            control.finish(op, 'completed', 'done')
        self.assertEqual(len(control.history()), 54)
        self.assertEqual(len(console.state()['history']), 54)

    def test_restore_marker_never_proves_completed_restore(self):
        point = console.SNAPSHOTS / 'clients' / '123'
        point.mkdir(parents=True)
        (point / 'manifest.json').write_text(json.dumps({'service': 'clients', 'created_at_utc': '2026-01-01T00:00:00Z', 'schemas': ['clients']}))
        (point / 'restored').write_text('stale')
        op = {'id': '991', 'action': 'restore', 'scope': 'clients', 'snapshot_id': '123', 'started_at': 1,
              'status': 'failed', 'diagnostics': {'scope': 'clients', 'snapshot_id': '123', 'database_outcome': 'committed', 'stage': 'documents'}}
        console.update(op)
        payload = control.payload()
        self.assertFalse(payload['snapshots']['clients'][0]['restored'])
        self.assertFalse(payload['restore_history'][0]['confirmed'])
        self.assertEqual(payload['restore_history'][0]['database_outcome'], 'committed')
        console.STATE.write_text(json.dumps({'operation': None, 'history': []}))
        self.assertEqual(len(control.payload()['restore_history']), 1)


if __name__ == '__main__':
    unittest.main()
