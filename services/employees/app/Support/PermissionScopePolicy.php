<?php

namespace App\Support;

/**
 * Department-bound Employees operations have a scope independent from their
 * boolean permission grant. A user's broad HR/personnel visibility must never
 * silently widen salary rights granted by a different (manager) role.
 *
 * "own" is relative to a role assignment, NOT the employee's home department:
 * managers use managed subtrees and direction HR uses assigned HR subtrees.
 */
final class PermissionScopePolicy
{
    private const SCOPED = [
        'employees.read',
        'employees.application.create',
        'employees.hire',
        'employees.manage',
        'employees.salary.read',
        'employees.salary.manage',
        'employees.fot.view',
        'employees.fot.plan',
        'employees.transfer',
    ];

    public static function scoped(string $permission): bool
    {
        return in_array($permission, self::SCOPED, true);
    }

    public static function keys(): array
    {
        return self::SCOPED;
    }

    public static function defaultForRole(string $role): string
    {
        // Default to fail-closed for unrelated/legacy department roles.
        // Only established global Employees audiences get "all" by default.
        // A new explicit scope=all is necessary to expand any other role.
        return in_array($role, [
            SpecialRoles::PlatformAdmin, SpecialRoles::PersonnelOfficer, 'hr', 'accounting',
        ], true) ? 'all' : 'own';
    }

    /** Only roles with a concrete ownership relationship may select "own". */
    public static function options(string $role, string $permission): array
    {
        if (!self::scoped($permission)) return ['deny', 'allow'];
        if ($role === SpecialRoles::Managers || $role === SpecialRoles::DirectionHr) {
            return ['deny', 'own', 'all'];
        }
        return ['deny', 'all'];
    }

    /** The legacy persisted enabled=true record has no scope; use role default. */
    public static function savedValue(string $role, string $permission, bool $enabled, ?string $scope = null): string
    {
        if (!$enabled) return 'deny';
        if (!self::scoped($permission)) return 'allow';
        return $scope ?: self::defaultForRole($role);
    }

    /**
     * Merge independently granted role scopes for ONE permission.
     * A grant of "all" contributes all; otherwise union only actual role
     * department IDs. Callers must apply boolean/explicit-deny semantics first.
     *
     * @param array<string, array{enabled: bool, scope?: ?string, department_ids?: int[]}> $grants
     */
    public static function merge(array $grants): array
    {
        $sources = [];
        $ids = [];
        $all = false;
        foreach ($grants as $role => $grant) {
            if (!$grant['enabled']) continue;
            $mode = $grant['scope'] ?: self::defaultForRole($role);
            $roleIds = array_values(array_unique(array_map('intval', $grant['department_ids'] ?? [])));
            if ($mode === 'own' && !$roleIds) continue; // Missing assignment never becomes company-wide.
            if ($mode === 'all') $all = true;
            else $ids = array_merge($ids, $roleIds);
            $sources[] = [
                'role' => $role,
                'scope' => $mode,
                'department_ids' => $mode === 'own' ? $roleIds : [],
            ];
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return [
            'scope' => $all ? 'all' : ($ids ? 'subtree' : 'none'),
            'department_ids' => $all ? [] : $ids,
            'sources' => $sources,
        ];
    }

    public static function intersect(array $first, array $second): array
    {
        if (($first['scope'] ?? 'none') === 'none' || ($second['scope'] ?? 'none') === 'none') {
            return ['scope' => 'none', 'department_ids' => [], 'sources' => $first['sources'] ?? []];
        }
        if ($second['scope'] === 'all') return $first;
        if ($first['scope'] === 'all') return array_replace($first, [
            'scope' => $second['scope'],
            'department_ids' => $second['department_ids'],
        ]);
        $ids = array_values(array_intersect($first['department_ids'], $second['department_ids']));
        return array_replace($first, ['scope' => $ids ? 'subtree' : 'none', 'department_ids' => $ids]);
    }
}
