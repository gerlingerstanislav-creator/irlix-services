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

    public function assign(Request $request, string $role, int $employee): JsonResponse
    {
        if (!SpecialRoles::exists($role)) return response()->json(['message' => 'Unknown special role'], 404);
        if (!$this->isPlatformAdmin($request)) return response()->json([
            'message' => 'Управление ролями доступно только администратору платформы.',
        ], 403);
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
