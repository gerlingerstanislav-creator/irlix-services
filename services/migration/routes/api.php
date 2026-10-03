<?php

use App\Migration\Core\ConnectionProfileStore;
use App\Migration\Core\LegacyReader;
use App\Migration\Core\MigrationStore;
use App\Migration\Core\MigrationOperationsClient;
use App\Migration\Jobs\RunMigrationJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Route::get('/migration/health', function () {
    try {
        // Exercise the same key resolver used by authenticated run requests. A healthy metadata
        // database alone does not mean this API process can enqueue migration work.
        app(\App\Migration\Core\MigrationCredentialCipher::class)->fingerprint();
        $ready = Schema::hasTable('migration_runs')
            && Schema::hasTable('migration_connections')
            && Schema::hasTable('migration_run_events')
            && Schema::hasTable('migration_conflicts');
    } catch (\Throwable $e) {
        report($e);
        $ready = false;
    }

    return response()->json([
        'status' => $ready ? 'ok' : 'not_ready',
        'service' => 'migration',
    ], $ready ? 200 : 503);
});

Route::get('/migration/ops/health', function (MigrationOperationsClient $operations) {
    try {
        [$status] = $operations->request('GET', '/state');
        return response()->json(['service' => 'migration-ops', 'status' => $status === 200 ? 'ok' : 'not_ready'], $status === 200 ? 200 : 503);
    } catch (\Throwable $e) {
        report($e);
        return response()->json(['service' => 'migration-ops', 'status' => 'not_ready'], 503);
    }
});

$authorize = function (Request $request): array|JsonResponse {
    $authorization = trim((string) $request->header('Authorization', ''));
    if ($authorization === '') {
        return response()->json(['message' => 'Authentication required'], 401);
    }

    try {
        $response = Http::timeout(6)
            ->acceptJson()
            ->withHeaders(['Authorization' => $authorization])
            ->get(config('migration.employees_url').'/access/me');
    } catch (\Throwable $e) {
        report($e);
        return response()->json(['message' => 'Employees access service is unavailable'], 503);
    }

    if ($response->status() === 401) {
        return response()->json(['message' => 'Authentication required'], 401);
    }
    if (! $response->successful()) {
        return response()->json(['message' => 'Unable to resolve platform permissions'], 503);
    }

    $access = (array) data_get($response->json(), 'data', []);
    $roles = array_values(array_unique(array_map('strval', $access['roles'] ?? [])));
    if (! in_array('platform-admin', $roles, true)) {
        return response()->json(['message' => 'Migration Service is available to platform-admin only'], 403);
    }

    return $access;
};

Route::get('/migration/state', function (Request $request, MigrationStore $store, ConnectionProfileStore $profiles) use ($authorize) {
    $access = $authorize($request);
    if ($access instanceof JsonResponse) return $access;

    $implemented = array_keys(config('migration.modules', []));
    $modules = [];
    foreach (config('migration.catalog', []) as $key => $meta) {
        $isImplemented = in_array($key, $implemented, true);
        $runs = $isImplemented ? $store->recentRuns($key, 8) : [];
        $active = null;
        foreach ($runs as $run) {
            if (in_array($run['status'], ['queued', 'running'], true)) {
                $active = $run;
                break;
            }
        }
        $modules[] = [
            'key' => $key,
            'title' => $meta['title'] ?? ucfirst($key),
            'description' => $meta['description'] ?? '',
            'status' => $isImplemented ? 'implemented' : ($meta['status'] ?? 'planned'),
            'connection' => $isImplemented ? $profiles->publicProfile($key) : null,
            'active_run' => $active,
            'latest_run' => $runs[0] ?? null,
            'recent_runs' => $runs,
        ];
    }

    return response()->json(['data' => [
        'modules' => $modules,
        'recent_runs' => $store->recentRuns(null, 30),
    ]]);
});

Route::get('/migration/snapshots', function (Request $request, MigrationOperationsClient $operations) use ($authorize) {
    $access = $authorize($request);
    if ($access instanceof JsonResponse) return $access;
    try {
        [$code, $body] = $operations->request('GET', '/state');
        return response()->json(['data' => $body], $code);
    } catch (\Throwable $e) {
        report($e);
        return response()->json(['message' => $e->getMessage()], 503);
    }
});

Route::post('/migration/snapshots', function (Request $request, MigrationStore $store, MigrationOperationsClient $operations) use ($authorize) {
    $access = $authorize($request);
    if ($access instanceof JsonResponse) return $access;
    foreach (array_keys(config('migration.modules', [])) as $service) {
        if ($store->hasActiveRun($service)) return response()->json(['message' => 'Дождитесь завершения текущего переноса.'], 409);
    }
    try {
        [$code, $body] = $operations->request('POST', '/snapshot');
        return response()->json($code === 202 ? ['data' => $body] : $body, $code);
    } catch (\Throwable $e) {
        report($e);
        return response()->json(['message' => $e->getMessage()], 503);
    }
});

