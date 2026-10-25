<?php

namespace App\Http\Controllers;

use App\Services\CashFlowFactsReader;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'client_id' => ['nullable', 'integer', 'min:1'],
            'client_member_ids' => ['nullable', 'array', 'max:10000'],
            'client_member_ids.*' => ['integer', 'min:1'],
            'freeze_token' => ['nullable', 'string', 'size:64'],
        ]);
        $scoped = array_key_exists('client_id', $data);
        abort_if($scoped !== array_key_exists('client_member_ids', $data), 422,
            'Reporting period must provide both client identity and membership IDs');
        abort_if(!$scoped && Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 62,
            422, 'Cash flow snapshot must not exceed 62 days');

        $snapshot = $reader->read($data['from'], $data['to'], $scoped ? $data['client_member_ids'] : null);
        if (isset($data['freeze_token'])) {
            abort_unless($scoped, 422, 'Frozen snapshot requires client scope');
            $freeze = DB::table('reporting_freezes')->where('freeze_token', $data['freeze_token'])->first();
            abort_unless($freeze && (int) $freeze->client_id === (int) $data['client_id']
                && $freeze->period_start === $data['from'] && $freeze->period_end === $data['to'],
                409, 'Reporting freeze does not cover requested period');
            abort_unless(hash_equals((string) $freeze->revision, $snapshot['revision']), 409,
                'Frozen Timesheets revision has changed');
        }
        return response()->json(['data' => $snapshot]);
    }
}
