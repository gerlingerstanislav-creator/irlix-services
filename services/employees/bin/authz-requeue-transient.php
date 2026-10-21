<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Once after Keycloak readiness, requeue deferred cURL 7 errors and the
// bounded HTTP 400 role-mapping failures addressed by this release.
// Never reset attempts, clear errors, change the status, grants or identities.
try {
    $rows = DB::table('keycloak_auth_sync')
        ->where('status', 'retry')
        ->where(static function ($query): void {
            $query->where('last_error', 'like', '%cURL error 7:%')
                ->orWhere('last_error', 'like', 'Keycloak authorization sync failed with HTTP 400%');
        })
        ->where('available_at', '>', now())
        ->update(['available_at' => now(), 'updated_at' => now()]);
    echo "Requeued deferred Keycloak retries after readiness: {$rows}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Requeue of transient authorization retries failed\n");
    exit(3);
}
