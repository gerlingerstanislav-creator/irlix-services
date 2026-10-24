<?php

namespace App\Services;

use App\Models\EmployeeStatusHistory;
use App\Models\EmploymentAssignmentHistory;

final class EmployeeHistoryService
{
    public function closeStatus(int $employee, string $end): void
    {
        EmployeeStatusHistory::query()->where('employee_id', $employee)
            ->whereNull('effective_to')->update(['effective_to' => $end]);
    }

    public function openStatus(int $employee, string $status, string $from, ?string $reason = null): void
    {
        EmployeeStatusHistory::query()->create([
            'employee_id' => $employee, 'status' => $status,
            'effective_from' => $from, 'effective_to' => null, 'reason' => $reason,
        ]);
    }

    public function closeAssignment(int $employee, string $end): void
    {
        EmploymentAssignmentHistory::query()->where('employee_id', $employee)
            ->whereNull('effective_to')->update(['effective_to' => $end]);
    }

    public function openAssignment(int $employee, ?int $department, ?string $position, string $from): void
    {
        EmploymentAssignmentHistory::query()->create([
            'employee_id' => $employee, 'department_id' => $department,
            'position' => $position, 'effective_from' => $from, 'effective_to' => null,
        ]);
    }
}
