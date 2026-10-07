<?php
// Offline domain import integration. All identities, amounts and records are synthetic.
$path = '/tmp/schema-import-'.getmypid().'.sqlite'; touch($path);
putenv('MIGRATION_METADATA_DATABASE='.$path);
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);

class SyntheticVacations extends \App\Migration\Services\VacationsV2Migration {
    public array $fixture = [];
    protected function extract(): array { return $this->fixture; }
    protected function assertCheckpoint(): void {}
}
class SyntheticClients extends \App\Migration\Services\ClientsMigration {
    public array $fixture = [];
    protected function extract(): array { return $this->fixture; }
    protected function assertCheckpoint(): void {}
}
class SyntheticTimesheets extends \App\Migration\Services\TimesheetsMigration {
    public array $fixture = [];
    protected function extract(): array { return $this->fixture; }
    protected function assertCheckpoint(): void {}
}
function verify(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function db(string $service) { return \Illuminate\Support\Facades\DB::connection('target_'.$service); }
function table(string $service, string $name, string $columns, array $unique = []): void {
    $definitions = ['id INTEGER PRIMARY KEY AUTOINCREMENT'];
    foreach (explode(' ', $columns.' created_at updated_at') as $column) $definitions[] = '"'.$column.'" TEXT';
    if ($unique) $definitions[] = 'UNIQUE ('.implode(',', $unique).')';
    db($service)->statement('CREATE TABLE '.$name.' ('.implode(',', $definitions).')');
}
function runImport($module, bool $dry): array {
    $store = app(\App\Migration\Core\MigrationStore::class);
    $run = $store->beginRun($module->key(), $dry ? 'dry-run' : 'migrate');
    $result = $module->migrate($run, $dry);
    $store->finishRun($run, $result['conflicts'] ? 'conflicts' : 'completed', $result);
    return $result;
}
$failure = null;
try {
    foreach (['employees','vacations','clients','timesheets'] as $service) {
        config(['database.connections.target_'.$service => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        \Illuminate\Support\Facades\DB::purge('target_'.$service);
    }
    table('employees','employees','login full_name');
    db('employees')->table('employees')->insert(['id'=>1,'login'=>'synthetic.operator','full_name'=>'Synthetic Operator']);
    $store = app(\App\Migration\Core\MigrationStore::class);
    $seedRun = $store->beginRun('employees','migrate');
    $store->saveMapping($seedRun, 'employees', 'employee', 'synthetic-employee-uuid', 1);
    $store->finishRun($seedRun,'completed');
    foreach ([
        'clients'=>'name description type sector sales_employee_id account_employee_id act_approval_days payment_days',
        'projects'=>'client_id name is_default',
        'project_members'=>'project_id specialist_id specialist_name',
        'member_terms'=>'project_member_id technology level hourly_rate hours_per_day valid_from valid_to',
        'leads'=>'name source responsible_employee_id status converted_client_id',
        'contact_people'=>'full_name',
        'contact_relations'=>'contact_person_id entity_type entity_id relation_role active',
        'client_legal_entities'=>'client_id name full_name inn ogrn kpp registration_date okpo oktmo address',
        'client_requests'=>'client_id title description responsible_employee_id request_date deadline lifetime_weeks status',
        'positions'=>'client_request_id technology level description direction_department_id quantity status',
        'connection_attempts'=>'position_id specialist_id specialist_name is_external status description cv_sent_at connection_date closed_at control_date proposed_rate',
        'reporting_periods'=>'client_id period_start period_end status confirmed_hours timesheets_sent_at timesheets_approved_at act_sent_at act_approved_at paid_at',
    ] as $name=>$columns) table('clients',$name,$columns);
    table('vacations','absences','employee_id type starts_on ends_on calendar_days status created_by_subject');
    table('vacations','absence_audit_log','absence_id event actor_subject after');
    table('vacations','absence_status_history','absence_id to_status actor_subject reason context');
    table('timesheets','timesheet_entries','employee_id client_id project_id account_employee_id work_date hours description', ['employee_id','project_id','work_date']);

    $clients = new SyntheticClients($store);
    $clients->fixture = array_fill_keys(config('migration.legacy.clients.required_tables'), []);
    $clients->fixture['users'] = [['id'=>13,'email'=>'synthetic.operator@example.invalid','name'=>'Synthetic Operator','external_key'=>'synthetic-employee-uuid']];
    $clients->fixture['clients'] = [['id'=>6,'title'=>'Synthetic Client','description'=>null,'type'=>null,'sector_id'=>null,'sales_manager_id'=>13,'account_manager_id'=>13,'approving_term'=>7,'payment_term'=>7]];
    $clients->fixture['projects'] = [['id'=>7,'client_id'=>6,'title'=>'Synthetic Project']];
    $clients->fixture['members'] = [['id'=>31,'memberable_type'=>'employee','memberable_id'=>13,'project_id'=>7,'client_id'=>6]];
    $clients->fixture['technologies'] = [['id'=>5,'title'=>'Synthetic Technology']];
    $clients->fixture['rates'] = [['id'=>41,'member_id'=>31,'technology_id'=>5,'grade'=>'Synthetic level','rate'=>'1500.50','workload'=>8,'start_date'=>'2026-01-01','end_date'=>'2026-01-31']];
    $clients->fixture['contacts'] = [['id'=>2,'name'=>'Synthetic Contact','contacts'=>'[]']];
    $clients->fixture['client_requests'] = [['id'=>10,'client_id'=>6,'responsible_id'=>13,'title'=>'Synthetic request','description'=>null,'date'=>'2026-01-01','expired_at'=>'2026-01-15','closed_at'=>null]];
    $clients->fixture['positions'] = [['id'=>11,'client_request_id'=>10,'technology_id'=>5,'department_id'=>null,'grade'=>'Synthetic level','description'=>null,'count'=>1,'closed_at'=>null,'status'=>'open']];
    $clients->fixture['attempts'] = [['id'=>12,'position_id'=>11,'user_id'=>13,'user_name'=>null,'result_comment'=>null,'closed_at'=>null,'cv_sent_at'=>null,'interviewed_at'=>null,'started_at'=>null,'control_date'=>null,'rate'=>null]];
    $dry = runImport($clients, true);
    verify($dry['conflicts']===0, 'Clients dry run resolves virtual references');
    verify(db('clients')->table('clients')->count()===0 && $store->mappedCount('clients','clients')===0, 'Dry run writes no target data or mappings');
    verify(runImport($clients,false)['conflicts']===0,'Clients imports core records');
    verify((float) db('clients')->table('member_terms')->value('hours_per_day')===8.0,'workload is daily hours');
    verify(runImport($clients,false)['conflicts']===0 && db('clients')->table('project_members')->count()===1,'Repeat import is idempotent');
    verify($clients->validate(1)['ok'],'Clients reconciliation validates current source, IDs and target fields');
    db('clients')->table('clients')->update(['name'=>'Modified target']);
    verify(!$clients->validate(1)['ok'],'Reconciliation detects modified target fields');
    runImport($clients,false);
    $clients->fixture['rates'][0]['rate'] = 'encrypted:synthetic';
    verify(runImport($clients,true)['conflicts']>0,'Encrypted rate cannot be imported');
    $clients->fixture['clients'][0]['title']='Synthetic changed before invalid rate';
    try { runImport($clients,false); throw new LogicException('Unsafe actual import accepted'); }
    catch (RuntimeException $e) { verify(str_contains($e->getMessage(),'Import blocked'),'Full preflight blocks direct actual import'); }
    verify(db('clients')->table('clients')->value('name')==='Synthetic Client','No earlier target writes when a later source row is unresolved');
    $clients->fixture['clients'][0]['title']='Synthetic Client';
    $clients->fixture['rates'][0]['rate']='1500.50';
    $clients->fixture['rates'][]=$clients->fixture['rates'][0];$clients->fixture['rates'][1]['id']=42;
    verify(runImport($clients,true)['conflicts']===1,'Overlapping rate periods cannot import');
    array_pop($clients->fixture['rates']);

    $vacations = new SyntheticVacations($store);
    $vacations->fixture = array_fill_keys(config('migration.legacy.vacations.required_tables'), []);
    $vacations->fixture['users'] = [['id'=>4,'employee_id'=>'synthetic-employee-uuid','email'=>'synthetic.operator@example.invalid']];
    $vacations->fixture['vacations'] = [['id'=>8,'user_id'=>4,'type'=>'paid_vacation','status'=>'confirmed','from'=>'2026-01-10','to'=>'2026-01-12','working_hours'=>8]];
    $vacations->fixture['approvers'] = [['id'=>9,'vacation_id'=>8,'employee_id'=>'synthetic-employee-uuid','order'=>1,'is_approved'=>true]];
    verify(runImport($vacations,true)['conflicts']===0 && db('vacations')->table('absences')->count()===0,'Vacations v2 dry run is read-only');
    $result = runImport($vacations,false);
    verify($result['conflicts']===0 && $result['warnings']===1,'Approvals preserved without invented stages');
    verify((int) db('vacations')->table('absences')->value('calendar_days')===3,'Inclusive date range');
    runImport($vacations,false);
    verify(db('vacations')->table('absences')->count()===1 && db('vacations')->table('absence_audit_log')->count()===1,'Absence and audit idempotency');
    foreach (['paid'=>'paid_vacation','unpaid'=>'unpaid_vacation','maternity'=>'maternity_leave'] as $legacy=>$target) {
        $vacations->fixture['vacations'][0]['type']=$legacy;
        verify(runImport($vacations,true)['conflicts']===0, 'Legacy absence alias passes preflight: '.$legacy);
        verify(runImport($vacations,false)['conflicts']===0, 'Legacy absence alias imports: '.$legacy);
        verify(db('vacations')->table('absences')->value('type')===$target, 'Alias maps to target type: '.$target);
    }
    $vacations->fixture['vacations'][0]['type']='sick';
    foreach (['granted'=>'confirmed','not_approved'=>'rejected','approvers_definition'=>'planned','on_approval'=>'planned'] as $legacy=>$target) {
        $vacations->fixture['vacations'][0]['status']=$legacy;
        verify(runImport($vacations,true)['conflicts']===0, 'Legacy status alias preflight: '.$legacy);
        verify(runImport($vacations,false)['conflicts']===0, 'Legacy status imports: '.$legacy);
        $absence=db('vacations')->table('absences')->first();
        verify($absence->type==='sick_leave' && $absence->status===$target, 'Stored type and status correspond');
    }
    $vacations->fixture['vacations'][0]['status']='confirmed';
    $vacations->fixture['vacations'][0]['type']='unknown-synthetic-type';
    verify(runImport($vacations,true)['conflicts']===1, 'Unknown type remains a conflict');
    $vacations->fixture['vacations'][0]['type']='paid';
    $vacations->fixture['vacations'][0]['status']='unknown-synthetic-status';
    verify(runImport($vacations,true)['conflicts']===1,'Unknown absence status is a conflict');
    $vacations->fixture['vacations'][0]['status']='confirmed';
    $vacations->fixture['vacations'][0]['to']='2026-01-01';
    verify(runImport($vacations,true)['conflicts']===1,'Reversed absence range is a conflict');

    $vacations->fixture['vacations'][0]['to']='2026-01-12';
    $vacations->fixture['users'][0]['email']='synthetic.missing@example.invalid';
    verify(runImport($vacations,true)['conflicts']===2, 'Missing employee also blocks dependent absence');
    $lastRun=$store->latestRun('vacations','dry-run');
    $error=\Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id',$lastRun['id'])->where('entity_type','users')->first();
    verify(str_contains($error->message,'не найден'), 'Missing login is distinguished from ambiguity');
    verify(json_decode($error->context,true)['source']['email']==='synthetic.missing@example.invalid', 'Employee identity saved with error');
    \Illuminate\Support\Facades\DB::table('migration_overrides')->insert(['service'=>'vacations','entity_type'=>'users','legacy_id'=>'4','target_id'=>'1','note'=>json_encode(['source_fingerprint'=>\App\Migration\Core\EmployeeUserOverrides::fingerprint($vacations->fixture['users'][0])]),'created_at'=>now(),'updated_at'=>now()]);
    verify(runImport($vacations,true)['conflicts']===0, 'Explicit user identity resolves dependent absence during preflight');
    verify(runImport($vacations,false)['conflicts']===0 && db('vacations')->table('absences')->count()===1, 'Actual alias import is idempotent and does not create an employee');
    \Illuminate\Support\Facades\DB::table('migration_overrides')->where('service','vacations')->delete();
    db('employees')->table('employees')->insert([['id'=>2,'login'=>'synthetic.operator'],['id'=>3,'login'=>'synthetic.operator']]);
    $vacations->fixture['users'][0]['email']='synthetic.operator@example.invalid';
    verify(runImport($vacations,true)['conflicts']===2, 'Ambiguous employee blocks dependent absence');
    $lastRun=$store->latestRun('vacations','dry-run');
    $error=\Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id',$lastRun['id'])->where('entity_type','users')->first();
    verify(str_contains($error->message,'3 сотрудников') && str_contains($error->message,'ID: 1, 2, 3'), 'Ambiguous login lists target IDs');
    db('employees')->table('employees')->whereIn('id',[2,3])->delete();

    $timesheets = new SyntheticTimesheets($store);
    $timesheets->fixture = array_fill_keys(config('migration.legacy.timesheets.required_tables'), []);
    $timesheets->fixture['users'] = [['id'=>17,'employee_id'=>'synthetic-employee-uuid','email'=>'synthetic.operator@example.invalid']];
    $timesheets->fixture['members'] = [['id'=>831,'employee_id'=>'synthetic-employee-uuid','memberable_type'=>'employee','project'=>'Synthetic Project']];
    $timesheets->fixture['rates'] = [['id'=>91,'member_id'=>831,'from'=>'2026-01-01','to'=>'2026-01-31','workload'=>8]];
    $timesheets->fixture['timesheets'] = [['id'=>101,'rate_id'=>91,'date'=>'2026-01-05','hours'=>7.5,'description'=>'Synthetic task','is_submitted'=>true]];
    $timesheets->fixture['month_confirmations'] = [['id'=>111,'member_id'=>831,'month'=>'2026-01','is_confirmed'=>true,'is_account_confirmed'=>true]];
    verify(runImport($timesheets,true)['conflicts']===0 && db('timesheets')->table('timesheet_entries')->count()===0,'Timesheets dry run validates Clients connection without writes');
    verify(runImport($timesheets,false)['conflicts']===0,'Timesheets import succeeds');
    verify((float) db('timesheets')->table('timesheet_entries')->value('hours')===7.5,'Hours transferred without conversion');
    runImport($timesheets,false);
    verify(db('timesheets')->table('timesheet_entries')->count()===1 && $timesheets->validate(1)['ok'],'Timesheets rerun and reconciliation');
    $timesheets->fixture['members'][0]['project']='Unknown synthetic descriptor';
    verify(runImport($timesheets,true)['conflicts']>0,'Unknown project cannot use coincidental numeric member ID');
    \Illuminate\Support\Facades\DB::table('migration_overrides')->insert(['service'=>'timesheets','entity_type'=>'members','legacy_id'=>'831','target_id'=>$store->mapping('clients','members',31),'created_at'=>now(),'updated_at'=>now()]);
    verify(runImport($timesheets,true)['conflicts']===0,'Explicit connection override resolves descriptor without changing employee identity');
    \Illuminate\Support\Facades\DB::table('migration_overrides')->where('service','timesheets')->delete();
    $timesheets->fixture['members'][0]['project']='Synthetic Project';
    db('timesheets')->table('timesheet_entries')->insert(['employee_id'=>1,'client_id'=>1,'project_id'=>999,'work_date'=>'2026-01-05','hours'=>20]);
    verify(runImport($timesheets,true)['conflicts']===1,'Total daily hours include existing other projects');
    db('timesheets')->table('timesheet_entries')->where('project_id',999)->delete();
    $timesheets->fixture['timesheets'][0]['date']='2026-02-01';
    verify(runImport($timesheets,true)['conflicts']===1,'Date outside connection is refused');
    $timesheets->fixture['timesheets'][0]['date']='2026-01-05';
    $timesheets->fixture['timesheets'][]=$timesheets->fixture['timesheets'][0]; $timesheets->fixture['timesheets'][1]['id']=102;
    verify(runImport($timesheets,true)['conflicts']===1,'Duplicate daily entries cannot silently overwrite');
    db('employees')->table('employees')->insert(['id'=>2,'login'=>'SYNTHETIC.OPERATOR']);
    verify(runImport($timesheets,true)['conflicts']>=1,'Ambiguous login cannot fall back to stale mappings');
    echo "Schema import integration passed: vacations v2, Clients, Timesheets, dry-run isolation, idempotency, reconciliation and conflicts.\n";
} catch (Throwable $e) { $failure=$e; }
finally { @unlink($path); @unlink($path.'-wal'); @unlink($path.'-shm'); }
if ($failure) { fwrite(STDERR, $failure->__toString()."\n"); exit(1); }
