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
    $data['warning_summary'] = \Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id', $run)->where('severity', 'warning')->select('entity_type', 'code', 'message')->selectRaw('count(*) as count')->groupBy('entity_type', 'code', 'message')->orderByDesc('count')->limit(40)->get()->all();
    $data['conflicts_more'] = $items->count() > 200;
    $data['conflicts'] = $items->take(200)->map(function ($row) { $r = (array) $row; $r['context'] = json_decode($r['context'] ?? '{}', true); return $r; })->all();
    $data['conflict_cursor'] = $items->take(200)->last()?->id;
    return response()->json(['data' => $data]);
});
// A separate authenticated view: names are never included in downloadable audit reports.
Route::get('/migration/console/runs/{run}/unresolved-members', function (Request $request, int $run) {
    $access = app(PlatformAdminAuthorizer::class)->authorize($request);
    if ($access instanceof JsonResponse) return $access;
    $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        'operation_id' => ['required', 'string', 'max:100', 'regex:/^[0-9]+$/D']]);
    if (!\App\Migration\Core\ConsoleRunIdentity::matches($request, $run)) {
        return response()->json(['message' => 'История запуска не совпадает с выбранной операцией.'], 404);
    }
    $record = \Illuminate\Support\Facades\DB::table('migration_runs')->find($run);
    if (!$record || $record->service !== 'timesheets') return response()->json(['message' => 'Запуск Timesheets не найден.'], 404);
    try {
        $data = \App\Migration\Core\TimesheetsUnresolvedMembers::page($run, (int) ($input['page'] ?? 1));
        return response()->json(['data' => $data], 200, ['Cache-Control' => 'no-store']);
    } catch (Throwable $e) {
        return response()->json(['message' => 'Не удалось прочитать исходных участников. Проверьте read-only подключение Timesheets.'], 503);
    }
});


