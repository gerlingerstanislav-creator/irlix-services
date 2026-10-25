<?php

namespace App\Services;

use App\Models\Client;
use App\Models\MemberTerm;
use App\Models\ReportingPeriod;
use App\Models\ReportingPeriodApprovedHours;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ReportingPeriodApprovedHoursService
{
    private static function raw($model): ?object
    {
        return $model ? (object) $model->getRawOriginal() : null;
    }

    public function update(Request $request, int $period, int $term, array $data): array
    {
        $periodRow = self::raw(ReportingPeriod::query()->find($period));
        abort_unless($periodRow, 404, 'Reporting period not found');
        abort_if($periodRow->status === 'Счет оплачен', 422, 'Оплаченный период нельзя редактировать.');
        $memberQuery=MemberTerm::query()->from('member_terms as mt');
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
            $clientId=ReportingPeriod::query()->where('id',$period)->value('client_id');
            if($clientId) Client::query()->where('id',$clientId)->lockForUpdate()->first();
            $current = self::raw(ReportingPeriod::query()->where('id', $period)->lockForUpdate()->first());
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
            ReportingPeriodApprovedHours::query()->updateOrInsert(
                ['reporting_period_id' => $period, 'member_term_id' => $storedTerm],
                ['hours' => $regular, 'overtime_hours' => $overtime,
                    'updated_by' => $request->attributes->get('identity', [])['preferred_username'] ?? null,
                    'updated_at' => now()]
            );
            app(ReportingPeriodWriteService::class)->recordEvent($period,
                $request->attributes->get('identity', [])['preferred_username'] ?? null,
                'approved_hours', 'Коммерческие часы изменены',
                'Ставка #'.$term.': основные '.number_format($regular, 2, '.', '').
                ', переработки '.number_format($overtime, 2, '.', ''));
            return (object) ['hours' => $regular, 'overtime_hours' => $overtime];
        });
        return [
            'member_term_id' => $term, 'hours' => $saved->hours,
            'overtime_hours' => $saved->overtime_hours,
        ];
    }

    private function regularHoursForTerm(Request $request, object $period, int $termId): float
    {
        $withMembers=DB::getSchemaBuilder()->hasTable('client_members');
        $query=MemberTerm::query()->from('member_terms as mt')
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


    private function timesheetsRange(
        Request $request, string $from, string $to, int $clientId, ?string $freezeToken = null
    ): array {
        return app(\App\Integrations\TimesheetsCommercialClient::class)
            ->range($request, $from, $to, $clientId, $freezeToken);
    }
}