Route::post('/migration/snapshots/{snapshot}/restore', function (Request $request, string $snapshot, MigrationStore $store, MigrationOperationsClient $operations) use ($authorize) {
    $access = $authorize($request);
    if ($access instanceof JsonResponse) return $access;
    if (! ctype_digit($snapshot) || $request->input('confirmation') !== 'RESTORE EMPLOYEES') {
        return response()->json(['message' => 'Укажите ID снимка и точное подтверждение RESTORE EMPLOYEES.'], 422);
    }
    foreach (array_keys(config('migration.modules', [])) as $service) {
        if ($store->hasActiveRun($service)) return response()->json(['message' => 'Дождитесь завершения текущего переноса.'], 409);
    }
    try {
        [$code, $body] = $operations->request('POST', '/restore', ['snapshot_id' => $snapshot]);
        return response()->json($code === 202 ? ['data' => $body] : $body, $code);
    } catch (\Throwable $e) {
        report($e);
        return response()->json(['message' => $e->getMessage()], 503);
    }
});

Route::put('/migration/services/{service}/connection', function (Request $request, string $service, MigrationStore $store, ConnectionProfileStore $profiles) use ($authorize) {
    $access = $authorize($request);
    if ($access instanceof JsonResponse) return $access;
    if ($store->hasActiveRun($service)) {
        return response()->json(['message' => 'Нельзя менять подключение во время активного переноса.'], 409);
    }

    try {
        $profile = $profiles->save($service, $request->all());
        return response()->json(['data' => $profile]);
    } catch (\InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    } catch (\Throwable $e) {
        report($e);
        return response()->json(['message' => 'Не удалось сохранить параметры подключения.'], 500);
    }
});

Route::delete('/migration/services/{service}/connection', function (Request $request, string $service, MigrationStore $store, ConnectionProfileStore $profiles) use ($authorize) {
    $access = $authorize($request);
    if ($access instanceof JsonResponse) return $access;
    if ($store->hasActiveRun($service)) {
        return response()->json(['message' => 'Нельзя удалить подключение во время активного переноса.'], 409);
    }

    try {
        $profiles->delete($service);
        return response()->noContent();
    } catch (\InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 404);
    }
});

Route::post('/migration/services/{service}/reachability', function (Request $request, string $service) use ($authorize) {
    $access = $authorize($request);
    if ($access instanceof JsonResponse) return $access;

    if (! array_key_exists($service, config('migration.modules', []))) {
        return response()->json(['message' => 'Migration module is not implemented yet.'], 404);
    }

    $host = trim((string) $request->input('host', ''));
    $port = (int) $request->input('port', 5432);

    if ($host === '' || strlen($host) > 255 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $host)) {
        return response()->json(['message' => 'Укажите корректный host сервера БД.'], 422);
    }
    if ($port < 1 || $port > 65535) {
        return response()->json(['message' => 'Port must be between 1 and 65535.'], 422);
    }

    $startedAt = microtime(true);
    $errno = 0;
    $error = '';
    $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, 3, STREAM_CLIENT_CONNECT);
    $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

    if (! is_resource($socket)) {
        return response()->json(['message' => "Сервер {$host}:{$port} недоступен по TCP. {$error} ({$errno})", 'data' => [
            'reachable' => false,
            'host' => $host,
            'port' => $port,
            'latency_ms' => $latencyMs,
        ]], 422);
    }

    fclose($socket);

    return response()->json(['data' => [
        'reachable' => true,
        'host' => $host,
        'port' => $port,
        'latency_ms' => $latencyMs,
    ]]);
});

Route::post('/migration/services/{service}/verify', function (Request $request, string $service, MigrationStore $store, ConnectionProfileStore $profiles) use ($authorize) {
    $access = $authorize($request);
    if ($access instanceof JsonResponse) return $access;
    if ($store->hasActiveRun($service)) {
        return response()->json(['message' => 'Нельзя перепроверять подключение во время активной операции.'], 409);
    }

    $profile = $profiles->publicProfile($service);
    if (! $profile) {
        return response()->json(['message' => 'Сначала сохраните параметры подключения.'], 409);
    }
    if ($profile['credential_status'] !== 'ready') {
        return response()->json(['message' => 'Сохранённый пароль не расшифровывается. Введите пароль заново и нажмите «Сохранить доступ».'], 409);
    }

    try {
        $safety = (new LegacyReader($service))->assertSafe();
        $profiles->markVerified($service, $safety);
        return response()->json(['data' => [
            'safe' => true,
            'profile' => $profiles->publicProfile($service),
            'safety' => $safety,
        ]]);
    } catch (\Throwable $e) {
        $profiles->markVerificationFailed($service, $e->getMessage());
        return response()->json(['message' => $e->getMessage(), 'data' => [
            'safe' => false,
            'profile' => $profiles->publicProfile($service),
        ]], 422);
    }
});

