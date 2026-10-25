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
        return response()->json(['data' => app(\App\Services\ReportingPeriodWriteService::class)->store(
            $data, $this->username($request)
        )], 201);
    }

    private function username(Request $request): ?string
    {
        $identity = $request->attributes->get('identity', []);
        return $identity['preferred_username'] ?? $identity['name'] ?? null;
    }

    public function update(Request $request, int $period)
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'date' => ['nullable', 'date'],
        ]);
        $rollback = false;
        $acquiredFreeze=null;
        $releaseFreeze=null;
        try {
        DB::transaction(function () use ($request, $period, $data, &$rollback, &$acquiredFreeze, &$releaseFreeze): void {
            $clientId=DB::table('reporting_periods')->where('id',$period)->value('client_id');
            if($clientId) DB::table('clients')->where('id',$clientId)->lockForUpdate()->first();
            $current = DB::table('reporting_periods')->where('id', $period)->lockForUpdate()->first();
            abort_unless($current, 404, 'Reporting period not found');
            if($current->status==='Новый' && $data['status']==='Новый' && !empty($current->timesheet_freeze_token)) {
                $releaseFreeze=$current->timesheet_freeze_token;
                return;
            }
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
                if($data['status']==='Новый') {
                    $releaseFreeze=$current->timesheet_freeze_token??null;
                    DB::table('reporting_periods')->where('id',$period)->update(['timesheet_snapshot'=>null,'timesheet_revision'=>null]);
                }
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
                $freeze=$this->freezeTimesheets($current);
                $acquiredFreeze=$freeze['freeze_token'];
                $payload = $this->timesheetsRange($request, substr((string) $current->period_start, 0, 10), substr((string) $current->period_end, 0, 10), (int) $current->client_id,$acquiredFreeze);
                abort_unless(!empty($payload['membership_revision']) && hash_equals(\App\Support\TimesheetMemberships::snapshot()['revision'],(string)$payload['membership_revision']),409,'Условия подключений изменились во время отправки.');
                abort_unless(!empty($payload['revision']) && hash_equals($freeze['revision'],(string)$payload['revision']),409,'Таймшиты изменились во время отправки.');
                abort_if(collect($payload['entries'] ?? [])->contains(fn($e) =>
                    !empty($e['client_member_id']) && (!empty($e['term_conflict']) || empty($e['member_term_id']))),
                    422, 'Есть часы без действующих однозначных условий подключения.');
                $expectedTerms=DB::table('member_terms as mt')->join('client_members as cm','cm.id','=','mt.client_member_id')
                    ->where('cm.client_id',$current->client_id)->whereDate('mt.valid_from','<=',$current->period_end)
                    ->where(fn($q)=>$q->whereNull('mt.valid_to')->orWhereDate('mt.valid_to','>=',$current->period_start))
                    ->select('mt.*')->get();
                $entries=collect($payload['entries']??[]);
                abort_if($expectedTerms->isEmpty(),422,'Нет действующих подключений отчётного периода.');
                abort_if($expectedTerms->contains(fn($term)=>$term->hourly_rate===null),422,'Укажите неизвестные коммерческие ставки перед отправкой.');
                $approvals=collect($payload['final_approvals']??[]);
                $exceptions=collect($payload['final_approval_exceptions']??[]);
                foreach($expectedTerms->groupBy('client_member_id') as $memberId=>$memberTerms) {
                    $firstMonth=Carbon::parse($current->period_start)->startOfMonth();
                    $lastMonth=Carbon::parse($current->period_end)->startOfMonth();
                    for($month=$firstMonth->copy();$month->lte($lastMonth);$month->addMonth()) {
                        $sliceFrom=max(substr((string)$current->period_start,0,10),$month->toDateString());
                        $sliceTo=min(substr((string)$current->period_end,0,10),$month->copy()->endOfMonth()->toDateString());
                        if(!$memberTerms->contains(fn($t)=>$t->valid_from<=$sliceTo && (!$t->valid_to||$t->valid_to>=$sliceFrom)))continue;
                        $facts=$entries->filter(fn($e)=>(int)($e['client_member_id']??0)===(int)$memberId
                            && substr((string)($e['work_date']??''),0,10)>=$sliceFrom
                            && substr((string)($e['work_date']??''),0,10)<=$sliceTo);
                        // An explicit zero-hour fact is evidence; an absent person is not.
                        abort_if($facts->isEmpty(),422,'Не заполнены ТШ всех ожидаемых специалистов за каждый месяц периода.');
                        abort_unless($approvals->contains(fn($a)=>(int)($a['client_member_id']??0)===(int)$memberId
                            && substr((string)($a['month']??''),0,7)===$month->format('Y-m')),422,'Подтвердите все подключения и месяцы отчётного периода.');
                        abort_if($exceptions->contains(fn($e)=>(int)($e['client_member_id']??0)===(int)$memberId
                            && substr((string)($e['work_date']??''),0,10)>=$sliceFrom
                            && substr((string)($e['work_date']??''),0,10)<=$sliceTo),422,'Есть отменённые подтверждения дней отчётного периода.');
                        foreach($facts as $fact) {
                            abort_unless($memberTerms->contains(fn($t)=>(int)$t->id===(int)($fact['member_term_id']??0)
                                && $t->valid_from<=$fact['work_date'] && (!$t->valid_to||$t->valid_to>=$fact['work_date'])),422,'Часы не соответствуют условиям подключения.');
                        }
                    }
                }
                DB::table('reporting_periods')->where('id',$period)->update([
                    'timesheet_freeze_token'=>$acquiredFreeze,'timesheet_revision'=>$payload['revision'],
                    'timesheet_snapshot'=>json_encode($payload,JSON_THROW_ON_ERROR)]);
            }
            $this->recordEvent($period, $request, 'status', 'Этап изменён', $current->status.' → '.$data['status'].' ('.substr((string)$data['date'],0,10).')');
            DB::table('reporting_periods')->where('id', $period)->update([
                'status' => $data['status'],
                self::STAGE_DATES[$data['status']] => $data['date'],
                'updated_at' => now(),
            ]);
        });
        } catch (\Throwable $error) {
            // Release only a freeze from a failed transaction. A committed protected
            // period keeps its local Timesheets lock even when HTTP delivery fails.
            if($acquiredFreeze!==null) $this->releaseTimesheetsFreeze($period,$acquiredFreeze);
            throw $error;
        }
        if($releaseFreeze!==null) {
            $this->releaseTimesheetsFreeze($period,$releaseFreeze);
            DB::table('reporting_periods')->where('id',$period)->update(['timesheet_freeze_token'=>null]);
        }
        return response()->json(['data' => DB::table('reporting_periods')->find($period)]);
    }

    public function storeNote(Request $request, int $period)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:5000']]);
        app(\App\Services\ReportingPeriodWriteService::class)->note(
            $period, $data['text'], $this->username($request)
        );
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
        $term=\App\Support\MemberIdentity::canonicalTermId($term);
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
        $memberQuery=DB::table('member_terms as mt');
        if(DB::getSchemaBuilder()->hasTable('client_members')) {
            $memberQuery->join('client_members as cm','cm.id','=','mt.client_member_id')
                ->where('cm.client_id',$periodRow->client_id);
        }else {
            $memberQuery->join('project_members as pm','pm.id','=','mt.project_member_id')
                ->join('projects as p','p.id','=','pm.project_id')
                ->where('p.client_id',$periodRow->client_id);
        }
        $memberTerm=$memberQuery->where('mt.id',$term)
            ->whereDate('mt.valid_from','<=',$periodRow->period_end)
            ->where(fn($q)=>$q->whereNull('mt.valid_to')->orWhereDate('mt.valid_to','>=',$periodRow->period_start))
            ->select('mt.id')->first();
        abort_unless($memberTerm, 422, 'Ставка не принадлежит отчётному периоду.');
        $saved = DB::transaction(function () use ($request, $period, $term, $data): object {
            // Coordinate with paid transition, which locks the period row.
            $clientId=DB::table('reporting_periods')->where('id',$period)->value('client_id');
            if($clientId) DB::table('clients')->where('id',$clientId)->lockForUpdate()->first();
            $current = DB::table('reporting_periods')->where('id', $period)->lockForUpdate()->first();
            abort_unless($current, 404, 'Reporting period not found');
            abort_if($current->status === 'Счет оплачен', 423, 'Оплаченный период нельзя редактировать.');
            $prior=\App\Support\MemberTermHistory::approvedHours($period)->firstWhere('member_term_id',$term);
            $storedTerm=$prior->original_member_term_id??$term;
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
                ['reporting_period_id' => $period, 'member_term_id' => $storedTerm],
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
        $withMembers=DB::getSchemaBuilder()->hasTable('client_members');
        $query=DB::table('member_terms as mt')
            ->leftJoin('project_members as pm','pm.id','=','mt.project_member_id');
        if($withMembers)$query->leftJoin('client_members as cm','cm.id','=','mt.client_member_id');
        $columns=['mt.valid_from','mt.valid_to','pm.specialist_id','pm.project_id'];
        if($withMembers)$columns[]='cm.id as client_member_id';
        $term=$query->where('mt.id',$termId)->first($columns);
        abort_unless($term, 422, 'Неизвестная ставка.');
        // Preserve factual approved regular default without trusting the browser.
        $payload = $this->timesheetsRange($request,
            substr((string) $period->period_start, 0, 10),
            substr((string) $period->period_end, 0, 10),
            (int) $period->client_id);
        $total = 0.0;
        foreach ($payload['entries'] ?? [] as $entry) {
            $date = substr((string) ($entry['work_date'] ?? ''), 0, 10);
            if ((!empty($entry['client_member_id'])
                    ? (int)$entry['client_member_id']!==(int)$term->client_member_id
                    : ((int)($entry['project_id']??0)!==(int)$term->project_id ||
                       (int)($entry['employee_id']??0)!==(int)$term->specialist_id))
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
        return response()->json(['data' => app(\App\Services\ReportingPeriodWriteService::class)
            ->overtimeMultiplier($period, $data, $this->username($request))]);
    }

    public function lockStatus(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'work_date' => ['required', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:work_date'],
        ]);
        $locked = app(\App\Services\ReportingPeriodWriteService::class)->lockStatus(
            (int) $data['client_id'], $data['work_date'], $data['to'] ?? null
        );
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
        app(\App\Services\ReportingPeriodWriteService::class)->delete($period);
        return response()->noContent();
    }

    public function show(Request $request, int $period)
    {
        return response()->json(['data' => app(\App\Services\ReportingPeriodReadService::class)
            ->show($request, $period)]);
    }

    private function assertNoOverlap(int $clientId, string $from, string $to, ?int $excludeId = null): void
    {
        app(\App\Services\ReportingPeriodWriteService::class)->assertNoOverlap(
            $clientId, $from, $to, $excludeId
        );
    }

    private function freezeTimesheets(object $period): array
    {
        $secret=(string)env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN','');
        abort_unless(strlen($secret)>=32,503,'Не настроена интеграция фиксации таймшитов.');
        $memberIds=\App\Models\ClientMember::query()->where('client_id',$period->client_id)
            ->orderBy('id')->pluck('id')->map(fn($id)=>(int)$id)->all();
        abort_if($memberIds===[],422,'Нет подключений клиента для фиксации таймшитов.');
        $membershipRevision=\App\Support\TimesheetMemberships::snapshot()['revision'];
        $response=Http::acceptJson()->withHeaders(['X-Irlix-Timesheets-Token'=>$secret])->timeout(15)
            ->post(rtrim((string)env('TIMESHEETS_INTERNAL_URL','http://timesheets:8000/internal'),'/').'/system/reporting-freezes-v2',[
                'reporting_period_id'=>$period->id,'client_id'=>$period->client_id,
                'client_member_ids'=>$memberIds,'membership_revision'=>$membershipRevision,
                'from'=>substr((string)$period->period_start,0,10),'to'=>substr((string)$period->period_end,0,10)]);
        abort_unless($response->successful(),503,'Не удалось зафиксировать таймшиты.');
        $data=$response->json('data');
        abort_unless(is_array($data)&&is_string($data['freeze_token']??null)&&is_string($data['revision']??null),503,'Неполная фиксация таймшитов.');
        return $data;
    }
    private function releaseTimesheetsFreeze(int $period,string $token): void
    {
        $response=Http::acceptJson()->withHeaders(['X-Irlix-Timesheets-Token'=>(string)env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN','')])->timeout(15)
            ->delete(rtrim((string)env('TIMESHEETS_INTERNAL_URL','http://timesheets:8000/internal'),'/').'/system/reporting-freezes/'.$period,['freeze_token'=>$token]);
        abort_unless($response->successful(),503,'Таймшиты остаются заблокированы; повторите снятие фиксации.');
    }

    private function timesheetsRange(Request $request, string $from, string $to, int $clientId, ?string $freezeToken = null): array
    {
        return app(\App\Integrations\TimesheetsCommercialClient::class)
            ->range($request, $from, $to, $clientId, $freezeToken);
    }
}
