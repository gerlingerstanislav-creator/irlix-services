<?php

namespace App\Http\Controllers;

use App\Services\SpecialRoleService;
use App\Support\SpecialRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

final class AccessRoleController extends Controller
{
    public function __construct(private readonly SpecialRoleService $roles) {}

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->attributes->get('employees_access', [])]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->roles->catalogWithMembers()]);
    }

    /**
     * Current role projection from Employees, not from potentially stale Keycloak tokens.
     * Organizational claims and functional labels are descriptive; they do not
     * grant permissions or copy assignments into the special-role table.
     */
    public function employeeRoles(Request $request, int $employee): JsonResponse
    {
        if (!$this->isPlatformAdmin($request)) {
            return response()->json(['message' => 'Доступно только администратору платформы.'], 403);
        }
        $person = \App\Models\Employee::query()->whereKey($employee)->first();
        if (!$person) return response()->json(['message' => 'Employee not found'], 404);

        $departments = \App\Models\Department::query()->get()->all();
        $assigned = \App\Models\EmployeeAccessRole::query()->where('employee_id', $employee)
            ->pluck('role')->filter(static fn ($role) => SpecialRoles::exists((string) $role))->all();
        $labels = \App\Support\RoleLabels::canonicalRoleCatalog($departments);
        $claims = \App\Support\RoleLabels::publishedRoleClaims($person, $departments, $assigned);
        $legacy = \App\Support\RoleLabels::resolve($person, $departments, $assigned);
        $items = [];
        foreach ($claims as $claim) {
            $role = str_starts_with($claim, 'special:') ? substr($claim, 8) : null;
            $definition = $role ? (SpecialRoles::catalog()[$role] ?? []) : [];
            $items[] = [
                'key' => $claim,
                'label' => $labels[$claim] ?? $claim,
                'description' => $definition['description'] ?? '',
                'source' => str_starts_with($claim, 'org:') ? 'Подразделение'
                    : (str_starts_with($claim, 'head:') ? 'Руководство'
                        : (SpecialRoles::isAutomatic((string) $role) ? 'Оргструктура' : 'Назначена вручную')),
            ];
        }
        foreach (['account-manager' => 'Аккаунт-менеджер', 'sales-manager' => 'Менеджер по продажам'] as $key => $label) {
            if (in_array($key, $legacy, true)) {
                $items[] = ['key' => 'functional:'.$key, 'label' => $label,
                    'description' => 'Функциональная роль определяется текущими данными сотрудника.',
                    'source' => 'Функциональная'];
            }
        }
        return response()->json(['data' => $items]);
    }

    public function assign(Request $request, string $role, int $employee): JsonResponse
    {
        if (!SpecialRoles::exists($role)) return response()->json(['message' => 'Unknown special role'], 404);
        if (!$this->isPlatformAdmin($request)) return response()->json([
            'message' => 'Управление ролями доступно только администратору платформы.',
        ], 403);
        if (SpecialRoles::isAutomatic($role)) return response()->json(['message' => 'Состав автоматической роли определяется оргструктурой и недоступен для ручного изменения.'], 409);
        if (!$this->roles->employeeExists($employee)) return response()->json(['message' => 'Employee not found'], 404);

        $this->roles->assign($employee, $role);
        return response()->json(['data' => ['employee_id' => $employee, 'role' => $role]]);
    }

    public function revoke(Request $request, string $role, int $employee): Response
    {
        if (!SpecialRoles::exists($role)) return response()->json(['message' => 'Unknown special role'], 404);
        if (!$this->isPlatformAdmin($request)) return response()->json([
            'message' => 'Управление ролями доступно только администратору платформы.',
        ], 403);
        if (SpecialRoles::isAutomatic($role)) return response()->json(['message' => 'Состав автоматической роли определяется оргструктурой и недоступен для ручного изменения.'], 409);
        if ($role === SpecialRoles::PlatformAdmin && $this->roles->isSystemAdministrator($employee)) {
            return response()->json(['message' => 'Нельзя снять роль администратора платформы с системного аккаунта IRLIX.'], 409);
        }

        $this->roles->revoke($employee, $role);
        return response()->noContent();
    }

    private function isPlatformAdmin(Request $request): bool
    {
        $roles = (array) (($request->attributes->get('employees_access', []))['roles'] ?? []);
        return in_array(SpecialRoles::PlatformAdmin, $roles, true);
    }
}
