<?php

namespace App\Services;

use App\Models\TimesheetsRolePermission;

final class TimesheetsRoleAuthorization
{
    public const PERMISSIONS = [
        'timesheets.mine.view', 'timesheets.mine.manage',
        'timesheets.management.view', 'timesheets.management.manage',
        'timesheets.analytics.view', 'timesheets.audit.view',
    ];

    /**
     * Independent Timesheets permission/scope matrix. Deny on missing rows,
     * explicit false overriding grants for the SAME permission. Other
     * permissions never inherit scope from this permission's grants.
     */
    public static function evaluate(array $verifiedRoles, array $rows): array
    {
        $permissions = [];
        foreach (self::PERMISSIONS as $permission) {
            $permissions[$permission] = ['allowed' => false, 'scope' => 'none'];
        }
        if (in_array('platform-admin', $verifiedRoles, true)
            || in_array('special:platform-admin', $verifiedRoles, true)) {
            foreach (self::PERMISSIONS as $permission) {
                $permissions[$permission] = ['allowed' => true, 'scope' => 'all'];
            }
            return $permissions;
        }

        $names = array_fill_keys(array_values(array_filter($verifiedRoles, static fn ($role) =>
            is_string($role) && preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role) === 1)), true);
        $rank = ['none'=>0, 'own'=>1, 'team'=>2, 'all'=>3];
        $grants = [];
        $denied = [];
        foreach ($rows as $row) {
            $role = (string) ($row['role'] ?? '');
            $permission = (string) ($row['permission'] ?? '');
            $scope = (string) ($row['scope'] ?? 'none');
            if (!isset($names[$role]) || !array_key_exists($permission, $permissions)) continue;
            if (!($row['allowed'] ?? false) || ($rank[$scope] ?? 0) === 0) {
                $denied[$permission] = true;
                continue;
            }
            if (($rank[$scope] ?? 0) > ($rank[$grants[$permission] ?? 'none'] ?? 0)) {
                $grants[$permission] = $scope;
            }
        }
        foreach (self::PERMISSIONS as $permission) {
            if (isset($denied[$permission]) || !isset($grants[$permission])) continue;
            $permissions[$permission] = ['allowed' => true, 'scope' => $grants[$permission]];
        }
        return $permissions;
    }

    public function forVerifiedRoles(array $roles): array
    {
        $filtered = array_values(array_filter($roles, static fn ($role) =>
            is_string($role) && preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role) === 1));
        if (in_array('platform-admin', $roles, true)
            || in_array('special:platform-admin', $roles, true)) {
            return self::evaluate($roles, []);
        }
        return self::evaluate($roles,
            TimesheetsRolePermission::query()->whereIn('role', $filtered)->get()->map(static fn ($row) => [
                'role' => $row->role, 'permission' => $row->permission,
                'allowed' => (bool) $row->allowed, 'scope' => $row->scope,
            ])->all());
    }
}
