<?php
namespace App\Http\Controllers;

use App\Services\ReportingFreezeService;
use Illuminate\Http\Request;

final class ReportingFreezeController
{
    private function authorizeClients(Request $request): void
    {
        $expected = (string) env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN', '');
        $provided = (string) $request->header('X-Irlix-Timesheets-Token', '');
        abort_unless(strlen($expected) >= 32 && $provided !== '' && hash_equals($expected, $provided),
            403, 'Invalid reporting integration token');
    }

    public function createLegacy(Request $request, ReportingFreezeService $service)
    {
        $this->authorizeClients($request);
        $data = $request->validate([
            'reporting_period_id' => ['required', 'integer', 'min:1'],
            'client_id' => ['required', 'integer', 'min:1'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        return response()->json(['data' => $service->createLegacy($data)]);
    }

    public function createCanonical(Request $request, ReportingFreezeService $service)
    {
        $this->authorizeClients($request);
        $data = $request->validate([
            'reporting_period_id' => ['required', 'integer', 'min:1'],
            'client_id' => ['required', 'integer', 'min:1'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'client_member_ids' => ['required', 'array', 'min:1', 'max:10000'],
            'client_member_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'membership_revision' => ['required', 'regex:/^[0-9a-f]{64}$/'],
        ]);
        return response()->json(['data' => $service->createCanonical($data)]);
    }

    public function release(Request $request, int $period, ReportingFreezeService $service)
    {
        $this->authorizeClients($request);
        $data = $request->validate(['freeze_token' => ['required', 'string', 'size:64']]);
        $service->release($period, $data['freeze_token']);
        return response()->json(['data' => ['ok' => true]]);
    }
}
