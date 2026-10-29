<?php

namespace App\Services;

use App\Models\TimesheetsRolePermission;

final class TimesheetsRoleAuthorization
{
    /**
     * Only verified, still-current namespaced identity assignments may be
     * passed to the Timesheets local permission engine. The Employees lookup
     * is identity freshness, not a source of Timesheets business permissions.
     */
    public static function currentIdentityMatches(array $signed, array $live): bool
    {
        $ids = array_values(array_filter($signed, static fn ($role) =>
            is_string($role) && preg_match('/^employee-id:[1-9][0-9]*$/D', $role) === 1));
        if (count($ids) !== 1 || (int) substr($ids[0], strlen('employee-id:')) !== (int) ($live['employee_id'] ?? 0)
            || !is_array($live['role_claims'] ?? null)
            || !is_array($live['roles'] ?? null)) return false;
        $current = array_fill_keys((array) $live['role_claims'], true);
        foreach ($signed as $role) {
            if (!is_string($role)) continue;
            if ($role === 'platform-admin' && !in_array('platform-admin',$live['roles'],true)) return false;
            if (preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role) === 1
                && !isset($current[$role])) return false;
        }
        return true;
    }

    public const PERMISSIONS = [
        'timesheets.mine.view', 'timesheets.mine.manage',
        'timesheets.management.view', 'timesheets.management.manage',
        'timesheets.analytics.view', 'timesheets.audit.view',
        'timesheets.partner-hours.view', 'timesheets.partner-hours.manage',
        'timesheets.partner-hours.approve',
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
        // Existing Clients generic department-manager rows apply only to a
        // currently verified head. Live Clients effective access further
        // narrows these template grants before any operation proceeds.
        if (array_filter(array_keys($names), static fn($name) =>
            str_starts_with($name, 'head:'))) $names['head:*'] = true;
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

    /**
     * The old live Clients matrix still owns the authorization exceptions
     * for Timesheets management, analytics and audit during the migration.
     * A V2 role grant can only be equal to or narrower than its effective
     * old permission/scope for this signed employee.
     */
    public static function restrictToLegacy(array $v2, array $legacy): array
    {
        $rank = ['none'=>0,'own'=>1,'team'=>2,'all'=>3];
        foreach ([
            'timesheets.management.view','timesheets.management.manage',
            'timesheets.analytics.view','timesheets.audit.view',
        ] as $permission) {
            $new = (array) ($v2[$permission] ?? []);
            $old = (array) ($legacy[$permission] ?? []);
            $newScope = (string) ($new['scope'] ?? 'none');
            $oldScope = (string) ($old['scope'] ?? 'none');
            $newRank = $rank[$newScope] ?? 0;
            $oldRank = $rank[$oldScope] ?? 0;
            $allowed = ($new['allowed'] ?? false) === true
                && ($old['allowed'] ?? false) === true && $newRank > 0 && $oldRank > 0;
            $scope = $allowed ? ($newRank <= $oldRank ? $newScope : $oldScope) : 'none';
            $v2[$permission] = ['allowed'=>$allowed,'scope'=>$scope];
        }
        return $v2;
    }

    public function forVerifiedRoles(array $roles): array
    {
        $filtered = array_values(array_filter($roles, static fn ($role) =>
            is_string($role) && preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role) === 1));
        if (in_array('platform-admin', $roles, true)
            || in_array('special:platform-admin', $roles, true)) {
            return self::evaluate($roles, []);
        }
        if (array_filter($filtered, static fn($name)=>str_starts_with($name,'head:'))) {
            $filtered[]='head:*';
        }
        return self::evaluate($roles,
            TimesheetsRolePermission::query()->whereIn('role', $filtered)->get()->map(static fn ($row) => [
                'role' => $row->role, 'permission' => $row->permission,
                'allowed' => (bool) $row->allowed, 'scope' => $row->scope,
            ])->all());
    }
}
