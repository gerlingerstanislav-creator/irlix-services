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
try {
    $store = app(\App\Migration\Core\MigrationStore::class);
    $run = $store->beginRun('employees', 'migrate');
    \App\Migration\Core\TableProgress::$activeRun = $run;
    \App\Migration\Core\TableProgress::extracted('SELECT count(*)::bigint AS count FROM public.employees', [(object) ['count' => 3]]);
    $store->saveMapping($run, 'employees', 'employee', 'synthetic-1', 1);
    $store->conflict($run, 'employees', 'employee', 'synthetic-1', 'SYNTHETIC_WARNING', 'Synthetic warning', [], 'warning');
    $store->conflict($run, 'employees', 'employee', 'synthetic-2', 'SYNTHETIC_ERROR', 'Synthetic error');
    \App\Migration\Core\TableProgress::extracted('SELECT id FROM public.users', [(object) ['id' => 1]]);
    $store->finishRun($run, 'conflicts', []);
    $data = $store->run($run);
    $table = collect($data['tables'])->firstWhere('table_name', 'employees');
    check((int) $table['success_count'] === 1, 'Exact success counter');
    check((int) $table['error_count'] === 1 && (int) $table['warning_count'] === 1, 'Separate error/warning counters');
    check((int) $table['total'] === 3, 'Source volume');
    check($table['state'] === 'conflicts', 'Table conflict state');
    $read = collect($data['tables'])->firstWhere('table_name', 'users');
    check($read['state'] === 'read_only' && (int) $read['success_count'] === 0, 'Reading is not import success');
    check($data['processed_count'] === 2 && $data['success_count'] === 1, 'Warning does not count as another processed row');
    $queued = $store->queueRun('vacations', 'inspect');
    try { $store->queueRun('vacations', 'inspect'); throw new LogicException('Duplicate accepted'); }
    catch (RuntimeException $e) { check($e->getCode() === 409, 'Duplicate blocked'); }
    echo "Console SQLite integration passed.\n";
} finally {
    \App\Migration\Core\TableProgress::$activeRun = null;
    @unlink($path); @unlink($path.'-wal'); @unlink($path.'-shm');
}
