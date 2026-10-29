<?php

// Import only Clients' existing Timesheets permission rows. This executable
// accepts private role/permission metadata over stdin, never IDs or secrets.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$source = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($source['version'] ?? null) !== 1 || !is_array($source['rows'] ?? null)) {
    throw new RuntimeException('Invalid Clients policy input');
}

$roleMap = [
    'employee'=>'org:irlix',
    'client-service'=>'org:client-service',
    'account-manager'=>'org:account-management',
    'accounting-head'=>'head:account-management',
    'client-service-head'=>'head:client-service',
    'sales-manager'=>'org:sales',
    'sales-head'=>'head:sales',
    'department-manager'=>'head:*',
    'personnel-officer'=>'special:personnel-officer',
    'system-admin'=>'special:system-admin',
];

$recognized = array_fill_keys(\App\Services\TimesheetsRoleAuthorization::PERMISSIONS, true);
$allowedScopes = ['own'=>true,'team'=>true,'all'=>true];
$rows = [];
foreach ($source['rows'] as $record) {
    if (!is_array($record)) throw new RuntimeException('Invalid policy record');
    $role = $roleMap[$record['role'] ?? ''] ?? null;
    $permission = $record['permission'] ?? null;
    if (!is_string($permission) || !isset($recognized[$permission])) {
        throw new RuntimeException('Unsupported Timesheets permission');
    }
    if ($role === null || !is_bool($record['allowed'] ?? null)
        || !is_bool($record['denied'] ?? null)) {
        throw new RuntimeException('Invalid legacy role or grant');
    }
    $scope = $record['scope'] ?? null;
    if (!is_string($scope) || !in_array($scope, ['none','own','team','all'], true)) {
        throw new RuntimeException('Invalid scope');
    }
    // Historical allowed=false denotes unassigned, not an explicit veto.
    if (!$record['allowed'] && !$record['denied']) continue;
    $denied = $record['denied'] === true;
    if (!$denied && !isset($allowedScopes[$scope])) {
        throw new RuntimeException('Allowed permission has no valid scope');
    }
    // Partner hours historically reuse Clients' management.view/manage,
    // including final approval. Preserve the exact old scope and denies.
    $targets = [$permission];
    if ($permission === 'timesheets.management.view') {
        $targets[] = 'timesheets.partner-hours.view';
    } elseif ($permission === 'timesheets.management.manage') {
        $targets[] = 'timesheets.partner-hours.manage';
        $targets[] = 'timesheets.partner-hours.approve';
    }
    foreach ($targets as $target) {
        $key = $role.'|'.$target;
        if (isset($rows[$key])) throw new RuntimeException('Ambiguous permission mapping');
        $rows[$key] = [
            'role'=>$role,'permission'=>$target,
            'allowed'=>!$denied && $record['allowed'],
            'scope'=>$denied ? 'none' : $scope,
        ];
    }
}

\Illuminate\Support\Facades\DB::transaction(static function () use ($rows): void {
    foreach ($rows as $record) {
        \Illuminate\Support\Facades\DB::table('timesheets_role_permissions')->insertOrIgnore([
            ...$record, 'created_at'=>now(), 'updated_at'=>now(),
        ]);
    }
});
fwrite(STDERR, "Timesheets legacy policy import completed (no role records logged).\n");
