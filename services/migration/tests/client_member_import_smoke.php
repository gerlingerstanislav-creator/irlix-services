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
    echo "ClientMember legacy import smoke passed.\n";
} catch (Throwable $e) { $failure=$e; }
finally { @unlink($path); @unlink($path.'.identity'); @unlink($path.'-wal'); @unlink($path.'-shm'); }
if ($failure) { fwrite(STDERR,$failure->__toString()."\n"); exit(1); }
