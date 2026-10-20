<?php
// Synthetic integration: control mode is additive, partial and repeatable; exports are full and safe.
$path = '/tmp/migration-control-'.getmypid().'.sqlite'; touch($path);
putenv('MIGRATION_METADATA_DATABASE='.$path);
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
function ensureControl(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
class ControlFixture extends \App\Migration\Core\SchemaMigration {
    public array $fixture = [];
    public function key(): string { return 'clients'; }
    protected function extract(): array { return $this->fixture; }
    protected function assertCheckpoint(): void {}
    protected function import(): void {
        $this->rows('things',function ($row,$id) {
            $status = $this->enum($row['status'],['open'=>'open'],'things.status');
            $this->write('things',$id,'things',['name'=>$row['name'],'status'=>$status],$row,['name'=>$row['name']]);
        });
    }
}
try {
    config(['database.connections.target_clients'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);
    \Illuminate\Support\Facades\DB::purge('target_clients');
    $target = \Illuminate\Support\Facades\DB::connection('target_clients');
    $target->statement('CREATE TABLE things (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, status TEXT, created_at TEXT, updated_at TEXT)');
    $store = app(\App\Migration\Core\MigrationStore::class);
    $module = new ControlFixture($store);
    $module->fixture = ['things'=>[['id'=>1,'name'=>'valid','status'=>'open'],['id'=>2,'name'=>'unknown','status'=>'unexpected']]];
    $strict = $store->beginRun('clients','migrate');
    try { $module->migrate($strict,false); throw new LogicException('Strict preflight accepted invalid source'); }
    catch (RuntimeException $e) { ensureControl(str_contains($e->getMessage(),'Import blocked'),'Existing strict mode retains preflight gate'); }
    ensureControl($target->table('things')->count()===0,'Strict migration writes no target rows');
    \App\Migration\Core\ControlMode::$enabled = true;
    $run = $store->beginRun('clients','migrate');
    \App\Migration\Core\TableProgress::$activeRun = $run;
    $store->event($run,'console','Synthetic control identity','info',['operation_id'=>'12345']);
    $result = $module->migrate($run,false);
    ensureControl($result['conflicts']===1 && $target->table('things')->count()===1,'Control migrates independent safe row and rejects unknown status');
    $first = $store->mapping('clients','things',1);
    $repeat = $store->beginRun('clients','migrate');
    $module->migrate($repeat,false);
    ensureControl($target->table('things')->count()===1 && $store->mapping('clients','things',1)===$first,'Retry reuses target identity');
    $target->table('things')->insert(['name'=>'collision','status'=>'open']);
    $module->fixture['things']=[['id'=>3,'name'=>'collision','status'=>'open']];
    $collision = $module->migrate($repeat,false);
    ensureControl($collision['conflicts']===1 && $store->mapping('clients','things',3)===null,'Unowned natural-key collision remains unresolved');
    \Illuminate\Support\Facades\DB::table('migration_control_write_intents')->insert(['service'=>'clients','entity_type'=>'things','legacy_id'=>'4','migration_run_id'=>$repeat,'created_at'=>now(),'updated_at'=>now()]);
    $module->fixture['things']=[['id'=>4,'name'=>'possible-orphan','status'=>'open']];
    $intent = $module->migrate($repeat,false);
    ensureControl($intent['conflicts']===1 && $target->table('things')->count()===2,'Cross-database interrupted write cannot duplicate on retry');
    $target->statement("CREATE TRIGGER reject_fixture BEFORE INSERT ON things WHEN NEW.name='reject-once' BEGIN SELECT RAISE(ABORT,'synthetic rejection'); END");
    $module->fixture['things']=[['id'=>5,'name'=>'reject-once','status'=>'open']];
    try { $module->migrate($repeat,false); throw new LogicException('Target SQL failure expected'); }
    catch (\Illuminate\Database\QueryException $expected) {}
    ensureControl(!\Illuminate\Support\Facades\DB::table('migration_control_write_intents')->where(['service'=>'clients','entity_type'=>'things','legacy_id'=>'5'])->exists(),'Proven precommit rollback clears intent');
    ensureControl(!$target->table('things')->where('name','reject-once')->exists(),'Failed target transaction actually rolled back');
    $target->statement('DROP TRIGGER reject_fixture');
    $retry=$module->migrate($repeat,false);
    ensureControl($retry['conflicts']===0 && $store->mapping('clients','things',5)!==null,'Ordinary rolled-back failure is safely retryable');
    for ($i=0;$i<260;$i++) {
        $store->conflict($run,'clients','things','row-'.($i % 130),'SYNTHETIC','Synthetic full diagnostic',['source'=>['password'=>'sensitive'],'field'=>'status']);
        $store->event($run,'synthetic','Safe event','info',['source'=>['secret'=>'sensitive']]);
    }
    $store->finishRun($run,'partial',$result);
    $report = app(\App\Migration\Core\ControlReport::class)->export($run,'12345');
    ensureControl(count($report['diagnostics']['items'])===261,'Full report includes every diagnostic beyond old 200-row cap');
    ensureControl(count($report['events'])>100,'Full report includes events beyond old cap');
    ensureControl($report['diagnostics']['affected_rows']===131,'Multiple messages do not multiply affected rows');
    ensureControl(!str_contains(json_encode($report),'sensitive'),'Raw private source never exported');
    ensureControl(str_starts_with($report['implementation_revision'],'sha256:'),'Actual implementation fingerprint present');
    try { app(\App\Migration\Core\ControlReport::class)->export($run,'999'); throw new LogicException('Wrong operation accepted'); }
    catch (RuntimeException $expected) { ensureControl(str_contains($expected->getMessage(),'does not belong'),'Report identity fails closed after restore'); }
    $compact = \App\Migration\Core\ControlReport::compact($report);
    ensureControl(!isset($compact['diagnostics']['items'],$compact['events']) && count($compact['diagnostics']['groups'])===2,'Compact response retains complete groups without full arrays');
    $roles = [];
    \Illuminate\Support\Facades\Http::fake(function () use (&$roles) { return \Illuminate\Support\Facades\Http::response(['data'=>['roles'=>$roles,'employee'=>['id'=>42]]],200); });
    $app->instance(\App\Migration\Core\MigrationOperationsClient::class,new \App\Migration\Core\MigrationOperationsClient(fn()=>[200,$report]));
    $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    foreach ([null,[],['platform-tester'],['platform-admin']] as $identity) {
        $roles = $identity ?? [];
        foreach (['/api/migration/control/state','/api/migration/control/operations/12345/report','/api/migration/control/operations/12345/report?download=1'] as $uri) {
            $request = \Illuminate\Http\Request::create($uri,'GET');
            $request->headers->set('Accept','application/json');
            if ($identity !== null) $request->headers->set('Authorization','Bearer synthetic-token');
            $response = $kernel->handle($request);
            ensureControl($response->getStatusCode()===($identity===null ? 401 : ($roles===['platform-admin'] ? 200 : 403)),'Control state/report/download enforce platform-admin');
            if ($roles===['platform-admin'] && str_contains($uri,'download')) ensureControl(str_contains($response->headers->get('Content-Disposition'),'attachment') && count(json_decode($response->getContent(),true)['diagnostics']['items'])===261,'Download contains complete server export');
            $kernel->terminate($request,$response);
        }
    }
    // Cancellation must reach host operations despite an active console owner; no auth bypass.
    $cancelRequests=0;
    $app->instance(\App\Migration\Core\MigrationOperationsClient::class,new \App\Migration\Core\MigrationOperationsClient(function ($method,$path,$body) use (&$cancelRequests,$report) {
        if ($method==='POST' && $path==='/control/cancel') {
            $cancelRequests++;
            ensureControl(($body['reason'] ?? '')==='Synthetic stop' && ($body['operation_id'] ?? '')==='12345' && ($body['requested_by'] ?? '')==='42','Cancellation identity, actor and reason forwarded');
            return [202,['status'=>'cancellation_requested']];
        }
        if ($path==='/console/state') return [200,['operation'=>['id'=>'12345','status'=>'running']]];
        return [200,$report];
    }));
    foreach ([[null,[],401],['Bearer synthetic-token',[],403],['Bearer synthetic-token',['platform-admin'],202]] as [$auth,$roles,$expected]) {
        $request=\Illuminate\Http\Request::create('/api/migration/control/cancel','POST',['operation_id'=>'12345','reason'=>'Synthetic stop']);
        $request->headers->set('Accept','application/json');
        if ($auth) $request->headers->set('Authorization',$auth);
        $response=$kernel->handle($request);
        ensureControl($response->getStatusCode()===$expected,'Active-owner cancellation retains authentication and authorization');
        $kernel->terminate($request,$response);
    }
    ensureControl($cancelRequests===1,'Only authorized cancellation reaches host operations');
    foreach ([null,123,['invalid']] as $invalidReason) {
        $request=\Illuminate\Http\Request::create('/api/migration/control/cancel','POST',['operation_id'=>'12345','reason'=>$invalidReason]);
        $request->headers->set('Accept','application/json');$request->headers->set('Authorization','Bearer synthetic-token');
        $response=$kernel->handle($request);ensureControl($response->getStatusCode()===422,'Cancel reason must be a required string');$kernel->terminate($request,$response);
    }
    ensureControl($cancelRequests===1,'Invalid cancellation reasons never reach host operations');

    $request=\Illuminate\Http\Request::create('/api/migration/control/start','POST',['scope'=>'clients','confirm'=>true]);
    $request->headers->set('Accept','application/json');$request->headers->set('Authorization','Bearer synthetic-token');
    $response=$kernel->handle($request);
    ensureControl($response->getStatusCode()===409,'New start remains blocked during active operation');$kernel->terminate($request,$response);
    // Host-only control reads survive metadata restore, with normal platform-admin authorization.
    $maintenancePath=$path.'.restore.json'; file_put_contents($maintenancePath,json_encode(['state'=>'running']));
    config(['migration.restore_status_path'=>$maintenancePath]);
    foreach ([['/api/migration/control/state',200],['/api/migration/control/operations/12345/report',200],['/api/migration/control/operations/12345/report?download=1',200],['/api/migration/runs/1',503],['/api/migration/control/operations/not-numeric/report',503]] as [$uri,$expected]) {
        $request=\Illuminate\Http\Request::create($uri,'GET');$request->headers->set('Accept','application/json');$request->headers->set('Authorization','Bearer synthetic-token');
        $response=$kernel->handle($request);ensureControl($response->getStatusCode()===$expected,'Restore maintenance narrowly permits host-side control reads');$kernel->terminate($request,$response);
    }
    foreach ([[null,[],401],['Bearer synthetic-token',[],403]] as [$auth,$roles,$expected]) {
        $request=\Illuminate\Http\Request::create('/api/migration/control/operations/12345/report','GET');$request->headers->set('Accept','application/json');
        if ($auth) $request->headers->set('Authorization',$auth);
        $response=$kernel->handle($request);ensureControl($response->getStatusCode()===$expected,'Maintenance exemption never bypasses report authorization');$kernel->terminate($request,$response);
    }
    @unlink($maintenancePath); config(['migration.restore_status_path'=>'/ops/restore-status.json']);
    // Catalog fixture includes a new empty table and inaccessible table outside required_tables.
    $reader = new class {
        public function assertSafe(): void {}
        public function select($sql,$bindings=[]): array {
            \App\Migration\Core\LegacyReader::assertReadQuery($sql);
            if (str_contains($sql,'FROM pg_enum')) return [(object)['enumlabel'=>'unexpected'],(object)['enumlabel'=>'unused']];
            if (str_contains($sql,'FROM pg_class')) return [
                (object)['schema_name'=>'public','table_name'=>'new_empty','column_name'=>'new_field','data_type'=>'text','type_kind'=>'b','readable'=>true],
                (object)['schema_name'=>'public','table_name'=>'new_states','column_name'=>'status','data_type'=>'status_enum','type_kind'=>'e','type_id'=>9001,'readable'=>true],
                (object)['schema_name'=>'public','table_name'=>'private_table','column_name'=>'unknown','data_type'=>'text','type_kind'=>'b','readable'=>false],
            ];
            return [(object)['value'=>null,'count'=>1],(object)['value'=>'unexpected','count'=>2]];
        }
        public function selectOne($sql,$bindings=[]): object {
            \App\Migration\Core\LegacyReader::assertReadQuery($sql);
            if (str_contains($sql,'new_empty')) return (object)['count'=>0,'filled'=>0,'null_count'=>0];
            return (object)['count'=>3,'filled'=>2,'null_count'=>1];
        }
    };
    $inventory = (new \App\Migration\Core\SourceInventory(fn()=>$reader))->capture('clients');
    ensureControl(count($inventory['tables'])===3 && !$inventory['complete'],'Catalog includes extra empty/unreadable tables explicitly');
    ensureControl($inventory['tables'][0]['columns'][0]['mapping']['fate']==='unmapped','Unknown field never inferred imported');
    ensureControl($inventory['tables'][1]['columns'][0]['values'][0]['value']===null && $inventory['tables'][1]['columns'][0]['values_complete'],'All status values include NULL with counts');
    ensureControl($inventory['tables'][1]['columns'][0]['filled']===2 && $inventory['tables'][0]['total']===0,'Filled counts and empty tables retained');
    ensureControl(count($inventory['tables'][1]['columns'][0]['declared_values'])===2 && $inventory['tables'][1]['columns'][0]['declared_values_complete'],'Declared unused enum values are inventoried');
    ensureControl($inventory['tables'][2]['unavailable_reason']!==null,'Unreadable table has explicit reason');
    echo "Control migration integration passed: strict compatibility, partial import, safe retry, full sanitized diagnostics, identity, authorization and catalog inventory.\n";
} finally {
    \App\Migration\Core\ControlMode::$enabled = false;
    \App\Migration\Core\TableProgress::$activeRun = null;
    \Illuminate\Support\Facades\DB::disconnect('sqlite');
    foreach ([$path,$path.'-wal',$path.'-shm',$path.'.restore.json'] as $file) @unlink($file);
}
