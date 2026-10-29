<?php

namespace App\Support;

use RuntimeException;

/**
 * Canonical V2 head labels for currently managed production departments.
 * Unmanaged production branches have no active head: role and must not
 * block migration of historical department-manager permissions.
 *
 * Names/IDs stay inside Employees; only verified aliases cross to Clients.
 */
final class ClientsProductionHeadAliases
{
    public static function fromDepartments(array $departments): array
    {
        $byId = [];
        foreach ($departments as $department) {
            $id = (int) ($department['id'] ?? 0);
            if ($id > 0) $byId[$id] = $department;
        }

        $aliases = [];
        foreach ($departments as $department) {
            if (!($department['is_production'] ?? false)) continue;
            // A department without a manager has no currently assigned head
            // role. It cannot receive a generic manager's historic policy.
            if ((int) ($department['manager_id'] ?? 0) <= 0) continue;
            $alias = (string) ($department['alias'] ?? '');
            if ($alias === 'accounting') {
                $id = (int) ($department['id'] ?? 0);
                $visited = [];
                while ($id > 0 && isset($byId[$id]) && !isset($visited[$id])) {
                    $visited[$id] = true;
                    if (($byId[$id]['alias'] ?? null) === 'client-service') {
                        $alias = 'account-management';
                        break;
                    }
                    $id = (int) ($byId[$id]['parent_id'] ?? 0);
                }
            }
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $alias) !== 1) {
                // Cannot invent a new role or silently lose a manager's
                // rights. Requires fixing the department's canonical alias.
                throw new RuntimeException('Managed production department lacks canonical alias');
            }
            if (isset($aliases[$alias]) && $aliases[$alias] !== (int) ($department['id'] ?? 0)) {
                throw new RuntimeException('Duplicate managed production role alias');
            }
            $aliases[$alias] = (int) ($department['id'] ?? 0);
        }
        $result = array_keys($aliases);
        sort($result, SORT_STRING);
        return $result;
    }
}
