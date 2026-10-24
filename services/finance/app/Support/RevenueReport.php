<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RevenueReport
{
    public static function month(string $month): array
    {
        if (!preg_match('/^\\d{4}-(?:0[1-9]|1[0-2])$/', $month)) throw new HttpException(422,'Invalid month');
        $first=Carbon::parse($month.'-01')->toDateString();
        $last=Carbon::parse($month.'-01')->endOfMonth()->toDateString();
        $ct=(string)env('IRLIX_FINANCE_CLIENTS_TOKEN','');
        $tt=(string)env('IRLIX_FINANCE_TIMESHEETS_TOKEN','');
        if(strlen($ct)<32 || strlen($tt)<32)
            throw new HttpException(503,'Finance commercial integrations are not configured');
        $cUrl=rtrim((string)env('CLIENTS_INTERNAL_URL','http://clients:8000/internal/system'),'/')
            .'/finance/commercial-terms';
        $tUrl=(string)env('TIMESHEETS_FINANCE_URL','http://timesheets:8000/internal/system/finance/commercial-data');
        try {
            $client=Http::withHeaders(['X-Irlix-Finance-Token'=>$ct])->acceptJson()->timeout(10)
                ->get($cUrl,['from'=>$first,'to'=>$last]);
            if (!$client->successful()) throw new HttpException(503,'Clients commercial data unavailable');
            $allocationFrom=$client->json('data.allocation_from') ?? $first;
            $allocationTo=$client->json('data.allocation_to') ?? $last;
            if (!is_string($allocationFrom) || !is_string($allocationTo)
                || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$allocationFrom)
                || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$allocationTo)
                || $allocationFrom>$first || $allocationTo<$last)
                throw new HttpException(503,'Invalid commercial allocation window');
            $timesheet=Http::withHeaders(['X-Irlix-Finance-Timesheets-Token'=>$tt])->acceptJson()->timeout(10)
                ->get($tUrl,['from'=>$allocationFrom,'to'=>$allocationTo]);
        } catch(\Throwable) {
            throw new HttpException(503,'Finance commercial data sources unavailable');
        }
        if(!$client->successful() || !$timesheet->successful())
            throw new HttpException(503,'Finance commercial data sources refused request');

        $terms=collect((array)$client->json('data.terms',[]));
        $periods=collect((array)$client->json('data.periods',[]));
        $overrides=collect((array)$client->json('data.approved_hours_overrides',[]));
        $entries=collect((array)$timesheet->json('data.entries',[]));
        $approvals=collect((array)$timesheet->json('data.final_approvals',[]));
        $exceptions=collect((array)$timesheet->json('data.final_approval_exceptions',[]));
        self::assertSnapshot($timesheet->json('data'));
        $clientData = $client->json('data');
        if (!is_array($clientData) || ($clientData['complete'] ?? null) !== true
            || ($clientData['count'] ?? null) !== $terms->count())
            throw new HttpException(503, 'Clients commercial data incomplete');
        foreach (['terms', 'periods', 'approved_hours_overrides'] as $collection) {
            if (!is_array($clientData[$collection] ?? null) || !array_is_list($clientData[$collection])
                || count(array_filter($clientData[$collection], 'is_array')) !== count($clientData[$collection]))
                throw new HttpException(503, 'Invalid Clients commercial collection');
        }
        // Protected periods use the exact Timesheets snapshot accepted by Clients.
        // A later service restart or change outside that period must not rewrite it.
        foreach ($periods as $period) {
            $saved = $period['timesheet_snapshot'] ?? null;
            if ($saved === null || ($period['status'] ?? '') === 'Новый') continue;
            if (is_string($saved)) $saved = json_decode($saved, true);
            self::assertSnapshot($saved);
            if (!is_array($saved) || ($saved['complete'] ?? false) !== true
                || !is_array($saved['entries'] ?? null) || !array_is_list($saved['entries'])
                || ($saved['count'] ?? null) !== count($saved['entries'])
                || !is_string($saved['revision'] ?? null)
                || !hash_equals((string)($period['timesheet_revision'] ?? ''), $saved['revision']))
                throw new HttpException(503, 'Protected commercial snapshot is invalid');
            $periodClient = (int)$period['client_id'];
            $periodFrom = substr((string)$period['period_start'], 0, 10);
            $periodTo = substr((string)$period['period_end'], 0, 10);
            $entries = $entries->reject(fn($entry) => (int)($entry['client_id'] ?? 0) === $periodClient
                && ($entry['work_date'] ?? '') >= $periodFrom && ($entry['work_date'] ?? '') <= $periodTo);
            foreach ($saved['entries'] as $entry) {
                $date = $entry['work_date'] ?? '';
                if ((int)($entry['client_id'] ?? 0) !== $periodClient || $date < $periodFrom || $date > $periodTo)
                    throw new HttpException(503, 'Protected commercial snapshot has unrelated facts');
                $entry['_snapshot_approved'] = collect($saved['final_approvals'] ?? [])->contains(fn($approval) =>
                    self::sameSubject($approval, $entry)
                    && substr((string)($approval['month'] ?? ''), 0, 7) === substr($date, 0, 7))
                    && !collect($saved['final_approval_exceptions'] ?? [])->contains(fn($exception) =>
                        self::sameSubject($exception, $entry)
                        && substr((string)($exception['work_date'] ?? ''), 0, 10) === $date);
                $entries->push($entry);
            }
        }
        $rows=[];$errors=[];$preliminary=0;$confirmed=0;
        // The account manager independently approves regular and overtime
        // hours per reporting period. Exceeding the employee's global monthly
        // norm is no longer an authorization or financial-quality blocker:
        // Clients owns the commercial classification, not Finance/Timesheets.
        // Overrides may still require month-specific allocation below.
        foreach($entries as $entry){
            $rawHours=$entry['hours']??null;
            if(!is_numeric($rawHours) || !is_finite((float)$rawHours) || (float)$rawHours<0){
                $errors[]=['date'=>$entry['work_date']??null,
                    'reason'=>'invalid_timesheet_hours'];
                continue;
            }
            $date=$entry['work_date']??null;
            // A broken or out-of-window upstream row must not move September
            // service revenue into October merely because it was returned by
            // the October Timesheets query.
            if(!is_string($date)
                || !preg_match('/^\\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\\d|3[01])$/',$date)
                || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))){
                $errors[]=['date'=>$date,'reason'=>'invalid_timesheet_work_date'];
                continue;
            }
            if($date<$allocationFrom || $date>$allocationTo){
                $errors[]=['date'=>$date,'reason'=>'timesheet_entry_outside_requested_month'];
                continue;
            }
            if (substr($date, 0, 7) !== $month && !$periods->contains(fn($period) =>
                (int)($period['client_id'] ?? 0) === (int)($entry['client_id'] ?? 0)
                && substr((string)($period['period_start'] ?? ''), 0, 10) <= $date
                && substr((string)($period['period_end'] ?? ''), 0, 10) >= $date)) continue;
            $clientId=(int)($entry['client_id']??0);
            $projectId=(int)($entry['project_id']??0);
            $clientMemberId=(int)($entry['client_member_id']??0);
            $employeeId=(int)($entry['employee_id']??0);
            if($clientId<1 || ($clientMemberId<1 && ($projectId<1 || $employeeId<1))){
                $errors[]=['date'=>$date,'reason'=>'invalid_timesheet_identity'];
                continue;
            }
            $hours=(float)$rawHours;
            if (!empty($entry['term_conflict'])) {
                $errors[]=['date'=>$date,'client_member_id'=>$clientMemberId,'reason'=>'timesheet_term_conflict'];
                continue;
            }
            if($hours===0.0)continue;
            $valid=$terms->filter(fn($t)=>
                ($clientMemberId>0
                    ? (int)($t['client_member_id']??0)===$clientMemberId
                    : ((int)($t['project_id']??0)===$projectId &&
                       (int)($t['employee_id']??0)===$employeeId))
                && (int)($t['client_id']??0)===$clientId
                && substr((string)($t['valid_from']??''),0,10)<=$date
                && (empty($t['valid_to']) || substr((string)$t['valid_to'],0,10)>=$date));
            if($valid->count()!==1 || ($valid->first()['hourly_rate']??null)===null){
                $errors[]=['date'=>$date,'project_id'=>$projectId,'employee_id'=>$employeeId,
                    'reason'=>$valid->count()===1?'rate_missing':'rate_terms_missing_or_ambiguous'];
                continue;
            }
            $term=$valid->first();
            $rawRate=$term['hourly_rate'];
            if(!is_numeric($rawRate) || !is_finite((float)$rawRate) || (float)$rawRate<0){
                $errors[]=['date'=>$date,'project_id'=>$projectId,
                    'employee_id'=>$employeeId,'reason'=>'invalid_commercial_rate'];
                continue;
            }
            // An explicitly contracted zero rate is valid; a missing or
            // malformed rate must never be silently coerced to zero.
            $rate=(float)$rawRate;
            $amount=round($hours*$rate,2);
            if(!is_finite($amount)){
                $errors[]=['date'=>$date,'project_id'=>$projectId,
                    'employee_id'=>$employeeId,'reason'=>'commercial_amount_overflow'];
                continue;
            }
            $accountApproved=$approvals->contains(fn($a)=>
                ($clientMemberId>0
                    ? (int)($a['client_member_id']??0)===$clientMemberId
                    : ((int)($a['employee_id']??0)===$employeeId &&
                       (int)($a['project_id']??0)===$projectId))
                && substr((string)($a['month']??''),0,7)===substr($date,0,7));
            $accountApproved=$accountApproved && !$exceptions->contains(fn($a)=>
                ($clientMemberId>0
                    ? (int)($a['client_member_id']??0)===$clientMemberId
                    : ((int)($a['employee_id']??0)===$employeeId && (int)($a['project_id']??0)===$projectId))
                && substr((string)($a['work_date']??''),0,10)===$date);
            if (array_key_exists('_snapshot_approved', $entry)) $accountApproved = $entry['_snapshot_approved'];
            $matchingPeriods=$periods->filter(fn($p)=>(int)($p['client_id']??0)===$clientId
                && substr((string)($p['period_start']??''),0,10)<=$date
                && substr((string)($p['period_end']??''),0,10)>=$date);
            if($matchingPeriods->count()>1){
                $errors[]=['date'=>$date,'client_id'=>$clientId,'project_id'=>$projectId,
                    'employee_id'=>$employeeId,'reason'=>'client_reporting_period_ambiguous'];
            }
            // Overlapping Client reporting periods cannot select an arbitrary
            // approval and certify revenue for the wrong billing period.
            $clientPeriod=$matchingPeriods->count()===1?$matchingPeriods->first():null;
            $clientApproved=$clientPeriod && in_array($clientPeriod['status']??'',[
                'Акт согласован','Счет оплачен'
            ],true);
            $rows[]=[
                'work_date'=>$date,'client_id'=>$clientId,'client_name'=>$term['client_name']??'',
                'project_id'=>$projectId?:null,'project_name'=>$term['project_name']??'',
                'client_member_id'=>$clientMemberId?:null,
                'partner_specialist_id'=>$term['partner_specialist_id']??null,
                'employee_id'=>$employeeId?:null,'specialist_name'=>$term['specialist_name']??null,'term_id'=>$term['term_id'],
                'hours'=>round($hours,2),'rate'=>$rate,'amount'=>$amount,
                'account_confirmed'=>$accountApproved,'client_approved'=>(bool)$clientApproved,
                'reporting_period_id'=>$clientPeriod['id']??null,
                'status'=>$clientApproved&&$accountApproved?'agreed':($accountApproved?'account_approved':'preliminary'),
            ];
        }
        $commercial=CommercialRevenueAllocation::apply($rows,$overrides->all(),$periods->all(),$month,
            ['from'=>$allocationFrom,'to'=>$allocationTo]);
        $rows=array_values(array_filter($commercial['rows'],fn($row)=>substr($row['work_date'],0,7)===$month));
        $errors=array_merge($errors,$commercial['errors']);
        foreach($rows as $row){
            $preliminary+=(float)$row['amount'];
            if(($row['status']??null)==='agreed')$confirmed+=(float)$row['amount'];
        }
        // No commercial entries is not a verified zero-revenue month:
        // source completeness and the absence of performed work are distinct.
        $hasEntries=$entries->contains(fn($entry)=>substr((string)($entry['work_date']??''),0,7)===$month);
        // An unapproved Timesheet or client period is not confirmed revenue.
        // Returning confirmed=0 while positive preliminary hours exist would
        // misstate a pending month as a fully agreed zero-revenue period.
        $hasUnapproved=collect($rows)->contains(fn($row)=>($row['status']??null)!=='agreed');
        $quality=count($errors)?'incomplete':
            (!$hasEntries?'no_commercial_entries':
                ($hasUnapproved?'awaiting_approvals':'complete'));
        return [
            'month'=>$month,'rows'=>$rows,
            'preliminary_amount'=>in_array($quality,['complete','awaiting_approvals'],true)
                ?round($preliminary,2):null,
            'client_agreed_amount'=>$quality==='complete'?round($confirmed,2):null,
            'hours'=>round(array_sum(array_column($rows,'hours')),2),
            'quality'=>$quality,
            'unknown_rows'=>$errors,'source'=>'Clients member_terms + Timesheets final_approvals',
            'note'=>'Подтверждённые основные и сверхурочные часы и коэффициент берутся из Clients ReportingPeriod. Согласованные суммы распределяются по фактическим дневным часам всего отчётного периода с сохранением точной суммы по месяцам.',
        ];
    }

    private static function sameSubject(array $a, array $b): bool
    {
        $member = (int)($b['client_member_id'] ?? 0);
        if ($member > 0) return (int)($a['client_member_id'] ?? 0) === $member;
        $employee = (int)($b['employee_id'] ?? 0);
        $project = (int)($b['project_id'] ?? 0);
        return $employee > 0 && $project > 0
            && (int)($a['employee_id'] ?? 0) === $employee
            && (int)($a['project_id'] ?? 0) === $project;
    }

    private static function assertSnapshot(mixed $source): void
    {
        if (!is_array($source) || ($source['complete'] ?? null) !== true)
            throw new HttpException(503, 'Timesheets commercial data incomplete');
        foreach (['entries', 'final_approvals', 'final_approval_exceptions'] as $collection) {
            if (!is_array($source[$collection] ?? null) || !array_is_list($source[$collection])
                || count(array_filter($source[$collection], 'is_array')) !== count($source[$collection]))
                throw new HttpException(503, 'Invalid Timesheets commercial collection');
        }
        if (($source['count'] ?? null) !== count($source['entries']))
            throw new HttpException(503, 'Timesheets commercial count mismatch');
    }
}
