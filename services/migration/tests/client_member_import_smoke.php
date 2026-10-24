<?php
use Illuminate\Support\Facades\DB;
// Canonical ClientMember migration smoke: all records synthetic.
$path=tempnam(sys_get_temp_dir(),'client-member-import-');
putenv('MIGRATION_METADATA_DATABASE='.$path);
putenv('MIGRATION_IDENTITY_DATABASE='.$path.'.identity');
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);

class SyntheticClientMemberTimesheets extends \App\Migration\Services\TimesheetsMigration {
    public array $fixture=[];
    protected function extract(): array {return $this->fixture;}
    protected function deferredSourceTables(): array {return [];}
    protected function sourceBatches(string $table): iterable {
        foreach(parent::sourceBatches($table) as $batch){
            if($table==='timesheets'){foreach(array_chunk($batch,1) as $page)yield $page;}else yield $batch;
        }
    }
    protected function assertCheckpoint(): void {}
}
$pdo=['driver'=>'sqlite','database'=>':memory:','prefix'=>''];
foreach(['employees','clients','timesheets'] as $name){
    config(['database.connections.target_'.$name=>$pdo]);
    DB::purge('target_'.$name);
}
DB::connection('target_employees')->statement("CREATE TABLE employees (id INTEGER PRIMARY KEY, login TEXT, full_name TEXT)");
DB::connection('target_employees')->table('employees')->insert(['id'=>10,'login'=>'synthetic.staff','full_name'=>'Synthetic Staff']);
DB::connection('target_clients')->statement("CREATE TABLE clients (id INTEGER PRIMARY KEY,name TEXT,account_employee_id INTEGER,sales_employee_id INTEGER)");
DB::connection('target_clients')->table('clients')->insert([['id'=>91,'name'=>'Synthetic client'],['id'=>92,'name'=>'Synthetic partner client']]);
DB::connection('target_clients')->statement("CREATE TABLE projects (id INTEGER PRIMARY KEY,client_id INTEGER,name TEXT)");
DB::connection('target_clients')->table('projects')->insert([['id'=>81,'client_id'=>91,'name'=>'A'],['id'=>82,'client_id'=>91,'name'=>'B']]);
DB::connection('target_clients')->table('clients')->insert(['id'=>94,'name'=>'  Source   Label  ']);
DB::connection('target_clients')->table('clients')->insert([
    ['id'=>95,'name'=>'Duplicate Project','account_employee_id'=>10,'sales_employee_id'=>20],
    ['id'=>96,'name'=>'Duplicate Project','account_employee_id'=>11,'sales_employee_id'=>21],
]);
DB::connection('target_clients')->table('projects')->insert(['id'=>83,'client_id'=>91,'name'=>'  Source   Project  ']);
if(\App\Migration\Core\TimesheetsClientMemberMatching::sourceClientIds('source label')!==[94]
    || \App\Migration\Core\TimesheetsClientMemberMatching::sourceClientIds('SOURCE PROJECT')!==[91]
    || \App\Migration\Core\TimesheetsClientMemberMatching::sourceClientIds('source project typo')!==[])
    throw new RuntimeException('Client label proof must fold whitespace but reject fuzzy matches');
DB::connection('target_clients')->statement("CREATE TABLE client_members (id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL, employee_id INTEGER, partner_specialist_id INTEGER)");
DB::connection('target_clients')->table('client_members')->insert([
    ['id'=>501,'client_id'=>91,'employee_id'=>10,'partner_specialist_id'=>null],
    ['id'=>502,'client_id'=>92,'employee_id'=>null,'partner_specialist_id'=>15],
]);
DB::connection('target_clients')->statement("CREATE TABLE member_terms (id INTEGER PRIMARY KEY, client_member_id INTEGER, valid_from TEXT, valid_to TEXT, hours_per_day DECIMAL(5,2))");
DB::connection('target_clients')->table('member_terms')->insert([
    ['id'=>601,'client_member_id'=>501,'valid_from'=>'2026-01-01','valid_to'=>'2026-01-31','hours_per_day'=>8],
    ['id'=>602,'client_member_id'=>502,'valid_from'=>'2026-01-01','valid_to'=>'2026-01-31','hours_per_day'=>8],
]);
DB::connection('target_timesheets')->statement("CREATE TABLE timesheet_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, project_id INTEGER, client_member_id INTEGER, work_date TEXT, hours DECIMAL(5,2), description TEXT, term_conflict INTEGER, created_at TEXT, updated_at TEXT, UNIQUE(client_member_id,work_date))");
$store=app(\App\Migration\Core\MigrationStore::class);
$run=$store->beginRun('employees','migrate');
$store->saveMapping($run,'employees','employee','synthetic-staff-uuid',10);
$matchedClientIds=\App\Migration\Core\TimesheetsClientMemberMatching::sourceClientIdsForMember(
    ['project'=>'Duplicate Project','account_manager_id'=>'synthetic-staff-uuid'],$store);
