<?php

namespace App\Support;

/**
 * Organizational labels are about DIRECT membership and DIRECT management.
 * Parent departments grant a scope to their managers, not child labels.
 * Special roles are independent of the organization tree.
 */
final class RoleLabels
{
    public static function resolve(?object $employee, array $departments, array $specialRoles = []): array
    {
        if (!$employee) return [];
        // Automatic roles come only from current organizational facts.
        $labels = array_values(array_filter(array_map('strval', $specialRoles),
            static fn (string $role): bool => !SpecialRoles::isAutomatic($role)));
        if (self::managesProductionDirection($employee, $departments)) {
            $labels[] = SpecialRoles::ProductionDirectionHead;
        }
        $byId = [];
        foreach ($departments as $department) $byId[(int) $department->id] = $department;
        $own = $byId[(int) ($employee->department_id ?? 0)] ?? null;
        $functional = self::functionalClientRoles($employee, $departments);
        // Legacy client Accounting may have the alias "accounting". Never
        // publish that FINANCE label when it denotes account management.
        if ($own && self::valid((string) ($own->alias ?? ''))
            && !((string) $own->alias === 'accounting' && in_array('account-manager', $functional, true))) {
            $labels[] = (string) $own->alias;
        }

        foreach ($departments as $department) {
            $alias = (string) ($department->alias ?? '');
            if ((int) ($employee->id ?? 0) <= 0 || (int) ($department->manager_id ?? 0) <= 0
                || (int) $department->manager_id !== (int) $employee->id || !self::valid($alias)) continue;
            if ($alias === 'accounting' && in_array('account-manager',
                self::functionalClientRoles((object) ['department_id' => $department->id], $departments), true)) {
                $labels[] = 'account-management-head';
                continue;
            }
            $labels[] = $alias;
            $labels[] = $alias.'-head';
        }
        // Functional client roles are DERIVED from department ancestry,
        // unlike organizational JWT labels which remain direct-only.
        $labels = array_values(array_unique([...$labels, ...$functional]));
        sort($labels, SORT_STRING);
        return $labels;
    }

    /**
     * Canonical V2 identity roles published alongside legacy direct labels.
     *
     * "org:" means membership in the employee's own department OR one of its
     * ancestors, never management of that department. "head:" is assigned
     * only by an explicit manager_id relationship. "special:" requires an
     * explicit Employees special-role assignment, except the derived
     * production-direction-head role.
     *
     * These claims are deliberately separate from EmployeesAccess::roles:
     * publishing an ancestor MUST NOT implicitly change legacy permissions,
     * service admission, salaries or scope during the staged cutover.
     */
    public static function publishedRoleClaims(?object $employee, array $departments, array $specialRoles = []): array
    {
        if (!$employee) return [];

        $byId = [];
        foreach ($departments as $department) $byId[(int) $department->id] = $department;

        $claims = [];
        $visited = [];
        $id = (int) ($employee->department_id ?? 0);
        while ($id > 0 && isset($byId[$id]) && !isset($visited[$id])) {
            $visited[$id] = true;
            $department = $byId[$id];
            $alias = self::canonicalOrganizationAlias($department, $departments);
            if ($alias !== null) $claims[] = 'org:'.$alias;
            $id = (int) ($department->parent_id ?? 0);
        }

        foreach ($departments as $department) {
            if ((int) ($employee->id ?? 0) <= 0 || (int) ($department->manager_id ?? 0) <= 0
                || (int) $department->manager_id !== (int) $employee->id) continue;
            $alias = self::canonicalOrganizationAlias($department, $departments);
            if ($alias !== null) $claims[] = 'head:'.$alias;
        }

        foreach ($specialRoles as $role) {
            if (!is_string($role) || $role === SpecialRoles::PlatformTester
                || SpecialRoles::isAutomatic($role) || !SpecialRoles::exists($role)) continue;
            $claims[] = 'special:'.$role;
        }
        if (self::managesProductionDirection($employee, $departments)) {
            $claims[] = 'special:'.SpecialRoles::ProductionDirectionHead;
        }

        $claims = array_values(array_unique($claims));
        sort($claims, SORT_STRING);
        return $claims;
    }

    /**
     * Authoritative membership: direct management of a production-marked
     * department, never inherited subordinate management or a job title.
     * One employee may manage multiple production departments but gets one role.
     */
    public static function managesProductionDirection(?object $employee, array $departments): bool
    {
        $employeeId = (int) ($employee->id ?? 0);
        if ($employeeId <= 0) return false;
        foreach ($departments as $department) {
            if ((int) ($department->manager_id ?? 0) === $employeeId
                && (bool) ($department->is_production ?? false)) return true;
        }
        return false;
    }

