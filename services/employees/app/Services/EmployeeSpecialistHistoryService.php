<?php

namespace App\Services;

use App\Models\EmployeeStatusHistory;
use App\Models\EmploymentAssignmentHistory;
use App\Models\EmploymentPeriod;

/** Minimal dated employment timeline for Specialists. Salaries are never read. */
final class EmployeeSpecialistHistoryService
{
    public function timeline(int $employee): array
    {
        $events = [];
        $at = static fn (?string $day): ?string => $day ? substr($day, 0, 10).'T12:00:00' : null;
        foreach (EmploymentPeriod::query()->where('employee_id', $employee)
            ->orderBy('started_at')->get() as $period) {
            if ($at($period->started_at)) $events[] = [
                'id' => 'employees-hire-'.$period->id,
                'type' => 'employment', 'title' => 'Трудоустройство',
                'text' => null, 'created_at' => $at($period->started_at),
            ];
            if ($at($period->ended_at)) $events[] = [
                'id' => 'employees-dismiss-'.$period->id,
                'type' => 'employment', 'title' => 'Увольнение',
                'text' => null, 'created_at' => $at($period->ended_at),
            ];
        }
        foreach (EmploymentAssignmentHistory::query()->from('employment_assignment_history as h')
            ->leftJoin('departments as d', 'd.id', '=', 'h.department_id')
            ->where('h.employee_id', $employee)
            ->select(['h.id', 'h.effective_from', 'h.position', 'd.name as department_name'])
            ->orderBy('h.effective_from')->get() as $assignment) {
            if (!$at($assignment->effective_from)) continue;
            $events[] = [
                'id' => 'employees-assignment-'.$assignment->id,
                'type' => 'assignment', 'title' => 'Назначение в подразделение',
                'text' => implode(' · ', array_filter([
                    $assignment->department_name, $assignment->position,
                ], static fn ($value) => is_string($value) && trim($value) !== '')),
                'created_at' => $at($assignment->effective_from),
            ];
        }
        foreach (EmployeeStatusHistory::query()->where('employee_id', $employee)
            ->orderBy('effective_from')->get() as $status) {
            if (!$at($status->effective_from)) continue;
            // Status history is distinct from employment periods (e.g. pending,
            // rehiring): do not infer dates or statuses from today's projection.
            $events[] = [
                'id' => 'employees-status-'.$status->id,
                'type' => 'status', 'title' => 'Кадровый статус',
                'text' => (string) $status->status,
                'created_at' => $at($status->effective_from),
            ];
        }
        usort($events, static fn ($a, $b) => strcmp($a['created_at'], $b['created_at'])
            ?: strcmp($a['id'], $b['id']));
        return $events;
    }
}
