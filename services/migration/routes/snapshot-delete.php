<?php

use App\Migration\Core\MigrationOperationsClient;
use App\Migration\Core\MigrationStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::delete('/migration/snapshots/{snapshot}', function (
    Request $request,
    string $snapshot,
    MigrationStore $store,
    MigrationOperationsClient $operations,
) use ($authorize) {
    $access = $authorize($request);
    if ($access instanceof JsonResponse) return $access;

    if (! ctype_digit($snapshot)) {
        return response()->json(['message' => 'Укажите корректный ID точки отката.'], 422);
    }

    foreach (array_keys(config('migration.modules', [])) as $service) {
        if ($store->hasActiveRun($service)) {
            return response()->json(['message' => 'Нельзя удалить точку отката во время активного переноса.'], 409);
        }
    }

    try {
        [$stateCode, $state] = $operations->request('GET', '/state');
        if ($stateCode !== 200) {
            return response()->json(['message' => 'Не удалось проверить состояние службы снимков.'], 503);
        }
        if (in_array($state['operation']['state'] ?? '', ['queued', 'running'], true)) {
            return response()->json(['message' => 'Дождитесь завершения текущей операции со снимком.'], 409);
        }

        [$code, $body] = $operations->request('DELETE', '/snapshot/'.$snapshot);
        return response()->json($code === 200 ? ['data' => $body] : $body, $code);
    } catch (\Throwable $e) {
        report($e);
        return response()->json(['message' => $e->getMessage()], 503);
    }
});
