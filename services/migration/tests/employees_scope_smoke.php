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
    targetTable($target,'employees','full_name first_name last_name middle_name gender login work_email personal_email birth_date city phone telegram skype specialization department_id position employment_status work_format cooperation_type is_remote hired_at fired_at identity_status onboarding_email_status');
    targetTable($target,'employment_periods','employee_id cooperation_type started_at ended_at department_id position');
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
    echo "Employees scope passed: no salary reads/grants/writes/counters; remaining entities import and historical reports survive.\n";
} finally { \App\Migration\Core\TableProgress::$activeRun=null; @unlink($path); }
