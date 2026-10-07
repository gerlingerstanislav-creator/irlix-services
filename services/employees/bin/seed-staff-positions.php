<?php

declare(strict_types=1);

use App\Support\StaffPositionImporter;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $payload = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $result = (new StaffPositionImporter())->import($payload);
    fwrite(STDOUT, "Staff positions seed applied: total={$result['total']}, created={$result['created']}, updated={$result['updated']}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Staff positions seed failed: {$e->getMessage()}\n");
    exit(1);
}
