<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ClientContourAccess
{
    public const ROLE_LABELS = [
        'employee' => 'Сотрудник',
        'account-manager' => 'Аккаунт-менеджер',
        'accounting-head' => 'Руководитель направления аккаунтинга',
        'client-service-head' => 'Руководитель клиентской службы',
        'sales-manager' => 'Сейлз',
        'sales-head' => 'Руководитель направления сейлз',
        'department-manager' => 'Руководитель производственного направления',
        'platform-admin' => 'Администратор платформы',
        'personnel-officer' => 'Кадровик',
        'system-admin' => 'Системный администратор',
    ];

    public const PERMISSION_LABELS = [
        'clients.view' => 'Клиенты: просмотр страницы', 'clients.manage' => 'Клиенты: изменение',
        'contacts.view' => 'Контактные лица: просмотр', 'contacts.manage' => 'Контактные лица: изменение',
        'members.view' => 'Участники проектов: просмотр', 'members.manage' => 'Участники проектов: изменение',
        'requests.view' => 'Запросы: просмотр', 'requests.manage' => 'Запросы: изменение',
        'positions.view' => 'Позиции: просмотр', 'positions.manage' => 'Позиции: изменение',
        'attempts.view' => 'Попытки: просмотр', 'attempts.manage' => 'Попытки: изменение',
        'leads.view' => 'Лиды: просмотр', 'leads.manage' => 'Лиды: изменение',
        'reports.view' => 'Отчётные периоды: просмотр', 'reports.manage' => 'Отчётные периоды: изменение',
        'cashflow.view' => 'ДДС: просмотр', 'timesheets.mine.view' => 'ТШ: мои таймшиты',
        'timesheets.management.view' => 'ТШ: управление — просмотр',
        'timesheets.management.manage' => 'ТШ: управление — изменение',
        'timesheets.analytics.view' => 'ТШ: коммерческая загрузка',
        'timesheets.audit.view' => 'ТШ: история действий',
        'permissions.view' => 'Настройки разрешений: просмотр',
        'permissions.manage' => 'Настройки разрешений: изменение',
    ];

    public function resolve(Request $request): array
    {
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
        $http = Http::withToken((string) $token)->acceptJson()->timeout(8);
        $employeeResponse = $http->get($base.'/self');
        $accessResponse = $http->get($base.'/access/me');
        $contextResponse = $http->get($base.'/self/absence-approval-context');
        $directoryResponse = $http->get($base.'/clients-directory');
        abort_unless($employeeResponse->successful() && $accessResponse->successful() && $contextResponse->successful() && $directoryResponse->successful(), 503, 'Employees access service is unavailable');

        $employee = (array) $employeeResponse->json('data', []);
        $specialRoles = array_values(array_map(
            fn ($role) => str_replace('_', '-', mb_strtolower(trim((string) $role))),
            $accessResponse->json('data.roles', [])
        ));
        $departmentIds = array_values(array_unique(array_map('intval', $accessResponse->json('data.department_ids', []))));
        $chain = collect($contextResponse->json('data.department_chain', []));
        $departmentNames = $chain->map(fn ($d) => mb_strtolower((string) ($d['name'] ?? '')))->all();
        $ownDepartment = $departmentNames[0] ?? '';
        $position = mb_strtolower((string) ($employee['position'] ?? ''));
        $roles = array_values(array_unique(array_merge(['employee'], array_diff($specialRoles, ['department-manager']))));
        $departments = collect($directoryResponse->json('data.departments', []));
        $directoryEmployees = collect($directoryResponse->json('data.employees', []));
        $managedProductionRoots = $departments
            ->filter(fn ($department) => (int) ($department['manager_id'] ?? 0) === (int) ($employee['id'] ?? 0)
                && ((bool) ($department['is_production'] ?? false)))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $productionDepartmentIds = $managedProductionRoots;
        do {
            $previousCount = count($productionDepartmentIds);
            foreach ($departments as $department) {
                $parentId = (int) ($department['parent_id'] ?? 0);
                $departmentId = (int) ($department['id'] ?? 0);
                if ($departmentId && in_array($parentId, $productionDepartmentIds, true) && !in_array($departmentId, $productionDepartmentIds, true)) $productionDepartmentIds[] = $departmentId;
            }
        } while (count($productionDepartmentIds) !== $previousCount);
        $productionDepartmentNames = $departments
            ->filter(fn ($department) => in_array((int) ($department['id'] ?? 0), $productionDepartmentIds, true))
            ->pluck('name')->filter()->map(fn ($name) => (string) $name)->values()->all();
        $productionEmployeeIds = $directoryEmployees
            ->filter(fn ($person) => in_array((int) ($person['department_id'] ?? 0), $productionDepartmentIds, true))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $productionDirections = $departments->filter(fn ($department) => (bool) ($department['is_production'] ?? false))->values()->all();
        $clientServiceRoots = $departments->filter(function ($department): bool {
            $name = mb_strtolower(trim((string) ($department['name'] ?? '')));
            return $name === 'client service' || str_contains($name, 'клиентск');
        })->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $clientServiceDepartmentIds = $clientServiceRoots;
        do {
            $previousCount = count($clientServiceDepartmentIds);
            foreach ($departments as $department) {
                $parentId = (int) ($department['parent_id'] ?? 0);
                $departmentId = (int) ($department['id'] ?? 0);
                if ($departmentId && in_array($parentId, $clientServiceDepartmentIds, true) && !in_array($departmentId, $clientServiceDepartmentIds, true)) $clientServiceDepartmentIds[] = $departmentId;
            }
        } while (count($clientServiceDepartmentIds) !== $previousCount);
        $accountingRoots = $departments->filter(function ($department): bool {
            $name = mb_strtolower(trim((string) ($department['name'] ?? '')));
            return $name === 'accounting' || str_contains($name, 'аккаунтинг') || str_contains($name, 'accounting');
        })->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $accountingDepartmentIds = $accountingRoots;
        do {
            $previousCount = count($accountingDepartmentIds);
            foreach ($departments as $department) {
                $parentId = (int) ($department['parent_id'] ?? 0);
                $departmentId = (int) ($department['id'] ?? 0);
                if ($departmentId && in_array($parentId, $accountingDepartmentIds, true) && !in_array($departmentId, $accountingDepartmentIds, true)) $accountingDepartmentIds[] = $departmentId;
            }
        } while (count($accountingDepartmentIds) !== $previousCount);
        $accountEmployeeIds = $directoryEmployees
            ->filter(function ($person) use ($accountingDepartmentIds): bool {
                $personPosition = mb_strtolower((string) ($person['position'] ?? ''));
                $isAccountManager = (str_contains($personPosition, 'аккаунт') || str_contains($personPosition, 'account'))
                    && (str_contains($personPosition, 'менедж') || str_contains($personPosition, 'manager'));
                return in_array((int) ($person['department_id'] ?? 0), $accountingDepartmentIds, true) || $isAccountManager;
            })
            ->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $attemptDepartmentIds = array_map(fn ($row) => (int) $row['id'], $productionDirections);
        if (!in_array('platform-admin', $specialRoles, true)) $attemptDepartmentIds = array_values(array_intersect($attemptDepartmentIds, $productionDepartmentIds));
        $attemptEmployeeIds = $directoryEmployees
            ->filter(fn ($person) => in_array((int) ($person['department_id'] ?? 0), $attemptDepartmentIds, true))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $legacyDirectionIds = $departments->groupBy('name')
            ->filter(fn ($matches) => $matches->count() === 1)
            ->map(fn ($matches) => (int) $matches->first()['id'])->all();
        $inAccounting = collect($departmentNames)->contains(fn ($name) => $name === 'accounting' || str_contains($name, 'аккаунтинг') || str_contains($name, 'accounting'));
        $inSales = collect($departmentNames)->contains(fn ($name) => $name === 'sales' || str_contains($name, 'сейлз') || str_contains($name, 'sales'));
        $isHead = str_contains($position, 'руководител');
        $isAccountManager = (str_contains($position, 'аккаунт') || str_contains($position, 'account'))
            && (str_contains($position, 'менедж') || str_contains($position, 'manager'));
        if ($inAccounting || $isAccountManager) $roles[] = 'account-manager';
        if ($inSales) $roles[] = 'sales-manager';
        if (($inAccounting || $isAccountManager) && $isHead) $roles[] = 'accounting-head';
        if ($inSales && $isHead) $roles[] = 'sales-head';
        if ($isHead && ($ownDepartment === 'client service' || str_contains($position, 'клиентской служб'))) $roles[] = 'client-service-head';
        if ($managedProductionRoots) $roles[] = 'department-manager';
        $roles = array_values(array_unique($roles));
        $isClientServiceHead = count(array_intersect(['sales-head', 'accounting-head', 'client-service-head'], $roles)) > 0;
        $clientServiceEmployeeIds = $directoryEmployees
            ->filter(fn ($person) => in_array((int) ($person['department_id'] ?? 0), $clientServiceDepartmentIds, true))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $assignableClientServiceEmployeeIds = in_array('platform-admin', $roles, true)
            ? $clientServiceEmployeeIds
            : ($isClientServiceHead
                ? $directoryEmployees->filter(fn ($person) => in_array((int) ($person['department_id'] ?? 0), $departmentIds, true))->pluck('id')->map(fn ($id) => (int) $id)->push((int) ($employee['id'] ?? 0))->filter()->unique()->values()->all()
                : [(int) ($employee['id'] ?? 0)]);

        // Transfer is the one flow where a Sales user must be able to select an Account
        // outside their normal assignment scope. Other assignee selectors keep their usual scope.
        if (preg_match('#^api/clients/\d+/transfer$#', $request->path()) === 1) {
            $assignableClientServiceEmployeeIds = array_values(array_unique([...$assignableClientServiceEmployeeIds, ...$accountEmployeeIds]));
        }

        if (in_array('platform-admin', $roles, true)) {
            $permissions = [];
            foreach (array_keys(self::PERMISSION_LABELS) as $permission) $permissions[$permission] = ['allowed' => true, 'scope' => 'all'];
        } else {
            $permissions = [];
            $clientServicePermissions = [];
            $rank = ['none' => 0, 'own' => 1, 'team' => 2, 'all' => 3];
            $matrixRoles = in_array('manager', $specialRoles, true) ? [...$roles, 'department-manager'] : $roles;
            foreach (DB::table('client_contour_permissions')->whereIn('role', $matrixRoles)->where('allowed', true)->get() as $row) {
                if ($row->role === 'department-manager' && !$managedProductionRoots && !str_starts_with($row->permission, 'timesheets.')) continue;
                $current = $permissions[$row->permission]['scope'] ?? 'none';
                if (($rank[$row->scope] ?? 0) >= ($rank[$current] ?? 0)) $permissions[$row->permission] = ['allowed' => true, 'scope' => $row->scope];
                if ($row->role !== 'department-manager' || str_starts_with($row->permission, 'leads.') || str_starts_with($row->permission, 'requests.')) {
                    $current = $clientServicePermissions[$row->permission]['scope'] ?? 'none';
                    if (($rank[$row->scope] ?? 0) >= ($rank[$current] ?? 0)) $clientServicePermissions[$row->permission] = ['allowed' => true, 'scope' => $row->scope];
                }
            }
        }

        // Request/position/attempt permissions are independent from editing the client card.
        // For entity-scope checks, reuse the exact workflow manage scope on those mutation routes.
        if ($request->method() !== 'GET') {
            $workflowManagePermission = match (true) {
                preg_match('#^api/requests/\d+#', $request->path()) === 1 => 'requests.manage',
                preg_match('#^api/positions/\d+#', $request->path()) === 1 => 'positions.manage',
                preg_match('#^api/attempts/\d+#', $request->path()) === 1 => 'attempts.manage',
                default => null,
            };
            if ($workflowManagePermission && isset($permissions[$workflowManagePermission])) {
                $permissions['clients.manage'] = $permissions[$workflowManagePermission];
            }
        }

        if (!$managedProductionRoots && ($permissions['attempts.manage']['allowed'] ?? false)) {
            $attemptDepartmentIds = array_map(fn ($row) => (int) $row['id'], $productionDirections);
            $attemptEmployeeIds = $directoryEmployees
                ->filter(fn ($person) => in_array((int) ($person['department_id'] ?? 0), $attemptDepartmentIds, true))
                ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }

        return [
            'employee' => $employee,
            'roles' => $roles,
            'permissions' => $permissions,
            'department_ids' => $departmentIds,
            'production_department_ids' => $productionDepartmentIds,
            'production_department_names' => $productionDepartmentNames,
            'production_employee_ids' => $productionEmployeeIds,
            'attempt_employee_ids' => $attemptEmployeeIds,
            'employee_ids' => $directoryEmployees->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'team_employee_ids' => $directoryEmployees->filter(fn ($person) => in_array((int) ($person['department_id'] ?? 0), $departmentIds, true))->pluck('id')->map(fn ($id) => (int) $id)->push((int) ($employee['id'] ?? 0))->filter()->unique()->values()->all(),
            'client_service_department_ids' => $clientServiceDepartmentIds,
            'client_service_employee_ids' => $clientServiceEmployeeIds,
            'assignable_client_service_employee_ids' => $assignableClientServiceEmployeeIds,
            'account_employee_ids' => $accountEmployeeIds,
            'production_directions' => $productionDirections,
            'legacy_direction_ids' => $legacyDirectionIds,
            'client_service_permissions' => $clientServicePermissions ?? $permissions,
            'platform_admin' => in_array('platform-admin', $roles, true),
        ];
    }

    public function allows(array $access, string $permission): bool
    {
        return (bool) ($access['permissions'][$permission]['allowed'] ?? false);
    }

    public function scope(array $access, string $permission): string
    {
        return (string) ($access['permissions'][$permission]['scope'] ?? 'none');
    }
}
