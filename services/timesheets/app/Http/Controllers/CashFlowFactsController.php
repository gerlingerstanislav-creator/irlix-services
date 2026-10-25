<?php

namespace App\Http\Controllers;

use App\Services\CashFlowFactsReader;
use Carbon\Carbon;
use Illuminate\Http\Request;

final class CashFlowFactsController
{
    public function __invoke(Request $request, CashFlowFactsReader $reader)
    {
        $secret = (string) env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN', '');
        $supplied = (string) $request->header('X-Irlix-Timesheets-Token', '');
        abort_unless(strlen($secret) >= 32 && $supplied !== ''
            && hash_equals($secret, $supplied), 403, 'Invalid reporting integration token');

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        abort_if(Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 62,
            422, 'Commercial facts range must not exceed 62 days');
        return response()->json(['data' => $reader->read($data['from'], $data['to'])]);
    }
}
