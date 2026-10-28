<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\AbsenceApproval;
use App\Support\EmployeesDirectory;
use Illuminate\Http\Request;

/**
 * Intersection of local permission scope with CURRENT Vacations-owned object
 * ownership and trusted Employees manager relations. This is not a grant on
 * its own: controllers still enforce workflow stage, personnel secrecy,
 * active approvers, status, manager chain and immutable history.
 */
final class VacationsScopedAccess
{
    public function __construct(private readonly EmployeesDirectory $directory) {}

    /**
     * Never pass an empty team/own list to AbsenceReadService: an empty ID
     * selection in its legacy query means "no filter", not "no matches".
     * Return null only for an explicitly authorized ALL query with no IDs.
     */
    public function visibleEmployeeIds(
        Request $request, string $scope, int $actorId, array $requested = []
    ): ?array {
        if ($actorId <= 0) return [];
        $requested = array_values(array_unique(array_filter(array_map('intval', $requested),
            static fn (int $id): bool => $id > 0)));
        if ($scope === 'all') return $requested ?: null;
        if ($scope === 'own') {
            return !$requested || in_array($actorId, $requested, true) ? [$actorId] : [];
        }
        if ($scope !== 'team') return [];
        $org = $this->directory->vacationsDirectory($request);
        $people = array_values($org['employees'] ?? []);
        $departments = array_values($org['departments'] ?? []);
        return array_values(array_filter(array_map(
            static fn (array $employee): int => (int) ($employee['id'] ?? 0), $people
        ), static fn (int $id): bool => $id > 0
            && (!$requested || in_array($id, $requested, true))
            && VacationsObjectScope::allows('team', $actorId, $id, $departments, $people)));
    }

    public function permits(Request $request, string $scope, int $actorId): bool
    {
        if ($actorId <= 0 || !in_array($scope, ['own','team','all'], true)) return false;

        $path = $request->path();
        $method = $request->method();

        // The collective queue is filtered again by its controller before
        // serialization. Other mass read models require separate filtering.
        if (in_array($path, ['api/approvals','api/registry','api/calendar-absences'], true)
            && $method === 'GET') return true;
        if ($path === 'api/department-roster' && $method === 'GET') {
            return in_array($scope, ['team', 'all'], true);
        }
        if (in_array($path, ['api/client-service-calendar','api/client-service-absence-alerts'], true)
            && $method === 'GET') return $scope === 'all';

        if (in_array($path, [
            'api/me','api/workspace','api/history','api/attention',
            'api/production-calendar',
        ], true)) return $method === 'GET';

        if ($path === 'api/absences') return in_array($method, ['GET','POST'], true);

        $targetId = 0;
        $approverId = 0;
        if ($path === 'api/absences/occupied' && $method === 'GET') {
            $targetId = (int) ($request->query('employee_id') ?: $actorId);
        } elseif ($path === 'api/absences/for-employee' && $method === 'POST') {
            $targetId = (int) $request->input('employee_id', 0);
        } elseif (preg_match('#^api/approvals/([1-9][0-9]*)/(?:approve|reject)$#D', $path, $m)) {
            $approval = AbsenceApproval::query()->find((int) $m[1]);
            if (!$approval) return false;
            $approverId = (int) $approval->approver_employee_id;
            $absence = Absence::query()->find((int) $approval->absence_id);
            $targetId = (int) ($absence?->employee_id ?? 0);
        } elseif (preg_match('#^api/absences/([1-9][0-9]*)(?:/.*)?$#D', $path, $m)) {
            $absence = Absence::query()->find((int) $m[1]);
            $targetId = (int) ($absence?->employee_id ?? 0);
        } else {
            return false;
        }
        if ($targetId <= 0) return false;

        // An assigned approver has "own task" access even though the
        // underlying absence belongs to another employee. Self-approval is
        // prohibited again by the approval use case itself.
        if ($approverId === $actorId && $targetId !== $actorId) return true;
        if ($targetId === $actorId) return true;
        if ($scope === 'all') return true;
        if ($scope === 'own') return false;

        // Team means current verified organizational subordinates. A sick
        // leave can start in the past and still require today's manager to
        // approve it; its start date must not silently revoke that workflow.
        // Former employees are excluded because only the current Employees
        // directory can establish current team membership.
        $org = $this->directory->vacationsDirectory($request);
        return VacationsObjectScope::allows(
            'team', $actorId, $targetId,
            array_values($org['departments'] ?? []),
            array_values($org['employees'] ?? [])
        );
    }
}
