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

    /**
     * One trusted permissions endpoint for the Timesheets frontend. During
     * migration only, it relays the historical Clients matrix; afterwards
     * it resolves solely the Timesheets database against verified JWT roles.
     */
    public function me(Request $request)
    {
        $jwt = filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        $local = $jwt && filter_var(env('IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        if ($local) {
            $roles = (array) ($request->attributes->get('identity', [])['realm_roles'] ?? []);
            $permissions = $request->attributes->get('timesheets_local_permissions');
            if (!is_array($permissions)) {
                $permissions = app(TimesheetsRoleAuthorization::class)->forVerifiedRoles($roles);
            }
            return response()->json(['data' => ['permissions' => $permissions, 'mode' => 'local']]);
        }

        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        if (!$token) return response()->json(['message' => 'Authentication required'], 401);
        try {
            $response = \Illuminate\Support\Facades\Http::withToken((string) $token)
                ->acceptJson()->timeout(6)->get(
                    rtrim((string) env('CLIENTS_URL', 'http://clients:8000/api'), '/').'/permissions/me'
                );
        } catch (\Throwable) {
            return response()->json(['message' => 'Legacy permission matrix unavailable'], 503);
        }
        if (!$response->successful()) {
            return response()->json(['message' => 'Legacy permission matrix unavailable'], 503);
        }
        return response()->json(['data' => [
            'permissions' => (array) $response->json('data.permissions', []),
            'mode' => 'legacy',
        ]]);
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
