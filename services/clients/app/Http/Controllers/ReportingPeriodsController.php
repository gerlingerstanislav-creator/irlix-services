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
        return response()->json(['data' => app(\App\Services\ReportingPeriodLifecycleService::class)
            ->update($request, $period, $data)]);
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
        $term = \App\Support\MemberIdentity::canonicalTermId($term);
        $data = $request->validate([
            'hours' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'overtime_hours' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'expected_hours' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'expected_overtime_hours' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
        ]);
        abort_if(!array_key_exists('hours', $data) && !array_key_exists('overtime_hours', $data),
            422, 'Укажите основные часы или переработку.');
        return response()->json(['data' => app(\App\Services\ReportingPeriodApprovedHoursService::class)
            ->update($request, $period, $term, $data)]);
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
