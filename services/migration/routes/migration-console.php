<?php

use App\Migration\Core\ConnectionProfileStore;
use App\Migration\Core\EmploymentConflictDetails;
use App\Migration\Core\MigrationOperationsClient;
use App\Migration\Core\MigrationStore;
use App\Migration\Core\PlatformAdminAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/migration/console/conflicts/{conflict}/details', function (Request $request, int $conflict, EmploymentConflictDetails $details, MigrationOperationsClient $ops, MigrationStore $store) {
    $access = app(PlatformAdminAuthorizer::class)->authorize($request);
    if ($access instanceof JsonResponse) return $access;
    $row = \Illuminate\Support\Facades\DB::table('migration_conflicts')->find($conflict);
    if (! $row) return response()->json(['message' => 'Ошибка не найдена.'], 404);
    if ($row->service !== 'employees' || $row->entity_type !== 'employment' || ! ctype_digit((string) $row->legacy_id)) {
        return response()->json(['message' => 'Подробности доступны для периодов работы сотрудников.'], 422);
    }
    $recorded = json_decode($row->context ?? '{}', true);
    if (isset($recorded['employee'], $recorded['source_period'])) {
        return response()->json(['data' => ['basis' => 'recorded', 'details' => $recorded]]);
    }
    foreach (array_keys(config('migration.modules', [])) as $service) {
        if ($store->hasActiveRun($service)) return response()->json(['message' => 'Для чтения текущих данных дождитесь завершения переноса.'], 409);
    }
    try {
        [$status, $state] = $ops->request('GET', '/console/state');
        if ($status !== 200) throw new RuntimeException('Operations unavailable');
        if (in_array($state['operation']['status'] ?? null, ['queued', 'running'], true) || in_array($state['snapshot_operation']['state'] ?? null, ['queued', 'running'], true)) {
            return response()->json(['message' => 'Для чтения текущих данных дождитесь завершения операции с бэкапом или переноса.'], 409);
        }
        $current = $details->current((string) $row->legacy_id);
        if (! $current) return response()->json(['message' => 'Исходный период больше не найден в старой БД.'], 404);
        return response()->json(['data' => ['basis' => 'current', 'details' => $current]]);
    } catch (Throwable $e) {
        return response()->json(['message' => 'Не удалось прочитать подробности. Проверьте доступность очереди и read-only подключение к БД сотрудников.'], 503);
    }
});

Route::get('/migration/console/state', function (Request $request, MigrationOperationsClient $ops) {
    $access = app(PlatformAdminAuthorizer::class)->authorize($request);
    if ($access instanceof JsonResponse) return $access;
    try {
        [$code, $body] = $ops->request('GET', '/console/state');
        return response()->json(['data' => $body], $code);
    } catch (Throwable $e) { return response()->json(['message' => 'Служба очереди переноса недоступна.'], 503); }
});

foreach (['start', 'restore', 'snapshot'] as $action) {
    Route::post('/migration/console/'.$action, function (Request $request, MigrationOperationsClient $ops, MigrationStore $store, ConnectionProfileStore $profiles) use ($action) {
        $access = app(PlatformAdminAuthorizer::class)->authorize($request);
        if ($access instanceof JsonResponse) return $access;
        $scope = $request->input('scope');
        if (! in_array($scope, ['all', ...array_keys(config('migration.modules', []))], true)) {
            return response()->json(['message' => 'Этот модуль переноса ещё не реализован.'], 422);
        }
        foreach (array_keys(config('migration.modules', [])) as $service) {
            if ($store->hasActiveRun($service)) return response()->json(['message' => 'Дождитесь завершения активной операции.'], 409);
        }
        if ($action === 'start') {
            if ($request->input('confirm') !== true) return response()->json(['message' => 'Подтвердите реальный перенос.'], 422);
            foreach ($scope === 'all' ? array_keys(config('migration.modules', [])) : [$scope] as $service) {
                $profile = $profiles->publicProfile($service);
                if (! $profile || ! $profile['verified_at'] || ($profile['credential_status'] ?? '') !== 'ready') {
                    return response()->json(['message' => $service.': сохраните и проверьте read-only подключение.'], 409);
                }
            }
        }
        try {
            [$code, $body] = $ops->request('POST', '/console/'.$action, [
                'scope' => $scope, 'confirm' => $request->input('confirm'),
                'snapshot_id' => $request->input('snapshot_id'), 'confirmation' => $request->input('confirmation'),
                'requested_by' => (string) ($access['employee_id'] ?? 'platform-admin'),
            ]);
            return response()->json($code === 202 ? ['data' => $body] : $body, $code);
        } catch (Throwable $e) { return response()->json(['message' => 'Нет ответа от очереди переноса. Проверьте состояние перед повторным запуском.'], 503); }
    });
}

