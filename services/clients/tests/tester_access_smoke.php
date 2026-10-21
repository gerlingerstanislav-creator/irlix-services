<?php

namespace App\Http\Controllers { abstract class Controller {} }

namespace {
    require getenv('CLIENTS_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
    spl_autoload_register(function (string $class): void {
        if (str_starts_with($class, 'App\\')) {
            $path = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            if (is_file($path)) require $path;
        }
    });
    $app = new \Illuminate\Foundation\Application(sys_get_temp_dir().'/clients-tester-smoke');
    $app->instance('request', \Illuminate\Http\Request::create('/'));
    $app->instance('config', new \Illuminate\Config\Repository([
        'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]],
        'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
        'app' => ['locale' => 'en', 'fallback_locale' => 'en'],
    ]));
    \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    foreach ([\Illuminate\Filesystem\FilesystemServiceProvider::class, \Illuminate\View\ViewServiceProvider::class,
        \Illuminate\Database\DatabaseServiceProvider::class, \Illuminate\Translation\TranslationServiceProvider::class,
        \Illuminate\Validation\ValidationServiceProvider::class] as $provider) $app->register($provider);
    \Illuminate\Http\Request::macro('validate', function (array $rules): array {
        return app('validator')->make($this->all(), $rules)->validate();
    });
    $schema = \Illuminate\Support\Facades\DB::connection()->getSchemaBuilder();
    $schema->create('client_contour_permissions', function ($t) {
        $t->id(); $t->string('role'); $t->string('permission'); $t->boolean('allowed'); $t->string('scope');
    });
    // Configurable tester grants must apply without granting platform-admin status.
    foreach (['platform-tester', 'account-manager'] as $role) {
        foreach (array_keys(\App\Support\ClientContourAccess::PERMISSION_LABELS) as $permission) {
            \Illuminate\Support\Facades\DB::table('client_contour_permissions')->insert(compact('role', 'permission') + ['allowed' => true, 'scope' => 'all']);
        }
    }
    $roles = [];
    $actorStub = ['id' => 1, 'position' => 'Account Manager'];
    $chainStub = [];
    $departmentsStub = [];
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake(function ($request) use (&$roles, &$actorStub, &$chainStub, &$departmentsStub) {
        $url = parse_url($request->url(), PHP_URL_PATH);
        $data = match ($url) {
            '/api/self' => $actorStub,
            '/api/access/me' => ['roles' => $roles, 'department_ids' => []],
            '/api/self/absence-approval-context' => ['department_chain' => $chainStub],
            '/api/clients-directory' => ['departments' => $departmentsStub, 'employees' => []],
            default => throw new \RuntimeException('Unexpected dependency request'),
        };
        return \Illuminate\Support\Facades\Http::response(['data' => $data]);
    });
    $resolver = new \App\Support\ClientContourAccess();
    $guard = new \App\Http\Middleware\ClientsAccountingAccess($resolver);
    $transfer = new \App\Http\Middleware\TransferredClientSalesReadOnly($resolver);
    $checks = 0;
    $check = function (bool $condition, string $message) use (&$checks): void {
        if (!$condition) throw new \RuntimeException($message);
        $checks++;
    };
    $request = fn ($path, $method = 'GET', $data = []) => \Illuminate\Http\Request::create('/'.$path, $method, $data, [], [], ['HTTP_AUTHORIZATION' => 'Bearer synthetic-token']);
    foreach ([['platform-tester'], [' PLATFORM_TESTER ', 'sales-manager', 'department-manager']] as $roles) {
        $access = $resolver->resolve($request('api/permissions/me'));
        $check(!$access['clients_service_blocked'] && !$access['platform_admin'], 'Tester must remain non-admin with configurable access');
        $check(($access['permissions']['clients.view']['scope'] ?? null) === 'all', 'Configured tester Clients grant ignored');
        $check(($access['permissions']['timesheets.management.view']['scope'] ?? null) === 'all', 'Tester Timesheets grant ignored');
    }
    foreach ([['platform-admin'], ['platform-admin', 'platform-tester']] as $roles) {
        $access = $resolver->resolve($request('api/permissions/me'));
        $check(!$access['clients_service_blocked'] && $access['platform_admin'], 'Actual administrator blocked');
        foreach ($access['permissions'] as $value) $check($value['allowed'] && $value['scope'] === 'all', 'Admin permission changed');
        $check($guard->handle($request('api/clients/1/card'), fn () => response()->noContent())->getStatusCode() === 204, 'Admin API denied');
    }
    $roles = [];
    $access = $resolver->resolve($request('api/permissions/me'));
    $check(!$access['clients_service_blocked'] && $access['permissions']['clients.view']['allowed'], 'Ordinary Account access changed');
    $roles = ['platform-admin'];
    $controller = new \App\Http\Controllers\ClientContourPermissionsController();
    $matrix = $controller->index($request('api/permissions'), $resolver)->getData(true)['data']['matrix'];
    $check(!($matrix['platform-tester']['clients.view']['locked'] ?? false) && $matrix['platform-tester']['clients.view']['allowed'], 'Configurable tester matrix is misleading');
    // Accounting and account management are different departments. Their names are irrelevant.
    $departmentsStub = [
        ['id' => 1, 'name' => 'Аккаунтинг', 'alias' => 'account-management', 'manager_id' => 15, 'parent_id' => null],
        ['id' => 2, 'name' => 'Бухгалтерия', 'alias' => 'accounting', 'manager_id' => 25, 'parent_id' => null],
        ['id' => 3, 'name' => 'Клиентская служба', 'alias' => 'client-service', 'manager_id' => 20, 'parent_id' => null],
        ['id' => 4, 'name' => 'Продажи', 'alias' => 'sales', 'manager_id' => 21, 'parent_id' => null],
    ];
    $roles = [];
    $actorStub = ['id' => 10, 'position' => 'Бухгалтер'];
    $chainStub = [['id' => 2, 'name' => 'Бухгалтерия', 'alias' => 'accounting']];
    $accounting = $resolver->resolve($request('api/permissions/me'));
    $check(!in_array('account-manager', $accounting['roles'], true), 'Accounting incorrectly inherits client account-manager role');

    $actorStub = ['id' => 15, 'position' => 'Специалист'];
    $chainStub = [['id' => 1, 'name' => 'Аккаунтинг', 'alias' => 'account-management']];
    $accountHead = $resolver->resolve($request('api/permissions/me'));
    $check(in_array('account-manager', $accountHead['roles'], true), 'Account-management alias does not yield account-manager role');
    $check(in_array('accounting-head', $accountHead['roles'], true), 'Department manager is not account-management head');

    $actorStub = ['id' => 16, 'position' => 'Руководитель аккаунтинга'];
    $pretendHead = $resolver->resolve($request('api/permissions/me'));
    $check(!in_array('accounting-head', $pretendHead['roles'], true), 'Free-text position incorrectly yields head role');

    $actorStub = ['id' => 20, 'position' => 'Специалист'];
    $chainStub = [['id' => 3, 'name' => 'Клиентская служба', 'alias' => 'client-service']];
    $clientHead = $resolver->resolve($request('api/permissions/me'));
    $check(in_array('client-service-head', $clientHead['roles'], true), 'Client-service manager not detected by department ID');

    $actorStub = ['id' => 21, 'position' => 'Специалист'];
    $chainStub = [['id' => 4, 'name' => 'Продажи', 'alias' => 'sales']];
    $salesHead = $resolver->resolve($request('api/permissions/me'));
    $check(in_array('sales-head', $salesHead['roles'], true), 'Sales head not detected by department ID');

    // ReportingPeriod rollback must never infer head access from the job-title text.
    $controllerCode = file_get_contents(dirname(__DIR__).'/app/Http/Controllers/ReportingPeriodsController.php');
    $check(str_contains($controllerCode, "'accounting-head', 'client-service-head'"), 'Rollback does not use the canonical contour head roles');
    $check(!str_contains($controllerCode, "str_contains($position, 'руководитель"), 'Rollback authorization still depends on a free-text job title');

    echo "Clients tester and department alias access smoke passed: {$checks} checks\n";
}
