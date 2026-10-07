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
        $this->error('Numeric legacy member and target project_member IDs required.'); return 1;
    }
    $store = app(MigrationStore::class);
    foreach (array_keys(config('migration.modules', [])) as $service) {
        if ($store->hasActiveRun($service)) { $this->error('An operation is active.'); return 1; }
    }
    if (!\Illuminate\Support\Facades\DB::connection('target_clients')->table('project_members')->where('id', (int) $target_member)->exists()) {
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
                ['target_id' => $target_member, 'note' => 'Explicit operator connection mapping; employee identity checked by importer', 'created_at' => now(), 'updated_at' => now()],
            );
        });
    } catch (Throwable $e) { $this->error($e->getMessage()); return 1; }
    $this->info('Member mapping saved. Repeat Dry run; source employee must still match.'); return 0;
});

// Called only by the host coordinator while it owns the exclusive console operation.
Artisan::command('migration:console-enqueue {service} {mode} {operation} {snapshot}', function (string $service, string $mode, string $operation, string $snapshot): int {
    try {
        $state = json_decode(file_get_contents('/ops/console.json'), true, 512, JSON_THROW_ON_ERROR);
        $owner = $state['operation'] ?? [];
        if (($owner['id'] ?? '') !== $operation || ($owner['status'] ?? '') !== 'running'
            || ! in_array($service, $owner['services'] ?? [], true)
            || ! in_array($mode, ['inspect', 'dry-run', 'migrate', 'validate'], true)
            || ($owner['snapshot_ids'][$service] ?? '') !== $snapshot) {
            throw new RuntimeException('Нет действующего владельца операции или точки отката.');
        }
        app(MigrationCredentialCipher::class)->fingerprint();
        $store = app(MigrationStore::class);
        $profile = app(\App\Migration\Core\ConnectionProfileStore::class)->publicProfile($service);
        if (! $profile || ! $profile['verified_at'] || $profile['credential_status'] !== 'ready') {
            throw new RuntimeException('Подключение не проверено.');
        }
        if ($mode === 'migrate') {
            $dry = $store->latestRun($service, 'dry-run');
            if (! $dry || $dry['status'] !== 'completed' || $dry['conflict_count'] > 0
                || $dry['started_at'] < $profile['verified_at']) {
                throw new RuntimeException('Dry run содержит ошибки или устарел; перенос заблокирован.');
            }
        }
        $id = $store->queueRun($service, $mode, (string) ($owner['requested_by'] ?? 'platform-admin'), $operation);
        $store->event($id, 'console', 'Запуск из пульта переноса.', 'info', ['operation_id' => $operation, 'snapshot_id' => $snapshot]);
        try { \App\Migration\Jobs\RunMigrationJob::dispatch($id, $service, $mode); }
        catch (Throwable $e) { $store->finishRun($id, 'failed', [], 'Не удалось поставить этап в очередь.'); throw $e; }
        $this->line(json_encode(['run_id' => $id]));
        return 0;
    } catch (Throwable $e) { $this->error($e->getMessage()); return 1; }
});
