<?php

// Run with Laravel 12 dependencies: CLIENTS_TEST_AUTOLOAD=/app/vendor/autoload.php php tests/attempt_funnel_smoke.php
namespace App\Http\Controllers {
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

    $app = new \Illuminate\Foundation\Application(sys_get_temp_dir().'/clients-attempt-funnel-smoke');
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
    $schema->create('clients', function ($t): void {
        $t->id(); $t->string('name'); $t->integer('sales_employee_id')->nullable(); $t->integer('account_employee_id')->nullable();
    });
    $schema->create('leads', function ($t): void { $t->id(); $t->string('name'); });
    $schema->create('client_requests', function ($t): void {
        $t->id(); $t->integer('client_id')->nullable(); $t->integer('lead_id')->nullable(); $t->integer('responsible_employee_id')->nullable();
    });
    $schema->create('positions', function ($t): void {
        $t->id(); $t->integer('client_request_id'); $t->integer('direction_department_id')->nullable(); $t->string('direction')->nullable();
        $t->string('technology')->nullable(); $t->string('level')->nullable(); $t->integer('responsible_rn_employee_id')->nullable();
    });
    $schema->create('connection_attempts', function ($t): void {
        $t->id(); $t->integer('position_id'); $t->integer('specialist_id')->nullable(); $t->integer('responsible_employee_id')->nullable();
        $t->boolean('is_external')->default(false); $t->string('status'); $t->timestamp('created_at'); $t->timestamp('updated_at')->nullable();
        $t->timestamp('closed_at')->nullable(); $t->timestamp('cv_sent_at')->nullable(); $t->date('connection_date')->nullable();
        $t->string('closed_from_status')->nullable(); $t->text('failure_reasons')->nullable();
    });
    $schema->create('attempt_interviews', function ($t): void {
        $t->id(); $t->integer('connection_attempt_id'); $t->dateTime('scheduled_at'); $t->dateTime('completed_at')->nullable();
    });

    \Illuminate\Support\Facades\DB::table('clients')->insert([
        ['id' => 1, 'name' => 'Synthetic Client A', 'sales_employee_id' => 10, 'account_employee_id' => 20],
        ['id' => 2, 'name' => 'Synthetic Client B', 'sales_employee_id' => 11, 'account_employee_id' => 21],
    ]);
    \Illuminate\Support\Facades\DB::table('client_requests')->insert([
        ['id' => 1, 'client_id' => 1, 'lead_id' => null, 'responsible_employee_id' => 20],
        ['id' => 2, 'client_id' => 2, 'lead_id' => null, 'responsible_employee_id' => 21],
    ]);
    \Illuminate\Support\Facades\DB::table('positions')->insert([
        ['id' => 1, 'client_request_id' => 1, 'direction_department_id' => 100, 'direction' => 'Synthetic Backend', 'technology' => 'PHP', 'level' => 'Middle', 'responsible_rn_employee_id' => 1000],
        ['id' => 2, 'client_request_id' => 2, 'direction_department_id' => 200, 'direction' => 'Synthetic QA', 'technology' => 'QA', 'level' => 'Senior', 'responsible_rn_employee_id' => 2000],
    ]);
    $attempts = [
        ['id'=>1,'position_id'=>1,'specialist_id'=>1001,'responsible_employee_id'=>20,'is_external'=>false,'status'=>'Закрыт: успех','created_at'=>'2026-09-02 09:00:00','updated_at'=>'2026-10-03 09:00:00','closed_at'=>'2026-10-03 09:00:00','cv_sent_at'=>'2026-09-03 09:00:00','connection_date'=>'2026-10-01','closed_from_status'=>null,'failure_reasons'=>null],
        ['id'=>2,'position_id'=>1,'specialist_id'=>1001,'responsible_employee_id'=>20,'is_external'=>false,'status'=>'Закрыт: неудача','created_at'=>'2026-09-10 09:00:00','updated_at'=>'2026-09-12 09:00:00','closed_at'=>'2026-09-12 09:00:00','cv_sent_at'=>'2026-09-11 09:00:00','connection_date'=>null,'closed_from_status'=>'CV отправлено','failure_reasons'=>json_encode(['CV: Не пройдено'], JSON_UNESCAPED_UNICODE)],
        ['id'=>3,'position_id'=>1,'specialist_id'=>1001,'responsible_employee_id'=>20,'is_external'=>false,'status'=>'Интервью назначено','created_at'=>'2026-09-20 09:00:00','updated_at'=>'2026-09-22 09:00:00','closed_at'=>null,'cv_sent_at'=>'2026-09-21 09:00:00','connection_date'=>null,'closed_from_status'=>null,'failure_reasons'=>null],
        ['id'=>4,'position_id'=>1,'specialist_id'=>1001,'responsible_employee_id'=>20,'is_external'=>false,'status'=>'Закрыт: успех','created_at'=>'2026-08-15 09:00:00','updated_at'=>'2026-09-25 09:00:00','closed_at'=>'2026-09-25 09:00:00','cv_sent_at'=>'2026-08-16 09:00:00','connection_date'=>'2026-09-20','closed_from_status'=>null,'failure_reasons'=>null],
        ['id'=>5,'position_id'=>2,'specialist_id'=>2001,'responsible_employee_id'=>21,'is_external'=>false,'status'=>'Закрыт: успех','created_at'=>'2026-09-05 09:00:00','updated_at'=>'2026-09-10 09:00:00','closed_at'=>'2026-09-10 09:00:00','cv_sent_at'=>'2026-09-06 09:00:00','connection_date'=>'2026-09-09','closed_from_status'=>null,'failure_reasons'=>null],
    ];
    \Illuminate\Support\Facades\DB::table('connection_attempts')->insert($attempts);
    \Illuminate\Support\Facades\DB::table('attempt_interviews')->insert([
        ['id'=>1,'connection_attempt_id'=>1,'scheduled_at'=>'2026-09-10 12:00:00','completed_at'=>'2026-09-10 13:00:00'],
        ['id'=>2,'connection_attempt_id'=>3,'scheduled_at'=>'2026-09-23 12:00:00','completed_at'=>null],
        ['id'=>3,'connection_attempt_id'=>4,'scheduled_at'=>'2026-08-20 12:00:00','completed_at'=>'2026-08-20 13:00:00'],
        ['id'=>4,'connection_attempt_id'=>5,'scheduled_at'=>'2026-09-07 12:00:00','completed_at'=>'2026-09-07 13:00:00'],
    ]);

