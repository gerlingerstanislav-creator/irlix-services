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

    private static function valid(string $alias): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $alias) === 1;
    }
}
