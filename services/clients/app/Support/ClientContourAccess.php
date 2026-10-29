<?php

namespace App\Support;

use Illuminate\Http\Request;
use App\Models\ClientContourPermission;
use Illuminate\Support\Facades\Http;

class ClientContourAccess
{
    public const PERMISSION_LABELS = [
        'clients.view' => 'Клиенты: просмотр страницы', 'clients.manage' => 'Клиенты: изменение',
        'contacts.view' => 'Контактные лица: просмотр', 'contacts.manage' => 'Контактные лица: изменение',
        'members.view' => 'Участники проектов: просмотр', 'members.manage' => 'Участники проектов: изменение',
        'specialists.view' => 'Специалисты: общий календарь (вся компания)',
        'requests.view' => 'Запросы: просмотр', 'requests.manage' => 'Запросы: изменение',
        'positions.view' => 'Позиции: просмотр', 'positions.manage' => 'Позиции: изменение',
        'attempts.view' => 'Попытки: просмотр', 'attempts.manage' => 'Попытки: изменение',
        'attempts.analytics.view' => 'Воронка попыток: просмотр',
        'leads.view' => 'Лиды: просмотр', 'leads.manage' => 'Лиды: изменение',
        'reports.view' => 'Отчётные периоды: просмотр', 'reports.manage' => 'Отчётные периоды: изменение',
        'cashflow.view' => 'ДДС: просмотр',
        'permissions.view' => 'Настройки разрешений: просмотр',
        'permissions.manage' => 'Настройки разрешений: изменение',
    ];

    /**
     * Clients retains its own historical matrix role "accounting-head" for
     * account-management leadership. The identical organizational JWT label
     * means FINANCE accounting leadership and must never be imported as a
     * Clients matrix grant. Only a verified account-management-head label
     * is translated into the local legacy matrix role below.
     */
    public static function importedMatrixLabels(array $roles): array
    {
        return array_values(array_filter($roles, static fn ($role) =>
            is_string($role)
            && !str_starts_with($role, 'service:')
            && !str_starts_with($role, 'scope:')
            && !str_starts_with($role, 'employee-id:')
            && !str_starts_with($role, 'permission:')
            // The new signed identity labels must not become implicit
            // legacy Clients matrix grants before its own role cutover.
            && !str_starts_with($role, 'org:')
            && !str_starts_with($role, 'head:')
            && !str_starts_with($role, 'special:')
            && !in_array($role, ['accounting', 'accounting-head',
                'account-manager', 'sales-manager', 'department-manager', 'manager'], true)
        ));
    }

