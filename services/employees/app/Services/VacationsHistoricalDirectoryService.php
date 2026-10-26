<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentAssignmentHistory;
use App\Models\EmploymentPeriod;
use App\Support\SpecialRoles;

final class VacationsHistoricalDirectoryService
{
    /** The Employees-owned historical snapshot; caller's CURRENT scope remains authoritative. */
    public function forYear(array $access, int $year): array
    {
        $roles = array_values(array_unique(array_map('strval', $access['roles'] ?? [])));
        $global = SpecialRoles::isPlatformPrivileged($roles)
            || in_array(SpecialRoles::PersonnelOfficer, $roles, true)
            || in_array('hr', $roles, true);
        $allowedIds = array_values(array_unique(array_map('intval', $access['department_ids'] ?? [])));

        $departmentsQuery = Department::query()->select(['id', 'name', 'parent_id']);
        if (!$global) $departmentsQuery->whereIn('id', $allowedIds);
        $departments = $departmentsQuery->orderBy('name')->get()
            ->map(static fn (Department $d): array => $d->getRawOriginal())->all();
        if (!$global && !$allowedIds) return ['employees' => [], 'departments' => $departments];
        $departmentNames = Department::query()->pluck('name', 'id')->all();

        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $periods = EmploymentPeriod::query()
            ->whereDate('started_at', '<=', $to)
            ->where(static fn ($q) => $q->whereNull('ended_at')->orWhereDate('ended_at', '>=', $from))
            ->orderBy('employee_id')->orderBy('started_at')
            ->get(['employee_id', 'started_at', 'ended_at', 'department_id']);
        $ids = $periods->pluck('employee_id')->map(static fn ($id) => (int) $id)->unique()->all();
        if (!$ids) return ['employees' => [], 'departments' => $departments];

        $assignments = EmploymentAssignmentHistory::query()
            ->whereIn('employee_id', $ids)
            ->whereDate('effective_from', '<=', $to)
            ->where(static fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
            ->orderBy('effective_from')->get(['employee_id', 'department_id', 'effective_from', 'effective_to'])
            ->groupBy('employee_id');
        $segmentsByEmployee = [];
        foreach ($periods as $period) {
            $id = (int) $period->employee_id;
            $start = max($from, substr((string) $period->started_at, 0, 10));
            $end = min($to, $period->ended_at ? substr((string) $period->ended_at, 0, 10) : $to);
            if ($start > $end) continue;

            $matchedAssignment = false;
            foreach ($assignments->get($id, collect()) as $assignment) {
                $aStart = max($start, substr((string) $assignment->effective_from, 0, 10));
                $aEnd = min($end, $assignment->effective_to ? substr((string) $assignment->effective_to, 0, 10) : $end);
                if ($aStart > $aEnd) continue;
                $matchedAssignment = true;
                $this->appendSegment($segmentsByEmployee, $id, $assignment->department_id, $aStart, $aEnd,
                    $global, $allowedIds, $departmentNames);
            }
            // Historical backfills can have employment periods without an assignment record.
            // Only in that case use the department recorded on the period itself.
            if (!$matchedAssignment) {
                $this->appendSegment($segmentsByEmployee, $id, $period->department_id, $start, $end,
                    $global, $allowedIds, $departmentNames);
            }
        }

        if (!$segmentsByEmployee) return ['employees' => [], 'departments' => $departments];
        $rows = Employee::query()->whereIn('id', array_keys($segmentsByEmployee))
            ->orderBy('full_name')->get(['id', 'full_name', 'employment_status']);
        $employees = [];
        foreach ($rows as $employee) {
            $segments = $segmentsByEmployee[(int) $employee->id];
            usort($segments, static fn ($a, $b) => strcmp($a['from'], $b['from']) ?: strcmp($a['to'], $b['to']));
            $last = end($segments);
            $employees[] = [
                'id' => (int) $employee->id,
                'full_name' => $employee->full_name,
                'employment_status' => $employee->employment_status,
                'department_id' => $last['department_id'],
                'department_name' => $last['department_name'],
                'employment_segments' => $segments,
            ];
        }
        return ['employees' => $employees, 'departments' => $departments];
    }

    private function appendSegment(array &$result, int $employee, mixed $department, string $from, string $to,
        bool $global, array $allowedIds, array $names): void
    {
        $departmentId = $department === null ? null : (int) $department;
        if (!$global && !in_array($departmentId, $allowedIds, true)) return;
        $segment = [
            'from' => $from, 'to' => $to,
            'department_id' => $departmentId,
            'department_name' => $departmentId === null ? null : ($names[$departmentId] ?? null),
        ];
        $result[$employee][] = $segment;
    }
}
