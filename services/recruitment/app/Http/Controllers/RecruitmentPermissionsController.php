<?php

namespace App\Http\Controllers;

use App\Services\RecruitmentPermissionManagementService;
use App\Services\RecruitmentRoleAuthorization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class RecruitmentPermissionsController
{
    private function admin(Request $request):void
    {
        $roles=(array)($request->attributes->get('identity',[])['realm_roles']??[]);
        abort_unless(in_array('platform-admin',$roles,true)
            ||in_array('special:platform-admin',$roles,true),403);
        // A preview cannot mutate permissions or impersonate an admin.
        abort_if($request->header('X-Irlix-Role-Preview')!==null,403);
        $ids=array_values(array_filter($roles,static fn($role)=>
            is_string($role)&&preg_match('/^employee-id:[1-9][0-9]*$/D',$role)===1));
        abort_unless(count($ids)===1,403);
        $token=$request->bearerToken()?:$request->header('X-Irlix-Access-Token');
        abort_unless(is_string($token)&&$token!=='',401);
        $url=rtrim((string)env('EMPLOYEES_URL','http://employees:8000/api'),'/');
        try {
            $live=\Illuminate\Support\Facades\Http::withToken($token)->acceptJson()
                ->timeout(5)->get($url.'/access/me');
        }catch(\Throwable){abort(503,'Administrator role verification unavailable');}
        abort_unless($live->successful()&&is_array($live->json('data')),503);
        abort_unless((int)$live->json('data.employee_id')===(int)substr($ids[0],strlen('employee-id:'))
            &&in_array('platform-admin',(array)$live->json('data.roles',[]),true),403);
    }
    public function index(Request $request,RecruitmentPermissionManagementService $service)
    {
        $this->admin($request);
        return response()->json(['data'=>[
            'can_manage'=>true,
            'enabled'=>filter_var(env('IRLIX_RECRUITMENT_LOCAL_AUTHZ_ENABLED',false),FILTER_VALIDATE_BOOLEAN),
            ...$service->matrix(),
        ]]);
    }
    private function rules(bool $withEffect):array
    {
        $result=[
            'role'=>['required','string','max:120','regex:/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'permission'=>['required',Rule::in(RecruitmentRoleAuthorization::PERMISSIONS)],
        ];
        if($withEffect){
            $result['allowed']=['required','boolean'];
            $result['scope']=['required',Rule::in(['none','all'])];
        }
        return $result;
    }
    public function update(Request $request,RecruitmentPermissionManagementService $service)
    {
        $this->admin($request);$input=$request->validate($this->rules(true));
        abort_if($input['role']==='special:platform-admin',422);
        abort_if(($input['allowed']&&$input['scope']!=='all')
            ||(!$input['allowed']&&$input['scope']!=='none'),422);
        $service->update($input,(string)($request->attributes->get('identity',[])['sub']??'unknown'));
        return $this->index($request,$service);
    }
    public function remove(Request $request,RecruitmentPermissionManagementService $service)
    {
        $this->admin($request);$input=$request->validate($this->rules(false));
        abort_if($input['role']==='special:platform-admin',422);
        $service->remove($input['role'],$input['permission'],
            (string)($request->attributes->get('identity',[])['sub']??'unknown'));
        return $this->index($request,$service);
    }
}
