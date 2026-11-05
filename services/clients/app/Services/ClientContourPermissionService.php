<?php

namespace App\Services;

use App\Models\ClientContourPermission;
use App\Models\ClientContourPermissionAudit;
use App\Support\ClientContourAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ClientContourPermissionService
{
    public function matrix(): array
    {
        $rows = ClientContourPermission::query()->orderBy('role')->orderBy('permission')->get();
        $matrix = [];
        foreach ($rows as $row) {
            // Only canonical signed Keycloak V2 roles and Clients-owned
            // permissions are editable. Old role columns and Timesheets
            // rows never participate in the effective Clients matrix.
            if (!self::isCanonicalRole((string) $row->role)
                || str_starts_with((string) $row->permission, 'timesheets.')) continue;
            $matrix[$row->role][$row->permission] = [
                'allowed' => (bool) $row->allowed,
                'scope' => $row->scope,
                'denied' => (bool) ($row->denied ?? false),
            ];
        }
        // This is the ONLY non-editable superuser column. It is not a
        // second legacy platform-admin role.
        $matrix['special:platform-admin'] = [];
        foreach (array_keys(ClientContourAccess::PERMISSION_LABELS) as $permission) {
            $matrix['special:platform-admin'][$permission] = [
                'allowed' => true, 'scope' => 'all', 'locked' => true,
            ];
        }
        return $matrix;
    }

    public static function isCanonicalRole(string $role): bool
    {
        return preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $role) === 1
            && $role !== 'special:platform-tester';
    }

    /** Only roles already present in the V2 matrix can be configured.
     * A matrix request must not create an arbitrary identity/Keycloak role.
     */
    public function isEditableRole(string $role): bool
    {
        return self::isCanonicalRole($role)
            && $role !== 'special:platform-admin'
            && ClientContourPermission::query()->where('role', $role)->exists();
    }

    /**
     * Creating a visible column stores explicit empty permissions, not grants.
     * A removed column has no rows and can never influence effective access.
     */
    public function addRole(string $role, ?int $actorId): bool
    {
        if (!self::isCanonicalRole($role) || $role === 'special:platform-admin') return false;

        return DB::transaction(static function () use ($role, $actorId): bool {
            if (ClientContourPermission::query()->where('role', $role)->exists()) return false;
            $now = now();
            foreach (array_keys(ClientContourAccess::PERMISSION_LABELS) as $permission) {
                ClientContourPermission::query()->create([
                    'role' => $role, 'permission' => $permission,
                    'allowed' => false, 'scope' => 'none', 'denied' => false,
                ]);
                ClientContourPermissionAudit::query()->insert([
                    'actor_employee_id' => $actorId,
                    'role' => $role, 'permission' => $permission,
                    'before' => null,
                    'after' => json_encode(['allowed' => false, 'scope' => 'none', 'denied' => false]),
                    'created_at' => $now,
                ]);
            }
            return true;
        });
    }

    /** Revokes every Clients grant and explicit denial in one transaction. */
    public function removeRole(string $role, ?int $actorId): bool
    {
        if (!self::isCanonicalRole($role) || $role === 'special:platform-admin') return false;

        return DB::transaction(static function () use ($role, $actorId): bool {
            $rows = ClientContourPermission::query()->where('role', $role)->lockForUpdate()->get();
            if ($rows->isEmpty()) return false;
            $now = now();
            foreach ($rows as $row) {
                ClientContourPermissionAudit::query()->insert([
                    'actor_employee_id' => $actorId,
                    'role' => $role, 'permission' => (string) $row->permission,
                    'before' => json_encode([
                        'allowed' => (bool) $row->allowed, 'scope' => (string) $row->scope,
                        'denied' => (bool) ($row->denied ?? false),
                    ]),
                    'after' => null,
                    'created_at' => $now,
                ]);
            }
            ClientContourPermission::query()->where('role', $role)->delete();
            return true;
        });
    }

    public function update(array $data, ?int $actorId): void
    {
        DB::transaction(static function () use ($data, $actorId): void {
            // Re-check under a row lock: a delayed editor cannot recreate
            // a role that another administrator has just removed.
            $activeRole = ClientContourPermission::query()->where('role', $data['role'])
                ->lockForUpdate()->first(['id']);
            if (!$activeRole) {
                throw ValidationException::withMessages(['role' => 'Роль уже удалена. Обновите список.']);
            }
            $row = ClientContourPermission::query()
                ->where(['role' => $data['role'], 'permission' => $data['permission']])
                ->lockForUpdate()->first();
            $before = [
                'allowed' => (bool) ($row?->allowed ?? false),
                'scope' => $row?->scope ?? 'none',
                'denied' => (bool) ($row?->denied ?? false),
            ];
            $after = [
                'allowed' => $data['allowed'],
                'scope' => $data['scope'],
                'denied' => (bool) ($data['denied'] ?? false),
            ];
            ClientContourPermission::query()->updateOrInsert(
                ['role' => $data['role'], 'permission' => $data['permission']],
                [...$after, 'updated_at' => now(), ...(!$row ? ['created_at' => now()] : [])]
            );
            ClientContourPermissionAudit::query()->insert([
                'actor_employee_id' => $actorId,
                'role' => $data['role'], 'permission' => $data['permission'],
                'before' => json_encode($before, JSON_UNESCAPED_UNICODE),
                'after' => json_encode($after, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        });
    }
}
