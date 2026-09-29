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
        'department-manager' => 'Руководитель направления',
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
        abort_unless($employeeResponse->successful() && $accessResponse->successful() && $contextResponse->successful(), 503, 'Employees access service is unavailable');

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
        $roles = array_values(array_unique(array_merge(['employee'], $specialRoles)));
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
        if (in_array('manager', $specialRoles, true)) $roles[] = 'department-manager';
        $roles = array_values(array_unique($roles));

        if (in_array('platform-admin', $roles, true)) {
            $permissions = [];
            foreach (array_keys(self::PERMISSION_LABELS) as $permission) $permissions[$permission] = ['allowed' => true, 'scope' => 'all'];
        } else {
            $permissions = [];
            $rank = ['none' => 0, 'own' => 1, 'team' => 2, 'all' => 3];
            foreach (DB::table('client_contour_permissions')->whereIn('role', $roles)->where('allowed', true)->get() as $row) {
                $current = $permissions[$row->permission]['scope'] ?? 'none';
                if (($rank[$row->scope] ?? 0) >= ($rank[$current] ?? 0)) $permissions[$row->permission] = ['allowed' => true, 'scope' => $row->scope];
            }
        }

        return [
            'employee' => $employee,
            'roles' => $roles,
            'permissions' => $permissions,
            'department_ids' => $departmentIds,
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
