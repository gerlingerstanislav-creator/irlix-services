<?php
require __DIR__.'/client_members_bootstrap.php';
use Illuminate\Support\Facades\DB;
foreach(['compatible','incompatible','protected','ambiguous_approvals'] as $scenario) {
DB::purge();
clientSchemaLegacy();
DB::table('clients')->insert(['id'=>1,'name'=>'Synthetic']);
DB::table('projects')->insert([['id'=>1,'client_id'=>1],['id'=>2,'client_id'=>1]]);
foreach([1,2] as $id) DB::table('project_members')->insert(['id'=>$id,'project_id'=>$id,'specialist_id'=>5,'specialist_name'=>'Synthetic']);
foreach([1,2] as $id) DB::table('member_terms')->insert(['id'=>$id,'project_member_id'=>$id,'technology'=>'PHP','level'=>'Senior','hourly_rate'=>$scenario==='incompatible'&&$id===2?'200.00':'100.00','hours_per_day'=>8,'valid_from'=>$id===1?'2026-01-01':'2026-02-01','valid_to'=>$id===1?'2026-03-31':null]);
DB::table('reporting_period_approved_hours')->insert(['reporting_period_id'=>1,'member_term_id'=>2,'hours'=>7.33]);
if($scenario==='protected') DB::table('reporting_periods')->insert(['client_id'=>1,'period_start'=>'2026-02-01','period_end'=>'2026-02-28','status'=>'Счет оплачен']);
if($scenario==='ambiguous_approvals') DB::table('reporting_period_approved_hours')->insert(['reporting_period_id'=>1,'member_term_id'=>1,'hours'=>8]);
if($scenario!=='compatible') {
    DB::beginTransaction();
    try{clientExpand();throw new LogicException('Ambiguous migration accepted');}
    catch(RuntimeException $e){clientCheck(!($e instanceof LogicException),'Ambiguous migration accepted');}
    finally{DB::rollBack();}
    clientCheck(DB::table('member_terms')->count()===2 && !DB::getSchemaBuilder()->hasTable('client_members'),'Rejected expand leaves original schema and facts intact');
    continue;
}
clientExpand();
clientCheck(DB::table('client_members')->count()===1,'one enduring membership');
clientCheck(DB::table('member_terms')->count()===2 && DB::table('member_terms')->whereNotNull('client_member_id')->count()===1,'source IDs retained, one canonical term');
clientCheck(DB::table('member_term_lineage')->count()===2,'every original payload retained');
clientCheck(DB::table('project_assignments')->count()===0,'no commercial dates fabricated into project assignments');
clientCheck(DB::table('member_terms')->find(2)->superseded_by_term_id===1,'source alias');
clientCheck(App\Support\MemberTermHistory::approvedHours(1)->first()->member_term_id===1,'approved source resolves canonical');
clientCheck((float)App\Support\MemberTermHistory::approvedHours(1)->first()->hours===7.33,'approval exact hours');
(require dirname(__DIR__).'/database/migrations/2026_10_23_000002_client_members.php')->down();
clientCheck(DB::table('member_terms')->find(1)->valid_to==='2026-03-31','rollback restores original interval');
}
echo "Clients SQLite expand/coalescing/lineage/down + incompatible/protected/ambiguous refusal PASS\n";
