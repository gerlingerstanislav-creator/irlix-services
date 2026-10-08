<?php

use App\Resources\PlatformAdminAuthorizer;
use App\Resources\ResourceStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Infrastructure liveness only: does not return resource measurements or container names.
Route::get('/resources/health', function (ResourceStore $store) {
    $snapshot = $store->snapshot();
    $healthy = $snapshot && !$snapshot['stale'] && !$snapshot['partial'];
    return response()->json(['service' => 'resource-monitor', 'status' => $healthy ? 'ok' : 'unavailable'], $healthy ? 200 : 503)
        ->header('Cache-Control', 'no-store');
});

Route::get('/resources', function (Request $request, PlatformAdminAuthorizer $authorizer, ResourceStore $store) {
    if ($denied = $authorizer->authorize($request)) return $denied->header('Cache-Control', 'no-store');
    $snapshot = $store->snapshot();
    if (!$snapshot) return response()->json(['message' => 'Сборщик ещё не предоставил данные'], 503)->header('Cache-Control', 'no-store');
    return response()->json(['data' => $snapshot])->header('Cache-Control', 'no-store');
});

Route::get('/resources/history', function (Request $request, PlatformAdminAuthorizer $authorizer, ResourceStore $store) {
    if ($denied = $authorizer->authorize($request)) return $denied->header('Cache-Control', 'no-store');
    try {
        $period = (string) $request->query('period', '1h');
        $service = (string) $request->query('service', '__host__');
        return response()->json(['data' => $store->history($period, $service), 'period' => $period, 'service' => $service])
            ->header('Cache-Control', 'no-store');
    } catch (\InvalidArgumentException $error) {
        return response()->json(['message' => $error->getMessage()], 422)->header('Cache-Control', 'no-store');
    } catch (\Throwable $error) {
        return response()->json(['message' => 'История временно недоступна'], 503)->header('Cache-Control', 'no-store');
    }
});
