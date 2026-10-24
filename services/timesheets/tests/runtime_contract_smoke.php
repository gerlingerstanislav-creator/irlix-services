<?php
// Actual route dispatch against the persisted schema; all identities and HTTP
// dependencies below are synthetic, never production service credentials.
require __DIR__.'/assignment_reconciliation_smoke.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Support\ClientMemberDirectory;

$app->register(Illuminate\Foundation\Providers\FoundationServiceProvider::class);
$app->register(Illuminate\Validation\ValidationServiceProvider::class);
$app['config']->set('app.debug',true);
$app->boot();
$app->instance('Illuminate\Contracts\Debug\ExceptionHandler',new Illuminate\Foundation\Exceptions\Handler($app));
$app['router']->prefix('internal')->group(dirname(__DIR__).'/routes/integrations.php');
$app['router']->prefix('internal/system')->group(dirname(__DIR__).'/routes/system.php');
$actor=1;$scope='own';$admin=false;$permission=true;$broken=null;$locked=[];
$rows=[
 ['client_member_id'=>501,'client_id'=>101,'employee_id'=>1,'partner_specialist_id'=>null,
  'specialist_name'=>'Employee A','client_name'=>'Synthetic Client','account_employee_id'=>100,
  'delegated_account_employee_ids'=>[],'member_term_id'=>71,'valid_from'=>'2026-10-01','valid_to'=>'2026-10-31'],
 ['client_member_id'=>503,'client_id'=>103,'employee_id'=>1,'partner_specialist_id'=>null,
  'specialist_name'=>'Employee A','client_name'=>'Other Client','account_employee_id'=>200,
  'delegated_account_employee_ids'=>[],'member_term_id'=>73,'valid_from'=>'2026-10-01','valid_to'=>'2026-10-31'],
 ['client_member_id'=>502,'client_id'=>101,'employee_id'=>null,'partner_specialist_id'=>5,
  'specialist_name'=>'Partner Person','client_name'=>'Synthetic Client','account_employee_id'=>100,
  'delegated_account_employee_ids'=>[300],'member_term_id'=>72,'valid_from'=>'2026-10-01','valid_to'=>'2026-10-31'],
 ['client_member_id'=>504,'client_id'=>103,'employee_id'=>null,'partner_specialist_id'=>5,
  'specialist_name'=>'Partner Person','client_name'=>'Other Client','account_employee_id'=>200,
  'delegated_account_employee_ids'=>[],'member_term_id'=>74,'valid_from'=>'2026-10-01','valid_to'=>'2026-10-31'],
];
$employees=[['id'=>1,'full_name'=>'Employee A','department_id'=>10],['id'=>2,'full_name'=>'Employee B','department_id'=>20],
    ['id'=>100,'full_name'=>'AM'],['id'=>200,'full_name'=>'Other AM'],['id'=>300,'full_name'=>'Delegate']];
