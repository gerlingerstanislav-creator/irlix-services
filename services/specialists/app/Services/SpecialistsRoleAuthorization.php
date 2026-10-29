<?php

namespace App\Services;

use App\Models\SpecialistsRolePermission;

final class SpecialistsRoleAuthorization
{
    public const PERMISSIONS=[
        'specialists.profiles.view','specialists.profiles.manage',
        'specialists.catalog.view','specialists.catalog.manage',
    ];
    public static function evaluate(array $roles,array $rows):array
    {
        $effective=array_fill_keys(self::PERMISSIONS,['allowed'=>false,'scope'=>'none']);
        if(in_array('platform-admin',$roles,true)
            ||in_array('special:platform-admin',$roles,true))
            return array_fill_keys(self::PERMISSIONS,['allowed'=>true,'scope'=>'all']);
        $roleSet=array_fill_keys(array_values(array_filter($roles,static fn($role)=>
            is_string($role)&&preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D',$role)===1)),true);
        if (array_filter(array_keys($roleSet), static fn($role) =>
            str_starts_with($role, 'head:'))) $roleSet['head:*'] = true;
        $grants=[];$denied=[];$rank=['none'=>0,'team'=>1,'all'=>2];
        foreach($rows as $row){
            $role=(string)($row['role']??'');$p=(string)($row['permission']??'');
            if(!isset($roleSet[$role])||!array_key_exists($p,$effective))continue;
            $scope=(string)($row['scope']??'none');
            if(($row['allowed']??false)!==true||!in_array($scope,['team','all'],true)
                ||(str_starts_with($p,'specialists.catalog.')&&$scope!=='all')){
                $denied[$p]=true;continue;
            }
            if(($rank[$scope]??0)>($rank[$grants[$p]??'none']??0))$grants[$p]=$scope;
        }
        foreach(self::PERMISSIONS as $p){
            if(isset($denied[$p])||!isset($grants[$p]))continue;
            $effective[$p]=['allowed'=>true,'scope'=>$grants[$p]];
        }
        return $effective;
    }

    public static function currentIdentityMatches(array $signed,array $live):bool
    {
        $ids=array_values(array_filter($signed,static fn($r)=>
            is_string($r)&&preg_match('/^employee-id:[1-9][0-9]*$/D',$r)===1));
        if(count($ids)!==1||(int)substr($ids[0],strlen('employee-id:'))!==(int)($live['employee_id']??0)
            ||!is_array($live['role_claims']??null)||!is_array($live['roles']??null))return false;
        $fresh=array_fill_keys($live['role_claims'],true);
        foreach($signed as $role){
            if(!is_string($role))continue;
            if($role==='platform-admin'&&!in_array('platform-admin',$live['roles'],true))return false;
            if(preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D',$role)
                &&!isset($fresh[$role]))return false;
        }
        return true;
    }

    public function forVerifiedRoles(array $roles):array
    {
        if(in_array('platform-admin',$roles,true)
            ||in_array('special:platform-admin',$roles,true))return self::evaluate($roles,[]);
        $valid=array_values(array_filter($roles,static fn($role)=>
            is_string($role)&&preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D',$role)===1));
        return self::evaluate($roles,SpecialistsRolePermission::query()->whereIn('role',$valid)->get()
            ->map(static fn($row)=>['role'=>$row->role,'permission'=>$row->permission,
                'allowed'=>(bool)$row->allowed,'scope'=>$row->scope])->all());
    }
}
