<?php

use App\Migration\Core\MigrationRegistry;
use App\Migration\Core\MigrationStore;
use App\Migration\Core\MigrationCredentialCipher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('migration:key-check', function (): int {
    try {
        app(MigrationCredentialCipher::class)->fingerprint();
        $this->line('Migration credential key available.');
        return 0;
    } catch (Throwable $e) {
        $this->error('Migration credential key is missing or invalid.');
        return 1;
    }
});

Artisan::command('migration:credentials-check {--allow-legacy}', function (): int {
    $cipher = app(MigrationCredentialCipher::class);
    $failed = false;
    foreach (DB::table('migration_connections')->get(['service', 'password_encrypted']) as $profile) {
        try {
            $cipher->decryptString((string) $profile->password_encrypted);
        } catch (Throwable $e) {
            $format = str_starts_with((string) $profile->password_encrypted, 'migration:v1:') ? 'migration:v1' : 'Laravel Crypt';
            if ($format === 'Laravel Crypt' && $this->option('allow-legacy')) {
                $this->warn('Legacy credentials for '.$profile->service.' need to be saved again before starting a run.');
                continue;
            }
            $this->error('Saved credentials cannot be decrypted for '.$profile->service.' ('.$format.').');
            $failed = true;
        }
    }
    if ($failed) return 1;
    $this->line('Saved migration credentials can be decrypted.');
    return 0;
});

// Read-only production contract check: validate columns/functions/permissions without returning identities.
Artisan::command('migration:employee-query-check', function (): int {
    try {
        DB::connection('target_employees')->table('employees')
            ->whereRaw('1 = 0 AND LOWER(TRIM(login)) = ?', ['synthetic.migration.contract'])
            ->get(['id', 'login', 'full_name']);
        $login = DB::connection('target_employees')->table('employees')->whereNotNull('login')->value('login');
        if ($login !== null) {
            try { \App\Migration\Core\EmployeeUserOverrides::match($login); }
            catch (DomainException $expected) { /* Missing/ambiguous identities are domain outcomes, not query failures. */ }
        }
        $this->line('Employees matching query contract OK (no employee data printed).');
        return 0;
    } catch (Throwable $error) {
        $this->error(json_encode(['check' => 'employees_matching_query', 'exception_type' => get_class($error),
            'sqlstate' => \App\Migration\Core\EmployeeMappingFailure::sqlState($error),
            'category' => \App\Migration\Core\EmployeeMappingFailure::category($error)]));
        return 1;
    }
});

Artisan::command('legacy:list', function (): void {
    $registry = app(MigrationRegistry::class);
    $this->line('Available legacy migration modules:');
    foreach ($registry->keys() as $key) {
        $this->line(' - '.$key);
    }
});

Artisan::command('legacy:inspect {service}', function (string $service): int {
    $store = app(MigrationStore::class);
    $runId = $store->beginRun($service, 'inspect');
    try {
        $result = app(MigrationRegistry::class)->get($service)->inspect($runId);
        $store->finishRun($runId, 'completed', $result);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return 0;
    } catch (Throwable $e) {
        $store->finishRun($runId, 'failed', [], $e->getMessage());
        $this->error($e->getMessage());
        return 1;
    }
});

Artisan::command('legacy:migrate {service} {--dry-run}', function (string $service): int {
    $dryRun = (bool) $this->option('dry-run');
    $mode = $dryRun ? 'dry-run' : 'migrate';
    $store = app(MigrationStore::class);
    $runId = $store->beginRun($service, $mode);
    try {
        $result = app(MigrationRegistry::class)->get($service)->migrate($runId, $dryRun);
        $store->finishRun($runId, 'completed', $result);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return 0;
    } catch (Throwable $e) {
        $store->finishRun($runId, 'failed', [], $e->getMessage());
        $this->error($e->getMessage());
        return 1;
    }
});

