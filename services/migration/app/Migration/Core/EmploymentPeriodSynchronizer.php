<?php

namespace App\Migration\Core;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

final class EmploymentPeriodSynchronizer
{
    public function __construct(private readonly MigrationStore $store) {}

    public function sync(Connection $target, object $source, int $employeeId, ?string $endDate, bool $latest): array
    {
        return $target->transaction(function () use ($target, $source, $employeeId, $endDate, $latest): array {
            // Serialize updates with business operations that lock the employee row.
            $target->table('employees')->where('id', $employeeId)->lockForUpdate()->first();
            $mappedId = $this->store->mapping('employees', 'employment', $source->id);
            $period = $mappedId ? $target->table('employment_periods')->where('id', (int) $mappedId)->first() : null;
            if ($period && (int) $period->employee_id !== $employeeId) {
                return ['error' => 'EMPLOYMENT_MAPPING_COLLISION'];
            }
            if (! $period) {
                $period = $target->table('employment_periods')->where('employee_id', $employeeId)
                    ->where('cooperation_type', $source->type)->whereDate('started_at', $source->start_date)
                    ->where(function ($query) use ($endDate): void {
                        $endDate === null ? $query->whereNull('ended_at') : $query->whereDate('ended_at', $endDate);
                    })->first();
            }
            $open = $target->table('employment_periods')->where('employee_id', $employeeId)->whereNull('ended_at')->orderBy('id')->get();
            if ($open->count() > 1) return ['error' => 'MULTIPLE_TARGET_OPEN_PERIODS'];
            if (! $period && ($endDate === null || $latest)) $period = $open->first();
            if ($period) {
                // One source interval must never overwrite a different imported interval.
                $claimed = DB::table('migration_mappings')->where('service', 'employees')->where('entity_type', 'employment')
                    ->where('target_id', (string) $period->id)->where('legacy_id', '<>', (string) $source->id)->exists();
                $desired = ['cooperation_type' => $source->type, 'started_at' => $source->start_date, 'ended_at' => $endDate];
                $before = ['cooperation_type' => $period->cooperation_type, 'started_at' => $period->started_at, 'ended_at' => $period->ended_at];
                if ($claimed && $before !== $desired) return ['error' => 'EMPLOYMENT_MAPPING_COLLISION'];
                if ($endDate === null && $open->first() && (int) $open->first()->id !== (int) $period->id) {
                    return ['error' => 'EMPLOYMENT_MAPPING_COLLISION'];
                }
                if ($before !== $desired) {
                    $target->table('employment_periods')->where('id', $period->id)->update($desired + ['updated_at' => now()]);
                }
                return ['id' => (int) $period->id, 'before' => $before, 'after' => $desired, 'updated' => $before !== $desired];
            }
            $id = $target->table('employment_periods')->insertGetId([
                'employee_id' => $employeeId, 'cooperation_type' => $source->type,
                'started_at' => $source->start_date, 'ended_at' => $endDate,
                'department_id' => null, 'position' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['id' => (int) $id, 'updated' => false];
        });
    }
}
