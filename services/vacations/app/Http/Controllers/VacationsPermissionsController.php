<?php

namespace App\Http\Controllers;

use App\Services\VacationsRoleAuthorization;
use App\Services\VacationsPermissionManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class VacationsPermissionsController
{
    private function admin(Request $request): void
    {
        $roles = (array) ($request->attributes->get('identity', [])['realm_roles'] ?? []);
        abort_unless(in_array('platform-admin',$roles,true)
            || in_array('special:platform-admin',$roles,true),403);
        abort_if($request->header('X-Irlix-Role-Preview') !== null && !$request->isMethod('GET'),
            403,'Role preview is read-only');
    }

    public function index(Request $request, VacationsPermissionManagementService $service): JsonResponse
    {
        $this->admin($request);
        return response()->json(['data'=>[
            'can_manage'=>true,
            'enabled'=>filter_var(env('IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            ...$service->matrix(),
        ]]);
    }

    private function validatedRole(Request $request, bool $withEffect = false): array
    {
        $rules = [
            'role'=>['required','string','max:120','regex:/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'permission'=>['required',Rule::in(VacationsRoleAuthorization::PERMISSIONS)],
        ];
        if ($withEffect) {
            $rules['allowed']=['required','boolean'];
            $rules['scope']=['required',Rule::in(['none','own','team','all'])];
        }
        $data=$request->validate($rules);
        abort_if($data['role']==='special:platform-admin',422,'Platform superuser role is locked');
        if ($withEffect && (($data['allowed'] && $data['scope']==='none')
            || (!$data['allowed'] && $data['scope']!=='none'))) {
            abort(422,'Invalid permission and scope combination');
        }
        return $data;
    }

    public function update(Request $request, VacationsPermissionManagementService $service): JsonResponse
    {
        $this->admin($request);
        $data=$this->validatedRole($request,true);
        $service->write($data,(string)($request->attributes->get('identity',[])['sub'] ?? 'unknown'));
        return $this->index($request,$service);
    }

    public function remove(Request $request, VacationsPermissionManagementService $service): JsonResponse
    {
        $this->admin($request);
        $data=$this->validatedRole($request);
        $service->forget($data['role'],$data['permission'],
            (string)($request->attributes->get('identity',[])['sub'] ?? 'unknown'));
        return $this->index($request,$service);
    }
}
