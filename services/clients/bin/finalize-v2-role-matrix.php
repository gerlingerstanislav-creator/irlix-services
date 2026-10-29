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
} catch (\Throwable $error) {
    // Static, audited error categories only: no client names, user IDs,
    // role labels, confidential source data, SQL or raw stack traces.
    $categories = [
        'Unrecognized active legacy Clients role',
        'Unrecognized active Clients permission',
        'Missing V2 role permission after import',
        'Unreconciled Clients policy difference',
        'No production head roles for existing manager grants',
        'Invalid production organization alias',
    ];
    $category = in_array($error->getMessage(), $categories, true)
        ? $error->getMessage() : 'Validation or runtime error';
    fwrite(STDERR, "Clients V2 finalization rejected: {$category}; source policy preserved.\n");
    exit(1);
}
