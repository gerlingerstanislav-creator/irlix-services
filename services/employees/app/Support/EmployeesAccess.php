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

        if (!$employee) return $this->withMatrix($this->result(null, false, false, false, false, false, 'none', [], []));

        $departments = $this->departments();
        $special = array_values(array_intersect(
            DB::table('employee_access_roles')->where('employee_id', $employee->id)->pluck('role')->all(),
            SpecialRoles::keys()
        ));
        $roles = RoleLabels::resolve($employee, $departments, array_map('strval', $special));
        $privileged = SpecialRoles::isPlatformPrivileged($roles);
        $accounting = in_array('accounting', $roles, true);
        $hr = in_array('hr', $roles, true);
        $personnel = in_array(SpecialRoles::PersonnelOfficer, $roles, true);
        $managerIds = [];
        foreach ($departments as $department) {
            if ((int) ($department->manager_id ?? 0) === (int) $employee->id) {
                $managerIds = array_merge($managerIds, $this->subtreeIds((int) $department->id, $departments));
            }
        }
        $managerIds = array_values(array_unique($managerIds));
        $manager = count($managerIds) > 0;
        $read = $privileged || $accounting || $hr || $personnel || $manager;
        $salary = $privileged || $accounting || $manager;
        $fullScope = $privileged || $accounting || $hr || $personnel;
        $scope = $fullScope ? 'all' : ($manager ? 'subtree' : 'none');
        $ids = $fullScope ? array_map(fn ($d) => (int) $d->id, $departments) : $managerIds;

        return $this->withMatrix($this->result(
            $employee, $read, $salary, $privileged, $privileged || $accounting,
            $privileged, $scope, $roles, $ids
        ));
    }


    /**
     * Role-only scenarios. Values are role identifiers or department aliases,
     * never user identifiers. This catalog is not an account-impersonation API.
     */
    public function previewCatalog(array $realAccess): array
    {
        if (!$this->canPreview($realAccess)) return [];
        $options = [
            ['value' => 'employee', 'label' => 'Обычный сотрудник'],
            ['value' => 'personnel-officer', 'label' => 'Специалист по кадрам'],
            ['value' => 'hr', 'label' => 'HR'],
            ['value' => 'accounting', 'label' => 'Бухгалтерия'],
            ['value' => 'account-management', 'label' => 'Аккаунт-менеджер'],
            ['value' => 'sales', 'label' => 'Менеджер по продажам'],
            ['value' => 'client-service', 'label' => 'Клиентский сервис'],
            ['value' => 'system-admin', 'label' => 'Системный администратор'],
        ];
        $departments = DB::table('departments')->where('is_production', true)
            ->whereNotNull('alias')->orderBy('name')->get(['id', 'name', 'alias']);
        foreach ($departments as $department) {
            $alias = (string) $department->alias;
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $alias)
                || str_ends_with($alias, '-head')) continue;
            $options[] = ['value' => $alias.'-head', 'label' => 'Руководитель: '.$department->name];
        }
        $options[] = ['value' => 'commercial-director', 'label' => 'Коммерческий директор (составная роль)'];
        return $options;
    }

    public function canPreview(array $realAccess): bool
    {
        return (int) ($realAccess['employee_id'] ?? 0) > 0
            && count(array_intersect(['platform-admin', 'platform-tester'], (array) ($realAccess['roles'] ?? []))) > 0;
    }

    public function preview(array $realAccess, string $scenario): ?array
    {
        if (!$this->canPreview($realAccess)) return null;
        $catalog = $this->previewCatalog($realAccess);
        $labels = array_column($catalog, 'label', 'value');
        if (!isset($labels[$scenario])) return null;

        $employee = DB::table('employees')->where('id', (int) $realAccess['employee_id'])->first();
        if (!$employee || ($employee->employment_status ?? null) !== 'Трудоустроен') return null;
        $departments = $this->departments();
        $roles = [];
        $scopedDepartments = [];
        $productionManager = false;

        if ($scenario === 'commercial-director') {
            // Role bundle, not impersonation of any director's account.
            foreach (['sales', 'account-management', 'client-service'] as $alias) {
                $roles[] = $alias;
                $roles[] = $alias.'-head';
                foreach ($departments as $department) {
                    if (($department->alias ?? null) === $alias) {
                        $scopedDepartments = array_merge($scopedDepartments, $this->subtreeIds((int) $department->id, $departments));
                    }
                }
            }
            $productionManager = true;
        } elseif (str_ends_with($scenario, '-head')) {
            $alias = substr($scenario, 0, -5);
            $roots = DB::table('departments')->where('alias', $alias)->where('is_production', true)->pluck('id');
            if ($roots->isEmpty()) return null;
            foreach ($roots as $root) {
                $scopedDepartments = array_merge($scopedDepartments, $this->subtreeIds((int) $root, $departments));
            }
            $roles = [$alias, $scenario];
            $productionManager = true;
        } elseif ($scenario !== 'employee') {
            $roles = [$scenario];
        }

        $roles = array_values(array_unique($roles));
        $scopedDepartments = array_values(array_unique(array_map('intval', $scopedDepartments)));
        $accounting = in_array('accounting', $roles, true);
        $hr = in_array('hr', $roles, true);
        $personnel = in_array('personnel-officer', $roles, true);
        $manager = $scopedDepartments !== [];
        $read = $accounting || $hr || $personnel || $manager;
        $salary = $accounting || $manager;
        $scope = ($accounting || $hr || $personnel) ? 'all' : ($manager ? 'subtree' : 'none');
        $ids = $scope === 'all' ? array_map(fn ($d) => (int) $d->id, $departments) : $scopedDepartments;
        $access = $this->withMatrix($this->result(
            $employee, $read, $salary, false, $accounting, false, $scope, $roles, $ids,
            $productionManager
        ));
        $access['role_preview'] = ['value' => $scenario, 'label' => $labels[$scenario], 'read_only' => true];
        $access['role_preview_options'] = $catalog;
        return $access;
    }

    private function withMatrix(array $access): array
    {
        // A tester marker never contributes business or administrative grants.
        $roles = array_values(array_diff($access['roles'], [SpecialRoles::PlatformTester]));
        if (in_array('platform-admin', $roles, true)) {
            $access['permissions']['employees.application.create'] = true;
            $access['permissions']['employees.hire'] = true;
            $access['services']['finance'] = (bool) ($access['permissions']['finance.view'] ?? false);
            $access['permissions']['employees.permissions.manage'] = true;
            $access['permissions']['employees.fot.view'] = true;
            $access['permissions']['employees.fot.plan'] = true;
            $access['permissions']['employees.fot.approve'] = true;
            $access['permissions']['employees.transfer'] = true;
            return $access;
        }

        $defaults = [
            'platform-tester' => [],
            'personnel-officer' => ['employees.read','employees.application.create','employees.hire','organization.read','staff_positions.read','employees.transfer'],
            'hr' => ['employees.read','employees.application.create','organization.read','staff_positions.read'],
            'accounting' => ['employees.read','employees.salary.read','employees.salary.manage','organization.read','staff_positions.read','employees.fot.view'],
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
                $grants = $defaults[$role] ?? (str_ends_with($role, '-head') ? $defaults['manager'] : []);
                $roleGrant = in_array('*',$grants,true) || in_array($permission,$grants,true);
                if (isset($overrides[$role]) && array_key_exists($permission,$overrides[$role])) {
                    $roleGrant = $overrides[$role][$permission];
                }
                if ($roleGrant) $granted = true;
            }
            $access['permissions'][$permission] = $granted;
        }
        $access['permissions']['employees.permissions.manage'] = false;
        // A platform tester must not acquire personnel-only transfer rights via '*'.
        $access['permissions']['employees.transfer'] = in_array('personnel-officer',$roles,true);
        // The global matrix controls SERVICE ENTRY. Domain permissions still
        // belong to the service and cannot be bypassed by this grant.
        // Explicit denies take priority over inherited grants.
        foreach ($access['services'] as $service => $default) {
            $rule = 'services.'.$service;
            $granted = (bool) $default;
            $denied = false;
            foreach ($roles as $role) {
                if (!array_key_exists($rule, $overrides[$role] ?? [])) continue;
                if ($overrides[$role][$rule] === false) $denied = true;
                else $granted = true;
            }
            $access['services'][$service] = $granted && !$denied;
        }
        // An explicit finance.view denial is authoritative even when some
        // other organizational label permits entering the Finance service.
        $access['services']['finance'] = $access['services']['finance']
            && (bool) ($access['permissions']['finance.view'] ?? false);
        $access['allowed'] = (bool) ($access['permissions']['employees.read'] ?? false);
        $access['services']['employees'] = $access['services']['employees'] && $access['allowed'];
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

    private function result(?object $employee, bool $readEmployees, bool $readSalary, bool $manageEmployees, bool $manageSalary, bool $manageAccess, string $scope, array $roles, array $departmentIds, ?bool $previewProductionManager = null): array
    {
        $normalizedRoles = array_values(array_unique(array_map(
            static fn ($role) => str_replace('_', '-', mb_strtolower(trim((string) $role))), $roles
        )));
        $privileged = SpecialRoles::isPlatformPrivileged($normalizedRoles);
        $departments = $this->departments();
        $accountingDepartment = in_array('accounting', $normalizedRoles, true);
        $accountingHead = in_array('accounting-head', $normalizedRoles, true);
        // Service entitlements are calculated once by Employees, not independently by every UI and API.
        $equipmentAllowed = $privileged
            || in_array(SpecialRoles::SystemAdministrator, $normalizedRoles, true)
            || $accountingDepartment;
        // Match service admission to organizational audiences, not to a static
        // 'available' UI flag. Existing domain permission matrices remain the
        // authority for finer grained actions within an admitted service.
        $inClientTeams = count(array_intersect(['account-management', 'sales', 'client-service'], $normalizedRoles)) > 0;
        $isProductionManager = $previewProductionManager ?? ($employee && DB::table('departments')
            ->where('manager_id', $employee->id)->where('is_production', true)->exists());
        $canClients = $employee !== null
            && ($privileged || $inClientTeams || $isProductionManager);
        $canSpecialists = $employee !== null && ($privileged || $isProductionManager);
        $canRecruitment = $employee !== null
            && ($privileged || in_array('hr', $normalizedRoles, true)
                || in_array(SpecialRoles::PersonnelOfficer, $normalizedRoles, true));
        // Finance is not inherited by ordinary production department managers.
        $financeAdmin = in_array('platform-admin', $normalizedRoles, true);
        $financeVisible = $financeAdmin || $accountingDepartment;
        $financePermissions = [
            'finance.view' => $financeVisible,
            'finance.invoices.manage' => $financeAdmin || $accountingDepartment,
            'finance.cash.confirm' => $financeAdmin || $accountingDepartment,
            'finance.expenses.manage' => $financeAdmin || $accountingDepartment,
            'finance.accounts.manage' => $financeAdmin || $accountingDepartment,
            'finance.salary.view' => $financeVisible,
            'finance.planning.manage' => $financeVisible,
            'finance.planning.approve' => $financeAdmin || $accountingHead,
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
            'design-system' => true,
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
        return DB::table('departments')->select(['id', 'parent_id', 'name', 'alias', 'manager_id'])->get()->all();
    }

    private function allDepartmentIds(): array
    {
        return array_map('intval', DB::table('departments')->pluck('id')->all());
    }

    private function findRootByAlias(array $departments, string $alias): ?object
    {
        foreach ($departments as $department) {
            if ((string) ($department->alias ?? '') === $alias) return $department;
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