Artisan::command('legacy:validate {service}', function (string $service): int {
    $store = app(MigrationStore::class);
    $runId = $store->beginRun($service, 'validate');
    try {
        $result = app(MigrationRegistry::class)->get($service)->validate($runId);
        $ok = (bool) ($result['ok'] ?? false);
        $store->finishRun($runId, $ok ? 'completed' : 'conflicts', $result);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $ok ? 0 : 2;
    } catch (Throwable $e) {
        $store->finishRun($runId, 'failed', [], $e->getMessage());
        $this->error($e->getMessage());
        return 1;
    }
});

// Explicit operator reconciliation; never guess a numeric cross-database identity.
Artisan::command('migration:timesheets-member-map {legacy_member} {target_member}', function (string $legacy_member, string $target_member): int {
    if (!ctype_digit($legacy_member) || !ctype_digit($target_member) || (int) $target_member < 1) {
        $this->error('Numeric legacy member and target ClientMember IDs required.'); return 1;
    }
    $store = app(MigrationStore::class);
    foreach (array_keys(config('migration.modules', [])) as $service) {
        if ($store->hasActiveRun($service)) { $this->error('An operation is active.'); return 1; }
    }
    if (!\Illuminate\Support\Facades\DB::connection('target_clients')->table('client_members')->where('id', (int) $target_member)->exists()) {
        $this->error('Target Clients connection does not exist.'); return 1;
    }
    try {
        \Illuminate\Support\Facades\DB::transaction(function () use ($legacy_member, $target_member, $store): void {
            \Illuminate\Support\Facades\DB::table('migration_connections')->where('service','timesheets')->update(['updated_at'=>\Illuminate\Support\Facades\DB::raw('updated_at')]);
            foreach (array_keys(config('migration.modules', [])) as $service) if ($store->hasActiveRun($service)) throw new RuntimeException('An operation is active.');
            foreach (['/ops/console.json'=>'status','/ops/status.json'=>'state'] as $path=>$field) {
                if (is_file($path)) {
                    $state = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                    if (in_array(($state['operation'] ?? $state)[$field] ?? '', ['queued','running'], true)) throw new RuntimeException('A checkpoint/console operation is active.');
                }
            }
            \Illuminate\Support\Facades\DB::table('migration_overrides')->updateOrInsert(
                ['service' => 'timesheets', 'entity_type' => 'members', 'legacy_id' => $legacy_member],
                ['target_id' => $target_member, 'note' => 'Explicit operator ClientMember mapping; staff identity or partner type checked by importer', 'created_at' => now(), 'updated_at' => now()],
            );
        });
    } catch (Throwable $e) { $this->error($e->getMessage()); return 1; }
    $this->info('ClientMember mapping saved. Repeat Dry run; source identity is checked by importer.'); return 0;
});

// Called only by the host coordinator while it owns the exclusive console operation.
Artisan::command('migration:console-enqueue {service} {mode} {operation} {snapshot} {--control}', function (string $service, string $mode, string $operation, string $snapshot): int {
    try {
        $state = json_decode(file_get_contents('/ops/console.json'), true, 512, JSON_THROW_ON_ERROR);
        $owner = $state['operation'] ?? [];
        $control = (bool) $this->option('control');
        if ($control && ($owner['control_mode'] ?? false) !== true) throw new RuntimeException('Control mode requires a control operation owner.');
        if (($owner['id'] ?? '') !== $operation || ($owner['status'] ?? '') !== 'running'
            || ! in_array($service, $owner['services'] ?? [], true)
            || ! in_array($mode, ['inspect', 'dry-run', 'migrate', 'validate'], true)
            || ($owner['snapshot_ids'][$service] ?? '') !== $snapshot) {
            throw new RuntimeException('Нет действующего владельца операции.');
        }
        app(MigrationCredentialCipher::class)->fingerprint();
        $store = app(MigrationStore::class);
        $profile = app(\App\Migration\Core\ConnectionProfileStore::class)->publicProfile($service);
        if (! $profile || ! $profile['verified_at'] || $profile['credential_status'] !== 'ready') {
            throw new RuntimeException('Подключение не проверено.');
        }
        if ($mode === 'migrate') {
            $dry = $store->latestRun($service, 'dry-run');
            if (! $dry || !in_array($dry['status'], $control ? ['completed', 'conflicts', 'partial'] : ['completed'], true) || (!$control && $dry['conflict_count'] > 0)
                || $dry['started_at'] < $profile['verified_at']) {
                throw new RuntimeException('Dry run содержит ошибки или устарел; перенос заблокирован.');
            }
        }
        $id = $store->queueRun($service, $mode, (string) ($owner['requested_by'] ?? 'platform-admin'), $operation);
        $store->event($id, 'console', 'Запуск из пульта переноса.', 'info', ['operation_id' => $operation, 'snapshot_id' => $snapshot !== '' ? $snapshot : null]);
        try { \App\Migration\Jobs\RunMigrationJob::dispatch($id, $service, $mode, null, $control); }
        catch (Throwable $e) { $store->finishRun($id, 'failed', [], 'Не удалось поставить этап в очередь.'); throw $e; }
        $this->line(json_encode(['run_id' => $id]));
        return 0;
    } catch (Throwable $e) { $this->error($e->getMessage()); return 1; }
});

