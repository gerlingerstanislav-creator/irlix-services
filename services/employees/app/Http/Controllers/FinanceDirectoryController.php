<?php

namespace App\Http\Controllers;

use App\Services\FinanceDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class FinanceDirectoryController extends Controller
{
    public function __construct(private readonly FinanceDirectoryService $directory) {}

    public function payrollConditions(Request $request): JsonResponse
    {
        $this->assertInternalToken($request, 'Salary data are restricted to Finance integration');
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        return response()->json(['data' => $this->directory->payrollConditions($data['month'])]);
    }

    public function showEmployee(Request $request, int $employee): JsonResponse
    {
        $this->assertInternalToken($request, 'Finance employee directory access forbidden');
        abort_unless($this->directory->exists($employee), 404, 'Employee ID does not exist');

        return response()->json(['data' => ['id' => $employee]]);
    }

    private function assertInternalToken(Request $request, string $message): void
    {
        $secret = (string) env('IRLIX_FINANCE_EMPLOYEES_TOKEN', '');
        $received = (string) $request->header('X-Irlix-Finance-Employees-Token', '');
        abort_unless(strlen($secret) >= 32 && $received !== ''
            && hash_equals($secret, $received), 403, $message);
    }
}
