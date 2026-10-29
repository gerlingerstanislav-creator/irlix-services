<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmploymentAssignmentHistory;
use App\Models\EmploymentPeriod;
use App\Models\SalaryHistory;
use App\Models\StaffPosition;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Correct an existing assignment without rewriting historical employment or salary events.
 * Changes to an active assignment update the employee projection and identity membership.
 */
final class EmployeeAssignmentHistoryService
{
    public function __construct(private readonly EmployeeIdentityService $identity) {}

    public function update(Employee $employee, int $assignmentId, array $data): EmploymentAssignmentHistory
    {
        $syncDepartment = false;
        $updated = DB::transaction(function () use ($employee, $assignmentId, $data, &$syncDepartment): EmploymentAssignmentHistory {
            $record = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $rows = EmploymentAssignmentHistory::query()
                ->where('employee_id', $record->id)
                ->orderBy('effective_from')->orderBy('id')->lockForUpdate()->get();
            $index = $rows->search(static fn ($item): bool => (int) $item->id === $assignmentId);
            if ($index === false) throw new DomainException('Запись перевода не найдена у этого сотрудника.');
            $item = $rows[$index];
            $previous = $index > 0 ? $rows[$index - 1] : null;
            $next = $index + 1 < $rows->count() ? $rows[$index + 1] : null;

            $from = $data['effective_from'];
            $to = $data['effective_to'] ?? null;
            $wasOpen = $item->effective_to === null;
            if ($wasOpen !== ($to === null)) {
                throw new DomainException('Нельзя менять статус открытого назначения через корректировку истории.');
            }
            if ($to !== null && $to < $from) {
                throw new DomainException('Дата окончания не может быть раньше даты начала.');
            }
            if ($next && ($to === null || $to >= $next->effective_from)) {
                throw new DomainException('Период пересекается со следующим назначением.');
            }

            $period = EmploymentPeriod::query()
                ->where('employee_id', $record->id)
                ->where('started_at', '<=', $item->effective_from)
                ->where(static fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $item->effective_from))
                ->orderByDesc('started_at')->first();
            if ($period && ($from < $period->started_at || ($period->ended_at && ($to === null || $to > $period->ended_at)))) {
                throw new DomainException('Даты назначения должны оставаться в пределах периода трудоустройства.');
            }

            $contiguous = $previous && $previous->effective_to === CarbonImmutable::parse($item->effective_from)->subDay()->toDateString();
            if ($previous) {
                if ($from <= $previous->effective_from) {
                    throw new DomainException('Дата начала должна быть позже предыдущего назначения.');
                }
                if (!$contiguous && ($previous->effective_to === null || $from <= $previous->effective_to)) {
                    throw new DomainException('Период пересекается с предыдущим назначением.');
                }
            }
            if ($from !== $item->effective_from && SalaryHistory::query()
                ->where('employee_id', $record->id)
                ->where('effective_from', $item->effective_from)
                ->where('comment', 'Изменение оклада при кадровом переводе')->exists()) {
                throw new DomainException('Дата перевода связана со сменой оклада. Сначала нужна согласованная корректировка зарплатной истории.');
            }

            $position = null;
            if ($data['position_id'] !== null) {
                $position = StaffPosition::query()->find((int) $data['position_id']);
                if (!$position || (string) $position->direction_id !== (string) $data['department_id']) {
                    throw new DomainException('Должность должна принадлежать выбранному направлению.');
                }
                if ($position->closed_at !== null && (int) $item->position_id !== (int) $position->id) {
                    throw new DomainException('Нельзя назначить закрытую должность.');
                }
            }

            if ($contiguous && $from !== $item->effective_from) {
                $previous->update(['effective_to' => CarbonImmutable::parse($from)->subDay()->toDateString()]);
            }
            $item->update([
                'department_id' => $data['department_id'],
                'position' => $position?->name,
                'effective_from' => $from,
                'effective_to' => $to,
            ]);
            // The legacy DB trigger derives position_id by name alone. Persist the
            // selected ID after the position update to preserve direction-scoped IDs.
            $item->update(['position_id' => $position?->id]);

            if ($wasOpen) {
                $syncDepartment = (string) ($record->department_id ?? '') !== (string) $data['department_id'];
                $record->update([
                    'department_id' => $data['department_id'],
                    'position' => $position?->name,
                    'position_id' => $position?->id,
                ]);
            }

            return $item->refresh();
        });

        if ($syncDepartment && $employee->keycloak_user_id) {
            try {
                $this->identity->syncDepartment((string) $employee->keycloak_user_id, (int) $data['department_id']);
            } catch (Throwable $error) {
                report($error);
                Employee::query()->whereKey($employee->id)->update(['identity_status' => 'sync_failed']);
            }
        }
        return $updated;
    }
}