if($matchedClientIds!==[95])throw new RuntimeException('Manager UUID failed to prove unique client');
$ambiguousClientIds=\App\Migration\Core\TimesheetsClientMemberMatching::sourceClientIdsForMember(
    ['project'=>'Duplicate Project','account_manager_id'=>'unmatched-uuid'],$store);
sort($ambiguousClientIds);
if($ambiguousClientIds!==[95,96])
    throw new RuntimeException('Unverified UUID collapsed ambiguous client labels');
$store->finishRun($run,'completed');
DB::table('migration_overrides')->insert([
    'service'=>'timesheets','entity_type'=>'members','legacy_id'=>'102','target_id'=>'502',
    'note'=>'Explicitly verified external contractor mapping','created_at'=>now(),'updated_at'=>now(),
]);
$module=new SyntheticClientMemberTimesheets($store);
$exactHours=new ReflectionMethod(\App\Migration\Services\TimesheetsMigration::class,'v3Cents');
foreach(['7.33'=>733,'7.3300'=>733,'1e1'=>1000,'0.00'=>0,'24'=>2400] as $input=>$expected)
    if($exactHours->invoke($module,$input)!==$expected)throw new RuntimeException('Exact decimal parser drift');
foreach(['7.33000000001','0.000000001','1e-100'] as $inexact){
    $blocked=false;try{$exactHours->invoke($module,$inexact);}catch(\App\Migration\Core\SourceRowConflict $e){$blocked=$e->reason==='INVALID_HOUR_PRECISION';}
    if(!$blocked)throw new RuntimeException('Parser silently rounded '.$inexact);
}

// Owner-authorized automation never authorizes a guessed cross-service person.
$pick=\App\Migration\Core\PartnerAutoMatch::choose(...);
$matching=['name_exact'=>true,'client_label_exact'=>true,'period_matches'=>true,
    'partner_specialist_id'=>15,'target_id'=>502];
if($pick([$matching],true)!==502)throw new RuntimeException('Unique verified partner not selected');
if($pick([array_replace($matching,['period_matches'=>false])],true)!==502)
    throw new RuntimeException('Exact partner/client match incorrectly depends on commercial term date');
$tokenOrder=array_replace($matching,['name_exact'=>false,'name_tokens_exact'=>true]);
if($pick([$tokenOrder],true)!==502)
    throw new RuntimeException('Unambiguous name-token permutation not selected');
if($pick([$matching,$tokenOrder],true)!==null)
    throw new RuntimeException('Competing exact and permuted partner matches accepted');
if($pick([array_replace($matching,['name_exact'=>false,'name_tokens_exact'=>false])],true)!==null)
    throw new RuntimeException('Missing identity proof accepted');
foreach([
    [[$matching],false],
    [[$matching,array_replace($matching,['target_id'=>503])],true],
    [[array_replace($matching,['name_exact'=>false])],true],
    [[array_replace($matching,['client_label_exact'=>false])],true],
    // Historic fact dates outside linked rate/terms do not invalidate person/client proof.
    [[array_replace($matching,['partner_specialist_id'=>null])],true],
    [[],true],
] as [$candidates,$hasDates]){
    if($pick($candidates,$hasDates)!==null)
        throw new RuntimeException('Ambiguous or unsupported partner auto-match accepted');
}

