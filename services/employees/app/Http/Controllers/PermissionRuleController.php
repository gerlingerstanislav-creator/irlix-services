<?php

namespace App\Http\Controllers;

use App\Services\PermissionRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class PermissionRuleController extends Controller
{
    public function __construct(private readonly PermissionRuleService $rules) {}

    public function index(Request $request): JsonResponse
    {
        if (!$this->canManage($request)) return response()->json(['message' => 'Forbidden'], 403);

        return response()->json(['data' => [
            'roles' => $this->rules->roleCatalog(),
            'permissions' => $this->rules->permissionCatalog(),
            'rules' => $this->rules->rules(),
        ]]);
    }

    public function update(Request $request, string $role, string $permission): JsonResponse
    {
        if (!$this->canManage($request)) return response()->json(['message' => 'Forbidden'], 403);
        if (!isset($this->rules->roleCatalog()[$role])
            || !isset($this->rules->permissionCatalog()[$permission])) {
            return response()->json(['message' => 'Unknown role or permission'], 404);
        }
        if ($role === 'platform-admin') return response()->json([
            'message' => 'Platform admin permissions cannot be restricted',
        ], 403);

        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->rules->setRule($role, $permission, (bool) $data['enabled']);
        return response()->json(['data' => [
            'role' => $role, 'permission' => $permission, 'enabled' => (bool) $data['enabled'],
        ]]);
    }

    private function canManage(Request $request): bool
    {
        return (bool) ($request->attributes->get('employees_access', [])['permissions']['employees.permissions.manage'] ?? false);
    }
}
