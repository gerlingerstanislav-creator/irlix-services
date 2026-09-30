<?php

// Run with Laravel 12 dependencies: CLIENTS_TEST_AUTOLOAD=/app/vendor/autoload.php php tests/workflow_smoke.php
namespace App\Http\Controllers {
    // The production image supplies this empty base class from the Laravel skeleton.
    abstract class Controller {}
}

namespace {
    require getenv('CLIENTS_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
    spl_autoload_register(function (string $class): void {
        if (str_starts_with($class, 'App\\')) {
            $path = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            if (is_file($path)) require $path;
        }
    });

    $app = new \Illuminate\Foundation\Application(sys_get_temp_dir().'/clients-workflow-smoke');
    $app->instance('request', \Illuminate\Http\Request::create('http://localhost/'));
    $app->instance('config', new \Illuminate\Config\Repository([
        'app' => ['key' => 'test-key', 'locale' => 'en', 'fallback_locale' => 'en'],
        'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]],
        'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
    ]));
    \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    foreach ([
        \Illuminate\Filesystem\FilesystemServiceProvider::class,
        \Illuminate\Database\DatabaseServiceProvider::class,
        \Illuminate\View\ViewServiceProvider::class,
        \Illuminate\Translation\TranslationServiceProvider::class,
        \Illuminate\Validation\ValidationServiceProvider::class,
    ] as $provider) $app->register($provider);
    \Illuminate\Http\Request::macro('validate', function (array $rules): array {
        return app('validator')->make($this->all(), $rules)->validate();
    });

    $schema = \Illuminate\Support\Facades\DB::connection()->getSchemaBuilder();
    $schema->create('client_requests', function ($t): void {
        $t->id(); $t->string('status'); $t->timestamps();
    });
    $schema->create('positions', function ($t): void {
        $t->id(); $t->integer('client_request_id'); $t->string('status');
        $t->string('direction')->nullable(); $t->integer('direction_department_id')->nullable(); $t->timestamps();
    });
    $schema->create('connection_attempts', function ($t): void {
        $t->id(); $t->integer('position_id'); $t->string('status'); $t->text('failure_reasons')->nullable();
        $t->timestamp('closed_at')->nullable(); $t->string('cv_storage_path')->nullable();
        $t->timestamp('cv_sent_at')->nullable(); $t->date('connection_date')->nullable(); $t->timestamps();
        $t->boolean('is_external')->default(false); $t->string('closed_from_status')->nullable();
        $t->integer('specialist_id')->nullable(); $t->string('specialist_name')->nullable();
        $t->integer('responsible_employee_id')->nullable(); $t->date('control_date')->nullable();
        $t->decimal('proposed_rate')->nullable(); $t->text('description')->nullable();
        $t->string('cv_original_name')->nullable(); $t->string('cv_mime_type')->nullable();
        $t->integer('cv_size_bytes')->nullable(); $t->timestamp('cv_uploaded_at')->nullable();
    });
    $schema->create('attempt_interviews', function ($t): void {
        $t->id(); $t->integer('connection_attempt_id'); $t->integer('sequence');
        $t->dateTime('scheduled_at'); $t->dateTime('completed_at')->nullable();
        $t->string('rating')->nullable(); $t->text('feedback')->nullable(); $t->timestamps();
    });
    $schema->create('project_members', function ($t): void { $t->id(); $t->integer('source_attempt_id')->nullable(); });

    $checks = 0;
    function check(bool $condition, string $message): void {
        global $checks;
        if (!$condition) throw new \RuntimeException($message);
        $checks++;
    }
    function rejected(callable $action, int $code, ?string $message = null): void {
        try { $action(); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            check($e->getStatusCode() === $code && ($message === null || $message === $e->getMessage()), 'Unexpected rejection: '.$e->getMessage());
            return;
        }
        throw new \RuntimeException('Expected rejection');
    }
    function workflowRequest(array $input = [], array $roles = ['account-manager']): \Illuminate\Http\Request {
        $request = \Illuminate\Http\Request::create('/', 'PATCH', $input);
        $request->attributes->set('client_contour_access', ['roles' => $roles]);
        return $request;
    }
    function seed(array $statuses): void {
        foreach (['attempt_interviews', 'connection_attempts', 'positions', 'client_requests'] as $table) \Illuminate\Support\Facades\DB::table($table)->delete();
        \Illuminate\Support\Facades\DB::table('client_requests')->insert(['id' => 1, 'status' => 'Открыт']);
        \Illuminate\Support\Facades\DB::table('positions')->insert([['id' => 1, 'client_request_id' => 1, 'status' => 'Открыт'], ['id' => 2, 'client_request_id' => 1, 'status' => 'Открыт']]);
        foreach ($statuses as $index => $status) \Illuminate\Support\Facades\DB::table('connection_attempts')->insert(['id' => $index + 1, 'position_id' => $index === 0 ? 1 : 2, 'status' => $status, 'cv_storage_path' => 'test.pdf']);
    }
    $controller = new \App\Http\Controllers\RequestWorkflowController();
    foreach (['CV отправлено', 'Интервью назначено', 'Интервью пройдено', 'Ожидает подключения'] as $status) {
        seed(['Новая', $status]);
        rejected(fn () => $controller->updateRequest(workflowRequest(['status' => 'Закрыт']), 1), 422, 'Сперва закройте активные попытки');
        check(\Illuminate\Support\Facades\DB::table('client_requests')->find(1)->status === 'Открыт', 'Request changed on failure');
        check(\Illuminate\Support\Facades\DB::table('connection_attempts')->find(1)->status === 'Новая', 'Partial attempt closure');
        rejected(fn () => $controller->updatePosition(workflowRequest(['status' => 'Закрыт']), 2), 422, 'Сперва закройте активные попытки');
    }
    seed(['Новая', 'Закрыт: успех', 'Закрыт: неудача']);
    $controller->updateRequest(workflowRequest(['status' => 'Закрыт']), 1);
    check(\Illuminate\Support\Facades\DB::table('positions')->where('status', 'Закрыт')->count() === 2, 'Request did not close positions');
    $attempt = \Illuminate\Support\Facades\DB::table('connection_attempts')->find(1);
    check($attempt->status === 'Закрыт: неудача' && json_decode($attempt->failure_reasons, true) === ['Запрос закрыт'] && $attempt->closed_at && $attempt->closed_from_status === 'Новая', 'New attempt reason/date missing');
    check(\Illuminate\Support\Facades\DB::table('connection_attempts')->find(2)->status === 'Закрыт: успех', 'Closed history changed');
    rejected(fn () => $controller->sendCv(workflowRequest(), 1), 422);
    rejected(fn () => $controller->updatePosition(workflowRequest(['status' => 'Открыт']), 1), 422);
    seed(['Новая', 'CV отправлено']);
    $controller->updatePosition(workflowRequest(['status' => 'Закрыт']), 1);
    check(\Illuminate\Support\Facades\DB::table('client_requests')->find(1)->status === 'Открыт' && \Illuminate\Support\Facades\DB::table('positions')->find(2)->status === 'Открыт', 'Position closure changed siblings');
    seed(['Новая']);
    $controller->sendCv(workflowRequest(), 1);
    rejected(fn () => $controller->destroyAttempt(workflowRequest([], ['department-manager']), 1), 422);
    rejected(fn () => $controller->closeFailure(workflowRequest(['reasons' => ['Интервью: отменено']]), 1), 422);
    $controller->scheduleInterview(workflowRequest(['scheduled_at' => '2026-10-01 12:00:00']), 1);
    $controller->scheduleInterview(workflowRequest(['scheduled_at' => '2026-10-02 12:00:00']), 1);
    $interviews = \Illuminate\Support\Facades\DB::table('attempt_interviews')->orderBy('sequence')->get();
    check($interviews->pluck('sequence')->all() === [1, 2], 'Interview history/numbering broken');
    $controller->completeInterview(workflowRequest(['rating' => 'Положительно', 'feedback' => 'Synthetic result']), 1, $interviews[0]->id);
    check(\Illuminate\Support\Facades\DB::table('connection_attempts')->find(1)->status === 'Интервью назначено', 'Pending second interview ignored');
    $controller->completeInterview(workflowRequest(['rating' => 'Положительно', 'feedback' => 'Synthetic result']), 1, $interviews[1]->id);
    $controller->scheduleConnection(workflowRequest(['connection_date' => '2026-10-05']), 1);
    $controller->closeSuccess(workflowRequest(), 1);
    check(\Illuminate\Support\Facades\DB::table('connection_attempts')->find(1)->status === 'Закрыт: успех' && \Illuminate\Support\Facades\DB::table('positions')->find(1)->status === 'Открыт', 'Success closed position');
    seed(['Новая']);
    rejected(fn () => $controller->updateRequest(workflowRequest(['status' => 'Закрыт'], ['department-manager']), 1), 403);
    $controller->destroyAttempt(workflowRequest([], ['department-manager']), 1);
    check(!\Illuminate\Support\Facades\DB::table('connection_attempts')->exists(), 'New attempt not deleted');
    check(\App\Support\ProductionDirection::id(['direction_department_id' => 9, 'direction' => 'Old name'], ['legacy_direction_ids' => ['Old name' => 2]]) === 9, 'Name overrides stable identity');
    check(\App\Support\ProductionDirection::id(['direction' => 'Duplicate'], ['legacy_direction_ids' => []]) === 0, 'Ambiguous legacy name grants access');
    check(!\App\Support\ClientContourAccess::supportsPermission('department-manager', 'requests.view'), 'Production manager sees Requests');
    check(\App\Support\ClientContourAccess::supportsPermission('department-manager', 'attempts.manage'), 'Production manager cannot create attempts');
    check(\App\Support\ClientContourAccess::supportsPermission('platform-admin', 'requests.manage'), 'Admin lost access');
    // Exercise the real access resolver and middleware with a synthetic Employees API.
    $schema->create('client_contour_permissions', function ($t): void {
        $t->id(); $t->string('role'); $t->string('permission'); $t->boolean('allowed'); $t->string('scope');
    });
    foreach (array_keys(\App\Support\ClientContourAccess::PERMISSION_LABELS) as $permission) {
        \Illuminate\Support\Facades\DB::table('client_contour_permissions')->insert(['role' => 'department-manager', 'permission' => $permission, 'allowed' => true, 'scope' => 'all']);
    }
    \Illuminate\Support\Facades\DB::table('client_contour_permissions')->insert(['role' => 'employee', 'permission' => 'clients.view', 'allowed' => true, 'scope' => 'all']);
    $schema->create('clients', function ($t): void { $t->id(); $t->integer('account_employee_id'); $t->integer('sales_employee_id'); });
    $schema->table('client_requests', function ($t): void { $t->integer('client_id')->nullable(); $t->integer('responsible_employee_id')->nullable(); });
    $departments = [
        ['id' => 10, 'name' => 'Synthetic direction', 'parent_id' => null, 'manager_id' => 101, 'is_production' => true],
        ['id' => 11, 'name' => 'Synthetic child', 'parent_id' => 10, 'manager_id' => null, 'is_production' => false],
        ['id' => 12, 'name' => 'Synthetic grandchild', 'parent_id' => 11, 'manager_id' => 102, 'is_production' => true],
        ['id' => 20, 'name' => 'Synthetic direction', 'parent_id' => null, 'manager_id' => 999, 'is_production' => false],
    ];
    $people = [['id' => 201, 'department_id' => 12], ['id' => 202, 'department_id' => 20], ['id' => 203, 'department_id' => 11]];
    $actorId = 101;
    \Illuminate\Support\Facades\Http::fake(function ($request) use (&$actorId, $departments, $people) {
        $data = match (true) {
            str_ends_with($request->url(), '/clients-directory') => ['departments' => $departments, 'employees' => $people],
            str_ends_with($request->url(), '/absence-approval-context') => ['department_chain' => []],
            str_ends_with($request->url(), '/access/me') => ['roles' => ['manager', 'department-manager'], 'department_ids' => [10, 20]],
            default => ['id' => $actorId, 'position' => 'Synthetic specialist'],
        };
        return \Illuminate\Support\Facades\Http::response(['data' => $data], 200);
    });
    $resolver = new \App\Support\ClientContourAccess();
    $access = $resolver->resolve(workflowRequest());
    check($access['production_department_ids'] === [10, 11, 12], 'Production tree missed descendants or included unrelated department');
    check($access['production_employee_ids'] === [201, 203], 'Specialists escaped department tree');
    check($access['attempt_employee_ids'] === [201], 'Attempt selector includes nonproduction employees');
    check(!isset($access['legacy_direction_ids']['Synthetic direction']), 'Duplicate names grant legacy access');
    check(!isset($access['permissions']['requests.view']) && !isset($access['permissions']['clients.view']) && isset($access['permissions']['attempts.manage']), 'Production role exposes other pages');
    check(!isset($access['client_service_permissions']['attempts.manage']), 'Production grants expand client-service scope');
    seed(['Новая']);
    \Illuminate\Support\Facades\DB::table('clients')->insert(['id' => 1, 'account_employee_id' => 202, 'sales_employee_id' => 202]);
    \Illuminate\Support\Facades\DB::table('client_requests')->where('id', 1)->update(['client_id' => 1, 'responsible_employee_id' => 202]);
    \Illuminate\Support\Facades\DB::table('positions')->where('id', 1)->update(['direction_department_id' => 12, 'direction' => 'Synthetic grandchild']);
    \Illuminate\Support\Facades\DB::table('positions')->where('id', 2)->update(['direction_department_id' => 20, 'direction' => 'Synthetic direction']);
    $payload = ['data' => ['requests' => [[
        'id' => 1, 'client_id' => 1, 'title' => 'Synthetic request', 'status' => 'Открыт',
        'responsible_employee_id' => 202, 'description' => 'Private request description',
        'positions' => [['id' => 1, 'direction_department_id' => 12, 'attempts' => []], ['id' => 2, 'direction_department_id' => 20, 'attempts' => []]],
    ]], 'clients' => [['id' => 1, 'name' => 'Synthetic client', 'projects' => [['commercial_terms' => 'private']]]]]];
    $middleware = new \App\Http\Middleware\ClientsAccountingAccess($resolver);
    $response = $middleware->handle(\Illuminate\Http\Request::create('/api/overview'), fn () => new \Illuminate\Http\JsonResponse($payload));
    $visible = $response->getData(true)['data'];
    check(count($visible['requests']) === 1 && array_column($visible['requests'][0]['positions'], 'id') === [1], 'Overview leaks unrelated positions');
    check(!isset($visible['requests'][0]['description']) && !isset($visible['clients'][0]['projects']), 'Overview leaks request/client detail');
    check($middleware->handle(\Illuminate\Http\Request::create('/api/positions/2/attempts', 'POST'), fn () => new \Illuminate\Http\JsonResponse())->getStatusCode() === 403, 'Direct attempt endpoint escapes tree');
    check($middleware->handle(\Illuminate\Http\Request::create('/api/positions/1/attempts', 'POST'), fn () => new \Illuminate\Http\JsonResponse())->getStatusCode() === 200, 'Own descendant position inaccessible');
    check($middleware->handle(\Illuminate\Http\Request::create('/api/requests/1', 'PATCH'), fn () => new \Illuminate\Http\JsonResponse())->getStatusCode() === 403, 'Production manager edits Requests');
    $actorId = 999;
    $generic = $resolver->resolve(workflowRequest());
    check(!in_array('department-manager', $generic['roles'], true) && !isset($generic['permissions']['attempts.manage']), 'Nonproduction manager gains Clients role');
    check(isset($generic['permissions']['timesheets.management.view']), 'Generic manager lost existing Timesheets grants');
    seed([]);
    $create = workflowRequest(['specialist_id' => 201, 'specialist_name' => 'Synthetic specialist'], ['department-manager']);
    $create->attributes->set('client_contour_access', $access);
    try {
        $controller->storeAttempt($create, 1);
        throw new \RuntimeException('Attempt accepted without CV');
    } catch (\Illuminate\Validation\ValidationException $e) {
        check(isset($e->errors()['cv']), 'Missing CV validation absent');
    }
    $file = tempnam(sys_get_temp_dir(), 'clients-cv-');
    file_put_contents($file, "%PDF-1.4\nSynthetic test CV\n%%EOF\n");
    $create = workflowRequest(['specialist_id' => 201, 'specialist_name' => 'Synthetic specialist'], ['department-manager']);
    $create->attributes->set('client_contour_access', $access);
    $create->files->set('cv', new \Illuminate\Http\UploadedFile($file, 'synthetic.pdf', 'application/pdf', null, true));
    $create->merge(['specialist_id' => 202]);
    rejected(fn () => $controller->storeAttempt($create, 1), 422);
    $create->merge(['specialist_id' => 203]);
    rejected(fn () => $controller->storeAttempt($create, 1), 422);
    $create->merge(['specialist_id' => 201]);
    \Illuminate\Support\Facades\DB::table('positions')->where('id', 1)->update(['status' => 'Закрыт']);
    rejected(fn () => $controller->storeAttempt($create, 1), 422);
    check(!\Illuminate\Support\Facades\DB::table('connection_attempts')->exists(), 'Closed position accepted attempt');
    check(!glob(storage_path('app/clients/cv/*')), 'Failed creation left CV behind');
    \Illuminate\Support\Facades\DB::table('positions')->where('id', 1)->update(['status' => 'Открыт']);
    $file = tempnam(sys_get_temp_dir(), 'clients-cv-');
    file_put_contents($file, "%PDF-1.4\nSynthetic test CV\n%%EOF\n");
    $create = workflowRequest(['specialist_id' => 201, 'specialist_name' => 'Synthetic specialist'], ['department-manager']);
    $create->attributes->set('client_contour_access', $access);
    $create->files->set('cv', new \Illuminate\Http\UploadedFile($file, 'synthetic.pdf', 'application/pdf', null, true));
    $created = $controller->storeAttempt($create, 1)->getData(true)['data'];
    check($created['status'] === 'Новая' && (int) $created['specialist_id'] === 201 && $created['cv_original_name'] === 'synthetic.pdf', 'Valid attempt creation failed');
    $controller->destroyAttempt(workflowRequest([], ['department-manager']), (int) $created['id']);
    check(!glob(storage_path('app/clients/cv/*')), 'Deleted attempt left CV behind');
    $file = tempnam(sys_get_temp_dir(), 'clients-cv-');
    file_put_contents($file, "%PDF-1.4\nSynthetic external CV\n%%EOF\n");
    $external = workflowRequest(['is_external' => true, 'specialist_id' => 202, 'specialist_name' => 'Synthetic External Specialist', 'description' => 'Synthetic comment'], ['department-manager']);
    $external->attributes->set('client_contour_access', $access);
    $external->files->set('cv', new \Illuminate\Http\UploadedFile($file, 'synthetic.pdf', 'application/pdf', null, true));
    $external->merge(['specialist_name' => '   ']);
    try {
        $controller->storeAttempt($external, 1);
        throw new \RuntimeException('External attempt accepted without name');
    } catch (\Illuminate\Validation\ValidationException $e) {
        check(isset($e->errors()['specialist_name']), 'Missing external name validation absent');
    }
    $external->merge(['specialist_name' => 'Synthetic External Specialist']);
    $created = $controller->storeAttempt($external, 1)->getData(true)['data'];
    check($created['specialist_id'] === null && $created['is_external'] && $created['description'] === 'Synthetic comment', 'External attempt linked to an employee');
    $controller->destroyAttempt(workflowRequest([], ['department-manager']), (int) $created['id']);
    check(!glob(storage_path('app/clients/cv/*')), 'External deletion left CV behind');
    foreach (['Новая', 'CV отправлено', 'Интервью назначено', 'Интервью пройдено', 'Ожидает подключения'] as $status) {
        seed([$status]);
        $controller->closeFailure(workflowRequest(['reasons' => ['Запрос закрыт']], $status === 'Новая' ? ['department-manager'] : ['account-manager']), 1);
        $closed = \Illuminate\Support\Facades\DB::table('connection_attempts')->find(1);
        check($closed->status === 'Закрыт: неудача' && $closed->closed_from_status === $status, 'Failure stage was not preserved');
    }
    echo "Clients workflow and access smoke: {$checks} checks passed\n";
}
