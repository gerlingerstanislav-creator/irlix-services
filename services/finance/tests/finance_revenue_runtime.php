<?php
declare(strict_types=1);

// Isolated real Laravel HTTP client contract test. All upstream data is mocked;
// this does not prove production Clients/Timesheets availability.
require '/app/vendor/autoload.php';
$app=require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function(\Throwable $e): void {
    fwrite(STDERR,'Finance revenue source validation failed: '.$e->getMessage().PHP_EOL);
    exit(1);
});

use Illuminate\Support\Facades\Http;
use App\Support\RevenueReport;

function validateRevenue(bool $passed,string $message): void {
    if(!$passed)throw new RuntimeException($message);
}

$fixture=[
    'hours'=>1,
    'rate'=>'100.00',
    'duplicate_period'=>false,
    'work_date'=>'2026-10-01',
    'client_status'=>'Акт согласован',
    'account_approved'=>true,
    'employee_id'=>5,
    'approved_regular'=>null,
    'approved_overtime'=>0,
    'period_multiplier'=>1,
    'period_start'=>'2026-10-01',
    'period_end'=>'2026-10-31',
];
Http::fake(function($request) use (&$fixture){
    $url=$request->url();
    if(str_contains($url,'/finance/commercial-terms')){
        $periods=[['id'=>21,'client_id'=>2,'period_start'=>$fixture['period_start'],
            'period_end'=>$fixture['period_end'],'overtime_multiplier'=>$fixture['period_multiplier'],
            'status'=>$fixture['client_status']]];
        if($fixture['duplicate_period']){
            $periods[]=['id'=>22,'client_id'=>2,'period_start'=>'2026-10-01',
                'period_end'=>'2026-10-31','status'=>'Акт согласован'];
        }
        return Http::response(['data'=>[
            'terms'=>[['term_id'=>9,'project_id'=>10,'client_id'=>2,
                'employee_id'=>5,'hourly_rate'=>$fixture['rate'],
                'valid_from'=>'2026-10-01','valid_to'=>null]],
            'periods'=>$periods,'approved_hours_overrides'=>$fixture['approved_regular']===null
                ?[]:[['reporting_period_id'=>21,'member_term_id'=>9,
                    'hours'=>$fixture['approved_regular'],'overtime_hours'=>$fixture['approved_overtime']]],
        ]],200);
    }
    if(str_contains($url,'/finance/commercial-data')){
        return Http::response(['data'=>[
            'entries'=>[['employee_id'=>$fixture['employee_id'],'project_id'=>10,'client_id'=>2,
                'work_date'=>$fixture['work_date'],'hours'=>$fixture['hours']]],
            'final_approvals'=>$fixture['account_approved']
                ?[['employee_id'=>5,'project_id'=>10,'month'=>'2026-10-01']]:[],
        ]],200);
    }
    if(str_contains($url,'/production-calendar')){
        return Http::response(['data'=>['available'=>true,
            'days'=>['2026-10-01'=>['is_working'=>true,'is_short'=>false]]]],200);
    }
    return Http::response(['message'=>'Unexpected revenue fixture URL'],500);
});

$normal=RevenueReport::month('2026-10');
validateRevenue($normal['quality']==='complete' && $normal['client_agreed_amount']===100.0,
    'confirmed revenue must be computed from a unique trusted period and rate');

$fixture['rate']='invalid';
$badRate=RevenueReport::month('2026-10');
validateRevenue($badRate['quality']==='incomplete'
    && $badRate['client_agreed_amount']===null
    && collect($badRate['unknown_rows'])->contains(fn($x)=>
        ($x['reason']??'')==='invalid_commercial_rate'),
    'invalid rate must not be treated as a zero rate or certified revenue');

$fixture['rate']='0.00';
$zeroRate=RevenueReport::month('2026-10');
validateRevenue($zeroRate['quality']==='complete' && $zeroRate['client_agreed_amount']===0.0,
    'explicitly agreed zero tariff remains a legitimate documented zero');

$fixture['rate']='100.00';
$fixture['hours']=-2;
$badHours=RevenueReport::month('2026-10');
validateRevenue($badHours['quality']==='incomplete'
    && $badHours['client_agreed_amount']===null
    && collect($badHours['unknown_rows'])->contains(fn($x)=>
        ($x['reason']??'')==='invalid_timesheet_hours'),
    'negative Timesheet hours cannot silently disappear');

$fixture['hours']=1;
$fixture['duplicate_period']=true;
$ambiguous=RevenueReport::month('2026-10');
validateRevenue($ambiguous['quality']==='incomplete'
    && $ambiguous['client_agreed_amount']===null
    && collect($ambiguous['unknown_rows'])->contains(fn($x)=>
        ($x['reason']??'')==='client_reporting_period_ambiguous'),
    'overlapping Clients reporting periods must not certify arbitrary revenue');

