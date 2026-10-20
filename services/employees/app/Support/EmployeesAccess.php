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
            return $this->withMatrix($this->result($employee, true, false, false, false, false, 'all', $assignedRoles, $this->allDepartmentIds()));
        }

        if (!$employee || !$employee->department_id) {
            return $this->withMatrix($this->result($employee, false, false, false, false, false, 'none', $assignedRoles, []));
        }

        $allDepartments = $this->departments();
        $financeRoot = $this->findRootByNameOrAlias($allDepartments, 'Finance', 'finance');
        $accountingRoot = $this->findRootByNameOrAlias($allDepartments, 'Бухгалтерия', 'accounting');
        $hrRoot = $this->findRootByNameOrAlias($allDepartments, 'HR', 'hr');

        if (($accountingRoot && $this->isInSubtree((int) $employee->department_id, (int) $accountingRoot->id, $allDepartments)) || ($financeRoot && $this->isInSubtree((int) $employee->department_id, (int) $financeRoot->id, $allDepartments))) {
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
        if (in_array('platform-admin', $roles, true)) {
            $access['permissions']['employees.application.create'] = true;
            $access['permissions']['employees.hire'] = true;
            // A revoked finance.view hides the whole service; an accounting
        // department membership alone is not a bypass for an explicit deny.
        $access['services']['finance'] = (bool)($access['permissions']['finance.view'] ?? false);
        $access['permissions']['employees.permissions.manage'] = true;
            $access['permissions']['employees.fot.view'] = true;
            $access['permissions']['employees.fot.plan'] = true;
            $access['permissions']['employees.fot.approve'] = true;
            $access['permissions']['employees.transfer'] = true;
            return $access;
        }

        $defaults = [
            'platform-tester' => ['*'],
            'personnel-officer' => ['employees.read','employees.application.create','employees.hire','organization.read','staff_positions.read','employees.transfer'],
            'hr' => ['employees.read','employees.application.create','organization.read','staff_positions.read'],
            'finance' => ['employees.read','employees.salary.read','employees.salary.manage','organization.read','staff_positions.read','employees.fot.view'],
            'manager' => ['employees.read','employees.application.create','employees.salary.read','organization.read','staff_positions.read','employees.fot.view','employees.fot.plan','employees.fot.approve'],
            'system-admin' => [],
        ];
        $permissions = array_values(array_unique(array_merge(array_keys($access['permissions']), [
            'employees.application.create','employees.hire','employees.permissions.manage',
            'employees.fot.view','employees.fot.plan','employees.fot.approve','employees.transfer',
        ])));
        $overrides = [];
        if (DB::getSchemaBuilder()->hasTable('employee_permission_rules')) {
            foreach (DB::table('employee_permission_rules')->whereIn('role',$roles)->get() as $rule) {
                $overrides[$rule->role][$rule->permission] = (bool) $rule->enabled;
            }
        }
        foreach ($permissions as $permission) {
            // Finance has an explicit business eligibility boundary: normal
            // department-manager roles must never acquire the service through
            // a wildcard or a permission-rule grant. However administrators
            // can DENY a sensitive finance capability for any eligible role.
            // A positive override cannot expand an unauthorized audience.
            if (str_starts_with($permission, 'finance.')) {
                foreach ($roles as $role) {
                    if (isset($overrides[$role])
                        && array_key_exists($permission, $overrides[$role])
                        && $overrides[$role][$permission] === false) {
                        $access['permissions'][$permission] = false;
                    }
                }
                continue;
            }
            $granted = false;
            foreach ($roles as $role) {
                $grants = $defaults[$role] ?? [];
                $roleGrant = in_array('*',$grants,true) || in_array($permission,$grants,true);
                if (isset($overrides[$role]) && array_key_exists($permission,$overrides[$role])) {
                    $roleGrant = $overrides[$role][$permission];
                }
                if ($roleGrant) $granted = true;
            }
            $access['permissions'][$permission] = $granted;
        }
        $access['permissions']['employees.permissions.manage'] = in_array('platform-tester',$roles,true);
        // A platform tester must not acquire personnel-only transfer rights via '*'.
        $access['permissions']['employees.transfer'] = in_array('personnel-officer',$roles,true);
        $access['allowed'] = (bool) ($access['permissions']['employees.read'] ?? false);
        $access['services']['employees'] = $access['allowed'];
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
        // Match service admission to organizational audiences, not to a static
        // 'available' UI flag. Existing domain permission matrices remain the
        // authority for finer grained actions within an admitted service.
        $position = mb_strtolower((string) ($employee?->position ?? ''));
        $departmentAncestors = [];
        if ($employee?->department_id) {
            $allDepartments = $this->departments();
            $byId = [];
            foreach ($allDepartments as $candidate) $byId[(int) $candidate->id] = $candidate;
            $cursor = (int) $employee->department_id;
            $visited = [];
            while ($cursor && isset($byId[$cursor]) && !isset($visited[$cursor])) {
                $visited[$cursor] = true;
                $ancestor = $byId[$cursor];
                $departmentAncestors[] = mb_strtolower((string) $ancestor->name);
                $departmentAncestors[] = mb_strtolower((string) ($ancestor->alias ?? ''));
                $cursor = (int) ($ancestor->parent_id ?? 0);
            }
        }
        $inOrg = static function (array $needles) use ($departmentAncestors): bool {
            foreach ($departmentAncestors as $label) {
                foreach ($needles as $needle) {
                    if ($needle !== '' && str_contains($label, $needle)) return true;
                }
            }
            return false;
        };
        $isManager = in_array('manager', $normalizedRoles, true)
            || ($employee && DB::table('departments')->where('manager_id', $employee->id)->exists());
        $isProductionManager = $employee && DB::table('departments')
            ->where('manager_id', $employee->id)->where('is_production', true)->exists();
        $inClientTeams = $inOrg(['accounting', 'аккаунтинг', 'sales', 'сейлз', 'клиентск', 'client service']);
        $isAccountManager = (str_contains($position, 'аккаунт') || str_contains($position, 'account'))
            && (str_contains($position, 'менедж') || str_contains($position, 'manager'));
        $isSales = str_contains($position, 'сейлз') || str_contains($position, 'sales');
        $isRecruitment = $inOrg(['hr', 'recruit', 'рекрут', 'подбор персонала'])
            || str_contains($position, 'рекрут') || str_contains($position, 'recruit');
        $canClients = $employee !== null
            && ($privileged || $inClientTeams || $isAccountManager || $isSales || $isProductionManager);
        if (in_array(SpecialRoles::PlatformTester, $normalizedRoles, true)
            && !in_array(SpecialRoles::PlatformAdmin, $normalizedRoles, true)) $canClients = false;
        $canSpecialists = $employee !== null && ($privileged || $isProductionManager);
        $canRecruitment = $employee !== null && ($privileged || $isRecruitment || $isManager
            || in_array(SpecialRoles::PersonnelOfficer, $normalizedRoles, true));
        // Finance is not inherited by ordinary production department managers.
        $financeAdmin = in_array('platform-admin', $normalizedRoles, true);
        $financeDirector = in_array('finance-director', $normalizedRoles, true);
        $financeVisible = $financeAdmin || $financeDirector || $accountingDepartment;
        $financePermissions = [
            'finance.view' => $financeVisible,
            'finance.invoices.manage' => $financeAdmin || $accountingDepartment,
            'finance.cash.confirm' => $financeAdmin || $accountingDepartment,
            'finance.expenses.manage' => $financeAdmin || $accountingDepartment,
            'finance.accounts.manage' => $financeAdmin || $accountingDepartment,
            'finance.salary.view' => $financeVisible,
            'finance.planning.manage' => $financeVisible,
            'finance.planning.approve' => $financeAdmin || $financeDirector,
        ];
        $serviceAccess = [
            'dashboard' => true,
            'employees' => $readEmployees,
            'vacations' => $employee !== null,
            'clients' => $canClients,
            'timesheets' => $employee !== null,
            'assessments' => false,
            'specialists' => $canSpecialists,
            'equipment' => $equipmentAllowed,
            'finance' => $financeVisible,
            'recruitment' => $canRecruitment,
            'cv-converter' => $privileged,
            'design-system' => $privileged,
            'migration' => in_array(SpecialRoles::PlatformAdmin, $normalizedRoles, true),
            'system' => in_array(SpecialRoles::PlatformAdmin, $normalizedRoles, true),
        ];
        $managedSalaryDepartments = [];
        if ($employee?->id) {
            foreach (DB::table('departments')->where('manager_id', $employee->id)->pluck('id') as $managedRoot) {
                $managedSalaryDepartments = array_merge($managedSalaryDepartments, $this->subtreeIds((int) $managedRoot, $this->departments()));
            }
        }
        return [
            'staff_positions_salary_departments' => array_values(array_unique(array_map('intval', $managedSalaryDepartments))),
            'staff_positions_salary_all' => $accountingDepartment,
            'services' => $serviceAccess,
            'allowed' => $readEmployees,
            'employee_id' => $employee?->id,
            'employee_name' => $employee?->full_name,
            'roles' => $roles,
            'scope' => $scope,
            'department_ids' => array_values(array_unique(array_map('intval', $departmentIds))),
            'permissions' => [
                ...$financePermissions,
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
