<?php

namespace App\Services;

use App\Models\ReportingPeriod;
use App\Models\Client;
use App\Models\ClientMember;
use App\Models\MemberTerm;
use App\Models\ReportingPeriodHistory;
use Carbon\Carbon;
use Illuminate\Http\Request;

final class ReportingPeriodReadService
{
    private static function raw($model): ?object
    {
        return $model ? (object) $model->getRawOriginal() : null;
    }

    private function canRollback(Request $request): bool
    {
        $access = (array) $request->attributes->get('client_contour_access', []);
        return count(array_intersect(
            ['platform-admin', 'accounting-head', 'client-service-head'],
            (array) ($access['roles'] ?? [])
        )) > 0;
    }

    public function show(Request $request, int $period)
    {
        $row = self::raw(ReportingPeriod::query()->find($period));
        abort_unless($row, 404, 'Reporting period not found');
        $client = self::raw(Client::query()->find($row->client_id));
        abort_unless($client, 404, 'Client not found');

        $from = substr((string) $row->period_start, 0, 10);
        $to = substr((string) $row->period_end, 0, 10);
        $payload = !empty($row->timesheet_snapshot) ? json_decode($row->timesheet_snapshot,true,512,JSON_THROW_ON_ERROR) : app(\App\Integrations\TimesheetsCommercialClient::class)->range($request, $from, $to, (int) $row->client_id);
        $entries = collect($payload['entries'] ?? [])->values();
        $approvals = collect($payload['final_approvals'] ?? []);

        $clientMembers=ClientMember::query()->where('client_id',$row->client_id)->get()->map(static fn ($item) => (object) $item->getRawOriginal());
        $terms=MemberTerm::query()->whereIn('client_member_id',$clientMembers->pluck('id'))
            ->whereDate('valid_from','<=',$to)->where(fn($q)=>$q->whereNull('valid_to')->orWhereDate('valid_to','>=',$from))
            ->orderBy('valid_from')->get()->map(static fn ($item) => (object) $item->getRawOriginal());
        $hourOverrides=\App\Support\MemberTermHistory::approvedHours($period)->keyBy('member_term_id');
        $grouped = [];
        foreach ($terms as $term) {
            $member=$clientMembers->firstWhere('id',$term->client_member_id);
            if(!$member) continue;
            $grouped[$term->id] = [
                'term_id' => (int) $term->id,
                'project_member_id' => $term->project_member_id,
                'client_member_id' => (int)$member->id,
                'employee_id' => $member->employee_id===null?null:(int)$member->employee_id,
                'partner_specialist_id' => $member->partner_specialist_id ?? null,
                'employee_name' => (string) $member->specialist_name,
                'project_id' => null,
                'project_name' => null,
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
            $member=$clientMembers->firstWhere('id',$entry['client_member_id']??0);
            if(!$member) continue;
            $term=$terms->first(fn($t)=>(int)$t->client_member_id===(int)$member->id
                && substr((string)$t->valid_from,0,10)<=$workDate
                && (!$t->valid_to||substr((string)$t->valid_to,0,10)>=$workDate));
            if (!$term || !isset($grouped[$term->id])) continue;
            $rate = $term->hourly_rate === null ? null : (float) $term->hourly_rate;
            $monthStart = Carbon::parse($workDate)->startOfMonth()->toDateString();
            $approved = empty($entry['term_conflict']) && $approvals->contains(fn ($approval) =>
                (int)($approval['client_member_id']??0)===(int)$member->id
                && substr((string)($approval['month']??''),0,10)===$monthStart
            ) && !collect($payload['final_approval_exceptions']??[])->contains(fn($exception)=>
                (int)($exception['client_member_id']??0)===(int)$member->id
                && substr((string)($exception['work_date']??''),0,10)===$workDate);


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

        return [
            'period' => $row,
            'history' => ReportingPeriodHistory::query()->where('reporting_period_id', $period)->orderBy('id')->get()->map(static fn ($item) => (object) $item->getRawOriginal()),
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
            'specialists' => $timesheets->groupBy('client_member_id')->map(function($items){
                $first=$items->first();
                return ['client_member_id'=>$first['client_member_id'],'employee_id'=>$first['employee_id'],
                    'partner_specialist_id'=>$first['partner_specialist_id'],'specialist_name'=>$first['employee_name'],
                    'worked_hours'=>round($items->sum('worked_hours'),2),
                    'worked_amount'=>$items->contains(fn($i)=>$i['worked_amount']===null)?null:round($items->sum('worked_amount'),2),
                    'confirmed_total_hours'=>round($items->sum('confirmed_total_hours'),2),
                    'confirmed_amount'=>$items->contains(fn($i)=>$i['confirmed_amount']===null)?null:round($items->sum('confirmed_amount'),2),
                    'account_confirmed'=>$items->every(fn($i)=>$i['account_confirmed'])];
            })->values(),
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
        ];
    }


}
