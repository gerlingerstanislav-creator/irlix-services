import json
import io
from pathlib import Path
import tempfile
import time
import unittest
from unittest.mock import patch

from collector import Collector, RETENTION, aggregate, cpu_cores, disk_metrics, docker_get, group_for, host_metrics, memory_metrics, open_history, persist


class MetricsTest(unittest.TestCase):
    def test_disk_counts_shared_volume_once_and_keeps_other_project_out(self):
        def container(identity, service, volumes, project='test', size=10):
            return {'Id':identity*64,'Labels':{'com.docker.compose.project':project,'com.docker.compose.service':service},
                    'SizeRw':size,'Mounts':[{'Type':'volume','Name':name} for name in volumes]}
        with tempfile.TemporaryDirectory() as root:
            for name in ('models','shared','outside'):
                (Path(root)/name).mkdir()
            usage={'Containers':[container('a','cv-llm',['models']),container('b','cv-web',['models']),
                                 container('c','employees',['shared','outside']),container('d','timesheets',['shared']),
                                 container('e','employees',['outside'],'other')],
                   'Volumes':[{'Name':name,'Mountpoint':'/'+name,'UsageData':{'Size':100}} for name in ('models','shared','outside')]}
            result=disk_metrics(usage,'test',root)
            self.assertEqual(result['groups']['cv-converter']['disk_bytes'],120)
            self.assertEqual(result['groups']['cv-converter']['disk_volumes_bytes'],100)
            self.assertEqual(result['groups']['employees']['disk_bytes'],10)
            self.assertTrue(result['groups']['employees']['disk_partial'])
            self.assertTrue(result['groups']['timesheets']['disk_partial'])
            self.assertEqual(len(result['components']),4)
            self.assertNotIn('Mountpoint',json.dumps(result))

    def test_disk_missing_size_and_failed_volume_do_not_become_zero_measurements(self):
        with tempfile.TemporaryDirectory() as root:
            usage={'Containers':[{'Id':'a'*64,'SizeRw':-1,'Labels':{'com.docker.compose.project':'test','com.docker.compose.service':'postgres'},
                                 'Mounts':[{'Type':'volume','Name':'missing'},{'Type':'bind','RW':True}]}],
                   'Volumes':[{'Name':'missing','Mountpoint':'/no-such-volume','UsageData':{'Size':-1}}]}
            result=disk_metrics(usage,'test',root)
            self.assertIsNone(result['components']['a'*12])
            self.assertIsNone(result['groups']['postgres']['disk_bytes'])
            self.assertTrue(result['groups']['postgres']['disk_partial'])

    def test_disk_background_failure_preserves_last_good_measurement(self):
        from concurrent.futures import Future
        collector=Collector(Path('.'),Path('.'),'.','test')
        collector.disk={'collected_at':int(time.time()),'groups':{'postgres':{'disk_bytes':123,'disk_partial':False}},'components':{'a'*12:10},'partial':False}
        failed=Future(); failed.set_exception(RuntimeError('synthetic failure'))
        collector.disk_future=failed; collector.disk_attempt=time.monotonic()
        rows=[{'id':'postgres','components':[{'id':'a'*12}]}]
        metadata=collector.update_disk(rows)
        self.assertTrue(metadata['error']); self.assertEqual(rows[0]['disk_bytes'],123)
        rows=[{'id':'postgres','components':[{'id':'b'*12}]}]
        collector.update_disk(rows)
        self.assertIsNone(rows[0]['disk_bytes'])
        collector.disk_pool.shutdown()

    def test_cpu_deltas_and_counter_reset(self):
        stats = {'cpu_stats': {'cpu_usage': {'total_usage': 300}, 'system_cpu_usage': 1400, 'online_cpus': 4}}
        self.assertEqual(cpu_cores(stats, (100, 1000)), (2, (300, 1400)))
        self.assertIsNone(cpu_cores(stats, None)[0])
        self.assertIsNone(cpu_cores(stats, (400, 1500))[0])

    def test_memory_cgroups_v1_v2_and_boundaries(self):
        for cache in ({'inactive_file': 40}, {'total_inactive_file': 40}, {'cache': 40}):
            self.assertEqual(memory_metrics({'memory_stats': {'usage': 100, 'stats': cache}}),
                             {'memory_bytes': 100, 'working_bytes': 60, 'cache_bytes': 40})
        self.assertEqual(memory_metrics({'memory_stats': {'usage': 100, 'stats': {'inactive_file': 200}}})['working_bytes'], 0)

    def test_group_components_and_partial_data(self):
        self.assertEqual(group_for('cv-llm'), 'cv-converter')
        self.assertEqual(group_for('timesheets-web'), 'timesheets')
        self.assertEqual(group_for('timesheets-reconcile'), 'timesheets')
        self.assertEqual(group_for('postgres'), 'postgres')
        rows = aggregate([{'group': 'employees', 'state': 'running', 'memory_bytes': 100, 'cpu_cores': 1},
                          {'group': 'employees', 'state': 'running', 'memory_bytes': 20, 'cpu_cores': None},
                          {'group': 'postgres', 'error': True}])
        self.assertEqual(rows[0]['memory_bytes'], 120)
        self.assertIsNone(rows[0]['cpu_cores'])
        self.assertTrue(rows[1]['partial'])

    def test_history_retention_and_first_sample_per_minute(self):
        with tempfile.TemporaryDirectory() as directory:
            db = open_history(Path(directory) / 'history.sqlite')
            ts = int(time.time()) // 60 * 60
            snapshot = {'collected_at': ts, 'host': {'memory_used': 50, 'cpu_percent': 4},
                        'services': [{'id':'employees','memory_bytes':30,'working_bytes':20,'cpu_cores':1,'partial':False}]}
            db.execute('INSERT INTO samples VALUES (?,?,?,?,?)', (ts-RETENTION-60,'__host__',1,1,1)); db.commit()
            persist(db,snapshot); persist(db,snapshot)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM samples').fetchone()[0],2)
            snapshot['services'][0]['partial']=True; snapshot['collected_at']+=60
            persist(db,snapshot)
            self.assertEqual(db.execute("SELECT COUNT(*) FROM samples WHERE service='employees'").fetchone()[0],1)
            db.close()

    def test_host_available_memory_guest_not_double_counted(self):
        with tempfile.TemporaryDirectory() as directory:
            proc=Path(directory)
            (proc/'meminfo').write_text('MemTotal: 1000 kB\nMemAvailable: 300 kB\nCached: 200 kB\nSReclaimable: 50 kB\nShmem: 10 kB\nSwapTotal: 100 kB\nSwapFree: 80 kB\n')
            (proc/'stat').write_text('cpu 100 0 0 100 0 0 0 0 80 0\ncpu0 100 0 0 100\n')
            (proc/'loadavg').write_text('0.1 0.2 0.3 1/2 5')
            host,current=host_metrics(proc,directory,(100,100))
            self.assertEqual(current,(200,100)); self.assertEqual(host['cpu_percent'],100)
            self.assertEqual(host['memory_used'],700*1024); self.assertEqual(host['memory_cache'],240*1024)
            self.assertEqual(host['swap_used'],20*1024)

    def test_host_memory_limit_is_never_mistaken_for_vm_capacity(self):
        with tempfile.TemporaryDirectory() as directory:
            proc=Path(directory)
            (proc/'meminfo').write_text('MemTotal: 98304 kB\nMemAvailable: 86000 kB\n')
            (proc/'stat').write_text('cpu 100 0 0 100 0 0 0 0\ncpu0 100 0 0 100\n')
            (proc/'loadavg').write_text('0.1 0.2 0.3 1/2 5')
            with self.assertRaisesRegex(RuntimeError, 'does not match'):
                host_metrics(proc, directory, None, 8 * 1024**3)
            host, _ = host_metrics(proc, directory, None, 96 * 1024**2)
            self.assertEqual(host['memory_total'], 96 * 1024**2)

    def test_host_capacity_from_docker_engine_is_cached(self):
        collector=Collector(Path('.'),Path('.'),'.','test')
        with patch('collector.docker_get',return_value={'MemTotal':8 * 1024**3}) as get:
            self.assertEqual(collector.host_memory_capacity(),8 * 1024**3)
            self.assertEqual(collector.host_memory_capacity(),8 * 1024**3)
            get.assert_called_once_with('/info')
        collector.disk_pool.shutdown()

    def test_no_arbitrary_docker_endpoints(self):
        for path in ('/images/json','/containers/abc/stop','/containers/abc/exec'):
            with self.assertRaises(ValueError): docker_get(path)

    def test_docker_api_version_negotiation(self):
        from unittest.mock import MagicMock
        connection = MagicMock()
        version = io.StringIO('{"ApiVersion":"1.52","MinAPIVersion":"1.44"}'); version.status = 200
        data = io.StringIO('[]'); data.status = 200
        connection.getresponse.side_effect = [version, data]
        with patch('collector.API_VERSION', None), patch('collector.DockerConnection', return_value=connection):
            self.assertEqual(docker_get('/containers/json?all=true'), [])
        self.assertEqual(connection.request.call_args_list[0].args, ('GET', '/version'))
        self.assertEqual(connection.request.call_args_list[1].args, ('GET', '/v1.52/containers/json?all=true'))

    def test_component_only_persists_whitelisted_fields(self):
        identity='a'*64
        info={'Config': {'Env':['SECRET=do-not-store']},'HostConfig':{'Memory':1024,'NanoCpus':1500000000},
              'RestartCount':2,'State':{'OOMKilled':True}}
        stats={'cpu_stats': {'cpu_usage':{'total_usage':100},'system_cpu_usage':500,'online_cpus':4},
               'memory_stats':{'usage':300,'stats':{'inactive_file':100}}}
        collector=Collector(Path('.'),Path('.'),'.','test')
        with patch('collector.docker_get',side_effect=[info,stats]):
            item=collector.component({'Id':identity,'Labels':{'com.docker.compose.service':'cv-llm'},'State':'running'})
        self.assertEqual(item['cpu_limit'],1.5); self.assertEqual(item['memory_limit'],1024)
        self.assertTrue(item['oom_killed']); self.assertNotIn('SECRET',json.dumps(item))


if __name__ == '__main__': unittest.main()