Http::swap(new Illuminate\Http\Client\Factory());
Http::fake(function($request)use(&$actor,&$rows,&$broken,&$locked,&$scope,&$admin,&$permission,$employees){
    $path=parse_url($request->url(),PHP_URL_PATH);
    if($path==='/internal/system/timesheet-memberships'){
        $payload=['complete'=>true,'revision'=>hash('sha256',json_encode($rows)),'count'=>count($rows),'data'=>$rows,
            'locked_reporting_periods_complete'=>true,'locked_reporting_periods_count'=>count($locked),'locked_reporting_periods'=>$locked];
        if($broken==='outage')return Http::response([],503);
        if($broken==='incomplete')$payload['complete']=false;
        if($broken==='identity')$payload['data'][0]['employee_id']=0;
        if($broken==='date')$payload['data'][0]['valid_to']='2026-02-30';
        if($broken==='delegates')$payload['data'][0]['delegated_account_employee_ids']=['100'];
        if($broken==='overlap'){$payload['data'][]=$payload['data'][0];$payload['count']++;}
        if($broken==='lock'){$payload['locked_reporting_periods']=[['id'=>1,'client_id'=>101,'period_start'=>'x','period_end'=>'2026-10-20','status'=>'ТШ согласованы']];$payload['locked_reporting_periods_count']=1;}
        return Http::response($payload);
    }
    if(str_ends_with($path,'/permissions/me'))return Http::response(['data'=>['platform_admin'=>$admin,'permissions'=>array_fill_keys(
        ['timesheets.management.view','timesheets.management.manage','timesheets.audit.view','timesheets.analytics.view'],['allowed'=>$permission,'scope'=>$scope])]]);
    if(str_ends_with($path,'/self'))return Http::response(['data'=>['id'=>$actor,'full_name'=>'Corporate Actor']]);
    if(str_ends_with($path,'/clients-directory'))return Http::response(['data'=>['employees'=>$employees,'departments'=>[['id'=>10,'manager_id'=>400],['id'=>20,'manager_id'=>500]]]]);
    if(str_ends_with($path,'/access/me'))return Http::response(['data'=>['roles'=>$admin?['platform-admin']:[]]]);
    if(str_ends_with($path,'/calendar-absences'))return Http::response(['data'=>[]]);
    if(str_ends_with($path,'/reporting-period-lock'))return Http::response(['data'=>['locked'=>count($locked)>0]]);
    throw new RuntimeException('Unexpected dependency '.$request->url());
});
$checks=0;
$call=function(string $method,string $url,array $data=[],int $expected=200,array $headers=[])use($app,&$checks):array{
    $request=Request::create('http://localhost'.$url,$method,$data);
    $request->headers->set('Accept','application/json');$request->headers->set('Authorization','Bearer synthetic');
    foreach($headers as $key=>$value)$request->headers->set($key,$value);
    $app->instance('request',$request);Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
    try{$response=$app['router']->dispatch($request);$status=$response->getStatusCode();$body=json_decode($response->getContent(),true)??[];}
    catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){$status=$e->getStatusCode();$body=['message'=>$e->getMessage()];}
    catch(Illuminate\Validation\ValidationException $e){$status=422;$body=['message'=>$e->getMessage()];}
    if($status!==$expected)throw new RuntimeException($method.' '.$url.' expected '.$expected.' got '.$status.' '.json_encode($body));
    $checks++;return $body;
};
DB::table('timesheet_entries')->delete();DB::table('timesheet_audit')->delete();
$call('PUT','/entries',['client_member_id'=>501,'work_date'=>'2026-10-01','hours'=>8,'description'=>'No project']);
if(DB::table('timesheet_entries')->where('client_member_id',501)->value('project_id')!==null)throw new RuntimeException('Project dependency remains');
$call('GET','/workspace?month=2026-10');
$call('POST','/confirm',['dates'=>['2026-10-01']]);
$call('PUT','/entries',['client_member_id'=>501,'work_date'=>'2026-10-01','hours'=>7.5]);
if(DB::table('employee_confirmations')->exists())throw new RuntimeException('Edit retained daily confirmation');
$call('PUT','/entries',['client_member_id'=>502,'work_date'=>'2026-10-01','hours'=>8],422);
$call('PUT','/entries',['client_member_id'=>501,'work_date'=>'2026-09-30','hours'=>8],422);
// Legacy facts count towards the same employee day, even with null member ID.
DB::table('timesheet_entries')->insert(['employee_id'=>1,'project_id'=>7,'client_member_id'=>null,'work_date'=>'2026-10-02','hours'=>20,'created_at'=>now(),'updated_at'=>now()]);
$call('PUT','/entries',['client_member_id'=>501,'work_date'=>'2026-10-02','hours'=>8],422);
DB::table('timesheet_entries')->whereNotNull('project_id')->delete();
$actor=100;
$call('GET','/management?month=2026-10');
$call('PUT','/management/entries',['employee_id'=>1,'work_date'=>'2026-10-01','entries'=>[['client_member_id'=>501,'hours'=>8,'description'=>'AM']]]);
$call('PUT','/management/entries',['employee_id'=>1,'work_date'=>'2026-10-01','client_member_id'=>503,'hours'=>8],403);
$call('PUT','/management/bulk-hours',['employee_id'=>1,'client_member_id'=>501,'dates'=>['2026-10-02','2026-10-03'],'hours'=>8]);
$call('POST','/management/final-approval',['employee_id'=>1,'client_member_id'=>501,'month'=>'2026-10','approved'=>true]);
$call('PUT','/management/entries',['employee_id'=>1,'client_member_id'=>501,'work_date'=>'2026-10-01','hours'=>9]);
if(!DB::table('final_approval_exceptions')->where('client_member_id',501)->where('work_date','2026-10-01')->exists())throw new RuntimeException('Missing daily final exception');
$call('GET','/analytics?month=2026-10');
// Backdated gap remains visible and blocks final approval without deleting hours.
$rows[0]['valid_to']='2026-10-02';
$call('POST','/management/final-approval',['employee_id'=>1,'client_member_id'=>501,'month'=>'2026-10','approved'=>true],422);
$rows[0]['valid_to']='2026-10-31';
// Partner identity is distinct and limit spans all client memberships.
$call('PUT','/partner-hours',['client_member_id'=>502,'work_date'=>'2026-10-04','hours'=>16,'description'=>'Partner']);
if(DB::table('timesheet_entries')->where('client_member_id',502)->value('employee_id')!==null)throw new RuntimeException('Partner has fake employee ID');
$actor=200;
$call('PUT','/partner-hours',['client_member_id'=>504,'work_date'=>'2026-10-04','hours'=>9],422);
$call('PUT','/partner-hours',['client_member_id'=>502,'work_date'=>'2026-10-04','hours'=>8],422);
$actor=400;$scope='team';
$call('PUT','/partner-hours',['client_member_id'=>502,'work_date'=>'2026-10-04','hours'=>8],422);
$call('PUT','/management/entries',['employee_id'=>1,'client_member_id'=>501,'work_date'=>'2026-10-05','hours'=>8]);
$actor=300;$scope='own';
$call('POST','/partner-hours/final-approval',['client_member_id'=>502,'month'=>'2026-10','approved'=>true],422);
$call('POST','/partner-hours/confirm',['client_member_id'=>502,'work_date'=>'2026-10-04','confirmed'=>true]);
$call('POST','/partner-hours/final-approval',['client_member_id'=>502,'month'=>'2026-10','approved'=>true]);
$call('PUT','/partner-hours',['client_member_id'=>502,'work_date'=>'2026-10-04','hours'=>15]);
if(DB::table('partner_daily_confirmations')->where('client_member_id',502)->exists())throw new RuntimeException('Partner confirmation did not reset');
if(!DB::table('final_approval_exceptions')->where('client_member_id',502)->exists())throw new RuntimeException('Partner final exception absent');
$admin=true;$actor=999;
$call('GET','/partner-hours?month=2026-10');
$call('PUT','/partner-hours',['client_member_id'=>504,'work_date'=>'2026-10-06','hours'=>8]);
$permission=false;$admin=false;$actor=100;
$call('PUT','/partner-hours',['client_member_id'=>502,'work_date'=>'2026-10-04','hours'=>8],403);
$permission=true;
// Every malformed/incomplete catalogue blocks both classes of writer and preserves rows/audit.
foreach(['outage','incomplete','identity','date','delegates','overlap','lock'] as $bad){
    $broken=$bad;$count=DB::table('timesheet_audit')->count();
    $call('PUT','/partner-hours',['client_member_id'=>502,'work_date'=>'2026-10-04','hours'=>8],503);
    $actor=1;$call('PUT','/entries',['client_member_id'=>501,'work_date'=>'2026-10-04','hours'=>8],503);$actor=100;
    if(DB::table('timesheet_audit')->count()!==$count)throw new RuntimeException('Malformed catalog mutated audit');
}
$broken=null;
// Audit scopes follow the client membership, including partner events.
$actor=100;$scope='own';
DB::table('timesheet_audit')->insert(['action'=>'other_client_synthetic','actor_employee_id'=>200,'employee_id'=>1,'client_member_id'=>503,'work_date'=>'2026-10-01','created_at'=>now()]);
$events=$call('GET','/audit')['data'];
if(collect($events)->contains(fn($e)=>(int)($e['client_member_id']??0)===503)
    ||!collect($events)->contains(fn($e)=>(int)($e['client_member_id']??0)===502))throw new RuntimeException('Audit leaked another client or hid accessible partner events');
