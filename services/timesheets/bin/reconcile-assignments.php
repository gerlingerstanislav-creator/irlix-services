<?php

use App\Support\AssignmentReconciler;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

while (true) {
    try {
        $app->make(AssignmentReconciler::class)->run();
    } catch (Throwable $error) {
        fwrite(STDERR, "Timesheets reconciliation failed; retrying: {$error->getMessage()}\n");
    }
    if (in_array('--once', $argv, true)) break;
    sleep(max(30, (int) env('TIMESHEETS_RECONCILE_INTERVAL', 60)));
}
