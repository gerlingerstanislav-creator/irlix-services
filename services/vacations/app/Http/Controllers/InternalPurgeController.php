<?php

namespace App\Http\Controllers;

use App\Application\EmployeePurgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InternalPurgeController extends Controller
{
    public function __construct(private readonly EmployeePurgeService $purge) {}

    public function employee(Request $request, int $employee): JsonResponse
    {
        $expected = (string) env('IRLIX_INTERNAL_PURGE_TOKEN', '');
        $provided = (string) $request->header('X-Irlix-Internal-Purge-Token', '');
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Invalid internal purge token'], 403);
        }
        $subject = (string) $request->input('keycloak_subject', '');
        if (!$this->purge->purge($employee, $subject)) {
            return response()->json(['message' => 'Failed to remove employee attachment'], 500);
        }
        return response()->json(['purged' => true]);
    }
}
