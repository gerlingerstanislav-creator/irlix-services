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

Artisan::command('migration:credentials-check', function (): int {
    $cipher = app(MigrationCredentialCipher::class);
    $failed = false;
    foreach (DB::table('migration_connections')->get(['service', 'password_encrypted']) as $profile) {
        try {
            $cipher->decryptString((string) $profile->password_encrypted);
        } catch (Throwable $e) {
            $format = str_starts_with((string) $profile->password_encrypted, 'migration:v1:') ? 'migration:v1' : 'Laravel Crypt';
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
