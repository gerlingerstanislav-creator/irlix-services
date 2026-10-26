<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAccessRole;
use App\Support\SpecialRoles;

final class EmployeeDirectoryService
{
    public function actor(array $identity): ?object
    {
        $subject = $identity['sub'] ?? null;
        if (!$subject) return null;
        $employee = Employee::query()->leftJoin('departments',
                'departments.id', '=', 'employees.department_id')
            ->where('employees.keycloak_user_id', $subject)
            ->select([
                'employees.id', 'employees.full_name', 'employees.first_name',
                'employees.last_name', 'employees.login', 'employees.work_email',
                'employees.department_id', 'employees.position',
                'employees.employment_status', 'departments.name as department_name',
            ])->first();
        return $employee ? (object) $employee->getRawOriginal() : null;
    }

    public function equipmentDirectory(bool $includeFormer, ?int $employee): mixed
    {
        // Projected allowlist: never send HR private profile or salary fields.
        $query = Employee::query()->from('employees as e')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->select(['e.id', 'e.full_name', 'e.position', 'e.employment_status',
                'd.name as department_name']);
        if ($employee !== null) {
            $found = $query->where('e.id', $employee)->first();
            return $found ? (object) $found->getRawOriginal() : null;
        }
        if (!$includeFormer) $query->where('e.employment_status', 'Трудоустроен');
        return $query->orderBy('e.full_name')->get()
            ->map(static fn (Employee $row) => (object) $row->getRawOriginal());
    }

