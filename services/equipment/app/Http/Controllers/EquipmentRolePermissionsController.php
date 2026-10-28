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
        // Matrix editing is a privileged operation even while the feature
        // flag is OFF. Require CURRENT Employees assignment, not just an
        // unexpired signed platform-admin claim or a role preview.
        if ($request->header('X-Irlix-Role-Preview') !== null) {
            abort(403, 'Role preview cannot administer permission matrices');
        }
        $ids = array_values(array_filter($roles, static fn ($role) =>
            is_string($role) && preg_match('/^employee-id:[1-9][0-9]*$/D', $role) === 1));
        abort_unless(count($ids) === 1, 403, 'Verified employee identity required');
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        abort_unless(is_string($token) && $token !== '', 401);
        $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');
        try {
            $source = \Illuminate\Support\Facades\Http::withToken($token)->acceptJson()
                ->timeout(5)->get($base.'/access/me');
        } catch (\Throwable) {
            abort(503, 'Current administrator assignment unavailable');
        }
        abort_unless($source->successful() && is_array($source->json('data')), 503,
            'Current administrator assignment unavailable');
        $live = (array) $source->json('data');
        abort_unless(
            (int) ($live['employee_id'] ?? 0) === (int) substr($ids[0], strlen('employee-id:'))
            && in_array('platform-admin', (array) ($live['roles'] ?? []), true),
            403, 'Platform administrator role revoked'
        );

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
