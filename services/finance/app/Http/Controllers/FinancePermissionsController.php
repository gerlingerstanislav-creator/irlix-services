<?php

namespace App\Http\Controllers;

use App\Models\FinanceRolePermission;
use App\Services\FinanceRoleAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function index(Request $request)
    {
        $this->requireAdmin($request);
        return response()->json(['data' => [
            'can_manage' => true,
            'enabled' => filter_var(env('IRLIX_FINANCE_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'permissions' => FinanceRoleAuthorization::PERMISSIONS,
            'scopes' => ['none', 'all'],
            'matrix' => FinanceRolePermission::query()->orderBy('role')->orderBy('permission')->get(),
        ]]);
    }

    public function update(Request $request)
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
        DB::transaction(static function () use ($input, $request): void {
            $row = FinanceRolePermission::query()->where('role', $input['role'])
                ->where('permission', $input['permission'])->lockForUpdate()->first();
            $before = $row ? ['allowed' => (bool) $row->allowed, 'scope' => $row->scope] : null;
            FinanceRolePermission::query()->updateOrCreate(
                ['role' => $input['role'], 'permission' => $input['permission']],
                ['allowed' => $input['allowed'], 'scope' => $input['scope']]
            );
            DB::table('finance_permission_audit')->insert([
                'actor' => (string) ($request->attributes->get('identity', [])['sub'] ?? 'unknown'),
                'role' => $input['role'], 'permission' => $input['permission'],
                'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode(['allowed' => $input['allowed'], 'scope' => $input['scope']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });
        return $this->index($request);
    }
}
