<?php

namespace App\Http\Controllers;

use App\Services\FinanceClientsService;
use Carbon\Carbon;
use Illuminate\Http\Request;

final class FinanceClientsController extends Controller
{
    public function __construct(private readonly FinanceClientsService $finance) {}

    private function allowed(Request $request): void
    {
        $secret = (string) env('IRLIX_FINANCE_CLIENTS_TOKEN', '');
        $provided = (string) $request->header('X-Irlix-Finance-Token', '');
        abort_unless(strlen($secret) >= 32 && $provided !== '' &&
            hash_equals($secret, $provided), 403, 'Finance integration forbidden');
    }

    public function projects(Request $request)
    {
        $this->allowed($request);
        return response()->json(['data' => $this->finance->projectsDirectory()]);
    }

    public function directCostEntity(Request $request)
    {
        $this->allowed($request);
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'project_id' => ['nullable', 'integer', 'min:1'],
        ]);
        return response()->json(['data' => $this->finance->directCostEntity(
            (int) $data['client_id'], isset($data['project_id']) ? (int) $data['project_id'] : null
        )]);
    }

    public function periods(Request $request)
    {
        $this->allowed($request);
        return response()->json(['data' => $this->finance->approvedPeriods()]);
    }

    public function terms(Request $request)
    {
        $this->allowed($request);
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        abort_if(Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 31,
            422, 'Only one month at a time');
        return response()->json(['data' => $this->finance->commercialTerms($data['from'], $data['to'])]);
    }

    public function period(Request $request, int $period)
    {
        $this->allowed($request);
        return response()->json(['data' => $this->finance->period($period)]);
    }

    public function paid(Request $request, int $period)
    {
        $this->allowed($request);
        $data = $request->validate([
            'ledger_version' => ['required', 'integer', 'min:1'],
            'invoice_count' => ['required', 'integer', 'min:1'],
            'paid_total_kopecks' => ['required', 'integer', 'min:1'],
            'evidence_id' => ['required', 'string', 'max:180'],
        ]);
        return response()->json(['data' => $this->finance->paid($period, $data)]);
    }
}
