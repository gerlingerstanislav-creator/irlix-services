<?php
use Illuminate\Support\Facades\DB;
// Canonical ClientMember migration smoke: all records synthetic.
$path='/tmp/client-member-import-'.getmypid().'.sqlite';
touch($path);
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
    protected function assertCheckpoint(): void {}
}
$pdo=['driver'=>'sqlite','database'=>':memory:','prefix'=>''];
foreach(['employees','clients','timesheets'] as $name){
    config(['database.connections.target_'.$name=>$pdo]);
    DB::purge('target_'.$name);
}
DB::connection('target_employees')->statement("CREATE TABLE employees (id INTEGER PRIMARY KEY, login TEXT, full_name TEXT)");
DB::connection('target_employees')->table('employees')->insert(['id'=>10,'login'=>'synthetic.staff','full_name'=>'Synthetic Staff']);
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
$store->finishRun($run,'completed');
DB::table('migration_overrides')->insert([
    'service'=>'timesheets','entity_type'=>'members','legacy_id'=>'102','target_id'=>'502',
    'note'=>'Explicitly verified external contractor mapping','created_at'=>now(),'updated_at'=>now(),
]);
$module=new SyntheticClientMemberTimesheets($store);
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
        ['id'=>203,'member_id'=>102,'from'=>'2026-01-01','to'=>'2026-01-31','workload'=>8],
    ],
    'timesheets'=>[
        ['id'=>1001,'rate_id'=>201,'date'=>'2026-01-05','hours'=>'3.00','description'=>'Task A'],
        ['id'=>1002,'rate_id'=>202,'date'=>'2026-01-05','hours'=>'4.33','description'=>'Task B'],
        ['id'=>1003,'rate_id'=>203,'date'=>'2026-01-05','hours'=>'5.00','description'=>'Partner C'],
    ],
    'month_confirmations'=>[],'time_records'=>[],'vacations'=>[],'business_dates'=>[],'departments'=>[],
];
$dryRun=$store->beginRun('timesheets','dry-run');
$preview=$module->migrate($dryRun,true);
if(($preview['conflicts']??-1)!==0 || DB::connection('target_timesheets')->table('timesheet_entries')->exists())
    throw new RuntimeException('Canonical dry run must not write and must resolve all identities: '.json_encode($preview));
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
echo "Canonical ClientMember import: employee merge, partner hours, descriptions, provenance, dry-run PASS\n";
