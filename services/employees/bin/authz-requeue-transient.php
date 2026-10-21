<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// After Keycloak readiness is proven, requeue ONLY deferred cURL 7
// connectivity failures. Never reset attempts, clear errors, alter
// identity/role grants, or touch errors from any other category.
try {
    $rows = DB::table('keycloak_auth_sync')
        ->where('status', 'retry')
        ->where('last_error', 'like', '%cURL error 7:%')
        ->where('available_at', '>', now())
        ->update(['available_at' => now(), 'updated_at' => now()]);
    echo "Requeued transient connectivity retries: {$rows}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Requeue of transient authorization retries failed\n");
    exit(3);
}
