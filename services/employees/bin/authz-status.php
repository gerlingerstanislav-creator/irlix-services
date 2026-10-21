<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $counts = DB::table('keycloak_auth_sync')
        ->select('status', DB::raw('count(*) as count'))->groupBy('status')->pluck('count', 'status');
    $active = (int) DB::table('employees')->where('employment_status','Трудоустроен')->count();
    $identities = (int) DB::table('employees')->where('employment_status','Трудоустроен')
        ->whereNotNull('keycloak_user_id')->count();
    $admins = (int) DB::table('employee_access_roles')->where('role','platform-admin')->count();
    $pending = (int) ($counts['pending'] ?? 0);
    $processing = (int) ($counts['processing'] ?? 0);
    $retry = (int) ($counts['retry'] ?? 0);
    $failed = (int) ($counts['failed'] ?? 0);
    $synced = (int) ($counts['synced'] ?? 0);
    $ready = $active > 0 && $identities > 0 && $admins > 0
        && $pending === 0 && $processing === 0 && $retry === 0 && $failed === 0
        && $synced >= $identities;
    echo json_encode(compact('ready','active','identities','admins',
        'pending','processing','retry','failed','synced'), JSON_THROW_ON_ERROR)."\n";
    exit($ready ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, "Keycloak authorization status cannot be read: ".get_class($e)."\n");
    exit(3);
}
