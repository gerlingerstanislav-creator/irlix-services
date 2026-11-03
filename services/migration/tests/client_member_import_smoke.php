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
    protected function extract(): array {
        // Production's reader owns its independent pages. In this in-memory
        // fixture, explicitly make the current source visible to Validate,
        // even when no migrate() has been called on this new object yet.
        return $this->source = $this->fixture;
    }
    protected function deferredSourceTables(): array { return ['timesheets']; }
    protected function sourceBatches(string $table): iterable {
        foreach (array_chunk($this->source[$table] ?? [], 1000) as $batch) yield $batch;
    }
}
// Exercise the real Timesheets sourceBatches() page hashing against a synthetic
// LegacyReader page implementation. Previous fixtures bypassed page audit entirely
// and therefore missed live failure #351 (0 dry-run source hashes).
class MemberBaselineTimesheetsFixture extends MemberTimesheetsFixture {
    protected function deferredSourceTables(): array { return ['timesheets']; }
    protected function extract(): array {
        $this->resetSourceScan();
        $this->sourceTotals = ['timesheets'=>count($this->fixture['timesheets'] ?? [])];
        $ids = array_column($this->fixture['timesheets'] ?? [], 'id');
        $this->deferredSourceWatermarks = ['timesheets'=>$ids ? max($ids) : null];
        $this->source = $this->fixture;
        $this->source['timesheets'] = [];
        return $this->source;
    }
    protected function readTimesheetsPage(\App\Migration\Core\LegacyReader $reader, mixed $highWater, mixed $last, int $size): array {
        $rows = array_values(array_filter($this->fixture['timesheets'] ?? [],
            static fn (array $item) => (int)$item['id'] <= (int)$highWater
                && ($last === null || (int)$item['id'] > (int)$last)));
        usort($rows, static fn($a,$b) => (int)$a['id'] <=> (int)$b['id']);
        return array_map(static fn (array $item) => (object)$item, array_slice($rows, 0, $size));
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
        'connection_attempts'=>'position_id specialist_id specialist_name is_external status closed_from_status failure_reasons description cv_sent_at connection_date closed_at control_date',
        'reporting_periods'=>'client_id period_start period_end status confirmed_hours timesheets_sent_at timesheets_approved_at act_sent_at act_approved_at paid_at timesheet_snapshot timesheet_revision timesheet_freeze_token',
    ] as $name=>$columns) entity('clients',$name,$columns);
    entity('clients','reporting_period_history','reporting_period_id type title');
    entity('clients','reporting_period_approved_hours','reporting_period_id member_term_id hours');
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
    // Product invariant: no proposed_rate in the target Attempt model, even
    // if the source has a legacy price and MemberTerms amounts are enabled.
    $clients->fixture['client_requests'] = [[
        'id'=>71,'client_id'=>6,'lead_id'=>null,'date'=>'2026-01-01','expired_at'=>null,
        'title'=>'Synthetic Request','description'=>null,'responsible_id'=>13,'closed_at'=>null,
    ]];
    $clients->fixture['positions'] = [[
        'id'=>72,'client_request_id'=>71,'technology_id'=>5,'department_id'=>null,
        'count'=>1,'grade'=>'Middle','description'=>null,'closed_at'=>null,'status'=>'open',
    ]];
    $clients->fixture['attempts'] = [[
        'id'=>73,'position_id'=>72,'user_id'=>13,'user_name'=>'Synthetic Operator',
        'result_comment'=>null,'cv_sent_at'=>null,'interviewed_at'=>null,'started_at'=>null,
        'closed_at'=>null,'control_date'=>null,'status'=>null,'rate'=>'1500.50',
    ]];

    check(runFixture($clients,true)['conflicts']===0,'Clients dry-run');
    check(target('clients')->table('client_members')->count()===0,'Clients dry-run must not write');
    check(runFixture($clients,false)['conflicts']===0,'Clients import');
    check(target('clients')->table('projects')->count()===1,'No synthetic default project');
    check(target('clients')->table('client_members')->count()===2,'Repeated employee becomes one ClientMember');
    check(target('clients')->table('member_terms')->count()===2,'Compatible overlapping employee terms coalesce');
    check(target('clients')->table('connection_attempts')->count()===1,
        'Imported synthetic attempt without removed proposed_rate column');
    check($store->mapping('clients','attempts','73')!==null,
        'Attempt target identity is retained for later idempotent retry');
    check($store->mapping('clients','members','31')===$store->mapping('clients','members','32'),'Member alias');
    check($store->mapping('clients','rates','41')===$store->mapping('clients','rates','42'),'Term alias');
    // Daily reruns must NOT physically rewrite equal records/timestamps.
    $clientId = $store->mapping('clients','clients','6');
    $memberId = $store->mapping('clients','members','31');
    target('clients')->table('clients')->where('id',$clientId)
        ->update(['updated_at'=>'2001-01-01 00:00:00']);
    target('clients')->table('client_members')->where('id',$memberId)
        ->update(['updated_at'=>'2001-01-01 00:00:00']);
    check(runFixture($clients,false)['conflicts']===0 && $clients->validate(1)['ok'],
        'Idempotent Clients rerun and reconciliation');
    check(target('clients')->table('clients')->where('id',$clientId)->value('updated_at')==='2001-01-01 00:00:00'
        && target('clients')->table('client_members')->where('id',$memberId)->value('updated_at')==='2001-01-01 00:00:00',
        'Equal mapped domain records must preserve their physical updated_at');
    $clients->fixture['clients'][0]['description'] = 'Source correction';
    check(runFixture($clients,false)['conflicts']===0
        && target('clients')->table('clients')->where('id',$clientId)->value('description')==='Source correction',
        'Changed legacy field must update the mapped record');
    check(target('clients')->table('client_members')->where('id',$memberId)->value('updated_at')==='2001-01-01 00:00:00',
        'Changed client must not rewrite unrelated ClientMember');
    $clients->fixture['clients'][0]['description'] = null;
    check(runFixture($clients,false)['conflicts']===0, 'Revert synthetic source correction');
    check(\App\Migration\Core\TargetFieldComparison::matches('001', '1', 'login')===false
        && \App\Migration\Core\TargetFieldComparison::matches('8.000', '8', 'hours_per_day')
        && \App\Migration\Core\TargetFieldComparison::matches('2026-01-31 00:00:00', '2026-01-31', 'valid_to')
        && !\App\Migration\Core\TargetFieldComparison::matches('2026-01-31 10:00:00', '2026-01-31', 'valid_to'),
        'Numeric and date normalization must preserve actual differences and string identities');

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

    // Repeated imports must use the *same* scalar/date comparison as writes.
    // SQL DATE and DECIMAL display formats do not signify a business change.
    $canonicalTermId = $store->mapping('clients', 'rates', '41');
    target('clients')->table('member_terms')->where('id', $canonicalTermId)
        ->update(['valid_to'=>'2026-01-31 00:00:00', 'hourly_rate'=>'1500.5000']);
    check(runFixture($clients, true)['conflicts']===0,
        'Equivalent date and decimal representations do not block a submitted period');
    target('clients')->table('member_terms')->where('id', $canonicalTermId)
        ->update(['valid_to'=>'2026-01-31', 'hourly_rate'=>'1500.50']);

    // Clients itself compares the clipped span within a protected month.
    // An extension after January changes no approved January day.
    $clients->fixture['rates'][1]['end_date'] = '2026-02-10';
    check(runFixture($clients, true)['conflicts']===0,
        'An extension entirely after the submitted month does not require reconciliation');
    check(runFixture($clients, false)['conflicts']===0
        && target('clients')->table('member_terms')->where('id',$canonicalTermId)->value('valid_to')==='2026-02-10',
        'Approved future-only date extension is actually synchronized');
    $clients->fixture['rates'][1]['end_date'] = '2026-01-31';
    check(runFixture($clients, false)['conflicts']===0,
        'Idempotent shrink back to the prior end is safe without changing approved January');
    $clients->fixture['rates'][1]['end_date'] = '2026-01-30';
    check(runFixture($clients, true)['conflicts']>0,
        'Shortening a term inside a submitted month requires reconciliation');
    $clients->fixture['rates'][1]['end_date'] = '2026-01-31';

    // Moving the incoming interval completely out of a protected month
    // must not bypass protection of the *previous* target interval.
    $originalRates = $clients->fixture['rates'];
    $clients->fixture['rates'][0]['start_date'] = '2026-02-01';
    $clients->fixture['rates'][0]['end_date'] = '2026-02-20';
    $clients->fixture['rates'][1]['start_date'] = '2026-02-10';
    $clients->fixture['rates'][1]['end_date'] = '2026-02-28';
    check(runFixture($clients, true)['conflicts']>0,
        'Moving a term outside a submitted month must also protect its former dates');
    $clients->fixture['rates'] = $originalRates;

    // Unknown reporting states are not proof that a period is editable.
    target('clients')->table('reporting_periods')->update(['status'=>null]);
    $clients->fixture['rates'][0]['rate'] = '1600.50';
    $clients->fixture['rates'][1]['rate'] = '1600.50';
    check(runFixture($clients, true)['conflicts']>0,
        'Unknown reporting status fails closed for changed legacy commercial terms');
    target('clients')->table('reporting_periods')->update(['status'=>'ТШ согласованы']);
    $clients->fixture['rates'][0]['rate'] = '1500.50';
    $clients->fixture['rates'][1]['rate'] = '1500.50';

    // Production #372: previously deferred NULL amounts can be backfilled
    // after recovering the legacy APP_KEY, even within a submitted report.
    // Only this single NULL -> numeric field is exempt from the protected
    // commercial-terms guard; changed workload or existing price is not.
    $legacyKey = 'base64:'.base64_encode(str_repeat('z', 32));
    $cipher = \App\Migration\Core\LegacyClientsCipher::encrypter($legacyKey, 'AES-256-CBC');
    $encryptedAmount = $cipher->encryptString('1500.50');
    foreach ($clients->fixture['rates'] as &$row) $row['rate'] = $encryptedAmount;
    unset($row);
    config(['migration.clients_app_key' => $legacyKey, 'migration.clients_cipher' => 'AES-256-CBC']);
    $originalIds = target('clients')->table('member_terms')->orderBy('id')->pluck('id')->all();
    target('clients')->table('member_terms')->update(['hourly_rate'=>null]);
    $beforeBackfill = target('clients')->table('member_terms')->orderBy('id')->get()->toArray();
    // A previously NULL encrypted rate is still immutable if the associated
    // legacy-imported period is paid; an unsealed submitted period is different.
    target('clients')->table('reporting_periods')->update([
        'status'=>'Счет оплачен', 'paid_at'=>'2026-03-01',
    ]);
    check(runFixture($clients, true)['conflicts']>0,
        'NULL encrypted amount must not alter a previously imported paid period');
    check(\Illuminate\Support\Facades\DB::table('migration_conflicts')
        ->where('code','PROTECTED_TERM_CHANGE')
        ->where('context','like','%paid_or_native_approved_backfill%')->exists(),
        'Paid-period backfill must be blocked at the rate guard, not by an unrelated period mismatch');
    target('clients')->table('reporting_periods')->update([
        'status'=>'ТШ согласованы', 'paid_at'=>null,
        'timesheet_snapshot'=>'{"revision":"native"}',
    ]);
    check(runFixture($clients, true)['conflicts']>0,
        'NULL encrypted amount must not bypass native Timesheets freeze');
    check(\Illuminate\Support\Facades\DB::table('migration_conflicts')
        ->where('code','PROTECTED_TERM_CHANGE')
        ->where('context','like','%NATIVE_TIMESHEETS_FREEZE%')->exists(),
        'Native freeze must reject the targeted NULL-only amount backfill');
    target('clients')->table('reporting_periods')
        ->update(['timesheet_snapshot'=>null]);
    $dryBackfill = runFixture($clients, true);
    check($dryBackfill['conflicts']===0, 'Encrypted legacy amount can fill NULL in a submitted reporting period');
    check(target('clients')->table('member_terms')->whereNotNull('hourly_rate')->count()===0,
        'Dry run must not fill protected period amounts');
    $wrongKey = 'base64:'.base64_encode(str_repeat('q', 32));
    config(['migration.clients_app_key'=>$wrongKey]);
    check(runFixture($clients, true)['conflicts']>0, 'Wrong recovered key must still fail decryption');
    config(['migration.clients_app_key'=>$legacyKey]);
    $clients->fixture['rates'][0]['workload'] = 7;
    $clients->fixture['rates'][1]['workload'] = 7;
    check(runFixture($clients, true)['conflicts']>0,
        'Changed workload with missing rate must still be protected');
    $clients->fixture['rates'][0]['workload'] = 8;
    $clients->fixture['rates'][1]['workload'] = 8;
    $clients->fixture['rates'][1]['rate'] = $cipher->encryptString('1500.50');
    check($clients->fixture['rates'][0]['rate'] !== $clients->fixture['rates'][1]['rate']
        && runFixture($clients, true)['conflicts']===0,
        'Distinct encrypted payloads of equal amounts must coalesce without a false interval conflict');
    check(runFixture($clients, false)['conflicts']===0, 'Backfill deferred encrypted rates without remapping');
    $afterBackfill = target('clients')->table('member_terms')->orderBy('id')->get()->toArray();
    check(array_column($afterBackfill, 'id')===$originalIds,
        'Backfill preserves existing target term identities');
    foreach ($afterBackfill as $index => $term) {
        check((float)$term->hourly_rate===1500.5, 'Backfilled rate must match decrypted legacy amount');
        foreach (['client_member_id','project_member_id','technology','level','hours_per_day','valid_from','valid_to'] as $field) {
            check($term->$field === $beforeBackfill[$index]->$field,
                'Backfill must not change historical '.$field);
        }
    }
    check(runFixture($clients, false)['conflicts']===0,
        'Idempotent repeat after protected-period backfill');
    $clients->fixture['rates'][0]['rate'] = $cipher->encryptString('1600.50');
    $clients->fixture['rates'][1]['rate'] = $clients->fixture['rates'][0]['rate'];
    check(runFixture($clients, true)['conflicts']>0,
        'Changing a previously populated amount in submitted report is still blocked');
    foreach ($clients->fixture['rates'] as &$row) $row['rate'] = '1500.50';
    unset($row);
    config(['migration.clients_app_key'=>null]);
    $legacyRun = $store->beginRun('clients','migrate');
    $store->saveMapping($legacyRun,'clients','default_projects','legacy-only',1);
    $result = runFixture($clients,true);
    check(($result['conflicts'] ?? 0) > 0 && ($result['preflight'] ?? '') === 'legacy_mappings_need_reconciliation',
        'Old Clients mappings are reported as row-level dry-run conflicts without target writes');
    $blocked = false;
    try { runFixture($clients,false); }
    catch (RuntimeException $e) { $blocked = str_contains($e->getMessage(),'CLIENTS_LEGACY_MAPPINGS_INCOMPATIBLE'); }
    check($blocked,'Old Clients mappings still forbid data writes');
    \Illuminate\Support\Facades\DB::table('migration_mappings')->where('service','clients')
        ->where('entity_type','default_projects')->where('legacy_id','legacy-only')->delete();
    $store->finishRun($legacyRun,'completed');

    // A submitted-looking status imported from the old service is not by
    // itself a native approval. The legacy source can correct such terms
    // while there is no frozen Timesheets report, Finance closure or native
    // approval history, and the migration mapping is still intact.
    target('clients')->table('reporting_periods')->delete();
    $clients->fixture['reporting_periods'] = [[
        'id'=>51, 'client_id'=>6, 'from'=>'2026-01-01', 'to'=>'2026-01-31',
        'approval_at'=>'2026-02-01', 'approved_at'=>'2026-02-02',
        'act_approval_at'=>null, 'act_approved_at'=>null, 'paid_at'=>null,
        'approved_hours'=>null,
    ]];
    check(runFixture($clients, false)['conflicts']===0,
        'Source reporting period is imported with its legacy submitted status');
    $legacyPeriodId = $store->mapping('clients','reporting_periods','51');
    check($legacyPeriodId!==null
        && target('clients')->table('reporting_periods')->where('id',$legacyPeriodId)->value('status')==='ТШ согласованы',
        'Imported legacy reporting period has no native freeze');
    $clients->fixture['rates'][0]['rate'] = '1600.50';
    $clients->fixture['rates'][1]['rate'] = '1600.50';
    $clients->fixture['rates'][1]['end_date'] = '2026-01-30';
    $beforeUnsealedDry = target('clients')->table('member_terms')
        ->where('id',$canonicalTermId)->first();
    check(runFixture($clients, true)['conflicts']===0,
        'Legacy-only, unsealed reporting period allows source rate correction in dry-run');
    check(target('clients')->table('member_terms')->where('id',$canonicalTermId)->value('valid_to')
        ===$beforeUnsealedDry->valid_to,
        'Dry run cannot apply a historical correction');
    check(runFixture($clients, false)['conflicts']===0,
        'Safe historical correction is applied by normal mapped write');
    $correctedTerm = target('clients')->table('member_terms')->where('id',$canonicalTermId)->first();
    check($correctedTerm->valid_to==='2026-01-30'
        && (float)$correctedTerm->hourly_rate===1600.5,
        'Historical correction preserves MemberTerm identity and adopts legacy truth');
    check(runFixture($clients, false)['conflicts']===0,
        'Safe historical correction remains idempotent on rerun');

    // Even without a native approval, a target-owned correction must not be
    // silently reverted by re-importing unchanged legacy data.
    target('clients')->table('member_terms')->where('id',$canonicalTermId)
        ->update(['hourly_rate'=>'1550.50']);
    check(runFixture($clients, true)['conflicts']>0,
        'Target-side rate correction requires V2 reconciliation evidence');
    check((float)target('clients')->table('member_terms')
        ->where('id',$canonicalTermId)->value('hourly_rate')===1550.5,
        'Dry run must preserve target-side correction');
    target('clients')->table('member_terms')->where('id',$canonicalTermId)
        ->update(['hourly_rate'=>'1600.50']);
    check(runFixture($clients, true)['conflicts']===0,
        'Restored target baseline remains eligible for idempotent dry run');

    $clients->fixture['rates'][0]['rate']='1700.50';
    $clients->fixture['rates'][1]['rate']='1700.50';
    target('clients')->table('reporting_period_history')->insert([
        'reporting_period_id'=>$legacyPeriodId,'type'=>'native-approval','title'=>'Native approval',
    ]);
    check(runFixture($clients, true)['conflicts']>0,
        'Any new Clients approval history blocks correcting historical submitted amounts');
    target('clients')->table('reporting_period_history')->delete();

    target('clients')->table('reporting_periods')->where('id',$legacyPeriodId)
        ->update(['timesheet_snapshot'=>'{"revision":"native"}']);
    check(runFixture($clients, true)['conflicts']>0,
        'Timesheets freeze snapshot protects an otherwise legacy-imported period');
    target('clients')->table('reporting_periods')->where('id',$legacyPeriodId)
        ->update(['timesheet_snapshot'=>null]);

    target('clients')->table('reporting_period_approved_hours')->insert([
        'reporting_period_id'=>$legacyPeriodId,'member_term_id'=>$canonicalTermId,'hours'=>'3',
    ]);
    check(runFixture($clients, true)['conflicts']>0,
        'Native hour approvals protect an otherwise legacy-imported period');
    target('clients')->table('reporting_period_approved_hours')->delete();

    target('clients')->table('reporting_periods')->where('id',$legacyPeriodId)
        ->update(['status'=>'Счет оплачен', 'paid_at'=>'2026-03-01']);
    check(runFixture($clients, true)['conflicts']>0,
        'Finance paid state cannot be rewritten by legacy migration');
    target('clients')->table('reporting_periods')->where('id',$legacyPeriodId)
        ->update(['status'=>'ТШ согласованы', 'paid_at'=>null]);

    target('clients')->table('reporting_periods')->where('id',$legacyPeriodId)
        ->update(['confirmed_hours'=>'12']);
    check(runFixture($clients, true)['conflicts']>0,
        'Reporting period changed outside migration is no longer a verified legacy-only record');
    target('clients')->table('reporting_periods')->where('id',$legacyPeriodId)
        ->update(['confirmed_hours'=>null]);

    $clients->fixture['rates'][0]['rate']='1600.50';
    $clients->fixture['rates'][1]['rate']='1600.50';
    check(runFixture($clients, true)['conflicts']===0,
        'Original source correction persists after negative safety cases');

    // Source can change the reporting status too. A repeated import must
    // never manufacture a paid period or roll back a native Finance closure.
    $clients->fixture['reporting_periods'][0]['paid_at'] = '2026-03-01';
    $reportAttempt = runFixture($clients, true);
    check($reportAttempt['conflicts']>0
        && \Illuminate\Support\Facades\DB::table('migration_conflicts')
            ->where('code','PROTECTED_REPORTING_PERIOD_CHANGE')->exists(),
        'An already imported period cannot become Finance-paid via a legacy delta');
    $clients->fixture['reporting_periods'][0]['paid_at'] = null;
    $clients->fixture['reporting_periods'][0]['act_approved_at'] = '2026-03-01';
    check(runFixture($clients, true)['conflicts']>0,
        'A legacy delta cannot issue newly act-approved status in place of native workflow');
    $clients->fixture['reporting_periods'][0]['act_approved_at'] = null;

    target('clients')->table('reporting_periods')->where('id',$legacyPeriodId)
        ->update(['status'=>'Счет оплачен','paid_at'=>'2026-03-01']);
    check(runFixture($clients, true)['conflicts']>0,
        'Repeat import cannot downgrade a Finance-paid period back to source status');
    check(target('clients')->table('reporting_periods')->where('id',$legacyPeriodId)->value('status')==='Счет оплачен',
        'A denied dry-run leaves the paid period intact');
    target('clients')->table('reporting_periods')->where('id',$legacyPeriodId)
        ->update(['status'=>'ТШ согласованы','paid_at'=>null]);
    check(runFixture($clients, true)['conflicts']===0,
        'No-op after restoring original historical status still succeeds');

    // The original (human-confirmed) paid period remains authoritative.
    // Finance has not been commissioned, so an empty Finance ledger is not
    // evidence that an imported human-confirmed reporting period was unpaid.
    $periodMapping = \Illuminate\Support\Facades\DB::table('migration_mappings')
        ->where('service','clients')->where('entity_type','reporting_periods')->where('legacy_id','51');
    $originalPeriodMeta = json_decode($periodMapping->value('metadata'), true);
    $originalRates = $clients->fixture['rates'];
    $originalReportingRow = $clients->fixture['reporting_periods'][0];
    $originalTerm = target('clients')->table('member_terms')->where('id',$canonicalTermId)->first();
    $clients->fixture['reporting_periods'][0]['paid_at'] = '2026-03-01';
    $paidPeriodMeta = $originalPeriodMeta;
    $paidPeriodMeta['source'] = $clients->fixture['reporting_periods'][0];
    $paidPeriodMeta['target_payload']['status'] = 'Счет оплачен';
    $paidPeriodMeta['target_payload']['paid_at'] = '2026-03-01';
    target('clients')->table('reporting_periods')->where('id',$legacyPeriodId)
        ->update(['status'=>'Счет оплачен','paid_at'=>'2026-03-01']);
    $periodMapping->update(['metadata'=>json_encode($paidPeriodMeta,JSON_UNESCAPED_UNICODE)]);
    $clients->fixture['rates'][0]['rate'] = '1700.50';
    $clients->fixture['rates'][1]['rate'] = '1700.50';
    $clients->fixture['rates'][1]['end_date'] = '2026-01-29';
    $frozenDry = runFixture($clients,true);
    check($frozenDry['conflicts']===0,
        'Confirmed historically paid period may preserve its original rate without a Finance ledger');
    check(\Illuminate\Support\Facades\DB::table('migration_conflicts')
        ->where('code','CONFIRMED_HISTORICAL_RATE_PRESERVED')->exists(),
        'Frozen historical rate must leave an auditable exception, not silently disappear');
    check(target('clients')->table('member_terms')->where('id',$canonicalTermId)
        ->value('valid_to')===$originalTerm->valid_to,
        'Dry-run must not modify the historical term end');
    check(runFixture($clients,false)['conflicts']===0,
        'Frozen historical rate may be reconciled metadata-only, without changing paid data');
    check(target('clients')->table('member_terms')->where('id',$canonicalTermId)
        ->value('valid_to')===$originalTerm->valid_to
        && (float)target('clients')->table('member_terms')->where('id',$canonicalTermId)
            ->value('hourly_rate')===(float)$originalTerm->hourly_rate,
        'Paid period rate and term must be unchanged after the real migration');
    check(target('clients')->table('reporting_periods')->where('id',$legacyPeriodId)
        ->value('status')==='Счет оплачен',
        'Human-confirmed paid reporting period cannot be downgraded');
    check($clients->validate(1)['ok'],
        'Accepted frozen historical rate is accounted for in full Clients validation');
    check(runFixture($clients,false)['conflicts']===0,
        'Repeated frozen reconciliation must remain idempotent');
    target('clients')->table('member_terms')->where('id',$canonicalTermId)
        ->update(['hourly_rate'=>'1550.50']);
    check(runFixture($clients,true)['conflicts']>0,
        'A later independent target rate change must invalidate the frozen-rate exemption');
    target('clients')->table('member_terms')->where('id',$canonicalTermId)
        ->update(['hourly_rate'=>$originalTerm->hourly_rate]);
    // Production can defer source hourly_rate, leaving a seven-field
    // previous mapping and a valid_to-only delta. Its human-confirmed
    // period must remain protected without demanding an active Finance.
    config(['migration.clients_defer_rate_amounts'=>true]);
    check(runFixture($clients,true)['conflicts']===0,
        'Deferred amount must not block an otherwise verified historical valid_to exception');
    check(runFixture($clients,false)['conflicts']===0 && $clients->validate(1)['ok'],
        'Deferred date-only source delta preserves paid term and remains validatable');
    check(target('clients')->table('member_terms')->where('id',$canonicalTermId)
        ->value('valid_to')===$originalTerm->valid_to,
        'Date-only historic exception must not rewrite the protected end date');
    config(['migration.clients_defer_rate_amounts'=>false]);
    $clients->fixture['rates'] = $originalRates;
    $clients->fixture['reporting_periods'][0] = $originalReportingRow;
    target('clients')->table('reporting_periods')->where('id',$legacyPeriodId)
        ->update(['status'=>'ТШ согласованы','paid_at'=>null]);
    $periodMapping->update(['metadata'=>json_encode($originalPeriodMeta,JSON_UNESCAPED_UNICODE)]);
    check(runFixture($clients,false)['conflicts']===0 && $clients->validate(1)['ok'],
        'Restoring the fixture after a preserved paid period remains idempotent');

    $only = [['target_id'=>98,'client_name'=>'Renamed Client','project_names'=>[],
        'terms'=>[['id'=>5,'valid_from'=>'2026-01-01','valid_to'=>'2026-12-31']]]];
    check(\App\Migration\Core\TimesheetsMemberMatching::choose('Old project', [], $only)['selected_id']===98,
        'Unique UUID-verified ClientMember resolves even if legacy project name differs');
    $ambiguous = [['target_id'=>125,'client_name'=>'Client A','project_names'=>['Same Project'],'terms'=>[]],
        ['target_id'=>293,'client_name'=>'Client B','project_names'=>['Same Project'],'terms'=>[]]];
    check(\App\Migration\Core\TimesheetsMemberMatching::choose('Same Project', [], $ambiguous)['selected_id']===null,
        'Two ClientMembers with the same project and no fact dates must remain ambiguous');
    $partner = target('clients')->table('client_members')->whereNotNull('partner_specialist_id')->first();
    $timesheets = new MemberTimesheetsFixture($store);
    $timesheets->fixture = array_fill_keys(config('migration.legacy.timesheets.required_tables'), []);
    $timesheets->fixture['users'] = [['id'=>17,'employee_id'=>'synthetic-uuid','email'=>'synthetic.operator@example.invalid']];
    $timesheets->fixture['members'] = [
        ['id'=>831,'employee_id'=>'synthetic-uuid','memberable_type'=>'employee','project'=>'Synthetic Client'],
        ['id'=>832,'employee_id'=>null,'memberable_type'=>'subcontract','project'=>'Synthetic Client'],
        ['id'=>833,'employee_id'=>'synthetic-uuid','memberable_type'=>'employee','project'=>'Ambiguous old project'],
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
    // Real run #320: dangling legacy rate with no matching source member
    // and no facts. Keep it as metadata rather than fabricate a ClientMember.
    $orphan = new MemberTimesheetsFixture($store);
    $orphan->fixture = $timesheets->fixture;
    $orphan->fixture['rates'][] = [
        'id'=>588, 'member_id'=>421, 'from'=>'2026-01-01',
        'to'=>'2026-01-31', 'workload'=>8,
    ];
    check(runFixture($orphan,true)['conflicts']===0,
        'Empty orphan rate must not block dry-run');
    check(runFixture($orphan,false)['conflicts']===0,
        'Empty orphan rate must retain metadata without domain mapping');
    check($store->mapping('timesheets','rates','588')===null,
        'Empty orphan rate must never create a target mapping');
    $orphanValidation = $orphan->validate(1);
    if (!$orphanValidation['ok']) fwrite(STDERR, 'orphan validation summary '.json_encode($orphanValidation,JSON_UNESCAPED_UNICODE)."\\n");
    check($orphanValidation['ok'],
        'Metadata-only orphan rate excluded from domain validation');
    $freshValidator = new MemberTimesheetsFixture($store);
    $freshValidator->fixture = $orphan->fixture;
    check($freshValidator->validate(1)['ok'],
        'Fresh worker validates orphan metadata against its own extracted source snapshot');

    $orphanWithFacts = new MemberTimesheetsFixture($store);
    $orphanWithFacts->fixture = $orphan->fixture;
    $orphanWithFacts->fixture['timesheets'][] = [
        'id'=>589, 'rate_id'=>588, 'date'=>'2026-01-07',
        'hours'=>'3.00', 'description'=>'Must block',
        'is_submitted'=>false,
    ];
    check(runFixture($orphanWithFacts,true)['conflicts']>0,
        'Orphan rate with actual hours must remain blocking');

    check($store->mapping('timesheets','members','833')===null,
        'Member without legacy rates is metadata only, not an invented ClientMember');
    check(runFixture($timesheets,false)['conflicts']===0,'Timesheets import');
    check(target('timesheets')->table('timesheet_entries')->count()===2,'One fact per ClientMember/day');
    $staff = target('timesheets')->table('timesheet_entries')->whereNotNull('employee_id')->first();
    check((float)$staff->hours===9.58 && $staff->project_id===null,'Exact summed client hours without project');
    check(str_contains($staff->description,'First task') && str_contains($staff->description,'Second task'),'Both descriptions retained');
    check($store->mapping('timesheets','timesheets','101')===$store->mapping('timesheets','timesheets','102'),'Source lineage aliases');
    check(target('timesheets')->table('timesheet_entries')->whereNull('employee_id')->count()===1,'Partner hours have no fake employee');
    check(runFixture($timesheets,false)['conflicts']===0 && $timesheets->validate(1)['ok'],'Timesheets rerun and reconciliation');
    // Real control-mode #351 regression: a complete dry-run must record source
    // batch SHA-256 evidence and the next migrate must consume exactly this run
    // (not a previous operation's baseline). No production databases required.
    $baselineFixture = new MemberBaselineTimesheetsFixture($store);
    $baselineFixture->fixture = $timesheets->fixture;
    $baselineDry = $store->beginRun('timesheets','dry-run');
    $store->event($baselineDry,'console','Synthetic control operation','info',
        ['operation_id'=>'9100351']);
    $baselineDryResult = $baselineFixture->migrate($baselineDry,true);
    check($baselineDryResult['conflicts']===0,'Synthetic control dry-run must complete without conflicts');
    $store->finishRun($baselineDry,'completed',$baselineDryResult);
    $batchRows = \Illuminate\Support\Facades\DB::table('migration_batch_progress')
        ->where('migration_run_id',$baselineDry)->where('table_name','timesheets')->get();
    check($batchRows->count()===1 && (int)$batchRows[0]->row_count===3
        && preg_match('/^[a-f0-9]{64}$/D',(string)$batchRows[0]->source_sha256)===1,
        'Dry-run must persist the real Timesheets batch inventory for later migrate (#351)');
    $snapshotBefore = target('timesheets')->table('timesheet_entries')->count();
    \App\Migration\Core\ControlMode::$enabled = true;
    try {
        $baselineFixture->fixture['timesheets'][0]['hours'] = 7.34;
        $mutatedRun = $store->beginRun('timesheets','migrate');
        $store->event($mutatedRun,'console','Synthetic control operation','info',
            ['operation_id'=>'9100351']);
        $rejected = false;
        try { $baselineFixture->migrate($mutatedRun,false); }
        catch (RuntimeException $e) {
            $rejected = str_contains($e->getMessage(),'Legacy Timesheets differ from the completed dry-run');
        }
        check($rejected,'Changed facts between dry-run and migrate must be rejected before target writes');
        check(target('timesheets')->table('timesheet_entries')->count()===$snapshotBefore,
            'Drifted source must not modify target data');
        $baselineFixture->fixture['timesheets'][0]['hours'] = 7.33;
        $successfulRun = $store->beginRun('timesheets','migrate');
        $store->event($successfulRun,'console','Synthetic control operation','info',
            ['operation_id'=>'9100351']);
        $written = $baselineFixture->migrate($successfulRun,false);
        check($written['conflicts']===0,'Unchanged source with real dry-run batch baseline must migrate');
        check(\Illuminate\Support\Facades\DB::table('migration_batch_progress')
            ->where('migration_run_id',$successfulRun)->where('table_name','timesheets')->count()===1,
            'Real import must also report processed source pages');
        $store->finishRun($successfulRun,'completed',$written);
    } finally { \App\Migration\Core\ControlMode::$enabled = false; }
    // A genuine historical fact may outlive both the legacy rate and Clients
    // member_terms. Do not fabricate a commercial term or discard paid hours.
    $historical = new MemberTimesheetsFixture($store);
    $historical->fixture = $timesheets->fixture;
    $historical->fixture['timesheets'][] = [
        'id'=>105, 'rate_id'=>91, 'date'=>'2026-03-15', 'hours'=>'2.50',
        'description'=>'Historical exception', 'is_submitted'=>false,
    ];
    check(runFixture($historical,true)['conflicts']===0,
        'Historical fact outside commercial term must pass dry-run with warning');
    check(runFixture($historical,false)['conflicts']===0,
        'Historical fact must retain ClientMember and hours without adding terms');
    $oldFact = target('timesheets')->table('timesheet_entries')->where('work_date','2026-03-15')->first();
    check($oldFact !== null && (float)$oldFact->hours===2.5,
        'Historical fact imported as noncommercial ClientMember hours');
    $historicalMeta = \Illuminate\Support\Facades\DB::table('migration_mappings')
        ->where('service','timesheets')->where('entity_type','timesheets')
        ->where('legacy_id','105')->value('metadata');
    $historicalResolution = json_decode($historicalMeta ?: '{}',true)['resolution'] ?? [];
    check(array_key_exists('target_term_id',$historicalResolution)
        && $historicalResolution['target_term_id']===null,
        'Historical fact has explicit null target term, not an invented anchor');
    check($historical->validate(1)['ok'], 'Historical exception must validate cleanly');
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
    $missingCanonical = new MemberPagedTimesheetsFixture($store);
    $missingCanonical->fixture = $paged->fixture;
    $missingCanonical->fixture['timesheets'] = array_values(array_filter(
        $missingCanonical->fixture['timesheets'],
        static fn ($row) => (int)$row['id'] !== 2000
    ));
    $missingValidation = $missingCanonical->validate(1);
    check(!$missingValidation['ok']
        && ($missingValidation['tables']['timesheets']['changed'] ?? 0) > 0,
        'Cross-page alias must fail when its canonical source fact disappeared');
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
    check(abs((float) ($excludedValidation['hour_reconciliation']['difference_hours'] ?? NAN)) < 0.000001,
        'Imported source and mapped target sums must reconcile exactly');
    check($excludedValidation['ok']
        && ($excludedValidation['tables']['timesheets']['excluded'] ?? 0)===1
        && ($excludedValidation['exclusions']['rate_id_null_hours'] ?? 0)===3.25,
        'Validate must separately reconcile excluded facts');
    $excludedMappingRun = $store->beginRun('timesheets','migrate');
    $store->saveMapping($excludedMappingRun, 'timesheets', 'timesheets', '4999', (int) $staff->id, [
        'source'=>$paged->fixture['timesheets'][array_key_last($paged->fixture['timesheets'])],
        'resolution'=>['version'=>3, 'client_member_id'=>(int)$staff->client_member_id],
    ]);
    check(runFixture($paged, true)['conflicts'] > 0,
        'Previously mapped null-rate fact must block a fresh dry-run');
    check(!$paged->validate(1)['ok'], 'Validate must reject previously mapped excluded facts');
    \Illuminate\Support\Facades\DB::table('migration_mappings')
        ->where('service','timesheets')->where('entity_type','timesheets')
        ->where('legacy_id','4999')->delete();
    $store->finishRun($excludedMappingRun, 'completed');
    // Restore the smaller fixture for subsequent negative-path checks.
    $legacyRun = $store->beginRun('timesheets','migrate');
    $store->saveMapping($legacyRun,'timesheets','members','legacy-only',1,
        ['resolution'=>['version'=>2,'project_member_id'=>1]]);
    $result = runFixture($timesheets,true);
    check(($result['conflicts'] ?? 0) > 0 && ($result['preflight'] ?? '') === 'legacy_resolutions_need_reconciliation',
        'Old Timesheets mappings are reported as row-level dry-run conflicts without target writes');
    $blocked = false;
    try { runFixture($timesheets,false); }
    catch (RuntimeException $e) { $blocked = str_contains($e->getMessage(),'TIMESHEETS_LEGACY_MAPPINGS_INCOMPATIBLE'); }
    check($blocked,'Old Timesheets mappings still forbid data writes');
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
    // Optional staging-only 64k fact stress: exercise full import, replay and
    // reconciliation without real people, network or production connections.
    // Baseline Docker build keeps this OFF; opt in explicitly from PG CI.
    $volumeCount = (int) (getenv('MIGRATION_CLIENT_MEMBER_VOLUME_ROWS') ?: 0);
    if ($volumeCount > 0) {
        check($volumeCount >= 1000 && $volumeCount <= 64000,
            'Synthetic volume must be within [1000, 64000]');
        $volume = new MemberPagedTimesheetsFixture($store);
        $volume->fixture = $timesheets->fixture;
        $volume->fixture['rates'] = array_slice($volume->fixture['rates'], 0, 3);
        $volume->fixture['timesheets'] = array_slice($volume->fixture['timesheets'], 0, 3);
        $days = array_merge(range(1, 4), range(7, 20));
        for ($i = 0; $i < $volumeCount; ++$i) {
            $bucket = $i % (count($days) * 2);
            $volume->fixture['timesheets'][] = [
                'id'=>300000 + $i, 'rate_id'=>$bucket % 2 ? 92 : 91,
                'date'=>sprintf('2026-01-%02d', $days[intdiv($bucket, 2)]),
                'hours'=>'0.01', 'description'=>'Synthetic work '.($i + 1),
                'is_submitted'=>false,
            ];
        }
        $started = microtime(true);
        check(runFixture($volume, true)['conflicts']===0,
            'Large synthetic legacy dry-run has no unresolved facts');
        $before = target('timesheets')->table('timesheet_entries')->count();
        check(runFixture($volume, false)['conflicts']===0,
            'Large synthetic import must complete');
        $created = target('timesheets')->table('timesheet_entries')->count();
        check(runFixture($volume, false)['conflicts']===0
            && target('timesheets')->table('timesheet_entries')->count()===$created,
            'Large synthetic rerun must not duplicate grouped target facts');
        $reconciliation = $volume->validate(1);
        check($reconciliation['ok']
            && ($reconciliation['tables']['timesheets']['source'] ?? 0)===$volumeCount + 3
            && abs((float)($reconciliation['hour_reconciliation']['difference_hours'] ?? NAN))<0.000001,
            'Large synthetic Validate must reconcile every source fact and hour');
        printf("ClientMember synthetic volume: source_facts=%d, groups_added=%d, elapsed_seconds=%.2f, peak_memory_mb=%.1f\\n",
            $volumeCount, $created - $before, microtime(true) - $started,
            memory_get_peak_usage(true) / 1048576);
    }
    echo "ClientMember legacy import smoke passed.\n";
} catch (Throwable $e) { $failure=$e; }
finally { @unlink($path); @unlink($path.'.identity'); @unlink($path.'-wal'); @unlink($path.'-shm'); }
if ($failure) { fwrite(STDERR,$failure->__toString()."\n"); exit(1); }
