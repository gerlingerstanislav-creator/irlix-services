<?php

namespace App\Http\Controllers;

use App\Services\TimesheetsPermissionManagementService;
use App\Services\TimesheetsRoleAuthorization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TimesheetsPermissionsController
{
    private function authorizeAdmin(Request $request): void
    {
        $roles = (array) ($request->attributes->get('identity', [])['realm_roles'] ?? []);
        abort_unless(in_array('platform-admin', $roles, true)
            || in_array('special:platform-admin', $roles, true), 403);
        abort_if($request->attributes->has('role_preview_access') && $request->method() !== 'GET',
            403, 'Role preview is read-only');
    }

    public function index(Request $request, TimesheetsPermissionManagementService $service)
    {
        $this->authorizeAdmin($request);
        return response()->json(['data' => [
            'can_manage' => true,
            'enabled' => filter_var(env('IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            ...$service->matrix(),
        ]]);
    }

    public function update(Request $request, TimesheetsPermissionManagementService $service)
    {
        $this->authorizeAdmin($request);
        $input = $request->validate([
            'role' => ['required','string','max:120','regex:/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'permission' => ['required',Rule::in(TimesheetsRoleAuthorization::PERMISSIONS)],
            'allowed' => ['required','boolean'],
            'scope' => ['required',Rule::in(['none','own','team','all'])],
        ]);
        if ($input['role'] === 'special:platform-admin') {
            return response()->json(['message' => 'Platform superuser policy is not editable'], 422);
        }
        if (($input['allowed'] && $input['scope'] === 'none')
            || (!$input['allowed'] && $input['scope'] !== 'none')) {
            return response()->json(['message' => 'Permission scope is inconsistent'], 422);
        }
        $service->update($input, (string) ($request->attributes->get('identity', [])['sub'] ?? 'unknown'));
        return $this->index($request, $service);
    }
}
