<?php

namespace App\Http\Controllers;

use App\Services\SystemAccountService;
use App\Support\SpecialRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class SystemAccountController extends Controller
{
    public function __construct(private readonly SystemAccountService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        // Frontend hiding is not security. No role preview or tester access.
        $roles = (array) (($request->attributes->get('employees_access', []))['roles'] ?? []);
        if ($request->headers->has('X-Irlix-Role-Preview')
            || !in_array(SpecialRoles::PlatformAdmin, $roles, true)) {
            return response()->json(['message' => 'Platform administrator role required'], 403);
        }

        return response()->json(['data' => $this->accounts->list()]);
    }
}