$fixture['duplicate_period']=false;
$fixture['client_status']='Акт на согласовании';
$pending=RevenueReport::month('2026-10');
validateRevenue($pending['quality']==='awaiting_approvals'
    && $pending['preliminary_amount']===100.0
    && $pending['client_agreed_amount']===null,
    'valid pending client approval must retain estimated revenue, without certified revenue');

$fixture['client_status']='Акт согласован';
$fixture['account_approved']=false;
$pendingTimesheet=RevenueReport::month('2026-10');
validateRevenue($pendingTimesheet['quality']==='awaiting_approvals'
    && $pendingTimesheet['preliminary_amount']===100.0
    && $pendingTimesheet['client_agreed_amount']===null,
    'valid pending Timesheet approval keeps preliminary management revenue');
$fixture['account_approved']=true;

$fixture['work_date']='2026-09-30';
$wrongMonth=RevenueReport::month('2026-10');
validateRevenue($wrongMonth['quality']==='incomplete'
    && $wrongMonth['client_agreed_amount']===null
    && $wrongMonth['preliminary_amount']===null
    && count($wrongMonth['rows'])===0
    && collect($wrongMonth['unknown_rows'])->contains(fn($x)=>
        ($x['reason']??'')==='timesheet_entry_outside_requested_month'),
    'wrong-month Timesheet data must not shift service revenue into October');

$fixture['work_date']='2026-10-99';
$invalidDate=RevenueReport::month('2026-10');
validateRevenue($invalidDate['quality']==='incomplete'
    && collect($invalidDate['unknown_rows'])->contains(fn($x)=>
        ($x['reason']??'')==='invalid_timesheet_work_date'),
    'impossible work dates must invalidate revenue');

$fixture['work_date']='2026-10-01';
$fixture['employee_id']=0;
$invalidIdentity=RevenueReport::month('2026-10');
validateRevenue($invalidIdentity['quality']==='incomplete'
    && collect($invalidIdentity['unknown_rows'])->contains(fn($x)=>
        ($x['reason']??'')==='invalid_timesheet_identity'),
    'missing employee identity must invalidate revenue source');
$fixture['employee_id']=5;

// Client-approved figures are independent from actual Timesheets hours.
$fixture['hours']=160;
$fixture['approved_regular']=150;
$fixture['approved_overtime']=0;
$fixture['period_multiplier']=2;
$less=RevenueReport::month('2026-10');
validateRevenue($less['quality']==='complete' && $less['client_agreed_amount']===15000.0
    && $less['rows'][0]['hours']===160.0, '150 + 0 / 160 uses AM approved hours');

$fixture['hours']=150;
$fixture['approved_regular']=160;
$more=RevenueReport::month('2026-10');
validateRevenue($more['quality']==='complete' && $more['client_agreed_amount']===16000.0
    && $more['rows'][0]['hours']===150.0, '160 + 0 / 150 is permitted');

$fixture['hours']=160;
$fixture['approved_regular']=150;
$fixture['approved_overtime']=10;
$fixture['period_multiplier']=1.5;
$ot=RevenueReport::month('2026-10');
validateRevenue($ot['quality']==='complete' && $ot['client_agreed_amount']===16500.0
    && $ot['rows'][0]['approved_overtime_hours_total']===10.0
    && $ot['rows'][0]['overtime_multiplier']===1.5,
    '150 + 10 / 160 x1.5 applies premium to overtime only');

$fixture['client_status']='Акт на согласовании';
$pendingOverride=RevenueReport::month('2026-10');
validateRevenue($pendingOverride['quality']==='awaiting_approvals'
    && $pendingOverride['preliminary_amount']===16500.0
    && $pendingOverride['client_agreed_amount']===null,
    'pending act has preliminary commercial amount, not confirmed revenue');
$fixture['client_status']='Акт согласован';

$fixture['period_start']='2026-09-20';
$cross=RevenueReport::month('2026-10');
validateRevenue($cross['quality']==='incomplete'
    && $cross['client_agreed_amount']===null && $cross['preliminary_amount']===null
    && collect($cross['unknown_rows'])->contains(fn($x)=>
        ($x['reason']??'')==='client_approved_hours_requires_month_allocation'),
    'a cross-month AM override must not all land in October');
$fixture['period_start']='2026-10-01';

$fixture['period_multiplier']=null;
$missingFactor=RevenueReport::month('2026-10');
validateRevenue($missingFactor['quality']==='incomplete'
    && collect($missingFactor['unknown_rows'])->contains(fn($x)=>
        ($x['reason']??'')==='invalid_client_approved_hours_or_multiplier'),
    'missing period factor may not default silently');
$fixture['period_multiplier']=1;
$fixture['approved_regular']=null;
$fixture['approved_overtime']=0;
$fixture['hours']=1;

echo "Finance revenue source guards OK: service dates, AM hours and period overtime\n";
