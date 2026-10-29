<?php

namespace App\Services;

use App\Models\FinanceRolePermission;

final class FinanceRoleAuthorization
{
    /**
     * Keycloak supplies signed roles; Employees verifies that each signed
     * identity role is still assigned. A current role never grants access
     * without also appearing in the signed bearer. No Employees permissions
     * or global service grants are imported.
     */
    public static function currentIdentityMatches(array $signed, array $current): bool
    {
        $ids = array_values(array_filter($signed, static fn ($role) =>
            is_string($role) && preg_match('/^employee-id:[1-9][0-9]*$/D', $role) === 1));
        if (count($ids) !== 1 || (int) substr($ids[0], 12) !== (int) ($current['employee_id'] ?? 0)
            || !is_array($current['roles'] ?? null)
            || !is_array($current['role_claims'] ?? null)) return false;
        $fresh = array_fill_keys((array) $current['role_claims'], true);
        foreach ($signed as $role) {
            if (!is_string($role)) continue;
            if ($role === 'platform-admin' && !in_array('platform-admin', $current['roles'], true)) return false;
            if (preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role)
                && !isset($fresh[$role])) return false;
        }
        return true;
    }

    public static function intersectLegacy(array $new, array $legacy): array
    {
        if (($legacy['services']['finance'] ?? false) !== true) {
            return array_fill_keys(array_keys($new), false);
        }
        foreach ($new as $permission => $allowed) {
            $new[$permission] = $allowed === true
                && ($legacy['permissions'][$permission] ?? false) === true;
        }
        return $new;
    }

    public const PERMISSIONS = [
        'finance.view',
        'finance.salary.view',
        'finance.invoices.manage',
        'finance.cash.confirm',
        'finance.expenses.manage',
        'finance.accounts.manage',
        'finance.planning.manage',
        'finance.planning.approve',
    ];

    /**
     * V2 is deliberately deny-by-default. No Employees/Keycloak service
     * grants or organizational membership may implicitly grant Finance data.
     *
     * Finance presently has company-wide ledgers. Unsupported own/team
     * scopes are denied until each endpoint implements proper row filters.
     * Explicit denies for one permission override other positive roles.
     */
    public static function evaluate(array $verifiedRoles, array $policyRows): array
    {
        $permissions = array_fill_keys(self::PERMISSIONS, false);
        if (in_array('platform-admin', $verifiedRoles, true)
            || in_array('special:platform-admin', $verifiedRoles, true)) {
            return array_fill_keys(self::PERMISSIONS, true);
        }

        $names = array_fill_keys(array_filter($verifiedRoles, static fn ($role) =>
            is_string($role) && preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role) === 1), true);
        $allowed = [];
        $denied = [];
        foreach ($policyRows as $row) {
            $role = (string) ($row['role'] ?? '');
            $permission = (string) ($row['permission'] ?? '');
            if (!isset($names[$role]) || !array_key_exists($permission, $permissions)) continue;
            if (($row['allowed'] ?? false) === true && ($row['scope'] ?? '') === 'all') {
                $allowed[$permission] = true;
            } else {
                $denied[$permission] = true;
            }
        }
        foreach (self::PERMISSIONS as $permission) {
            $permissions[$permission] = !isset($denied[$permission]) && isset($allowed[$permission]);
        }
        return $permissions;
    }

    public function forVerifiedRoles(array $verifiedRoles): array
    {
        if (in_array('platform-admin', $verifiedRoles, true)
            || in_array('special:platform-admin', $verifiedRoles, true)) {
            return self::evaluate($verifiedRoles, []);
        }
        $roles = array_values(array_filter($verifiedRoles, static fn ($role) =>
            is_string($role) && preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role) === 1));
        $rows = FinanceRolePermission::query()->whereIn('role', $roles)->get()
            ->map(static fn ($row) => [
                'role' => $row->role, 'permission' => $row->permission,
                'allowed' => (bool) $row->allowed, 'scope' => $row->scope,
            ])->all();
        return self::evaluate($verifiedRoles, $rows);
    }
}
