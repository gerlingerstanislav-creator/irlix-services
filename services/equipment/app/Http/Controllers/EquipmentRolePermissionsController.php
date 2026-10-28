<?php

namespace App\Http\Controllers;

use App\Services\EquipmentPermissionManagementService;
use App\Services\EquipmentRoleAuthorization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EquipmentRolePermissionsController
{
    private function admin(Request $request): void
    {
        $roles=(array)($request->attributes->get('identity',[])['realm_roles']??[]);
        abort_unless(in_array('platform-admin',$roles,true)
            || in_array('special:platform-admin',$roles,true),403);
        abort_if($request->header('X-Irlix-Role-Preview')!==null
            && $request->method()!=='GET',403,'Role preview is read-only');
    }

    public function index(Request $request, EquipmentPermissionManagementService $service)
    {
        $this->admin($request);
        return response()->json(['data'=>[
            'can_manage'=>true,
            'enabled'=>filter_var(env('IRLIX_EQUIPMENT_LOCAL_AUTHZ_ENABLED',false),FILTER_VALIDATE_BOOLEAN),
            ...$service->matrix(),
        ]]);
    }
    private function rules(bool $withGrant): array
    {
        $rules=[
            'role'=>['required','string','max:120','regex:/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'permission'=>['required',Rule::in(EquipmentRoleAuthorization::PERMISSIONS)],
        ];
        if ($withGrant) {
            $rules['allowed']=['required','boolean'];
            $rules['scope']=['required',Rule::in(['none','all'])];
        }
        return $rules;
    }
    public function update(Request $request, EquipmentPermissionManagementService $service)
    {
        $this->admin($request);
        $data=$request->validate($this->rules(true));
        abort_if($data['role']==='special:platform-admin',422);
        abort_if(($data['allowed'] && $data['scope']!=='all')
            || (!$data['allowed'] && $data['scope']!=='none'),422);
        $service->update($data,(string)($request->attributes->get('identity',[])['sub']??'unknown'));
        return $this->index($request,$service);
    }
    public function remove(Request $request, EquipmentPermissionManagementService $service)
    {
        $this->admin($request);
        $data=$request->validate($this->rules(false));
        abort_if($data['role']==='special:platform-admin',422);
        $service->remove($data['role'],$data['permission'],
            (string)($request->attributes->get('identity',[])['sub']??'unknown'));
        return $this->index($request,$service);
    }
}
