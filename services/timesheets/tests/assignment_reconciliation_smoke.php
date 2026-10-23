<?php
// Synthetic test: retroactive gaps never destroy previously saved time.
require getenv('TIMESHEETS_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
spl_autoload_register(function(string $class):void {
    if(str_starts_with($class,'App\\')){
        $path=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,4)).'.php';
        if(is_file($path)) require $path;
    }
});
use App\Support\AssignmentReconciler;
use App\Support\ClientMemberDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;

$app=new Illuminate\Foundation\Application(sys_get_temp_dir().'/timesheets-client-member-smoke');
$app->instance('request',Request::create('http://localhost/'));
$app->instance('config',new Illuminate\Config\Repository([
    'app'=>['locale'=>'en','fallback_locale'=>'en'],
    'database'=>['default'=>'sqlite','connections'=>['sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]],
    'view'=>['paths'=>[],'compiled'=>sys_get_temp_dir()],
]));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
foreach([Illuminate\Events\EventServiceProvider::class,Illuminate\Routing\RoutingServiceProvider::class,
    Illuminate\Filesystem\FilesystemServiceProvider::class,Illuminate\Database\DatabaseServiceProvider::class,
    Illuminate\View\ViewServiceProvider::class,Illuminate\Translation\TranslationServiceProvider::class] as $provider)
    $app->register($provider);
foreach(['2026_09_27_000001_create_timesheets.php','2026_10_08_000003_final_approval_exceptions.php',
    '2026_10_22_000004_normalize_daily_facts.php','2026_10_23_000005_client_member_expansion.php',
    '2026_10_23_000006_reporting_freezes.php'] as $name)
    (require dirname(__DIR__).'/database/migrations/'.$name)->up();
putenv('IRLIX_TIMESHEETS_INTEGRATION_TOKEN=synthetic-membership-catalog-token-0123456789');
$rows=[
 ['client_member_id'=>501,'client_id'=>101,'employee_id'=>1,'partner_specialist_id'=>null,
  'specialist_name'=>'Employee A','client_name'=>'Synthetic Client','account_employee_id'=>100,
  'delegated_account_employee_ids'=>[],'member_term_id'=>71,'valid_from'=>'2026-10-01','valid_to'=>'2026-10-15'],
 ['client_member_id'=>502,'client_id'=>102,'employee_id'=>null,'partner_specialist_id'=>5,
  'specialist_name'=>'Partner Person','client_name'=>'Other Client','account_employee_id'=>200,
  'delegated_account_employee_ids'=>[],'member_term_id'=>72,'valid_from'=>'2026-10-01','valid_to'=>'2026-10-31'],
];
Http::fake(function($request) use (&$rows) {
    if($request->url()!=='http://clients:8000/internal/system/timesheet-memberships') return Http::response([],404);
    return Http::response(['complete'=>true,'revision'=>'synthetic-revision','count'=>count($rows),'data'=>$rows,
        'locked_reporting_periods_complete'=>true,'locked_reporting_periods_count'=>0,'locked_reporting_periods'=>[]]);
});
DB::table('timesheet_entries')->insert([
 ['employee_id'=>1,'project_id'=>null,'client_member_id'=>501,'work_date'=>'2026-10-10','hours'=>8,'description'=>'OK','term_conflict'=>false,'created_at'=>now(),'updated_at'=>now()],
 ['employee_id'=>1,'project_id'=>null,'client_member_id'=>501,'work_date'=>'2026-10-20','hours'=>7.33,'description'=>'Outside','term_conflict'=>false,'created_at'=>now(),'updated_at'=>now()],
 ['employee_id'=>null,'project_id'=>null,'client_member_id'=>502,'work_date'=>'2026-10-20','hours'=>6,'description'=>'Partner','term_conflict'=>false,'created_at'=>now(),'updated_at'=>now()],
]);
$reconciler=new AssignmentReconciler(app(ClientMemberDirectory::class));
$reconciler->run();
if(DB::table('timesheet_entries')->count()!==3)throw new RuntimeException('Reconciler erased saved facts');
if(!DB::table('timesheet_entries')->where('client_member_id',501)->where('work_date','2026-10-20')->value('term_conflict'))
    throw new RuntimeException('Missing flag for backdated gap');
if(DB::table('timesheet_entries')->where('client_member_id',502)->value('term_conflict'))
    throw new RuntimeException('Partner valid term was misclassified');
if(DB::table('timesheet_audit')->count()!==1)throw new RuntimeException('Expected one conflict transition');
$reconciler->run();
if(DB::table('timesheet_audit')->count()!==1)throw new RuntimeException('Reconciler is not idempotent');
$rows[0]['valid_to']='2026-10-31';
$reconciler->run();
if(DB::table('timesheet_entries')->where('term_conflict',true)->exists())
    throw new RuntimeException('Resolved gap remained blocked');
if(DB::table('timesheet_audit')->count()!==2)throw new RuntimeException('Resolution transition not audited');
echo "ClientMember reconciliation passed: preserve hours, flag conflicts, include partners, idempotence, resolution.\n";

// Contract regression: normal employee endpoints MUST read ClientMember terms,
// never the old project-based TimesheetAssignments directory.
require dirname(__DIR__).'/routes/api.php';
$request=Request::create('http://localhost/api/timesheets/workspace?month=2026-10');
$resolved=$assignments($request);
if(count($resolved)!==1 || $resolved[0]['client_member_id']!==501 ||
    $resolved[0]['client_id']!==101 || $resolved[0]['employee_id']!==1 ||
    $resolved[0]['valid_from']!=='2026-10-01' ||
    count($request->attributes->get('locked_reporting_periods',[]))!==0) {
    throw new RuntimeException('Employee directory must resolve canonical ClientMember, not project');
}
$rows[]=['client_member_id'=>503,'client_id'=>103,'employee_id'=>1,'partner_specialist_id'=>null,
    'specialist_name'=>'No terms','client_name'=>'No terms client',
    'account_employee_id'=>100,'delegated_account_employee_ids'=>[],
    'member_term_id'=>null,'valid_from'=>null,'valid_to'=>null];
$resolved=$assignments($request);
if(count($resolved)!==1)
    throw new RuntimeException('ClientMember without terms must not grant writable time');
echo "Employee Timesheets assignment contract passed: ClientMember, no project, partner exclusion, no-term exclusion.\n";
