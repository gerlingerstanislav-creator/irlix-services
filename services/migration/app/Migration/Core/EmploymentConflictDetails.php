<?php

namespace App\Migration\Core;

use Illuminate\Support\Facades\DB;

final class EmploymentConflictDetails
{
    public function __construct(private readonly MigrationStore $store) {}

    public function capture(object $period, ?object $employee): array
    {
        $targetId = $this->store->mapping('employees', 'employee', $period->employee_id);
        $open = $targetId ? DB::connection('target_employees')->table('employment_periods')
            ->where('employee_id', (int) $targetId)->whereNull('ended_at')
            ->orderBy('id')->get(['id', 'cooperation_type', 'started_at', 'ended_at'])->map(fn ($row) => (array) $row)->all() : [];

        return [
            'employee' => [
                'legacy_id' => (string) $period->employee_id,
                'target_id' => $targetId,
                'full_name' => $employee ? trim(implode(' ', array_filter([$employee->surname ?? null, $employee->name ?? null, $employee->patronymic ?? null]))) : null,
                'login' => $employee->username ?? null,
            ],
            'source_period' => ['id' => $period->id, 'cooperation_type' => $period->type, 'started_at' => $period->start_date, 'ended_at' => $period->end_date],
            'effective_end_date' => $period->end_date !== null && (str_starts_with($period->end_date, '0001-01-01') || str_starts_with($period->end_date, '0000-')) ? null : $period->end_date,
            'target_open_periods' => $open,
            'observed_at' => now()->toIso8601String(),
        ];
    }

    public function current(string $legacyId): ?array
    {
        $reader = new LegacyReader('employees');
        $reader->assertSafe();
        $period = $reader->selectOne('SELECT id, employee_id::text AS employee_id, type, start_date, end_date FROM public.employments WHERE id = ?', [$legacyId]);
        if (! $period) return null;
        $employee = $reader->selectOne('SELECT id::text AS id, username, surname, name, patronymic FROM public.employees WHERE id::text = ?', [$period->employee_id]);
        return $this->capture($period, $employee);
    }
}