Route::delete('/migration/console/snapshots/{scope}/{snapshot}', function (Request $request, string $scope, string $snapshot, MigrationOperationsClient $ops, MigrationStore $store) {
    $access = app(PlatformAdminAuthorizer::class)->authorize($request);
    if ($access instanceof JsonResponse) return $access;
    if (! in_array($scope, ['all', ...array_keys(config('migration.modules', []))], true) || ! ctype_digit($snapshot)) {
        return response()->json(['message' => 'Некорректный сервис или ID бэкапа.'], 422);
    }
    foreach (array_keys(config('migration.modules', [])) as $service) {
        if ($store->hasActiveRun($service)) return response()->json(['message' => 'Дождитесь завершения активного переноса.'], 409);
    }
    try {
        [$code, $body] = $ops->request('DELETE', '/console/snapshots/'.$scope.'/'.$snapshot);
        return response()->json($code === 200 ? ['data' => $body] : $body, $code);
    } catch (Throwable $e) { return response()->json(['message' => 'Служба бэкапов недоступна.'], 503); }
});

Route::get('/migration/console/runs/{run}', function (Request $request, int $run, MigrationStore $store) {
    $access = app(PlatformAdminAuthorizer::class)->authorize($request);
    if ($access instanceof JsonResponse) return $access;
    if (! \App\Migration\Core\ConsoleRunIdentity::matches($request, $run)) return response()->json(['message' => 'Подробный отчёт этой операции недоступен после восстановления метаданных. Запись истории сохранена.'], 404);
    $data = $store->run($run);
    if (! $data) return response()->json(['message' => 'Этот запуск недоступен: возможно, его метаданные восстановлены из точки отката.'], 404);
    $items = \Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id', $run)->orderByDesc('id')->limit(201)->get();
    $data['conflict_summary'] = \Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id', $run)->where('severity', 'error')->select('entity_type', 'code', 'message')->selectRaw('count(*) as count')->groupBy('entity_type', 'code', 'message')->orderByDesc('count')->limit(20)->get()->all();
    $data['conflicts_more'] = $items->count() > 200;
    $data['conflicts'] = $items->take(200)->map(function ($row) { $r = (array) $row; $r['context'] = json_decode($r['context'] ?? '{}', true); return $r; })->all();
    $data['conflict_cursor'] = $items->take(200)->last()?->id;
    return response()->json(['data' => $data]);
});
Route::get('/migration/console/runs/{run}/conflicts', function (Request $request, int $run) {
    $access = app(PlatformAdminAuthorizer::class)->authorize($request);
    if ($access instanceof JsonResponse) return $access;
    $input = $request->validate([
        'before' => ['sometimes', 'required', 'integer', 'min:1'],
        'severity' => ['sometimes', 'required', 'in:error,warning'],
        'table' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[a-z_]+$/D'],
    ]);
    if (! \App\Migration\Core\ConsoleRunIdentity::matches($request, $run)) return response()->json(['message' => 'Подробный отчёт этой операции недоступен после восстановления метаданных. Запись истории сохранена.'], 404);
    $record = \Illuminate\Support\Facades\DB::table('migration_runs')->find($run);
    if (! $record) return response()->json(['message' => 'Этот запуск недоступен: возможно, его метаданные восстановлены из точки отката.'], 404);
    $query = \Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id', $run);
    if (isset($input['severity'])) $query->where('severity', $input['severity']);
    if (isset($input['table'])) {
        // Stored entity names may differ from the displayed source table (employee -> employees).
        $entities = [$input['table']];
        foreach (config('migration.table_entities.'.$record->service, []) as $entity => $table) {
            if ($table === $input['table']) $entities[] = $entity;
        }
        $query->whereIn('entity_type', array_unique($entities));
    }
    $total = (clone $query)->count();
    if (isset($input['before'])) $query->where('id', '<', $input['before']);
    $items = $query->orderByDesc('id')->limit(201)->get();
    return response()->json(['data' => [
        'items' => $items->take(200)->map(function ($row) { $r = (array) $row; $r['context'] = json_decode($r['context'] ?? '{}', true); return $r; })->all(),
        'more' => $items->count() > 200, 'cursor' => $items->take(200)->last()?->id, 'total' => $total,
    ]]);
});


