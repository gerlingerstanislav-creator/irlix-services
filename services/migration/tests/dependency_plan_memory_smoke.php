<?php
/** Synthetic dependency fingerprint regression: no legacy data or network. */
$path = sys_get_temp_dir().'/timesheets-plan-'.bin2hex(random_bytes(8)).'.sqlite';
touch($path);
putenv('MIGRATION_METADATA_DATABASE='.$path);
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$path);
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Migration\Core\MigrationStore;
use App\Migration\Services\TimesheetsMigration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function planHash(MigrationStore $store): string
{
    $module = new TimesheetsMigration($store);
    $reflection = new ReflectionClass($module);
    $reflection->getMethod('guardDependencyPlan')->invoke($module);
    $hash = $reflection->getProperty('dependencyPlanHash')->getValue($module);
    if (!is_string($hash) || strlen($hash) !== 64) {
        throw new RuntimeException('Missing dependency-plan fingerprint');
    }
    return $hash;
}

try {
    Artisan::call('migrate', ['--force' => true]);
    $now = '2026-01-01 00:00:00';
    DB::table('migration_mappings')->insert([
        'service' => 'timesheets', 'entity_type' => 'rates',
        'legacy_id' => 'relevant-rate', 'target_id' => '10',
        'metadata' => json_encode(['resolution' => ['version' => 3, 'anchor_id' => 10]]),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $store = app(MigrationStore::class);
    $original = planHash($store);

    // The production registry can contain tens of thousands of large Clients
    // metadata-only rows and Timesheets facts, neither of which is part of
    // the dependency plan. An unfiltered ->get() exceeds a 128 MiB worker.
    $payload = json_encode(['source' => ['synthetic_padding' => str_repeat('X', 8192)]]);
    $rows = [];
    $noiseRows = 12000;
    DB::beginTransaction();
    try {
        for ($i = 0; $i < $noiseRows; ++$i) {
            $rows[] = [
                'service' => $i % 2 ? 'clients' : 'timesheets',
                'entity_type' => $i % 2 ? 'activity_log' : 'timesheets',
                'legacy_id' => (string) $i, 'target_id' => (string) ($i + 1000),
                'metadata' => $payload, 'created_at' => $now, 'updated_at' => $now,
            ];
            if (count($rows) === 100) {
                DB::table('migration_mappings')->insert($rows);
                $rows = [];
            }
        }
        DB::commit();
    } catch (Throwable $exception) {
        DB::rollBack();
        throw $exception;
    }
    if (planHash($store) !== $original) {
        throw new RuntimeException('Unrelated metadata changed the dependency plan');
    }
    DB::table('migration_mappings')->where('service', 'timesheets')
        ->where('entity_type', 'rates')->where('legacy_id', 'relevant-rate')
        ->update(['target_id' => '11']);
    if (planHash($store) === $original) {
        throw new RuntimeException('Relevant rate mapping did not change the plan');
    }
    $peakMb = memory_get_peak_usage(true) / 1048576;
    if ($peakMb > 96) {
        throw new RuntimeException('Dependency plan exceeded memory budget: '.round($peakMb).' MiB');
    }
    printf("Timesheets plan: %d unrelated mappings ignored, fingerprint stable, peak %.1f MiB\n",
        $noiseRows, $peakMb);
} finally {
    DB::disconnect();
    foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
        if (is_file($file)) unlink($file);
    }
}
