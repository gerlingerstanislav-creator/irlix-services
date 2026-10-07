<?php
// Real Employees adapter with synthetic read-only source and SQLite targets only.
$path = '/tmp/employees-scope-'.getmypid().'.sqlite'; touch($path);
putenv('MIGRATION_METADATA_DATABASE='.$path);
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true]);

class SyntheticEmployeeSource extends \Illuminate\Database\Connection {
    public array $fixture = [];
    public function statement($query, $bindings = []) { return true; }
    public function transaction($callback, $attempts = 1) { return $callback($this); }
    public function select($query, $bindings = [], $useReadPdo = true) {
        if (preg_match('/salar/i', $query.' '.implode(' ', $bindings))) throw new RuntimeException('Salary source must not be read or require SELECT');
        if (str_contains($query, 'current_setting')) return [(object)['db_user'=>'synthetic_reader','db_name'=>'synthetic_source','default_read_only'=>'on']];
        if (str_contains($query, 'FROM pg_roles')) return [(object)['rolsuper'=>false,'rolcreatedb'=>false,'rolcreaterole'=>false,'rolreplication'=>false,'rolbypassrls'=>false]];
        if (str_contains($query, 'database_create')) return [(object)['database_create'=>false,'schema_create'=>false]];
        if (str_contains($query, 'to_regclass')) return [(object)['relation'=>$bindings[0],'allowed'=>true]];
        if (str_contains($query, "'public', 'USAGE'")) return [(object)['allowed'=>true]];
        if (str_contains($query, 'pg_class')) return [];
        if (preg_match('/FROM public\.([a-z_]+)/', $query, $m)) {
            $rows=$this->fixture[$m[1]] ?? [];
            if ($bindings) $rows=array_values(array_filter($rows,fn($r)=>(string)$r['id']===(string)$bindings[0]));
            return str_contains($query, 'count(*)') ? [(object)['count'=>count($rows)]] : array_map(fn($r)=>(object)$r, $rows);
        }
        throw new RuntimeException('Unrecognised synthetic source query');
    }
}
function ensure(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function targetTable($target, string $name, string $columns): void {
    $target->statement('CREATE TABLE '.$name.' (id INTEGER PRIMARY KEY AUTOINCREMENT, '.implode(', ',array_map(fn($c)=>$c.' TEXT', explode(' ', $columns))).', created_at TEXT, updated_at TEXT)');
}
try {
    $source = new SyntheticEmployeeSource(new PDO('sqlite::memory:'));
    $source->fixture['departments'] = [['id'=>'synthetic-department','head_id'=>null,'hr_id'=>null,'parent_id'=>null,'title'=>'Synthetic Department','alias'=>'synthetic','yandex_id'=>null,'ldap_title'=>null,'is_production'=>true]];
    $employee = array_fill_keys(explode(' ', 'id username email name surname patronymic gender birthdate city is_remote specialization employment_type position phone skype telegram personal_email status hired_at dismissed_at department_id legal_entity yandex_id created_at updated_at'), null);
    $source->fixture['employees'] = [array_replace($employee,['id'=>'synthetic-employee','username'=>'synthetic.operator','email'=>'synthetic.operator@example.invalid','name'=>'Synthetic','surname'=>'Operator','status'=>'Трудоустроен','hired_at'=>'2026-01-01','created_at'=>'2026-01-01','department_id'=>'synthetic-department','employment_type'=>'Synthetic cooperation'])];
    $source->fixture['employments'] = [['id'=>11,'employee_id'=>'synthetic-employee','type'=>'Synthetic cooperation','start_date'=>'2026-01-01','end_date'=>null]];
    $source->fixture['employee_roles'] = [['id'=>12,'employee_id'=>'synthetic-employee','role'=>'synthetic-role']];
    \Illuminate\Support\Facades\DB::extend('synthetic_employee_source', fn()=>$source);
    config(['database.connections.synthetic_employee_source'=>['driver'=>'synthetic_employee_source'], 'migration.legacy.employees.connection'=>'synthetic_employee_source', 'migration.legacy.employees.readonly_confirmed'=>true, 'migration.legacy.employees.database'=>['host'=>'synthetic.invalid','database'=>'synthetic_source','username'=>'synthetic_reader'], 'database.connections.target_employees'=>['driver'=>'sqlite','database'=>':memory:']]);
    ensure(!in_array('salaries',config('migration.legacy.employees.required_tables'),true),'Salary grant excluded');
    $target = \Illuminate\Support\Facades\DB::connection('target_employees');
    targetTable($target,'departments','name alias parent_id manager_id hr_id yandex_id ldap_group is_production');
    targetTable($target,'employees','full_name first_name last_name middle_name gender login work_email personal_email birth_date city phone telegram skype specialization department_id position employment_status work_format cooperation_type is_remote hired_at fired_at identity_status onboarding_email_status password keycloak_user_id');
    targetTable($target,'employment_periods','employee_id cooperation_type started_at ended_at department_id position');
    $target->statement('CREATE UNIQUE INDEX one_open_period ON employment_periods(employee_id) WHERE ended_at IS NULL');
    targetTable($target,'employee_access_roles','employee_id role');
    targetTable($target,'employee_status_history','employee_id status effective_from effective_to reason');
    targetTable($target,'employment_assignment_history','employee_id department_id position effective_from effective_to');
    targetTable($target,'salary_history','employee_id gross_salary');
    $target->table('salary_history')->insert(['employee_id'=>99,'gross_salary'=>'12345.67']);
    $salaryBefore=(array)$target->table('salary_history')->first();
    $store=app(\App\Migration\Core\MigrationStore::class);
    $old=$store->beginRun('employees','migrate');
    $store->saveMapping($old,'employees','salary','old-synthetic-salary',1);
    $store->conflict($old,'employees','salary','old-synthetic-conflict','SALARY_VALUE_ENCRYPTED_OR_INVALID','Synthetic historical conflict');
    $store->finishRun($old,'conflicts',[]);
    $adapter=app(\App\Migration\Services\EmployeesMigration::class);
    foreach (['inspect','dry-run','migrate','validate'] as $mode) {
        $run=$store->beginRun('employees',$mode); \App\Migration\Core\TableProgress::$activeRun=$run;
        $result=match($mode) {'inspect'=>$adapter->inspect($run),'dry-run'=>$adapter->migrate($run,true),'migrate'=>$adapter->migrate($run,false),'validate'=>$adapter->validate($run)};
        if ($mode==='inspect') ensure(!array_key_exists('salaries',$result['counts']),'Inspect excludes salary table');
        if ($mode==='dry-run') ensure($target->table('employees')->count()===0 && !array_key_exists('salary_rows',$result),'Dry run isolates remaining data and excludes salaries');
        if ($mode==='validate') ensure($result['ok'],'Remaining entity validation passes');
        ensure(\Illuminate\Support\Facades\DB::table('migration_table_progress')->where('migration_run_id',$run)->whereIn('table_name',['salary','salaries'])->count()===0,'New report has no salary counters');
        ensure(\Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id',$run)->count()===0,'Remaining synthetic data imports without conflicts');
        $store->finishRun($run,'completed',$result);
    }
    foreach (['departments','employees','employment_periods','employee_access_roles','employee_status_history','employment_assignment_history'] as $table) ensure($target->table($table)->count()===1,'Remaining data imported: '.$table);
    ensure((array)$target->table('salary_history')->first()===$salaryBefore && $target->table('salary_history')->count()===1,'Existing salaries unchanged');
    ensure($store->mappedCount('employees','salary')===1 && \Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id',$old)->count()===1,'Historical salary mappings and conflicts preserved');
    $source->fixture['employments'][] = ['id'=>13,'employee_id'=>'synthetic-employee','type'=>'Synthetic cooperation','start_date'=>'2026-02-01','end_date'=>null];
    $run=$store->beginRun('employees','migrate');
    \App\Migration\Core\TableProgress::$activeRun=$run;
    $result=$adapter->migrate($run,false); $store->finishRun($run,'conflicts',$result);
    $conflict=\Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id',$run)->where('code','MULTIPLE_LEGACY_OPEN_PERIODS')->where('legacy_id','13')->first();
    ensure($conflict!==null,'Multiple different open source periods remain an explicit conflict');
    $recorded=json_decode($conflict->context,true);
    ensure($recorded['employee']['full_name']==='Operator Synthetic' && $recorded['employee']['login']==='synthetic.operator','Recorded identity');
    ensure($recorded['source_period']['started_at']==='2026-02-01' && $recorded['target_open_periods'][0]['started_at']==='2026-01-01','Recorded date comparison');
    \App\Migration\Core\TableProgress::$activeRun=null;
    $before=\Illuminate\Support\Facades\DB::table('migration_conflicts')->get()->toJson();
    $periodsBefore=$target->table('employment_periods')->get()->toJson();
    $live=app(\App\Migration\Core\EmploymentConflictDetails::class)->current('13');
    ensure($live['employee']===$recorded['employee'] && $live['source_period']===$recorded['source_period'],'Historical details can be read without another import');
    ensure(app(\App\Migration\Core\EmploymentConflictDetails::class)->current('999')===null,'Missing source period handled');
    ensure($before===\Illuminate\Support\Facades\DB::table('migration_conflicts')->get()->toJson() && $periodsBefore===$target->table('employment_periods')->get()->toJson(),'Diagnostic reads preserve reports and target data');
    $kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
    $roles=[];
    \Illuminate\Support\Facades\Http::fake(function() use (&$roles) { return \Illuminate\Support\Facades\Http::response(['data'=>['roles'=>$roles]],200); });
    foreach ([[null,401],['Bearer synthetic-token',403]] as [$token,$expected]) {
        $request=\Illuminate\Http\Request::create('/api/migration/console/conflicts/'.$conflict->id.'/details','GET');
        $request->headers->set('Accept','application/json'); if($token)$request->headers->set('Authorization',$token);
        $response=$kernel->handle($request); ensure($response->getStatusCode()===$expected,'Diagnostics require platform admin'); $kernel->terminate($request,$response);
    }
    $roles=['platform-admin'];
    $request=\Illuminate\Http\Request::create('/api/migration/console/conflicts/'.$conflict->id.'/details','GET');
    $request->headers->set('Accept','application/json'); $request->headers->set('Authorization','Bearer synthetic-token');
    $response=$kernel->handle($request);
    ensure($response->getStatusCode()===200 && json_decode($response->getContent(),true)['data']['basis']==='recorded','Recorded details remain accessible without ops socket');
    $kernel->terminate($request,$response);
    // Old conflicts have no snapshot context: queue unavailable must fail closed.
    \Illuminate\Support\Facades\DB::table('migration_conflicts')->where('id',$conflict->id)->update(['context'=>'{}']);
    $response=$kernel->handle($request); ensure($response->getStatusCode()===503,'Historical diagnostics fail closed when ops is unavailable'); $kernel->terminate($request,$response);
    $active=$store->queueRun('employees','inspect');
    $response=$kernel->handle($request); ensure($response->getStatusCode()===409,'Historical diagnostics blocked during active import'); $kernel->terminate($request,$response);
    $store->finishRun($active,'completed',[]);
    // A manually created employee is updated in place; identity credentials are not imported.
    $source->fixture['employments']=[$source->fixture['employments'][0]];
    $source->fixture['employments'][0]['start_date']='2025-01-01';
    $firstId=(int)$store->mapping('employees','employee','synthetic-employee');
    $firstPeriod=(int)$store->mapping('employees','employment','11');
    $target->table('employees')->where('id',$firstId)->update(['password'=>'synthetic-hash-one','keycloak_user_id'=>'synthetic-identity-one','identity_status'=>'provisioned','onboarding_email_status'=>'sent']);
    $second=array_replace($source->fixture['employees'][0],['id'=>'synthetic-employee-two','username'=>'Synthetic.Operator.Two','email'=>'synthetic.two@example.invalid','name'=>'Second','surname'=>'Operator','hired_at'=>'2020-03-01','birthdate'=>'1990-04-01','city'=>'Synthetic City','phone'=>'synthetic-phone','employment_type'=>'Synthetic legacy type']);
    $source->fixture['employees'][]=$second;
    $manualId=$target->table('employees')->insertGetId(['full_name'=>'Manual Synthetic','login'=>'synthetic.operator.two','work_email'=>$second['email'],'hired_at'=>'2026-09-01','password'=>'synthetic-existing-hash','keycloak_user_id'=>'synthetic-identity-two','identity_status'=>'provisioned','onboarding_email_status'=>'sent','created_at'=>'2026-08-01']);
    $manualPeriod=$target->table('employment_periods')->insertGetId(['employee_id'=>$manualId,'cooperation_type'=>'Synthetic manual type','started_at'=>'2026-09-01','ended_at'=>null,'position'=>'Synthetic preserved context']);
    $source->fixture['employments'][]=['id'=>14,'employee_id'=>$second['id'],'type'=>'Synthetic legacy type','start_date'=>'2020-03-01','end_date'=>null];
    $beforeEmployees=$target->table('employees')->get()->toJson(); $beforePeriods=$target->table('employment_periods')->get()->toJson();
    $run=$store->beginRun('employees','dry-run'); $preview=$adapter->migrate($run,true); $store->finishRun($run,'completed',$preview);
    ensure($preview['employees']['existing']===2 && $beforeEmployees===$target->table('employees')->get()->toJson() && $beforePeriods===$target->table('employment_periods')->get()->toJson(),'Dry run finds existing records and never updates them');
    foreach ([true,false] as $first) {
        $run=$store->beginRun('employees','migrate'); \App\Migration\Core\TableProgress::$activeRun=$run;
        $result=$adapter->migrate($run,false); $store->finishRun($run,'completed',$result);
        ensure(\Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id',$run)->count()===0,'Manual date conflicts reconciled without errors');
        ensure($result['employees']['created']===0 && $result['employees']['updated']===2 && $result['employment_periods']===2,'Existing employees and mapped periods count as success');
        ensure($result['employment_periods_updated']===($first?2:0),'Repeat import does not change equal periods');
        ensure(\Illuminate\Support\Facades\DB::table('migration_run_events')->where('migration_run_id',$run)->where('event','employment_period_updated')->count()===($first?2:0),'Changed dates audited once with before and after values');
        ensure($target->table('employees')->count()===2 && $target->table('employment_periods')->count()===2,'No duplicate employee or interval');
        ensure((int)$store->mapping('employees','employee',$second['id'])===(int)$manualId && (int)$store->mapping('employees','employment',14)===(int)$manualPeriod,'Manual employee and period IDs retained');
        $updated=$target->table('employees')->where('id',$manualId)->first();
        ensure($updated->full_name==='Operator Second' && $updated->hired_at==='2020-03-01' && $updated->city==='Synthetic City' && $updated->phone==='synthetic-phone','Legacy card fields authoritative');
        ensure($updated->password==='synthetic-existing-hash' && $updated->keycloak_user_id==='synthetic-identity-two' && $updated->identity_status==='provisioned' && $updated->onboarding_email_status==='sent' && $updated->created_at==='2026-08-01','Existing credentials and identity lifecycle unchanged');
        $period=$target->table('employment_periods')->where('id',$manualPeriod)->first();
        ensure($period->started_at==='2020-03-01' && $period->cooperation_type==='Synthetic legacy type' && $period->ended_at===null && $period->position==='Synthetic preserved context','Legacy dates and type replace manual values without unrelated period writes');
        ensure($target->table('employment_periods')->where('id',$firstPeriod)->value('started_at')==='2025-01-01','Already mapped period receives corrected legacy date');
    }
    // A simultaneous source rename must follow the mapping, not create another employee.
    $source->fixture['employees'][1]['username']='synthetic.renamed'; $source->fixture['employees'][1]['email']='synthetic.renamed@example.invalid';
    $source->fixture['employments'][1]['end_date']='2026-01-31';
    $run=$store->beginRun('employees','migrate'); \App\Migration\Core\TableProgress::$activeRun=$run; $result=$adapter->migrate($run,false); $store->finishRun($run,'completed',$result);
    ensure($target->table('employees')->count()===2 && $target->table('employees')->where('id',$manualId)->value('login')==='synthetic.renamed','Stable mapping survives a source login and email rename');
    ensure($target->table('employment_periods')->where('id',$manualPeriod)->value('ended_at')==='2026-01-31','Source closing date corrects mapped open interval');
    ensure((array)$target->table('salary_history')->first()===$salaryBefore,'Reconciliation does not change salary');
    // A closed latest source period also replaces an unmapped manual open period.
    $source->fixture['employees'][1]['id']='synthetic-closed-employee'; $source->fixture['employees'][1]['username']='synthetic.closed'; $source->fixture['employees'][1]['email']='synthetic.closed@example.invalid';
    $closedId=$target->table('employees')->insertGetId(['login'=>'synthetic.closed','work_email'=>'synthetic.closed@example.invalid']);
    $closedPeriod=$target->table('employment_periods')->insertGetId(['employee_id'=>$closedId,'cooperation_type'=>'Synthetic manual type','started_at'=>'2026-09-01','ended_at'=>null]);
    $source->fixture['employments'][1]['id']=15; $source->fixture['employments'][1]['employee_id']='synthetic-closed-employee';
    $run=$store->beginRun('employees','migrate'); \App\Migration\Core\TableProgress::$activeRun=$run; $result=$adapter->migrate($run,false); $store->finishRun($run,'completed',$result);
    ensure((int)$store->mapping('employees','employment',15)===(int)$closedPeriod && $target->table('employment_periods')->where('id',$closedPeriod)->value('ended_at')==='2026-01-31','Manual period closed with authoritative source dates');
    // A mapped identity may not be reassigned to another person's login.
    $target->table('employees')->insert(['login'=>'synthetic.occupied','work_email'=>'synthetic.occupied@example.invalid']);
    $source->fixture['employees'][1]['username']='synthetic.occupied';
    $beforeProtected=$target->table('employees')->where('id',$closedId)->first();
    $run=$store->beginRun('employees','dry-run'); $preview=$adapter->migrate($run,true); $store->finishRun($run,'conflicts',$preview);
    ensure($preview['employees']['identity_conflicts']===1 && (array)$beforeProtected===(array)$target->table('employees')->where('id',$closedId)->first(),'Identity collision remains blocked and dry run leaves target untouched');
    $target->table('employees')->insert(['login'=>'SYNTHETIC.OPERATOR','work_email'=>'synthetic.ambiguous@example.invalid']);
    $run=$store->beginRun('employees','dry-run'); $preview=$adapter->migrate($run,true); $store->finishRun($run,'conflicts',$preview);
    ensure($preview['employees']['identity_conflicts']===2,'Ambiguous case-insensitive identity is not chosen arbitrarily');
    $periodsBefore=$target->table('employment_periods')->get()->toJson();
    $store->saveMapping($run,'employees','employment',11,$manualPeriod);
    $sync=app(\App\Migration\Core\EmploymentPeriodSynchronizer::class)->sync($target,(object)$source->fixture['employments'][0],$firstId,null,true);
    ensure(($sync['error'] ?? '')==='EMPLOYMENT_MAPPING_COLLISION' && $periodsBefore===$target->table('employment_periods')->get()->toJson(),'A wrong period mapping cannot update another employee');
    echo "Employees scope passed: no salary reads/grants/writes/counters; legacy priority updates manual and mapped employees/periods, preserves identity, audits changes, blocks ambiguity and survives repeat imports.\n";
} finally { \App\Migration\Core\TableProgress::$activeRun=null; @unlink($path); }
