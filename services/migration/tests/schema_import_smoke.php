<?php
// Offline domain import integration. All identities, amounts and records are synthetic.
$path = '/tmp/schema-import-'.getmypid().'.sqlite'; touch($path);
putenv('MIGRATION_VACATIONS_FILES_PATH='.$path.'.files');
putenv('MIGRATION_IDENTITY_DATABASE='.$path.'.identity');putenv('MIGRATION_METADATA_DATABASE='.$path);
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);

class SyntheticDocuments extends \App\Migration\Core\LegacyVacationDocuments {
    public static int $downloads = 0;
    private array $paths = [];
    public function __destruct() { foreach($this->paths as $path) @unlink($path); }
    public function stage(string $key,string $url): array {
        self::$downloads++;
        $path = tempnam(sys_get_temp_dir(),'synthetic-pdf-');
        $this->paths[]=$path;
        file_put_contents($path,"%PDF-1.4\nSynthetic test document\n%%EOF");
        return ['path'=>$path,'size'=>filesize($path),'mime'=>'application/pdf'];
    }
}
class SyntheticVacations extends \App\Migration\Services\VacationsV2Migration {
    public array $fixture = [];
    private ?SyntheticDocuments $fakeDocuments = null;
    protected function documents(): \App\Migration\Core\LegacyVacationDocuments { return $this->fakeDocuments ??= new SyntheticDocuments(); }
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
        'partner_specialists'=>'full_name',
        'project_members'=>'project_id specialist_id partner_specialist_id specialist_name',
        'member_terms'=>'project_member_id technology level hourly_rate hours_per_day valid_from valid_to',
        'leads'=>'name source responsible_employee_id status converted_client_id',
        'contact_people'=>'full_name',
        'contact_relations'=>'contact_person_id entity_type entity_id relation_role active',
        'client_legal_entities'=>'client_id name full_name inn ogrn kpp registration_date okpo oktmo address',
        'client_requests'=>'client_id title description responsible_employee_id request_date deadline lifetime_weeks status',
        'positions'=>'client_request_id technology level description direction_department_id quantity status',
        'connection_attempts'=>'position_id specialist_id specialist_name is_external status closed_from_status failure_reasons description cv_sent_at connection_date closed_at control_date proposed_rate',
        'reporting_periods'=>'client_id period_start period_end status confirmed_hours timesheets_sent_at timesheets_approved_at act_sent_at act_approved_at paid_at',
    ] as $name=>$columns) table('clients',$name,$columns);
    table('vacations','absences','employee_id type starts_on ends_on calendar_days status created_by_subject');
    table('vacations','absence_audit_log','absence_id event actor_subject after');
    table('vacations','absence_approvals','absence_id sequence stage status required_role approver_employee_id approver_subject acted_by_subject acted_at comment');
    table('vacations','absence_attachments','absence_id kind original_name source_original_name mime_type size_bytes storage_path external_url uploaded_by_subject uploaded_by_employee_id');
    table('vacations','absence_status_history','absence_id to_status actor_subject reason context');
    table('timesheets','timesheet_entries','employee_id client_id project_id account_employee_id work_date hours description', ['employee_id','project_id','work_date']);

    $deferredClientsTables = config('migration.deferred_tables.clients');
    config(['migration.deferred_tables.clients' => []]); // Existing rate path stays covered for future re-enablement.
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
    $originalEmployee=$store->mapping('clients','users','13');
    \Illuminate\Support\Facades\DB::table('migration_mappings')->where(['service'=>'clients','entity_type'=>'users','legacy_id'=>'13'])->update(['target_id'=>999]);
    verify(runImport($clients,true)['conflicts']>0,'Previously imported identity cannot silently rebind');
    verify(\Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id',$store->latestRun('clients','dry-run')['id'])->where('code','IDENTITY_MAPPING_COLLISION')->exists(),'Imported identity disagreement is explicit');
    \Illuminate\Support\Facades\DB::table('migration_mappings')->where(['service'=>'clients','entity_type'=>'users','legacy_id'=>'13'])->update(['target_id'=>$originalEmployee]);
    $partnersBase=$clients->fixture;
    $clients->fixture['subcontracts']=[['id'=>61,'surname'=>'Synthetic','name'=>'Partner']];
    $clients->fixture['members'][]=['id'=>32,'memberable_type'=>'subcontract','memberable_id'=>61,'project_id'=>7,'client_id'=>6];
    verify(runImport($clients,true)['conflicts']===0 && db('clients')->table('partner_specialists')->count()===0,'Partner dry run creates no identities');
    verify(runImport($clients,false)['conflicts']===0,'Partner member imports from explicit subcontract identity');
    $partner=db('clients')->table('project_members')->whereNotNull('partner_specialist_id')->first();
    verify($partner && $partner->specialist_id===null && $partner->specialist_name==='Synthetic Partner','Partner is separate from Employees');
    verify(runImport($clients,false)['conflicts']===0 && db('clients')->table('partner_specialists')->count()===1,'Partner import repeat reuses source mapping');
    verify($clients->validate(1)['ok'],'Partner identity reconciliation succeeds');
    $clients->fixture['members'][1]['memberable_type']='unknown';
    verify(runImport($clients,true)['conflicts']>0,'Unknown polymorphic type never guesses partner');
    $clients->fixture=$partnersBase;
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
    $overlap=\Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id',$store->latestRun('clients','dry-run')['id'])->where('code','SOURCE_PERIOD_OVERLAP')->first();
    verify($overlap && json_decode($overlap->context,true)['overlapping_period']['legacy_id']==='41','Overlap diagnostics identify both source intervals');
    array_pop($clients->fixture['rates']);

    // Realistic legacy codes, multiple negative reasons, diagnostics and dry-run counters.
    $base = $clients->fixture;
    $fieldDiagnostics = SyntheticClients::attemptFields(['id'=>12,'status'=>'legacy_unknown','is_success'=>false,'closed_at'=>null,'rate'=>'synthetic-secret','custom_outcome'=>'unknown']);
    verify($fieldDiagnostics['is_success']['filled'] && $fieldDiagnostics['is_success']['value']==='false', 'False is a filled source value');
    verify(!$fieldDiagnostics['closed_at']['filled'] && $fieldDiagnostics['rate']['value']==='заполнено; значение скрыто', 'Null and hidden fields distinguish presence without leaking rates');
    config(['migration.clients_technology_names.555' => 'Synthetic confirmed technology']);
    $clients->fixture['positions'][0]['technology_id'] = 555;
    verify(runImport($clients,false)['conflicts']===0, 'Confirmed catalog resolves absent source technology');
    verify(db('clients')->table('positions')->value('technology')==='Synthetic confirmed technology', 'Technology belongs to position');
    verify(!array_key_exists('technology', (array) db('clients')->table('connection_attempts')->first()), 'Attempt has no technology field');
    foreach ([null, ''] as $missingTechnology) {
        $clients->fixture['positions'][0]['technology_id']=$missingTechnology;
        verify(runImport($clients,true)['conflicts']===0,'Empty legacy technology no longer blocks position or its attempts');
        verify(runImport($clients,false)['conflicts']===0,'Position without technology imports');
        verify(db('clients')->table('positions')->value('technology')===null && db('clients')->table('connection_attempts')->count()===1,'Absent technology stays NULL and attempt remains linked');
        verify(\Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id',$store->latestRun('clients','migrate')['id'])->where('code','MISSING_POSITION_TECHNOLOGY')->where('severity','warning')->exists(),'Missing technology is visible as warning');
    }
    $clients->fixture['positions'][0]['technology_id']=999999;
    verify(runImport($clients,true)['conflicts']===2,'Unknown nonempty technology still blocks position and dependent attempt');
    $clients->fixture = $base;
    runImport($clients,false);
    foreach (['new'=>'Новый лид','initial'=>'Первичный контакт','clarification'=>'Уточнение потребностей','ignore'=>'Клиент в игноре','failed'=>'Сделка закрыта - Отказ','active'=>'Активные переговоры','proposal_sent'=>'КП отправлено'] as $code=>$label) {
        $clients->fixture['leads'] = [['id'=>90,'title'=>'Synthetic Lead','source'=>null,'responsible_id'=>13,'status'=>$code,'client_id'=>null]];
        verify(runImport($clients,true)['conflicts']===0, 'Legacy lead status preflight: '.$code);
        verify(runImport($clients,false)['conflicts']===0 && db('clients')->table('leads')->value('status')===$label, 'Lead status semantics preserved: '.$code);
    }
    foreach (['under_consideration','waiting','no_candidates'] as $status) {
        $clients->fixture['positions'][0]['status']=$status;
        verify(runImport($clients,false)['conflicts']===0 && db('clients')->table('positions')->value('status')==='Открыт','Legacy active position remains open: '.$status);
    }
    $clients->fixture['positions'][0]['status']='open';
    $clients->fixture['attempts'][0]['closed_at']='2026-01-15 12:00:00';
    $clients->fixture['results']=[['id'=>71,'title'=>'Запрос закрыт'],['id'=>72,'title'=>'CV: Нет ОС']];
    $clients->fixture['attempt_result']=[['id'=>81,'attempt_id'=>12,'result_id'=>71],['id'=>82,'attempt_id'=>12,'result_id'=>72]];
    verify(runImport($clients,false)['conflicts']===0,'Multiple failure reasons import as one failed outcome');
    $attempt=db('clients')->table('connection_attempts')->first();
    verify($attempt->status==='Закрыт: неудача' && json_decode($attempt->failure_reasons,true)===['Запрос закрыт','CV: Нет ОС'],'Failure reasons retained in ordinary target field');
    $savedLinks=$clients->fixture['attempt_result'];$clients->fixture['attempt_result']=[];
    foreach ([
        [null, null, null, 'Новая'],
        ['2026-01-12 10:00:00', null, null, 'CV отправлено'],
        ['2026-01-12 10:00:00', '2026-01-13 11:00:00', null, 'Интервью пройдено'],
        ['2026-01-12 10:00:00', '2026-01-13 11:00:00', '2026-01-14', null],
    ] as [$cv, $interview, $started, $failedStage]) {
        $clients->fixture['attempts'][0]['cv_sent_at']=$cv;
        $clients->fixture['attempts'][0]['interviewed_at']=$interview;
        $clients->fixture['attempts'][0]['started_at']=$started;
        verify(runImport($clients,true)['conflicts']===0,'Date fallback passes dry run');
        verify(runImport($clients,false)['conflicts']===0,'Closed attempt without result imports by approved dates');
        $attempt=db('clients')->table('connection_attempts')->first();
        verify($attempt->status===($started ? 'Закрыт: успех' : 'Закрыт: неудача') && $attempt->closed_from_status===$failedStage,'Outcome and failure stage follow source milestones');
        verify($attempt->connection_date===$started && $attempt->cv_sent_at===$cv,'Milestone values are preserved');
        verify($started ? $attempt->failure_reasons===null : json_decode($attempt->failure_reasons,true)===['Причина не указана в старом сервисе'],'Fallback does not invent a rejection reason');
        $warning=\Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id',$store->latestRun('clients','migrate')['id'])->where('code','INFERRED_ATTEMPT_OUTCOME')->first();
        verify($warning && json_decode($warning->context,true)['attempt_fields']['closed_at']['filled'],'Inferred outcome is explained with source fields');
        $clients->fixture['attempts'][0]['closed_at']=null;
        verify(runImport($clients,false)['conflicts']===0,'Open milestone remains open');
        verify(db('clients')->table('connection_attempts')->value('status')===($started ? 'Ожидает подключения' : $failedStage),'Dates alone do not close an open attempt');
        $clients->fixture['attempts'][0]['closed_at']='2026-01-15 12:00:00';
    }
    $clients->fixture['attempts'][0]['cv_sent_at']=null;
    $clients->fixture['attempts'][0]['interviewed_at']=null;
    $clients->fixture['attempts'][0]['started_at']=null;
    foreach (['success'=>'Закрыт: успех','Успех'=>'Закрыт: успех','failed'=>'Закрыт: неудача','fail'=>'Закрыт: неудача','Неуспех'=>'Закрыт: неудача','Закрыт: неуспех'=>'Закрыт: неудача','Закрыт: неудача'=>'Закрыт: неудача'] as $sourceStatus=>$targetStatus) {
        $clients->fixture['attempts'][0]['status']=$sourceStatus;
        verify(runImport($clients,false)['conflicts']===0,'Explicit terminal status imports without result: '.$sourceStatus);
        $attempt=db('clients')->table('connection_attempts')->first();
        verify($attempt->status===$targetStatus,'Explicit source outcome is authoritative');
        verify($targetStatus==='Закрыт: успех' ? $attempt->failure_reasons===null : json_decode($attempt->failure_reasons,true)===['Причина не указана в старом сервисе'],'Empty success result and dedicated unknown failure reason');
    }
    $clients->fixture['attempts'][0]['status']='success';
    $inspection=$clients->inspect(1);
    verify($inspection['legacy_values']['attempts.status']===['success'] && in_array('status',$inspection['legacy_fields']['attempts'],true),'Inspect exposes separate legacy outcome field and values');
    $clients->fixture['attempts'][0]['closed_at']=null;
    verify(runImport($clients,false)['conflicts']===0 && db('clients')->table('connection_attempts')->value('closed_at')===null,'Explicit success does not fabricate missing closure date');
    $clients->fixture['attempts'][0]['closed_at']='2026-01-15 12:00:00';
    $clients->fixture['attempt_result']=$savedLinks;
    verify(runImport($clients,true)['conflicts']===1,'Source success and failure reasons are a collision, never overwritten');
    unset($clients->fixture['attempts'][0]['status']);
    $clients->fixture['attempt_result']=[];
    $clients->fixture['attempts'][0]['started_at']='2026-01-14';
    verify(runImport($clients,false)['conflicts']===0 && db('clients')->table('connection_attempts')->value('status')==='Закрыт: успех','Closed started attempt succeeds without result');
    $clients->fixture['attempts'][0]['status']='failed';
    verify(runImport($clients,false)['conflicts']===0 && db('clients')->table('connection_attempts')->value('closed_from_status')==='Ожидает подключения','Explicit failure overrides started date and retains connection stage');
    unset($clients->fixture['attempts'][0]['status']);
    $clients->fixture['attempts'][0]['started_at']=null;
    $clients->fixture['attempt_result']=$savedLinks;
    $clients->fixture['results'][1]['title']='Успех';
    verify(runImport($clients,true)['conflicts']===1,'Contradictory outcomes remain blocked');
    $clients->fixture['results'][1]['title']='Synthetic unknown result';
    verify(runImport($clients,true)['conflicts']===1,'Unknown result never guessed');
    $error=\Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id',$store->latestRun('clients','dry-run')['id'])->where('entity_type','attempts')->first();
    $context=json_decode($error->context,true);
    verify($error->code==='UNKNOWN_ATTEMPT_RESULT' && $context['attempt_fields']['closed_at']['filled'] && $context['result_count']===2,'Unresolved results still expose complete diagnostics');
    $clients->fixture=$base;
    runImport($clients,true);
    $data=$store->run($store->latestRun('clients','dry-run')['id']);
    verify($data['ready_count']>0 && $data['success_count']===0,'Dry run counts ready rows without claiming import');
    $users=collect($data['tables'])->firstWhere('table_name','users');
    verify((int)$users['ready_count']===1 && (int)$users['processed_count']===1,'User matching counted exactly once');
    $contacts=collect($data['tables'])->firstWhere('table_name','contacts');
    verify((int)$contacts['ready_count']===1 && (int)$contacts['processed_count']===1 && (int)$contacts['warning_count']===1,'Partially preserved contact counted once');
    foreach ([['rate',null,'MISSING_AMOUNT'],['rate','encrypted:synthetic','NON_NUMERIC_AMOUNT'],['rate',base64_encode(json_encode(['iv'=>'synthetic','value'=>'synthetic','mac'=>'synthetic'])),'ENCRYPTED_AMOUNT'],['rate','1.001','INVALID_AMOUNT'],['workload',25,'INVALID_AMOUNT'],['grade',null,'MISSING_GRADE'],['technology_id',null,'MISSING_REFERENCE']] as [$field,$value,$reason]) {
        $clients->fixture=$base;$clients->fixture['rates'][0][$field]=$value;
        verify(runImport($clients,true)['conflicts']===1, 'Invalid rate row conflicts: '.$field);
        $error=\Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id',$store->latestRun('clients','dry-run')['id'])->where('entity_type','rates')->first();
        verify($error->code===$reason && isset(json_decode($error->context,true)['field']),'Field-specific safe diagnostic: '.$field);
        verify(!str_contains($error->context,'encrypted:synthetic'),'Raw commercial amount omitted from diagnostics');
    }
    $clients->fixture=$base;$clients->fixture['users'][0]['email']='synthetic.missing@example.invalid';
    runImport($clients,true);
    $data=$store->run($store->latestRun('clients','dry-run')['id']);
    verify($data['blocked_count']>0,'Dependent failures classified separately from root causes');
    verify(\Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id',$data['id'])->where('code','DEPENDENCY_BLOCKED')->count()>0,'Dependency report saved');
    $clients->fixture=$base;

    // The production default defers ALL rate domain writes, including proposed rates.
    config(['migration.deferred_tables.clients' => $deferredClientsTables]);
    $clients->fixture=$base;
    $termsBefore=db('clients')->table('member_terms')->get()->toJson();
    $clients->fixture['rates'][0]=['id'=>141,'member_id'=>999,'technology_id'=>null,'grade'=>null,'rate'=>'encrypted:synthetic','workload'=>'encrypted:synthetic','start_date'=>'invalid','end_date'=>null];
    $clients->fixture['attempts'][0]['rate']='encrypted:synthetic';
    $existingAttemptId=$store->mapping('clients','attempts','12');
    db('clients')->table('connection_attempts')->where('id',$existingAttemptId)->update(['proposed_rate'=>'321.50']);
    $clients->fixture['attempts'][]=$clients->fixture['attempts'][0];$clients->fixture['attempts'][1]['id']=112;
    verify(runImport($clients,true)['conflicts']===0,'Deferred invalid/encrypted rates do not block dry run');
    verify(runImport($clients,false)['conflicts']===0,'Deferred rates do not block actual core import');
    verify(db('clients')->table('member_terms')->get()->toJson()===$termsBefore && $store->mapping('clients','rates','141')===null,'Deferral creates no conditions/mappings and preserves earlier target conditions');
    verify(db('clients')->table('connection_attempts')->where('id',$existingAttemptId)->value('proposed_rate')==='321.50','Deferral does not erase an existing proposed rate');
    verify(db('clients')->table('connection_attempts')->where('id',$store->mapping('clients','attempts','112'))->value('proposed_rate')===null,'Deferred proposed rate remains null on a new attempt, never zero');
    $validation=$clients->validate(1);
    verify($validation['ok'] && !isset($validation['tables']['rates']) && $validation['deferred'][0]['entity']==='rates','Validation excludes deferred rates and explicitly reports deferral');
    $rateProgress=collect($store->run($store->latestRun('clients','migrate')['id'])['tables'])->firstWhere('table_name','rates');
    verify((int)$rateProgress['success_count']===0 && (int)$rateProgress['warning_count']===1,'Deferred rates never claim successful domain import');
    config(['migration.deferred_tables.clients' => []]);
    $clients->fixture=$base;

    $periodDefaults=['client_id'=>6,'approval_at'=>null,'approved_at'=>null,'act_approval_at'=>null,'act_approved_at'=>null,'paid_at'=>null,'approved_hours'=>null];
    $previousPeriod=$periodDefaults+['id'=>201,'from'=>'2028-07-13','to'=>'2028-08-12'];
    $nextPeriod=$periodDefaults+['id'=>202,'from'=>'2028-08-10','to'=>'2028-09-12'];
    foreach ([[$previousPeriod,$nextPeriod],[$nextPeriod,$previousPeriod]] as $periodOrder) {
        $clients->fixture['reporting_periods']=$periodOrder;
        verify(runImport($clients,true)['conflicts']===0,'Adjacent monthly overlap aligns independently of extraction order');
    }
    verify(db('clients')->table('reporting_periods')->count()===0,'Period dry run writes no domain rows');
    verify(runImport($clients,false)['conflicts']===0,'Aligned monthly periods import');
    $nextTarget=$store->mapping('clients','reporting_periods','202');
    verify(db('clients')->table('reporting_periods')->where('id',$nextTarget)->value('period_start')==='2028-08-13','Only next period start shifts after prior closing day');
    verify(db('clients')->table('reporting_periods')->where('id',$nextTarget)->value('period_end')==='2028-09-12','Monthly alignment retains end date');
    verify(runImport($clients,false)['conflicts']===0 && db('clients')->table('reporting_periods')->count()===2,'Aligned period repeat is idempotent');
    $clients->fixture['reporting_periods']=[
        $periodDefaults+['id'=>203,'from'=>'2030-03-01','to'=>'2030-03-31'],
        array_replace($periodDefaults,['id'=>204,'from'=>'2030-03-01','to'=>'2030-03-31','approved_at'=>'2030-04-02 10:00:00','approved_hours'=>'synthetic-secret']),
    ];
    $clients->fixture['reporting_period_rate']=[['id'=>301,'reporting_period_id'=>204,'rate_id'=>41]];
    verify(runImport($clients,true)['conflicts']===1,'Identical reporting ranges remain blocked, never merged');
    $duplicate=\Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id',$store->latestRun('clients','dry-run')['id'])->where('code','SOURCE_PERIOD_OVERLAP')->first();
    $records=json_decode($duplicate->context,true)['period_records'];
    verify($records[0]['legacy_id']==='204' && $records[1]['legacy_id']==='203' && $records[0]['rate_link_count']===1,'Duplicate details identify both records and their linked rate counts');
    verify($records[0]['fields']['approved_at']['filled'] && $records[0]['fields']['approved_hours']['value']==='заполнено; значение скрыто','Duplicate lifecycle dates visible while financial data remains hidden');
    $clients->fixture['reporting_periods']=[
        $periodDefaults+['id'=>205,'from'=>'2031-04-01','to'=>'2031-04-30'],
        $periodDefaults+['id'=>206,'from'=>'2031-04-15','to'=>'2031-05-10'],
    ];
    $clients->fixture['reporting_period_rate']=[];
    verify(runImport($clients,true)['conflicts']===1,'Arbitrary partial overlaps without monthly anchor remain blocked');
    $clients->fixture=$base;

    $vacations = new SyntheticVacations($store);
    $vacations->fixture = array_fill_keys(config('migration.legacy.vacations.required_tables'), []);
    $vacations->fixture['users'] = [['id'=>4,'employee_id'=>'synthetic-employee-uuid','email'=>'synthetic.operator@example.invalid']];
    $vacations->fixture['vacations'] = [['id'=>8,'user_id'=>4,'type'=>'paid_vacation','status'=>'confirmed','from'=>'2026-01-10','to'=>'2026-01-12','working_hours'=>8]];
    $vacations->fixture['approvers'] = [['id'=>9,'vacation_id'=>8,'employee_id'=>'synthetic-employee-uuid','order'=>1,'is_approved'=>true]];
    verify(runImport($vacations,true)['conflicts']===0 && db('vacations')->table('absences')->count()===0,'Vacations v2 dry run is read-only');
    $result = runImport($vacations,false);
    verify($result['conflicts']===0 && $result['warnings']===0,'Approvals imported into assigned employee stages');
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
    $vacations->fixture['approvers'] = [
        ['id'=>9,'vacation_id'=>8,'employee_id'=>'synthetic-employee-uuid','order'=>1,'is_approved'=>true],
        ['id'=>22,'vacation_id'=>8,'employee_id'=>'synthetic-employee-uuid','order'=>2,'is_approved'=>false],
    ];
    $vacations->fixture['users'][0]['employee_id']='synthetic-employee-uuid';
    $vacations->fixture['vacations'][0]['type']='paid';
    $vacations->fixture['attachments'] = [['id'=>31,'attachmentable_type'=>'App\\Models\\Vacation','attachmentable_id'=>8,'url'=>'javascript:synthetic','name'=>'Synthetic application.pdf','mime'=>'application/pdf']];
    verify(config('migration.vacation_documents_enabled')===false && !in_array('attachments',config('migration.legacy.vacations.required_tables'),true),'Documents absent from the default source pipeline');
    verify(runImport($vacations,true)['conflicts']===0 && runImport($vacations,false)['conflicts']===0,'Disabled documents never block dry run or import');
    verify(SyntheticDocuments::$downloads===0 && db('vacations')->table('absence_attachments')->count()===0,'Disabled documents neither download nor write attachments');
    $validation=$vacations->validate(1);
    verify($validation['ok'] && !isset($validation['tables']['attachments']) && isset($validation['tables']['approvers']),'Validation excludes disabled documents and retains approvals');
    config(['migration.vacation_documents_enabled'=>true]);
    $vacations->fixture['attachments'][0]['url']='https://files.example.invalid/synthetic-application.pdf';
    verify(runImport($vacations,true)['conflicts']===0 && db('vacations')->table('absence_approvals')->count()===2,'Approval/document dry run does not write');
    verify(runImport($vacations,false)['conflicts']===0,'Approvals and documents imported');
    verify(db('vacations')->table('absence_approvals')->orderBy('sequence')->pluck('status')->all()===['approved','waiting'],'Order and known approval preserved; false is not rejection');
    verify(db('vacations')->table('absence_approvals')->whereNotNull('acted_at')->count()===0,'Approval timestamps never fabricated');
    verify(db('vacations')->table('absence_attachments')->value('external_url')==='https://files.example.invalid/synthetic-application.pdf','Original link preserved');
    $document = db('vacations')->table('absence_attachments')->first();
    verify(str_starts_with($document->original_name,'Заявление — ') && is_file(getenv('MIGRATION_VACATIONS_FILES_PATH').'/'.$document->storage_path),'Readable filename and stored file bytes');
    verify(runImport($vacations,false)['conflicts']===0 && db('vacations')->table('absence_approvals')->count()===2 && db('vacations')->table('absence_attachments')->count()===1,'Repeated import does not duplicate children');
    $vacations->fixture['attachments'][0]['url']='javascript:synthetic';
    verify(runImport($vacations,true)['conflicts']===1,'Unsafe document scheme blocks import');
    $vacations->fixture['attachments'][0]['url']='https://files.example.invalid/synthetic-application.pdf';
    db('vacations')->table('absence_approvals')->where('sequence',2)->update(['status'=>'approved']);
    verify(runImport($vacations,true)['conflicts']===1,'New decisions cannot be overwritten by repeat migration');
    db('vacations')->table('absence_approvals')->where('sequence',2)->update(['status'=>'waiting']);
    $vacations->fixture['approvers']=[];$vacations->fixture['attachments']=[];
    config(['migration.vacation_documents_enabled'=>false]);
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
    \Illuminate\Support\Facades\DB::table('migration_overrides')->insert(['service'=>'vacations','entity_type'=>'users','legacy_id'=>'4','target_id'=>'1','note'=>json_encode(['source_fingerprint'=>\App\Migration\Core\EmployeeUserOverrides::fingerprint($vacations->fixture['users'][0]),'target_login'=>'synthetic.operator','source_key'=>\App\Migration\Core\EmployeeLoginRegistry::sourceKey()]),'created_at'=>now(),'updated_at'=>now()]);
    $confirmedNote = \Illuminate\Support\Facades\DB::table('migration_overrides')->where('service','vacations')->value('note');
    \Illuminate\Support\Facades\DB::table('migration_overrides')->where('service','vacations')->update(['note'=>json_encode(['source_fingerprint'=>\App\Migration\Core\EmployeeUserOverrides::fingerprint($vacations->fixture['users'][0])])]);
    \App\Migration\Core\EmployeeLoginRegistry::upgradeExisting();
    verify(\App\Migration\Core\EmployeeUserOverrides::resolve($vacations->fixture['users'][0])===null,'Historical numeric ID alone cannot promote identity');
    \Illuminate\Support\Facades\DB::table('migration_overrides')->where('service','vacations')->update(['note'=>$confirmedNote]);
    \App\Migration\Core\EmployeeLoginRegistry::upgradeExisting();
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
finally { foreach(glob($path.'.files/attachments/*') ?: [] as $file) @unlink($file);@rmdir($path.'.files/attachments');@rmdir($path.'.files'); @unlink($path);@unlink($path.'.identity'); @unlink($path.'-wal'); @unlink($path.'-shm'); }
if ($failure) { fwrite(STDERR, $failure->__toString()."\n"); exit(1); }
