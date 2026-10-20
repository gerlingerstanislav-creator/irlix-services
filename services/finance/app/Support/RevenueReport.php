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
        $entries=collect((array)$timesheet->json('data.entries',[]));
        $approvals=collect((array)$timesheet->json('data.final_approvals',[]));
        $rows=[];$errors=[];$preliminary=0;$confirmed=0;
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
            if($clientApproved&&$accountApproved)$confirmed+=$amount;
        }
        return [
            'month'=>$month,'rows'=>$rows,
            'preliminary_amount'=>count($errors)?null:round($preliminary,2),
            'client_agreed_amount'=>count($errors)?null:round($confirmed,2),
            'hours'=>round(array_sum(array_column($rows,'hours')),2),
            'quality'=>count($errors)?'incomplete':'complete',
            'unknown_rows'=>$errors,'source'=>'Clients member_terms + Timesheets final_approvals',
            'note'=>'Отклонения часов/сумм в акте и тарифы переработок требуют отдельной детализации',
        ];
    }
}
