<?php

// Read only the Clients-owned permission definitions for Timesheets.
// The caller must keep the output inside the protected deployment host.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = \App\Models\ClientContourPermission::query()
    ->where('permission','like','timesheets.%')
    ->get(['role','permission','allowed','scope','denied']);
$export = [];
foreach ($rows as $row) {
    if (str_contains((string) $row->role, ':')) continue;
    $export[] = [
        'role'=>(string) $row->role,
        'permission'=>(string) $row->permission,
        'allowed'=>(bool) $row->allowed,
        'scope'=>(string) $row->scope,
        'denied'=>(bool) $row->denied,
    ];
}
echo json_encode(['version'=>1,'rows'=>$export], JSON_THROW_ON_ERROR)."\n";
