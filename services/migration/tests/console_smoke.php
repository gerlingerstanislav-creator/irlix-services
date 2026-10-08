<?php
// Offline SQLite integration: reporting has exact successes and separate warnings/read counts.
$path = '/tmp/migration-console-'.getmypid().'.sqlite';
touch($path);
putenv('MIGRATION_METADATA_DATABASE='.$path);
putenv('DB_CONNECTION=sqlite');
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
function check(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
$failure = null;
try {
    $store = app(\App\Migration\Core\MigrationStore::class);
    $run = $store->beginRun('employees', 'migrate');
    \App\Migration\Core\TableProgress::$activeRun = $run;
    \App\Migration\Core\TableProgress::extracted('SELECT count(*)::bigint AS count FROM public.employees', [(object) ['count' => 3]]);
    $store->saveMapping($run, 'employees', 'employee', 'synthetic-1', 1);
    $store->conflict($run, 'employees', 'employee', 'synthetic-1', 'SYNTHETIC_WARNING', 'Synthetic warning', [], 'warning');
    $store->conflict($run, 'employees', 'employee', 'synthetic-2', 'SYNTHETIC_ERROR', 'Synthetic error');
    \App\Migration\Core\TableProgress::extracted('SELECT id FROM public.users', [(object) ['id' => 1]]);
    $store->conflict($run, 'employees', 'employee', 'synthetic-2', 'SECOND_ERROR', 'Same row second error');
    $store->saveMapping($run, 'employees', 'employee', 'synthetic-1', 1);
    $store->finishRun($run, 'conflicts', []);
    $data = $store->run($run);
    $table = collect($data['tables'])->firstWhere('table_name', 'employees');
    check((int) $table['success_count'] === 1, 'Exact success counter');
    check((int) $table['processed_count'] === 2, 'Repeated errors and mappings count a row only once');
    check((int) $table['error_count'] === 2 && (int) $table['warning_count'] === 1, 'Separate error/warning counters');
    check((int) $table['total'] === 3, 'Source volume');
    check($table['state'] === 'conflicts', 'Table conflict state');
    $read = collect($data['tables'])->firstWhere('table_name', 'users');
    check($read['state'] === 'read_only' && (int) $read['success_count'] === 0, 'Reading is not import success');
    check($data['processed_count'] === 2 && $data['success_count'] === 1, 'Warning does not count as another processed row');
    $queued = $store->queueRun('vacations', 'inspect');
    try { $store->queueRun('vacations', 'inspect'); throw new LogicException('Duplicate accepted'); }
    catch (RuntimeException $e) { check($e->getCode() === 409, 'Duplicate blocked'); }
    // Exercise the real delete route without touching the ops socket or any archive.
    $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    $cases = [
        [null, [], 'employees', '123', 401],
        ['Bearer synthetic-token', [], 'employees', '123', 403],
        ['Bearer synthetic-token', ['platform-admin'], 'unknown', '123', 422],
        ['Bearer synthetic-token', ['platform-admin'], 'employees', 'invalid', 422],
        ['Bearer synthetic-token', ['platform-admin'], 'employees', '123', 409],
    ];
    $roles = [];
    \Illuminate\Support\Facades\Http::fake(function () use (&$roles) {
        return \Illuminate\Support\Facades\Http::response(['data' => ['roles' => $roles]], 200);
    });
    foreach ($cases as [$authorization, $roles, $scope, $snapshot, $expected]) {
        $request = \Illuminate\Http\Request::create('/api/migration/console/snapshots/'.$scope.'/'.$snapshot, 'DELETE');
        $request->headers->set('Accept', 'application/json');
        if ($authorization) $request->headers->set('Authorization', $authorization);
        $response = $kernel->handle($request);
        check($response->getStatusCode() === $expected, 'Backup delete route authorization/scope/active-run guard: '.$expected.' got '.$response->getStatusCode());
        $kernel->terminate($request, $response);
    }
    foreach ([
        [null, [], 'clients', 401],
        ['Bearer synthetic-token', [], 'timesheets', 403],
        ['Bearer synthetic-token', ['platform-admin'], 'unknown', 422],
        ['Bearer synthetic-token', ['platform-admin'], 'clients', 409],
        ['Bearer synthetic-token', ['platform-admin'], 'timesheets', 409],
    ] as [$authorization, $roles, $scope, $expected]) {
        $request = \Illuminate\Http\Request::create('/api/migration/console/snapshot', 'POST', ['scope'=>$scope]);
        $request->headers->set('Accept','application/json');
        if ($authorization) $request->headers->set('Authorization',$authorization);
        $response=$kernel->handle($request);
        check($response->getStatusCode()===$expected, 'Manual checkpoint route authorization/scope/queue guard');
        $kernel->terminate($request,$response);
    }
    // Operational API stays up without any SQLite access during restore.
    $restoreState=$path.'.restore-state';$restoreLock=$path.'.restore-lock';
    config(['migration.restore_status_path'=>$restoreState,'migration.restore_lock_path'=>$restoreLock]);
    file_put_contents($restoreState,json_encode(['state'=>'running','stage'=>'database']));
    $sqlCount=0;\Illuminate\Support\Facades\DB::listen(function () use (&$sqlCount) { $sqlCount++; });
    foreach (['/api/migration/health'=>200,'/api/migration/state'=>503,'/api/migration/console/start'=>503] as $url=>$expected) {
        $before=$sqlCount;$request=\Illuminate\Http\Request::create($url,str_ends_with($url,'start')?'POST':'GET');
        $response=$kernel->handle($request);check($response->getStatusCode()===$expected,'Maintenance route status');
        check($sqlCount===$before,'Maintenance must not access replaced SQLite metadata');$kernel->terminate($request,$response);
    }
    $request=\Illuminate\Http\Request::create('/api/migration/console/state','GET');
    $response=$kernel->handle($request);check($response->getStatusCode()===401,'Operational console keeps authorization');$kernel->terminate($request,$response);
    file_put_contents($restoreState,json_encode(['state'=>'failed','database_outcome'=>'unknown']));
    $response=$kernel->handle(\Illuminate\Http\Request::create('/api/migration/state','GET'));
    check($response->getStatusCode()===503,'Unknown COMMIT outcome stays protected');
    unlink($restoreState);
    $hold=fopen($restoreLock,'c');flock($hold,LOCK_EX);
    $response=$kernel->handle(\Illuminate\Http\Request::create('/api/migration/state','GET'));
    check($response->getStatusCode()===503,'In-flight metadata drain never blocks the operational API');flock($hold,LOCK_UN);fclose($hold);@unlink($restoreLock);
    echo "Console SQLite integration passed.\n";
} catch (Throwable $e) {
    $failure = $e;
} finally {
    \App\Migration\Core\TableProgress::$activeRun = null;
    @unlink($path.'.restore-state'); @unlink($path.'.restore-lock');
    @unlink($path); @unlink($path.'-wal'); @unlink($path.'-shm');
}

if ($failure) {
    fwrite(STDERR, $failure->getMessage()."\n");
    exit(1);
}
