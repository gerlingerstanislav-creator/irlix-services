<?php

namespace App\Http\Controllers;

use App\Services\StorageV1DirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class StorageV1DirectoryController extends Controller
{
    public function __construct(private readonly StorageV1DirectoryService $directory) {}

    private function allowed(Request $request): bool
    {
        $access = (array) $request->attributes->get('employees_access', []);
        return $this->directory->actorActive((int) ($access['employee_id'] ?? 0))
            && ($access['services']['storage'] ?? false) === true;
    }

    public function people(Request $request): JsonResponse
    {
        if (!$this->allowed($request)) return response()->json(['message' => 'Forbidden'], 403);
        return response()->json(['data' => $this->directory->people()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function departments(Request $request): JsonResponse
    {
        if (!$this->allowed($request)) return response()->json(['message' => 'Forbidden'], 403);
        return response()->json(['data' => $this->directory->departments()])
            ->header('Cache-Control', 'private, no-store');
    }
}
