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

    /** Only service-local read permissions can open business navigation. */
    public static function canOpenWorkspace(array $permissions): bool
    {
        foreach ([
            'vacations.mine.view', 'vacations.department.view',
            'vacations.manage.view', 'vacations.approvals.view',
            'vacations.history.view',
        ] as $permission) {
            if (($permissions[$permission]['allowed'] ?? false) === true) return true;
        }
        return false;
    }

    /**
     * Signed V2 claims must be a subset of the current assignments of the
     * SAME Employees actor. No live role can add a privilege absent from JWT.
     * Current role changes invalidate the entire stale JWT, including scope:*.
     */
    public static function currentIdentityMatches(array $signed, array $live): bool
    {
        $ids = array_values(array_filter($signed, static fn ($role) =>
            is_string($role) && preg_match('/^employee-id:[1-9][0-9]*$/D', $role) === 1));
        if (count($ids) !== 1 ||
            (int) substr($ids[0], strlen('employee-id:')) !== (int) ($live['employee_id'] ?? 0) ||
            !is_array($live['roles'] ?? null) || !is_array($live['role_claims'] ?? null)) return false;
        $current = array_fill_keys($live['role_claims'], true);
        foreach ($signed as $role) {
            if (!is_string($role)) continue;
            if ($role === 'platform-admin' && !in_array('platform-admin', $live['roles'], true)) return false;
            if (preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role) &&
                !isset($current[$role])) return false;
        }
        return true;
    }

    /**
     * Target permission contract only. A matching permission NEVER proves
     * access to a particular absence: the controller must additionally
     * enforce scope, current employee relationship, approver assignments,
     * document confidentiality and status. Do not enable V2 before that.
     */
    public static function requiredPermission(string $method, string $path): ?string
    {
        $path = ltrim($path, '/');
        return match (true) {
            $method === 'GET' && in_array($path, [
                'api/me', 'api/workspace', 'api/attention', 'api/absences',
                'api/absences/occupied', 'api/production-calendar',
            ], true) => 'vacations.mine.view',
            $method === 'GET' && preg_match('#^api/absences/[1-9][0-9]*(?:/workspace|/history)?$#D', $path) === 1
                => 'vacations.mine.view',
            $method === 'POST' && $path === 'api/absences'
                => 'vacations.mine.manage',
            $method === 'PATCH' && preg_match('#^api/absences/[1-9][0-9]*$#D', $path) === 1
                => 'vacations.mine.manage',
            $method === 'POST' && preg_match('#^api/absences/[1-9][0-9]*/submit$#D', $path) === 1
                => 'vacations.mine.manage',
            $method === 'GET' && $path === 'api/department-roster'
                => 'vacations.department.view',
            $method === 'GET' && $path === 'api/calendar-absences'
                => 'vacations.department.view',
            $method === 'POST' && $path === 'api/absences/for-employee'
                => 'vacations.department.manage',
            $method === 'GET' && $path === 'api/registry'
                => 'vacations.manage.view',
            $method === 'DELETE' && preg_match('#^api/absences/[1-9][0-9]*$#D', $path) === 1
                => 'vacations.manage.manage',
            $method === 'GET' && $path === 'api/approvals'
                => 'vacations.approvals.view',
            $method === 'POST' && preg_match('#^api/approvals/[1-9][0-9]*/(?:approve|reject)$#D', $path) === 1
                => 'vacations.approvals.manage',
            $method === 'POST' && preg_match('#^api/absences/[1-9][0-9]*/return-to-planned$#D', $path) === 1
                => 'vacations.approvals.manage',
            $method === 'GET' && preg_match('#^api/absences/[1-9][0-9]*/attachments(?:/[1-9][0-9]*/download)?$#D', $path) === 1
                => 'vacations.documents.view',
            in_array($method, ['POST','DELETE'], true)
                && preg_match('#^api/absences/[1-9][0-9]*/attachments(?:/[1-9][0-9]*)?$#D', $path) === 1
                => 'vacations.documents.manage',
            $method === 'GET' && $path === 'api/history'
                => 'vacations.history.view',
            $method === 'GET' && in_array($path, [
                'api/client-service-calendar','api/client-service-absence-alerts',
            ], true) => 'vacations.client-calendar.view',
            default => null,
        };
    }

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
