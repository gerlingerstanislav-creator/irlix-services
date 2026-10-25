<?php
// Synthetic SQLite test for cash-flow monthly facts, rate versions and partners.
require __DIR__.'/client_members_bootstrap.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

clientSchemaLegacy();
clientExpand();
\Illuminate\Database\Eloquent\Model::setConnectionResolver($app['db']);

DB::table('clients')->insert(['id'=>1,'name'=>'Synthetic Cashflow']);
DB::table('partner_specialists')->insert(['id'=>7,'full_name'=>'Synthetic Partner']);
DB::table('client_members')->insert([
 ['id'=>10,'client_id'=>1,'employee_id'=>5,'partner_specialist_id'=>null,'specialist_name'=>'Synthetic Staff'],
 ['id'=>20,'client_id'=>1,'employee_id'=>null,'partner_specialist_id'=>7,'specialist_name'=>'Synthetic Partner'],
]);
// Real projects and project assignments are intentionally absent.
foreach([
 [101,10,'2026-10-01','2026-10-15',100],
 [102,10,'2026-10-16',null,200],
 [201,20,'2026-10-01',null,null],
] as [$id,$member,$start,$end,$rate]) {
 DB::table('member_terms')->insert([
  'id'=>$id,'client_member_id'=>$member,'project_member_id'=>null,
  'valid_from'=>$start,'valid_to'=>$end,'technology'=>'PHP','level'=>'Senior',
  'hourly_rate'=>$rate,'hours_per_day'=>8,'created_at'=>now(),'updated_at'=>now(),
 ]);
}
$entries=[
 ['client_member_id'=>10,'employee_id'=>5,'work_date'=>'2026-10-05','hours'=>'7.33','member_term_id'=>101,'term_conflict'=>false],
 ['client_member_id'=>10,'employee_id'=>5,'work_date'=>'2026-10-20','hours'=>'8.00','member_term_id'=>102,'term_conflict'=>false],
 ['client_member_id'=>20,'employee_id'=>null,'work_date'=>'2026-10-20','hours'=>'6.50','member_term_id'=>201,'term_conflict'=>false],
];
$payload=['data'=>[
 'schema_version'=>3,'from'=>'2026-10-01','to'=>'2026-10-31',
 'complete'=>true,'count'=>count($entries),'entries'=>$entries,
 'final_approvals'=>[
  ['client_member_id'=>10,'month'=>'2026-10-01'],
  ['client_member_id'=>20,'month'=>'2026-10-01'],
 ],
 'final_approval_exceptions'=>[['client_member_id'=>20,'work_date'=>'2026-10-20']],
]];
Http::fake(['*'=>Http::response($payload)]);
$request=\Illuminate\Http\Request::create('/api/clients/cash-flow?month=2026-10','GET',['month'=>'2026-10']);
$result=app(\App\Services\CashFlowReportService::class)->report($request,'2026-10');
$rows=collect($result['rows'])->keyBy('term_id');
clientCheck($result['month']==='2026-10'&&$rows->count()===3,'All canonical commercial terms must be returned');
clientCheck((float)$rows[101]['timesheet_hours']===7.33&&(float)$rows[101]['timesheet_amount']===733.0,'Decimal hours are priced on their original dates');
clientCheck((float)$rows[102]['timesheet_amount']===1600.0,'Later rate applies only after its effective date');
clientCheck((float)$rows[201]['timesheet_hours']===6.5 && $rows[201]['timesheet_amount']===null,'Partner hours without known rate remain unpriced');
clientCheck($rows[101]['final_approved']===true&&$rows[102]['final_approved']===true,'Final approved staff hours preserved');
clientCheck($rows[201]['final_approved']===false&&(float)$rows[201]['confirmed_hours']===0.0,'Daily exception invalidates partner final approval');
clientCheck(DB::table('project_assignments')->count()===0,'Cash flow must not create project assignments');
clientCheck(Http::recorded()->count()===1,'Only one Timesheets snapshot request is needed');
clientCheck(str_contains(Http::recorded()->first()[0]->url(),'/internal/cash-flow-facts?'), 'Cash flow must not call the recursive commercial-data endpoint');
echo "CashFlow canonical monthly rates, approvals, partners and projectless members PASS\n";
