<?php

namespace App\Services;

use App\Models\RecruitmentRolePermission;

final class RecruitmentRoleAuthorization
{
    public const PERMISSIONS=[
        'recruitment.workspace.view',
        'recruitment.candidates.manage',
        'recruitment.requests.manage',
        'recruitment.hiring.manage',
        'recruitment.interviews.manage',
        'recruitment.offers.manage',
        'recruitment.pools.manage',
        'recruitment.employment.manage',
        'recruitment.onboarding.manage',
        'recruitment.tasks.manage',
    ];

    public static function evaluate(array $roles,array $rows):array
    {
        $effective=array_fill_keys(self::PERMISSIONS,false);
        if(in_array('platform-admin',$roles,true)
            || in_array('special:platform-admin',$roles,true))return array_fill_keys(self::PERMISSIONS,true);
        $allowed=[];$denied=[];
        $roleSet=array_fill_keys(array_values(array_filter($roles,static fn($role)=>
            is_string($role)&&preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D',$role)===1)),true);
        foreach($rows as $row){
            $role=(string)($row['role']??'');$permission=(string)($row['permission']??'');
            if(!isset($roleSet[$role])||!array_key_exists($permission,$effective))continue;
            // Recruitment currently returns a global workspace snapshot.
            // team/own scopes cannot be enforced by its API yet.
            if(($row['allowed']??false)===true&&($row['scope']??'')==='all')$allowed[$permission]=true;
            else $denied[$permission]=true;
        }
        foreach(self::PERMISSIONS as $p)$effective[$p]=isset($allowed[$p])&&!isset($denied[$p]);
        return $effective;
    }

    public static function currentIdentityMatches(array $signed,array $live):bool
    {
        $ids=array_values(array_filter($signed,static fn($role)=>
            is_string($role)&&preg_match('/^employee-id:[1-9][0-9]*$/D',$role)===1));
        if(count($ids)!==1||(int)substr($ids[0],strlen('employee-id:'))!==(int)($live['employee_id']??0)
            ||!is_array($live['roles']??null)||!is_array($live['role_claims']??null))return false;
        $current=array_fill_keys($live['role_claims'],true);
        foreach($signed as $role){
            if(!is_string($role))continue;
            if($role==='platform-admin'&&!in_array('platform-admin',$live['roles'],true))return false;
            if(preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D',$role)
                &&!isset($current[$role]))return false;
        }
        return true;
    }

    public static function requiredPermission(string $method,string $path):?string
    {
        if($path==='api/workspace'||preg_match('#^api/candidates/[0-9]+$#',$path))return 'recruitment.workspace.view';
        if($method==='POST'&&($path==='api/candidates'||preg_match('#^api/candidates/[0-9]+/activities$#',$path)))
            return 'recruitment.candidates.manage';
        if($method==='POST'&&$path==='api/requests')return 'recruitment.requests.manage';
        if($method==='POST'&&preg_match('#^api/hiring-processes/[0-9]+/stage$#',$path))
            return 'recruitment.hiring.manage';
        if($method==='POST'&&($path==='api/interviews'||preg_match('#^api/interviews/[0-9]+/evaluations$#',$path)))
            return 'recruitment.interviews.manage';
        if(in_array($method,['POST','PATCH'],true)
            &&($path==='api/offers'||preg_match('#^api/offers/[0-9]+$#',$path)))
            return 'recruitment.offers.manage';
        if($method==='POST'&&($path==='api/talent-pools'
            ||preg_match('#^api/talent-pools/[0-9]+/candidates$#',$path)))
            return 'recruitment.pools.manage';
        if(in_array($method,['POST','PATCH'],true)
            &&($path==='api/employment-requests'||preg_match('#^api/employment-requests/[0-9]+$#',$path)))
            return 'recruitment.employment.manage';
        if($method==='PATCH'&&preg_match('#^api/onboarding/[0-9]+$#',$path))
            return 'recruitment.onboarding.manage';
        if($method==='POST'&&($path==='api/tasks'||preg_match('#^api/tasks/[0-9]+/complete$#',$path)))
            return 'recruitment.tasks.manage';
        return null;
    }

    public function forVerifiedRoles(array $roles):array
    {
        if(in_array('platform-admin',$roles,true)
            ||in_array('special:platform-admin',$roles,true))return self::evaluate($roles,[]);
        $valid=array_values(array_filter($roles,static fn($role)=>
            is_string($role)&&preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D',$role)===1));
        return self::evaluate($roles,RecruitmentRolePermission::query()->whereIn('role',$valid)->get()
            ->map(static fn($row)=>['role'=>$row->role,'permission'=>$row->permission,
                'allowed'=>(bool)$row->allowed,'scope'=>$row->scope])->all());
    }
}