// Employee auto-matching requires a proven UUID, one actual membership,
// dated source hours and an exact single term-coverage candidate.
$employeePick=\App\Migration\Core\EmployeeAutoMatch::choose(...);
$employeeMembership=[['target_id'=>501,'employee_id'=>10]];
$dated=[['target_id'=>501,'period_matches'=>true]];
if($employeePick($employeeMembership,$dated,true)!==501)
    throw new RuntimeException('Single verified employee membership was not selected');
$twoMemberships=[$employeeMembership[0],['target_id'=>503,'employee_id'=>10]];
if($employeePick($twoMemberships,$dated,true)!==501)
    throw new RuntimeException('Unique dated membership among multiple client connections was not selected');
if($employeePick($twoMemberships,[$dated[0],['target_id'=>503,'period_matches'=>true]],true)!==null)
    throw new RuntimeException('Competing date-compatible client connections were silently assigned');
foreach([
    [[], $dated, true],
    [[$employeeMembership[0],['target_id'=>503,'employee_id'=>10]], [
        ['target_id'=>501,'period_matches'=>true],
        ['target_id'=>503,'period_matches'=>true]], true],
    [$employeeMembership, $dated, false],
    [$employeeMembership, [['target_id'=>501,'period_matches'=>false]], true],
    [$employeeMembership, [['target_id'=>503,'period_matches'=>true]], true],
] as [$memberships,$candidateTerms,$hasDates]) {
    if($employeePick($memberships,$candidateTerms,$hasDates)!==null)
        throw new RuntimeException('Unproved employee-client auto-match accepted');
}

// Empty historical rates never prove a commercial period for a person.
$emptyRateEvidence=\App\Migration\Core\TimesheetsMemberMatching::resolveByPeriods(
    [['id'=>777,'from'=>'2026-01-01','to'=>'2026-01-31','fact_dates'=>[]]],
    [['target_id'=>501,'terms'=>[
        ['id'=>601,'valid_from'=>'2026-01-01','valid_to'=>'2026-01-31'],
    ]]]);
if($emptyRateEvidence['periods_usable'] ||
   ($emptyRateEvidence['candidates'][0]['period_matches']??true))
    throw new RuntimeException('Empty historical partner facts passed as period evidence');

// Cross-service client IDs are never joined by equal old integers.
// An ambiguous project label can be narrowed only by a corporate UUID
// independently mapped to the target client account/sales manager.
$clientPick=\App\Migration\Core\TimesheetsClientResolver::unique(...);
$clientManagers=[
    91=>['account'=>10,'sales'=>20],
    92=>['account'=>11,'sales'=>21],
];
if($clientPick([91,92],10,20,$clientManagers)!==91)
    throw new RuntimeException('Independent manager UUID evidence not accepted');
if($clientPick([91,92],10,null,$clientManagers)!==91 ||
   $clientPick([91,92],null,21,$clientManagers)!==92)
    throw new RuntimeException('Unique single-manager evidence not accepted');
foreach([
    [[91,92],null,null,$clientManagers],
    [[91,92],10,21,$clientManagers],
    [[91,92],10,99,$clientManagers],
    [[91,92],99,20,$clientManagers],
    [[],10,20,$clientManagers],
] as [$candidates,$account,$sales,$managers])
    if($clientPick($candidates,$account,$sales,$managers)!==null)
        throw new RuntimeException('Unproven or conflicting account/sales identity accepted');

// The original Timesheets FK is timesheets.rate_id -> rates.id, not
// an interval FK; out-of-term hours must still be migrated without edits.
$historicalStage=new \App\Migration\Core\TimesheetsFactStage();
$historicalRate=['client_member_id'=>501,'employee_id'=>10,'partner_specialist_id'=>null,'client_id'=>91];
$historicalStage->add('historic-1',['hours'=>'2.00','rate_id'=>201],$historicalRate,'2025-12-27',null,200);
$historicalGroups=iterator_to_array($historicalStage->groups());
if(count($historicalGroups)!==1 ||
   $historicalGroups[0]['term_conflict']!==true ||
   (int)$historicalGroups[0]['cents']!==200)
    throw new RuntimeException('Historical fact outside rate/term dates was discarded or marked billable');
