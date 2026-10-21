<?php

use App\Migration\Core\ConnectionProfileStore;
use App\Migration\Core\MigrationOperationsClient;
use App\Migration\Core\MigrationStore;
use App\Migration\Core\PlatformAdminAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/migration/control/state', function (Request $request, MigrationOperationsClient $ops) {
    $access = app(PlatformAdminAuthorizer::class)->authorize($request);
    if ($access instanceof JsonResponse) return $access;
    try {
        [$code,$body] = $ops->request('GET','/control/state');
        return response()->json(['data'=>$body],$code);
    } catch (Throwable $e) { return response()->json(['message'=>'Служба контроля переноса недоступна.'],503); }
});

Route::get('/migration/control/operations/{operation}/report', function (Request $request, string $operation, MigrationOperationsClient $ops) {
    $access = app(PlatformAdminAuthorizer::class)->authorize($request);
    if ($access instanceof JsonResponse) return $access;
    if (!ctype_digit($operation) || strlen($operation) > 100) return response()->json(['message'=>'Некорректный ID операции.'],422);
    try {
        $download = $request->boolean('download');
        [$code,$body] = $ops->request('GET','/control/operations/'.$operation.($download?'/report':'/summary'));
        if ($code !== 200) return response()->json($body,$code);
        if ($download) return response()->json($body,200,[
            'Content-Disposition'=>'attachment; filename="migration-control-'.$operation.'.json"',
            'Cache-Control'=>'no-store',
        ]);
        // Defense in depth: the host has already dropped unbounded events.
        // Reapplying this filter also protects older migration-ops deployments
        // and mock transports returning a complete report.
        return response()->json(['data'=>\App\Migration\Core\ControlReport::compact($body)],200,['Cache-Control'=>'no-store']);
    } catch (Throwable $e) { return response()->json(['message'=>'Полный отчёт недоступен.'],503); }
});

foreach (['start','restore','snapshot','cancel'] as $action) {
    Route::post('/migration/control/'.$action, function (Request $request, MigrationOperationsClient $ops, MigrationStore $store, ConnectionProfileStore $profiles) use ($action) {
        $access = app(PlatformAdminAuthorizer::class)->authorize($request);
        if ($access instanceof JsonResponse) return $access;
        $input = $action === 'cancel' ? $request->validate(['reason'=>['required','string','max:500'],'operation_id'=>['required','string','max:100','regex:/^[0-9]+$/D']]) : [];
        $scope = $request->input('scope');
        if ($action !== 'cancel') {
            if (!in_array($scope,['all',...array_keys(config('migration.modules',[]))],true)) return response()->json(['message'=>'Этот модуль не реализован.'],422);
            foreach (array_keys(config('migration.modules',[])) as $service) if ($store->hasActiveRun($service)) return response()->json(['message'=>'Дождитесь завершения активного переноса.'],409);
        }
        if ($action === 'start') {
            if ($request->input('confirm') !== true) return response()->json(['message'=>'Подтвердите реальный перенос.'],422);
            foreach ($scope === 'all' ? array_keys(config('migration.modules',[])) : [$scope] as $service) {
                $profile = $profiles->publicProfile($service);
                if (!$profile || !$profile['verified_at'] || ($profile['credential_status'] ?? '') !== 'ready') return response()->json(['message'=>$service.': сохраните и проверьте read-only подключение.'],409);
            }
        }
        try {
            // Shared snapshot and restore implementations retain all existing guards.
            $path = in_array($action,['snapshot','restore'],true) ? '/console/'.$action : '/control/'.$action;
            [$code,$body] = $ops->request('POST',$path,[
                'scope'=>$scope,'confirm'=>$request->input('confirm'),'snapshot_id'=>$request->input('snapshot_id'),
                'confirmation'=>$request->input('confirmation'),'operation_id'=>$request->input('operation_id'),'reason'=>$input['reason'] ?? null,
                'requested_by'=>(string)(data_get($access,'employee.id') ?? ($access['employee_id'] ?? 'platform-admin')),
            ]);
            return response()->json($code >= 200 && $code < 300 ? ['data'=>$body] : $body,$code);
        } catch (Throwable $e) { return response()->json(['message'=>'Нет ответа от очереди; проверьте состояние перед повтором.'],503); }
    });
}

Route::delete('/migration/control/snapshots/{scope}/{snapshot}', function (Request $request, string $scope, string $snapshot, MigrationOperationsClient $ops, MigrationStore $store) {
    $access = app(PlatformAdminAuthorizer::class)->authorize($request);
    if ($access instanceof JsonResponse) return $access;
    if (!in_array($scope,['all',...array_keys(config('migration.modules',[]))],true) || !ctype_digit($snapshot)) return response()->json(['message'=>'Некорректный сервис или ID снимка.'],422);
    foreach (array_keys(config('migration.modules',[])) as $service) if ($store->hasActiveRun($service)) return response()->json(['message'=>'Дождитесь завершения активного переноса.'],409);
    try {
        [$code,$body] = $ops->request('DELETE','/console/snapshots/'.$scope.'/'.$snapshot);
        return response()->json($code === 200 ? ['data'=>$body] : $body,$code);
    } catch (Throwable $e) { return response()->json(['message'=>'Служба снимков недоступна.'],503); }
});
