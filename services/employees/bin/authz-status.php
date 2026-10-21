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
    // Only grouped allowlisted diagnostics: NEVER expose usernames, employee
    // identifiers, Keycloak payloads or secrets in Actions production logs.
    $errorKinds = [
        'admin_login_unavailable' => 0, 'bootstrap_admin_mismatch' => 0,
        'keycloak_user_missing' => 0, 'http_401' => 0, 'http_403' => 0,
        'http_404' => 0, 'http_409' => 0, 'http_500' => 0,
        'other_keycloak_http' => 0, 'unknown' => 0,
    ];
    foreach (DB::table('keycloak_auth_sync')->whereIn('status', ['retry', 'failed'])
                 ->whereNotNull('last_error')->pluck('last_error') as $message) {
        $message = (string) $message;
        $kind = match (true) {
            str_contains($message, 'administrator login unavailable') => 'admin_login_unavailable',
            str_contains($message, 'Unmapped bootstrap platform administrator') => 'bootstrap_admin_mismatch',
            str_contains($message, 'employee identity not found') => 'keycloak_user_missing',
            preg_match('/HTTP 401\\b/', $message) === 1 => 'http_401',
            preg_match('/HTTP 403\\b/', $message) === 1 => 'http_403',
            preg_match('/HTTP 404\\b/', $message) === 1 => 'http_404',
            preg_match('/HTTP 409\\b/', $message) === 1 => 'http_409',
            preg_match('/HTTP 500\\b/', $message) === 1 => 'http_500',
            str_contains($message, 'Keycloak authorization sync failed with HTTP') => 'other_keycloak_http',
            default => 'unknown',
        };
        $errorKinds[$kind]++;
    }
    $errorKinds = array_filter($errorKinds, static fn (int $total): bool => $total > 0);
    echo json_encode([...compact('ready','active','identities','admins',
        'pending','processing','retry','failed','synced'), 'errors' => $errorKinds],
        JSON_THROW_ON_ERROR)."\n";
    exit($ready ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, "Keycloak authorization status cannot be read: ".get_class($e)."\n");
    exit(3);
}
