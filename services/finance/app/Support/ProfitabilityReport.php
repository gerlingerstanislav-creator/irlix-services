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
        $groups=collect($details)->groupBy('client_id')->map(function($items){
            $anyUnknown=$items->contains(fn($a)=>$a['labor_margin']===null);
            return [
                'client_id'=>$items->first()['client_id'],
                'client_name'=>$items->first()['client_name'],
                'hours'=>round($items->sum('hours'),2),
                'revenue'=>$anyUnknown?null:round($items->sum('revenue'),2),
                'direct_labor_cost'=>$anyUnknown?null:round($items->sum('direct_labor_cost'),2),
                'labor_margin'=>$anyUnknown?null:round($items->sum('labor_margin'),2),
                'quality'=>$anyUnknown?'incomplete':'labor_cost_only'
            ];
        })->values()->all();
        return [
            'month'=>$month,'norm_hours'=>$norm,'rows'=>$groups,'details'=>$details,
            'quality'=>$revenue['quality']==='complete' && !$incomplete?'labor_cost_only':'incomplete',
            'missing'=>$incomplete,'revenue_quality'=>$revenue['quality'],
            'note'=>'Маржа только по подтверждённым трудозатратам. Не является полной рентабельностью: пока не рассчитаны отпуска, накладные, специальные переработки и отдельные прямые расходы.'
        ];
    }
}
