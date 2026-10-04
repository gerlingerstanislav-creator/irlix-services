<?php

use App\Migration\Core\MigrationOperationsClient;
use App\Migration\Core\MigrationStore;
use App\Migration\Core\PlatformAdminAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$vacationsAuthorize = static function (Request $request): array|JsonResponse {
    return app(PlatformAdminAuthorizer::class)->authorize($request);
};

$vacationsEnsureIdle = static function (MigrationStore $store): ?JsonResponse {
    foreach (array_keys(config('migration.modules', [])) as $service) {
        if ($store->hasActiveRun($service)) {
            return response()->json(['message' => 'Дождитесь завершения текущего переноса.'], 409);
        }
    }
    return null;
};

Route::get('/migration/vacations/snapshots', function (Request $request, MigrationOperationsClient $operations) use ($vacationsAuthorize) {
    $access = $vacationsAuthorize($request);
    if ($access instanceof JsonResponse) return $access;
    try {
        [$code, $body] = $operations->request('GET', '/vacations/state');
        return response()->json(['data' => $body], $code);
    } catch (Throwable $e) {
        report($e);
        return response()->json(['message' => 'Служба снимков Vacations недоступна.'], 503);
    }
});

Route::post('/migration/vacations/snapshots', function (Request $request, MigrationStore $store, MigrationOperationsClient $operations) use ($vacationsAuthorize, $vacationsEnsureIdle) {
    $access = $vacationsAuthorize($request);
    if ($access instanceof JsonResponse) return $access;
    if ($busy = $vacationsEnsureIdle($store)) return $busy;
    try {
        [$code, $body] = $operations->request('POST', '/vacations/snapshot');
        return response()->json($code === 202 ? ['data' => $body] : $body, $code);
    } catch (Throwable $e) {
        report($e);
        return response()->json(['message' => $e->getMessage()], 503);
    }
});

Route::post('/migration/vacations/snapshots/{snapshot}/restore', function (Request $request, string $snapshot, MigrationStore $store, MigrationOperationsClient $operations) use ($vacationsAuthorize, $vacationsEnsureIdle) {
    $access = $vacationsAuthorize($request);
    if ($access instanceof JsonResponse) return $access;
    if (! ctype_digit($snapshot) || $request->input('confirmation') !== 'RESTORE VACATIONS') {
        return response()->json(['message' => 'Укажите ID снимка и точное подтверждение RESTORE VACATIONS.'], 422);
    }
    if ($busy = $vacationsEnsureIdle($store)) return $busy;
    try {
        [$code, $body] = $operations->request('POST', '/vacations/restore', ['snapshot_id' => $snapshot]);
        return response()->json($code === 202 ? ['data' => $body] : $body, $code);
    } catch (Throwable $e) {
        report($e);
        return response()->json(['message' => $e->getMessage()], 503);
    }
});

Route::delete('/migration/vacations/snapshots/{snapshot}', function (Request $request, string $snapshot, MigrationStore $store, MigrationOperationsClient $operations) use ($vacationsAuthorize, $vacationsEnsureIdle) {
    $access = $vacationsAuthorize($request);
    if ($access instanceof JsonResponse) return $access;
    if (! ctype_digit($snapshot)) return response()->json(['message' => 'Некорректный ID снимка.'], 422);
    if ($busy = $vacationsEnsureIdle($store)) return $busy;
    try {
        [$code, $body] = $operations->request('DELETE', '/vacations/snapshot/'.$snapshot);
        return response()->json($body, $code);
    } catch (Throwable $e) {
        report($e);
        return response()->json(['message' => $e->getMessage()], 503);
    }
});