Artisan::command('migration:identity-upgrade', function () {
    try {
        \App\Migration\Core\EmployeeLoginRegistry::upgradeExisting();
        $this->info('Persistent employee login registry ready (no identity data printed).');
        return 0;
    } catch (\Throwable $e) {
        $this->error('Persistent identity registry upgrade failed: '.get_class($e));
        return 1;
    }
});

// Deployment contract only: no legacy downloads or employee/document rows read.
Artisan::command('migration:document-storage-check', function () {
    try {
        $directory = getenv('MIGRATION_VACATIONS_FILES_PATH') ?: '/vacation-files';
        if (!is_dir($directory) || !is_writable($directory)) throw new RuntimeException('Document volume unavailable');
        \Illuminate\Support\Facades\DB::connection('target_vacations')->table('absence_attachments')
            ->select(['storage_path', 'external_url', 'source_original_name'])->whereRaw('1 = 0')->get();
        $this->info('Vacations document storage and schema contract ready (no document data read).');
        return 0;
    } catch (\Throwable $e) {
        $this->error('Vacations document contract failed: '.get_class($e));
        return 1;
    }
});

// Coordinator-only complete export; identity is checked before reading restored metadata.
Artisan::command('migration:control-report {run} {operation}', function (string $run, string $operation): int {
    try {
        if (!ctype_digit($run) || !ctype_digit($operation)) throw new RuntimeException('Invalid report identity.');
        $report = app(\App\Migration\Core\ControlReport::class)->export((int) $run, $operation, 256);
        $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return 0;
    } catch (Throwable $e) {
        // This command is called by migration-ops. Never echo exception messages:
        // SQL, legacy row values and credentials can be present in them.
        // The deterministic category and source line help diagnose export
        // failures without placing private data in the operation report.
        $type = preg_replace('/[^A-Za-z0-9_]/', '', class_basename($e));
        $file = preg_replace('/[^A-Za-z0-9_.-]/', '', basename($e->getFile()));
        $code = is_int($e->getCode()) ? $e->getCode() : 0;
        $this->error('CONTROL_REPORT_FAILURE|'.$type.'|'.$code.'|'.$file.'|'.$e->getLine());
        return 1;
    }
});


