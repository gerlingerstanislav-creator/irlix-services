<?php

namespace App\Http\Controllers;

use App\Services\EmployeeDeletionService;
use App\Support\SpecialRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

final class EmployeeDeletionController extends Controller
{
    public function __construct(private readonly EmployeeDeletionService $deletions) {}

    public function destroy(Request $request, int $employee): Response
    {
        $access = (array) $request->attributes->get('employees_access', []);
        if (!SpecialRoles::isPlatformPrivileged((array) ($access['roles'] ?? []))) {
            return response()->json(['message' => 'Platform administrator role required'], 403);
        }

        $row = $this->deletions->find($employee);
        if (!$row) return response()->json(['message' => 'Employee not found'], 404);
        if ($row->login === 'admin') return response()->json([
            'message' => 'Системный аккаунт IRLIX нельзя удалить.',
        ], 409);

        $result = $this->deletions->purge($row);
        $status = $result['status'];
        if ($status === 204) return response()->noContent();
        unset($result['status']);
        // Existing clients rely on "status" for an upstream HTTP status.
        if (isset($result['upstream_status'])) {
            $result['status'] = $result['upstream_status'];
            unset($result['upstream_status']);
        }
        return response()->json($result, $status);
    }
}
