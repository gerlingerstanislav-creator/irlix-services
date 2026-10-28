<?php

namespace App\Services;

use App\Models\SpecialistsRolePermission;
use Illuminate\Support\Facades\DB;

final class SpecialistsPermissionManagementService
{
    public function matrix():array
    {
        return [
            'permissions'=>SpecialistsRoleAuthorization::PERMISSIONS,
            'scopes'=>['none','team','all'],
            'matrix'=>SpecialistsRolePermission::query()->orderBy('role')->orderBy('permission')->get(),
        ];
    }
    public function update(array $input,string $actor):void
    {
        DB::transaction(static function()use($input,$actor):void{
            $row=SpecialistsRolePermission::query()->where('role',$input['role'])
                ->where('permission',$input['permission'])->lockForUpdate()->first();
            $before=$row?['allowed'=>(bool)$row->allowed,'scope'=>$row->scope]:null;
            SpecialistsRolePermission::query()->updateOrCreate(
                ['role'=>$input['role'],'permission'=>$input['permission']],
                ['allowed'=>$input['allowed'],'scope'=>$input['scope']]
            );
            DB::table('specialists_permission_audit')->insert([
                'actor'=>$actor,'role'=>$input['role'],'permission'=>$input['permission'],
                'before'=>$before===null?null:json_encode($before,JSON_THROW_ON_ERROR),
                'after'=>json_encode(['allowed'=>$input['allowed'],'scope'=>$input['scope']],JSON_THROW_ON_ERROR),
                'created_at'=>now(),
            ]);
        });
    }
    public function remove(string $role,string $permission,string $actor):void
    {
        DB::transaction(static function()use($role,$permission,$actor):void{
            $row=SpecialistsRolePermission::query()->where('role',$role)
                ->where('permission',$permission)->lockForUpdate()->first();
            if(!$row)return;
            $before=['allowed'=>(bool)$row->allowed,'scope'=>$row->scope];
            $row->delete();
            DB::table('specialists_permission_audit')->insert([
                'actor'=>$actor,'role'=>$role,'permission'=>$permission,
                'before'=>json_encode($before,JSON_THROW_ON_ERROR),
                'after'=>null,'created_at'=>now(),
            ]);
        });
    }
}
