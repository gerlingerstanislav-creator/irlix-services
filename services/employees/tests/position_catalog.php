<?php
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../app/Http/Middleware/EmployeePositionCatalog.php';

use App\Http\Middleware\EmployeePositionCatalog;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, (new ReflectionClass(Illuminate\Routing\ResponseFactory::class))->newInstanceWithoutConstructor());
$capsule = new Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$connection = $capsule->getConnection();
DB::swap($connection);
$connection->statement('CREATE TABLE staff_positions (id INTEGER PRIMARY KEY, name TEXT, direction_id INTEGER, closed_at TEXT)');
$connection->statement('CREATE TABLE employees (id INTEGER PRIMARY KEY, department_id INTEGER, position_id INTEGER, position TEXT)');
$connection->table('staff_positions')->insert([
    ['id' => 11, 'name' => 'Synthetic position A', 'direction_id' => 2, 'closed_at' => null],
    ['id' => 12, 'name' => 'Synthetic position B', 'direction_id' => 3, 'closed_at' => null],
    ['id' => 13, 'name' => 'Synthetic closed position', 'direction_id' => 2, 'closed_at' => '2026-10-03'],
]);
$connection->table('employees')->insert(['id' => 1, 'department_id' => 2, 'position_id' => 11, 'position' => 'Synthetic position A']);
$run = function ($path, $method, $data) {
    return (new EmployeePositionCatalog())->handle(Request::create($path, $method, $data), fn ($r) => new JsonResponse($r->all()));
};
$check = function ($condition, $message) { if (!$condition) throw new RuntimeException($message); };
foreach ([
    ['position_id' => 11],
    ['department_id' => 3, 'position_id' => 11],
    ['department_id' => 2, 'position_id' => 13],
    ['department_id' => 2, 'position_id' => '11.5'],
] as $payload) $check($run('/api/employees', 'POST', $payload)->getStatusCode() === 422, 'Invalid assignment accepted');
$valid = $run('/api/employees', 'POST', ['department_id' => 2, 'position_id' => 11]);
$check($valid->getStatusCode() === 200 && $valid->getData(true)['position'] === 'Synthetic position A', 'Valid assignment rejected');
$patch = $run('/api/employees/1', 'PATCH', ['position_id' => 11]);
$check($patch->getStatusCode() === 200, 'Current department not used for inline edit');
$salaryReview = $run('/api/employees/1/salary-history', 'POST', ['position_id' => 11]);
$check($salaryReview->getStatusCode() === 200, 'Current department not used for salary review position');
$check($run('/api/employees/1/salary-history', 'POST', ['position_id' => 12])->getStatusCode() === 422, 'Other department accepted in salary review');
$check($run('/api/employees/1', 'PATCH', ['position_id' => 12])->getStatusCode() === 422, 'Other department accepted on PATCH');
$reset = $run('/api/employees/1', 'PATCH', ['department_id' => 3])->getData(true);
$check(array_key_exists('position_id', $reset) && $reset['position_id'] === null && $reset['position'] === null, 'Department change retained previous position');
$combined = $run('/api/employees/1', 'PATCH', ['department_id' => 3, 'position_id' => 12]);
$check($combined->getStatusCode() === 200, 'Combined department and position update rejected');
$check($run('/api/employees/1/rehire', 'POST', ['department_id' => 3, 'position_id' => 11])->getStatusCode() === 422, 'Rehire mismatch accepted');
echo "Department-scoped position assignments and reset: PASS\n";
