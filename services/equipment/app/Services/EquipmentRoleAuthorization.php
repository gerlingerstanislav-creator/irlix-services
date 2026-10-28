<?php

namespace App\Services;

use App\Models\EquipmentRolePermission;

final class EquipmentRoleAuthorization
{
    public const PERMISSIONS = [
        'equipment.view','equipment.manage','equipment.operate',
    ];
    /**
     * Equipment currently owns one company-wide asset register. Accept only
     * none/all; own/team would need an explicit row-level ownership model.
     * A deny for a permission vetoes grants from all other roles. View never
     * implies manage or issue/return/write-off privileges.
     */
    public static function evaluate(array $roles, array $rows): array
    {
        $effective = array_fill_keys(self::PERMISSIONS, false);
        if (in_array('platform-admin',$roles,true)
            || in_array('special:platform-admin',$roles,true)) return array_fill_keys(self::PERMISSIONS,true);
        $roleNames = array_fill_keys(array_values(array_filter($roles,static fn ($role) =>
            is_string($role) && preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D',$role) === 1)),true);
        $granted=[]; $denied=[];
        foreach ($rows as $row) {
            $role=(string)($row['role']??''); $permission=(string)($row['permission']??'');
            if (!isset($roleNames[$role]) || !array_key_exists($permission,$effective)) continue;
            if (($row['allowed']??false)===true && ($row['scope']??'')==='all') $granted[$permission]=true;
            else $denied[$permission]=true;
        }
        foreach (self::PERMISSIONS as $permission) {
            $effective[$permission]=isset($granted[$permission]) && !isset($denied[$permission]);
        }
        return $effective;
    }
    public function forVerifiedRoles(array $roles): array
    {
        if (in_array('platform-admin',$roles,true)
            || in_array('special:platform-admin',$roles,true)) return self::evaluate($roles,[]);
        $valid=array_values(array_filter($roles,static fn ($role) =>
            is_string($role) && preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D',$role)===1));
        return self::evaluate($roles,EquipmentRolePermission::query()->whereIn('role',$valid)->get()
            ->map(static fn ($row)=>['role'=>$row->role,'permission'=>$row->permission,
                'allowed'=>(bool)$row->allowed,'scope'=>$row->scope])->all());
    }
}
