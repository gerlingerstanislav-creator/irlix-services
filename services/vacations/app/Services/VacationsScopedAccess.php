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

    public function permits(Request $request, string $scope, int $actorId): bool
    {
        if ($actorId <= 0 || !in_array($scope, ['own','team','all'], true)) return false;

        $path = $request->path();
        $method = $request->method();

        // The collective queue is filtered again by its controller before
        // serialization. Other mass read models require separate filtering.
        if ($path === 'api/approvals' && $method === 'GET') return true;

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
            $targetId = (int) (Absence::query()->whereKey((int) $approval->absence_id)->value('employee_id') ?? 0);
        } elseif (preg_match('#^api/absences/([1-9][0-9]*)(?:/.*)?$#D', $path, $m)) {
            $targetId = (int) (Absence::query()->whereKey((int) $m[1])->value('employee_id') ?? 0);
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

        $org = $this->directory->vacationsDirectory($request);
        return VacationsObjectScope::allows(
            'team', $actorId, $targetId,
            array_values($org['departments'] ?? []),
            array_values($org['employees'] ?? [])
        );
    }
}
