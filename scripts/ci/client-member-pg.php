<?php
// Executed inside disposable CI containers against PostgreSQL 16.
require '/app/vendor/autoload.php';
$app=require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function cmCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
function cmReject(callable $action,string $message):void {
    try {DB::transaction($action);} catch(Illuminate\Database\QueryException $e) {cmCheck(in_array($e->getCode(),['23514','23503','23505']),$message.' SQLSTATE '.$e->getCode());return;}
    throw new RuntimeException('Accepted invalid write: '.$message);
}
function cmOldMigrate():void {
    foreach(glob('/app/database/migrations/*.php') as $path)
        if(basename($path)<'2026_10_23_') Artisan::call('migrate',['--path'=>$path,'--realpath'=>true,'--force'=>true]);
}
function cmFixture():array {return json_decode(file_get_contents('/evidence/fixture.json'),true,512,JSON_THROW_ON_ERROR);}

$mode=$argv[1]??'';
if($mode==='clients') {
    cmOldMigrate();
    $client=DB::table('clients')->insertGetId(['name'=>'Synthetic upgrade fixture','account_employee_id'=>424243,'created_at'=>now(),'updated_at'=>now()]);
    $projects=[];$terms=[];
    foreach([1,2] as $i) {
        $project=DB::table('projects')->insertGetId(['client_id'=>$client,'name'=>'Synthetic project '.$i,'is_default'=>false,'created_at'=>now(),'updated_at'=>now()]);
        $projects[]=$project;
        $member=DB::table('project_members')->insertGetId(['project_id'=>$project,'specialist_id'=>424242,'specialist_name'=>'Synthetic employee','created_at'=>now(),'updated_at'=>now()]);
        $terms[]=DB::table('member_terms')->insertGetId(['project_member_id'=>$member,'technology'=>'PHP','level'=>'Senior','hourly_rate'=>'100.00','hours_per_day'=>'8.00','valid_from'=>'2026-01-01','valid_to'=>'2026-12-31','created_at'=>now(),'updated_at'=>now()]);
        DB::table('project_member_feedbacks')->insert(['project_member_id'=>$member,'text'=>'Synthetic original feedback '.$i,'created_at'=>now(),'updated_at'=>now()]);
    }
    $before=DB::table('member_terms')->whereIn('id',$terms)->orderBy('id')->get()->toArray();
    Artisan::call('migrate',['--force'=>true]);
    $member=DB::table('client_members')->where('client_id',$client)->where('employee_id',424242)->sole();
    cmCheck(DB::table('client_members')->where('client_id',$client)->count()===1,'one membership across two legacy projects');
    cmCheck(DB::table('member_terms')->whereIn('id',$terms)->count()===2,'both historical term IDs retained');
    cmCheck(DB::table('member_terms')->where('client_member_id',$member->id)->count()===1,'compatible overlap coalesced');
    cmCheck(DB::table('member_term_lineage')->whereIn('source_term_id',$terms)->count()===2,'original terms have lineage');
    foreach($before as $original) {
        $payload=json_decode(DB::table('member_term_lineage')->where('source_term_id',$original->id)->value('original_payload'),true);
        cmCheck((string)$payload['hourly_rate']===(string)$original->hourly_rate && $payload['valid_from']===$original->valid_from,'exact original term retained');
    }
    cmCheck(DB::table('project_assignments')->where('client_member_id',$member->id)->count()===0,'no invented project dates');
    cmCheck(DB::table('client_member_feedbacks')->where('client_member_id',$member->id)->count()===2,'both historical feedbacks retained');
    cmReject(fn()=>DB::table('client_members')->insert(['client_id'=>$client,'employee_id'=>null,'partner_specialist_id'=>null,'specialist_name'=>'Invalid']),'identity XOR');
    cmReject(fn()=>DB::table('client_members')->insert(['client_id'=>$client,'employee_id'=>424242,'specialist_name'=>'Duplicate']),'unique client/person membership');
    cmReject(fn()=>DB::table('member_terms')->insert(['client_member_id'=>$member->id,'technology'=>'PHP','level'=>'Senior','hourly_rate'=>200,'hours_per_day'=>8,'valid_from'=>'2026-10-01']),'overlap rejected');
    cmReject(fn()=>DB::table('member_terms')->insert(['client_member_id'=>$member->id,'technology'=>'PHP','level'=>'Senior','hourly_rate'=>200,'hours_per_day'=>8,'valid_from'=>'2027-02-01','valid_to'=>'2027-01-31']),'inverted commercial interval rejected');
    cmReject(fn()=>DB::table('member_terms')->where('id',$terms[0])->update(['client_member_id'=>null,'superseded_by_term_id'=>null]),'commercial term must retain canonical owner or lineage');
    cmReject(fn()=>DB::table('project_members')->where('id',$before[0]->project_member_id)->delete(),'historical terms restrict deletion');
    $assignment=['client_member_id'=>$member->id,'project_id'=>$projects[0],'valid_from'=>'2026-01-01','valid_to'=>'2026-03-31'];
    DB::table('project_assignments')->insert($assignment);
    cmReject(fn()=>DB::table('project_assignments')->insert(array_replace($assignment,['valid_from'=>'2026-03-31','valid_to'=>'2026-04-30'])),'inclusive assignment overlap rejected');
    cmReject(fn()=>DB::table('project_assignments')->insert(array_replace($assignment,['valid_from'=>'2026-06-01','valid_to'=>'2026-05-31'])),'inverted assignment interval rejected');
    DB::table('project_assignments')->insert(array_replace($assignment,['valid_from'=>'2026-04-01','valid_to'=>'2026-04-30']));
    DB::table('project_assignments')->insert(array_replace($assignment,['project_id'=>$projects[1]]));
    cmCheck(DB::table('project_assignments')->where('client_member_id',$member->id)->count()===3,'parallel projects and non-overlapping return accepted');
    $otherClient=DB::table('clients')->insertGetId(['name'=>'Synthetic other client','created_at'=>now(),'updated_at'=>now()]);
    $otherProject=DB::table('projects')->insertGetId(['client_id'=>$otherClient,'name'=>'Synthetic foreign project','created_at'=>now(),'updated_at'=>now()]);
    cmReject(fn()=>DB::table('project_assignments')->insert(array_replace($assignment,['project_id'=>$otherProject])),'assignment cannot cross clients');
    file_put_contents('/evidence/fixture.json',json_encode(['client'=>$client,'projects'=>$projects,'member'=>$member->id]));
    exit;
}
if($mode==='timesheets') {
    cmOldMigrate();$fixture=cmFixture();
    foreach($fixture['projects'] as $i=>$project) {
        DB::table('timesheet_entries')->insert(['employee_id'=>424242,'project_id'=>$project,'work_date'=>'2026-10-10','hours'=>$i===0?'3.33':'4.00','description'=>'Original description '.$i,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('final_approvals')->insert(['employee_id'=>424242,'project_id'=>$project,'month'=>'2026-10-01','approved_by'=>424243,'approved_at'=>'2026-10-20 10:00:00','created_at'=>now(),'updated_at'=>now()]);
    }
    DB::table('employee_confirmations')->insert(['employee_id'=>424242,'work_date'=>'2026-10-10','confirmed_at'=>'2026-10-20 09:00:00','created_at'=>now(),'updated_at'=>now()]);
    Artisan::call('migrate',['--force'=>true]);
    cmCheck(DB::table('timesheet_entries')->count()===2 && App\Support\TimeHours::cents(DB::table('timesheet_entries')->sum('hours'))===733,'upgrade preserves 7.33 legacy hours');
    cmCheck(DB::table('final_approvals')->count()===2 && DB::table('employee_confirmations')->count()===1,'upgrade preserves original confirmations');
    cmReject(fn()=>DB::table('timesheet_entries')->insert(['employee_id'=>424242,'project_id'=>null,'client_member_id'=>null,'work_date'=>'2026-10-11','hours'=>1]),'fact source XOR');
    cmReject(fn()=>DB::table('timesheet_entries')->insert(['employee_id'=>424242,'project_id'=>$fixture['projects'][0],'client_member_id'=>$fixture['member'],'work_date'=>'2026-10-11','hours'=>1]),'fact cannot have project and membership together');
    exit;
}
if($mode==='convert') {
    $fixture=cmFixture();$converter=new App\Support\ProjectHoursConverter();
    $plan=$converter->run();cmCheck($plan['conflict_count']===0 && $plan['target_groups']===1,'dry-run reads live Clients catalog');
    putenv('TIMESHEETS_MAINTENANCE_LOCK=1');$crash=true;
    DB::listen(function($query)use(&$crash){if($crash && str_starts_with($query->sql,'insert into "final_approvals"'))throw new RuntimeException('Synthetic conversion interruption');});
    try {$converter->run(true);throw new LogicException('Interruption did not fire');}
    catch(RuntimeException $e) {if($e->getMessage()!=='Synthetic conversion interruption')throw $e;}
    cmCheck(DB::table('timesheet_entries')->whereNotNull('project_id')->count()===2 && !DB::table('timesheet_legacy_sources')->exists(),'interrupted conversion rolls back facts and lineage');
    $crash=false;$result=$converter->run(true);$entry=DB::table('timesheet_entries')->where('client_member_id',$fixture['member'])->sole();
    cmCheck(App\Support\TimeHours::cents($entry->hours)===733 && str_contains($entry->description,'Original description 0') && str_contains($entry->description,'Original description 1'),'conversion exact hours and all descriptions');
    cmCheck(DB::table('timesheet_legacy_sources')->count()===2 && DB::table('final_approvals')->where('client_member_id',$fixture['member'])->count()===1,'conversion lineage and final approval');
    $audit=DB::table('timesheet_audit')->count();$converter->run(true);
    cmCheck(DB::table('timesheet_audit')->count()===$audit,'conversion retry idempotent');
    $base='http://127.0.0.1:18082/internal/system/reporting-freezes';
    $payload=['reporting_period_id'=>424244,'client_id'=>$fixture['client'],'from'=>'2026-10-01','to'=>'2026-10-31'];
    cmCheck(Http::post($base,$payload)->status()===403,'live freeze rejects missing token');
    cmCheck(Http::withHeaders(['X-Irlix-Timesheets-Token'=>'wrong'])->post($base,$payload)->status()===403,'live freeze rejects wrong token');
    $headers=['X-Irlix-Timesheets-Token'=>getenv('IRLIX_TIMESHEETS_INTEGRATION_TOKEN')];
    $freeze=Http::withHeaders($headers)->post($base,$payload);cmCheck($freeze->successful(),'live authorized freeze HTTP '.$freeze->status());
    $data=$freeze->json('data');$again=Http::withHeaders($headers)->post($base,$payload)->json('data');
    cmCheck($data===$again,'live freeze retry stable revision/token');
    try {DB::transaction(fn()=>App\Support\ReportingFreeze::assertEditable($fixture['client'],'2026-10-10'));throw new LogicException('Freeze write allowed');}
    catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {cmCheck($e->getStatusCode()===423,'persisted freeze blocks writer');}
    cmCheck(Http::withHeaders($headers)->delete($base.'/424244',['freeze_token'=>str_repeat('f',64)])->status()===409,'live freeze wrong-token release rejected');
    cmCheck(Http::withHeaders($headers)->delete($base.'/424244',['freeze_token'=>$data['freeze_token']])->successful(),'live freeze safely released');
    exit;
}
if($mode==='migration') {
    Artisan::call('migrate',['--force'=>true]);$fixture=cmFixture();
    foreach(['clients','timesheets','employees'] as $name) {
        config(['database.connections.target_'.$name=>['driver'=>'pgsql','host'=>'127.0.0.1','port'=>5432,
            'database'=>'membership_ci','username'=>'postgres','password'=>'synthetic_ci_password',
            'charset'=>'utf8','prefix'=>'','search_path'=>$name,'sslmode'=>'disable']]);
        DB::purge('target_'.$name);
    }
    $employees=DB::connection('target_employees');$employees->statement('CREATE SCHEMA IF NOT EXISTS employees');
    $employees->statement('CREATE TABLE employees.employees (id bigint primary key,login text,full_name text)');
    $employees->table('employees')->insert(['id'=>424242,'login'=>'synthetic.staff','full_name'=>'Synthetic employee']);
    $clients=DB::connection('target_clients');
    $partner=$clients->table('partner_specialists')->insertGetId(['full_name'=>'Synthetic imported partner','created_at'=>now(),'updated_at'=>now()]);
    $partnerMember=$clients->table('client_members')->insertGetId(['client_id'=>$fixture['client'],'employee_id'=>null,'partner_specialist_id'=>$partner,'specialist_name'=>'Synthetic imported partner','created_at'=>now(),'updated_at'=>now()]);
    $clients->table('member_terms')->insert(['client_member_id'=>$partnerMember,'technology'=>'PHP','level'=>'Senior','hourly_rate'=>'100.00','hours_per_day'=>8,'valid_from'=>'2026-01-01','valid_to'=>'2026-12-31','created_at'=>now(),'updated_at'=>now()]);
    $store=app(App\Migration\Core\MigrationStore::class);$employeeRun=$store->beginRun('employees','migrate');
    $store->saveMapping($employeeRun,'employees','employee','synthetic-pg-uuid',424242);$store->finishRun($employeeRun,'completed');
    foreach([100=>$fixture['member'],101=>$fixture['member'],102=>$partnerMember] as $legacy=>$target)
        DB::table('migration_overrides')->insert(['service'=>'timesheets','entity_type'=>'members','legacy_id'=>(string)$legacy,'target_id'=>(string)$target,'note'=>'Synthetic verified mapping','created_at'=>now(),'updated_at'=>now()]);
    $module=new class($store) extends App\Migration\Services\TimesheetsMigration {
        public array $fixture=[];
        protected function extract():array {return $this->fixture;}
        protected function deferredSourceTables():array {return [];}
        protected function assertCheckpoint():void {}
    };
    $module->fixture=[
        'users'=>[['id'=>1,'email'=>'synthetic.staff@example.invalid','employee_id'=>'synthetic-pg-uuid']],
        'members'=>[['id'=>100,'memberable_type'=>'employee','employee_id'=>'synthetic-pg-uuid','project'=>'Synthetic project 1'],['id'=>101,'memberable_type'=>'employee','employee_id'=>'synthetic-pg-uuid','project'=>'Synthetic project 2'],['id'=>102,'memberable_type'=>'subcontract','employee_id'=>null,'project'=>'Synthetic project 1']],
        'rates'=>[['id'=>201,'member_id'=>100,'from'=>'2026-01-01','to'=>'2026-12-31','workload'=>8],['id'=>202,'member_id'=>101,'from'=>'2026-01-01','to'=>'2026-12-31','workload'=>8],['id'=>203,'member_id'=>102,'from'=>'2026-01-01','to'=>'2026-12-31','workload'=>8]],
        'timesheets'=>[['id'=>1001,'rate_id'=>201,'date'=>'2026-10-15','hours'=>'3.33','description'=>'Imported A'],['id'=>1002,'rate_id'=>202,'date'=>'2026-10-15','hours'=>'4.00','description'=>'Imported B'],['id'=>1003,'rate_id'=>203,'date'=>'2026-10-15','hours'=>'5.00','description'=>'Imported partner']],
        'month_confirmations'=>[],'time_records'=>[],'vacations'=>[],'business_dates'=>[],'departments'=>[]];
    $target=DB::connection('target_timesheets');$before=$target->table('timesheet_entries')->count();
    foreach([true,false,false] as $dry) {
        $run=$store->beginRun('timesheets',$dry?'dry-run':'migrate');$result=$module->migrate($run,$dry);
        cmCheck(($result['conflicts']??-1)===0,'PostgreSQL target '.($dry?'dry-run':'migrate/retry').' conflict-free');
        $store->finishRun($run,'completed',$result);
        cmCheck($target->table('timesheet_entries')->count()===($dry?$before:$before+2),'PostgreSQL target dry-run/idempotent row count');
    }
    $employee=$target->table('timesheet_entries')->where('client_member_id',$fixture['member'])->where('work_date','2026-10-15')->sole();
    $external=$target->table('timesheet_entries')->where('client_member_id',$partnerMember)->sole();
    cmCheck((string)$employee->hours==='7.33' && str_contains($employee->description,'Imported A') && str_contains($employee->description,'Imported B'),'PG import precision/descriptions');
    cmCheck($external->employee_id===null && (string)$external->hours==='5.00','PG partner import preserves identity');
    cmCheck($store->mapping('timesheets','timesheets','1001')===$store->mapping('timesheets','timesheets','1002'),'PG import source alias lineage');
    exit;
}
throw new RuntimeException('Unknown CI mode');