    /**
     * Filter signed V2 identity labels against CURRENT Employees directory
     * facts. An old token must not keep department/head grants after a move.
     * Explicit special roles are still bound to token TTL and require the
     * separate emergency-revocation control before production cutover.
     *
     * Legacy Client Service / Accounting maps to account-management only
     * when its ancestry includes client-service; Finance Accounting stays
     * accounting. No free-text job title is used.
     */
    private static function canonicalDepartmentAlias(array $department, array $departments): string
    {
        $alias = (string) ($department['alias'] ?? '');
        if ($alias !== 'accounting') return $alias;
        $byId = [];
        foreach ($departments as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) $byId[$id] = $row;
        }
        $id = (int) ($department['id'] ?? 0);
        $visited = [];
        while ($id > 0 && isset($byId[$id]) && !isset($visited[$id])) {
            $visited[$id] = true;
            if (($byId[$id]['alias'] ?? '') === 'client-service') return 'account-management';
            $id = (int) ($byId[$id]['parent_id'] ?? 0);
        }
        return $alias;
    }

    /** Deny a stale privileged JWT, including any old global scope grants. */
    public static function currentSpecialsMatch(array $jwt, int $employeeId, array $live): bool
    {
        if ($employeeId <= 0 || (int) ($live['employee_id'] ?? 0) !== $employeeId
            || !is_array($live['roles'] ?? null)) return false;
        foreach ($jwt as $role) {
            if (!is_string($role)) continue;
            if (str_starts_with($role, 'special:')) $special = substr($role, 8);
            elseif ($role === 'platform-admin') $special = 'platform-admin';
            else continue;
            if (!in_array($special, $live['roles'], true)) return false;
        }
        return true;
    }

    public static function currentIdentityLabels(
        array $signed, int $actorId, array $departments, array $employees
    ): array {
        if ($actorId <= 0) return [];
        $actor = null;
        foreach ($employees as $employee) {
            if ((int) ($employee['id'] ?? 0) === $actorId) { $actor = $employee; break; }
        }
        if (!$actor) return [];
        $byId = [];
        foreach ($departments as $d) {
            if ((int) ($d['id'] ?? 0) > 0) $byId[(int) $d['id']] = $d;
        }

        $valid = [];
        $id = (int) ($actor['department_id'] ?? 0);
        $seen = [];
        while ($id > 0 && isset($byId[$id]) && !isset($seen[$id])) {
            $seen[$id] = true;
            $alias = self::canonicalDepartmentAlias($byId[$id], $departments);
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $alias)) $valid['org:'.$alias] = true;
            $id = (int) ($byId[$id]['parent_id'] ?? 0);
        }
        foreach ($departments as $d) {
            if ((int) ($d['manager_id'] ?? 0) !== $actorId) continue;
            $alias = self::canonicalDepartmentAlias($d, $departments);
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $alias)) $valid['head:'.$alias] = true;
        }
        $out = [];
        foreach ($signed as $role) {
            if (!is_string($role) ||
                preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role) !== 1) continue;
            if (str_starts_with($role, 'special:') || isset($valid[$role])) $out[$role] = true;
        }
        return array_keys($out);
    }

    public function resolve(Request $request): array
    {
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
        $http = Http::withToken((string) $token)->acceptJson()
            ->withHeaders($request->header('X-Irlix-Role-Preview') ? ['X-Irlix-Role-Preview' => $request->header('X-Irlix-Role-Preview')] : [])->timeout(8);
        $jwtMode = filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        $localRoleMode = $jwtMode && filter_var(env('IRLIX_CLIENTS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        // JWT roles are cryptographically verified by KeycloakBearer before
        // this resolver runs. Do not fetch /access/me for authorization in JWT mode.
        $jwtLabels = (array) ($request->attributes->get('identity', [])['realm_roles'] ?? []);
        $identityIds = array_values(array_filter($jwtLabels,
            fn ($role) => is_string($role) && preg_match('/^employee-id:[1-9][0-9]*$/D', $role) === 1));
        if ($jwtMode && count($identityIds) !== 1) abort(403, 'Verified employee identity is missing or ambiguous');
        if ($localRoleMode && array_filter($jwtLabels, static fn ($role) =>
            is_string($role) && ($role === 'platform-admin' || str_starts_with($role, 'special:')))) {
            // This is a privileged *identity* freshness check only. Clients
            // permissions still come exclusively from the local matrix.
            // Never forward role-preview headers into the real assignment check.
            if ($request->header('X-Irlix-Role-Preview') !== null) {
                abort(403, 'Role preview cannot authorize privileged Clients access');
            }
            try {
                $live = Http::withToken((string) $token)->acceptJson()
                    ->timeout(5)->get($base.'/access/me');
            } catch (\Throwable) {
                abort(503, 'Current role assignments unavailable');
            }
            abort_unless($live->successful() && is_array($live->json('data')), 503,
                'Current role assignments unavailable');
            abort_unless(self::currentSpecialsMatch($jwtLabels,
                (int) substr($identityIds[0], strlen('employee-id:')),
                (array) $live->json('data')), 403, 'Privileged identity roles were revoked');
        }
        $employeeResponse = $jwtMode ? null : $http->get($base.'/self');
        $directoryResponse = $http->get($base.'/clients-directory');
        if ($directoryResponse->successful()) {
            $request->attributes->set('clients_employees_directory', (array) $directoryResponse->json('data', []));
        }
        $accessResponse = $jwtMode ? null : $http->get($base.'/access/me');
        $contextResponse = $jwtMode ? null : $http->get($base.'/self/absence-approval-context');
        abort_unless($directoryResponse->successful()
            && ($jwtMode || ($employeeResponse->successful() && $accessResponse->successful() && $contextResponse->successful())),
            503, 'Employees directory is unavailable');

        $employee = $jwtMode
            ? ['id' => (int) substr($identityIds[0], strlen('employee-id:'))]
            : (array) $employeeResponse->json('data', []);
        // A signed Keycloak role is a stable authorization key. Normalizing
        // arbitrary realm group names (e.g. platform_admin → platform-admin)
        // would let an unrelated group escalate privileges.
        $specialRoles = $jwtMode
            ? array_values(array_filter($jwtLabels, 'is_string'))
            : array_values(array_map(
                fn ($role) => str_replace('_', '-', mb_strtolower(trim((string) $role))),
                $accessResponse->json('data.roles', [])
            ));
        // Legacy platform-tester tokens never carry administrative power in
        // business authorization, even before their Keycloak grants expire.
        $specialRoles = array_values(array_diff($specialRoles, ['platform-tester']));
        $departments = collect($directoryResponse->json('data.departments', []));
        $directoryEmployees = collect($directoryResponse->json('data.employees', []));
        $departmentIds = $localRoleMode
            ? $departments->filter(fn ($department) =>
                (int) ($department['manager_id'] ?? 0) > 0
                && (int) ($department['manager_id'] ?? 0) === (int) ($employee['id'] ?? 0)
                && in_array('head:'.self::canonicalDepartmentAlias($department, $departments->all()), $specialRoles, true))
                ->pluck('id')->map(fn ($id) => (int) $id)->all()
            : ($jwtMode
                ? array_values(array_map(fn ($role) => (int) substr($role, strlen('scope:department-')),
                    array_filter($specialRoles, fn ($role) => preg_match('/^scope:department-[1-9][0-9]*$/D', $role))))
                : array_values(array_unique(array_map('intval', $accessResponse->json('data.department_ids', [])))));
        if ($jwtMode && !$localRoleMode && in_array('scope:all', $specialRoles, true))
            $departmentIds = $departments->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($localRoleMode && $departmentIds !== []) {
            // A head scope covers the current managed subtree, not just the
            // department's root row. Use stable IDs and terminate on cycles.
            do {
                $before = count($departmentIds);
                foreach ($departments as $department) {
                    $parentId = (int) ($department['parent_id'] ?? 0);
                    $childId = (int) ($department['id'] ?? 0);
                    if ($childId > 0 && in_array($parentId, $departmentIds, true)
                        && !in_array($childId, $departmentIds, true)) $departmentIds[] = $childId;
                }
            } while (count($departmentIds) !== $before);
        }
        $chain = collect($jwtMode ? [] : $contextResponse->json('data.department_chain', []));
        $departmentAliases = $jwtMode
            ? $specialRoles
            : $chain->map(fn ($d) => (string) ($d['alias'] ?? ''))->all();
        // Functional client roles are assigned by Employees and signed into
        // Keycloak JWTs. Unlike arbitrary imported labels, these verified
        // assignments are authoritative in JWT mode. Preserve the legacy
        // membership-based derivation for existing accounts during rollout.
        $jwtFunctionalRoles = $jwtMode
            ? array_values(array_intersect($specialRoles, ['account-manager', 'sales-manager']))
            : [];
        $roles = array_values(array_unique(array_merge(
            ['employee'], self::importedMatrixLabels($specialRoles), $jwtFunctionalRoles
        )));
        // New-only Keycloak tokens contain special:platform-admin rather than
        // the retired unprefixed platform-admin role. Keep the internal
        // superuser capability independent of any legacy realm role.
        if ($localRoleMode && in_array('special:platform-admin', $jwtLabels, true)) {
            $roles[] = 'platform-admin';
        }
        $managedProductionRoots = $departments
            ->filter(fn ($department) => (bool) ($department['is_production'] ?? false)
                && ($localRoleMode
                    ? ((int) ($department['manager_id'] ?? 0) > 0
                        && (int) $department['manager_id'] === (int) ($employee['id'] ?? 0)
                        && in_array('head:'.self::canonicalDepartmentAlias($department, $departments->all()), $specialRoles, true))
                    : ($jwtMode
                        ? in_array((string) ($department['alias'] ?? '').'-head', $specialRoles, true)
                        : (int) ($department['manager_id'] ?? 0) === (int) ($employee['id'] ?? 0))))
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
        $clientServiceRoots = $departments->filter(fn ($department) =>
            ($department['alias'] ?? '') === 'client-service'
            || (($department['alias'] ?? '') === ''
                && in_array(mb_strtolower(trim((string) ($department['name'] ?? ''))),
                    ['client service', 'клиентская служба'], true)))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $clientServiceDepartmentIds = $clientServiceRoots;
        do {
            $previousCount = count($clientServiceDepartmentIds);
            foreach ($departments as $department) {
                $parentId = (int) ($department['parent_id'] ?? 0);
                $departmentId = (int) ($department['id'] ?? 0);
                if ($departmentId && in_array($parentId, $clientServiceDepartmentIds, true) && !in_array($departmentId, $clientServiceDepartmentIds, true)) $clientServiceDepartmentIds[] = $departmentId;
            }
        } while (count($clientServiceDepartmentIds) !== $previousCount);
        // Legacy organizations may call this department Accounting or
        // Аккаунтинг, without the newer account-management alias. Restrict
        // name-based recognition to the client-service tree: finance Accounting
        // is a different, non-client role.
        $accountingRoots = $departments->filter(fn ($department) =>
            ($department['alias'] ?? '') === 'account-management'
            || (in_array((int) ($department['parent_id'] ?? 0), $clientServiceDepartmentIds, true)
                && in_array(mb_strtolower(trim((string) ($department['name'] ?? ''))),
                    ['accounting', 'аккаунтинг'], true)))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $accountingDepartmentIds = $accountingRoots;
        do {
            $previousCount = count($accountingDepartmentIds);
            foreach ($departments as $department) {
                $parentId = (int) ($department['parent_id'] ?? 0);
                $departmentId = (int) ($department['id'] ?? 0);
                if ($departmentId && in_array($parentId, $accountingDepartmentIds, true) && !in_array($departmentId, $accountingDepartmentIds, true)) $accountingDepartmentIds[] = $departmentId;
            }
        } while (count($accountingDepartmentIds) !== $previousCount);
        $salesRoots = $departments->filter(fn ($department) =>
            ($department['alias'] ?? '') === 'sales'
            || (in_array((int) ($department['parent_id'] ?? 0), $clientServiceDepartmentIds, true)
                && in_array(mb_strtolower(trim((string) ($department['name'] ?? ''))),
                    ['sales', 'продажи'], true)))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $salesDepartmentIds = $salesRoots;
        do {
            $previousCount = count($salesDepartmentIds);
            foreach ($departments as $department) {
                $parentId = (int) ($department['parent_id'] ?? 0);
                $departmentId = (int) ($department['id'] ?? 0);
                if ($departmentId && in_array($parentId, $salesDepartmentIds, true) && !in_array($departmentId, $salesDepartmentIds, true)) $salesDepartmentIds[] = $departmentId;
            }
        } while (count($salesDepartmentIds) !== $previousCount);
        $accountEmployeeIds = $directoryEmployees
            ->filter(fn ($person) => in_array((int) ($person['department_id'] ?? 0), $accountingDepartmentIds, true))
            ->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $attemptDepartmentIds = array_map(fn ($row) => (int) $row['id'], $productionDirections);
        if (count(array_intersect(['platform-admin'], $specialRoles)) === 0) $attemptDepartmentIds = array_values(array_intersect($attemptDepartmentIds, $productionDepartmentIds));
        $attemptEmployeeIds = $directoryEmployees
            ->filter(fn ($person) => in_array((int) ($person['department_id'] ?? 0), $attemptDepartmentIds, true))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $legacyDirectionIds = $departments->groupBy('name')
            ->filter(fn ($matches) => $matches->count() === 1)
            ->map(fn ($matches) => (int) $matches->first()['id'])->all();
        // Resolve a verified actor's current department through trusted
        // Employees directory data. JWT labels remain direct-only, so they do
        // not describe membership inherited from parent departments.
        $actorDirectory = $directoryEmployees->first(fn ($person) =>
            (int) ($person['id'] ?? 0) === (int) ($employee['id'] ?? 0));
        $actorDepartmentId = (int) ($actorDirectory['department_id'] ?? 0);
        // Employees owns functional role derivation. Its authenticated
        // clients-directory actor projection is tied to the signed employee id:
        // do not derive or grant Client permissions from arbitrary JWT strings,
        // client-supplied job titles, or duplicated local position mappings.
        $directoryActor = (array) $directoryResponse->json('data.actor', []);
        $verifiedFunctionalRoles = (int) ($directoryActor['id'] ?? 0) === (int) ($employee['id'] ?? 0)
            && (int) ($directoryActor['department_id'] ?? 0) === $actorDepartmentId
            ? array_values(array_intersect((array) ($directoryActor['functional_roles'] ?? []),
                ['account-manager', 'sales-manager']))
            : [];
        $inAccounting = in_array('account-management', $departmentAliases, true)
            || in_array('account-manager', $jwtFunctionalRoles, true)
            || in_array('account-manager', $verifiedFunctionalRoles, true)
            || ($actorDepartmentId > 0 && in_array($actorDepartmentId, $accountingDepartmentIds, true));
        $inSales = in_array('sales', $departmentAliases, true)
            || in_array('sales-manager', $jwtFunctionalRoles, true)
            || in_array('sales-manager', $verifiedFunctionalRoles, true)
            || ($actorDepartmentId > 0 && in_array($actorDepartmentId, $salesDepartmentIds, true));
        $managedDepartmentAliases = $localRoleMode
            ? array_values(array_map(static fn ($role) => substr($role, 5),
                array_filter(self::currentIdentityLabels($jwtLabels, (int) ($employee['id'] ?? 0),
                    $departments->all(), $directoryEmployees->all()),
                    static fn ($role) => str_starts_with($role, 'head:'))))
            : ($jwtMode
                ? array_values(array_map(fn ($role) => substr($role, 0, -5),
                    array_filter($specialRoles, fn ($role) => str_ends_with($role, '-head'))))
                : $departments->filter(fn ($d) => (int) ($d['manager_id'] ?? 0) === (int) ($employee['id'] ?? 0))
                    ->pluck('alias')->filter()->values()->all());
        // Manager assignment is verified against the current organizational
        // tree. Exact department IDs also support legacy alias/name variants,
        // unlike brittle hard-coded -head label comparisons. Scope comes from
        // Employees' managed department ids; a job title never creates it.
        $managedRoots = $departments->filter(fn ($d) =>
            (int) ($employee['id'] ?? 0) > 0
            && (int) ($d['manager_id'] ?? 0) === (int) ($employee['id'] ?? 0)
            && (!$localRoleMode || in_array('head:'.self::canonicalDepartmentAlias($d, $departments->all()), $specialRoles, true)))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $isAccountHead = in_array('account-management', $managedDepartmentAliases, true)
            || (bool) array_intersect($managedRoots, $accountingRoots);
        $isSalesHead = in_array('sales', $managedDepartmentAliases, true)
            || (bool) array_intersect($managedRoots, $salesRoots);
        $isClientServiceManager = in_array('client-service', $managedDepartmentAliases, true)
            || (bool) array_intersect($managedRoots, $clientServiceRoots);
        // Every member of this subtree inherits the baseline client-service
        // matrix role, not the client-service-head management role.
        if (($actorDepartmentId > 0 && in_array($actorDepartmentId, $clientServiceDepartmentIds, true))
            || in_array('client-service', $departmentAliases, true)
            || $inAccounting || $inSales) {
            $roles[] = 'client-service';
        }
        if ($inAccounting) $roles[] = 'account-manager';
        if ($inSales) $roles[] = 'sales-manager';
        if ($isAccountHead) $roles[] = 'accounting-head';
        if ($isSalesHead) $roles[] = 'sales-head';
        if ($isClientServiceManager) $roles[] = 'client-service-head';
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
        if (preg_match('#^api/clients/\d+/(?:transfer|reassign|delegations)$#', $request->path()) === 1) {
            $assignableClientServiceEmployeeIds = array_values(array_unique([...$assignableClientServiceEmployeeIds, ...$accountEmployeeIds]));
        }

        if (in_array('platform-admin', $roles, true)) {
            $permissions = [];
            foreach (array_keys(self::PERMISSION_LABELS) as $permission) $permissions[$permission] = ['allowed' => true, 'scope' => 'all'];
        } else {
            $permissions = [];
            $clientServicePermissions = [];
            $rank = ['none' => 0, 'own' => 1, 'team' => 2, 'all' => 3];
            // Only signed canonical identity labels select Clients' persisted
            // V2 rows. Old role aliases are never used for grant selection.
            $matrixRoles = $localRoleMode
                ? self::currentIdentityLabels(
                    $jwtLabels, (int) ($employee['id'] ?? 0),
                    $departments->all(), $directoryEmployees->all()
                )
                : $roles;
            $explicitDenies = [];
            foreach (ClientContourPermission::query()->whereIn('role', $matrixRoles)->get() as $row) {
                // A default false row does not deny grants from another role.
                // Only the administrator's opt-in "denied" flag vetoes them.
                if ((bool) ($row->denied ?? false)) {
                    $explicitDenies[$row->permission] = true;
                    continue;
                }
                if (!(bool) $row->allowed) continue;
                $current = $permissions[$row->permission]['scope'] ?? 'none';
                if (($rank[$row->scope] ?? 0) >= ($rank[$current] ?? 0)) $permissions[$row->permission] = ['allowed' => true, 'scope' => $row->scope];
                {
                    $current = $clientServicePermissions[$row->permission]['scope'] ?? 'none';
                    if (($rank[$row->scope] ?? 0) >= ($rank[$current] ?? 0)) $clientServicePermissions[$row->permission] = ['allowed' => true, 'scope' => $row->scope];
                }
            }
            foreach (array_keys($explicitDenies) as $permission) {
                unset($permissions[$permission], $clientServicePermissions[$permission]);
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

        // Route-specific workflow mapping is only an implementation detail:
        // it must never resurrect a permission that an administrator explicitly
        // denied to one of the actor's effective matrix roles.
        if (!in_array('platform-admin', $roles, true)) {
            foreach (array_keys($explicitDenies) as $permission) {
                unset($permissions[$permission], $clientServicePermissions[$permission]);
            }
        }

        if (!$managedProductionRoots && ($permissions['attempts.manage']['allowed'] ?? false)) {
            $attemptDepartmentIds = array_map(fn ($row) => (int) $row['id'], $productionDirections);
            $attemptEmployeeIds = $directoryEmployees
                ->filter(fn ($person) => in_array((int) ($person['department_id'] ?? 0), $attemptDepartmentIds, true))
                ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }

        $clientsServiceBlocked = false;

        // Only /permissions/me is consumed by Timesheets for legacy
        // upper-bound checks. Keep the distinction between an ABSENT old
        // employee row and an explicit/unassigned or denied old row; the
        // effective permission projection by itself loses that distinction.
        $implicitPersonalTimesheetsView = false;
        if ($request->is('api/permissions/me') && !in_array('platform-admin', $roles, true)) {
            $employeeViewRowExists = ClientContourPermission::query()
                ->where('role', 'employee')->where('permission', 'timesheets.mine.view')
                ->exists();
            // Check the historical explicit veto using trusted Employees-derived
            // functional roles, not V2 matrix roles. During Clients V2 mode
            // the latter are namespaced and would silently miss old vetoes.
            $legacyMatrixRoles = in_array('manager', $specialRoles, true)
                ? [...$roles, 'department-manager'] : $roles;
            $legacyExplicitDeny = ClientContourPermission::query()
                ->where('permission', 'timesheets.mine.view')
                ->where('denied', true)
                ->whereIn('role', $legacyMatrixRoles)
                ->exists();
            $implicitPersonalTimesheetsView = self::historicalPersonalTimesheetsViewWasImplicit(
                $employeeViewRowExists, $legacyExplicitDeny
            );
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
            'legacy_timesheets_implicit_mine_view' => $implicitPersonalTimesheetsView,
            'platform_admin' => in_array('platform-admin', $roles, true),
            'clients_service_blocked' => $clientsServiceBlocked,
        ];
    }

    /**
     * The historical Timesheets personal workspace was admitted by Employees
     * service entry, even when Clients had no employee/mine.view matrix row.
     * This is only evidence for the Timesheets consumer to preserve OWN scope:
     * it is never a Clients grant and cannot override an explicit legacy veto.
     */
    public static function historicalPersonalTimesheetsViewWasImplicit(
        bool $employeeRuleExists, bool $explicitDeny
    ): bool {
        return !$employeeRuleExists && !$explicitDeny;
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
