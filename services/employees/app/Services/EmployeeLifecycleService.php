<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmploymentAssignmentHistory;
use App\Models\EmploymentPeriod;
use App\Models\SalaryHistory;
use App\Models\StaffPosition;
use App\Support\EmployeeOnboardingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Business operations on employee lifecycle and histories.
 * Input validation and HTTP responses remain in EmployeeController.
 * Identity side effects retain their original failure order.
 */
final class EmployeeLifecycleService
{
    public function __construct(
        private readonly EmployeeIdentityService $identity,
        private readonly EmployeeHistoryService $history,
        private readonly EmployeeReadService $read,
        private readonly EmployeeOnboardingService $onboarding
    ) {}

    public function application(array $validated): int
    {
        $data = $validated;
        $data['employment_status'] = 'Ожидает трудоустройства';
        $data['hired_at'] = null;
        $data['work_email'] = $data['login'].'@irlix.ru';
        $payload = $this->employeePayload($data);
        return DB::transaction(function () use ($payload, $data): int {
            $row = Employee::query()->create([
                ...$payload,
                'application_gross_salary' => $data['gross_salary'],
                'identity_status' => 'not_provisioned',
                'onboarding_email_status' => 'not_requested',
            ]);
            $this->history->openStatus((int) $row->id,
                'Ожидает трудоустройства', now()->toDateString(), 'Заявка на трудоустройство');
            return (int) $row->id;
        });
    }

    public function hire(Employee $employee, string $start, float|string $gross): void
    {
        $identity = $this->identity->provision($employee);
        DB::transaction(function () use ($employee, $start, $gross, $identity): void {
            $id = (int) $employee->id;
            $this->history->closeStatus($id, Carbon::parse($start)->subDay()->toDateString());
            $this->history->openStatus($id, 'Трудоустроен', $start, 'Первичное трудоустройство');
            $this->history->openAssignment($id, $employee->department_id
                ? (int) $employee->department_id : null, $employee->position, $start);
            EmploymentPeriod::query()->create([
                'employee_id' => $id, 'cooperation_type' => $employee->cooperation_type,
                'started_at' => $start, 'ended_at' => null,
                'department_id' => $employee->department_id, 'position' => $employee->position,
            ]);
            SalaryHistory::query()->create([
                'employee_id' => $id, 'effective_from' => $start,
                'gross_salary' => $gross, 'status' => 'Действует',
            ]);
            Employee::query()->whereKey($id)->update([
                'employment_status' => 'Трудоустроен', 'hired_at' => $start,
                'application_gross_salary' => null,
                'keycloak_user_id' => $identity['user_id'], 'identity_status' => 'active',
                'onboarding_email_status' => 'pending_template',
            ]);
        });
        try {
            $this->onboarding->sendPasswordSetup((int) $employee->id);
        } catch (Throwable $e) {
            report($e);
            $this->onboarding->markFailure((int) $employee->id, $e);
        }
    }

    public function update(Employee $employee, array $data): void
    {
        $identityChanges = array_intersect_key($data, array_flip(['login', 'first_name', 'last_name']));
        if ($identityChanges) $this->identity->update($employee, $identityChanges);

        DB::transaction(function () use ($employee, $data): void {
            $update = $data;
            if (array_key_exists('work_format', $update)) {
                $update['is_remote'] = $update['work_format'] === 'Удалённо';
            }
            if (array_key_exists('is_remote', $update)
                && !array_key_exists('work_format', $update)) {
                $update['work_format'] = $update['is_remote'] ? 'Удалённо' : 'Офис';
            }
            $nameChanged = array_key_exists('first_name', $update)
                || array_key_exists('last_name', $update)
                || array_key_exists('middle_name', $update);
            if ($nameChanged) {
                $last = array_key_exists('last_name', $update)
                    ? $update['last_name'] : $employee->last_name;
                $first = array_key_exists('first_name', $update)
                    ? $update['first_name'] : $employee->first_name;
                $middle = array_key_exists('middle_name', $update)
                    ? $update['middle_name'] : $employee->middle_name;
                $update['full_name'] = trim(implode(' ', array_filter([$last, $first, $middle])));
            }
            $assignmentChanged = (array_key_exists('department_id', $update)
                    && (string) ($update['department_id'] ?? '')
                        !== (string) ($employee->department_id ?? ''))
                || (array_key_exists('position', $update)
                    && (string) ($update['position'] ?? '')
                        !== (string) ($employee->position ?? ''));
            if ($assignmentChanged && $employee->employment_status === 'Трудоустроен') {
                $today = now()->toDateString();
                $end = Carbon::parse($today)->subDay()->toDateString();
                $this->history->closeAssignment((int) $employee->id, $end);
                $this->history->openAssignment((int) $employee->id,
                    array_key_exists('department_id', $update)
                        ? ($update['department_id'] ? (int) $update['department_id'] : null)
                        : $employee->department_id,
                    array_key_exists('position', $update) ? $update['position'] : $employee->position,
                    $today);
            }
            Employee::query()->whereKey($employee->id)->update($update);
        });

        if (array_key_exists('department_id', $data) && $employee->keycloak_user_id) {
            try {
                $this->identity->syncDepartment(
                    (string) $employee->keycloak_user_id,
                    $data['department_id'] ? (int) $data['department_id'] : null
                );
            } catch (Throwable $e) {
                report($e);
                Employee::query()->whereKey($employee->id)->update(['identity_status' => 'sync_failed']);
            }
        }
    }

