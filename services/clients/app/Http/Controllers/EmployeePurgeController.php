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
     * Validate the full Clients DB deletion in a rollback-only transaction.
     * This MUST precede Timesheets: later Clients DB failures would otherwise
     * leave a partially erased employee across services. No CV files are touched.
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
