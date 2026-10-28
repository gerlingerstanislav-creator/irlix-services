<?php

namespace App\Http\Controllers;

use App\Services\FinancePermissionManagementService;
use App\Services\FinanceRoleAuthorization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FinancePermissionsController
{
    private function requireAdmin(Request $request): void
    {
        $roles = (array) ($request->attributes->get('identity', [])['realm_roles'] ?? []);
        abort_unless(in_array('platform-admin', $roles, true)
            || in_array('special:platform-admin', $roles, true), 403);
        // The platform's role preview must never mutate the matrix.
        abort_if($request->attributes->has('role_preview_access') && $request->method() !== 'GET',
            403, 'Role preview is read-only');
    }

    public function index(Request $request, FinancePermissionManagementService $service)
    {
        $this->requireAdmin($request);
        return response()->json(['data' => [
            'can_manage' => true,
            'enabled' => filter_var(env('IRLIX_FINANCE_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            ...$service->matrix(),
        ]]);
    }

    public function remove(Request $request, FinancePermissionManagementService $service)
    {
        $this->requireAdmin($request);
        $input = $request->validate([
            'role' => ['required', 'string', 'max:120', 'regex:/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'permission' => ['required', Rule::in(FinanceRoleAuthorization::PERMISSIONS)],
        ]);
        if ($input['role'] === 'special:platform-admin') abort(422, 'Platform superuser is immutable');
        $service->remove($input['role'], $input['permission'],
            (string) ($request->attributes->get('identity', [])['sub'] ?? 'unknown'));
        return $this->index($request, $service);
    }

    public function update(Request $request, FinancePermissionManagementService $service)
    {
        $this->requireAdmin($request);
        $input = $request->validate([
            'role' => ['required', 'string', 'max:120', 'regex:/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'permission' => ['required', Rule::in(FinanceRoleAuthorization::PERMISSIONS)],
            'allowed' => ['required', 'boolean'],
            'scope' => ['required', Rule::in(['none', 'all'])],
        ]);
        if ($input['role'] === 'special:platform-admin') {
            return response()->json(['message' => 'The platform superuser policy is not editable'], 422);
        }
        if (($input['allowed'] && $input['scope'] !== 'all')
            || (!$input['allowed'] && $input['scope'] !== 'none')) {
            return response()->json(['message' => 'Finance currently supports only full-ledger grants or denial'], 422);
        }
        $service->update($input, (string) ($request->attributes->get('identity', [])['sub'] ?? 'unknown'));
        return $this->index($request, $service);
    }
}
