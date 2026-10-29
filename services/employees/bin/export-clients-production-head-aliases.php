<?php
declare(strict_types=1);

// Private local transport of organizational aliases, not employee records.
// Used only while deleting Clients' historical department-manager matrix.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$aliases = [];
foreach (\App\Models\Department::query()->where('is_production', true)
    ->get(['alias']) as $department) {
    $alias = (string) $department->alias;
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $alias) !== 1) {
        fwrite(STDERR, "A production department lacks a canonical alias; role conversion stopped.\n");
        exit(1);
    }
    $aliases[$alias] = true;
}
echo json_encode(['version'=>1,'production_aliases'=>array_keys($aliases)], JSON_THROW_ON_ERROR)."\n";
