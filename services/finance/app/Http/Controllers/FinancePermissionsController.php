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