// Historical final approval can be removed after all terms were withdrawn.
$original=$rows[0];$rows[0]['member_term_id']=null;$rows[0]['valid_from']=null;$rows[0]['valid_to']=null;
$call('POST','/management/final-approval',['employee_id'=>1,'client_member_id'=>501,'month'=>'2026-10','approved'=>false]);
$call('POST','/management/final-approval',['employee_id'=>1,'client_member_id'=>501,'month'=>'2026-10','approved'=>true],422);
$rows[0]=$original;
$rows[0]['valid_to']='2026-10-02';$actor=1;
$call('POST','/confirm',['dates'=>['2026-10-03']],422);
$rows[0]=$original;$actor=100;
$call('POST','/management/final-approval',['employee_id'=>1,'client_member_id'=>501,'month'=>'2026-10','approved'=>true]);
$secret='synthetic-membership-catalog-token-0123456789';$headers=['X-Irlix-Timesheets-Token'=>$secret];
$freeze=$call('POST','/internal/system/reporting-freezes',['reporting_period_id'=>51,'client_id'=>101,'from'=>'2026-10-01','to'=>'2026-10-31'],200,$headers)['data'];
$again=$call('POST','/internal/system/reporting-freezes',['reporting_period_id'=>51,'client_id'=>101,'from'=>'2026-10-01','to'=>'2026-10-31'],200,$headers)['data'];
if($freeze!==$again)throw new RuntimeException('Freeze retries are not idempotent');
$snapshot=$call('GET','/internal/commercial-data?from=2026-10-01&to=2026-10-31&client_id=101&freeze_token='.$freeze['freeze_token'],[],200,$headers)['data'];
if($snapshot['revision']!==$freeze['revision']||!$snapshot['complete'])throw new RuntimeException('Frozen snapshot revision drift');
$call('PUT','/partner-hours',['client_member_id'=>502,'work_date'=>'2026-10-04','hours'=>8],423);
$call('POST','/partner-hours/confirm',['client_member_id'=>502,'work_date'=>'2026-10-04','confirmed'=>true],423);
$call('POST','/partner-hours/final-approval',['client_member_id'=>502,'month'=>'2026-10','approved'=>false],423);
$call('PUT','/management/entries',['employee_id'=>1,'client_member_id'=>501,'work_date'=>'2026-10-01','hours'=>8],423);
$call('PUT','/management/bulk-hours',['employee_id'=>1,'client_member_id'=>501,'dates'=>['2026-10-01'],'hours'=>8],423);
$call('POST','/management/final-approval',['employee_id'=>1,'client_member_id'=>501,'month'=>'2026-10','approved'=>false],423);
$actor=1;
$call('PUT','/entries',['client_member_id'=>501,'work_date'=>'2026-10-01','hours'=>8],423);
$call('POST','/confirm',['dates'=>['2026-10-01']],423);
$call('POST','/unconfirm',['dates'=>['2026-10-01']],423);
putenv('IRLIX_INTERNAL_PURGE_TOKEN=synthetic-purge');
$call('POST','/internal/system/employees/1/purge',[],423,['X-Irlix-Internal-Purge-Token'=>'synthetic-purge']);
$call('POST','/internal/system/employees/100/purge',[],423,['X-Irlix-Internal-Purge-Token'=>'synthetic-purge']);
$call('DELETE','/internal/system/reporting-freezes/51',['freeze_token'=>str_repeat('f',64)],409,$headers);
$call('DELETE','/internal/system/reporting-freezes/51',['freeze_token'=>$freeze['freeze_token']],200,$headers);
$call('DELETE','/internal/system/reporting-freezes/51',['freeze_token'=>$freeze['freeze_token']],200,$headers);
// Exact historical precision survives snapshot/read and description-only edit.
DB::table('timesheet_entries')->where('client_member_id',501)->where('work_date','2026-10-01')->update(['hours'=>'7.33']);
$snapshot=$call('GET','/internal/commercial-data?from=2026-10-01&to=2026-10-31&client_id=101',[],200,$headers)['data'];
$fact=collect($snapshot['entries'])->first(fn($e)=>$e['client_member_id']===501&&$e['work_date']==='2026-10-01');
if(App\Support\TimeHours::cents($fact['hours'])!==733)throw new RuntimeException('Historical precision lost');
$actor=100;
$call('PUT','/management/entries',['employee_id'=>1,'client_member_id'=>501,'work_date'=>'2026-10-01','hours'=>'7.33','description'=>'Historical precision preserved']);
$call('PUT','/management/entries',['employee_id'=>1,'client_member_id'=>501,'work_date'=>'2026-10-07','hours'=>'7.33'],422);
if(App\Support\TimeHours::cents(DB::table('timesheet_entries')->where('client_member_id',501)->where('work_date','2026-10-01')->value('hours'))!==733)throw new RuntimeException('Description edit changed exact historical hours');
echo "Timesheets actual route contracts passed: {$checks} requests, canonical members, AM/RN/delegate/admin, confirmations, conflicts, 24h, malformed catalog, freeze/revision, precision.\n";
