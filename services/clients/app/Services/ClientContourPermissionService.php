<?php

namespace App\Services;

use App\Models\ClientContourPermission;
use App\Models\ClientContourPermissionAudit;
use App\Support\ClientContourAccess;
use Illuminate\Support\Facades\DB;

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

    public function update(array $data, ?int $actorId): void
    {
        DB::transaction(static function () use ($data, $actorId): void {
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
