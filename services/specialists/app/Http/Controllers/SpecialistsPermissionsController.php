<?php

namespace App\Http\Controllers;

use App\Services\SpecialistsRoleAuthorization;
use App\Services\SpecialistsPermissionManagementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SpecialistsPermissionsController
{
    private function admin(Request $request):void
    {
        $roles=(array)($request->attributes->get('identity',[])['realm_roles']??[]);
        abort_unless(in_array('platform-admin',$roles,true)
            ||in_array('special:platform-admin',$roles,true),403);
        abort_if($request->header('X-Irlix-Role-Preview')!==null,403);
        $ids=array_values(array_filter($roles,static fn($role)=>
            is_string($role)&&preg_match('/^employee-id:[1-9][0-9]*$/D',$role)===1));
        abort_unless(count($ids)===1,403);
        $token=$request->bearerToken()?:$request->header('X-Irlix-Access-Token');
        abort_unless(is_string($token)&&$token!=='',401);
        $base=rtrim((string)env('EMPLOYEES_URL','http://employees:8000/api'),'/');
        try {
            $source=\Illuminate\Support\Facades\Http::withToken($token)->acceptJson()
                ->timeout(5)->get($base.'/access/me');
        }catch(\Throwable){abort(503,'Current administrator assignment unavailable');}
        abort_unless($source->successful()&&is_array($source->json('data')),503);
        $current=(array)$source->json('data');
        abort_unless((int)($current['employee_id']??0)===(int)substr($ids[0],strlen('employee-id:'))
            &&in_array('platform-admin',(array)($current['roles']??[]),true),403);
    }
    public function index(Request $request,SpecialistsPermissionManagementService $service)
    {
        $this->admin($request);
        return response()->json(['data'=>[
            'can_manage'=>true,
            'enabled'=>filter_var(env('IRLIX_SPECIALISTS_LOCAL_AUTHZ_ENABLED',false),FILTER_VALIDATE_BOOLEAN),
            ...$service->matrix(),
        ]]);
    }
    private function rules(bool $withEffect):array
    {
        $rules=[
            'role'=>['required','string','max:120','regex:/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'permission'=>['required',Rule::in(SpecialistsRoleAuthorization::PERMISSIONS)],
        ];
        if($withEffect){
            $rules['allowed']=['required','boolean'];
            $rules['scope']=['required',Rule::in(['none','team','all'])];
        }
        return $rules;
    }
    public function update(Request $request,SpecialistsPermissionManagementService $service)
    {
        $this->admin($request);$input=$request->validate($this->rules(true));
        abort_if($input['role']==='special:platform-admin',422);
        abort_if(($input['allowed']&&$input['scope']==='none')
            ||(!$input['allowed']&&$input['scope']!=='none')
            ||(str_starts_with($input['permission'],'specialists.catalog.')&&$input['allowed']
                &&$input['scope']!=='all'),422,'Invalid scope');
        $service->update($input,(string)($request->attributes->get('identity',[])['sub']??'unknown'));
        return $this->index($request,$service);
    }
    public function remove(Request $request,SpecialistsPermissionManagementService $service)
    {
        $this->admin($request);$input=$request->validate($this->rules(false));
        abort_if($input['role']==='special:platform-admin',422);
        $service->remove($input['role'],$input['permission'],
            (string)($request->attributes->get('identity',[])['sub']??'unknown'));
        return $this->index($request,$service);
    }
}
