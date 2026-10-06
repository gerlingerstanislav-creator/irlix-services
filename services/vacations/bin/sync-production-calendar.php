<?php

declare(strict_types=1);

use App\Support\ProductionCalendar;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$calendar = $app->make(ProductionCalendar::class);
$futureYears = max(1, (int) env('PRODUCTION_CALENDAR_FUTURE_YEARS', 2));
$checkInterval = max(3600, (int) env('PRODUCTION_CALENDAR_WORKER_INTERVAL', 86400));

fwrite(STDOUT, "Vacations production calendar sync started\n");

while (true) {
    try {
        $calendar->syncHorizon((int) now()->year, $futureYears);
    } catch (Throwable $error) {
        fwrite(STDERR, "Production calendar sync failed: {$error->getMessage()}\n");
    }
    sleep($checkInterval);
}
