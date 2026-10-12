<?php
declare(strict_types=1);
use App\Calendar\ProductionCalendar;
use Illuminate\Contracts\Console\Kernel;
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$calendar=$app->make(ProductionCalendar::class);
while (true) {
    try { $calendar->syncHorizon((int)now()->year, max(2,(int)env('PRODUCTION_CALENDAR_FUTURE_YEARS',2))); }
    catch (\Throwable $error) { fwrite(STDERR, "Production calendar sync failed: ".$error->getMessage()."\n"); }
    sleep(max(3600,(int)env('PRODUCTION_CALENDAR_WORKER_INTERVAL',86400)));
}
