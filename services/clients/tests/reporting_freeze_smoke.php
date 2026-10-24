<?php
require __DIR__.'/client_members_bootstrap.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
clientSchemaLegacy();clientExpand();
DB::connection()->getSchemaBuilder()->create('client_account_delegations',function($t){$t->id();$t->integer('client_id');$t->integer('to_employee_id');$t->date('starts_at');$t->date('ends_at');$t->timestamp('revoked_at')->nullable();});
DB::table('clients')->insert(['id'=>1,'name'=>'Synthetic']);
DB::table('partners')->insert(['id'=>1,'name'=>'Synthetic partner']);
DB::table('partner_specialists')->insert(['id'=>1,'partner_id'=>1,'full_name'=>'Synthetic partner specialist']);
DB::table('client_members')->insert([['id'=>1,'client_id'=>1,'employee_id'=>5,'partner_specialist_id'=>null,'specialist_name'=>'Employee'],['id'=>2,'client_id'=>1,'employee_id'=>null,'partner_specialist_id'=>1,'specialist_name'=>'Partner']]);
foreach([1,2]as $id)DB::table('member_terms')->insert(['id'=>$id,'client_member_id'=>$id,'technology'=>'PHP','level'=>'Senior','hourly_rate'=>100,'hours_per_day'=>8,'valid_from'=>'2026-10-01']);
DB::table('reporting_periods')->insert(['id'=>1,'client_id'=>1,'period_start'=>'2026-10-01','period_end'=>'2026-10-31','status'=>'Новый']);
putenv('IRLIX_TIMESHEETS_INTEGRATION_TOKEN='.str_repeat('s',32));
$entries=[['client_member_id'=>1,'employee_id'=>5,'member_term_id'=>1,'work_date'=>'2026-10-12','hours'=>7.33,'description'=>'Synthetic']];
$approvals=[['client_member_id'=>1,'month'=>'2026-10-01'],['client_member_id'=>2,'month'=>'2026-10-01']];
$revision='synthetic-revision';$released=0;$requests=0;$invalidPayload=null;
Http::fake(function($request)use(&$entries,&$approvals,&$revision,&$released,&$requests,&$invalidPayload){
    clientCheck($request->header('X-Irlix-Timesheets-Token')[0]===str_repeat('s',32),'trusted token header');
    if($request->method()==='POST') return Http::response(['data'=>['freeze_token'=>'synthetic-freeze','revision'=>'synthetic-revision']]);
    if($request->method()==='DELETE'){$released++;return Http::response(['data'=>true]);}
    $requests++;
    clientCheck($request['freeze_token']==='synthetic-freeze','snapshot protected by freeze token');
    $payload=['entries'=>$entries,'final_approvals'=>$approvals,'final_approval_exceptions'=>[],
        'complete'=>true,'count'=>count($entries),'revision'=>$revision,'membership_revision'=>App\Support\TimesheetMemberships::snapshot()['revision']];
    if($invalidPayload!==null)$payload=$invalidPayload($payload);
    return Http::response(['data'=>$payload]);
});
$ctl=new App\Http\Controllers\ReportingPeriodsController();
$send=fn()=>$ctl->update(clientRequest(['status'=>'ТШ на согласовании','date'=>'2026-10-23'],'PATCH'),1);
$malformed=[
    'missing exceptions'=>function($p){unset($p['final_approval_exceptions']);return $p;},
    'string exceptions'=>fn($p)=>[...$p,'final_approval_exceptions'=>'incomplete'],
    'string approvals'=>fn($p)=>[...$p,'final_approvals'=>'incomplete'],
    'non-list entries'=>fn($p)=>[...$p,'entries'=>['fact'=>$p['entries'][0]]],
    'wrong employee identity'=>function($p){$p['entries'][0]['employee_id']=999;return $p;},
    'invalid date'=>function($p){$p['entries'][0]['work_date']='2026-02-30';return $p;},
    'invalid member ID'=>function($p){$p['entries'][0]['client_member_id']='unknown';return $p;},
    'malformed exception'=>fn($p)=>[...$p,'final_approval_exceptions'=>[['client_member_id'=>1,'work_date'=>'invalid']]],
];
foreach($malformed as $reason=>$mutation) {
    $invalidPayload=$mutation;
    clientReject($send,503,$reason);
    $period=DB::table('reporting_periods')->find(1);
    clientCheck($period->status==='Новый'&&$period->timesheet_snapshot===null,'Malformed snapshot leaves no effects');
}
$invalidPayload=null;
$negativeReleases=$released;
clientReject($send,422,'missing expected partner must block send');
clientCheck($released===$negativeReleases+1 && DB::table('reporting_periods')->find(1)->status==='Новый','failed send rolls back status and releases freeze');
$entries[]=['client_member_id'=>2,'employee_id'=>null,'member_term_id'=>2,'work_date'=>'2026-10-12','hours'=>0,'description'=>'Explicit no hours'];
$revision='changed';clientReject($send,409,'concurrent changed revision');
$revision='synthetic-revision';$send();
clientCheck(DB::table('reporting_periods')->find(1)->timesheet_revision===$revision,'snapshot revision persisted');
$before=$requests;$entries[0]['hours']=9;
$show=$ctl->show(clientRequest([], 'GET'),1)->getData(true)['data'];
clientCheck($before===$requests && (float)$show['summary']['worked_hours']===7.33,'sent report uses immutable snapshot');
clientCheck(count($show['specialists'])===2 && $show['specialists'][1]['employee_id']===null,'partner summary retained without employee');
clientCheck($released===$negativeReleases+2,'successful send keeps local freeze');
clientReject(fn()=>$ctl->destroy(1),423,'Sent report cannot be deleted');
echo "Reporting freeze/malformed service responses/revision/missing partner/explicit zero/immutable snapshot PASS\n";
