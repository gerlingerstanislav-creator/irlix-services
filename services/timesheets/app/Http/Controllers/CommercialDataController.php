<?php
namespace App\Http\Controllers;

use App\Support\CommercialData;
use Illuminate\Http\Request;

final class CommercialDataController
{
    public function clients(Request $request, CommercialData $commercial)
    {
        $expected = (string) env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN', '');
        $provided = (string) $request->header('X-Irlix-Timesheets-Token', '');
        abort_unless(strlen($expected) >= 32 && $provided !== '' && hash_equals($expected, $provided),
            403, 'Invalid reporting integration token');
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'freeze_token' => ['nullable', 'string', 'size:64'],
        ]);
        return response()->json(['data' => $commercial->read(
            $data['from'], $data['to'], isset($data['client_id']) ? (int) $data['client_id'] : null,
            $data['freeze_token'] ?? null
        )]);
    }

    public function finance(Request $request, CommercialData $commercial)
    {
        $secret = (string) env('IRLIX_FINANCE_TIMESHEETS_TOKEN', '');
        $provided = (string) $request->header('X-Irlix-Finance-Timesheets-Token', '');
        abort_unless(strlen($secret) >= 32 && $provided !== '' && hash_equals($secret, $provided),
            403, 'Finance/Timesheets access forbidden');
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        return response()->json(['data' => $commercial->read($data['from'], $data['to'])]);
    }
}
