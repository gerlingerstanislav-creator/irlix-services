<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $payload = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, "Staff positions seed: invalid JSON: {$e->getMessage()}\n");
    exit(1);
}

if (!is_array($payload) || $payload === []) {
    fwrite(STDERR, "Staff positions seed: expected a non-empty JSON array\n");
    exit(1);
}

$rows = [];
foreach ($payload as $index => $row) {
    if (!is_array($row)) {
        fwrite(STDERR, "Staff positions seed: row {$index} must be an object\n");
        exit(1);
    }

    $department = trim((string) ($row['department'] ?? ''));
    $name = trim((string) ($row['name'] ?? ''));
    $salary = $row['base_salary'] ?? null;

    if ($department === '' || $name === '') {
        fwrite(STDERR, "Staff positions seed: row {$index} requires department and name\n");
        exit(1);
    }
    if ($salary !== null && (!is_numeric($salary) || (float) $salary < 0)) {
        fwrite(STDERR, "Staff positions seed: row {$index} has invalid base_salary\n");
        exit(1);
    }

    $rows[] = [
        'department' => $department,
        'name' => $name,
        'base_salary' => $salary === null ? null : (float) $salary,
    ];
}

$created = 0;
$updated = 0;
DB::transaction(function () use ($rows, &$created, &$updated): void {

    foreach ($rows as $row) {
        $departmentId = DB::table('departments')->where('name', $row['department'])->value('id');
        if (!$departmentId) {
            throw new RuntimeException("Unknown department: {$row['department']}");
        }

        $position = DB::table('staff_positions')
            ->where('direction_id', $departmentId)
            ->where('name', $row['name'])
            ->first();

        if ($position) {
            DB::table('staff_positions')->where('id', $position->id)->update([
                'base_salary' => $row['base_salary'],
                'updated_at' => now(),
            ]);
            $updated++;
            continue;
        }

        DB::table('staff_positions')->insert([
            'name' => $row['name'],
            'direction_id' => $departmentId,
            'base_salary' => $row['base_salary'],
            'closed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $created++;
    }
});

fwrite(STDOUT, "Staff positions seed applied: created={$created}, updated={$updated}\n");
