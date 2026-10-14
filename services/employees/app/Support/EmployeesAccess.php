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
        $platformPrivileged = SpecialRoles::isPlatformPrivileged($assignedRoles);

        if ($platformPrivileged) {
            return $this->withMatrix($this->result($employee, true, true, true, true, true, 'all', $assignedRoles, $this->allDepartmentIds()));
        }

        if (in_array('personnel-officer', $assignedRoles, true)) {
            return $this->withMatrix($this->result($employee, true, true, false, false, false, 'all', $assignedRoles, $this->allDepartmentIds()));
        }

        if (!$employee || !$employee->department_id) {
            return $this->withMatrix($this->result($employee, false, false, false, false, false, 'none', $assignedRoles, []));
        }

        $allDepartments = $this->departments();
        $financeRoot = $this->findRootByNameOrAlias($allDepartments, 'Finance', 'finance');
        $hrRoot = $this->findRootByNameOrAlias($allDepartments, 'HR', 'hr');

        if ($financeRoot && $this->isInSubtree((int) $employee->department_id, (int) $financeRoot->id, $allDepartments)) {
            return $this->withMatrix($this->result($employee, true, true, false, true, false, 'all', array_values(array_unique(array_merge($assignedRoles, ['finance']))), array_map(fn ($d) => (int) $d->id, $allDepartments)));
        }

        if ($hrRoot && $this->isInSubtree((int) $employee->department_id, (int) $hrRoot->id, $allDepartments)) {
            return $this->withMatrix($this->result($employee, true, false, false, false, false, 'all', array_values(array_unique(array_merge($assignedRoles, ['hr']))), array_map(fn ($d) => (int) $d->id, $allDepartments)));
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
            return $this->withMatrix($this->result($employee, true, true, false, false, false, 'subtree', array_values(array_unique(array_merge($assignedRoles, ['manager']))), $departmentIds));
        }

        return $this->withMatrix($this->result($employee, false, false, false, false, false, 'none', $assignedRoles, []));
    }

    private function withMatrix(array $access): array
    {
        $roles = $access['roles'];
        $defaults = [
            'employees.application.create' => (bool) ($access['permissions']['employees.manage'] ?? false)
                || in_array('hr',$roles,true) || in_array('manager',$roles,true) || in_array('personnel-officer',$roles,true),
            'employees.hire' => in_array('personnel-officer',$roles,true) || in_array('platform-tester',$roles,true),
            'employees.permissions.manage' => in_array('platform-admin',$roles,true) || in_array('platform-tester',$roles,true),
        ];
        if (in_array('personnel-officer',$roles,true)) {
            $access['permissions']['employees.read'] = true;
            $access['permissions']['employees.salary.read'] = true;
            $access['allowed'] = true;
        }
        $access['permissions'] = array_merge($access['permissions'],$defaults);
        if (in_array('platform-admin',$roles,true)) {
            foreach ($access['permissions'] as $permission => $_) $access['permissions'][$permission] = true;
            return $access;
        }
        if (\\Illuminate\\Support\\Facades\\Schema::hasTable('employee_permission_rules')) {
            $rules = DB::table('employee_permission_rules')->whereIn('role',$roles)->get();
            foreach ($rules as $rule) {
                // Positive permission union: one role cannot revoke another role's grant.
                if ($rule->enabled) $access['permissions'][$rule->permission] = true;
            }
        }
        $access['allowed'] = (bool) ($access['permissions']['employees.read'] ?? false);
        return $access;
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

    private function result(?object $employee, bool $readEmployees, bool $readSalary, bool $manageEmployees, bool $manageSalary, bool $manageAccess, string $scope, array $roles, array $departmentIds): array
    {
        $normalizedRoles = array_values(array_unique(array_map(
            static fn ($role) => str_replace('_', '-', mb_strtolower(trim((string) $role))), $roles
        )));
        $privileged = SpecialRoles::isPlatformPrivileged($normalizedRoles);
        $departmentName = $employee?->department_id
            ? (string) (DB::table('departments')->where('id', $employee->department_id)->value('name') ?? '')
            : '';
        $departmentName = mb_strtolower($departmentName);
        $accountingDepartment = str_contains($departmentName, 'бухгалтер') || str_contains($departmentName, 'accounting');
        if ($employee?->department_id && !$accountingDepartment) {
            $departments = $this->departments();
            foreach ($departments as $candidate) {
                $name = mb_strtolower((string) $candidate->name);
                $alias = mb_strtolower((string) ($candidate->alias ?? ''));
                if ((str_contains($name, 'бухгалтер') || str_contains($name, 'accounting') || str_contains($alias, 'accounting'))
                    && $this->isInSubtree((int) $employee->department_id, (int) $candidate->id, $departments)) {
                    $accountingDepartment = true;
                    break;
                }
            }
        }
        // Service entitlements are calculated once by Employees, not independently by every UI and API.
        $equipmentAllowed = $privileged
            || in_array(SpecialRoles::SystemAdministrator, $normalizedRoles, true)
            || $accountingDepartment;
        $serviceAccess = [
            'dashboard' => true,
            'employees' => $readEmployees,
            'vacations' => $employee !== null,
            'clients' => !in_array(SpecialRoles::PlatformTester, $normalizedRoles, true)
                || in_array(SpecialRoles::PlatformAdmin, $normalizedRoles, true), // Clients matrix still decides domain permissions.
            'timesheets' => $employee !== null,
            'assessments' => false,
            'specialists' => true, // Preserve existing domain-specific permissions.
            'equipment' => $equipmentAllowed,
            'recruitment' => true, // Domain access is evaluated by Recruitment.
            'cv-converter' => $privileged,
            'design-system' => $privileged,
            'migration' => in_array(SpecialRoles::PlatformAdmin, $normalizedRoles, true),
        ];
        return [
            'services' => $serviceAccess,
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
                'employees.salary.manage' => $manageSalary,
                'organization.read' => $readEmployees,
                'organization.manage' => $manageEmployees,
                'staff_positions.read' => $readEmployees,
                'staff_positions.manage' => $manageAccess,
                'access.manage' => $manageAccess,
                'audit.read' => $manageAccess,
            ],
        ];
    }

    private function departments(): array
    {
        return DB::table('departments')->select(['id', 'parent_id', 'name', 'alias'])->get()->all();
    }

    private function allDepartmentIds(): array
    {
        return array_map('intval', DB::table('departments')->pluck('id')->all());
    }

    private function findRootByNameOrAlias(array $departments, string $name, string $alias): ?object
    {
        $name = mb_strtolower($name);
        $alias = mb_strtolower($alias);
        foreach ($departments as $department) {
            if (mb_strtolower((string) $department->name) === $name) return $department;
            if (mb_strtolower((string) ($department->alias ?? '')) === $alias) return $department;
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
