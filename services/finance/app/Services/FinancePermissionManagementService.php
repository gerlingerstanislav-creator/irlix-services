<?php

namespace App\Services;

use App\Models\FinanceRolePermission;
use Illuminate\Support\Facades\DB;

final class FinancePermissionManagementService
{
    public function matrix(): array
    {
        return [
            'permissions' => FinanceRoleAuthorization::PERMISSIONS,
            'scopes' => ['none', 'all'],
            'matrix' => FinanceRolePermission::query()->orderBy('role')->orderBy('permission')->get(),
        ];
    }

    public function update(array $input, string $actor): void
    {
        DB::transaction(static function () use ($input, $actor): void {
            $row = FinanceRolePermission::query()->where('role', $input['role'])
                ->where('permission', $input['permission'])->lockForUpdate()->first();
            $before = $row ? ['allowed' => (bool) $row->allowed, 'scope' => $row->scope] : null;
            FinanceRolePermission::query()->updateOrCreate(
                ['role' => $input['role'], 'permission' => $input['permission']],
                ['allowed' => $input['allowed'], 'scope' => $input['scope']]
            );
            DB::table('finance_permission_audit')->insert([
                'actor' => $actor,
                'role' => $input['role'], 'permission' => $input['permission'],
                'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode(['allowed' => $input['allowed'], 'scope' => $input['scope']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });
    }
}