Route::get('/migration/console/conflicts/{conflict}/employee-match', function (Request $request, int $conflict, MigrationOperationsClient $ops) {
    $access = app(PlatformAdminAuthorizer::class)->authorize($request);
    if ($access instanceof JsonResponse) return $access;
    $input = $request->validate(['login'=>['required','string','max:255']]);
    $stage = 'queue';
    try {
        \App\Migration\Core\EmployeeUserOverrides::idle($ops);
        $stage = 'source';
        [, $source] = \App\Migration\Core\EmployeeUserOverrides::source($conflict);
        $stage = 'employee';
        $employee = \App\Migration\Core\EmployeeUserOverrides::match($input['login']);
        $stage = 'identity';
        \App\Migration\Core\EmployeeUserOverrides::assertUuid($source, (int) $employee->id);
        return response()->json(['data'=>[...(array) $employee,'source'=>$source,'source_fingerprint'=>\App\Migration\Core\EmployeeUserOverrides::fingerprint($source),'target_login'=>$employee->login,'source_key'=>\App\Migration\Core\EmployeeLoginRegistry::sourceKey()]]);
    } catch (DomainException $e) { return response()->json(['message'=>$e->getMessage()],422); }
    catch (Throwable $e) { return \App\Migration\Core\EmployeeMappingFailure::response($e, $stage); }
});

Route::post('/migration/console/conflicts/{conflict}/employee-map', function (Request $request, int $conflict, MigrationOperationsClient $ops, MigrationStore $store) {
    $access = app(PlatformAdminAuthorizer::class)->authorize($request);
    if ($access instanceof JsonResponse) return $access;
    $input = $request->validate(['login'=>['required','string','max:255'], 'employee_id'=>['required','integer','min:1'], 'source_fingerprint'=>['required','string','size:64'], 'confirmation'=>['required','string']]);
    $stage = 'write';
    try {
        $employee = \Illuminate\Support\Facades\DB::transaction(function () use ($input,$conflict,$ops,$store,$access,&$stage) {
            \Illuminate\Support\Facades\DB::table('migration_connections')->where('service','vacations')->update(['updated_at'=>\Illuminate\Support\Facades\DB::raw('updated_at')]);
            $stage = 'queue';
            \App\Migration\Core\EmployeeUserOverrides::idle($ops);
            $stage = 'source';
            [$error, $source] = \App\Migration\Core\EmployeeUserOverrides::source($conflict);
            if (!hash_equals(\App\Migration\Core\EmployeeUserOverrides::fingerprint($source), $input['source_fingerprint'])) throw new DomainException('Исходные данные изменились. Проверьте сотрудника заново.');
            if ($input['confirmation'] !== 'MAP USER '.$error->legacy_id) throw new DomainException('Подтвердите сопоставление пользователя.');
            $stage = 'employee';
            $employee = \App\Migration\Core\EmployeeUserOverrides::match($input['login']);
            $stage = 'identity';
            if ((int) $employee->id !== (int) $input['employee_id']) throw new DomainException('Результат проверки изменился. Проверьте логин заново.');
            \App\Migration\Core\EmployeeUserOverrides::assertUuid($source, (int) $employee->id);
            $stage = 'write';
            $previous = \Illuminate\Support\Facades\DB::table('migration_overrides')->where(['service'=>'vacations','entity_type'=>'users','legacy_id'=>(string) $error->legacy_id])->value('target_id');
            $imported = $store->mapping('vacations','users',$error->legacy_id);
            if ($imported !== null && (int) $imported !== (int) $employee->id) throw new DomainException('Пользователь уже переносился к другому сотруднику. Сначала выполните откат.');
            \Illuminate\Support\Facades\DB::table('migration_overrides')->updateOrInsert(['service'=>'vacations','entity_type'=>'users','legacy_id'=>(string) $error->legacy_id], ['target_id'=>(string) $employee->id,'note'=>json_encode(['source_fingerprint'=>\App\Migration\Core\EmployeeUserOverrides::fingerprint($source),'target_login'=>$employee->login,'source_key'=>\App\Migration\Core\EmployeeLoginRegistry::sourceKey()]),'created_at'=>now(),'updated_at'=>now()]);
            \App\Migration\Core\EmployeeLoginRegistry::save($source, $employee->login, (string) data_get($access,'employee.id','platform-privileged'));
            $store->event((int) $error->migration_run_id,'employee_override_saved','Оператор сохранил сопоставление пользователя. Повторите Dry run.','info',['legacy_user_id'=>$error->legacy_id,'employee_id'=>$employee->id,'previous_employee_id'=>$previous,'actor'=>data_get($access,'employee.id','platform-privileged')]);
            return $employee;
        });
        return response()->json(['data'=>['employee'=>$employee,'message'=>'Сопоставление логинов сохранено и останется после отката. Повторите Dry run.']]);
    } catch (DomainException $e) { return response()->json(['message'=>$e->getMessage()],422); }
    catch (Throwable $e) { return \App\Migration\Core\EmployeeMappingFailure::response($e, $stage); }
});
