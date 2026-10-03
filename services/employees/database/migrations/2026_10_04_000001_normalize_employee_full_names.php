<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('employees')
            ->select(['id', 'last_name', 'first_name', 'middle_name'])
            ->orderBy('id')
            ->chunkById(200, function ($employees): void {
                foreach ($employees as $employee) {
                    $parts = array_values(array_filter([
                        trim((string) ($employee->last_name ?? '')),
                        trim((string) ($employee->first_name ?? '')),
                        trim((string) ($employee->middle_name ?? '')),
                    ], fn (string $part): bool => $part !== ''));

                    if ($parts === []) {
                        continue;
                    }

                    DB::table('employees')
                        ->where('id', $employee->id)
                        ->update([
                            'full_name' => implode(' ', $parts),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Data normalization is intentionally irreversible: the previous
        // denormalized value may have been stale and must not be restored.
    }
};
