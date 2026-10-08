<?php

require getenv('CLIENTS_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $path = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($path)) require $path;
    }
});
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

$app = new Illuminate\Foundation\Application(sys_get_temp_dir().'/clients-assignments-smoke');
$app->instance('config', new Illuminate\Config\Repository([
    'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
    'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]],
]));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$app->instance('request', Request::create('http://localhost/'));
$app->register(Illuminate\Filesystem\FilesystemServiceProvider::class);
$app->register(Illuminate\View\ViewServiceProvider::class);
$app->register(Illuminate\Events\EventServiceProvider::class);
$app->register(Illuminate\Routing\RoutingServiceProvider::class);
$app->register(Illuminate\Database\DatabaseServiceProvider::class);
$schema = DB::connection()->getSchemaBuilder();
$schema->create('clients', function ($t) { $t->id(); $t->string('name'); $t->integer('account_employee_id'); });
$schema->create('projects', function ($t) { $t->id(); $t->integer('client_id'); $t->string('name')->nullable(); });
$schema->create('project_members', function ($t) { $t->id(); $t->integer('project_id'); $t->integer('specialist_id')->nullable(); $t->string('specialist_name'); });
$schema->create('member_terms', function ($t) { $t->id(); $t->integer('project_member_id'); $t->date('valid_from'); $t->date('valid_to')->nullable(); $t->decimal('hourly_rate'); });
$schema->create('reporting_periods', function ($t) { $t->id(); $t->integer('client_id'); $t->date('period_start'); $t->date('period_end'); $t->string('status'); $t->date('timesheets_sent_at')->nullable(); });
DB::table('reporting_periods')->insert([
    ['client_id' => 1, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'ТШ на согласовании', 'timesheets_sent_at' => '2026-10-08'],
    ['client_id' => 2, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'Новый', 'timesheets_sent_at' => null],
]);
foreach ([1, 2] as $id) {
    DB::table('clients')->insert(['id' => $id, 'name' => 'Synthetic Client '.$id, 'account_employee_id' => 100 * $id]);
    DB::table('projects')->insert(['id' => $id, 'client_id' => $id, 'name' => null]);
    DB::table('project_members')->insert(['id' => $id, 'project_id' => $id, 'specialist_id' => $id, 'specialist_name' => 'Synthetic Specialist '.$id]);
    DB::table('member_terms')->insert(['project_member_id' => $id, 'valid_from' => '2026-01-01', 'valid_to' => null, 'hourly_rate' => 100]);
}
DB::table('project_members')->insert(['id'=>3,'project_id'=>1,'specialist_id'=>null,'specialist_name'=>'Synthetic Partner']);
DB::table('member_terms')->insert(['project_member_id'=>3,'valid_from'=>'2026-01-01','valid_to'=>null,'hourly_rate'=>100]);
putenv('IRLIX_TIMESHEETS_INTEGRATION_TOKEN=synthetic-secret');
$controller = new App\Http\Controllers\TimesheetAssignmentsController();
foreach (['', 'wrong-secret'] as $token) {
    $request = Request::create('/internal/timesheet-assignments');
    $request->headers->set('X-Irlix-Timesheets-Token', $token);
    try { $controller($request); throw new LogicException('Unauthorized internal directory accepted'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $error) { if ($error->getStatusCode() !== 403) throw $error; }
}
$request = Request::create('/internal/timesheet-assignments');
$request->headers->set('X-Irlix-Timesheets-Token', 'synthetic-secret');
$request->headers->set('Authorization', 'Bearer synthetic-user-with-limited-scope');
$payload = $controller($request)->getData(true);
if ($payload['complete'] !== true || $payload['count'] !== 2 || count($payload['data']) !== 2) throw new RuntimeException('Internal directory was scoped or incomplete');
if ($payload['locked_reporting_periods_complete'] !== true || $payload['locked_reporting_periods_count'] !== 1
    || $payload['locked_reporting_periods'][0]['client_id'] !== 1) throw new RuntimeException('Locked period snapshot is incomplete or includes New');
foreach ($payload['data'] as $row) if (array_key_exists('hourly_rate', $row)) throw new RuntimeException('Commercial rates exposed in assignment contract');
putenv('IRLIX_TIMESHEETS_INTEGRATION_TOKEN=');
try { $controller($request); throw new LogicException('Missing configured credential accepted'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $error) { if ($error->getStatusCode() !== 403) throw $error; }
fwrite(STDOUT, "Clients internal assignment directory smoke passed\n");
