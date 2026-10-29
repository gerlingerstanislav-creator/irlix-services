<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmploymentAssignmentHistory;
use App\Models\SalaryHistory;
use App\Models\StaffPosition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class EmployeeTransferService
{
    public function __construct(private readonly EmployeeIdentityService $identity) {}

    public function currentAssignment(int $employee): ?EmploymentAssignmentHistory
    {
        return EmploymentAssignmentHistory::query()->where('employee_id', $employee)
            ->whereNull('effective_to')->first();
    }

    public function currentSalary(int $employee): ?SalaryHistory
    {
        return SalaryHistory::query()->where('employee_id', $employee)
            ->whereNull('effective_to')->first();
    }

    public function transfer(
        Employee $employee,
        array $data,
        ?StaffPosition $position,
        EmploymentAssignmentHistory $assignment,
        ?SalaryHistory $salary
    ): object {
        $changeSalary = array_key_exists('gross_salary', $data)
            && $data['gross_salary'] !== null;

        DB::transaction(static function () use (
            $employee, $data, $position, $assignment, $salary, $changeSalary
        ): void {
            $previous = CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString();
            $assignment->update(['effective_to' => $previous]);
            $newAssignment = EmploymentAssignmentHistory::query()->create([
                'employee_id' => $employee->id,
                'department_id' => $data['department_id'],
                'position' => $position?->name,
                'effective_from' => $data['effective_from'],
                'effective_to' => null,
            ]);
            // Legacy name-only trigger can resolve a same-name position from a wrong direction.
            $newAssignment->update(['position_id' => $position?->id]);
            Employee::query()->whereKey($employee->id)->update([
                'department_id' => $data['department_id'],
                'position_id' => $position?->id,
                'position' => $position?->name,
            ]);
            if ($changeSalary) {
                $salary->update(['effective_to' => $previous, 'status' => 'Завершена']);
                SalaryHistory::query()->create([
                    'employee_id' => $employee->id,
                    'effective_from' => $data['effective_from'],
                    'effective_to' => null,
                    'gross_salary' => $data['gross_salary'],
                    'bonus' => $salary->bonus,
                    'status' => 'Действует',
                    'comment' => 'Изменение оклада при кадровом переводе',
                ]);
            }
        });
        if ($employee->keycloak_user_id) {
            try {
                $this->identity->syncDepartment(
                    (string) $employee->keycloak_user_id,
                    (int) $data['department_id']
                );
            } catch (Throwable $e) {
                report($e);
                Employee::query()->whereKey($employee->id)->update([
                    'identity_status' => 'sync_failed',
                ]);
            }
        }
        return (object) Employee::query()->whereKey($employee->id)->first()->getRawOriginal();
    }
}
