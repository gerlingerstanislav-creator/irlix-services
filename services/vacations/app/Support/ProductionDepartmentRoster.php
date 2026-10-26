<?php

namespace App\Support;

/**
 * Employees is the source of truth for production directions.
 * Include people from production roots and their descendant departments,
 * never employees from unrelated administrative departments.
 */
final class ProductionDepartmentRoster
{
    public static function filter(array $departments, array $employees): array
    {
        $byId = [];
        foreach ($departments as $department) {
            $byId[(int) ($department['id'] ?? 0)] = $department;
        }
        $isProduction = static function (int $id) use ($byId): bool {
            $visited = [];
            while ($id > 0 && isset($byId[$id]) && !isset($visited[$id])) {
                $visited[$id] = true;
                $department = $byId[$id];
                if (filter_var($department['is_production'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    return true;
                }
                $id = (int) ($department['parent_id'] ?? 0);
            }
            return false;
        };

        return [
            'employees' => array_values(array_filter($employees, static fn ($employee): bool =>
                $isProduction((int) ($employee['department_id'] ?? 0)))),
            'departments' => array_values(array_filter($departments, static fn ($department): bool =>
                $isProduction((int) ($department['id'] ?? 0)))),
        ];
    }
}
