<?php

namespace App\Services;

use App\Models\VacationsRolePermission;
use App\Models\VacationsPermissionAudit;
use Illuminate\Support\Facades\DB;

final class VacationsPermissionManagementService
{
    public function matrix(): array
    {
        return [
            'permissions' => VacationsRoleAuthorization::PERMISSIONS,
            'scopes' => ['none','own','team','all'],
            'matrix' => VacationsRolePermission::query()->orderBy('role')->orderBy('permission')->get(),
        ];
    }

    public function write(array $input, string $actor): void
    {
        DB::transaction(static function () use ($input, $actor): void {
            $row = VacationsRolePermission::query()->where('role', $input['role'])
                ->where('permission', $input['permission'])->lockForUpdate()->first();
            $before = $row ? ['allowed'=>(bool) $row->allowed,'scope'=>$row->scope] : null;
            VacationsRolePermission::query()->updateOrCreate(
                ['role'=>$input['role'],'permission'=>$input['permission']],
                ['allowed'=>$input['allowed'],'scope'=>$input['scope']]
            );
            VacationsPermissionAudit::query()->create([
                'actor'=>$actor,'role'=>$input['role'],'permission'=>$input['permission'],
                'before'=>$before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after'=>json_encode(['allowed'=>$input['allowed'],'scope'=>$input['scope']], JSON_THROW_ON_ERROR),
                'created_at'=>now(),
            ]);
        });
    }

    public function forget(string $role, string $permission, string $actor): void
    {
        DB::transaction(static function () use ($role, $permission, $actor): void {
            $row = VacationsRolePermission::query()->where('role',$role)
                ->where('permission',$permission)->lockForUpdate()->first();
            if (!$row) return;
            $before=['allowed'=>(bool) $row->allowed,'scope'=>$row->scope];
            $row->delete();
            VacationsPermissionAudit::query()->create([
                'actor'=>$actor,'role'=>$role,'permission'=>$permission,
                'before'=>json_encode($before,JSON_THROW_ON_ERROR),
                'after'=>null,'created_at'=>now(),
            ]);
        });
    }
}