// Explicit reconciliation of unresolved members: never infer a target
// from similar names. The operator chooses one Clients membership and
// confirms against a fingerprint of the current read-only source row.
Route::get('/migration/console/runs/{run}/unresolved-members/{member}/candidates',
    function (Request $request, int $run, string $member, MigrationOperationsClient $ops) {
        $access = app(PlatformAdminAuthorizer::class)->authorize($request);
        if ($access instanceof JsonResponse) return $access;
        $request->validate(['operation_id' => ['required', 'string', 'max:100', 'regex:/^[0-9]+$/D']]);
        if (!\App\Migration\Core\ConsoleRunIdentity::matches($request, $run)) {
            return response()->json(['message' => 'Запуск не принадлежит выбранной операции.'], 404);
        }
        $record = \Illuminate\Support\Facades\DB::table('migration_runs')->find($run);
        if (!$record || $record->service !== 'timesheets') {
            return response()->json(['message' => 'Запуск Timesheets не найден.'], 404);
        }
        try {
            \App\Migration\Core\EmployeeUserOverrides::idle($ops);
            return response()->json([
                'data' => \App\Migration\Core\TimesheetsClientMemberMatching::inspectSource($member),
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Не удалось проверить исходного участника и Clients.'], 503);
        }
    });

Route::post('/migration/console/runs/{run}/unresolved-members/{member}/map',
    function (Request $request, int $run, string $member, MigrationOperationsClient $ops, MigrationStore $store) {
        $access = app(PlatformAdminAuthorizer::class)->authorize($request);
        if ($access instanceof JsonResponse) return $access;
        $input = $request->validate([
            'operation_id' => ['required', 'string', 'max:100', 'regex:/^[0-9]+$/D'],
            'target_id' => ['required', 'integer', 'min:1'],
            'source_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/D'],
            'confirmation' => ['required', 'string', 'max:100'],
        ]);
        if (!\App\Migration\Core\ConsoleRunIdentity::matches($request, $run)) {
            return response()->json(['message' => 'Запуск не принадлежит выбранной операции.'], 404);
        }
        $record = \Illuminate\Support\Facades\DB::table('migration_runs')->find($run);
        if (!$record || $record->service !== 'timesheets') {
            return response()->json(['message' => 'Запуск Timesheets не найден.'], 404);
        }
        try {
            \App\Migration\Core\EmployeeUserOverrides::idle($ops);
            $result = \App\Migration\Core\TimesheetsMemberMatching::inspect($member);
            if (!hash_equals($result['source_fingerprint'], $input['source_fingerprint'])) {
                throw new \DomainException('Источник изменился. Повторно проверьте кандидатов.');
            }
            $candidate = collect($result['candidates'])->firstWhere('target_id', (int) $input['target_id']);
            $advisoryPartner=false;$advisoryEmployee=false;
            if(!$candidate && \App\Migration\Core\TimesheetsUnresolvedMembers::isExternal(
                (string)($result['source']['memberable_type']??''))) {
                $candidate=collect($result['possible_partner_candidates']??[])
                    ->firstWhere('target_id',(int)$input['target_id']);
                $advisoryPartner=$candidate!==null;
            } elseif(!$candidate && ($result['source']['memberable_type']??'')==='employee'
                && !empty($result['source']['employee_id'])) {
                $candidate=collect($result['possible_employee_candidates']??[])
                    ->firstWhere('target_id',(int)$input['target_id']);
                $advisoryEmployee=$candidate!==null;
            }
            if (!$candidate) throw new \DomainException('Кандидат не подтверждён по личности, клиенту или датам источника.');
            $requiredConfirmation=$advisoryPartner?'VERIFY PARTNER '.$member:
                ($advisoryEmployee?'VERIFY CLIENT '.$member:'MAP MEMBER '.$member);
            if ($input['confirmation'] !== $requiredConfirmation) {
                throw new \DomainException('Требуется точное подтверждение '.$requiredConfirmation);
            }
            if($advisoryPartner && (empty($candidate['name_exact']) || empty($candidate['period_matches'])))
                throw new \DomainException('Совпадения ФИО и фактических дат недостаточно для этого подключения.');
            if ($result['source_rates'] && empty($candidate['period_matches'])) {
                throw new \DomainException('Периоды или часы исходных rates не совпадают с Clients member_terms. '
                    .'Сначала исправьте связь/условия в Clients; неверный override не сохранён.');
            }
            $db = \Illuminate\Support\Facades\DB::connection();
            $db->transaction(function () use ($store, $member, $candidate, $run, $result, $access, $advisoryPartner, $advisoryEmployee) {
                $existing = $store->mapping('timesheets', 'members', $member);
                $mappingMeta = \Illuminate\Support\Facades\DB::table('migration_mappings')
                    ->where(['service'=>'timesheets','entity_type'=>'members','legacy_id'=>$member])->value('metadata');
                $mappingVersion = (int)(json_decode($mappingMeta ?? '{}', true)['resolution']['version'] ?? 0);
                // Old project-member mappings may be explicitly rebound to ClientMember;
                // an existing v3 mapping is immutable without a separate reconciliation.
                if ($existing !== null && (int)$existing !== (int)$candidate['target_id'] && $mappingVersion >= 3) {
                    throw new \DomainException('Участник уже перенесён в другое ClientMember. Сначала проверьте результаты.');
                }
                $key = ['service' => 'timesheets', 'entity_type' => 'members', 'legacy_id' => $member];
                $previous = \Illuminate\Support\Facades\DB::table('migration_overrides')
                    ->where($key)->lockForUpdate()->value('target_id');
                if ($previous !== null && (int) $previous !== (int) $candidate['target_id']) {
                    throw new \DomainException('Другое сопоставление уже сохранено; изменение требует отдельной сверки.');
                }
                \Illuminate\Support\Facades\DB::table('migration_overrides')->updateOrInsert($key, [
                    'target_id' => (string) $candidate['target_id'],
                    'note' => json_encode([
                        'source_fingerprint' => $result['source_fingerprint'],
                        'employee_id' => $result['source']['employee_id'],
                        'project_id' => $candidate['project_id'] ?? null,
                        'client_member_id' => $candidate['client_member_id'] ?? null,
                        'partner_specialist_id' => $candidate['partner_specialist_id'] ?? null,
                        'review' => $advisoryPartner?'explicit_operator_partner_identity_confirmation':
                            ($advisoryEmployee?'explicit_operator_employee_client_confirmation':'explicit_owner_confirmation'),
                        'client_label_verified' => !$advisoryPartner&&!$advisoryEmployee,
                        'name_exact' => (bool)($candidate['name_exact']??false),
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $store->event($run, 'timesheets_member_override_saved',
                    'Оператор подтвердил сопоставление участника Timesheets. Повторите Dry run.', 'info',
                    ['legacy_member_id' => $member, 'target_member_id' => $candidate['target_id'],
                        'actor' => data_get($access, 'employee.id', 'platform-admin')]);
            });
            return response()->json(['data' => ['message' => 'Подключение подтверждено. Повторите Dry run и затем перенос.']]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Не удалось сохранить сопоставление. Проверьте, что перенос остановлен и БД доступны.'], 503);
        }
    });

// Read one specific recorded failure against the current legacy + Clients data.
// Kept out of immutable archives: sensitive work descriptions and names must
// never leak to downloadable generic reports. Admin-only, no-store, read-only.
Route::get('/migration/console/runs/{run}/conflicts/{conflict}/evidence',
    function (Request $request, int $run, int $conflict, MigrationOperationsClient $ops, MigrationStore $store) {
        $access = app(PlatformAdminAuthorizer::class)->authorize($request);
        if ($access instanceof JsonResponse) return $access;
        $request->validate([
            'operation_id' => ['required','string','max:100','regex:/^[0-9]+$/D'],
        ]);
        if (!\App\Migration\Core\ConsoleRunIdentity::matches($request, $run)) {
            return response()->json(['message'=>'Запуск не принадлежит выбранной операции.'],404);
        }
        $runRow = \Illuminate\Support\Facades\DB::table('migration_runs')->find($run);
        $row = \Illuminate\Support\Facades\DB::table('migration_conflicts')
            ->where('migration_run_id',$run)->where('id',$conflict)->first();
        if (!$runRow || $runRow->service !== 'timesheets' || !$row
            || $row->severity !== 'error') {
            return response()->json(['message'=>'Ошибка Timesheets в этом запуске не найдена.'],404);
        }
        try {
            \App\Migration\Core\EmployeeUserOverrides::idle($ops);
            foreach (array_keys(config('migration.modules', [])) as $service) {
                if ($store->hasActiveRun($service)) {
                    return response()->json(['message'=>'Дождитесь завершения активной миграции.'],409);
                }
            }
            return response()->json([
                'data'=>\App\Migration\Core\TimesheetsConflictEvidence::inspect($row),
            ], 200, ['Cache-Control'=>'no-store']);
        } catch (\DomainException $e) {
            return response()->json(['message'=>$e->getMessage()],422);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message'=>'Не удалось получить исходные и целевые сведения. Проверьте read-only подключения.'],503);
        }
    });

Route::get('/migration/console/runs/{run}/conflicts', function (Request $request, int $run) {
    $access = app(PlatformAdminAuthorizer::class)->authorize($request);
    if ($access instanceof JsonResponse) return $access;
    $input = $request->validate([
        'before' => ['sometimes', 'required', 'integer', 'min:1'],
        'severity' => ['sometimes', 'required', 'in:error,warning'],
        'table' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[a-z_]+$/D'],
        'code' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[A-Z_]+$/D'],
        'legacy_id' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[0-9]+$/D'],
    ]);
    if (! \App\Migration\Core\ConsoleRunIdentity::matches($request, $run)) return response()->json(['message' => 'Подробный отчёт этой операции недоступен после восстановления метаданных. Запись истории сохранена.'], 404);
    $record = \Illuminate\Support\Facades\DB::table('migration_runs')->find($run);
    if (! $record) return response()->json(['message' => 'Этот запуск недоступен: возможно, его метаданные восстановлены из точки отката.'], 404);
    $query = \Illuminate\Support\Facades\DB::table('migration_conflicts')->where('migration_run_id', $run);
    if (isset($input['severity'])) $query->where('severity', $input['severity']);
    if (isset($input['code'])) $query->where('code', $input['code']);
    if (isset($input['legacy_id'])) $query->where('legacy_id', $input['legacy_id']);
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
        [$error, $source] = \App\Migration\Core\EmployeeUserOverrides::source($conflict);
        $stage = 'employee';
        $employee = \App\Migration\Core\EmployeeUserOverrides::match($input['login']);
        $stage = 'identity';
        \App\Migration\Core\EmployeeUserOverrides::assertUuid($source, (int) $employee->id);
        return response()->json(['data'=>[...(array) $employee,'source'=>$source,'source_fingerprint'=>\App\Migration\Core\EmployeeUserOverrides::fingerprint($source),'target_login'=>$employee->login,'source_key'=>\App\Migration\Core\EmployeeLoginRegistry::sourceKey($error->service)]]);
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
            \Illuminate\Support\Facades\DB::table('migration_connections')->whereIn('service',['vacations','clients'])->update(['updated_at'=>\Illuminate\Support\Facades\DB::raw('updated_at')]);
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
            $previous = \Illuminate\Support\Facades\DB::table('migration_overrides')->where(['service'=>$error->service,'entity_type'=>'users','legacy_id'=>(string) $error->legacy_id])->value('target_id');
            $imported = $store->mapping($error->service,'users',$error->legacy_id);
            if ($imported !== null && (int) $imported !== (int) $employee->id) throw new DomainException('Пользователь уже переносился к другому сотруднику. Сначала выполните откат.');
            \Illuminate\Support\Facades\DB::table('migration_overrides')->updateOrInsert(['service'=>$error->service,'entity_type'=>'users','legacy_id'=>(string) $error->legacy_id], ['target_id'=>(string) $employee->id,'note'=>json_encode(['source_fingerprint'=>\App\Migration\Core\EmployeeUserOverrides::fingerprint($source),'target_login'=>$employee->login,'source_key'=>\App\Migration\Core\EmployeeLoginRegistry::sourceKey($error->service)]),'created_at'=>now(),'updated_at'=>now()]);
            \App\Migration\Core\EmployeeLoginRegistry::save($source, $employee->login, (string) data_get($access,'employee.id','platform-privileged'), false, $error->service);
            $store->event((int) $error->migration_run_id,'employee_override_saved','Оператор сохранил сопоставление пользователя. Повторите Dry run.','info',['legacy_user_id'=>$error->legacy_id,'employee_id'=>$employee->id,'previous_employee_id'=>$previous,'actor'=>data_get($access,'employee.id','platform-privileged')]);
            return $employee;
        });
        return response()->json(['data'=>['employee'=>$employee,'message'=>'Сопоставление логинов сохранено и останется после отката. Повторите Dry run.']]);
    } catch (DomainException $e) { return response()->json(['message'=>$e->getMessage()],422); }
    catch (Throwable $e) { return \App\Migration\Core\EmployeeMappingFailure::response($e, $stage); }
});
