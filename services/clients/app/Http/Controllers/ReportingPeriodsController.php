<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ReportingPeriodsController extends Controller
{
    private const STATUSES = [
        'Новый',
        'ТШ на согласовании',
        'ТШ согласованы',
        'Акт на согласовании',
        'Акт согласован',
        'Счет оплачен',
    ];

    public function store(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $this->assertNoOverlap((int) $data['client_id'], $data['period_start'], $data['period_end']);

        $id = DB::table('reporting_periods')->insertGetId([
            ...$data,
            'status' => 'Новый',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => DB::table('reporting_periods')->find($id)], 201);
    }

    public function update(Request $request, int $period)
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
        ]);
        DB::transaction(function () use ($period, $data): void {
            $current = DB::table('reporting_periods')->where('id', $period)->lockForUpdate()->first();
            abort_unless($current, 404, 'Reporting period not found');
            $index = array_search($current->status, self::STATUSES, true);
            abort_if($index === false || !isset(self::STATUSES[$index + 1]) || $data['status'] !== self::STATUSES[$index + 1], 422, 'Доступен только следующий этап отчётного периода.');
            $dates = [
                'ТШ согласованы' => 'timesheets_approved_at',
                'Акт согласован' => 'act_approved_at',
                'Счет оплачен' => 'paid_at',
            ];
            $field = $dates[$data['status']] ?? null;
            DB::table('reporting_periods')->where('id', $period)->update([
                'status' => $data['status'],
                ...($field ? [$field => now()->toDateString()] : []),
                'updated_at' => now(),
            ]);
        });
        return response()->json(['data' => DB::table('reporting_periods')->find($period)]);
    }

    public function destroy(int $period)
    {
        abort_unless(DB::table('reporting_periods')->where('id', $period)->delete(), 404, 'Reporting period not found');
        return response()->noContent();
    }

    public function show(Request $request, int $period)
    {
        $row = DB::table('reporting_periods')->find($period);
        abort_unless($row, 404, 'Reporting period not found');
        $client = DB::table('clients')->find($row->client_id);
        abort_unless($client, 404, 'Client not found');

        $from = substr((string) $row->period_start, 0, 10);
        $to = substr((string) $row->period_end, 0, 10);
        $payload = $this->timesheetsRange($request, $from, $to, (int) $row->client_id);
        $entries = collect($payload['entries'] ?? [])->values();
        $approvals = collect($payload['final_approvals'] ?? []);

        $projects = DB::table('projects')->where('client_id', $row->client_id)->get()->keyBy('id');
        $members = DB::table('project_members as pm')
            ->join('projects as p', 'p.id', '=', 'pm.project_id')
            ->where('p.client_id', $row->client_id)
            ->select('pm.*')
            ->get();
        $terms = DB::table('member_terms')->whereIn('project_member_id', $members->pluck('id'))->get();

        $grouped = [];
        foreach ($entries as $entry) {
            $employeeId = (int) ($entry['employee_id'] ?? 0);
            $projectId = (int) ($entry['project_id'] ?? 0);
            $workDate = substr((string) ($entry['work_date'] ?? ''), 0, 10);
            $hours = (float) ($entry['hours'] ?? 0);
            $member = $members->first(fn ($m) => (int) $m->specialist_id === $employeeId && (int) $m->project_id === $projectId);
            if (!$member) continue;

            $term = $terms->first(fn ($t) =>
                (int) $t->project_member_id === (int) $member->id
                && substr((string) $t->valid_from, 0, 10) <= $workDate
                && (!$t->valid_to || substr((string) $t->valid_to, 0, 10) >= $workDate)
            );
            $rate = $term ? (float) $term->hourly_rate : 0;
            $monthStart = Carbon::parse($workDate)->startOfMonth()->toDateString();
            $approved = $approvals->contains(fn ($approval) =>
                (int) ($approval['employee_id'] ?? 0) === $employeeId
                && (int) ($approval['project_id'] ?? 0) === $projectId
                && substr((string) ($approval['month'] ?? ''), 0, 10) === $monthStart
            );

            $key = $member->id.'-'.$rate;
            if (!isset($grouped[$key])) {
                $project = $projects->get($projectId);
                $grouped[$key] = [
                    'project_member_id' => (int) $member->id,
                    'employee_id' => $employeeId,
                    'employee_name' => (string) $member->specialist_name,
                    'project_id' => $projectId,
                    'project_name' => (string) (($project->name ?? null) ?: 'Основной проект'),
                    'hourly_rate' => $rate,
                    'worked_hours' => 0,
                    'worked_amount' => 0,
                    'confirmed_hours' => 0,
                    'confirmed_amount' => 0,
                ];
            }
            $grouped[$key]['worked_hours'] += $hours;
            $grouped[$key]['worked_amount'] += $hours * $rate;
            if ($approved) {
                $grouped[$key]['confirmed_hours'] += $hours;
                $grouped[$key]['confirmed_amount'] += $hours * $rate;
            }
        }

        $timesheets = collect(array_values($grouped))->map(function ($item) {
            foreach (['worked_hours', 'worked_amount', 'confirmed_hours', 'confirmed_amount'] as $field) {
                $item[$field] = round((float) $item[$field], 2);
            }
            return $item;
        })->sortBy([['employee_name', 'asc'], ['project_name', 'asc']])->values();

        return response()->json(['data' => [
            'period' => $row,
            'client' => [
                'id' => (int) $client->id,
                'name' => $client->name,
                'account_employee_id' => $client->account_employee_id,
                'act_approval_days' => $client->act_approval_days ?? null,
                'payment_days' => $client->payment_days ?? null,
            ],
            'timesheets' => $timesheets,
            'summary' => [
                'worked_hours' => round((float) $timesheets->sum('worked_hours'), 2),
                'worked_amount' => round((float) $timesheets->sum('worked_amount'), 2),
                'confirmed_hours' => round((float) $timesheets->sum('confirmed_hours'), 2),
                'confirmed_amount' => round((float) $timesheets->sum('confirmed_amount'), 2),
            ],
        ]]);
    }

    private function assertNoOverlap(int $clientId, string $from, string $to, ?int $excludeId = null): void
    {
        $query = DB::table('reporting_periods')
            ->where('client_id', $clientId)
            ->whereDate('period_start', '<=', $to)
            ->whereDate('period_end', '>=', $from);
        if ($excludeId) $query->where('id', '<>', $excludeId);
        abort_if($query->exists(), 422, 'Отчётный период пересекается с уже существующим периодом клиента.');
    }

    private function timesheetsRange(Request $request, string $from, string $to, int $clientId): array
    {
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        $pending = Http::acceptJson()->timeout(10);
        if ($token) $pending = $pending->withToken((string) $token);
        $response = $pending->get(
            rtrim((string) env('TIMESHEETS_INTERNAL_URL', 'http://timesheets:8000/internal'), '/').'/commercial-data',
            ['from' => $from, 'to' => $to, 'client_id' => $clientId],
        );
        abort_unless($response->successful(), 503, 'Timesheets service is unavailable');
        $data = $response->json('data');
        return is_array($data) ? $data : [];
    }
}