    public function dismiss(Employee $employee, string $date): void
    {
        $this->identity->setEnabled($employee, false);
        DB::transaction(function () use ($employee, $date): void {
            $id = (int) $employee->id;
            EmploymentPeriod::query()->where('employee_id', $id)
                ->whereNull('ended_at')->update(['ended_at' => $date]);
            $previous = Carbon::parse($date)->subDay()->toDateString();
            $this->history->closeStatus($id, $previous);
            $this->history->openStatus($id, 'Уволен', $date, 'Увольнение');
            $this->history->closeAssignment($id, $date);
            Employee::query()->whereKey($id)->update([
                'employment_status' => 'Уволен', 'fired_at' => $date,
                'identity_status' => 'disabled',
            ]);
        });
    }

    public function rehire(Employee $employee, array $data): void
    {
        $this->identity->setEnabled($employee, true);
        if ($employee->keycloak_user_id) {
            $this->identity->syncDepartment((string) $employee->keycloak_user_id,
                (int) $data['department_id']);
        }
        DB::transaction(function () use ($employee, $data): void {
            $id = (int) $employee->id;
            EmploymentPeriod::query()->create([
                'employee_id' => $id, 'cooperation_type' => $data['cooperation_type'],
                'started_at' => $data['started_at'], 'ended_at' => null,
                'department_id' => $data['department_id'], 'position' => $data['position'] ?? null,
            ]);
            $previous = Carbon::parse($data['started_at'])->subDay()->toDateString();
            $this->history->closeStatus($id, $previous);
            $this->history->openStatus($id, 'Трудоустроен',
                $data['started_at'], 'Повторное трудоустройство');
            $this->history->openAssignment($id, (int) $data['department_id'],
                $data['position'] ?? null, $data['started_at']);
            Employee::query()->whereKey($id)->update([
                'employment_status' => 'Трудоустроен', 'cooperation_type' => $data['cooperation_type'],
                'department_id' => $data['department_id'], 'position' => $data['position'] ?? null,
                'hired_at' => $data['started_at'], 'fired_at' => null,
                'identity_status' => 'active', 'onboarding_email_status' => 'pending_template',
            ]);
        });
    }

    public function changeCooperation(Employee $employee, EmploymentPeriod $current, array $data): void
    {
        DB::transaction(function () use ($employee, $current, $data): void {
            $end = Carbon::parse($data['effective_from'])->subDay()->toDateString();
            $current->update(['ended_at' => $end]);
            EmploymentPeriod::query()->create([
                'employee_id' => $employee->id,
                'cooperation_type' => $data['cooperation_type'],
                'started_at' => $data['effective_from'], 'ended_at' => null,
                'department_id' => $employee->department_id,
                'position' => $employee->position,
            ]);
            Employee::query()->whereKey($employee->id)->update([
                'cooperation_type' => $data['cooperation_type'],
            ]);
        });
    }

    public function addSalary(Employee $employee, array $data, ?StaffPosition $position): int
    {
        $id = (int) $employee->id;
        $active = SalaryHistory::query()->where('employee_id', $id)->whereNull('effective_to')->first();
        $newPosition = $data['position_id'] ?? null;
        $positionChanged = (string) ($newPosition ?? '') !== (string) ($employee->position_id ?? '');
        $positionId = $newPosition;
        unset($data['position_id']);

        return DB::transaction(function () use (
            $id, $data, $active, $employee, $position,
            $positionChanged, $positionId
        ): int {
            if ($active) {
                $end = Carbon::parse($data['effective_from'])->subDay()->toDateString();
                $active->update(['effective_to' => $end, 'status' => 'Завершена']);
            }
            $salary = SalaryHistory::query()->create([
                ...$data, 'employee_id' => $id,
                'effective_to' => null, 'status' => 'Действует',
            ]);
            if ($positionChanged) {
                $end = Carbon::parse($data['effective_from'])->subDay()->toDateString();
                $this->history->closeAssignment($id, $end);
                $name = $position?->name;
                $this->history->openAssignment($id, $employee->department_id
                    ? (int) $employee->department_id : null, $name, $data['effective_from']);
                Employee::query()->whereKey($id)->update([
                    'position_id' => $positionId, 'position' => $name,
                ]);
            }
            return (int) $salary->id;
        });
    }

    public function deleteSalary(int $employee, SalaryHistory $salary): void
    {
        DB::transaction(static function () use ($employee, $salary): void {
            $wasActive = $salary->effective_to === null;
            $salary->delete();
            if ($wasActive) {
                $previous = SalaryHistory::query()->where('employee_id', $employee)
                    ->orderByDesc('effective_from')->first();
                if ($previous) $previous->update(['effective_to' => null, 'status' => 'Действует']);
            }
        });
    }

    public function employeePayload(array $data): array
    {
        $fullName = trim((string) ($data['full_name'] ?? ''));
        if ($fullName === '') $fullName = trim(implode(' ', array_filter([
            $data['last_name'] ?? null, $data['first_name'] ?? null, $data['middle_name'] ?? null,
        ])));
        return [
            'full_name' => $fullName, 'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null, 'middle_name' => $data['middle_name'] ?? null,
            'gender' => $data['gender'] ?? null, 'login' => $data['login'] ?? null,
            'work_email' => $data['work_email'] ?? null,
            'personal_email' => $data['personal_email'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'position' => $data['position'] ?? null,
            'specialization' => $data['specialization'] ?? null,
            'employment_status' => $data['employment_status'] ?? null,
            'work_format' => $data['work_format'] ?? null,
            'cooperation_type' => $data['cooperation_type'] ?? null,
            'hired_at' => $data['hired_at'] ?? null,
            'fired_at' => $data['fired_at'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'city' => $data['city'] ?? null,
            'phone' => $data['phone'] ?? null,
            'telegram' => $data['telegram'] ?? null,
            'skype' => $data['skype'] ?? null,
            'is_remote' => (bool) ($data['is_remote']
                ?? (($data['work_format'] ?? null) === 'Удалённо')),
        ];
    }
}
