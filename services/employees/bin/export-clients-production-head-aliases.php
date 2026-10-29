<?php
declare(strict_types=1);

// Private local transport of current production head aliases only.
// No employee identifiers, department names or confidential metadata leave
// Employees. Never synthesize a new Keycloak role to make migration pass.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $departments = \App\Models\Department::query()->get([
        'id', 'parent_id', 'alias', 'manager_id', 'is_production',
    ])->map(static fn($d) => [
        'id'=>(int)$d->id,
        'parent_id'=>(int)$d->parent_id,
        'alias'=>$d->alias,
        'manager_id'=>(int)$d->manager_id,
        'is_production'=>(bool)$d->is_production,
    ])->all();

    $aliases = \App\Support\ClientsProductionHeadAliases::fromDepartments($departments);
    echo json_encode(['version'=>1,'production_aliases'=>$aliases], JSON_THROW_ON_ERROR)."\n";
} catch (\Throwable $error) {
    $reason = match ($error->getMessage()) {
        'Managed production department lacks canonical alias' => 'missing-managed-alias',
        'Duplicate managed production role alias' => 'duplicate-managed-alias',
        default => 'directory-unavailable',
    };
    fwrite(STDERR, "[clients-head-export] {$reason}\n");
    exit(1);
}
