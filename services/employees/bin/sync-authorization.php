<?php

declare(strict_types=1);

use App\Support\KeycloakAuthorizationSync;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$once = in_array('--once', $argv, true);
$idle = max(1, (int) env('AUTH_SYNC_IDLE_SECONDS', 3));
$maxAttempts = max(1, (int) env('AUTH_SYNC_MAX_ATTEMPTS', 20));
$worker = new KeycloakAuthorizationSync();
echo "Employees Keycloak authorization synchronizer started\n";
do {
    try {
        $items = DB::table('keycloak_auth_sync')->whereIn('status', ['pending', 'retry'])
            ->where('available_at', '<=', now())->orderBy('available_at')->limit(25)->get();
        foreach ($items as $item) {
            // A concurrent mutation bumps version and resets status to pending.
            $claimed = DB::table('keycloak_auth_sync')->where('employee_id', $item->employee_id)
                ->where('version', $item->version)->whereIn('status', ['pending','retry'])
                ->update(['status'=>'processing','updated_at'=>now()]);
            if (!$claimed) continue;
            try {
                $worker->reconcile((int) $item->employee_id);
                DB::table('keycloak_auth_sync')->where('employee_id', $item->employee_id)
                    ->where('version', $item->version)->where('status', 'processing')
                    ->update(['status'=>'synced','synced_at'=>now(),'attempts'=>0,'last_error'=>null,'updated_at'=>now()]);
            } catch (Throwable $e) {
                $attempts = (int) $item->attempts + 1;
                $delay = min(3600, 5 * (2 ** min($attempts, 9)));
                DB::table('keycloak_auth_sync')->where('employee_id', $item->employee_id)
                    ->where('version', $item->version)->where('status', 'processing')
                    ->update(['status'=>$attempts >= $maxAttempts ? 'failed' : 'retry',
                        'attempts'=>$attempts,'available_at'=>now()->addSeconds($delay),
                        'last_error'=>mb_substr($e->getMessage(),0,600),'updated_at'=>now()]);
                fwrite(STDERR, "Authorization sync failed for employee ID {$item->employee_id}: ".get_class($e)."\n");
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Authorization sync loop failed: ".get_class($e)."\n");
    }
    if (!$once) sleep($idle);
} while (!$once);
