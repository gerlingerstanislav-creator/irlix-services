<?php

namespace App\Services;

use App\Models\SpecialistsRolePermission;
use Illuminate\Support\Facades\DB;

final class SpecialistsPermissionManagementService
{
    public function matrix(): array
    {
        $matrix = SpecialistsRolePermission::query()->orderBy('role')->orderBy('permission')->get();
        $roles = DB::table('specialists_permission_roles')->where('active', true)->pluck('role')->all();
        // The legacy head:* fallback remains visible but immutable until its
        // production-wide cutover is planned separately.
        if ($matrix->contains(static fn ($row) => $row->role === 'head:*')) $roles[] = 'head:*';
        return [
            'roles' => array_values(array_unique($roles)),
            'permissions' => SpecialistsRoleAuthorization::PERMISSIONS,
            'scopes' => ['none', 'team', 'all'],
            'matrix' => $matrix,
        ];
    }

    public function isEditableRole(string $role): bool
    {
        return (bool) DB::table('specialists_permission_roles')
            ->where('role', $role)->where('active', true)->exists();
    }

    /** New columns are deliberately empty; no grant is created implicitly. */
    public function addRole(string $role): bool
    {
        return DB::transaction(static function () use ($role): bool {
            $record = DB::table('specialists_permission_roles')
                ->where('role', $role)->lockForUpdate()->first();
            if ($record && $record->active) return false;
            if ($record) {
                DB::table('specialists_permission_roles')->where('role', $role)
                    ->update(['active' => true, 'updated_at' => now()]);
            } else {
                DB::table('specialists_permission_roles')->insert([
                    'role' => $role, 'active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            return true;
        });
    }

    /**
     * Revocation removes all direct grants atomically. Retain an inactive
     * column tombstone so the historical head:* template cannot silently
     * restore this role's grants after its column disappears.
     */
    public function removeRole(string $role, string $actor): bool
    {
        return DB::transaction(static function () use ($role, $actor): bool {
            $column = DB::table('specialists_permission_roles')
                ->where('role', $role)->lockForUpdate()->first();
            if (!$column || !$column->active) return false;
            $rows = SpecialistsRolePermission::query()->where('role', $role)
                ->lockForUpdate()->get();
            foreach ($rows as $row) {
                DB::table('specialists_permission_audit')->insert([
                    'actor' => $actor, 'role' => $role, 'permission' => $row->permission,
                    'before' => json_encode(['allowed' => (bool) $row->allowed, 'scope' => $row->scope], JSON_THROW_ON_ERROR),
                    'after' => null, 'created_at' => now(),
                ]);
            }
            SpecialistsRolePermission::query()->where('role', $role)->delete();
            DB::table('specialists_permission_roles')->where('role', $role)
                ->update(['active' => false, 'updated_at' => now()]);
            return true;
        });
    }

    public function update(array $input, string $actor): void
    {
        DB::transaction(static function () use ($input, $actor): void {
            $row = SpecialistsRolePermission::query()->where('role', $input['role'])
                ->where('permission', $input['permission'])->lockForUpdate()->first();
            $before = $row ? ['allowed' => (bool) $row->allowed, 'scope' => $row->scope] : null;
            SpecialistsRolePermission::query()->updateOrCreate(
                ['role' => $input['role'], 'permission' => $input['permission']],
                ['allowed' => $input['allowed'], 'scope' => $input['scope']]
            );
            DB::table('specialists_permission_audit')->insert([
                'actor' => $actor, 'role' => $input['role'], 'permission' => $input['permission'],
                'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode(['allowed' => $input['allowed'], 'scope' => $input['scope']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });
    }

    public function remove(string $role, string $permission, string $actor): void
    {
        DB::transaction(static function () use ($role, $permission, $actor): void {
            $row = SpecialistsRolePermission::query()->where('role', $role)
                ->where('permission', $permission)->lockForUpdate()->first();
            if (!$row) return;
            $before = ['allowed' => (bool) $row->allowed, 'scope' => $row->scope];
            $row->delete();
            DB::table('specialists_permission_audit')->insert([
                'actor' => $actor, 'role' => $role, 'permission' => $permission,
                'before' => json_encode($before, JSON_THROW_ON_ERROR),
                'after' => null, 'created_at' => now(),
            ]);
        });
    }
}
