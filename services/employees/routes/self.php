<?php

use App\Support\SpecialRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

$findEmployeeByRequest = function (Request $request): ?object {
    $identity = (array) $request->attributes->get('identity', []);
    $subject = $identity['sub'] ?? null;
    if (!$subject) return null;

    return DB::table('employees')
        ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
        ->where('employees.keycloak_user_id', $subject)
        ->select([
            'employees.id',
            'employees.full_name',
            'employees.first_name',
            'employees.last_name',
            'employees.login',
            'employees.work_email',
            'employees.department_id',
            'employees.position',
            'employees.employment_status',
            'departments.name as department_name',
        ])
        ->first();
};

$absenceApprovalContext = function (int $employeeId): ?array {
    $employee = DB::table('employees')
        ->where('id', $employeeId)
        ->select(['id', 'full_name', 'department_id', 'employment_status'])
        ->first();
    if (!$employee) return null;

    $departments = DB::table('departments')
        ->select(['id', 'name', 'parent_id', 'manager_id', 'hr_id'])
        ->get()
        ->keyBy('id');

    $departmentChain = [];
    $managerChain = [];
    $seenManagers = [];
    $hrApproverId = null;
    $currentDepartmentId = $employee->department_id ? (int) $employee->department_id : null;
    $visitedDepartments = [];

    while ($currentDepartmentId !== null && !isset($visitedDepartments[$currentDepartmentId])) {
        $visitedDepartments[$currentDepartmentId] = true;
        $department = $departments->get($currentDepartmentId);
        if (!$department) break;

        $departmentChain[] = [
            'id' => (int) $department->id,
            'name' => $department->name,
            'parent_id' => $department->parent_id === null ? null : (int) $department->parent_id,
        ];

        // Directional HR is part of the organizational model and is intentionally
        // independent from the special personnel-officer functional role.
        if ($hrApproverId === null && $department->hr_id !== null && (int) $department->hr_id !== $employeeId) {
            $hrApproverId = (int) $department->hr_id;
        }

        if ($department->manager_id !== null) {
            $managerId = (int) $department->manager_id;
            if ($managerId !== $employeeId && !isset($seenManagers[$managerId])) {
                $seenManagers[$managerId] = true;
                $manager = DB::table('employees')
                    ->where('id', $managerId)
                    ->select(['id', 'full_name', 'department_id', 'employment_status'])
                    ->first();
                if ($manager) {
                    $managerChain[] = [
                        'employee_id' => (int) $manager->id,
                        'full_name' => $manager->full_name,
                        'department_id' => $manager->department_id === null ? null : (int) $manager->department_id,
                        'employment_status' => $manager->employment_status,
                        'managed_department_id' => (int) $department->id,
                    ];
                }
            }
        }

        $currentDepartmentId = $department->parent_id === null ? null : (int) $department->parent_id;
    }

    $hrApprover = $hrApproverId === null ? null : DB::table('employees')
        ->where('id', $hrApproverId)
        ->select(['id', 'full_name', 'department_id', 'employment_status'])
        ->first();

    $personnelOfficers = DB::table('employee_access_roles as r')
        ->join('employees as e', 'e.id', '=', 'r.employee_id')
        ->where('r.role', SpecialRoles::PersonnelOfficer)
        ->where('e.employment_status', 'Трудоустроен')
        ->select(['e.id', 'e.full_name', 'e.department_id', 'e.employment_status'])
        ->orderBy('e.full_name')
        ->get()
        ->map(fn ($person) => [
            'employee_id' => (int) $person->id,
            'full_name' => $person->full_name,
            'department_id' => $person->department_id === null ? null : (int) $person->department_id,
            'employment_status' => $person->employment_status,
        ])
        ->values()
        ->all();

    return [
        'employee' => [
            'id' => (int) $employee->id,
            'full_name' => $employee->full_name,
            'department_id' => $employee->department_id === null ? null : (int) $employee->department_id,
            'employment_status' => $employee->employment_status,
        ],
        'department_chain' => $departmentChain,
        'hr_approver' => $hrApprover ? [
            'employee_id' => (int) $hrApprover->id,
            'full_name' => $hrApprover->full_name,
            'department_id' => $hrApprover->department_id === null ? null : (int) $hrApprover->department_id,
            'employment_status' => $hrApprover->employment_status,
        ] : null,
        'personnel_officers' => $personnelOfficers,
        'manager_chain' => $managerChain,
    ];
};

Route::get('/self', function (Request $request) use ($findEmployeeByRequest) {
    $identity = (array) $request->attributes->get('identity', []);
    if (empty($identity['sub'])) {
        return response()->json(['message' => 'Authenticated identity has no subject'], 401);
    }

    $employee = $findEmployeeByRequest($request);
    if (!$employee) {
        return response()->json(['message' => 'Employee profile is not linked to this account'], 404);
    }

    return response()->json(['data' => $employee]);
});

Route::get('/self/absence-approval-context', function (Request $request) use ($findEmployeeByRequest, $absenceApprovalContext) {
    $employee = $findEmployeeByRequest($request);
    if (!$employee) return response()->json(['message' => 'Employee profile is not linked to this account'], 404);

    return response()->json(['data' => $absenceApprovalContext((int) $employee->id)]);
});

Route::get('/absence-approval-context/{employee}', function (Request $request, int $employee) use ($findEmployeeByRequest, $absenceApprovalContext) {
    $actor = $findEmployeeByRequest($request);
    if (!$actor) return response()->json(['message' => 'Employee profile is not linked to this account'], 404);

    if ((int) $actor->id !== $employee) {
        $access = (array) $request->attributes->get('employees_access', []);
        if (!($access['permissions']['employees.read'] ?? false)) return response()->json(['message' => 'Forbidden'], 403);

        $targetDepartmentId = DB::table('employees')->where('id', $employee)->value('department_id');
        $scope = $access['scope'] ?? 'none';
        $visibleDepartments = array_map('intval', $access['department_ids'] ?? []);
        if ($scope !== 'all' && ($targetDepartmentId === null || !in_array((int) $targetDepartmentId, $visibleDepartments, true))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
    }

    $context = $absenceApprovalContext($employee);
    if (!$context) return response()->json(['message' => 'Employee not found'], 404);
    return response()->json(['data' => $context]);
})->whereNumber('employee');
