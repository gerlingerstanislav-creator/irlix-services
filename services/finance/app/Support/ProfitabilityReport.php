<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ProfitabilityReport
{
    private static function sources(string $month): array
    {
        $token=(string)env('IRLIX_FINANCE_EMPLOYEES_TOKEN','');
        if(strlen($token)<32)throw new HttpException(503,'Employees salary integration is not configured');
        try{
            $employees=Http::withHeaders(['X-Irlix-Finance-Employees-Token'=>$token])
                ->acceptJson()->timeout(8)->get(
                    (string)env('EMPLOYEES_FINANCE_URL','http://employees:8000/internal/system/finance/payroll-conditions'),
                    ['month'=>$month]
                );
            $calendar=Http::acceptJson()->timeout(8)->get(
                rtrim((string)env('PLATFORM_CORE_URL','http://platform-core:8000/api'),'/')
                .'/production-calendar',['year'=>(int)substr($month,0,4)]);
        }catch(\Throwable){throw new HttpException(503,'Payroll or calendar source unavailable');}
        if(!$employees->successful() || !$calendar->successful()
            || $calendar->json('data.available')!==true)
            throw new HttpException(503,'Payroll source or production calendar incomplete');
        return [(array)$employees->json('data',[]),(array)$calendar->json('data',[])];
    }

    public static function month(string $month): array
    {
        $revenue=RevenueReport::month($month);
        [$staff,$calendar]=self::sources($month);
        $days=(array)($calendar['days']??[]);
        $norm=0;
        foreach($days as $date=>$day){
            if(!str_starts_with((string)$date,$month))continue;
            if(!empty($day['is_working']))$norm+=empty($day['is_short'])?8:7;
        }
        if($norm<1)throw new HttpException(503,'Production calendar has no norm for month');
        $conditions=collect($staff['salary_conditions']??[])->groupBy('employee_id');
        $employees=collect($staff['employees']??[])->keyBy('id');
        $entries=collect($revenue['rows']);
        $workable=$entries->filter(fn($row)=>!empty($row['account_confirmed']));
        $hoursByEmployee=$workable->groupBy('employee_id')->map(fn($rows)=>
            $rows->sum(fn($x)=>(float)$x['hours']));
        $base=DB::table('accruals')->where('recognition_month',$month)
            ->whereIn('kind',['salary','bonus','contribution'])
            ->whereNotNull('employee_id')
            ->select('employee_id')->selectRaw('SUM(amount) AS pool')
            ->groupBy('employee_id')->get()->keyBy('employee_id');
        $details=[];$incomplete=[];
        foreach($entries as $row){
            $emp=(int)$row['employee_id'];$hours=(float)$row['hours'];
            $pool=$base->get($emp);
            $cost=null;
            if($pool && !empty($row['account_confirmed'])){
                $all=(float)($hoursByEmployee[$emp]??0);
                // A month of base cost is never allocated above 100% when hours
                // exceed the monthly calendar norm. Non-commercial time remains
                // unallocated to clients.
                $ratio=$all>$norm ? $norm/$all : 1.0;
                $cost=round((float)$pool->pool/$norm*$hours*$ratio,2);
            } else {
                $incomplete[]=['employee_id'=>$emp,'reason'=>$pool
                    ?'timesheet_not_account_confirmed':'no_confirmed_payroll_accruals'];
            }
            $clientAgreed=$row['status']==='agreed';
            $amount=$clientAgreed?(float)$row['amount']:null;
            $margin=$amount!==null && $cost!==null?round($amount-$cost,2):null;
            $details[]=[
                'client_id'=>$row['client_id'],'client_name'=>$row['client_name'],
                'project_id'=>$row['project_id'],'project_name'=>$row['project_name'],
                'employee_id'=>$emp,'employee_name'=>$employees->get($emp)['full_name']??null,
                'hours'=>$hours,'revenue'=>$amount,'preliminary_revenue'=>$row['amount'],
                'direct_labor_cost'=>$cost,'labor_margin'=>$margin,
                'normal_month_hours'=>$norm,'source_salary_conditions'=>$conditions->has($emp),
                'quality'=>$margin===null?'incomplete':'labor_cost_only'
            ];
        }
        // Explicit client-linked contractor/other accruals are incremental
        // direct costs. Payroll cannot be directly tagged because its labor
        // component is already proportionally attributed through Timesheets.
        $clientDirect=DB::table('accruals')->where('recognition_month',$month)
            ->whereIn('kind',['contractor','other'])
            ->whereNotNull('client_id')->select('client_id')
            ->selectRaw('SUM(amount) AS total')
            ->groupBy('client_id')->get()->keyBy('client_id');
        $groups=collect($details)->groupBy('client_id')->map(function($items,$clientId)use($clientDirect,$revenue){
            // Missing commercial terms/approvals can remove rows entirely;
            // don't present a seemingly complete client margin from a partial dataset.
            $anyUnknown=$revenue['quality']!=='complete' || $items->contains(fn($a)=>$a['labor_margin']===null);
            $labor=$anyUnknown?null:round($items->sum('labor_margin'),2);
            $direct=round((float)($clientDirect->get($clientId)->total??0),2);
            return [
                'client_id'=>$items->first()['client_id'],
                'client_name'=>$items->first()['client_name'],
                'hours'=>round($items->sum('hours'),2),
                'revenue'=>$anyUnknown?null:round($items->sum('revenue'),2),
                'direct_labor_cost'=>$anyUnknown?null:round($items->sum('direct_labor_cost'),2),
                'labor_margin'=>$labor,
                'other_direct_cost'=>$direct,
                'direct_contribution_margin'=>$labor===null?null:round($labor-$direct,2),
                'quality'=>$anyUnknown?'incomplete':'partial_direct_costs'
            ];
        });
        // An explicitly booked direct cost remains visible even if its client
        // has no confirmed labor entries in the selected month.
        foreach($clientDirect as $clientId=>$direct){
            if($groups->has($clientId))continue;
            $groups->put($clientId,[
                'client_id'=>(int)$clientId,'client_name'=>'Клиент #'.$clientId,
                'hours'=>0,'revenue'=>null,'direct_labor_cost'=>null,
                'labor_margin'=>null,'other_direct_cost'=>round((float)$direct->total,2),
                'direct_contribution_margin'=>null,'quality'=>'no_commercial_entries'
            ]);
        }
        return [
            'month'=>$month,'norm_hours'=>$norm,'rows'=>$groups->values()->all(),'details'=>$details,
            'quality'=>$revenue['quality']==='complete' && !$incomplete?'labor_cost_only':'incomplete',
            'missing'=>$incomplete,'revenue_quality'=>$revenue['quality'],
            'note'=>'Трудовая маржа с дополнительными подтверждёнными прямыми расходами клиентов (подрядчики и прочие). Полная рентабельность всё ещё не подтверждена: отпускные, отдельные переработки и иные компоненты могут отсутствовать.'
        ];
    }
}
