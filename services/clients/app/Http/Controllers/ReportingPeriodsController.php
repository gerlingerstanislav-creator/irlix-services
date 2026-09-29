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
    private const STAGE_DATES = [
        'ТШ на согласовании' => 'timesheets_sent_at',
        'ТШ согласованы' => 'timesheets_approved_at',
        'Акт на согласовании' => 'act_sent_at',
        'Акт согласован' => 'act_approved_at',
        'Счет оплачен' => 'paid_at',
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
            'date' => ['nullable', 'date'],
        ]);
        $rollback = false;
        DB::transaction(function () use ($request, $period, $data, &$rollback): void {
            $current = DB::table('reporting_periods')->where('id', $period)->lockForUpdate()->first();
            abort_unless($current, 404, 'Reporting period not found');
            $index = array_search($current->status, self::STATUSES, true);
            abort_if($index === false, 422, 'Неизвестный статус отчётного периода.');
            $rollback = $index > 0 && $data['status'] === self::STATUSES[$index - 1];
            $forward = isset(self::STATUSES[$index + 1]) && $data['status'] === self::STATUSES[$index + 1];
            abort_unless($rollback || $forward, 422, 'Доступен только соседний этап отчётного периода.');

            if ($rollback) {
                abort_unless($this->canRollback($request), 403, 'Откат доступен только руководителю клиентской службы или аккаунтинга.');
                $field = self::STAGE_DATES[$current->status];
                DB::table('reporting_periods')->where('id', $period)->update([
                    'status' => $data['status'], $field => null, 'updated_at' => now(),
                ]);
                return;
            }

            abort_unless(!empty($data['date']), 422, 'Укажите дату нового этапа.');
            if ($index > 0) {
                $previousDate = self::STAGE_DATES[$current->status];
                abort_if(empty($current->$previousDate), 422, 'Для предыдущего этапа не заполнена дата.');
            }
            if ($index === 0) {
                $payload = $this->timesheetsRange($request, substr((string) $current->period_start, 0, 10), substr((string) $current->period_end, 0, 10), (int) $current->client_id);
                $activeEntries = collect($payload['entries'] ?? [])->filter(function ($entry) use ($current): bool {
                    if ((float) ($entry['hours'] ?? 0) <= 0 || empty($entry['work_date'])) return false;
                    return DB::table('member_terms as mt')
                        ->join('project_members as pm', 'pm.id', '=', 'mt.project_member_id')
                        ->join('projects as p', 'p.id', '=', 'pm.project_id')
                        ->where('p.client_id', $current->client_id)
                        ->where('pm.project_id', (int) ($entry['project_id'] ?? 0))
                        ->where('pm.specialist_id', (int) ($entry['employee_id'] ?? 0))
                        ->whereDate('mt.valid_from', '<=', $entry['work_date'])
                        ->where(fn ($query) => $query->whereNull('mt.valid_to')->orWhereDate('mt.valid_to', '>=', $entry['work_date']))
                        ->exists();
                });
                abort_if($activeEntries->isEmpty(), 422, 'Нельзя отправить на согласование период без действующих заполненных ТШ.');
                $approvals = collect($payload['final_approvals'] ?? []);
                $allApproved = $activeEntries->every(fn ($entry) => $approvals->contains(fn ($approval) =>
                    (int) ($approval['employee_id'] ?? 0) === (int) $entry['employee_id']
                    && (int) ($approval['project_id'] ?? 0) === (int) $entry['project_id']
                    && substr((string) ($approval['month'] ?? ''), 0, 7) === substr((string) $entry['work_date'], 0, 7)
                ));
                abort_unless($allApproved, 422, 'Перед отправкой клиенту аккаунт-менеджер должен подтвердить все ТШ отчётного периода.');
            }
            DB::table('reporting_periods')->where('id', $period)->update([
                'status' => $data['status'],
                self::STAGE_DATES[$data['status']] => $data['date'],
                'updated_at' => now(),
            ]);
        });
        return response()->json(['data' => DB::table('reporting_periods')->find($period)]);
    }

    public function lockStatus(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'work_date' => ['required', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:work_date'],
        ]);
        $locked = DB::table('reporting_periods')
            ->where('client_id', $data['client_id'])
            ->whereDate('period_start', '<=', $data['to'] ?? $data['work_date'])
            ->whereDate('period_end', '>=', $data['work_date'])
            ->where('status', '<>', 'Новый')
            ->exists();
        return response()->json(['data' => ['locked' => $locked]]);
    }

    private function canRollback(Request $request): bool
    {
        $token = $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
        if (!$token) return false;
        $base = rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/');

        try {
            $access = Http::withToken((string) $token)->acceptJson()->timeout(5)->get($base.'/access/me');
            if (!$access->successful()) return false;
            $roles = array_map(fn ($role) => str_replace('_', '-', mb_strtolower((string) $role)), $access->json('data.roles', []));
            if (in_array('platform-admin', $roles, true)) return true;

            $profile = Http::withToken((string) $token)->acceptJson()->timeout(5)->get($base.'/self');
            if (!$profile->successful()) return false;
            $position = mb_strtolower((string) $profile->json('data.position', ''));
            return str_contains($position, 'руководитель клиентской службы')
                || str_contains($position, 'руководитель аккаунтинга')
                || str_contains($position, 'руководитель направления аккаунтинга');
        } catch (\Throwable) {
            return false;
        }
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
        $terms = DB::table('member_terms')
            ->whereIn('project_member_id', $members->pluck('id'))
            ->whereDate('valid_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from))
            ->orderBy('valid_from')
            ->get();

        $grouped = [];
        foreach ($terms as $term) {
            $member = $members->firstWhere('id', $term->project_member_id);
            if (!$member) continue;
            $project = $projects->get((int) $member->project_id);
            $grouped[$term->id] = [
                'term_id' => (int) $term->id,
                'project_member_id' => (int) $member->id,
                'employee_id' => (int) $member->specialist_id,
                'employee_name' => (string) $member->specialist_name,
                'project_id' => (int) $member->project_id,
                'project_name' => (string) (($project->name ?? null) ?: 'Основной проект'),
                'valid_from' => $term->valid_from,
                'valid_to' => $term->valid_to,
                'hourly_rate' => (float) $term->hourly_rate,
                'worked_hours' => 0,
                'worked_amount' => 0,
                'confirmed_hours' => 0,
                'confirmed_amount' => 0,
                'account_confirmed' => $approvals->contains(fn ($approval) =>
                    (int) ($approval['employee_id'] ?? 0) === (int) $member->specialist_id
                    && (int) ($approval['project_id'] ?? 0) === (int) $member->project_id
                    && substr((string) ($approval['month'] ?? ''), 0, 7) === substr($from, 0, 7)
                ),
            ];
        }
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
            if (!$term || !isset($grouped[$term->id])) continue;
            $rate = (float) $term->hourly_rate;
            $monthStart = Carbon::parse($workDate)->startOfMonth()->toDateString();
            $approved = $approvals->contains(fn ($approval) =>
                (int) ($approval['employee_id'] ?? 0) === $employeeId
                && (int) ($approval['project_id'] ?? 0) === $projectId
                && substr((string) ($approval['month'] ?? ''), 0, 10) === $monthStart
            );

            $grouped[$term->id]['worked_hours'] += $hours;
            $grouped[$term->id]['worked_amount'] += $hours * $rate;
            if ($approved) {
                $grouped[$term->id]['confirmed_hours'] += $hours;
                $grouped[$term->id]['confirmed_amount'] += $hours * $rate;
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
            'can_rollback' => $this->canRollback($request),
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
