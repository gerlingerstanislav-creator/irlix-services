<?php

require getenv('TIMESHEETS_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $path = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($path)) require $path;
    }
});

use App\Support\AssignmentReconciler;
use App\Support\AssignmentsDirectory;
use App\Support\CurrentEmployee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

$app = new Illuminate\Foundation\Application(sys_get_temp_dir().'/timesheets-smoke');
$app->instance('request', Request::create('http://localhost/'));
$app->instance('config', new Illuminate\Config\Repository([
    'app' => ['key' => 'test-key', 'locale' => 'en', 'fallback_locale' => 'en'],
    'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]],
    'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
]));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
foreach ([Illuminate\Events\EventServiceProvider::class, Illuminate\Routing\RoutingServiceProvider::class,
    Illuminate\Filesystem\FilesystemServiceProvider::class, Illuminate\Database\DatabaseServiceProvider::class,
    Illuminate\View\ViewServiceProvider::class, Illuminate\Translation\TranslationServiceProvider::class,
    Illuminate\Validation\ValidationServiceProvider::class] as $provider) $app->register($provider);
Request::macro('validate', function (array $rules): array { return app('validator')->make($this->all(), $rules)->validate(); });
$migration = require dirname(__DIR__).'/database/migrations/2026_09_27_000001_create_timesheets.php';
$migration->up();
putenv('IRLIX_TIMESHEETS_INTEGRATION_TOKEN=synthetic-integration-secret');

$employees = [
    ['id' => 1, 'department_id' => 10, 'full_name' => 'Synthetic Specialist A'],
    ['id' => 2, 'department_id' => 11, 'full_name' => 'Synthetic Specialist B'],
    ['id' => 100, 'department_id' => 20, 'position' => 'Account Manager'],
    ['id' => 200, 'department_id' => 20, 'position' => 'Account Manager'],
    ['id' => 300, 'department_id' => 10, 'position' => 'Direction Manager'],
    ['id' => 400, 'department_id' => 20, 'position' => 'Administrator'],
];
$makeAssignment = fn ($employee, $client, $project, $account) => [
    'employee_id' => $employee, 'client_id' => $client, 'project_id' => $project, 'account_employee_id' => $account,
    'employee_name' => 'Synthetic Specialist', 'client_name' => 'Synthetic Client', 'project_name' => 'Synthetic Project',
    'valid_from' => '2026-01-01', 'valid_to' => null,
];
$assignmentRows = [$makeAssignment(1, 101, 201, 100), $makeAssignment(2, 102, 202, 200)];
$directoryMode = 'complete';
$reportLocked = false;
Http::fake(function ($request) use (&$assignmentRows, &$directoryMode, &$reportLocked, $employees) {
    if (str_ends_with($request->url(), '/timesheet-assignments')) {
        if (($request->header('X-Irlix-Timesheets-Token')[0] ?? '') !== 'synthetic-integration-secret') throw new RuntimeException('Missing internal credential');
        if ($directoryMode === 'failure') return Http::response([], 503);
        if ($directoryMode === 'partial') return Http::response(['data' => [$assignmentRows[0]], 'complete' => false, 'count' => 1]);
        if ($directoryMode === 'malformed') return Http::response(['data' => [['employee_id' => 1]], 'complete' => true, 'count' => 1]);
        return Http::response(['data' => $assignmentRows, 'complete' => true, 'count' => count($assignmentRows),
            'locked_reporting_periods' => [], 'locked_reporting_periods_complete' => true,
            'locked_reporting_periods_count' => 0]);
    }
    $actor = (int) str_replace('Bearer actor-', '', $request->header('Authorization')[0] ?? '');
    if (str_ends_with($request->url(), '/self')) return Http::response(['data' => collect($employees)->firstWhere('id', $actor)]);
    if (str_ends_with($request->url(), '/clients-directory')) return Http::response(['data' => ['employees' => $employees, 'departments' => [['id' => 10, 'manager_id' => 300], ['id' => 20, 'manager_id' => null], ['id' => 11, 'parent_id' => 10, 'manager_id' => null]]]]);
    if (str_ends_with($request->url(), '/access/me')) return Http::response(['data' => ['roles' => $actor === 400 ? ['platform-admin'] : []]]);
    if (str_ends_with($request->url(), '/permissions/me')) return Http::response(['data' => ['permissions' => array_fill_keys([
        'timesheets.management.view', 'timesheets.management.manage', 'timesheets.analytics.view', 'timesheets.audit.view',
    ], ['allowed' => true])]]);
    if (str_contains($request->url(), '/reporting-period-lock')) return Http::response(['data' => ['locked' => $reportLocked]]);
    if (str_contains($request->url(), '/calendar-absences')) return Http::response(['data' => []]);
    throw new RuntimeException('Unexpected dependency: '.$request->url());
});
(new AssignmentsDirectory())->all();
require dirname(__DIR__).'/routes/api.php';

