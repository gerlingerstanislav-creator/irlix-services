<?php

namespace App\Http\Controllers;

use App\Services\PermissionRuleService;
use App\Support\PermissionScopePolicy;
use App\Support\EmployeesAccess;
use App\Models\Employee;
use App\Models\Department;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class PermissionRuleController extends Controller
{
    public function __construct(private readonly PermissionRuleService $rules, private readonly EmployeesAccess $accessResolver) {}

    public function index(Request $request): JsonResponse
    {
        if (!$this->canManage($request)) return response()->json(['message' => 'Forbidden'], 403);

        return response()->json(['data' => [
            'roles' => $this->rules->roleCatalog(),
            'permissions' => $this->rules->permissionCatalog(),
            'rules' => $this->rules->rules(),
            'scope_policies' => $this->rules->scopeCatalog(),
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

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'scope' => ['nullable', Rule::in(['own', 'all'])],
        ]);
        $enabled = (bool) $data['enabled'];
        $scope = $enabled && PermissionScopePolicy::scoped($permission)
            ? ($data['scope'] ?? PermissionScopePolicy::defaultForRole($role)) : null;
        $options = PermissionScopePolicy::options($role, $permission);
        if (($scope !== null && !in_array($scope, $options, true))
            || (!PermissionScopePolicy::scoped($permission) && !empty($data['scope']))
            || (!$enabled && !empty($data['scope']))) {
            return response()->json(['message' => 'Unsupported scope for this role and permission'], 422);
        }
        $this->rules->setRule($role, $permission, $enabled, $scope);
        return response()->json(['data' => [
            'role' => $role, 'permission' => $permission, 'enabled' => $enabled, 'scope' => $scope,
        ]]);
    }

    /**
     * Read-only explanation of one real employee's effective permissions.
     * Never impersonates that employee or trusts a preview identity.
     */
    public function effective(Request $request, int $employee): JsonResponse
    {
        if (!$this->canManage($request)) return response()->json(['message' => 'Forbidden'], 403);
        $subject = Employee::query()->business()->whereKey($employee)->first();
        if (!$subject) return response()->json(['message' => 'Not found'], 404);
        $access = $this->accessResolver->resolveEmployee($subject);
        $ids = collect($access['permission_scopes'] ?? [])
            ->flatMap(static fn (array $policy) => collect($policy['sources'] ?? [])
                ->flatMap(static fn (array $source) => $source['department_ids'] ?? []))
            ->unique()->values()->all();
        $names = Department::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
        return response()->json(['data' => [
            'employee_id' => (int) $subject->id,
            'roles' => $access['roles'],
            'permission_scopes' => $access['permission_scopes'],
            'permissions' => $access['permissions'],
            'department_names' => $names,
            'role_labels' => $this->roleLabels($access['roles']),
        ]]);
    }

    private function roleLabels(array $roles): array
    {
        $labels = $this->rules->roleCatalog();
        return array_replace(array_fill_keys($roles, ''), array_intersect_key($labels, array_flip($roles)));
    }

    private function canManage(Request $request): bool
    {
        return (bool) ($request->attributes->get('employees_access', [])['permissions']['employees.permissions.manage'] ?? false);
    }
}
