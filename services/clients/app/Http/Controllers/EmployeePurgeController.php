<?php

namespace App\Http\Controllers;

use App\Services\EmployeePurgeService;
use Illuminate\Http\Request;

final class EmployeePurgeController extends Controller
{
    public function __construct(private readonly EmployeePurgeService $employees) {}

    private function authorized(Request $request): bool
    {
        $expected = (string) env('IRLIX_INTERNAL_PURGE_TOKEN', '');
        $provided = (string) $request->header('X-Irlix-Internal-Purge-Token', '');
        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }

    /**
     * Read-only ownership/reporting checks. This MUST precede mutating purge
     * of Timesheets: a later Clients 423 would otherwise cause partial erasure.
     */
    public function preflight(Request $request, int $employee)
    {
        if (!$this->authorized($request)) {
            return response()->json(['message' => 'Invalid internal purge token'], 403);
        }
        $this->employees->purge($employee, (string) $request->input('username', ''), true);
        return response()->json(['allowed' => true, 'client_member_ids' => $this->employees->memberIds($employee)]);
    }

    public function __invoke(Request $request, int $employee)
    {
        if (!$this->authorized($request)) {
            return response()->json(['message' => 'Invalid internal purge token'], 403);
        }
        $this->employees->purge($employee, (string) $request->input('username', ''));
        return response()->json(['purged' => true]);
    }
}
