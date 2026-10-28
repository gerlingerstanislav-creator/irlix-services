<?php

namespace App\Services;

use App\Models\RecruitmentRolePermission;
use Illuminate\Support\Facades\DB;

final class RecruitmentPermissionManagementService
{
    public function matrix(): array
    {
        return [
            'permissions'=>RecruitmentRoleAuthorization::PERMISSIONS,
            'scopes'=>['none','all'],
            'matrix'=>RecruitmentRolePermission::query()->orderBy('role')->orderBy('permission')->get(),
        ];
    }

    public function update(array $data,string $actor):void
    {
        DB::transaction(static function()use($data,$actor):void{
            $row=RecruitmentRolePermission::query()->where('role',$data['role'])
                ->where('permission',$data['permission'])->lockForUpdate()->first();
            $before=$row?['allowed'=>(bool)$row->allowed,'scope'=>$row->scope]:null;
            RecruitmentRolePermission::query()->updateOrCreate(
                ['role'=>$data['role'],'permission'=>$data['permission']],
                ['allowed'=>$data['allowed'],'scope'=>$data['scope']]
            );
            DB::table('recruitment_permission_audit')->insert([
                'actor'=>$actor,'role'=>$data['role'],'permission'=>$data['permission'],
                'before'=>$before===null?null:json_encode($before,JSON_THROW_ON_ERROR),
                'after'=>json_encode(['allowed'=>$data['allowed'],'scope'=>$data['scope']],JSON_THROW_ON_ERROR),
                'created_at'=>now(),
            ]);
        });
    }

    public function remove(string $role,string $permission,string $actor):void
    {
        DB::transaction(static function()use($role,$permission,$actor):void{
            $row=RecruitmentRolePermission::query()->where('role',$role)
                ->where('permission',$permission)->lockForUpdate()->first();
            if(!$row)return;
            $before=['allowed'=>(bool)$row->allowed,'scope'=>$row->scope];
            $row->delete();
            DB::table('recruitment_permission_audit')->insert([
                'actor'=>$actor,'role'=>$role,'permission'=>$permission,
                'before'=>json_encode($before,JSON_THROW_ON_ERROR),
                'after'=>null,'created_at'=>now(),
            ]);
        });
    }
}
