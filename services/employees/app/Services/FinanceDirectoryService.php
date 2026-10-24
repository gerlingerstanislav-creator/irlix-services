<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\SalaryHistory;
use Carbon\Carbon;

final class FinanceDirectoryService
{
    public function payrollConditions(string $month): array
    {
        $first = Carbon::parse($month.'-01')->toDateString();
        $last = Carbon::parse($month.'-01')->endOfMonth()->toDateString();

        // Explicit columns are essential here: Finance must never receive
        // private employee fields or a full unfiltered Eloquent entity.
        $employees = Employee::query()->from('employees as e')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->select('e.id', 'e.full_name', 'e.department_id', 'd.name as department_name',
                'e.employment_status')->orderBy('e.id')->get();

        $salary = SalaryHistory::query()
            ->whereDate('effective_from', '<=', $last)
            ->where(fn ($query) => $query->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $first))
            ->select('employee_id', 'gross_salary', 'effective_from', 'effective_to', 'status')
            ->get();

        return [
            'month' => $month,
            'employees' => $employees,
            'salary_conditions' => $salary,
            'source' => 'Employees.salary_history',
            'note' => 'Это согласованные условия gross, не факт начисления или банковской выплаты',
        ];
    }

    public function exists(int $employeeId): bool
    {
        return Employee::query()->whereKey($employeeId)->exists();
    }
}