unset($historicalStage);

// Two commercial term versions on the same member/day must not be silently merged.
$termStage=new \App\Migration\Core\TimesheetsFactStage();
$termRate=['client_member_id'=>501,'employee_id'=>10,'partner_specialist_id'=>null,'client_id'=>91];
$termStage->add('term-a',['hours'=>'1.00'],$termRate,'2026-01-05',601,100);
$termStage->add('term-b',['hours'=>'1.00'],$termRate,'2026-01-05',602,100);
$termConflict=false;
try { iterator_to_array($termStage->groups()); }
catch (\App\Migration\Core\SourceRowConflict $e) { $termConflict=$e->reason==='FACT_GROUP_TERM_CONFLICT'; }
if (!$termConflict) throw new RuntimeException('Different commercial terms were silently merged');
unset($termStage);

$module->fixture=[
    'users'=>[['id'=>1,'email'=>'synthetic.staff@example.invalid','employee_id'=>'synthetic-staff-uuid']],
    'members'=>[
        ['id'=>100,'memberable_type'=>'employee','employee_id'=>'synthetic-staff-uuid','project'=>'A'],
        ['id'=>101,'memberable_type'=>'employee','employee_id'=>'synthetic-staff-uuid','project'=>'B'],
        ['id'=>102,'memberable_type'=>'subcontract','employee_id'=>null,'project'=>'C'],
    ],
    'rates'=>[
        ['id'=>201,'member_id'=>100,'from'=>'2026-01-01','to'=>'2026-01-31','workload'=>8],
        ['id'=>202,'member_id'=>101,'from'=>'2026-01-01','to'=>'2026-01-31','workload'=>8],
        // Deliberately stale legacy rate header: actual January fact is
        // covered by a genuine January ClientMember term and must not be lost.
        ['id'=>203,'member_id'=>102,'from'=>'2025-12-01','to'=>'2025-12-31','workload'=>8],
    ],
    'timesheets'=>[
        ['id'=>1001,'rate_id'=>201,'date'=>'2026-01-05','hours'=>'3.00','description'=>'Task A','is_submitted'=>true],
        ['id'=>1002,'rate_id'=>202,'date'=>'2026-01-05','hours'=>'4.33','description'=>'Task B'],
        ['id'=>1003,'rate_id'=>203,'date'=>'2026-01-05','hours'=>'5.00','description'=>'Partner C'],
    ],
    'month_confirmations'=>[],'time_records'=>[],'vacations'=>[],'business_dates'=>[],'departments'=>[],
];
$oldMemberEvidence=['source'=>$module->fixture['members'][0],'resolution'=>['version'=>2],'target_model'=>'ProjectMember'];
$oldRateEvidence=['source'=>$module->fixture['rates'][0],'resolution'=>['version'=>2],'target_model'=>'ProjectMemberTerms'];
$store->saveMapping($run,'timesheets','members','100',88,$oldMemberEvidence);
$store->saveMapping($run,'timesheets','rates','201',89,$oldRateEvidence);
$dryRun=$store->beginRun('timesheets','dry-run');
$preview=$module->migrate($dryRun,true);
if(($preview['conflicts']??-1)!==0 || DB::connection('target_timesheets')->table('timesheet_entries')->exists())
    throw new RuntimeException('Canonical dry run must not write and must resolve all identities: '.json_encode($preview));
if(($preview['warnings']??0)<1)throw new RuntimeException('Legacy is_submitted must be preserved as lineage without inferred daily confirmation');
$store->finishRun($dryRun,'completed',$preview);
$writeRun=$store->beginRun('timesheets','migrate');
$write=$module->migrate($writeRun,false);
if(($write['conflicts']??-1)!==0)throw new RuntimeException('Canonical write conflicts: '.json_encode($write));
$store->finishRun($writeRun,'completed',$write);
$rows=DB::connection('target_timesheets')->table('timesheet_entries')->orderBy('client_member_id')->get();
if($rows->count()!==2)throw new RuntimeException('Multiple projects must merge to one ClientMember fact');
if((int)$rows[0]->client_member_id!==501||abs((float)$rows[0]->hours-7.33)>0.00001)
    throw new RuntimeException('Merged hours are not exactly 7.33');
