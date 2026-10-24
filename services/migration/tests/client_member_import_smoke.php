<?php
// Synthetic cross-service import: no legacy server or production credentials.
$path = '/tmp/client-member-import-'.getmypid().'.sqlite';
touch($path);
putenv('MIGRATION_METADATA_DATABASE='.$path);
putenv('MIGRATION_IDENTITY_DATABASE='.$path.'.identity');
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true]);

class MemberClientsFixture extends \App\Migration\Services\ClientsMigration {
    public array $fixture = [];
    protected function extract(): array { return $this->fixture; }
    protected function assertCheckpoint(): void {}
}
class MemberTimesheetsFixture extends \App\Migration\Services\TimesheetsMigration {
    public array $fixture = [];
    protected function extract(): array { return $this->fixture; }
    protected function deferredSourceTables(): array { return []; }
    protected function assertCheckpoint(): void {}
}
// Exercise the production validation path where aliases cross 1,000-row pages.
class MemberPagedTimesheetsFixture extends MemberTimesheetsFixture {
    protected function deferredSourceTables(): array { return ['timesheets']; }
    protected function sourceBatches(string $table): iterable {
        foreach (array_chunk($this->source[$table] ?? [], 1000) as $batch) yield $batch;
    }
}
function check(bool $ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); }
function target(string $service) { return \Illuminate\Support\Facades\DB::connection('target_'.$service); }
function entity(string $service, string $name, string $columns, ?string $unique = null): void {
    $fields = ['id INTEGER PRIMARY KEY AUTOINCREMENT'];
    foreach (explode(' ', $columns.' created_at updated_at') as $column) $fields[] = '"'.$column.'" TEXT';
    if ($unique) $fields[] = 'UNIQUE ('.$unique.')';
    target($service)->statement('CREATE TABLE '.$name.' ('.implode(',', $fields).')');
}
function runFixture(object $module, bool $dry): array {
    $store = app(\App\Migration\Core\MigrationStore::class);
    $id = $store->beginRun($module->key(), $dry ? 'dry-run' : 'migrate');
    $result = $module->migrate($id, $dry);
    $store->finishRun($id, $result['conflicts'] ? 'conflicts' : 'completed', $result);
    return $result;
}
$failure = null;
try {
    foreach (['employees','clients','timesheets'] as $service) {
        config(['database.connections.target_'.$service => ['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]]);
        \Illuminate\Support\Facades\DB::purge('target_'.$service);
    }
    entity('employees','employees','login full_name');
    target('employees')->table('employees')->insert(['id'=>1,'login'=>'synthetic.operator','full_name'=>'Synthetic Operator']);
    $store = app(\App\Migration\Core\MigrationStore::class);
    $seed = $store->beginRun('employees','migrate');
    $store->saveMapping($seed,'employees','employee','synthetic-uuid',1);
    $store->finishRun($seed,'completed');
    foreach ([
        'clients'=>'name description type sector sales_employee_id account_employee_id act_approval_days payment_days',
        'projects'=>'client_id name is_default',
        'partner_specialists'=>'full_name',
        'client_members'=>'client_id employee_id partner_specialist_id specialist_name',
        'member_terms'=>'project_member_id client_member_id technology level hourly_rate hours_per_day valid_from valid_to',
        'leads'=>'name source responsible_employee_id status converted_client_id',
        'contact_people'=>'full_name',
        'contact_relations'=>'contact_person_id entity_type entity_id relation_role active',
        'client_legal_entities'=>'client_id name full_name inn ogrn kpp registration_date okpo oktmo address',
        'client_requests'=>'client_id lead_id title description responsible_employee_id request_date deadline lifetime_weeks status',
        'positions'=>'client_request_id technology level description direction_department_id quantity status',
        'connection_attempts'=>'position_id specialist_id specialist_name is_external status closed_from_status failure_reasons description cv_sent_at connection_date closed_at control_date proposed_rate',
        'reporting_periods'=>'client_id period_start period_end status confirmed_hours timesheets_sent_at timesheets_approved_at act_sent_at act_approved_at paid_at',
    ] as $name=>$columns) entity('clients',$name,$columns);
    entity('timesheets','timesheet_entries','client_member_id employee_id project_id work_date hours description','client_member_id,work_date');

    config(['migration.clients_defer_rate_amounts'=>false]);
    $clients = new MemberClientsFixture($store);
    $clients->fixture = array_fill_keys(config('migration.legacy.clients.required_tables'), []);
    $clients->fixture['users'] = [['id'=>13,'email'=>'synthetic.operator@example.invalid','external_key'=>'synthetic-uuid','name'=>'Synthetic Operator']];
    $clients->fixture['clients'] = [['id'=>6,'title'=>'Synthetic Client','description'=>null,'type'=>null,'sector_id'=>null,
        'sales_manager_id'=>13,'account_manager_id'=>13,'approving_term'=>7,'payment_term'=>7]];
    $clients->fixture['projects'] = [['id'=>7,'client_id'=>6,'title'=>'Synthetic Project']];
    $clients->fixture['subcontracts'] = [['id'=>61,'full_name'=>'Synthetic Partner']];
    $clients->fixture['members'] = [
        ['id'=>31,'memberable_type'=>'employee','memberable_id'=>13,'project_id'=>7,'client_id'=>6],
        ['id'=>32,'memberable_type'=>'employee','memberable_id'=>13,'project_id'=>null,'client_id'=>6],
        ['id'=>33,'memberable_type'=>'subcontract','memberable_id'=>61,'project_id'=>null,'client_id'=>6],
    ];
    $clients->fixture['technologies'] = [['id'=>5,'title'=>'Synthetic Technology']];
    $rate = ['member_id'=>31,'technology_id'=>5,'grade'=>'Middle','rate'=>'1500.50','workload'=>8,
        'start_date'=>'2026-01-01','end_date'=>'2026-01-20'];
    $clients->fixture['rates'] = [
        ['id'=>41] + $rate,
        ['id'=>42] + array_replace($rate,['member_id'=>32,'start_date'=>'2026-01-10','end_date'=>'2026-01-31']),
        ['id'=>43] + array_replace($rate,['member_id'=>33]),
    ];
    check(runFixture($clients,true)['conflicts']===0,'Clients dry-run');
    check(target('clients')->table('client_members')->count()===0,'Clients dry-run must not write');
    check(runFixture($clients,false)['conflicts']===0,'Clients import');
    check(target('clients')->table('projects')->count()===1,'No synthetic default project');
    check(target('clients')->table('client_members')->count()===2,'Repeated employee becomes one ClientMember');
    check(target('clients')->table('member_terms')->count()===2,'Compatible overlapping employee terms coalesce');
    check($store->mapping('clients','members','31')===$store->mapping('clients','members','32'),'Member alias');
    check($store->mapping('clients','rates','41')===$store->mapping('clients','rates','42'),'Term alias');
    check(runFixture($clients,false)['conflicts']===0 && $clients->validate(1)['ok'],'Clients rerun and reconciliation');

    $clients->fixture['rates'][1]['grade'] = 'Senior';
    check(runFixture($clients,true)['conflicts']>0,'Conflicting overlapping terms of one ClientMember are blocked');
    $clients->fixture['rates'][1]['grade'] = 'Middle';
    target('clients')->table('reporting_periods')->insert(['client_id'=>$store->mapping('clients','clients','6'),'period_start'=>'2026-01-01',
        'period_end'=>'2026-01-31','status'=>'ТШ согласованы']);
    $clients->fixture['rates'][0]['rate'] = '1600.50';
    $clients->fixture['rates'][1]['rate'] = '1600.50';
    check(runFixture($clients,true)['conflicts']>0,'Protected reporting period blocks a mapped term rewrite');
    $clients->fixture['rates'][0]['rate'] = '1500.50';
    $clients->fixture['rates'][1]['rate'] = '1500.50';
    $legacyRun = $store->beginRun('clients','migrate');
    $store->saveMapping($legacyRun,'clients','default_projects','legacy-only',1);
    $blocked = false;
    try { runFixture($clients,true); }
    catch (RuntimeException $e) { $blocked = str_contains($e->getMessage(),'pre-cutover'); }
    check($blocked,'An old default-project mapping requires reconciliation before Clients dry-run');
    \Illuminate\Support\Facades\DB::table('migration_mappings')->where('service','clients')
        ->where('entity_type','default_projects')->where('legacy_id','legacy-only')->delete();
    $store->finishRun($legacyRun,'completed');

    $partner = target('clients')->table('client_members')->whereNotNull('partner_specialist_id')->first();
    $timesheets = new MemberTimesheetsFixture($store);
    $timesheets->fixture = array_fill_keys(config('migration.legacy.timesheets.required_tables'), []);
    $timesheets->fixture['users'] = [['id'=>17,'employee_id'=>'synthetic-uuid','email'=>'synthetic.operator@example.invalid']];
    $timesheets->fixture['members'] = [
        ['id'=>831,'employee_id'=>'synthetic-uuid','memberable_type'=>'employee','project'=>'Synthetic Client'],
        ['id'=>832,'employee_id'=>null,'memberable_type'=>'subcontract','project'=>'Synthetic Client'],
    ];
    $timesheets->fixture['rates'] = [
        ['id'=>91,'member_id'=>831,'from'=>'2026-01-01','to'=>'2026-01-31','workload'=>8],
        ['id'=>92,'member_id'=>832,'from'=>'2026-01-01','to'=>'2026-01-20','workload'=>8],
        ['id'=>93,'member_id'=>831,'from'=>'2026-01-01','to'=>'2026-01-31','workload'=>8],
    ];
    $timesheets->fixture['timesheets'] = [
        ['id'=>101,'rate_id'=>91,'date'=>'2026-01-05','hours'=>7.33,'description'=>'First task','is_submitted'=>false],
        ['id'=>102,'rate_id'=>93,'date'=>'2026-01-05','hours'=>2.25,'description'=>'Second task','is_submitted'=>false],
        ['id'=>103,'rate_id'=>92,'date'=>'2026-01-05','hours'=>5.5,'description'=>'Partner task','is_submitted'=>false],
    ];
    check(runFixture($timesheets,true)['conflicts']>0,'Unmapped partner must block complete dry-run');
    \Illuminate\Support\Facades\DB::table('migration_overrides')->insert([
        'service'=>'timesheets','entity_type'=>'members','legacy_id'=>'832','target_id'=>$partner->id,
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    check(runFixture($timesheets,true)['conflicts']===0,'Explicit partner mapping permits complete dry-run');
    check(runFixture($timesheets,false)['conflicts']===0,'Timesheets import');
    check(target('timesheets')->table('timesheet_entries')->count()===2,'One fact per ClientMember/day');
    $staff = target('timesheets')->table('timesheet_entries')->whereNotNull('employee_id')->first();
    check((float)$staff->hours===9.58 && $staff->project_id===null,'Exact summed client hours without project');
    check(str_contains($staff->description,'First task') && str_contains($staff->description,'Second task'),'Both descriptions retained');
    check($store->mapping('timesheets','timesheets','101')===$store->mapping('timesheets','timesheets','102'),'Source lineage aliases');
    check(target('timesheets')->table('timesheet_entries')->whereNull('employee_id')->count()===1,'Partner hours have no fake employee');
    check(runFixture($timesheets,false)['conflicts']===0 && $timesheets->validate(1)['ok'],'Timesheets rerun and reconciliation');
    // A single ClientMember/day can produce aliases in multiple streamed pages.
    // Previous validation counted their shared target as an extra row on alias-only pages.
    $paged = new MemberPagedTimesheetsFixture($store);
    $paged->fixture = $timesheets->fixture;
    for ($i = 0; $i < 1005; ++$i) {
        $paged->fixture['timesheets'][] = [
            'id'=>2000 + $i, 'rate_id'=>91, 'date'=>'2026-01-06',
            'hours'=>'0.01', 'description'=>'Paged item '.($i + 1), 'is_submitted'=>false,
        ];
    }
    check(runFixture($paged, true)['conflicts']===0, 'Paged dry-run must resolve source facts');
    check(runFixture($paged, false)['conflicts']===0, 'Paged import must persist grouped facts');
    $pagedValidation = $paged->validate(1);
    check($pagedValidation['ok'], 'Canonical mapped on page one and aliases on page two must validate');
    check(($pagedValidation['tables']['timesheets']['source'] ?? 0) === 1008,
        'All 1008 source facts must participate in validation');
    check(($pagedValidation['tables']['timesheets']['aliases'] ?? 0) === 1005,
        'Cross-page aliases must not count as separate target facts');
    check(target('timesheets')->table('timesheet_entries')->where('work_date','2026-01-06')
        ->where('client_member_id', $store->mapping('timesheets','members','831'))->value('hours') == 10.05,
        'Paged grouped hours must reconcile exactly');
    // The agreed null-rate exception is excluded, never given an invented
    // client/term, and reconciled separately from imported source rows.
    $paged->fixture['timesheets'][] = ['id'=>4999, 'rate_id'=>null,
        'date'=>'2026-01-07', 'hours'=>'3.25', 'description'=>'Unclassified legacy fact',
        'is_submitted'=>false];
    check(runFixture($paged, true)['conflicts']===0, 'Null-rate exclusion must not block dry-run');
    $resultWithExclusion = runFixture($paged, false);
    check($resultWithExclusion['conflicts']===0
        && ($resultWithExclusion['exclusions']['rate_id_null_count'] ?? 0)===1
        && ($resultWithExclusion['exclusions']['rate_id_null_hours'] ?? 0)===3.25,
        'An excluded legacy fact must be counted with exact hours');
    check($store->mapping('timesheets','timesheets','4999')===null,
        'Excluded fact must never acquire a target mapping');
    $excludedValidation = $paged->validate(1);
    check(($excludedValidation['hour_reconciliation']['difference_hours'] ?? null) === 0.0,
        'Imported source and mapped target sums must reconcile exactly');
    check($excludedValidation['ok']
        && ($excludedValidation['tables']['timesheets']['excluded'] ?? 0)===1
        && ($excludedValidation['exclusions']['rate_id_null_hours'] ?? 0)===3.25,
        'Validate must separately reconcile excluded facts');
    // Restore the smaller fixture for subsequent negative-path checks.
    $legacyRun = $store->beginRun('timesheets','migrate');
    $store->saveMapping($legacyRun,'timesheets','members','legacy-only',1,
        ['resolution'=>['version'=>2,'project_member_id'=>1]]);
    $blocked = false;
    try { runFixture($timesheets,true); }
    catch (RuntimeException $e) { $blocked = str_contains($e->getMessage(),'Pre-cutover'); }
    check($blocked,'An old project-member mapping requires reconciliation before Timesheets dry-run');
    \Illuminate\Support\Facades\DB::table('migration_mappings')->where('service','timesheets')
        ->where('entity_type','members')->where('legacy_id','legacy-only')->delete();
    $store->finishRun($legacyRun,'completed');
    $entriesBeforeFailedImport = target('timesheets')->table('timesheet_entries')->count();
    $timesheets->fixture['rates'][] = ['id'=>94,'member_id'=>831,'from'=>'2026-01-01','to'=>'2026-01-31','workload'=>8];
    $timesheets->fixture['timesheets'][] = ['id'=>104,'rate_id'=>94,'date'=>'2026-01-05','hours'=>20,
        'description'=>'Over capacity','is_submitted'=>false];
    check(runFixture($timesheets,true)['conflicts']>0,'More than 24 hours for one employee/day is a conflict');
    $blocked = false;
    try { runFixture($timesheets,false); }
    catch (RuntimeException $e) { $blocked = str_contains($e->getMessage(),'Import blocked by unresolved source rows'); }
    check($blocked && target('timesheets')->table('timesheet_entries')->count()===$entriesBeforeFailedImport,
        'Full preflight prevents partial target writes for an excessive day');
    echo "ClientMember legacy import smoke passed.\n";
} catch (Throwable $e) { $failure=$e; }
finally { @unlink($path); @unlink($path.'.identity'); @unlink($path.'-wal'); @unlink($path.'-shm'); }
if ($failure) { fwrite(STDERR,$failure->__toString()."\n"); exit(1); }
