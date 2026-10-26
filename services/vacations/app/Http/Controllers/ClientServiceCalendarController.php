<?php

namespace App\Http\Controllers;

use App\Application\AbsenceReadService;
use App\Support\EmployeesDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Minimal cross-contour read model for active Employees, restricted to
 * staff physically belonging to the client-service department subtree.
 * No absence type, files, reason, comment, approval chain or medical data.
 */
final class ClientServiceCalendarController extends Controller
{
    public function __invoke(
        Request $request,
        EmployeesDirectory $directory,
        AbsenceReadService $reads
    ): JsonResponse {
        $month = (string) $request->query('month', '');
        abort_unless(preg_match('/^(20[0-9]{2}|2100)-(0[1-9]|1[0-2])$/D', $month), 422, 'Неверный месяц.');
        $year = (int) substr($month, 0, 4);
        $number = (int) substr($month, 5, 2);

        [$activeIds, $employees, $departments] = $this->authorizedDirectory($request, $directory);

        $start = sprintf('%04d-%02d-01', $year, $number);
        $end = date('Y-m-t', strtotime($start.' 12:00:00 UTC'));
        $absences = $reads->registry($activeIds, ['from' => $start, 'to' => $end]);
        return response()->json(['data' => [
            'employees' => array_map(static fn ($employee) => [
                'id' => (int) $employee['id'],
                'full_name' => $employee['full_name'],
                'department_id' => $employee['department_id'] ?? null,
                'department_name' => $employee['department_name'] ?? null,
            ], $employees),
            'departments' => array_map(static fn ($department) => [
                'id' => (int) $department['id'],
                'name' => $department['name'],
            ], $departments),
            'absences' => array_map(static fn ($item) => [
                'id' => $item['id'], 'employee_id' => (int) $item['employee_id'],
                'starts_on' => $item['starts_on'], 'ends_on' => $item['ends_on'],
                'status' => $item['status'],
            ], $absences),
        ]]);
    }
    /** Aggregated by Vacations in one indexed read, without personal details. */
    public function alerts(Request $request, EmployeesDirectory $directory, AbsenceReadService $reads): JsonResponse
    {
        [$activeIds] = $this->authorizedDirectory($request, $directory);
        $today = \Carbon\CarbonImmutable::today();
        $levels = $reads->absenceAlerts(
            $activeIds, $today->toDateString(),
            $today->addMonthNoOverflow()->toDateString(),
            $today->addMonthsNoOverflow(3)->toDateString()
        );
        return response()->json(['data' => $levels]);
    }

    private function authorizedDirectory(Request $request, EmployeesDirectory $directory): array
    {
        $identity = (array) $request->attributes->get('identity', []);
        $jwt = filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        $roles = (array) ($identity['realm_roles'] ?? []);
        if ($jwt) {
            $ids = array_values(array_filter($roles, static fn ($role) =>
                is_string($role) && preg_match('/^employee-id:[1-9][0-9]*$/D', $role)));
            abort_unless(count($ids) === 1 && in_array('service:clients', $roles, true), 403);
            $verifiedId = (int) substr($ids[0], strlen('employee-id:'));
        } else {
            $verifiedId = null;
        }

        // This Employees projection contains *only currently employed* people
        // and the actor's verified department identity; no former staff.
        $payload = $directory->clientsDirectory($request);
        $actorId = (int) ($payload['actor']['id'] ?? 0);
        abort_unless($actorId > 0 && (!$jwt || $actorId === $verifiedId), 403);
        $employees = array_values((array) ($payload['employees'] ?? []));
        $activeIds = array_values(array_filter(array_map(
            static fn ($row) => (int) ($row['id'] ?? 0), $employees
        )));
        abort_unless(in_array($actorId, $activeIds, true), 403, 'Сотрудник не трудоустроен.');

        $departments = array_values((array) ($payload['departments'] ?? []));
        $byId = [];
        foreach ($departments as $department) $byId[(int) $department['id']] = $department;
        $departmentId = (int) ($payload['actor']['department_id'] ?? 0);
        $inService = false;
        $visited = [];
        while ($departmentId > 0 && isset($byId[$departmentId]) && !isset($visited[$departmentId])) {
            $visited[$departmentId] = true;
            $department = $byId[$departmentId];
            if (($department['alias'] ?? '') === 'client-service') {
                $inService = true;
                break;
            }
            $departmentId = (int) ($department['parent_id'] ?? 0);
        }
        abort_unless($inService, 403, 'Календарь доступен только сотрудникам клиентской службы.');

        return [$activeIds, $employees, $departments];
    }

}