    $checks = 0;
    function funnelCheck(bool $condition, string $message): void {
        global $checks;
        if (!$condition) throw new \RuntimeException($message);
        $checks++;
    }
    function funnelRequest(array $query, array $access): \Illuminate\Http\Request {
        $request = \Illuminate\Http\Request::create('/api/attempt-funnel', 'GET', $query);
        $request->attributes->set('client_contour_access', $access);
        return $request;
    }
    function accessFor(array $roles, string $scope, int $employeeId, array $extra = []): array {
        return [
            'employee' => ['id' => $employeeId],
            'roles' => $roles,
            'permissions' => ['attempts.analytics.view' => ['allowed' => true, 'scope' => $scope]],
            'platform_admin' => false,
            'team_employee_ids' => [$employeeId],
            'production_employee_ids' => [],
            ...$extra,
        ];
    }

    $controller = new \App\Http\Controllers\AttemptFunnelController();
    $admin = [
        'employee' => ['id' => 999], 'roles' => ['platform-admin'], 'permissions' => ['attempts.analytics.view' => ['allowed' => true, 'scope' => 'all']],
        'platform_admin' => true,
    ];
    $created = $controller(funnelRequest(['date_from'=>'2026-09-01','date_to'=>'2026-09-30','date_mode'=>'created'], $admin))->getData(true)['data'];
    funnelCheck($created['summary']['total'] === 4, 'Created cohort count is wrong');
    funnelCheck($created['summary']['success'] === 2, 'Success must be based on final success status, not project connection');
    funnelCheck($created['summary']['failed'] === 1 && $created['summary']['in_progress'] === 1, 'Created cohort outcomes are wrong');
    funnelCheck($created['stages'][1]['count'] === 4 && $created['stages'][2]['count'] === 3, 'Stage reach calculation is wrong');
    funnelCheck(($created['failure_reasons_by_stage']['cv_sent'][0]['reason'] ?? null) === 'CV: Не пройдено', 'Failure reason/stage was lost');

    $closed = $controller(funnelRequest(['date_from'=>'2026-09-01','date_to'=>'2026-09-30','date_mode'=>'closed'], $admin))->getData(true)['data'];
    funnelCheck($closed['summary']['total'] === 3, 'Closed cohort must use closed_at');
    funnelCheck($closed['summary']['success'] === 2 && $closed['summary']['failed'] === 1 && $closed['summary']['in_progress'] === 0, 'Closed cohort outcomes are wrong');

    $sales = accessFor(['employee','sales-manager'], 'own', 10);
    $salesData = $controller(funnelRequest(['date_from'=>'2026-09-01','date_to'=>'2026-09-30','date_mode'=>'created'], $sales))->getData(true)['data'];
    funnelCheck($salesData['summary']['total'] === 3, 'Sales must only see own client/request attempts');
    funnelCheck($salesData['filters']['locks']['direction'] === true && $salesData['filters']['locks']['responsible'] === true, 'Sales scoped filters must be locked');

    $production = accessFor(['employee','department-manager'], 'team', 1000, ['production_employee_ids' => [1001]]);
    $productionData = $controller(funnelRequest(['date_from'=>'2026-09-01','date_to'=>'2026-09-30','date_mode'=>'created'], $production))->getData(true)['data'];
    funnelCheck($productionData['summary']['total'] === 3, 'Production manager must only see attempts of own employees');
    funnelCheck($productionData['filters']['locks']['direction'] === true && $productionData['filters']['locks']['responsible'] === true, 'Production manager scoped filters must be locked');

    $head = accessFor(['employee','sales-manager','sales-head'], 'team', 10, ['team_employee_ids' => [10,11]]);
    $headData = $controller(funnelRequest(['date_from'=>'2026-09-01','date_to'=>'2026-09-30','date_mode'=>'created'], $head))->getData(true)['data'];
    funnelCheck($headData['summary']['total'] === 4, 'Sales head team scope should include team clients');
    funnelCheck($headData['filters']['locks']['direction'] === false && $headData['filters']['locks']['responsible'] === false, 'Head filters should remain interactive');

    echo "attempt_funnel_smoke: {$checks} checks passed\n";
}
