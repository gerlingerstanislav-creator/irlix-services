<?php
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../app/Support/SpecialRoles.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, (new ReflectionClass(Illuminate\Routing\ResponseFactory::class))->newInstanceWithoutConstructor());
$capsule = new Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$connection = $capsule->getConnection();
DB::swap($connection);
$connection->statement('PRAGMA foreign_keys = ON');
$connection->statement('CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT)');
$connection->statement('CREATE TABLE staff_positions (id INTEGER PRIMARY KEY, name TEXT, direction_id INTEGER REFERENCES departments(id), base_salary NUMERIC, closed_at TEXT, created_at TEXT, updated_at TEXT)');
$connection->statement('CREATE TABLE employees (id INTEGER PRIMARY KEY, department_id INTEGER, position_id INTEGER REFERENCES staff_positions(id) ON DELETE SET NULL, position TEXT, employment_status TEXT)');
foreach (['employment_periods', 'employment_assignment_history'] as $table) {
    $connection->statement("CREATE TABLE $table (id INTEGER PRIMARY KEY, employee_id INTEGER, position_id INTEGER REFERENCES staff_positions(id) ON DELETE RESTRICT, position TEXT)");
}
$connection->table('departments')->insert(['id' => 1, 'name' => 'Synthetic department']);
foreach ([1, 2, 3] as $id) $connection->table('staff_positions')->insert(['id' => $id, 'name' => "Synthetic position $id", 'direction_id' => 1, 'closed_at' => null]);
$connection->table('employees')->insert([
    ['id' => 1, 'department_id' => 1, 'position_id' => 1, 'position' => 'Synthetic position 1', 'employment_status' => 'Трудоустроен'],
    ['id' => 2, 'department_id' => 1, 'position_id' => 2, 'position' => 'Synthetic past title', 'employment_status' => 'Уволен'],
    ['id' => 3, 'department_id' => 1, 'position_id' => 3, 'position' => 'Synthetic position 3', 'employment_status' => null],
]);
foreach (['employment_periods', 'employment_assignment_history'] as $table) {
    $connection->table($table)->insert(['id' => 1, 'employee_id' => 2, 'position_id' => 2, 'position' => 'Synthetic historical title']);
}
$router = new Router(new Dispatcher($app), $app);
Route::swap($router);
require __DIR__.'/../routes/organization.php';
$run = function ($method, $id, $action = '', $admin = true) use ($router) {
    $uri = '/staff-positions/'.$id.($action ? '/'.$action : '');
    $request = Request::create($uri, $method);
    $request->attributes->set('employees_access', ['roles' => $admin ? [App\Support\SpecialRoles::PlatformAdmin] : []]);
    $route = $router->getRoutes()->match($request);
    return ($route->getAction('uses'))($request, $id);
};
$check = function ($condition, $message) { if (!$condition) throw new RuntimeException($message); };
$check($run('DELETE', 2, '', false)->getStatusCode() === 403, 'Non-admin deletion accepted');
$check($run('POST', 2, 'reopen', false)->getStatusCode() === 403, 'Non-admin reopening accepted');
$check($run('DELETE', 1)->getStatusCode() === 409, 'Occupied position deleted');
$check($run('DELETE', 3)->getStatusCode() === 409, 'Unknown employee status treated as dismissed');
$check($run('POST', 1, 'close')->getStatusCode() === 200, 'Closing failed');
$check($run('DELETE', 1)->getStatusCode() === 409, 'Closed occupied position deleted');
$check($run('POST', 1, 'reopen')->getData(true)['data']['closed_at'] === null, 'Reopening failed');
$check($run('POST', 1, 'reopen')->getStatusCode() === 200, 'Repeated reopening failed');
$check(DB::table('employees')->where('id', 1)->value('position_id') === 1, 'Reopening changed employee assignment');
$check($run('DELETE', 2)->getStatusCode() === 200, 'Historical references blocked empty position deletion');
$check(!DB::table('staff_positions')->where('id', 2)->exists(), 'Deleted catalog entry remains');
$check(DB::table('employees')->where('id', 2)->value('position') === 'Synthetic past title', 'Dismissed employee title lost');
foreach (['employees', 'employment_periods', 'employment_assignment_history'] as $table) {
    $check(DB::table($table)->where('position_id', 2)->count() === 0, 'Foreign key was not detached');
}
foreach (['employment_periods', 'employment_assignment_history'] as $table) {
    $record = DB::table($table)->where('id', 1)->first();
    $check($record && $record->position === 'Synthetic historical title', 'Historical record or title lost');
}
$check($run('DELETE', 2)->getStatusCode() === 404, 'Missing delete did not return 404');
$check($run('POST', 2, 'reopen')->getStatusCode() === 404, 'Missing reopen did not return 404');
echo "Position lifecycle: admin-only empty deletion, preserved history, occupied guard, reopening PASS\n";
