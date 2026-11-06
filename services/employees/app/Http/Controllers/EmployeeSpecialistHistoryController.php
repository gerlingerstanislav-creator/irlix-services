<?php

namespace App\Http\Controllers;

use App\Services\EmployeeDirectoryService;
use App\Services\EmployeeSpecialistHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class EmployeeSpecialistHistoryController extends Controller
{
    public function __construct(
        private readonly EmployeeDirectoryService $directory,
        private readonly EmployeeSpecialistHistoryService $history,
    ) {}

    public function show(Request $request, int $employee): JsonResponse
    {
        if ($request->header('X-Irlix-Role-Preview') !== null)
            return response()->json(['message' => 'Role preview is not permitted'], 403);
        $access = (array) $request->attributes->get('employees_access', []);
        if (($access['services']['specialists'] ?? false) !== true)
            return response()->json(['message' => 'Specialists access denied'], 403);
        $actor = $this->directory->actor((array) $request->attributes->get('identity', []));
        if (!$actor) return response()->json(['message' => 'Employee identity unavailable'], 403);
        $scope = $this->directory->specialistsDirectory($actor, $access);
        if (($scope['status'] ?? 403) !== 200) {
            return response()->json(['message' => 'Specialists scope denied'], 403);
        }
        $visible = collect($scope['data']['employees'] ?? [])
            ->contains(static fn ($person) => (int) ($person->id ?? 0) === $employee);
        if (!$visible) return response()->json(['message' => 'Employee not in scope'], 404);

        return response()->json(['data' => $this->history->timeline($employee)]);
    }
}
