<?php
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../app/Support/SpecialRoles.php';
require_once __DIR__.'/../app/Support/EmployeesAccess.php';
require_once __DIR__.'/../app/Http/Middleware/EmployeesAuthorization.php';

use App\Http\Middleware\EmployeesAuthorization;
use App\Support\EmployeesAccess;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, (new ReflectionClass(Illuminate\Routing\ResponseFactory::class))->newInstanceWithoutConstructor());
$router = new Router(new Dispatcher($app), $app);
$app->instance('router', $router);
$capsule = new Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
DB::swap($db);
$db->statement('CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT, parent_id INTEGER, manager_id INTEGER)');
$db->statement('CREATE TABLE employees (id INTEGER PRIMARY KEY, keycloak_user_id TEXT, login TEXT, full_name TEXT, position TEXT, department_id INTEGER, employment_status TEXT, salary INTEGER, passport TEXT)');
$db->statement('CREATE TABLE employee_access_roles (employee_id INTEGER, role TEXT)');
$db->table('departments')->insert(['id' => 1, 'name' => 'Synthetic engineering', 'parent_id' => null, 'manager_id' => null]);
$db->table('employees')->insert([
    ['id'=>1,'keycloak_user_id'=>'synthetic-admin','login'=>'synthetic.admin','full_name'=>'Synthetic Administrator','position'=>'Engineer','department_id'=>1,'employment_status'=>'Трудоустроен','salary'=>123,'passport'=>'private'],
    ['id'=>2,'keycloak_user_id'=>'synthetic-user','login'=>'synthetic.user','full_name'=>'Synthetic Employee','position'=>'Developer','department_id'=>1,'employment_status'=>'Трудоустроен','salary'=>456,'passport'=>'private'],
    ['id'=>3,'keycloak_user_id'=>'synthetic-former','login'=>'synthetic.former','full_name'=>'Synthetic Former Employee','position'=>'Developer','department_id'=>1,'employment_status'=>'Уволен','salary'=>789,'passport'=>'private'],
]);
$db->table('employee_access_roles')->insert(['employee_id'=>1,'role'=>'system-admin']);
$router->prefix('api')->group(__DIR__.'/../routes/self.php');
$middleware = new EmployeesAuthorization(new EmployeesAccess());
$run = function ($path, $subject, $roles = []) use ($middleware, $router) {
    $request = Request::create($path, 'GET');
    $request->attributes->set('identity', ['sub'=>$subject,'realm_roles'=>$roles]);
    return $middleware->handle($request, function ($request) use ($router) {
        $route = $router->getRoutes()->match($request);
        return ($route->getAction('uses'))($request, isset($route->parameters()['employee']) ? (int)$route->parameters()['employee'] : null);
    });
};
$check = function ($condition, $message) { if (!$condition) throw new RuntimeException($message); };
$response = $run('/api/equipment-directory', 'synthetic-admin');
$check($response->getStatusCode() === 200, 'System admin without HR access cannot load directory');
$rows = $response->getData(true)['data'];
$check(array_column($rows, 'id') === [1, 2], 'Directory must contain employed employees only');
$check(array_keys($rows[0]) === ['id','full_name','position','employment_status','department_name'], 'Directory exposed private fields');
$check($run('/api/employees', 'synthetic-admin')->getStatusCode() === 403, 'Directory granted HR registry access');
$check($run('/api/employees/2', 'synthetic-admin')->getStatusCode() === 403, 'Directory granted HR card access');
$check($run('/api/equipment-directory', 'synthetic-user')->getStatusCode() === 403, 'Ordinary employee can load directory');
$check($run('/api/equipment-directory/2', 'synthetic-user')->getStatusCode() === 403, 'Ordinary employee can look up colleagues');
$check($run('/api/equipment-directory/2', 'synthetic-admin')->getData(true)['data']['employee']['employment_status'] === 'Трудоустроен', 'Assignment validation failed');
$check($run('/api/equipment-directory/3', 'synthetic-admin')->getData(true)['data']['employee']['employment_status'] === 'Уволен', 'History cannot validate former employee');
$check($run('/api/equipment-directory/999', 'synthetic-admin')->getStatusCode() === 404, 'Missing employee accepted');
foreach (['platform-admin','sysadmin','accounting','accountant','system_admin'] as $role) {
    $check($run('/api/equipment-directory', 'synthetic-user', [$role])->getStatusCode() === 200, 'Verified equipment role rejected: '.$role);
}
foreach (['Системный администратор','System Administrator','Бухгалтер'] as $position) {
    $db->table('employees')->where('id', 2)->update(['position'=>$position]);
    $check($run('/api/equipment-directory', 'synthetic-user')->getStatusCode() === 200, 'Equipment position rejected: '.$position);
}
$db->table('employees')->where('id', 2)->update(['position'=>'Developer']);
$db->table('departments')->where('id', 1)->update(['name'=>'Бухгалтерия']);
$check($run('/api/equipment-directory', 'synthetic-user')->getStatusCode() === 200, 'Accounting department rejected');
echo "Equipment directory authorization, privacy and employment lookup: PASS\n";