// Coordinator-only complete, sanitized row archive. Each row is one bounded
// JSON line; never construct a 60k-row PHP array or print raw source fields.
Artisan::command('migration:control-report-items {run} {operation}', function (string $run, string $operation): int {
    try {
        if (!ctype_digit($run) || !ctype_digit($operation)) throw new RuntimeException('Invalid report identity.');
        $count = 0;
        foreach (app(\App\Migration\Core\ControlReport::class)->streamDiagnosticItems((int) $run, $operation) as $item) {
            echo json_encode(['item'=>$item], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            $count++;
        }
        echo json_encode(['end'=>$count,'operation_id'=>$operation,'run_id'=>(int)$run],JSON_THROW_ON_ERROR)."\n";
        return 0;
    } catch (Throwable $e) {
        $type = preg_replace('/[^A-Za-z0-9_]/', '', class_basename($e));
        $file = preg_replace('/[^A-Za-z0-9_.-]/', '', basename($e->getFile()));
        $this->error('CONTROL_REPORT_FAILURE|'.$type.'|0|'.$file.'|'.$e->getLine());
        return 1;
    }
});


// Explicit opt-in: Clients and Timesheets domain data are disposable during the
// legacy reimport. Never touch Employees/Vacations, migration run history or
// source databases. PostgreSQL RESTRICT prevents destructive FK cascades.
Artisan::command('migration:reset-disposable-clients-timesheets
    {--execute : Actually discard target-domain rows and current mappings}
    {--ack= : Must be DISCARD_CLIENTS_TIMESHEETS to execute}', function (): int {
    $services = ['timesheets', 'clients'];
    $store = app(MigrationStore::class);
    foreach (array_keys(config('migration.modules', [])) as $service) {
        if ($store->hasActiveRun($service)) {
            $this->error('RESET_BLOCKED: another migration operation is active.');
            return 1;
        }
    }
    $plans = [];
    foreach ($services as $service) {
        $db = DB::connection('target_'.$service);
        $schema = $db->selectOne('SELECT current_schema() AS name')->name;
        if ($schema !== $service || !preg_match('/^[a-z_][a-z0-9_]*$/D', $schema)) {
            $this->error('RESET_BLOCKED: invalid target schema.');
            return 1;
        }
        $tables = $db->select("SELECT table_name FROM information_schema.tables
            WHERE table_schema = ? AND table_type = 'BASE TABLE'
              AND table_name NOT IN ('migrations')
            ORDER BY table_name", [$schema]);
        if (!$tables) {
            $this->error('RESET_BLOCKED: no target domain tables discovered for '.$service);
            return 1;
        }
        $plans[$service] = ['schema'=>$schema, 'tables'=>array_map(
            static fn ($row) => $row->table_name, $tables)];
    }
    $counts = [];
    foreach ($services as $service) {
        $counts[$service] = DB::table('migration_mappings')->where('service', $service)->count();
    }
    $this->line(json_encode(['scope'=>$plans, 'mapping_counts'=>$counts,
        'writes_requested'=>(bool)$this->option('execute')], JSON_UNESCAPED_SLASHES));
    if (!$this->option('execute')) {
        $this->warn('DRY RUN ONLY. No data or mappings changed.');
        return 0;
    }
    if ($this->option('ack') !== 'DISCARD_CLIENTS_TIMESHEETS') {
        $this->error('RESET_BLOCKED: explicit acknowledgment is required.');
        return 1;
    }
    // Read-only source databases and Employees/Vacations are never targeted.
    // Each target schema is truncated without CASCADE, so PostgreSQL rejects
    // any reference from another schema or an excluded bookkeeping table.
    try {
        foreach ($services as $service) {
            $db = DB::connection('target_'.$service);
            $schema = $plans[$service]['schema'];
            $qualified = array_map(static fn ($table) => '"'.$schema.'"."'.$table.'"',
                $plans[$service]['tables']);
            $db->transaction(static function () use ($db, $qualified): void {
                $db->statement('TRUNCATE TABLE '.implode(', ', $qualified).' RESTART IDENTITY RESTRICT');
            });
        }
        DB::transaction(function () use ($services, $store): void {
            foreach (array_keys(config('migration.modules', [])) as $service) {
                if ($store->hasActiveRun($service)) throw new \RuntimeException(
                    'RESET_BLOCKED: operation started during reset');
            }
            foreach (['migration_mappings','migration_overrides','migration_control_write_intents'] as $table) {
                if (!\Illuminate\Support\Facades\Schema::hasTable($table)) continue;
                DB::table($table)->whereIn('service', $services)->delete();
            }
        });
    } catch (\Throwable $error) {
        // A failure must never be advertised as a successful clean reset:
        // target schemas and metadata use independent transactions.
        $this->error('RESET_PARTIAL_OR_BLOCKED: '.get_class($error)
            .'. Do not start a new migration until the reset is repeated and verified.');
        return 1;
    }
    $this->info('RESET_COMPLETED: Clients/Timesheets targets and current mappings discarded.');
    return 0;
});
