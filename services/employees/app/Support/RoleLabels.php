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
        $labels = array_values(array_filter(array_map('strval', $specialRoles)));
        $byId = [];
        foreach ($departments as $department) $byId[(int) $department->id] = $department;
        $own = $byId[(int) ($employee->department_id ?? 0)] ?? null;
        if ($own && self::valid((string) ($own->alias ?? ''))) $labels[] = (string) $own->alias;

        foreach ($departments as $department) {
            $alias = (string) ($department->alias ?? '');
            if ((int) ($department->manager_id ?? 0) !== (int) $employee->id || !self::valid($alias)) continue;
            $labels[] = $alias;
            $labels[] = $alias.'-head';
        }

        $labels = array_values(array_unique($labels));
        sort($labels, SORT_STRING);
        return $labels;
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

    private static function valid(string $alias): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $alias) === 1
            && !in_array($alias, SpecialRoles::keys(), true)
            && !str_ends_with($alias, '-head')
            && !in_array($alias, ['manager', 'finance', 'finance-director'], true);
    }
}
