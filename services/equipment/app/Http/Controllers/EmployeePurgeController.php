<?php

namespace App\Http\Controllers;

use App\Services\EmployeePurgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class EmployeePurgeController extends Controller
{
    public function __invoke(Request $request, int $employee, EmployeePurgeService $purge): JsonResponse
    {
        $expected = (string) env('IRLIX_INTERNAL_PURGE_TOKEN', '');
        $given = (string) $request->header('X-Irlix-Internal-Purge-Token', '');
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            return response()->json(['message' => 'Invalid internal purge token'], 403);
        }
        $purge->purge($employee, (string) $request->input('username', ''),
            (string) $request->input('employee_name', ''));
        return response()->json(['purged' => true]);
    }
}
