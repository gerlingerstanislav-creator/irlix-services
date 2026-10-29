<?php
declare(strict_types=1);

// Called only AFTER Clients legacy Timesheets rights were sent to Timesheets.
// No employee IDs, personal or commercial records are transferred.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $input = json_decode((string)stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (($input['version'] ?? null) !== 1 || !is_array($input['production_aliases'] ?? null)) {
        throw new RuntimeException('Invalid production department metadata');
    }
    app(\App\Services\FinalizeLegacyClientPermissions::class)->run($input['production_aliases']);
    echo "Clients V2 legacy matrix finalized; only canonical business roles remain.\n";
} catch (\Throwable) {
    fwrite(STDERR,"Clients V2 finalization stopped; no legacy roles removed on error.\n");
    exit(1);
}
