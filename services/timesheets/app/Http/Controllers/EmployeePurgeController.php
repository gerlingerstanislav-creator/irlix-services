<?php
namespace App\Http\Controllers;

use App\Services\EmployeePurgeService;
use Illuminate\Http\Request;

final class EmployeePurgeController
{
    public function __invoke(Request $request, int $employee, EmployeePurgeService $service)
    {
        $expected = (string) env('IRLIX_INTERNAL_PURGE_TOKEN', '');
        $provided = (string) $request->header('X-Irlix-Internal-Purge-Token', '');
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Invalid internal purge token'], 403);
        }
        $service->purge($employee);
        return response()->json(['purged' => true]);
    }
}
