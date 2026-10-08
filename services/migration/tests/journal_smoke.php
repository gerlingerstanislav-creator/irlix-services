<?php
// Offline HTTP regression: old errors must be reachable behind newer warnings.
$path = '/tmp/migration-journal-'.getmypid().'.sqlite';
touch($path);
putenv('MIGRATION_METADATA_DATABASE='.$path);
putenv('DB_CONNECTION=sqlite');
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$failure = null;
try {
    $store = app(\App\Migration\Core\MigrationStore::class);
    $run = $store->beginRun('vacations', 'dry-run');
    for ($i=1; $i<=201; $i++) $store->conflict($run, 'vacations', 'users', 'synthetic-'.$i, 'SYNTHETIC_LOGIN_ERROR', 'Synthetic employee login unresolved');
    $store->conflict($run, 'vacations', 'vacations', 'synthetic-vacation', 'SYNTHETIC_REFERENCE_ERROR', 'Synthetic user reference failed');
    for ($i=1; $i<=401; $i++) $store->conflict($run, 'vacations', 'activity_log', 'synthetic-log-'.$i, 'SYNTHETIC_WARNING', 'Synthetic metadata warning', [], 'warning');
    $store->conflict($run, 'vacations', 'users', 'synthetic-warning', 'SYNTHETIC_WARNING', 'Synthetic user warning', [], 'warning');
    $store->finishRun($run, 'conflicts', []);
    $employeeRun = $store->beginRun('employees', 'dry-run');
    $store->conflict($employeeRun, 'employees', 'employee', 'synthetic-employee', 'SYNTHETIC_ERROR', 'Synthetic employee error');
    $store->finishRun($employeeRun, 'conflicts', []);
    $roles = ['platform-admin'];
    \Illuminate\Support\Facades\Http::fake(function () use (&$roles) { return \Illuminate\Support\Facades\Http::response(['data' => ['roles' => $roles]], 200); });
    $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    $get = function (string $url, int $status=200, bool $authorized=true) use ($kernel): array {
        $request=\Illuminate\Http\Request::create('/api/migration/console/runs/'.$url, 'GET');
        $request->headers->set('Accept','application/json');
        if ($authorized) $request->headers->set('Authorization','Bearer synthetic-token');
        $response=$kernel->handle($request);
        check($response->getStatusCode()===$status, $url.' expected '.$status.' got '.$response->getStatusCode().': '.substr($response->getContent(),0,250));
        $kernel->terminate($request,$response);
        return json_decode($response->getContent(),true)['data'] ?? [];
    };
    $store->event($run, 'console', 'Synthetic operation', 'info', ['operation_id'=>'8008']);
    $get($run.'?operation_id=8008');
    $get($run.'?operation_id=8009',404);
    $get($run.'/conflicts?operation_id=8009&severity=error',404);
    $get($run.'?operation_id[]=8008',422);
    $initial=$get((string) $run);
    check($initial['warning_summary'][0]['count']===401, 'Warnings grouped before pagination');
    check($initial['conflict_summary'][0]['count']===201, 'Failure summary includes older errors before pagination');
    check(count($initial['conflicts'])===200 && $initial['conflicts'][0]['severity']==='warning', 'Unfiltered initial page contains newer warnings');
    $first=$get($run.'/conflicts?severity=error&table=users');
    check(count($first['items'])===200 && $first['total']===201 && $first['more'], 'Filter applied before page limit');
    foreach ($first['items'] as $item) check($item['entity_type']==='users' && $item['severity']==='error', 'Table and severity isolate matching conflicts');
    $next=$get($run.'/conflicts?severity=error&table=users&before='.$first['cursor']);
    check(count($next['items'])===1 && !$next['more'] && $next['total']===201, 'Filtered cursor reaches older matching error');
    check(count(array_unique(array_column([...$first['items'],...$next['items']],'id')))===201, 'No overlap or loss between pages');
    $empty=$get($run.'/conflicts?severity=error&table=departments');
    check($empty['items']===[] && $empty['total']===0 && !$empty['more'], 'True empty result');
    $warnings=$get($run.'/conflicts?severity=warning&table=users');
    check($warnings['total']===1 && count($warnings['items'])===1, 'Warning query has an independent cursor');
    $alias=$get($employeeRun.'/conflicts?severity=error&table=employees');
    check($alias['total']===1 && $alias['items'][0]['entity_type']==='employee', 'Displayed table alias resolves stored entity');
    $old=$get($run.'/conflicts?before='.$initial['conflict_cursor']);
    check(count($old['items'])===200, 'Existing unfiltered cursor remains compatible');
    foreach (['before=0','before=bad','before[]=1','severity=info','table=users%27','table[]=users'] as $invalid) $get($run.'/conflicts?'.$invalid,422);
    $get('999999/conflicts?severity=error',404);
    $get($run.'/conflicts?severity=error',401,false);
    $roles = [];
    $get($run.'/conflicts?severity=error',403);
    echo "Migration filtered journal HTTP smoke passed\n";
} catch (Throwable $e) { $failure=$e; }
finally { @unlink($path); @unlink($path.'-wal'); @unlink($path.'-shm'); }
if ($failure) { fwrite(STDERR,$failure->getMessage()."\n"); exit(1); }
