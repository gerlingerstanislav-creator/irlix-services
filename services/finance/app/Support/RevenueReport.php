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
            $timesheet=Http::withHeaders(['X-Irlix-Finance-Timesheets-Token'=>$tt])->acceptJson()->timeout(10)
                ->get($tUrl,['from'=>$first,'to'=>$last]);
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
            if(substr($date,0,7)!==$month){
                $errors[]=['date'=>$date,'reason'=>'timesheet_entry_outside_requested_month'];
                continue;
            }
            $clientId=(int)($entry['client_id']??0);
            $projectId=(int)($entry['project_id']??0);
            $employeeId=(int)($entry['employee_id']??0);
            if($clientId<1 || $projectId<1 || $employeeId<1){
                $errors[]=['date'=>$date,'reason'=>'invalid_timesheet_identity'];
                continue;
            }
            $hours=(float)$rawHours;
            if($hours===0.0)continue;
            $valid=$terms->filter(fn($t)=>
                (int)($t['project_id']??0)===$projectId
                && (int)($t['client_id']??0)===$clientId
                && (int)($t['employee_id']??0)===$employeeId
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
                (int)($a['employee_id']??0)===$employeeId
                && (int)($a['project_id']??0)===$projectId
                && substr((string)($a['month']??''),0,7)===$month);
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
                'project_id'=>$projectId,'project_name'=>$term['project_name']??'',
                'employee_id'=>$employeeId,'term_id'=>$term['term_id'],
                'hours'=>round($hours,2),'rate'=>$rate,'amount'=>$amount,
                'account_confirmed'=>$accountApproved,'client_approved'=>(bool)$clientApproved,
                'reporting_period_id'=>$clientPeriod['id']??null,
                'status'=>$clientApproved&&$accountApproved?'agreed':($accountApproved?'account_approved':'preliminary'),
            ];
        }
        $commercial=CommercialRevenueAllocation::apply($rows,$overrides->all(),$periods->all(),$month);
        $rows=$commercial['rows'];
        $errors=array_merge($errors,$commercial['errors']);
        foreach($rows as $row){
            $preliminary+=(float)$row['amount'];
            if(($row['status']??null)==='agreed')$confirmed+=(float)$row['amount'];
        }
        // No commercial entries is not a verified zero-revenue month:
        // source completeness and the absence of performed work are distinct.
        $hasEntries=$entries->isNotEmpty();
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
            'note'=>'Подтверждённые основные и сверхурочные часы и коэффициент берутся из Clients ReportingPeriod. Корректировки периодов, пересекающих месяцы, требуют отдельного распределения и остаются неполными.',
        ];
    }
}
