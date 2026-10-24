<?php

declare(strict_types=1);

use App\Support\KeycloakAuthorizationSync;
use Illuminate\Contracts\Console\Kernel;
use App\Services\AuthorizationSyncQueueService;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$once = in_array('--once', $argv, true);
$idle = max(1, (int) env('AUTH_SYNC_IDLE_SECONDS', 3));
$maxAttempts = max(1, (int) env('AUTH_SYNC_MAX_ATTEMPTS', 20));
// Never mutate the shared Keycloak realm simply because the worker image has
// been deployed. Operators must explicitly activate writes after checking
// employee identity mappings and a staged dry-run/backfill.
if (!filter_var(env('IRLIX_AUTH_SYNC_WRITE_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "Keycloak role synchronization is staged, not writing (IRLIX_AUTH_SYNC_WRITE_ENABLED=false)\n");
    if ($once) exit(0);
    while (true) sleep(60);
}
$worker = new KeycloakAuthorizationSync();
$queue = app(AuthorizationSyncQueueService::class);
echo "Employees Keycloak authorization synchronizer started\n";
do {
    try {
        // Recovery after a container restart: an interrupted claim must not
        // remain stuck in processing forever.
        $queue->recoverStale();
        $items = $queue->ready();
        foreach ($items as $item) {
            // A concurrent mutation bumps version and resets status to pending.
            $claimed = $queue->claim($item);
            if (!$claimed) continue;
            try {
                $worker->reconcile((int) $item->employee_id);
                $queue->markSynced($item);
            } catch (Throwable $e) {
                $queue->markFailure($item, $e, $maxAttempts);
                fwrite(STDERR, "Authorization sync failed for employee ID {$item->employee_id}: ".get_class($e)."\n");
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Authorization sync loop failed: ".get_class($e)."\n");
    }
    if (!$once) sleep($idle);
} while (!$once);
