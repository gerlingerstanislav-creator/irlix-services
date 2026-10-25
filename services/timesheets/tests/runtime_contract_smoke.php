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
$actor=1;$scope='own';$admin=false;$permission=true;$broken=null;$locked=[];$absences=[];
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
Http::fake(function($request)use(&$actor,&$rows,&$broken,&$locked,&$scope,&$admin,&$permission,&$absences,$employees){
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
    if(str_ends_with($path,'/clients-directory'))return Http::response(['data'=>['employees'=>$employees,'departments'=>[
        ['id'=>10,'manager_id'=>400,'is_production'=>'t'],
        ['id'=>20,'manager_id'=>500,'is_production'=>'f'],
    ]]]);
    if(str_ends_with($path,'/access/me'))return Http::response(['data'=>['roles'=>$admin?['platform-admin']:[]]]);
    if(str_ends_with($path,'/calendar-absences'))return Http::response(['data'=>$absences]);
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
// A managerial entry is visible before final approval, but not counted as
// official commercial load. A later edit must count as unconfirmed, not lost.
$absences=[
    ['employee_id'=>1,'type'=>'paid_vacation','status'=>'confirmed','starts_on'=>'2026-10-05','ends_on'=>'2026-10-05'],
    ['employee_id'=>1,'type'=>'sick_leave','status'=>'confirmed','starts_on'=>'2026-10-06','ends_on'=>'2026-10-06'],
];
$admin=true; // Global scope must still exclude non-production departments.
$analytics=$call('GET','/analytics?month=2026-10')['data'];
$admin=false;$absences=[];
if(count($analytics['employees']??[])!==1
    || ($analytics['employees'][0]['employee_id']??null)!==1
    || ($analytics['totals']['employees']??null)!==2
    || count($analytics['partners']??[])!==1
    || count($analytics['departments']??[])!==2
    || (float)($analytics['totals']['norm_hours']??-1)!==352.0)
    throw new RuntimeException('Production employee filter or combined partner count failed');
if((float)($analytics['totals']['commercial_hours']??-1)!==16.0
    || (float)($analytics['totals']['unconfirmed_hours']??-1)!==9.0)
    throw new RuntimeException('Analytics final approval or unconfirmed-hours accounting failed');
if((float)($analytics['totals']['absence_hours']??-1)!==16.0
    || (float)($analytics['employees'][0]['absence_hours']??-1)!==16.0)
    throw new RuntimeException('Analytics combined absences do not sum confirmed types');
// The sent-period decision must be indexed by client, not scanned as a
// reporting-period x membership cross product for every timesheet fact.
$classifier=new \App\Services\CommercialAnalyticsClassifier();
$fact=static fn(int $id,int $memberId,string $day,bool $conflict=false):object=>(object)[
    'id'=>$id,'client_member_id'=>$memberId,'employee_id'=>$memberId===502?null:1,
    'work_date'=>$day,'hours'=>8,'term_conflict'=>$conflict,
];
[$accepted,$waiting,$conflicts]=$classifier->classify(
    collect([
        $fact(1,501,'2026-10-03'), // Approved month, no daily exception.
        $fact(2,501,'2026-10-04'), // Daily exception, but sent client period.
        $fact(3,501,'2026-10-07'), // Exception outside locked period.
        $fact(4,501,'2026-10-08',true), // Conflicts are always excluded.
        $fact(5,502,'2026-10-04'), // Partner has a different ClientMember.
    ]),
    collect([(object)['client_member_id'=>501]]),
    collect([(object)['client_member_id'=>501,'work_date'=>'2026-10-04'],
             (object)['client_member_id'=>501,'work_date'=>'2026-10-07']]),
    [['client_id'=>101,'period_start'=>'2026-10-04','period_end'=>'2026-10-04']],
    [['client_member_id'=>501,'client_id'=>101,'valid_from'=>'2026-10-01','valid_to'=>'2026-10-31'],
     ['client_member_id'=>502,'client_id'=>102,'valid_from'=>'2026-10-01','valid_to'=>'2026-10-31']],
);
if($accepted->pluck('id')->all()!==[1,2] || $waiting->pluck('id')->all()!==[3,5]
    || $conflicts->pluck('id')->all()!==[4])
    throw new RuntimeException('Analytics indexed final confirmation changed its semantics');

// Synthetic high-cardinality regression: unrelated reporting periods must not
// multiply work per entry. No wall-clock assertion (CI runners vary).
$largeFacts=collect();
for($i=1;$i<=2500;$i++)$largeFacts->push($fact(10000+$i,501,'2026-10-04'));
$unrelatedLocks=[];
for($i=1;$i<=600;$i++)$unrelatedLocks[]=[
    'client_id'=>1000+$i,'period_start'=>'2026-10-01','period_end'=>'2026-10-31',
];
[$selectedLarge,$pendingLarge]=$classifier->classify(
    $largeFacts,collect(),collect(),
    [...$unrelatedLocks,['client_id'=>101,'period_start'=>'2026-10-01','period_end'=>'2026-10-31']],
    [['client_member_id'=>501,'client_id'=>101,'valid_from'=>'2026-10-01','valid_to'=>'2026-10-31']],
);
if($selectedLarge->count()!==2500 || $pendingLarge->isNotEmpty())
    throw new RuntimeException('Large analytics reporting-period lookup failed');

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
$partnerAnalytics=$call('GET','/analytics?month=2026-10')['data'];
// Two ClientMembers of one partner must produce one identity and one norm.
$partnerRows=$partnerAnalytics['partners']??[];
$onePartner=$partnerRows[0]??[];
if(count($partnerRows)!==1
    || (int)($partnerAnalytics['partner_totals']['specialists']??-1)!==1
    || ($onePartner['client_member_ids']??[])!==[502,504]
    || (float)($partnerAnalytics['partner_totals']['norm_hours']??-1)!==176.0
    || (float)($onePartner['norm_hours']??-1)!==176.0
    || (float)($partnerAnalytics['partner_totals']['commercial_hours']??-1)!==0.0
    || (float)($partnerAnalytics['partner_totals']['unconfirmed_hours']??-1)!==23.0)
    throw new RuntimeException('Partner identity, provisional norm or pending hours incorrect');
if(($partnerAnalytics['totals']['employees']??-1)!==2
    || (float)($partnerAnalytics['totals']['norm_hours']??-1)!==352.0
    || (float)($partnerAnalytics['totals']['commercial_hours']??-1)!==
        (float)($partnerAnalytics['employees'][0]['commercial_hours']??-2)
    || count($partnerAnalytics['departments']??[])!==2
    || (float)($partnerAnalytics['totals']['idle_hours']??-1)!==
        (float)($partnerAnalytics['employees'][0]['idle_hours']??-2)+176.0)
    throw new RuntimeException('Partner was not counted once in the combined company and department totals');

// Final approval (without exception) moves partner hours from pending into
// the combined KPI; restore exception so subsequent freeze tests stay unchanged.
DB::table('final_approval_exceptions')->where('client_member_id',502)
    ->where('work_date','2026-10-04')->delete();
$approvedPartner=$call('GET','/analytics?month=2026-10')['data'];
if((float)($approvedPartner['partner_totals']['commercial_hours']??-1)!==15.0
    || (float)($approvedPartner['totals']['commercial_hours']??-1)!==
        (float)($partnerAnalytics['totals']['commercial_hours']??-1)+15.0
    || (float)($approvedPartner['totals']['idle_hours']??-1)!==
        (float)($partnerAnalytics['totals']['idle_hours']??-1)-15.0)
    throw new RuntimeException('Confirmed partner hours missing from the official combined KPI');
DB::table('final_approval_exceptions')->insert([
    'employee_id'=>null,'project_id'=>null,'client_member_id'=>502,
    'work_date'=>'2026-10-04','created_at'=>now(),'updated_at'=>now(),
]);
$august=$call('GET','/analytics?month=2026-08')['data'];
if((float)($august['employees'][0]['norm_hours']??0)!==168.0)
    throw new RuntimeException('August 2026 norm must use 21 weekdays');
$admin=false;$actor=100;$scope='own';
$accountPartnerReport=$call('GET','/analytics?month=2026-10')['data'];
if(count($accountPartnerReport['partners']??[])!==1
    || ($accountPartnerReport['partners'][0]['client_member_ids']??[])!==[502]
    || (float)($accountPartnerReport['partner_totals']['norm_hours']??-1)!==176.0)
    throw new RuntimeException('Analytics exposed a partner outside AM scope');
$actor=400;$scope='team';
$rnPartnerReport=$call('GET','/analytics?month=2026-10')['data'];
if(count($rnPartnerReport['partners']??[])!==0)
    throw new RuntimeException('RN received partner access without client delegation');
$actor=999;$admin=true;$scope='own';

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
// Finance invokes a whole-month commercial snapshot without a client filter.
// Extra historical catalog rows must not alter canonical hour ownership or
// term resolution (the production catalog can be much larger than one month).
$originalCatalog=$rows;
$rows[]=[...$rows[0],'member_term_id'=>9991,
    'valid_from'=>'2026-11-01','valid_to'=>'2026-11-30'];
for($i=0;$i<900;$i++){
    $rows[]=[
        'client_member_id'=>10000+$i,'client_id'=>10000+$i,
        'employee_id'=>20000+$i,'partner_specialist_id'=>null,
        'specialist_name'=>'Synthetic specialist','client_name'=>'Synthetic client',
        'account_employee_id'=>100,'delegated_account_employee_ids'=>[],
        'member_term_id'=>30000+$i,'valid_from'=>'2026-10-01','valid_to'=>'2026-10-31'
    ];
}
putenv('IRLIX_FINANCE_TIMESHEETS_TOKEN=synthetic-finance-token-0123456789');
$largeSnapshot=$call('GET','/internal/system/finance/commercial-data?from=2026-10-01&to=2026-10-31',[],200,
    ['X-Irlix-Finance-Timesheets-Token'=>'synthetic-finance-token-0123456789'])['data'];
putenv('IRLIX_FINANCE_TIMESHEETS_TOKEN');
$canonicalFact=collect($largeSnapshot['entries'])->first(fn($e)=>
    (int)($e['client_member_id']??0)===501 && ($e['work_date']??'')==='2026-10-01');
if(($canonicalFact['member_term_id']??null)!==71
    || ($canonicalFact['term_conflict']??null)!==false
    || ($largeSnapshot['count']??-1)!==DB::table('timesheet_entries')
        ->whereBetween('work_date',['2026-10-01','2026-10-31'])->count())
    throw new RuntimeException('Indexed terms changed full Finance snapshot ownership or precision');
$rows=$originalCatalog;
$fact=collect($snapshot['entries'])->first(fn($e)=>$e['client_member_id']===501&&$e['work_date']==='2026-10-01');
if(App\Support\TimeHours::cents($fact['hours'])!==733)throw new RuntimeException('Historical precision lost');
$actor=100;
$call('PUT','/management/entries',['employee_id'=>1,'client_member_id'=>501,'work_date'=>'2026-10-01','hours'=>'7.33','description'=>'Historical precision preserved']);
$call('PUT','/management/entries',['employee_id'=>1,'client_member_id'=>501,'work_date'=>'2026-10-07','hours'=>'7.33'],422);
if(App\Support\TimeHours::cents(DB::table('timesheet_entries')->where('client_member_id',501)->where('work_date','2026-10-01')->value('hours'))!==733)throw new RuntimeException('Description edit changed exact historical hours');
// Bounded reconciliation must cross multiple transaction chunks while
// preserving frozen periods and creating audit only for changed facts.
$baselineRows=$rows;
$baselineLocks=$locked;
$reconcileRows=[];
$ids=[];
for($i=0;$i<550;$i++){
    $memberId=20000+$i;$clientId=30000+$i;$ids[]=$memberId;
    $rows[]=[
        'client_member_id'=>$memberId,'client_id'=>$clientId,
        'employee_id'=>40000+$i,'partner_specialist_id'=>null,
        'specialist_name'=>'Synthetic worker','client_name'=>'Synthetic client',
        'account_employee_id'=>100,'delegated_account_employee_ids'=>[],
        'member_term_id'=>50000+$i,'valid_from'=>'2026-10-01','valid_to'=>'2026-10-31',
    ];
    $reconcileRows[]=[
        'employee_id'=>40000+$i,'project_id'=>null,'client_member_id'=>$memberId,
        'work_date'=>'2026-10-08','hours'=>'1.00','description'=>null,
        'term_conflict'=>true,'created_at'=>now(),'updated_at'=>now(),
    ];
}
foreach(array_chunk($reconcileRows,75) as $batch) DB::table('timesheet_entries')->insert($batch);
// An approved client period and a local finance freeze must remain untouched.
$locked[]=['id'=>995,'client_id'=>30001,'period_start'=>'2026-10-01',
    'period_end'=>'2026-10-31','status'=>'ТШ согласованы'];
DB::table('reporting_freezes')->insert([
    'reporting_period_id'=>996,'client_id'=>30002,'period_start'=>'2026-10-01',
    'period_end'=>'2026-10-31','freeze_token'=>str_repeat('b',64),
    'revision'=>'synthetic','created_at'=>now(),'updated_at'=>now(),
]);
$worker=app(\App\Support\AssignmentReconciler::class);
$worker->run();
$stillConflicted=DB::table('timesheet_entries')->whereIn('client_member_id',$ids)
    ->where('term_conflict',true)->pluck('client_member_id')->sort()->values()->all();
if($stillConflicted!==[20001,20002])throw new RuntimeException('Chunked reconciliation changed protected facts or missed chunks');
$audited=DB::table('timesheet_audit')->whereIn('client_member_id',$ids)->count();
if($audited!==548)throw new RuntimeException('Chunked reconciliation audit count incorrect');
$worker->run();
if(DB::table('timesheet_audit')->whereIn('client_member_id',$ids)->count()!==$audited)
    throw new RuntimeException('Chunked reconciliation not idempotent');
$rows=$baselineRows;$locked=$baselineLocks;
DB::table('reporting_freezes')->where('reporting_period_id',996)->delete();

echo "Timesheets actual route contracts passed: {$checks} requests, canonical members, AM/RN/delegate/admin, confirmations, conflicts, 24h, malformed catalog, freeze/revision, precision.\n";
