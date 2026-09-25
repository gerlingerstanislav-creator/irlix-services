<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeesAccess
{
    public function resolve(Request $request): array
    {
        $identity = (array) $request->attributes->get('identity', []);
        $employee = null;

        if (!empty($identity['sub'])) {
            $employee = DB::table('employees')->where('keycloak_user_id', $identity['sub'])->first();
        }

        if (!$employee && !empty($identity['preferred_username'])) {
            $employee = DB::table('employees')->where('login', $identity['preferred_username'])->first();
            if ($employee && empty($employee->keycloak_user_id) && !empty($identity['sub'])) {
                DB::table('employees')->where('id', $employee->id)->update([
                    'keycloak_user_id' => $identity['sub'],
                    'identity_status' => 'active',
                    'updated_at' => now(),
                ]);
                $employee->keycloak_user_id = $identity['sub'];
                $employee->identity_status = 'active';
            }
        }

        $assignedRoles = $employee
            ? array_values(array_map('strval', DB::table('employee_access_roles')->where('employee_id', $employee->id)->orderBy('role')->pluck('role')->all()))
            : [];
        $platformAdmin = in_array(SpecialRoles::PlatformAdmin, $assignedRoles, true);

        if ($platformAdmin) {
            return $this->result($employee, true, true, true, true, 'all', $assignedRoles, $this->allDepartmentIds());
        }

        if (!$employee || !$employee->department_id) {
            return $this->result($employee, false, false, false, false, 'none', $assignedRoles, []);
        }

        $allDepartments = $this->departments();
        $financeRoot = $this->findRootByName($allDepartments, 'Finance');
        $hrRoot = $this->findRootByName($allDepartments, 'HR');

        if ($financeRoot && $this->isInSubtree((int) $employee->department_id, (int) $financeRoot->id, $allDepartments)) {
            return $this->result($employee, true, true, false, false, 'all', array_values(array_unique(array_merge($assignedRoles, ['finance']))), array_map(fn ($d) => (int) $d->id, $allDepartments));
        }

        if ($hrRoot && $this->isInSubtree((int) $employee->department_id, (int) $hrRoot->id, $allDepartments)) {
            return $this->result($employee, true, false, false, false, 'all', array_values(array_unique(array_merge($assignedRoles, ['hr']))), array_map(fn ($d) => (int) $d->id, $allDepartments));
        }

        $managedRoots = array_values(array_map(
            fn ($id) => (int) $id,
            DB::table('departments')->where('manager_id', $employee->id)->pluck('id')->all()
        ));

        if ($managedRoots) {
            $departmentIds = [];
            foreach ($managedRoots as $rootId) {
                $departmentIds = array_merge($departmentIds, $this->subtreeIds($rootId, $allDepartments));
            }
            $departmentIds = array_values(array_unique($departmentIds));
            return $this->result($employee, true, true, false, false, 'subtree', array_values(array_unique(array_merge($assignedRoles, ['manager']))), $departmentIds);
        }

        return $this->result($employee, false, false, false, false, 'none', $assignedRoles, []);
    }

    public function canSeeEmployee(array $access, int $employeeId): bool
    {
        if (!($access['permissions']['employees.read'] ?? false)) return false;
        if (($access['scope'] ?? 'none') === 'all') return true;
        $departmentId = DB::table('employees')->where('id', $employeeId)->value('department_id');
        return $departmentId !== null && in_array((int) $departmentId, $access['department_ids'] ?? [], true);
    }

    public function canSeeDepartment(array $access, ?int $departmentId): bool
    {
        if ($departmentId === null) return false;
        if (($access['scope'] ?? 'none') === 'all') return true;
        return in_array($departmentId, $access['department_ids'] ?? [], true);
    }

    private function result(?object $employee, bool $readEmployees, bool $readSalary, bool $manageEmployees, bool $manageAccess, string $scope, array $roles, array $departmentIds): array
    {
        return [
            'allowed' => $readEmployees,
            'employee_id' => $employee?->id,
            'employee_name' => $employee?->full_name,
            'roles' => $roles,
            'scope' => $scope,
            'department_ids' => array_values(array_unique(array_map('intval', $departmentIds))),
            'permissions' => [
                'employees.read' => $readEmployees,
                'employees.manage' => $manageEmployees,
                'employees.salary.read' => $readSalary,
                'employees.salary.manage' => $manageEmployees,
                'organization.read' => $readEmployees,
                'organization.manage' => $manageEmployees,
                'access.manage' => $manageAccess,
                'audit.read' => $manageAccess,
            ],
        ];
    }

    private function departments(): array
    {
        return DB::table('departments')->select(['id', 'parent_id', 'name'])->get()->all();
    }

    private function allDepartmentIds(): array
    {
        return array_map('intval', DB::table('departments')->pluck('id')->all());
    }

    private function findRootByName(array $departments, string $name): ?object
    {
        foreach ($departments as $department) {
            if (mb_strtolower((string) $department->name) === mb_strtolower($name)) return $department;
        }
        return null;
    }

    private function isInSubtree(int $departmentId, int $rootId, array $departments): bool
    {
        return in_array($departmentId, $this->subtreeIds($rootId, $departments), true);
    }

    private function subtreeIds(int $rootId, array $departments): array
    {
        $children = [];
        foreach ($departments as $department) {
            $parentKey = $department->parent_id === null ? null : (int) $department->parent_id;
            $children[$parentKey][] = (int) $department->id;
        }

        $result = [];
        $queue = [$rootId];
        while ($queue) {
            $current = array_shift($queue);
            if (in_array($current, $result, true)) continue;
            $result[] = $current;
            foreach ($children[$current] ?? [] as $child) $queue[] = $child;
        }
        return $result;
    }
}