if(!str_contains((string)$rows[0]->description,'Task A')||!str_contains((string)$rows[0]->description,'Task B'))
    throw new RuntimeException('Descriptions were dropped');
if((int)$rows[1]->client_member_id!==502||$rows[1]->employee_id!==null)
    throw new RuntimeException('Partner hours must not impersonate Employees');
foreach([1001,1002,1003] as $sourceId)
    if($store->mapping('timesheets','timesheets',(string)$sourceId)===null)
        throw new RuntimeException('Missing source-to-target lineage #'.$sourceId);
function check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function runCanonical(SyntheticClientMemberTimesheets $module,bool $dry=true): array {
    $store=app(\App\Migration\Core\MigrationStore::class);
    $id=$store->beginRun('timesheets',$dry?'dry-run':'migrate');
    try {$result=$module->migrate($id,$dry);$store->finishRun($id,'completed',$result);return $result;}
    catch(\Throwable $e){$store->finishRun($id,'failed');throw $e;}
}
function blocked(SyntheticClientMemberTimesheets $module,string $code): void {
    $blocked=false;
    try {$r=runCanonical($module);$blocked=($r['conflicts']??0)>0;}
    catch(\App\Migration\Core\SourceRowConflict $e){$blocked=$e->reason===$code;}
    check($blocked,'Expected canonical block '.$code);
}
check($module->validate($store->beginRun('timesheets','validate'))['ok'],'Canonical aliases must validate');
check(runCanonical($module,false)['conflicts']===0,'Idempotent retry failed');
foreach(['members'=>['100',88,$oldMemberEvidence],'rates'=>['201',89,$oldRateEvidence]] as $entity=>[$sourceId,$oldTarget,$evidence]){
    $metadata=json_decode(DB::table('migration_mappings')->where(['service'=>'timesheets','entity_type'=>$entity,'legacy_id'=>$sourceId])->value('metadata'),true);
    check(($metadata['previous_mapping']??null)==['target_id'=>(string)$oldTarget,'version'=>2,'metadata'=>$evidence],'V2→V3 retry erased prior '.$entity.' mapping');
}
check(DB::connection('target_timesheets')->table('timesheet_entries')->count()===2,'Retry duplicated facts');
// A crash after canonical save but before all alias metadata is recoverable.
DB::table('migration_mappings')->where(['service'=>'timesheets','entity_type'=>'timesheets','legacy_id'=>'1002'])->delete();
check(runCanonical($module,false)['conflicts']===0,'Partial alias metadata retry failed');
check($store->mapping('timesheets','timesheets','1001')===$store->mapping('timesheets','timesheets','1002'),'Partial retry lost alias');
// Neither target edits nor source drift may be silently overwritten on a retry.
$targetId=(int)$store->mapping('timesheets','timesheets','1001');
DB::connection('target_timesheets')->table('timesheet_entries')->where('id',$targetId)->update(['description'=>'Manual correction']);
blocked($module,'FACT_TARGET_CHANGED');
DB::connection('target_timesheets')->table('timesheet_entries')->where('id',$targetId)->update(['description'=>$rows[0]->description]);
$fixture=$module->fixture;
$module->fixture['timesheets'][0]['hours']='3.01';
blocked($module,'FACT_SOURCE_GROUP_CHANGED');$module->fixture=$fixture;
$module->fixture['month_confirmations']=[['id'=>999,'member_id'=>100,'month'=>'2026-01','is_confirmed'=>true,'is_account_confirmed'=>true]];
blocked($module,'LEGACY_FINAL_CONFIRMATION_UNRESOLVED');$module->fixture=$fixture;
foreach(['3.001','0.0000000001','7.33000000001'] as $inexact){
    $module->fixture['timesheets'][0]['hours']=$inexact;
    blocked($module,'INVALID_HOUR_PRECISION');$module->fixture=$fixture;
}
// Owner-approved exclusion is explicit, auditable, and not a fabricated
// internal-activity classification. It does not replace existing client hours.
$module->fixture['timesheets'][]=[
    'id'=>1004,'rate_id'=>null,'date'=>'2026-01-05',
    'hours'=>'2.50','description'=>'Unclassified source activity','is_submitted'=>false,
];
$excluded=runCanonical($module);
check(($excluded['conflicts']??-1)===0,'Excluded rate-less fact blocked unrelated canonical import');
check(($excluded['excluded_unclassified_rate_null']??0)===1,'Rate-less exclusion was not counted');
check(($excluded['warnings']??0)>=1,'Excluded source was not recorded as a warning');
check($store->mapping('timesheets','timesheets','1004')===null,'Excluded source acquired a target mapping');
$excludedValidate=$module->validate($store->beginRun('timesheets','validate'));
check($excludedValidate['ok'],'Validation must reconcile scoped exclusion separately');
check(($excludedValidate['provenance']['excluded_rate_null']??0)===1
    && ($excludedValidate['provenance']['excluded_rate_null_hours']??null)==='2.50',
    'Excluded source count and exact hours were not reconciled');
