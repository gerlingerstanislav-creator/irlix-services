<?php
require __DIR__.'/assignment_reconciliation_smoke.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Support\ProjectHoursConverter;
use App\Support\TimeHours;

Http::swap(new Illuminate\Http\Client\Factory());
$member=['client_member_id'=>501,'client_id'=>101,'employee_id'=>1,'partner_specialist_id'=>null,
    'specialist_name'=>'Synthetic Specialist','client_name'=>'Synthetic Client','account_employee_id'=>100,
    'delegated_account_employee_ids'=>[],'member_term_id'=>71,'valid_from'=>'2026-01-01','valid_to'=>null];
Http::fake(function($request)use($member){
    $payload=['complete'=>true,'data'=>[],'count'=>0,'locked_reporting_periods_complete'=>true,'locked_reporting_periods'=>[],'locked_reporting_periods_count'=>0];
    if(str_ends_with($request->url(),'/timesheet-memberships'))return Http::response(array_replace($payload,['revision'=>'synthetic-revision','data'=>[$member],'count'=>1]));
    if(str_ends_with($request->url(),'/timesheet-assignments'))return Http::response($payload+[
        'project_clients_complete'=>true,'project_clients_count'=>2,'project_clients'=>[['project_id'=>11,'client_id'=>101],['project_id'=>12,'client_id'=>101]]]);
    throw new RuntimeException('Unexpected dependency '.$request->url());
});
DB::table('timesheet_entries')->delete();DB::table('timesheet_audit')->delete();
foreach([[11,'3.33','First description'],[12,'4.00','Second description']] as [$project,$hours,$description])DB::table('timesheet_entries')->insert([
    'employee_id'=>1,'project_id'=>$project,'client_member_id'=>null,'work_date'=>'2026-10-10','hours'=>$hours,'description'=>$description,'created_at'=>now(),'updated_at'=>now()]);
foreach([[11,'2026-10-01'],[12,'2026-10-01'],[11,'2026-11-01']] as [$project,$month])DB::table('final_approvals')->insert([
    'employee_id'=>1,'project_id'=>$project,'client_member_id'=>null,'month'=>$month,'approved_by'=>100,'approved_at'=>'2026-10-20 10:00:00','created_at'=>now(),'updated_at'=>now()]);
DB::table('final_approval_exceptions')->insert(['employee_id'=>1,'project_id'=>12,'client_member_id'=>null,'work_date'=>'2026-10-10','created_at'=>now(),'updated_at'=>now()]);
$converter=new ProjectHoursConverter();
$result=$converter->run();
if($result['conflict_count']!==0||$result['target_groups']!==1||$result['proposed_month_approvals']!==2||DB::table('timesheet_entries')->count()!==2)throw new RuntimeException('Conversion dry run failed '.json_encode($result));
putenv('TIMESHEETS_MAINTENANCE_LOCK=1');
$crash=true;
DB::listen(function($query)use(&$crash){
    if($crash && str_starts_with($query->sql,'insert into "final_approvals"'))throw new RuntimeException('Synthetic crash between facts and approvals');
});
try{$converter->run(true);throw new RuntimeException('Expected synthetic failure');}
catch(RuntimeException $e){if($e->getMessage()!=='Synthetic crash between facts and approvals')throw $e;}
if(DB::table('timesheet_entries')->whereNotNull('project_id')->count()!==2||DB::table('timesheet_legacy_sources')->exists()||DB::table('timesheet_audit')->exists())throw new RuntimeException('Crash left partial conversion');
$crash=false;
$result=$converter->run(true);
$fact=DB::table('timesheet_entries')->where('client_member_id',501)->first();
if(!$fact||TimeHours::cents($fact->hours)!==733||!str_contains($fact->description,'First description')||!str_contains($fact->description,'Second description')
    ||DB::table('timesheet_legacy_sources')->count()!==2||DB::table('timesheet_entries')->whereNotNull('project_id')->exists())throw new RuntimeException('Conversion lost facts, precision, descriptions or lineage');
if(DB::table('final_approvals')->where('client_member_id',501)->count()!==2||!DB::table('final_approval_exceptions')->where('client_member_id',501)->exists())throw new RuntimeException('Conversion lost approval/exception or empty month');
$auditCount=DB::table('timesheet_audit')->count();$before=DB::table('final_approvals')->where('client_member_id',501)->orderBy('id')->get()->toJson();
$converter->run(true);
if(DB::table('timesheet_audit')->count()!==$auditCount||DB::table('final_approvals')->where('client_member_id',501)->orderBy('id')->get()->toJson()!==$before)throw new RuntimeException('Conversion retry changed historical approvals');
DB::table('final_approvals')->where('project_id',12)->update(['approved_by'=>200]);
$result=$converter->run();
if($result['conflict_count']<1||!in_array('APPROVAL_STATE_AMBIGUOUS',array_column($result['conflicts'],'code'),true))throw new RuntimeException('Ambiguous approval was silently chosen');
echo "Project conversion passed: exact aggregation/descriptions/lineage, empty approval month, day exceptions, atomic crash rollback, idempotent retry, ambiguous approval refusal.\n";