    /**
     * The same V2 identity keys that Employees publishes in signed JWTs.
     * Role selectors in business services must never invent local aliases or
     * expose the retired platform-tester role as an authorization grant.
     */
    public static function canonicalRoleCatalog(array $departments): array
    {
        $catalog = [];
        foreach ($departments as $department) {
            $alias = self::canonicalOrganizationAlias($department, $departments);
            if ($alias === null) continue;
            $name = trim((string) ($department->name ?? $alias));
            $catalog['org:'.$alias] = 'Подразделение: '.$name;
            $catalog['head:'.$alias] = 'Руководитель: '.$name;
        }
        foreach (SpecialRoles::catalog() as $role => $definition) {
            if ($role === SpecialRoles::PlatformTester) continue;
            $catalog['special:'.$role] = $definition['label'];
        }
        asort($catalog, SORT_NATURAL | SORT_FLAG_CASE);
        return $catalog;
    }

    /**
     * Flat preorder of the live Employees organization tree for hierarchical
     * consumers such as Clients permission role pickers. The catalog and JWT
     * identity keys use the same canonical alias mapping, including the legacy
     * Client Service / Accounting disambiguation. Invalid/missing parents and
     * cycles cannot hide roles or make this traversal infinite.
     */
    public static function canonicalRoleTree(array $departments): array
    {
        $catalog = self::canonicalRoleCatalog($departments);
        $byId = [];
        foreach ($departments as $department) {
            $id = (int) ($department->id ?? 0);
            if ($id > 0) $byId[$id] = $department;
        }
        $children = [];
        $roots = [];
        foreach ($byId as $id => $department) {
            $parent = (int) ($department->parent_id ?? 0);
            if ($parent > 0 && $parent !== $id && isset($byId[$parent])) {
                $children[$parent][] = $id;
            } else {
                $roots[] = $id;
            }
        }
        $sort = static function (array $ids) use ($byId): array {
            usort($ids, static function (int $a, int $b) use ($byId): int {
                $left = (string) ($byId[$a]->name ?? $byId[$a]->alias ?? '');
                $right = (string) ($byId[$b]->name ?? $byId[$b]->alias ?? '');
                return strnatcasecmp($left, $right) ?: ($a <=> $b);
            });
            return $ids;
        };

        $tree = [];
        $visited = [];
        $published = [];
        $walk = function (int $id, int $depth) use (
            &$walk, &$tree, &$visited, &$published, $byId, $children, $sort, $catalog, $departments
        ): void {
            if (isset($visited[$id])) return;
            $visited[$id] = true;
            $department = $byId[$id];
            $alias = self::canonicalOrganizationAlias($department, $departments);
            $name = trim((string) ($department->name ?? ''));
            $tree[] = [
                'kind' => 'group',
                'key' => 'department:'.$id,
                'label' => $name !== '' ? $name : ($alias ?? 'Подразделение #'.$id),
                'depth' => $depth,
            ];
            if ($alias !== null) {
                foreach (['org:'.$alias, 'head:'.$alias] as $role) {
                    if (!isset($catalog[$role]) || isset($published[$role])) continue;
                    $published[$role] = true;
                    $tree[] = [
                        'kind' => 'role', 'key' => $role,
                        'label' => $catalog[$role], 'depth' => $depth + 1,
                    ];
                }
            }
            foreach ($sort($children[$id] ?? []) as $childId) $walk($childId, $depth + 1);
        };

        foreach ($sort($roots) as $id) $walk($id, 0);
        // A malformed cyclic ancestry has no root. Keep it visible, but
        // never derive any extra authorization from its visual position.
        foreach ($sort(array_keys($byId)) as $id) $walk($id, 0);

        $other = array_diff_key($catalog, $published);
        if ($other !== []) {
            $tree[] = ['kind' => 'group', 'key' => 'other', 'label' => 'Прочее', 'depth' => 0];
            foreach ($other as $role => $label) {
                $tree[] = ['kind' => 'role', 'key' => $role, 'label' => $label, 'depth' => 1];
            }
        }
        return $tree;
    }

