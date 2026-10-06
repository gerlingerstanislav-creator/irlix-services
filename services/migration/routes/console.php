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
