<?php

declare(strict_types=1);

use App\Services\SystemAccountBootstrapService;
use App\Services\SystemAccountService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$verifyOnly = in_array('--verify', $argv, true);
if (!$verifyOnly) app(SystemAccountBootstrapService::class)->reconcile();

// The read projection is exactly what the admin-only HTTP controller returns.
// Fail deployment/smoke if the bootstrap account is still absent.
$accounts = app(SystemAccountService::class)->list();
$bootstrap = collect($accounts)->firstWhere('login', 'admin');
if (!$bootstrap || !$bootstrap['protected']
    || !in_array('platform-admin', $bootstrap['roles'], true)) {
    fwrite(STDERR, "System account bootstrap integrity: FAILED\n");
    exit(1);
}
echo "System account bootstrap integrity: PASS\n";
