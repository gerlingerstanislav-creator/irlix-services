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
    \Illuminate\Database\Eloquent\Model::setConnectionResolver($app['db']);
    \Illuminate\Http\Request::macro('validate', function (array $rules): array {
        return app('validator')->make($this->all(), $rules)->validate();
    });
    $schema = \Illuminate\Support\Facades\DB::connection()->getSchemaBuilder();
    $schema->create('client_contour_permissions', function ($t) {
        $t->id(); $t->string('role'); $t->string('permission'); $t->boolean('allowed'); $t->string('scope'); $t->timestamps();
        $t->unique(['role', 'permission']);
    });
    // Old tester permission grants must remain ineffective after privilege removal.
    foreach (['platform-tester', 'account-manager'] as $role) {
        foreach (array_keys(\App\Support\ClientContourAccess::PERMISSION_LABELS) as $permission) {
            \Illuminate\Support\Facades\DB::table('client_contour_permissions')->insert(compact('role', 'permission') + ['allowed' => true, 'scope' => 'all']);
        }
    }
    foreach (['clients.view', 'contacts.view'] as $permission) {
        \Illuminate\Support\Facades\DB::table('client_contour_permissions')->insert([
            'role' => 'client-service', 'permission' => $permission,
            'allowed' => true, 'scope' => 'own',
        ]);
    }
    $db = \Illuminate\Support\Facades\DB::table('client_contour_permissions');
    foreach (['leads.view','requests.view','positions.view','attempts.view'] as $permission) {
        $db->insert(['role'=>'sales-manager','permission'=>$permission,'allowed'=>true,'scope'=>'own']);
    }
    $roles = [];
    $actorStub = ['id' => 1, 'position' => 'Account Manager'];
    $chainStub = [];
    $departmentsStub = [];
    $directoryEmployeesStub = [];
    $accessDepartmentIdsStub = [];
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake(function ($request) use (&$roles, &$actorStub, &$chainStub, &$departmentsStub, &$directoryEmployeesStub, &$accessDepartmentIdsStub) {
        $url = parse_url($request->url(), PHP_URL_PATH);
        $data = match ($url) {
            '/api/self' => $actorStub,
            '/api/access/me' => ['roles' => $roles, 'department_ids' => $accessDepartmentIdsStub],
            '/api/self/absence-approval-context' => ['department_chain' => $chainStub],
            '/api/clients-directory' => [
                'departments' => $departmentsStub, 'employees' => $directoryEmployeesStub,
                'actor' => [
                    'id' => $actorStub['id'],
                    'department_id' => collect($directoryEmployeesStub)->first(fn ($row) => $row['id'] === $actorStub['id'])['department_id'] ?? null,
                    'functional_roles' => $actorStub['functional_roles'] ?? [],
                ],
            ],
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
    $check(
        \App\Support\ClientContourAccess::importedMatrixLabels([
            'accounting', 'accounting-head', 'sales', 'account-management-head',
            'service:clients', 'permission:finance-view', 'scope:all', 'employee-id:42',
        ]) === ['sales', 'account-management-head'],
        'Finance accountant leaked into Clients account-head permission matrix'
    );
    $request = fn ($path, $method = 'GET', $data = []) => \Illuminate\Http\Request::create('/'.$path, $method, $data, [], [], ['HTTP_AUTHORIZATION' => 'Bearer synthetic-token']);
    foreach ([['platform-tester'], [' PLATFORM_TESTER ', 'sales-manager', 'department-manager']] as $roles) {
        $access = $resolver->resolve($request('api/permissions/me'));
        $check(!$access['clients_service_blocked'] && !$access['platform_admin'], 'Tester must remain non-admin with configurable access');
        $check(!($access['permissions']['clients.view']['allowed'] ?? false), 'Legacy tester Clients grant escalated privileges');
        $check(!($access['permissions']['timesheets.management.view']['allowed'] ?? false), 'Legacy tester Timesheets grant escalated privileges');
    }
    foreach ([['platform-admin'], ['platform-admin', 'platform-tester']] as $roles) {
        $access = $resolver->resolve($request('api/permissions/me'));
        $check(!$access['clients_service_blocked'] && $access['platform_admin'], 'Actual administrator blocked');
        foreach ($access['permissions'] as $value) $check($value['allowed'] && $value['scope'] === 'all', 'Admin permission changed');
        $check($guard->handle($request('api/clients/1/card'), fn () => response()->noContent())->getStatusCode() === 204, 'Admin API denied');
    }
    $roles = [];
    $chainStub = [['id'=>1,'name'=>'Аккаунтинг','alias'=>'account-management']];
    $access = $resolver->resolve($request('api/permissions/me'));
    $check(!$access['clients_service_blocked'] && $access['permissions']['clients.view']['allowed'], 'Organizational account-management access changed');
    $chainStub = [];
    $roles = ['platform-admin'];
    $controller = new \App\Http\Controllers\ClientContourPermissionsController();
    $matrix = $controller->index($request('api/permissions'), $resolver)->getData(true)['data']['matrix'];
    $check(!isset($matrix['platform-tester']), 'Retired tester grants exposed in editable matrix');
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
    $check(!str_contains($controllerCode, "str_contains(\$position, 'руководитель"), 'Rollback authorization still depends on a free-text job title');

    // Old company structures have a Client Service -> Accounting -> team
    // hierarchy and may still use the short accounting alias. Only accounting
    // in the CLIENT subtree is an Account Manager, never Finance accounting.
    $departmentsStub = [
        ['id'=>101,'name'=>'Client Service','alias'=>'client-service','parent_id'=>null,'manager_id'=>514],
        ['id'=>102,'name'=>'ACCOUNTING','alias'=>'accounting','parent_id'=>101,'manager_id'=>513],
        ['id'=>103,'name'=>'Account delivery','alias'=>null,'parent_id'=>102,'manager_id'=>null],
        ['id'=>104,'name'=>'Finance','alias'=>'finance','parent_id'=>null,'manager_id'=>null],
        ['id'=>105,'name'=>'Accounting','alias'=>'accounting','parent_id'=>104,'manager_id'=>null],
        ['id'=>106,'name'=>'Sales','alias'=>'sales','parent_id'=>101,'manager_id'=>512],
        ['id'=>107,'name'=>'Client Operations','alias'=>'client-operations','parent_id'=>101,'manager_id'=>null],
    ];
    $directoryEmployeesStub = [
        ['id'=>501,'department_id'=>103],
        ['id'=>502,'department_id'=>105],
        ['id'=>503,'department_id'=>106],
        ['id'=>508,'department_id'=>107],
        ['id'=>509,'department_id'=>101,'position'=>'account-manager'],
        ['id'=>510,'department_id'=>105,'position'=>'account-manager'],
        ['id'=>511,'department_id'=>101,'position'=>'Sales Manager'],
        ['id'=>512,'department_id'=>106,'position'=>'Sales Manager'],
        ['id'=>513,'department_id'=>102,'position'=>'Account Manager'],
        ['id'=>514,'department_id'=>101,'position'=>'Client Service Head'],
    ];
    $roles = [];
    $chainStub = [];
    $actorStub = ['id'=>501,'position'=>'Specialist'];
    $nestedAccount = $resolver->resolve($request('api/permissions/me'));
    $check(in_array('account-manager', $nestedAccount['roles'], true), 'Nested account employee lost Account Manager role');
    $check(($nestedAccount['permissions']['clients.view']['scope'] ?? 'none') === 'all', 'Account Manager matrix permission missing');
    $check(($nestedAccount['permissions']['timesheets.management.view']['allowed'] ?? false) === true, 'Account Manager Timesheets right missing');
    $check(in_array(501, $nestedAccount['account_employee_ids'], true) && !in_array(502, $nestedAccount['account_employee_ids'], true), 'Finance employee mixed into account directory');
    $actorStub = ['id'=>502,'position'=>'Finance specialist'];
    $financeAccount = $resolver->resolve($request('api/permissions/me'));
    $check(!in_array('account-manager', $financeAccount['roles'], true), 'Finance accounting inherited Clients role');
    $actorStub = ['id'=>503,'position'=>'Sales specialist'];
    $salesAccount = $resolver->resolve($request('api/permissions/me'));
    $check(in_array('sales-manager', $salesAccount['roles'], true), 'Sales department lost Clients role');
    $check(in_array('client-service', $salesAccount['roles'], true), 'Sales lost parent client-service baseline');
    $actorStub = ['id'=>508,'position'=>'Operations'];
    $clientOperations = $resolver->resolve($request('api/permissions/me'));
    $check(in_array('client-service', $clientOperations['roles'], true), 'Client-service descendant lost base role');
    $check(($clientOperations['permissions']['clients.view']['scope'] ?? '') === 'own', 'Client-service baseline must be own-scope');
    $check(!($clientOperations['permissions']['clients.manage']['allowed'] ?? false), 'Client-service baseline granted management actions');

    // Real organizational clients can appoint Account Managers directly in
    // Client Service; account-management is not necessarily their department.
    $actorStub = ['id'=>509,'position'=>'account-manager','functional_roles'=>['account-manager']];
    $positionAccount = $resolver->resolve($request('api/permissions/me'));
    $check(in_array('account-manager', $positionAccount['roles'], true),
        'Verified Account Manager position did not yield functional client role');
    foreach (['reports.view', 'cashflow.view', 'requests.view', 'positions.view',
              'attempts.view', 'timesheets.management.view', 'timesheets.management.manage'] as $permission) {
        $check(($positionAccount['permissions'][$permission]['allowed'] ?? false) === true,
            'Account Manager denied '. $permission);
    }
    $actorStub = ['id'=>510,'position'=>'account-manager','functional_roles'=>[]];
    $financeTitle = $resolver->resolve($request('api/permissions/me'));
    $check(!in_array('account-manager', $financeTitle['roles'], true),
        'Finance employee inherited account-management from same position title');

    // One resolver computes all functional and management matrix roles from
    // the same signed/verified Employees directory, not separate page hacks.
    $actorStub = ['id'=>511,'position'=>'Sales Manager','functional_roles'=>['sales-manager']];
    $directSales = $resolver->resolve($request('api/permissions/me'));
    $check(in_array('sales-manager', $directSales['roles'], true),
        'Client Service Sales position did not receive sales-manager');
    $check(!in_array('sales-head', $directSales['roles'], true),
        'Sales job title unexpectedly granted a manager role');
    foreach (['leads.view','requests.view','positions.view','attempts.view'] as $permission) {
        $check(($directSales['permissions'][$permission]['scope'] ?? '') === 'own',
            'Sales role matrix not applied to '.$permission);
    }
    $actorStub = ['id'=>510,'position'=>'Sales Manager','functional_roles'=>[]];
    $financeSalesTitle = $resolver->resolve($request('api/permissions/me'));
    $check(!in_array('sales-manager', $financeSalesTitle['roles'], true),
        'Finance employee inherited functional Sales role by title');

    $matrixDb = \Illuminate\Support\Facades\DB::table('client_contour_permissions');
    foreach ([
        ['role'=>'sales-head','permission'=>'leads.view','scope'=>'team'],
        ['role'=>'accounting-head','permission'=>'reports.view','scope'=>'team'],
        ['role'=>'client-service-head','permission'=>'requests.view','scope'=>'all'],
    ] as $grant) {
        $matrixDb->insert($grant + ['allowed'=>true]);
    }
    $actorStub = ['id'=>512,'position'=>'Sales Manager','functional_roles'=>['sales-manager']];
    $accessDepartmentIdsStub = [106];
    $salesHeadMatrix = $resolver->resolve($request('api/permissions/me'));
    $check(in_array('sales-head', $salesHeadMatrix['roles'], true)
        && ($salesHeadMatrix['permissions']['leads.view']['scope'] ?? '') === 'team',
        'Sales leader did not keep editable team scope');
    $check(in_array(503, $salesHeadMatrix['team_employee_ids'], true),
        'Sales leader did not receive team scope from Employees');
    $actorStub = ['id'=>513,'position'=>'Account Manager','functional_roles'=>['account-manager']];
    $accessDepartmentIdsStub = [102,103];
    $accountHeadMatrix = $resolver->resolve($request('api/permissions/me'));
    $check(in_array('accounting-head', $accountHeadMatrix['roles'], true)
        && ($accountHeadMatrix['permissions']['reports.view']['scope'] ?? '') === 'team',
        'Account leader did not keep editable team scope');
    $actorStub = ['id'=>514,'position'=>'Client Service Head','functional_roles'=>[]];
    $accessDepartmentIdsStub = [101,102,103,106,107];
    $clientHeadMatrix = $resolver->resolve($request('api/permissions/me'));
    $check(in_array('client-service-head', $clientHeadMatrix['roles'], true)
        && ($clientHeadMatrix['permissions']['requests.view']['scope'] ?? '') === 'all',
        'Client Service head did not keep independently editable all scope');
    $accessDepartmentIdsStub = [];

    $actorStub = ['id'=>511,'position'=>'Sales Manager','functional_roles'=>['sales-manager']];
    // JWT labels describe DIRECT organizational membership only; the verified
    // employee ID and Employees directory must recover ancestry in this mode.
    $oldJwtMode = getenv('IRLIX_AUTHZ_JWT_ENABLED');
    putenv('IRLIX_AUTHZ_JWT_ENABLED=true');
    $jwtRequest = $request('api/permissions/me');
    $jwtRequest->attributes->set('identity', ['realm_roles'=>['employee-id:501','service:clients']]);
    $jwtAccount = $resolver->resolve($jwtRequest);
    $check(in_array('account-manager', $jwtAccount['roles'], true), 'JWT descendant Account Manager lost role');
    $check(($jwtAccount['permissions']['timesheets.management.view']['allowed'] ?? false) === true, 'JWT descendant Timesheets permission missing');
    $jwtFunctionalRequest = $request('api/permissions/me');
    $jwtFunctionalRequest->attributes->set('identity', ['realm_roles'=>[
        'employee-id:505', 'account-manager', 'service:clients', 'service:timesheets',
    ]]);
    $jwtFunctionalAccount = $resolver->resolve($jwtFunctionalRequest);
    $check(in_array('account-manager', $jwtFunctionalAccount['roles'], true), 'Signed functional account-manager role was ignored');
    $check(($jwtFunctionalAccount['permissions']['clients.view']['allowed'] ?? false) === true, 'Signed functional account-manager lost Clients access');
    $check(($jwtFunctionalAccount['permissions']['timesheets.management.view']['allowed'] ?? false) === true, 'Signed functional account-manager lost Timesheets access');
    $check(in_array('client-service', $jwtFunctionalAccount['roles'], true), 'Functional account-manager lost base client-service matrix role');
    $check(!in_array('client-service-head', $jwtFunctionalAccount['roles'], true), 'Functional account-manager inherited manager role');
    $jwtOperationsRequest = $request('api/permissions/me');
    $jwtOperationsRequest->attributes->set('identity', ['realm_roles'=>[
        'employee-id:508', 'client-operations', 'service:clients', 'service:timesheets',
    ]]);
    $jwtOperations = $resolver->resolve($jwtOperationsRequest);
    $check(($jwtOperations['permissions']['clients.view']['scope'] ?? '') === 'own',
        'JWT client-service child cannot open Clients workspace');
    $check(!($jwtOperations['permissions']['reports.view']['allowed'] ?? false),
        'JWT client-service baseline leaked other business sections');
    $jwtTitleRequest = $request('api/permissions/me');
    $jwtTitleRequest->attributes->set('identity', ['realm_roles'=>[
        'employee-id:509', 'client-service', 'service:clients', 'service:timesheets',
    ]]);
    $jwtTitle = $resolver->resolve($jwtTitleRequest);
    $check(in_array('account-manager', $jwtTitle['roles'], true),
        'JWT verified position lost Account Manager rights pending Keycloak reconciliation');
    foreach (['reports.view','cashflow.view','requests.view','positions.view',
              'attempts.view','timesheets.management.view'] as $permission) {
        $check(($jwtTitle['permissions'][$permission]['allowed'] ?? false) === true,
            'JWT verified position denied '. $permission);
    }
    $jwtSalesRequest = $request('api/permissions/me');
    $jwtSalesRequest->attributes->set('identity', ['realm_roles'=>[
        'employee-id:511','client-service','service:clients',
    ]]);
    $jwtSales = $resolver->resolve($jwtSalesRequest);
    $check(in_array('sales-manager', $jwtSales['roles'], true)
        && ($jwtSales['permissions']['leads.view']['scope'] ?? '') === 'own'
        && ($jwtSales['permissions']['positions.view']['scope'] ?? '') === 'own',
        'A signed JWT with no sales-manager label lost trusted Employees Sales projection');
    $jwtFinanceRequest = $request('api/permissions/me');
    $jwtFinanceRequest->attributes->set('identity', ['realm_roles'=>['employee-id:502','service:timesheets','accounting']]);
    $jwtFinance = $resolver->resolve($jwtFinanceRequest);
    $check(!in_array('account-manager', $jwtFinance['roles'], true), 'JWT finance accounting inherited account role');
    if ($oldJwtMode === false) putenv('IRLIX_AUTHZ_JWT_ENABLED');
    else putenv('IRLIX_AUTHZ_JWT_ENABLED='.$oldJwtMode);

    // Production matrices can contain old or manually disabled entries.
    // Repair only the owner-approved Account Manager workspaces with own
    // default scope, and preserve already wider customized scopes.
    \Illuminate\Support\Facades\DB::table('client_contour_permissions')
        ->where('role', 'account-manager')->where('permission', 'reports.view')
        ->update(['allowed'=>false, 'scope'=>'none']);
    \Illuminate\Support\Facades\DB::table('client_contour_permissions')
        ->where('role', 'account-manager')->where('permission', 'cashflow.view')
        ->update(['allowed'=>false, 'scope'=>'team']);
    $migration = require dirname(__DIR__).'/database/migrations/2026_10_27_000002_restore_account_manager_workspace_permissions.php';
    $migration->up();
    $report = \Illuminate\Support\Facades\DB::table('client_contour_permissions')
        ->where('role','account-manager')->where('permission','reports.view')->first();
    $cashflow = \Illuminate\Support\Facades\DB::table('client_contour_permissions')
        ->where('role','account-manager')->where('permission','cashflow.view')->first();
    $check((bool) $report->allowed && $report->scope === 'own',
        'Account Manager reports disabled in persisted matrix');
    $check((bool) $cashflow->allowed && $cashflow->scope === 'team',
        'Client matrix repair expanded or downgraded a configured scope');
    $migration->up();
    $check(\Illuminate\Support\Facades\DB::table('client_contour_permissions')
        ->where('role','account-manager')->where('permission','reports.view')->count() === 1,
        'Workspace restoration migration is not idempotent');
    // The global Specialists calendar is no longer a hidden organizational
    // exception: both its navigation and API use an editable matrix key.
    $specialistsMigration = require dirname(__DIR__).'/database/migrations/2026_10_27_000003_seed_specialists_matrix_permission.php';
    $specialistsMigration->up();
    $specialistsMigration->up();
    $check(\Illuminate\Support\Facades\DB::table('client_contour_permissions')
        ->where('role','client-service')->where('permission','specialists.view')->count() === 1,
        'Global specialists baseline migration duplicated permission rows');
    $actorStub = ['id'=>511,'position'=>'Sales Manager','functional_roles'=>['sales-manager']];
    $salesRoster = $resolver->resolve($request('api/permissions/me'));
    $check(($salesRoster['permissions']['specialists.view']['scope'] ?? '') === 'all',
        'Sales member lacks specialists calendar after baseline migration');
    $calendarController = new \App\Http\Controllers\ClientSpecialistsController();
    $checkRoster = new \ReflectionMethod($calendarController, 'verifyMembership');
    $rosterRequest = $request('api/specialists/filter-clients');
    $rosterRequest->attributes->set('client_contour_access', $salesRoster);
    $checkRoster->invoke($calendarController, $rosterRequest);
    $check(true, 'Authorized Sales calendar was refused');

    \Illuminate\Support\Facades\DB::table('client_contour_permissions')
        ->where('role','client-service')->where('permission','specialists.view')
        ->update(['allowed'=>false,'scope'=>'none']);
    $specialistsMigration->up(); // Existing administrator changes survive.
    $disabled = $resolver->resolve($request('api/permissions/me'));
    $check(!($disabled['permissions']['specialists.view']['allowed'] ?? false),
        'Editable specialist permission ignored an explicit deny');
    $rosterRequest->attributes->set('client_contour_access', $disabled);
    try {
        $checkRoster->invoke($calendarController, $rosterRequest);
        throw new \RuntimeException('Disabled specialists roster was unexpectedly accepted');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        $check($exception->getStatusCode() === 403, 'Specialist API denied wrong status');
    }
    \Illuminate\Support\Facades\DB::table('client_contour_permissions')
        ->where('role','client-service')->where('permission','specialists.view')
        ->update(['allowed'=>true,'scope'=>'own']);
    $rosterRequest->attributes->set('client_contour_access', $resolver->resolve($request('api/permissions/me')));
    try {
        $checkRoster->invoke($calendarController, $rosterRequest);
        throw new \RuntimeException('Own-scope calendar leaked the global roster');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        $check($exception->getStatusCode() === 403, 'Own-scope calendar denied wrong status');
    }
    echo "Clients tester and department alias access smoke passed: {$checks} checks\n";
}
