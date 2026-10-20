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
        // Commercial overtime is determined once for each employee across
        // ALL client projects in a calendar month, not independently per
        // assignment. The separately agreed client and employee multipliers
        // are not in MemberTerms yet. Never certify base-rate overtime as
        // confirmed commercial revenue.
        $norm=null;
        try {
            $calendar=Http::acceptJson()->timeout(8)->get(
                rtrim((string)env('PLATFORM_CORE_URL','http://platform-core:8000/api'),'/')
                .'/production-calendar',['year'=>(int)substr($month,0,4)]
            );
            if($calendar->successful() && $calendar->json('data.available')===true) {
                $norm=0;
                foreach((array)$calendar->json('data.days',[]) as $date=>$day) {
                    if(!str_starts_with((string)$date,$month))continue;
                    if(!empty($day['is_working']))$norm+=empty($day['is_short'])?8:7;
                }
            }
        } catch(\Throwable) {
            $norm=null;
        }
        if($norm===null || $norm<1) {
            $errors[]=['reason'=>'production_calendar_unavailable_or_invalid',
                'month'=>$month];
        } else {
            $hoursByEmployee=$entries->groupBy('employee_id');
            foreach($hoursByEmployee as $employeeId=>$employeeEntries) {
                $total=(float)$employeeEntries->sum(fn($e)=>(float)($e['hours']??0));
                if($total>$norm+0.00001) {
                    $errors[]=[
                        'reason'=>'overtime_requires_client_and_employee_terms',
                        'employee_id'=>(int)$employeeId,
                        'month'=>$month,'commercial_hours'=>round($total,2),
                        'normal_hours'=>$norm,
                    ];
                }
            }
        }
        foreach($entries as $entry){
            $hours=(float)($entry['hours']??0);
            if($hours<=0)continue;
            $date=substr((string)($entry['work_date']??''),0,10);
            $clientId=(int)($entry['client_id']??0);
            $projectId=(int)($entry['project_id']??0);
            $employeeId=(int)($entry['employee_id']??0);
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
            $term=$valid->first();$rate=(float)$term['hourly_rate'];
            $amount=round($hours*$rate,2);
            $accountApproved=$approvals->contains(fn($a)=>
                (int)($a['employee_id']??0)===$employeeId
                && (int)($a['project_id']??0)===$projectId
                && substr((string)($a['month']??''),0,7)===$month);
            $clientPeriod=$periods->first(fn($p)=>(int)($p['client_id']??0)===$clientId
                && substr((string)($p['period_start']??''),0,10)<=$date
                && substr((string)($p['period_end']??''),0,10)>=$date);
            $hasOverride=$clientPeriod && $overrides->contains(fn($o)=>
                (int)($o['reporting_period_id']??0)===(int)$clientPeriod['id']
                && (int)($o['member_term_id']??0)===(int)$term['term_id']);
            if($hasOverride){
                $errors[]=['date'=>$date,'project_id'=>$projectId,'employee_id'=>$employeeId,
                    'reason'=>'client_approved_hours_override_requires_period_allocation'];
            }
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
            $preliminary+=$amount;
            if($clientApproved&&$accountApproved&&!$hasOverride)$confirmed+=$amount;
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
            'preliminary_amount'=>$quality==='complete'?round($preliminary,2):null,
            'client_agreed_amount'=>$quality==='complete'?round($confirmed,2):null,
            'hours'=>round(array_sum(array_column($rows,'hours')),2),
            'quality'=>$quality,
            'unknown_rows'=>$errors,'source'=>'Clients member_terms + Timesheets final_approvals',
            'note'=>'Если клиент согласовал отличающиеся часы, точное распределение суммы по месяцам отмечается неполным; тарифы переработок требуют детализации',
        ];
    }
}
