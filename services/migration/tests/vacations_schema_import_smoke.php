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
    protected function deferredSourceTables(): array { return []; }
    protected function assertCheckpoint(): void {}
}
class SyntheticPagedTimesheets extends SyntheticTimesheets {
    public int $pagesRead = 0;
    public bool $mutateOnSecondPass = false;
    public bool $mutateOnReload = false;
    private int $extractCount = 0;
    protected function deferredSourceTables(): array { return ['timesheets']; }
    protected function timesheetsBatchSize(): int { return 11; }
    protected function extract(): array {
        if (++$this->extractCount === 2 && $this->mutateOnReload)
            $this->fixture['timesheets'][array_key_last($this->fixture['timesheets'])]['description'] = 'Changed after preflight';
        $this->resetSourceScan();
        $this->sourceTotals['timesheets'] = count($this->fixture['timesheets'] ?? []);
        $this->deferredSourceWatermarks['timesheets'] = max(array_column($this->fixture['timesheets'] ?? [], 'id') ?: [0]);
        $rows = $this->fixture;
        $rows['timesheets'] = [];
        return $rows;
    }
    protected function readTimesheetsPage(\App\Migration\Core\LegacyReader $reader, mixed $highWater, mixed $last, int $size): array {
        $this->pagesRead++;
        if ($this->mutateOnSecondPass && $this->pagesRead === 4) {
            $this->fixture['timesheets'][0]['description'] = 'Changed while import is running';
        }
        $page = array_values(array_filter($this->fixture['timesheets'], fn ($row) =>
            $row['id'] <= $highWater && ($last === null || $row['id'] > $last)));
        usort($page, fn ($a,$b) => $a['id'] <=> $b['id']);
        return array_map(fn ($row) => (object) $row, array_slice($page, 0, $size));
    }
}
class SyntheticLargeTimesheetsScanner extends \App\Migration\Services\TimesheetsMigration {
    public function scan(int $total): int {
        $this->resetSourceScan();
        $this->sourceTotals['timesheets'] = $total;
        $this->deferredSourceWatermarks['timesheets'] = $total;
        $count = 0;
        foreach ($this->sourceRows('timesheets') as $row) $count++;
        return $count;
    }
    protected function deferredSourceTables(): array { return ['timesheets']; }
    protected function timesheetsBatchSize(): int { return 1000; }
    protected function readTimesheetsPage(\App\Migration\Core\LegacyReader $reader, mixed $highWater, mixed $last, int $size): array {
        $from = ((int) $last) + 1;
        $lastId = min((int) $highWater, $from + $size - 1);
        $rows = [];
        for ($id=$from; $id <= $lastId; $id++) {
            $rows[] = (object) ['id'=>$id, 'rate_id'=>1, 'date'=>'2026-01-01',
                'hours'=>1, 'description'=>'Synthetic generated row'];
        }
        return $rows;
    }
    protected function import(): void {}
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
    foreach (['employees','vacations'] as $service) {
        config(['database.connections.target_'.$service => ['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]]);
        \Illuminate\Support\Facades\DB::purge('target_'.$service);
    }
    table('employees','employees','login full_name');
    db('employees')->table('employees')->insert(['id'=>1,'login'=>'synthetic.operator','full_name'=>'Synthetic Operator']);
    $store=app(\App\Migration\Core\MigrationStore::class);
    $seed=$store->beginRun('employees','migrate');
    $store->saveMapping($seed,'employees','employee','synthetic-employee-uuid',1);
    $store->finishRun($seed,'completed');
    table('vacations','absences','employee_id type starts_on ends_on calendar_days status created_by_subject');
    table('vacations','absence_audit_log','absence_id event actor_subject after');
    table('vacations','absence_approvals','absence_id sequence stage status required_role approver_employee_id approver_subject acted_by_subject acted_at comment');
    table('vacations','absence_attachments','absence_id kind original_name source_original_name mime_type size_bytes storage_path external_url uploaded_by_subject uploaded_by_employee_id');
    table('vacations','absence_status_history','absence_id to_status actor_subject reason context');
    $vacations = new SyntheticVacations($store);
    $vacations->fixture = array_fill_keys(config('migration.legacy.vacations.required_tables'), []);
    $vacations->fixture['users'] = [['id'=>4,'employee_id'=>'synthetic-employee-uuid','email'=>'synthetic.operator@example.invalid']];
    $vacations->fixture['vacations'] = [['id'=>8,'user_id'=>4,'type'=>'paid_vacation','status'=>'confirmed','from'=>'2026-01-10','to'=>'2026-01-12','working_hours'=>8]];
    $vacations->fixture['approvers'] = [
        ['id'=>9,'vacation_id'=>8,'employee_id'=>'synthetic-employee-uuid','order'=>1,'is_approved'=>true],
        ['id'=>22,'vacation_id'=>8,'employee_id'=>'synthetic-employee-uuid','order'=>2,'is_approved'=>false],
    ];
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
    foreach (['granted'=>'confirmed','not_approved'=>'rejected','approvers_definition'=>'planned','on_approval'=>'in_review'] as $legacy=>$target) {
        $vacations->fixture['vacations'][0]['status']=$legacy;
        verify(runImport($vacations,true)['conflicts']===0, 'Legacy status alias preflight: '.$legacy);
        verify(runImport($vacations,false)['conflicts']===0, 'Legacy status imports: '.$legacy);
        $absence=db('vacations')->table('absences')->first();
        verify($absence->type==='sick_leave' && $absence->status===$target, 'Stored type and status correspond');
        if ($legacy === 'on_approval') {
            verify(db('vacations')->table('absence_approvals')->where('sequence',2)->value('status')==='pending',
                'First unfinished legacy group must be the active stage');
            verify(runImport($vacations,false)['conflicts']===0 && db('vacations')->table('absences')->count()===1,
                'Repeated in-review import is idempotent');
        }
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
    verify(db('vacations')->table('absence_approvals')->orderBy('sequence')->pluck('status')->all()===['approved','pending'],'Historical active sequence is preserved; false is not a rejection');
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
    db('vacations')->table('absence_approvals')->where('sequence',2)->update(['status'=>'pending']);
    $vacations->fixture['vacations'][0]['status']='on_approval';
    $vacations->fixture['approvers'][1]['is_approved']=true;
    verify(runImport($vacations,true)['conflicts']===1,
        'Review with no unresolved approver must be a conflict, not an invented stage');
    $vacations->fixture['approvers'][1]['is_approved']=false;
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

    echo "Vacations v2 schema import smoke passed.\n";
} catch (Throwable $e) { $failure=$e; }
finally { foreach(glob($path.'.files/attachments/*') ?: [] as $file) @unlink($file);@rmdir($path.'.files/attachments');@rmdir($path.'.files'); @unlink($path);@unlink($path.'.identity'); @unlink($path.'-wal'); @unlink($path.'-shm'); }
if ($failure) { fwrite(STDERR, $failure->__toString()."\n"); exit(1); }