$check = function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$call = function (string $uri, string $method, int $actor, array $data = []) {
    $request = Request::create('http://localhost/'.$uri, $method, $data);
    $request->headers->set('Authorization', 'Bearer actor-'.$actor);
    foreach (Route::getRoutes() as $route) {
        if (trim($route->uri(), '/') === trim($uri, '/') && in_array($method, $route->methods(), true)) {
            return ($route->getAction('uses'))($request, new CurrentEmployee());
        }
    }
    throw new RuntimeException('Route not found: '.$uri);
};
$snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['timesheet_entries', 'employee_confirmations', 'final_approvals', 'timesheet_audit']);

foreach ([[100, 1, 201], [200, 2, 202]] as [$actor, $employee, $project]) {
    $call('management/entries', 'PUT', $actor, ['employee_id' => $employee, 'project_id' => $project, 'work_date' => '2026-10-08', 'hours' => 8]);
    $call('management/final-approval', 'POST', $actor, ['employee_id' => $employee, 'project_id' => $project, 'month' => '2026-10', 'approved' => true]);
}
$saved = $snapshot();
foreach ([100, 200, 300, 400] as $actor) {
    $response = $call('management', 'GET', $actor, ['month' => '2026-10'])->getData(true)['data'];
    $check(count($response['entries']) === ($actor === 100 || $actor === 200 ? 1 : 2), 'AM/RN/admin visibility changed');
    $check($snapshot() === $saved, 'Viewing management deleted or changed data');
    $call('analytics', 'GET', $actor, ['month' => '2026-10']);
    $check($snapshot() === $saved, 'Viewing analytics changed data');
}
$reconciler = new AssignmentReconciler(new AssignmentsDirectory());
$reconciler->run(); $check($snapshot() === $saved, 'Full directory lost another account manager\'s data');
foreach (['failure', 'partial', 'malformed'] as $mode) {
    $directoryMode = $mode;
    try { $reconciler->run(); throw new LogicException('Untrusted directory accepted'); }
    catch (RuntimeException $error) { $check(!$error instanceof LogicException, 'Expected a dependency error'); }
    $check($snapshot() === $saved, 'Dependency failure deleted hours or approvals');
}
$directoryMode = 'complete';
$assignmentRows[0]['valid_to'] = '2026-10-07';
$reconciler->run();
$check(!DB::table('timesheet_entries')->where('employee_id', 1)->exists(), 'Real assignment shortening did not remove invalid hours');
$check(DB::table('timesheet_entries')->where('employee_id', 2)->value('hours') == 8, 'Cleanup removed another project');
$check(DB::table('final_approvals')->where('employee_id', 2)->exists(), 'Cleanup removed another approval');
$check(DB::table('timesheet_audit')->where('action', 'entry_deleted_outside_assignment')->count() === 1, 'Deletion audit missing');
$afterCleanup = $snapshot(); $reconciler->run(); $check($snapshot() === $afterCleanup, 'Reconciliation is not idempotent');

// Batch writes are atomic; invalid/out-of-scope projects must not partly update the first project.
$assignmentRows[0]['valid_to'] = null;
$assignmentRows[] = $makeAssignment(1, 101, 203, 100);
$call('management/entries', 'PUT', 100, ['employee_id' => 1, 'work_date' => '2026-10-08', 'entries' => [
    ['project_id' => 201, 'hours' => 4], ['project_id' => 203, 'hours' => 4],
]]);
$check(DB::table('timesheet_entries')->where('employee_id', 1)->sum('hours') == 8, 'Batch did not save all projects');
$beforeFailure = $snapshot();
try {
    $call('management/entries', 'PUT', 100, ['employee_id' => 1, 'work_date' => '2026-10-08', 'entries' => [
        ['project_id' => 201, 'hours' => 12], ['project_id' => 203, 'hours' => 13],
    ]]);
    throw new LogicException('Excess hours accepted');
} catch (Symfony\Component\HttpKernel\Exception\HttpException $error) { $check($error->getStatusCode() === 422, 'Wrong hours error'); }
$check($snapshot() === $beforeFailure, 'Invalid batch partly saved');
try {
    $call('management/entries', 'PUT', 200, ['employee_id' => 1, 'project_id' => 201, 'work_date' => '2026-10-08', 'hours' => 1]);
    throw new LogicException('Cross-account edit accepted');
} catch (Symfony\Component\HttpKernel\Exception\HttpException $error) { $check($error->getStatusCode() === 403, 'Wrong scope error'); }
$check($snapshot() === $beforeFailure, 'Forbidden edit changed data');

