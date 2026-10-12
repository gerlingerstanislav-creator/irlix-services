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
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake(function ($request) use (&$roles) {
        $url = parse_url($request->url(), PHP_URL_PATH);
        $data = match ($url) {
            '/api/self' => ['id' => 1, 'position' => 'Account Manager'],
            '/api/access/me' => ['roles' => $roles, 'department_ids' => []],
            '/api/self/absence-approval-context' => ['department_chain' => []],
            '/api/clients-directory' => ['departments' => [], 'employees' => []],
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
    echo "Clients tester access smoke passed: {$checks} checks\n";
}
