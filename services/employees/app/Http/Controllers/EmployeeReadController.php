<?php

namespace App\Http\Controllers;

use App\Services\EmployeeReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class EmployeeReadController extends Controller
{
    public function __construct(private readonly EmployeeReadService $employees) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->employees->list($request->query()));
    }

    public function show(int $employee): JsonResponse
    {
        $details = $this->employees->details($employee);
        if (!$details) return response()->json(['message' => 'Employee not found'], 404);
        return response()->json(['data' => $details]);
    }

    public function loginSuggestions(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->employees->loginSuggestions(
            (string) $request->query('first_name', ''),
            (string) $request->query('last_name', ''),
            (string) $request->query('login', '')
        )]);
    }
}
