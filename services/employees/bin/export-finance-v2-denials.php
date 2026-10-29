<?php
declare(strict_types=1);

// One-time protected AuthZ V2 migration: export ONLY role-level Finance
// explicit denials, never employee records or business/financial data.
// Stdout is consumed privately by the Finance owner, never by CI logs.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$permissions = [
    'finance.view', 'finance.salary.view', 'finance.invoices.manage',
    'finance.cash.confirm', 'finance.expenses.manage',
    'finance.accounts.manage', 'finance.planning.manage',
    'finance.planning.approve',
];
$rows = \App\Models\EmployeePermissionRule::query()
    ->whereIn('permission', $permissions)->where('enabled', false)
    ->get(['role','permission']);
$out = [];
foreach ($rows as $row) {
    $out[] = [
        'role' => (string) $row->role,
        'permission' => (string) $row->permission,
    ];
}
echo json_encode(['version'=>1,'denials'=>$out], JSON_THROW_ON_ERROR)."\n";
