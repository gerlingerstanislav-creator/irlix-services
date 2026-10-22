<?php
// Offline synthetic 61k-event reporting workload; no legacy/production access.
$database = '/tmp/migration-large-report-'.getmypid().'.sqlite';
touch($database);
putenv('MIGRATION_METADATA_DATABASE='.$database);
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true]);
try {
    $store = app(\App\Migration\Core\MigrationStore::class);
    $run = $store->beginRun('timesheets','dry-run');
    $store->event($run,'console','Synthetic report identity','info',['operation_id'=>'12345']);
    $store->finishRun($run,'completed',['inventory'=>['tables'=>[]]]);
    $now = now();
    for ($start=1; $start<=61229; $start+=100) {
        $items = [];
        for ($i=$start; $i<=min($start+99,61229); $i++) {
            $items[] = [
                'migration_run_id'=>$run,'service'=>'timesheets','entity_type'=>'timesheets',
                'legacy_id'=>(string)$i,'severity'=>'warning','code'=>'LEGACY_METADATA_ONLY',
                'message'=>'Synthetic metadata-only confirmation','context'=>'{}',
                'resolved'=>false,'created_at'=>$now,'updated_at'=>$now,
            ];
        }
        \Illuminate\Support\Facades\DB::table('migration_conflicts')->insert($items);
    }
    $report = app(\App\Migration\Core\ControlReport::class)->export($run,'12345',256);
    if (($report['diagnostics']['occurrences'] ?? null)!==61229
        || ($report['diagnostics']['affected_rows'] ?? null)!==61229
        || count($report['diagnostics']['items'] ?? [])!==256
        || ($report['diagnostics']['items_complete'] ?? true)!==false) {
        throw new RuntimeException('Large synthetic report lost source counts or bounded details.');
    }
    if (memory_get_peak_usage(true) >= 160*1024*1024) {
        throw new RuntimeException('Large synthetic report exceeded configured PHP memory budget.');
    }
    echo "Large synthetic report passed: 61229 occurrences, complete counts, 256 inline rows, bounded PHP memory.\n";
} finally {
    \Illuminate\Support\Facades\DB::disconnect();
    @unlink($database);@unlink($database.'-wal');@unlink($database.'-shm');
}
