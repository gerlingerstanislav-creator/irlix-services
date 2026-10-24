<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CashFlowController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $start = Carbon::createFromFormat('Y-m-d', $data['month'].'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $monthStart = $start->toDateString();
        $monthEnd = $end->toDateString();

        $timesheets = $this->timesheetsRange($request, $monthStart, $monthEnd);
        $entries = collect($timesheets['entries'] ?? []);
        $approvals = collect($timesheets['final_approvals'] ?? []);
        $exceptions=collect($timesheets['final_approval_exceptions']??[]);

        $terms = DB::table('member_terms as mt')
            ->join('client_members as cm','cm.id','=','mt.client_member_id')
            ->whereDate('mt.valid_from', '<=', $monthEnd)
            ->where(function ($query) use ($monthStart) {
                $query->whereNull('mt.valid_to')->orWhereDate('mt.valid_to', '>=', $monthStart);
            })
            ->select([
                'mt.id as term_id',
                'mt.project_member_id',
                'mt.hourly_rate',
                'mt.valid_from',
                'mt.valid_to',
                'cm.employee_id as specialist_id','cm.partner_specialist_id','cm.id as client_member_id','cm.client_id',
            ])
            ->get();

        $rows = $terms->map(function ($term) use ($entries, $approvals,$exceptions, $monthStart, $monthEnd) {
            $from = max(substr((string) $term->valid_from, 0, 10), $monthStart);
            $to = min($term->valid_to ? substr((string) $term->valid_to, 0, 10) : $monthEnd, $monthEnd);

            $termEntries = $entries->filter(fn ($entry) =>
                (int)($entry['client_member_id']??0)===(int)$term->client_member_id && empty($entry['term_conflict'])
                && substr((string) ($entry['work_date'] ?? ''), 0, 10) >= $from
                && substr((string) ($entry['work_date'] ?? ''), 0, 10) <= $to
            );

            $timesheetHours = round((float) $termEntries->sum(fn ($entry) => (float) ($entry['hours'] ?? 0)), 2);
            $rate = $term->hourly_rate === null ? null : (float) $term->hourly_rate;
            $timesheetAmount = $rate === null ? null : round($timesheetHours * $rate, 2);

            $approved = $approvals->contains(fn ($approval) =>
                (int)($approval['client_member_id']??0)===(int)$term->client_member_id
                && substr((string) ($approval['month'] ?? ''), 0, 10) === $monthStart
            );

            $approved=$approved && !$exceptions->contains(fn($exception)=>(int)($exception['client_member_id']??0)===(int)$term->client_member_id
                && $termEntries->contains(fn($entry)=>substr((string)$entry['work_date'],0,10)===substr((string)($exception['work_date']??''),0,10)));

            return [
                'term_id' => (int) $term->term_id,
                'project_member_id' => $term->project_member_id,'client_member_id'=>(int)$term->client_member_id,'client_id'=>(int)$term->client_id,
                'timesheet_hours' => $timesheetHours,
                'timesheet_amount' => $timesheetAmount,
                'confirmed_hours' => $approved ? $timesheetHours : 0,
                'confirmed_amount' => $rate === null ? null : ($approved ? $timesheetAmount : 0),
                'final_approved' => $approved,
            ];
        })->values();

        return response()->json(['data' => [
            'month' => $data['month'],
            'rows' => $rows,
        ]]);
    }

    private function timesheetsRange(Request $request, string $from, string $to): array
    {
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        $pending = Http::acceptJson()->withHeaders(['X-Irlix-Timesheets-Token'=>(string)env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN','')])->timeout(10);
        if ($token) $pending = $pending->withToken((string) $token);

        $response = $pending->get(
            rtrim((string) env('TIMESHEETS_INTERNAL_URL', 'http://timesheets:8000/internal'), '/').'/commercial-data',
            ['from' => $from, 'to' => $to],
        );

        abort_unless($response->successful(), 503, 'Timesheets service is unavailable');
        $data = $response->json('data');

        return \App\Support\CommercialSnapshot::validate($data,$from,$to,null);
    }
}
