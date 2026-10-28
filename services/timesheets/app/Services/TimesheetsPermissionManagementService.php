<?php

namespace App\Services;

use App\Models\TimesheetsRolePermission;
use Illuminate\Support\Facades\DB;

final class TimesheetsPermissionManagementService
{
    public function matrix(): array
    {
        return [
            'permissions' => TimesheetsRoleAuthorization::PERMISSIONS,
            'scopes' => ['none', 'own', 'team', 'all'],
            'matrix' => TimesheetsRolePermission::query()->orderBy('role')->orderBy('permission')->get(),
        ];
    }

    public function remove(string $role, string $permission, string $actor): void
    {
        DB::transaction(static function () use ($role, $permission, $actor): void {
            $row = TimesheetsRolePermission::query()->where('role', $role)
                ->where('permission', $permission)->lockForUpdate()->first();
            if (!$row) return;
            $before = ['allowed' => (bool) $row->allowed, 'scope' => $row->scope];
            $row->delete();
            DB::table('timesheets_permission_audit')->insert([
                'actor' => $actor, 'role' => $role, 'permission' => $permission,
                'before' => json_encode($before, JSON_THROW_ON_ERROR),
                'after' => null, 'created_at' => now(),
            ]);
        });
    }

    public function update(array $input, string $actor): void
    {
        DB::transaction(static function () use ($input, $actor): void {
            $row = TimesheetsRolePermission::query()->where('role', $input['role'])
                ->where('permission', $input['permission'])->lockForUpdate()->first();
            $before = $row ? ['allowed' => (bool) $row->allowed, 'scope' => $row->scope] : null;
            TimesheetsRolePermission::query()->updateOrCreate(
                ['role' => $input['role'], 'permission' => $input['permission']],
                ['allowed' => $input['allowed'], 'scope' => $input['scope']]
            );
            DB::table('timesheets_permission_audit')->insert([
                'actor' => $actor,
                'role' => $input['role'], 'permission' => $input['permission'],
                'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode(['allowed' => $input['allowed'], 'scope' => $input['scope']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });
    }
}