// A range changes only its project hours, keeps descriptions (including with zero), and rolls back all days on failure.
DB::table('timesheet_entries')->where('employee_id', 1)->where('project_id', 201)->update(['description' => 'Synthetic description A']);
$call('management/entries', 'PUT', 100, ['employee_id' => 1, 'project_id' => 201, 'work_date' => '2026-10-09', 'hours' => 6, 'description' => 'Synthetic description B']);
$call('management/final-approval', 'POST', 100, ['employee_id' => 1, 'project_id' => 201, 'month' => '2026-10', 'approved' => true]);
DB::table('employee_confirmations')->updateOrInsert(['employee_id' => 1, 'work_date' => '2026-10-08'], ['confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
$bulk = ['employee_id' => 1, 'project_id' => 201, 'dates' => ['2026-10-09', '2026-10-08', '2026-10-10'], 'hours' => 7.25];
$call('management/bulk-hours', 'PUT', 100, $bulk);
$check(DB::table('timesheet_entries')->where('employee_id', 1)->where('project_id', 201)->sum('hours') == 21.75, 'Range did not save every selected day');
$check(DB::table('timesheet_entries')->where('employee_id', 1)->where('project_id', 201)->where('work_date', '2026-10-08')->value('description') === 'Synthetic description A', 'Range changed first description');
$check(DB::table('timesheet_entries')->where('employee_id', 1)->where('project_id', 201)->where('work_date', '2026-10-09')->value('description') === 'Synthetic description B', 'Range changed second description');
$check(DB::table('timesheet_entries')->where('employee_id', 1)->where('project_id', 203)->value('hours') == 4, 'Range changed another project');
$check(!DB::table('final_approvals')->where('employee_id', 1)->where('project_id', 201)->exists(), 'Range did not reset final confirmation');
$check(!DB::table('employee_confirmations')->where('employee_id', 1)->whereIn('work_date', $bulk['dates'])->exists(), 'Range did not reset preliminary confirmation');
$bulkSnapshot = $snapshot();
$reportLocked = true;
try { $call('management/bulk-hours', 'PUT', 400, $bulk); throw new LogicException('Locked range accepted'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $error) { $check($error->getStatusCode() === 423, 'Wrong report lock error'); }
$check($snapshot() === $bulkSnapshot, 'Locked range changed data');
$reportLocked = false;
foreach ([[$bulk, 200, 403], [[...$bulk, 'hours' => 21, 'dates' => ['2026-10-07', '2026-10-08']], 100, 422], [[...$bulk, 'dates' => ['2026-10-08', '2026-11-01']], 100, 422]] as [$body, $actor, $status]) {
    try { $call('management/bulk-hours', 'PUT', $actor, $body); throw new LogicException('Invalid range accepted'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $error) { $check($error->getStatusCode() === $status, 'Wrong range error'); }
    $check($snapshot() === $bulkSnapshot, 'Range failure partly saved days or confirmations');
}
$assignmentRows[0]['valid_to'] = '2026-10-08';
try { $call('management/bulk-hours', 'PUT', 100, $bulk); throw new LogicException('Inactive range accepted'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $error) { $check($error->getStatusCode() === 422, 'Wrong inactive error'); }
$check($snapshot() === $bulkSnapshot, 'Inactive range partly saved');
$assignmentRows[0]['valid_to'] = null;
$call('management/bulk-hours', 'PUT', 400, [...$bulk, 'hours' => 0]);
$check(DB::table('timesheet_entries')->where('employee_id', 1)->where('project_id', 201)->where('work_date', '2026-10-09')->value('description') === 'Synthetic description B', 'Zero hours removed description');
$check(DB::table('timesheet_entries')->where('employee_id', 1)->where('project_id', 201)->sum('hours') == 0, 'Admin range did not set zero hours');
fwrite(STDOUT, "Timesheets scope, persistence, cleanup and atomic save smoke passed\n");
