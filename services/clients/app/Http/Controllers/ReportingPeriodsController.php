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

        $client = DB::table('clients')->where('id', (int) $data['client_id'])->first();
        abort_unless($client, 422, 'Client not found');
        $id = DB::table('reporting_periods')->insertGetId([
            ...$data,
            'overtime_multiplier' => $client->overtime_multiplier,
            'status' => 'Новый',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->recordEvent($id, $request, 'created', 'Отчётный период создан', $data['period_start'].' — '.$data['period_end']);
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
            // Terminal paid state is Finance-owned and cannot be rolled back by AM,
            // directors or platform-admin through the generic Clients workflow.
            abort_if($current->status === 'Счет оплачен' || $data['status'] === 'Счет оплачен', 423,
                'Оплата периода фиксируется Finance и не может быть изменена вручную.');
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
                $this->recordEvent($period, $request, 'status', 'Этап возвращён', $current->status.' → '.$data['status']);
                \App\Support\ClientHistory::record((int)$current->client_id,'reporting_rollback','Откат этапа отчётного периода',
                    substr((string)$current->period_start,0,10).' — '.substr((string)$current->period_end,0,10).': '.$current->status.' → '.$data['status'],
                    $request->attributes->get('identity',[])['preferred_username'] ?? null);
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
            $this->recordEvent($period, $request, 'status', 'Этап изменён', $current->status.' → '.$data['status'].' ('.substr((string)$data['date'],0,10).')');
            DB::table('reporting_periods')->where('id', $period)->update([
                'status' => $data['status'],
                self::STAGE_DATES[$data['status']] => $data['date'],
                'updated_at' => now(),
            ]);
        });
        return response()->json(['data' => DB::table('reporting_periods')->find($period)]);
    }

    public function storeNote(Request $request, int $period)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:5000']]);
        abort_unless(DB::table('reporting_periods')->where('id', $period)->exists(), 404, 'Reporting period not found');
        $this->recordEvent($period, $request, 'comment', 'Комментарий', trim($data['text']));
        return response()->json(['data' => true], 201);
    }

    private function recordEvent(int $period, Request $request, string $type, string $title, ?string $text = null): void
    {
        $identity = $request->attributes->get('identity', []);
        DB::table('reporting_period_history')->insert([
            'reporting_period_id' => $period,
            'type' => $type,
            'title' => $title,
            'text' => $text,
            'author' => $identity['preferred_username'] ?? $identity['name'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function updateApprovedHours(Request $request, int $period, int $term)
    {
        $data = $request->validate([
            'hours' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'overtime_hours' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'expected_hours' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'expected_overtime_hours' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
        ]);
        abort_if(!array_key_exists('hours', $data) && !array_key_exists('overtime_hours', $data),
            422, 'Укажите основные часы или переработку.');
        $periodRow = DB::table('reporting_periods')->find($period);
        abort_unless($periodRow, 404, 'Reporting period not found');
        abort_if($periodRow->status === 'Счет оплачен', 422, 'Оплаченный период нельзя редактировать.');
        $memberTerm = DB::table('member_terms as mt')
            ->join('project_members as pm', 'pm.id', '=', 'mt.project_member_id')
            ->join('projects as p', 'p.id', '=', 'pm.project_id')
            ->where('mt.id', $term)->where('p.client_id', $periodRow->client_id)
            ->whereDate('mt.valid_from', '<=', $periodRow->period_end)
            ->where(fn ($q) => $q->whereNull('mt.valid_to')->orWhereDate('mt.valid_to', '>=', $periodRow->period_start))
            ->select('mt.id')->first();
        abort_unless($memberTerm, 422, 'Ставка не принадлежит отчётному периоду.');
        $saved = DB::transaction(function () use ($request, $period, $term, $data): object {
            // Coordinate with paid transition, which locks the period row.
            $current = DB::table('reporting_periods')->where('id', $period)->lockForUpdate()->first();
            abort_unless($current, 404, 'Reporting period not found');
            abort_if($current->status === 'Счет оплачен', 423, 'Оплаченный период нельзя редактировать.');
            $query = DB::table('reporting_period_approved_hours')
                ->where('reporting_period_id', $period)->where('member_term_id', $term);
            $prior = $query->first();
            // Optimistic checks are per field. Two AMs changing different
            // fields can both succeed, but a stale edit to the SAME figure
            // must not silently replace a previously saved value.
            $previousRegular = $prior ? (float) $prior->hours : null;
            $previousOvertime = (float) ($prior->overtime_hours ?? 0);
            if (array_key_exists('expected_overtime_hours', $data)) {
                abort_if(abs($previousOvertime - (float) $data['expected_overtime_hours']) > 0.005,
                    409, 'Переработки уже изменены другим пользователем. Обновите период.');
            }
            if (array_key_exists('expected_hours', $data)) {
                if ($previousRegular === null) {
                    $previousRegular = $this->regularHoursForTerm($request, $current, $term);
                }
                abort_if(abs($previousRegular - (float) $data['expected_hours']) > 0.005,
                    409, 'Основные часы уже изменены другим пользователем. Обновите период.');
            }
            $regular = array_key_exists('hours', $data)
                ? round((float) $data['hours'], 2) : ($prior ? (float) $prior->hours : null);
            // When no explicit regular override exists, preserve the current
            // Timesheets-derived regular hours via a trusted existing default.
            if ($regular === null) {
                $regular = round($previousRegular ?? $this->regularHoursForTerm($request, $current, $term), 2);
            }
            $overtime = array_key_exists('overtime_hours', $data)
                ? round((float) $data['overtime_hours'], 2)
                : (float) ($prior->overtime_hours ?? 0);
            DB::table('reporting_period_approved_hours')->updateOrInsert(
                ['reporting_period_id' => $period, 'member_term_id' => $term],
                ['hours' => $regular, 'overtime_hours' => $overtime,
                    'updated_by' => $request->attributes->get('identity', [])['preferred_username'] ?? null,
                    'updated_at' => now()]
            );
            $this->recordEvent($period, $request, 'approved_hours', 'Коммерческие часы изменены',
                'Ставка #'.$term.': основные '.number_format($regular, 2, '.', '').
                ', переработки '.number_format($overtime, 2, '.', ''));
            return (object) ['hours' => $regular, 'overtime_hours' => $overtime];
        });
        return response()->json(['data' => [
            'member_term_id' => $term, 'hours' => $saved->hours,
            'overtime_hours' => $saved->overtime_hours,
        ]]);
    }

    private function regularHoursForTerm(Request $request, object $period, int $termId): float
    {
        $term = DB::table('member_terms as mt')
            ->join('project_members as pm', 'pm.id', '=', 'mt.project_member_id')
            ->where('mt.id', $termId)
            ->first(['mt.valid_from', 'mt.valid_to', 'pm.specialist_id', 'pm.project_id']);
        abort_unless($term, 422, 'Неизвестная ставка.');
        // Preserve factual approved regular default without trusting the browser.
        $payload = $this->timesheetsRange($request,
            substr((string) $period->period_start, 0, 10),
            substr((string) $period->period_end, 0, 10),
            (int) $period->client_id);
        $total = 0.0;
        foreach ($payload['entries'] ?? [] as $entry) {
            $date = substr((string) ($entry['work_date'] ?? ''), 0, 10);
            if ((int) ($entry['project_id'] ?? 0) !== (int) $term->project_id
                || (int) ($entry['employee_id'] ?? 0) !== (int) $term->specialist_id
                || $date < substr((string) $term->valid_from, 0, 10)
                || ($term->valid_to && $date > substr((string) $term->valid_to, 0, 10))) continue;
            $total += (float) ($entry['hours'] ?? 0);
        }
        return $total;
    }

    public function updateOvertimeMultiplier(Request $request, int $period)
    {
        $data = $request->validate([
            'overtime_multiplier' => ['required', 'numeric', 'gt:0', 'max:99999.999', 'decimal:0,3'],
            'expected_multiplier' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:99999.999', 'decimal:0,3'],
        ]);
        return DB::transaction(function () use ($request, $period, $data) {
            $current = DB::table('reporting_periods')->where('id', $period)
                ->lockForUpdate()->first();
            abort_unless($current, 404, 'Reporting period not found');
            abort_if($current->status === 'Счет оплачен', 423,
                'Коэффициент оплаченного периода нельзя менять.');
            if (array_key_exists('expected_multiplier', $data)) {
                abort_if(abs((float) $current->overtime_multiplier -
                    (float) $data['expected_multiplier']) > 0.0005,
                    409, 'Коэффициент уже изменён другим пользователем. Обновите период.');
            }
            $factor = round((float) $data['overtime_multiplier'], 3);
            if (abs((float) $current->overtime_multiplier - $factor) > 0.00001) {
                DB::table('reporting_periods')->where('id', $period)->update([
                    'overtime_multiplier' => $factor, 'updated_at' => now(),
                ]);
                $this->recordEvent($period, $request, 'overtime_multiplier',
                    'Коэффициент переработок изменён',
                    (string) $current->overtime_multiplier.' → '.number_format($factor, 3, '.', ''));
            }
            return response()->json(['data' => [
                'id' => $period, 'overtime_multiplier' => $factor,
            ]]);
        });
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
        // The Clients contour resolves its local roles from exact Employees
        // department aliases + departments.manager_id, not job titles.
        // Its middleware enforces the global Clients service entitlement first.
        $access = (array) $request->attributes->get('client_contour_access', []);
        return count(array_intersect(
            ['platform-admin', 'accounting-head', 'client-service-head'],
            (array) ($access['roles'] ?? [])
        )) > 0;
    }

    public function destroy(int $period)
    {
        // Lock the same reporting-period row that the Finance paid transition
        // locks. Otherwise a read-then-delete race can erase a paid period.
        return DB::transaction(function () use ($period) {
            $existing = DB::table('reporting_periods')->where('id', $period)->lockForUpdate()->first();
            abort_unless($existing, 404, 'Reporting period not found');
            abort_if($existing->status === 'Счет оплачен', 423, 'Оплаченный период удалять нельзя.');
            DB::table('reporting_periods')->where('id',$period)->delete();
            return response()->noContent();
        });
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

        $hourOverrides = DB::table('reporting_period_approved_hours')
            ->where('reporting_period_id', $period)->get()->keyBy('member_term_id');
        $grouped = [];
        foreach ($terms as $term) {
            $member = $members->firstWhere('id', $term->project_member_id);
            if (!$member) continue;
            $project = $projects->get((int) $member->project_id);
            $grouped[$term->id] = [
                'term_id' => (int) $term->id,
                'project_member_id' => (int) $member->id,
                'employee_id' => $member->specialist_id === null ? null : (int) $member->specialist_id,
                'partner_specialist_id' => $member->partner_specialist_id ?? null,
                'employee_name' => (string) $member->specialist_name,
                'project_id' => (int) $member->project_id,
                'project_name' => (string) (($project->name ?? null) ?: 'Основной проект'),
                'valid_from' => $term->valid_from,
                'valid_to' => $term->valid_to,
                'hourly_rate' => $term->hourly_rate === null ? null : (float) $term->hourly_rate,
                'worked_hours' => 0,
                'worked_amount' => $term->hourly_rate === null ? null : 0,
                'confirmed_hours' => 0,
                'confirmed_amount' => $term->hourly_rate === null ? null : 0,
                'approved_hours_override' => $hourOverrides->has($term->id) ? (float) $hourOverrides->get($term->id)->hours : null,
                'approved_overtime_hours' => $hourOverrides->has($term->id)
                    ? (float) $hourOverrides->get($term->id)->overtime_hours : 0.0,
                // Accumulate approvals from the actual work dates below.
                // Looking only at the first month of a cross-month period
                // produces a false "all Timesheets confirmed" signal.
                'account_confirmed' => true,
            ];
        }
        foreach ($entries as $entry) {
            $employeeId = (int) ($entry['employee_id'] ?? 0);
            $projectId = (int) ($entry['project_id'] ?? 0);
            $workDate = substr((string) ($entry['work_date'] ?? ''), 0, 10);
            $hours = (float) ($entry['hours'] ?? 0);
            $member = $members->first(fn ($m) => $m->specialist_id !== null && (int) $m->specialist_id === $employeeId && (int) $m->project_id === $projectId);
            if (!$member) continue;

            $term = $terms->first(fn ($t) =>
                (int) $t->project_member_id === (int) $member->id
                && substr((string) $t->valid_from, 0, 10) <= $workDate
                && (!$t->valid_to || substr((string) $t->valid_to, 0, 10) >= $workDate)
            );
            if (!$term || !isset($grouped[$term->id])) continue;
            $rate = $term->hourly_rate === null ? null : (float) $term->hourly_rate;
            $monthStart = Carbon::parse($workDate)->startOfMonth()->toDateString();
            $approved = $approvals->contains(fn ($approval) =>
                (int) ($approval['employee_id'] ?? 0) === $employeeId
                && (int) ($approval['project_id'] ?? 0) === $projectId
                && substr((string) ($approval['month'] ?? ''), 0, 10) === $monthStart
            );

            if ($hours > 0 && !$approved) $grouped[$term->id]['account_confirmed'] = false;
            $grouped[$term->id]['worked_hours'] += $hours;
            if ($rate !== null) $grouped[$term->id]['worked_amount'] += $hours * $rate;
            if ($approved) {
                $grouped[$term->id]['confirmed_hours'] += $hours;
                if ($rate !== null) $grouped[$term->id]['confirmed_amount'] += $hours * $rate;
            }
        }

        $periodMultiplier = (float) $row->overtime_multiplier;
        $timesheets = collect(array_values($grouped))->map(function ($item) use ($periodMultiplier) {
            // Commercial regular/overtime figures are editable independently
            // from actual Timesheets; disagreement is informative, not an error.
            $item['confirmed_hours'] = $item['approved_hours_override'] ?? $item['worked_hours'];
            $item['confirmed_total_hours'] = $item['confirmed_hours'] + $item['approved_overtime_hours'];
            if ($item['hourly_rate'] !== null) {
                $amounts = \App\Support\ApprovedCommercialHours::calculate(
                    (float) $item['confirmed_hours'], (float) $item['approved_overtime_hours'],
                    (float) $item['worked_hours'], (float) $item['hourly_rate'], $periodMultiplier
                );
                $item['confirmed_amount'] = $amounts['agreed_amount'];
            } else {
                $item['confirmed_amount'] = null;
            }
            foreach (['worked_hours', 'worked_amount', 'confirmed_hours', 'confirmed_total_hours', 'approved_overtime_hours', 'confirmed_amount'] as $field) {
                if ($item[$field] !== null) $item[$field] = round((float) $item[$field], 2);
            }
            return $item;
        })->sortBy([['employee_name', 'asc'], ['project_name', 'asc']])->values();

        return response()->json(['data' => [
            'period' => $row,
            'history' => DB::table('reporting_period_history')->where('reporting_period_id', $period)->orderBy('id')->get(),
            'can_rollback' => $this->canRollback($request),
            'client' => [
                'id' => (int) $client->id,
                'name' => $client->name,
                'account_employee_id' => $client->account_employee_id,
                'act_approval_days' => $client->act_approval_days ?? null,
                'act_approval_day_type' => $client->act_approval_day_type ?? 'working',
                'payment_days' => $client->payment_days ?? null,
                'payment_day_type' => $client->payment_day_type ?? 'working',
            ],
            'timesheets' => $timesheets,
            'summary' => [
                'worked_hours' => round((float) $timesheets->sum('worked_hours'), 2),
                'worked_amount' => $timesheets->contains(fn ($item) => $item['worked_amount'] === null) ? null : round((float) $timesheets->sum('worked_amount'), 2),
                'confirmed_hours' => round((float) $timesheets->sum('confirmed_hours'), 2),
                'approved_overtime_hours' => round((float) $timesheets->sum('approved_overtime_hours'), 2),
                'confirmed_total_hours' => round((float) $timesheets->sum('confirmed_total_hours'), 2),
                'all_account_confirmed' => $timesheets->filter(fn ($item) => $item['worked_hours'] > 0)->every(fn ($item) => $item['account_confirmed']),
                'confirmed_amount' => $timesheets->contains(fn ($item) => $item['confirmed_amount'] === null) ? null : round((float) $timesheets->sum('confirmed_amount'), 2),
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
