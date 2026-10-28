<?php

namespace App\Services;

use App\Models\VacationsRolePermission;

final class VacationsRoleAuthorization
{
    public const PERMISSIONS = [
        'vacations.mine.view', 'vacations.mine.manage',
        'vacations.department.view', 'vacations.department.manage',
        'vacations.manage.view', 'vacations.manage.manage',
        'vacations.approvals.view', 'vacations.approvals.manage',
        'vacations.documents.view', 'vacations.documents.manage',
        'vacations.history.view', 'vacations.client-calendar.view',
    ];

    /**
     * Local permission ceiling ONLY, never an override of absence status,
     * document confidentiality, specific task approvers, or employee scope.
     * The active business rules in Vacations remain a mandatory intersection.
     */
    public static function evaluate(array $roles, array $rows): array
    {
        $effective = array_fill_keys(self::PERMISSIONS, ['allowed' => false, 'scope' => 'none']);
        if (in_array('platform-admin', $roles, true)
            || in_array('special:platform-admin', $roles, true)) {
            return array_fill_keys(self::PERMISSIONS, ['allowed' => true, 'scope' => 'all']);
        }
        $valid = array_fill_keys(array_values(array_filter($roles, static fn ($role) =>
            is_string($role) && preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role) === 1)), true);
        $rank = ['none'=>0,'own'=>1,'team'=>2,'all'=>3];
        $grants = [];
        $denials = [];
        foreach ($rows as $row) {
            $name = (string) ($row['role'] ?? '');
            $permission = (string) ($row['permission'] ?? '');
            $scope = (string) ($row['scope'] ?? '');
            if (!isset($valid[$name]) || !array_key_exists($permission, $effective)) continue;
            if (($row['allowed'] ?? false) !== true || !in_array($scope, ['own','team','all'], true)) {
                $denials[$permission] = true;
                continue;
            }
            if ($rank[$scope] > ($rank[$grants[$permission] ?? 'none'] ?? 0)) $grants[$permission] = $scope;
        }
        foreach (self::PERMISSIONS as $permission) {
            if (isset($denials[$permission]) || !isset($grants[$permission])) continue;
            $effective[$permission] = ['allowed' => true, 'scope' => $grants[$permission]];
        }
        return $effective;
    }

    public function forSignedRoles(array $roles): array
    {
        if (in_array('platform-admin', $roles, true)
            || in_array('special:platform-admin', $roles, true)) return self::evaluate($roles, []);
        $valid = array_values(array_filter($roles, static fn ($role) =>
            is_string($role) && preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role) === 1));
        return self::evaluate($roles, VacationsRolePermission::query()->whereIn('role', $valid)->get()->map(
            static fn ($row) => ['role' => $row->role, 'permission' => $row->permission,
                'allowed' => (bool) $row->allowed, 'scope' => $row->scope]
        )->all());
    }
}
