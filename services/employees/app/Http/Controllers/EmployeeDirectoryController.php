<?php

namespace App\Http\Controllers;

use App\Services\EmployeeDirectoryService;
use App\Support\EmployeesAccess;
use App\Support\KeycloakAuthorizationSync;
use App\Support\SpecialRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class EmployeeDirectoryController extends Controller
{
    public function __construct(private readonly EmployeeDirectoryService $directory) {}

    public function preview(Request $request, EmployeesAccess $resolver): JsonResponse
    {
        $realAccess = (array) $request->attributes->get('employees_access', []);
        $role = trim((string) $request->header('X-Irlix-Role-Preview', ''));
        if ($role === '') return response()->json(['message' => 'Role selection is required'], 422);
        $preview = $resolver->preview($realAccess, $role);
        if ($preview === null) return response()->json(['message' => 'Role preview is not permitted'], 403);
        $preview['jwt_grants'] = KeycloakAuthorizationSync::grants($preview);
        return response()->json(['data' => $preview]);
    }

    private function actor(Request $request): ?object
    {
        return $this->directory->actor((array) $request->attributes->get('identity', []));
    }

    public function self(Request $request): JsonResponse
    {
        if (empty($request->attributes->get('identity', [])['sub'])) {
            return response()->json(['message' => 'Authenticated identity has no subject'], 401);
        }
        $employee = $this->actor($request);
        if (!$employee) return response()->json(['message' => 'Employee profile is not linked to this account'], 404);
        return response()->json(['data' => $employee]);
    }

    public function equipment(Request $request, ?int $employee = null): JsonResponse
    {
        $access = (array) $request->attributes->get('employees_access', []);
        if (($access['services']['equipment'] ?? false) !== true) {
            return response()->json(['message' => 'Equipment directory access is not granted for this account'], 403);
        }
        $result = $this->directory->equipmentDirectory($request->boolean('include_former'), $employee);
        if ($employee !== null) return $result
            ? response()->json(['data' => ['employee' => $result]])
            : response()->json(['message' => 'Employee not found'], 404);
        return response()->json(['data' => $result]);
    }

    public function clients(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if (!$actor) return response()->json(['message' => 'Employee profile is not linked to this account'], 404);
        return response()->json(['data' => $this->directory->clientsDirectory($actor)]);
    }

    public function vacations(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if (!$actor) return response()->json(['message' => 'Employee profile is not linked to this account'], 404);
        $access = (array) $request->attributes->get('employees_access', []);
        if ($request->has('historical_year')) {
            $year = filter_var($request->query('historical_year'), FILTER_VALIDATE_INT);
            if ($year === false || $year < 2000 || $year > 2100) {
                return response()->json(['message' => 'Invalid historical year'], 422);
            }
            return response()->json(['data' => $this->directory->vacationsHistoricalDirectory($access, $year)]);
        }
        return response()->json(['data' => $this->directory->vacationsDirectory($actor, $access)]);
    }

    public function specialists(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if (!$actor) return response()->json(['message' => 'Employee profile is not linked to this account'], 404);
        $result = $this->directory->specialistsDirectory(
            $actor, (array) $request->attributes->get('employees_access', [])
        );
        $status = $result['status'];
        unset($result['status']);
        return response()->json($status === 200 ? ['data' => $result['data']] : $result, $status);
    }

    public function selfAbsence(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if (!$actor) return response()->json(['message' => 'Employee profile is not linked to this account'], 404);
        return response()->json(['data' => $this->directory->absenceApprovalContext((int) $actor->id)]);
    }

    public function absence(Request $request, int $employee): JsonResponse
    {
        $actor = $this->actor($request);
        if (!$actor) return response()->json(['message' => 'Employee profile is not linked to this account'], 404);

        if ((int) $actor->id !== $employee) {
            $access = (array) $request->attributes->get('employees_access', []);
            $roles = array_values(array_unique(array_map('strval', $access['roles'] ?? [])));
            $globalPersonnel = in_array(SpecialRoles::PersonnelOfficer, $roles, true)
                || SpecialRoles::isPlatformPrivileged($roles);
            if (!$globalPersonnel && !($access['permissions']['employees.read'] ?? false)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }
        $context = $this->directory->absenceApprovalContext($employee);
        if (!$context) return response()->json(['message' => 'Employee not found'], 404);
        return response()->json(['data' => $context]);
    }
}