    public function absenceApprovalContext(int $employeeId): ?array
    {
        $employee = Employee::query()->whereKey($employeeId)
            ->first(['id', 'full_name', 'department_id', 'employment_status']);
        if (!$employee) return null;

        $departments = Department::query()->get([
            'id', 'name', 'alias', 'parent_id', 'manager_id', 'hr_id',
        ])->keyBy('id');
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
                'alias' => $department->alias,
                'parent_id' => $department->parent_id === null ? null : (int) $department->parent_id,
            ];
            if ($hrApproverId === null && $department->hr_id !== null
                && (int) $department->hr_id !== $employeeId) {
                $hrApproverId = (int) $department->hr_id;
            }
            if ($department->manager_id !== null) {
                $managerId = (int) $department->manager_id;
                if ($managerId !== $employeeId && !isset($seenManagers[$managerId])) {
                    $seenManagers[$managerId] = true;
                    $manager = Employee::query()->whereKey($managerId)->first([
                        'id', 'full_name', 'department_id', 'employment_status',
                    ]);
                    if ($manager) $managerChain[] = [
                        'employee_id' => (int) $manager->id,
                        'full_name' => $manager->full_name,
                        'department_id' => $manager->department_id === null
                            ? null : (int) $manager->department_id,
                        'employment_status' => $manager->employment_status,
                        'managed_department_id' => (int) $department->id,
                    ];
                }
            }
            $currentDepartmentId = $department->parent_id === null ? null : (int) $department->parent_id;
        }

        $hrApprover = $hrApproverId === null ? null : Employee::query()
            ->whereKey($hrApproverId)->first(['id','full_name','department_id','employment_status']);
        $personnelOfficers = EmployeeAccessRole::query()->from('employee_access_roles as r')
            ->join('employees as e', 'e.id', '=', 'r.employee_id')
            ->where('r.role', SpecialRoles::PersonnelOfficer)
            ->where('e.employment_status', 'Трудоустроен')
            ->select(['e.id', 'e.full_name', 'e.department_id', 'e.employment_status'])
            ->orderBy('e.full_name')->get()
            ->map(static fn ($person) => [
                'employee_id' => (int) $person->id,
                'full_name' => $person->full_name,
                'department_id' => $person->department_id === null ? null : (int) $person->department_id,
                'employment_status' => $person->employment_status,
            ])->values()->all();

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
    }

    public function clientsDirectory(object $actor): array
    {
        $chain = $this->absenceApprovalContext((int) $actor->id)['department_chain'] ?? [];
        $accounting = collect($chain)->contains(static fn ($department): bool =>
            ($department['alias'] ?? '') === 'account-management');

        return [
            'accounting' => $accounting,
            'actor' => [
                'id' => (int) $actor->id,
                'position' => $actor->position,
                'department_id' => $actor->department_id === null ? null : (int) $actor->department_id,
            ],
            'employees' => Employee::query()->from('employees as e')
                ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
                ->where('e.employment_status', 'Трудоустроен')
                ->select(['e.id', 'e.full_name', 'e.position', 'e.department_id',
                    'd.name as department_name'])
                ->orderBy('e.full_name')->get()->map(
                    static fn (Employee $row) => (object) $row->getRawOriginal()
                ),
            'departments' => Department::query()
                ->get(['id', 'name', 'alias', 'parent_id', 'manager_id', 'is_production'])
                ->map(static fn (Department $row) => (object) $row->getRawOriginal()),
        ];
    }

    public function clientSpecialistProfile(int $employeeId): ?array
    {
        $employee = Employee::query()->from('employees as e')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->where('e.id', $employeeId)->where('e.employment_status', 'Трудоустроен')
            ->first(['e.id', 'e.full_name', 'e.work_email', 'e.city', 'e.phone', 'e.telegram',
                'e.department_id', 'd.name as department_name']);
        if (!$employee) return null;

        $departments = Department::query()->get(['id', 'parent_id', 'is_production'])->keyBy('id');
        $id = (int) $employee->department_id;
        $visited = [];
        while ($id > 0 && !isset($visited[$id])) {
            $visited[$id] = true;
            $department = $departments->get($id);
            if (!$department) break;
            if ((bool) $department->is_production) {
                return [
                    'id' => (int) $employee->id,
                    'full_name' => $employee->full_name,
                    'email' => $employee->work_email,
                    'department' => $employee->department_name,
                    'city' => $employee->city,
                    'phone' => $employee->phone,
                    'telegram' => $employee->telegram,
                ];
            }
            $id = (int) $department->parent_id;
        }
        return null;
    }

    public function vacationsHistoricalDirectory(array $access, int $year): array
    {
        return (new VacationsHistoricalDirectoryService())->forYear($access, $year);
    }

    public function vacationsDirectory(object $actor, array $access): array
    {
        $roles = array_values(array_unique(array_map('strval', $access['roles'] ?? [])));
        $global = SpecialRoles::isPlatformPrivileged($roles)
            || in_array(SpecialRoles::PersonnelOfficer, $roles, true)
            || in_array('hr', $roles, true);

        $departmentsQuery = Department::query()->select(['id', 'name', 'parent_id']);
        $employeesQuery = Employee::query()->from('employees as e')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->where('e.employment_status', 'Трудоустроен')
            ->select(['e.id', 'e.full_name', 'e.department_id', 'e.position',
                'e.employment_status', 'd.name as department_name']);

        if (!$global) {
            $departmentIds = array_values(array_unique(array_map('intval', $access['department_ids'] ?? [])));
            if (!$departmentIds) return [
                'employees' => [[
                    'id' => (int) $actor->id,
                    'full_name' => $actor->full_name,
                    'department_id' => $actor->department_id === null ? null : (int) $actor->department_id,
                    'position' => $actor->position,
                    'employment_status' => $actor->employment_status,
                    'department_name' => $actor->department_name,
                ]],
                'departments' => [],
            ];
            $departmentsQuery->whereIn('id', $departmentIds);
            $employeesQuery->whereIn('e.department_id', $departmentIds);
        }
        return [
            'employees' => $employeesQuery->orderBy('e.full_name')->get()
                ->map(static fn (Employee $row) => (object) $row->getRawOriginal()),
            'departments' => $departmentsQuery->orderBy('name')->get()
                ->map(static fn (Department $row) => (object) $row->getRawOriginal()),
        ];
    }

    public function specialistsDirectory(object $actor, array $access): array
    {
        $roles = array_values(array_unique(array_map('strval', $access['roles'] ?? [])));
        $platformAdmin = SpecialRoles::isPlatformPrivileged($roles);
        $managedDepartmentIds = Department::query()
            ->where('manager_id', $actor->id)->where('is_production', true)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (!$platformAdmin && !$managedDepartmentIds) return [
            'status' => 403, 'message' => 'Specialists is available to production department managers only',
        ];
        $departmentIds = $platformAdmin
            ? Department::query()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : array_values(array_unique(array_map('intval', $access['department_ids'] ?? [])));
        if (!$platformAdmin && !$departmentIds) return [
            'status' => 403, 'message' => 'No managed department scope available',
        ];

        $departments = Department::query()->whereIn('id', $departmentIds)
            ->orderBy('name')->get(['id','name','parent_id','manager_id'])
            ->map(static fn (Department $row) => (object) $row->getRawOriginal());
        $employees = Employee::query()->from('employees as e')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->whereIn('e.department_id', $departmentIds)
            ->select(['e.id', 'e.full_name', 'e.department_id', 'e.position',
                'e.specialization', 'e.employment_status', 'e.fired_at',
                'd.name as department_name'])
            ->orderBy('e.full_name')->get()
            ->map(static fn (Employee $row) => (object) $row->getRawOriginal());

        return ['status' => 200, 'data' => [
            'actor' => [
                'id' => (int) $actor->id,
                'full_name' => $actor->full_name,
                'department_id' => $actor->department_id === null ? null : (int) $actor->department_id,
            ],
            'scope' => $platformAdmin ? 'all' : 'department-tree',
            'managed_department_ids' => $platformAdmin ? $departmentIds : $managedDepartmentIds,
            'employees' => $employees,
            'departments' => $departments,
        ]];
    }
}