Route::post('/migration/services/{service}/runs', function (Request $request, string $service, MigrationStore $store, ConnectionProfileStore $profiles) use ($authorize) {
    $access = $authorize($request);
    if ($access instanceof JsonResponse) return $access;

    if (! array_key_exists($service, config('migration.modules', []))) {
        return response()->json(['message' => 'Migration module is not implemented yet.'], 404);
    }

    $mode = trim((string) $request->input('mode', ''));
    if (! in_array($mode, ['inspect', 'dry-run', 'migrate', 'validate'], true)) {
        return response()->json(['message' => 'Unsupported migration mode.'], 422);
    }
    if ($store->hasActiveRun($service)) {
        return response()->json(['message' => 'Для этого сервиса уже выполняется операция.'], 409);
    }
    $profile = $profiles->publicProfile($service);
    if (! $profile || $profile['credential_status'] !== 'ready') {
        return response()->json(['message' => 'Сохранённый пароль не расшифровывается. Введите пароль заново и нажмите «Сохранить доступ».'], 409);
    }
    if ($service === 'employees') {
        try {
            [$status, $snapshotState] = app(MigrationOperationsClient::class)->request('GET', '/state');
            if ($status !== 200 || in_array($snapshotState['operation']['state'] ?? '', ['queued', 'running'], true)) {
                return response()->json(['message' => 'Дождитесь завершения операции со снимком.'], 409);
            }
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Служба снимков недоступна.'], 503);
        }
    }
    if (! $profiles->isVerified($service)) {
        return response()->json(['message' => 'Сначала проверьте подключение и read-only права legacy пользователя.'], 409);
    }

    if ($mode === 'migrate') {
        if ($service === 'employees') {
            $snapshotId = (string) $request->input('snapshot_id', '');
            if (! ctype_digit($snapshotId) || ! collect($snapshotState['snapshots'] ?? [])->contains(fn ($snapshot) => $snapshot['id'] === $snapshotId && ! $snapshot['restored'])) {
                return response()->json(['message' => 'Перед переносом выберите готовый снимок Employees.'], 409);
            }
        }
        if (! filter_var($request->input('confirm', false), FILTER_VALIDATE_BOOL)) {
            return response()->json(['message' => 'Для реального переноса требуется явное подтверждение.'], 422);
        }
        $dryRun = $store->latestRun($service, 'dry-run');
        $profile = $profiles->publicProfile($service);
        if (! $dryRun || ! in_array($dryRun['status'], ['completed', 'conflicts'], true)) {
            return response()->json(['message' => 'Перед реальным переносом необходимо выполнить Dry run.'], 409);
        }
        if ($profile && $profile['verified_at'] && $dryRun['started_at'] < $profile['verified_at']) {
            return response()->json(['message' => 'Подключение проверялось после последнего Dry run. Выполните Dry run повторно.'], 409);
        }
    }

    $requestedBy = (string) ($access['employee_id'] ?? 'platform-admin');
    try {
        app(\App\Migration\Core\MigrationCredentialCipher::class)->fingerprint();
    } catch (\RuntimeException $e) {
        return response()->json(['message' => 'Migration Service не видит MIGRATION_APP_KEY. Запуск заблокирован; проверьте ключ в API и worker.'], 503);
    }
    try {
        $runId = $store->queueRun($service, $mode, $requestedBy);
    } catch (\RuntimeException $e) {
        if ($e->getCode() !== 409) throw $e;
        return response()->json(['message' => $e->getMessage()], 409);
    }
    if ($mode === 'migrate' && $service === 'employees') {
        $store->event($runId, 'snapshot', "Точка отката: снимок #{$snapshotId}.");
    }
    try {
        RunMigrationJob::dispatch($runId, $service, $mode);
    } catch (\Throwable $e) {
        $store->finishRun($runId, 'failed', [], 'Не удалось поставить задачу в очередь: '.$e->getMessage());
        return response()->json(['message' => 'Не удалось поставить задачу в очередь.'], 500);
    }

    return response()->json(['data' => $store->run($runId)], 202);
});

Route::get('/migration/runs/{run}', function (Request $request, int $run, MigrationStore $store) use ($authorize) {
    $access = $authorize($request);
    if ($access instanceof JsonResponse) return $access;

    $payload = $store->run($run);
    if (! $payload) {
        return response()->json(['message' => 'Migration run not found.'], 404);
    }
    return response()->json(['data' => $payload]);
});