$module->fixture=$fixture;
// Stale mappings outside this inventory still count against daily limits.
DB::connection('target_clients')->table('client_members')->insert(['id'=>503,'client_id'=>93,'employee_id'=>10]);
$external=DB::connection('target_timesheets')->table('timesheet_entries')->insertGetId(['client_member_id'=>503,'employee_id'=>10,'project_id'=>null,'work_date'=>'2026-01-05','hours'=>20,'description'=>'Existing other client']);
$store->saveMapping($writeRun,'timesheets','timesheets','absent-source',$external);
blocked($module,'TARGET_DAY_OVER_24');
DB::connection('target_timesheets')->table('timesheet_entries')->where('id',$external)->delete();
DB::table('migration_mappings')->where(['service'=>'timesheets','entity_type'=>'timesheets','legacy_id'=>'absent-source'])->delete();
// A legacy project target cannot be counted a second time as a canonical fact.
$old=DB::connection('target_timesheets')->table('timesheet_entries')->insertGetId(['client_member_id'=>null,'employee_id'=>10,'project_id'=>88,'work_date'=>'2026-01-05','hours'=>1]);
blocked($module,'LEGACY_TARGET_FACT_REQUIRES_RECONCILIATION');
DB::connection('target_timesheets')->table('timesheet_entries')->where('id',$old)->delete();
// Independent client ID namespaces: equal numeric IDs are not evidence.
$mappingRun=$store->beginRun('clients','migrate');$store->saveMapping($mappingRun,'clients','clients','777',91);
$module->fixture['members'][0]['client_id']=777;
$module->fixture['members'][0]['project']='Unknown source client';
blocked($module,'MEMBER_IDENTITY_UNRESOLVED');$module->fixture=$fixture;
DB::connection('target_clients')->table('client_members')->where('id',503)->delete();
// Old v2 fact IDs cannot masquerade as v3 IDs, even when numeric IDs coincide.
$key=['service'=>'timesheets','entity_type'=>'timesheets','legacy_id'=>'1001'];
$meta=DB::table('migration_mappings')->where($key)->value('metadata');$oldMeta=json_decode($meta,true);$oldMeta['resolution']['version']=2;
DB::table('migration_mappings')->where($key)->update(['metadata'=>json_encode($oldMeta)]);
blocked($module,'FACT_MAPPING_SCHEMA_VERSION_MISMATCH');
DB::table('migration_mappings')->where($key)->update(['metadata'=>$meta]);
check(runCanonical($module)['conflicts']===0,'Restored mapping should pass preflight');
$module->fixture['timesheets']=array_values(array_filter($fixture['timesheets'],fn($row)=>$row['id']!==1002));
check(!$module->validate($store->beginRun('timesheets','validate'))['ok'],'Deleted alias source must fail reconciliation');
$module->fixture=$fixture;
echo "Canonical ClientMember import: exact merge, partner, aliases, retries, partial metadata restore, approval ambiguity, target/source drift, namespace, old target double count guards PASS\n";
