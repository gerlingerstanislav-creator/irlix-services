<?php

namespace App\Services;

/**
 * Vacations-only object scope. Employees supplies current department IDs,
 * employee assignments and manager IDs; service policy decides the action.
 * Human-readable names, JWT scope:all, role aliases and job titles are not
 * organizational authority.
 */
final class VacationsObjectScope
{
    public static function managedDepartments(int $actorId, array $departments): array
    {
        if ($actorId <= 0) return [];
        $byParent = [];
        $managed = [];
        foreach ($departments as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) continue;
            $byParent[(int) ($row['parent_id'] ?? 0)][] = $id;
            if ((int) ($row['manager_id'] ?? 0) === $actorId) $managed[$id] = true;
        }
        $queue = array_keys($managed);
        for ($i = 0; $i < count($queue); $i++) {
            foreach ($byParent[$queue[$i]] ?? [] as $child) {
                if (isset($managed[$child])) continue;
                $managed[$child] = true;
                $queue[] = $child;
            }
        }
        return array_keys($managed);
    }

    public static function allows(
        string $scope,
        int $actorId,
        int $targetId,
        array $departments,
        array $employees
    ): bool {
        if ($actorId <= 0 || $targetId <= 0) return false;
        if ($scope === 'all') return true;
        if ($scope === 'own') return $actorId === $targetId;
        if ($scope !== 'team') return false;

        $managed = array_fill_keys(self::managedDepartments($actorId, $departments), true);
        if (!$managed) return false;
        foreach ($employees as $employee) {
            if ((int) ($employee['id'] ?? 0) !== $targetId) continue;
            return isset($managed[(int) ($employee['department_id'] ?? 0)]);
        }
        return false;
    }

    /** Mass endpoints with no resource-specific filter cannot use an own grant. */
    public static function requiresCollectiveScope(string $path): bool
    {
        return in_array($path, [
            'api/registry', 'api/department-roster', 'api/calendar-absences',
            'api/client-service-calendar', 'api/client-service-absence-alerts',
        ], true);
    }
}