    private static function canonicalOrganizationAlias(object $department, array $departments): ?string
    {
        $alias = (string) ($department->alias ?? '');
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $alias)) return null;

        // Legacy Client Service / Accounting is account management, not
        // Finance Accounting. Do not publish an ambiguous finance identity.
        if ($alias === 'accounting' && in_array('account-manager',
            self::functionalClientRoles((object) ['department_id' => $department->id], $departments), true)) {
            return 'account-management';
        }
        return $alias;
    }

    /**
     * Service admission is inherited from the client-service organizational
     * subtree. JWT organizational labels stay direct-only; this lookup is used
     * by Employees when it publishes the service:clients entitlement.
     */
    public static function belongsToClientContour(?object $employee, array $departments): bool
    {
        $id = (int) ($employee->department_id ?? 0);
        if ($id <= 0) return false;
        $byId = [];
        foreach ($departments as $department) $byId[(int) $department->id] = $department;
        $visited = [];
        while ($id > 0 && isset($byId[$id]) && !isset($visited[$id])) {
            $visited[$id] = true;
            $department = $byId[$id];
            $alias = (string) ($department->alias ?? '');
            $name = mb_strtolower(trim((string) ($department->name ?? '')));
            if (in_array($alias, ['client-service', 'account-management', 'sales'], true)
                || ($alias === '' && in_array($name, ['client service', 'клиентская служба'], true))) {
                return true;
            }
            $id = (int) ($department->parent_id ?? 0);
        }
        return false;
    }

    /**
     * Organizational ancestors provide BASE permission rules, not extra JWT
     * roles and never manager or special-role privileges.
     */
    public static function ancestorDepartmentRoles(?object $employee, array $departments): array
    {
        $byId = [];
        foreach ($departments as $department) $byId[(int) $department->id] = $department;
        $current = (int) ($employee->department_id ?? 0);
        $id = (int) ($byId[$current]->parent_id ?? 0);
        $roles = [];
        $visited = [$current => true];
        while ($id > 0 && isset($byId[$id]) && !isset($visited[$id])) {
            $visited[$id] = true;
            $alias = (string) ($byId[$id]->alias ?? '');
            // Old Client Service / Accounting aliases must not inherit the
            // Finance role matrix just because they share the word Accounting.
            if ($alias === 'accounting' && in_array('account-manager',
                self::functionalClientRoles((object) ['department_id' => $id], $departments), true)) {
                $alias = 'account-management';
            }
            if (self::valid($alias)) $roles[] = $alias;
            $id = (int) ($byId[$id]->parent_id ?? 0);
        }
        return array_values(array_unique($roles));
    }

    /** Derive matrix roles without inheriting every organizational ancestor. */
    public static function functionalClientRoles(?object $employee, array $departments): array
    {
        $id = (int) ($employee->department_id ?? 0);
        if ($id <= 0) return [];
        $byId = [];
        foreach ($departments as $department) $byId[(int) $department->id] = $department;
        $chain = [];
        $visited = [];
        while ($id > 0 && isset($byId[$id]) && !isset($visited[$id])) {
            $visited[$id] = true;
            $chain[] = $byId[$id];
            $id = (int) ($byId[$id]->parent_id ?? 0);
        }
        $aliases = array_map(static fn ($department) => (string) ($department->alias ?? ''), $chain);
        $names = array_map(static fn ($department) => mb_strtolower(trim((string) ($department->name ?? ''))), $chain);
        $inClientService = in_array('client-service', $aliases, true)
            || in_array('client service', $names, true)
            || in_array('клиентская служба', $names, true);
        // A functional Account Manager can hold a position directly inside
        // Client Service without being assigned to an account-management
        // department. Only exact, normalized position names within this
        // organizational contour are eligible; a Finance position is not.
        $position = mb_strtolower(trim(preg_replace('/\\s+/u', ' ',
            str_replace(['_', '–', '—'], ['-', '-', '-'], (string) ($employee->position ?? '')))));
        $accountPosition = $inClientService && in_array($position, [
            'account-manager', 'account manager', 'аккаунт-менеджер', 'аккаунт менеджер',
        ], true);
        $accounting = in_array('account-management', $aliases, true)
            || ($inClientService && count(array_intersect($names, ['accounting', 'аккаунтинг'])) > 0)
            || $accountPosition;
        $salesPosition = $inClientService && in_array($position, [
            'sales-manager', 'sales manager', 'сейлз-менеджер', 'сейлз менеджер',
            'менеджер по продажам', 'менеджер продаж',
        ], true);
        $sales = in_array('sales', $aliases, true)
            || ($inClientService && count(array_intersect($names, ['sales', 'продажи', 'сейлз'])) > 0)
            || $salesPosition;
        return array_values(array_filter([
            $accounting ? 'account-manager' : null,
            $sales ? 'sales-manager' : null,
        ]));
    }

    private static function valid(string $alias): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $alias) === 1
            && !in_array($alias, SpecialRoles::keys(), true)
            && !str_ends_with($alias, '-head')
            && !in_array($alias, ['manager', 'finance', 'finance-director'], true);
    }
}
