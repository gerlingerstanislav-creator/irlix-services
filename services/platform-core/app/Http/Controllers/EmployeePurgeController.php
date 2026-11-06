<?php

namespace App\Http\Controllers;

use App\Reports\EmployeePurgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class EmployeePurgeController extends Controller
{
    public function __invoke(Request $request, int $employee, EmployeePurgeService $purge): JsonResponse
    {
        $expected = (string) env('IRLIX_INTERNAL_PURGE_TOKEN', '');
        $provided = (string) $request->header('X-Irlix-Internal-Purge-Token', '');
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Invalid internal purge token'], 403);
        }
        $purge->purge($employee);
        return response()->json(['purged' => true]);
    }
}
