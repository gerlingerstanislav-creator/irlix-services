<?php
declare(strict_types=1);

// Actual PostgreSQL accrual pool + mocked trusted upstream APIs. This protects
// Finance reporting invariants, not the full live service E2E.
require '/app/vendor/autoload.php';
$app=require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function(\Throwable $e): void {
    fwrite(STDERR,'Finance profitability contract failed: '.$e->getMessage().PHP_EOL);
    exit(1);
});

use App\Support\ProfitabilityReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function assertMargin(bool $ok,string $why): void {
    if(!$ok)throw new RuntimeException($why);
}

$fixture=['hours'=>1,'client_status'=>'Акт согласован'];
Http::fake(function($request)use(&$fixture){
    $url=$request->url();
    if(str_contains($url,'/finance/commercial-terms')){
        return Http::response(['data'=>[
            'terms'=>[['term_id'=>9,'client_id'=>2,'project_id'=>10,
                'employee_id'=>5,'hourly_rate'=>'100.00',
                'valid_from'=>'2026-10-01','valid_to'=>null]],
            'periods'=>[['id'=>21,'client_id'=>2,'period_start'=>'2026-10-01',
                'period_end'=>'2026-10-31','status'=>$fixture['client_status']]],
            'approved_hours_overrides'=>[],
        ]],200);
    }
    if(str_contains($url,'/finance/commercial-data')){
        return Http::response(['data'=>[
            'entries'=>[['employee_id'=>5,'client_id'=>2,'project_id'=>10,
                'work_date'=>'2026-10-01','hours'=>$fixture['hours']]],
            'final_approvals'=>[['employee_id'=>5,'project_id'=>10,'month'=>'2026-10-01']],
        ]],200);
    }
    if(str_contains($url,'/payroll-conditions')){
        return Http::response(['data'=>[
            'salary_conditions'=>[['employee_id'=>5]],
            'employees'=>[['id'=>5,'full_name'=>'CI payroll fixture']],
        ]],200);
    }
    if(str_contains($url,'/production-calendar')){
        return Http::response(['data'=>[
            'available'=>true,
            'days'=>['2026-10-01'=>['is_working'=>true,'is_short'=>false]],
        ]],200);
    }
    return Http::response(['message'=>'Unexpected source URL'],500);
});

$id=DB::table('accruals')->insertGetId([
    'recognition_month'=>'2026-10','kind'=>'salary',
    'article'=>'Profitability source acceptance test',
    'cost_center'=>'direction:qa','employee_id'=>5,
    'amount'=>'100.00','created_by'=>'ci',
    'created_at'=>now(),'updated_at'=>now(),
]);

try{
    $agreed=ProfitabilityReport::month('2026-10');
    $detail=$agreed['details'][0]??[];
    assertMargin($agreed['revenue_quality']==='complete'
        && $detail['revenue']===100.0
        && $detail['labor_margin']===87.5,
        'complete approved period can expose a clearly labeled direct labor margin');

    $fixture['hours']=9; // Monthly actual hours above an 8h fixture norm.
    $overtime=ProfitabilityReport::month('2026-10');
    $detail=$overtime['details'][0]??[];
    assertMargin($overtime['revenue_quality']==='complete'
        && $detail['revenue']===900.0
        && $detail['direct_labor_cost']===100.0
        && $detail['labor_margin']===800.0
        && $overtime['rows'][0]['revenue']===900.0,
        'AM-approved commercial revenue is not blocked by global employee monthly norm');

    $fixture['hours']=1;
    $fixture['client_status']='Акт на согласовании';
    $pending=ProfitabilityReport::month('2026-10');
    $detail=$pending['details'][0]??[];
    assertMargin($pending['revenue_quality']==='awaiting_approvals'
        && $detail['revenue']===null
        && $detail['labor_margin']===null
        && $detail['preliminary_revenue']===100.0,
        'pending Client approval preserves estimate but never labels margin confirmed');
}finally{
    DB::table('accruals')->where('id',$id)->delete();
}

echo "Finance profitability guard OK: incomplete and unapproved revenue never certifies detail margin\n";
