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
$app->instance('db.schema', $db->getSchemaBuilder());
DB::swap($db);
$db->statement('CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT, alias TEXT, parent_id INTEGER, manager_id INTEGER, is_production INTEGER DEFAULT 0)');
$db->statement('CREATE TABLE employees (id INTEGER PRIMARY KEY, keycloak_user_id TEXT, login TEXT, full_name TEXT, position TEXT, department_id INTEGER, employment_status TEXT, salary INTEGER, passport TEXT)');
$db->statement('CREATE TABLE employee_access_roles (employee_id INTEGER, role TEXT)');
$db->table('departments')->insert([['id'=>1,'name'=>'Synthetic engineering','alias'=>'development','parent_id'=>null,'manager_id'=>null],['id'=>2,'name'=>'Бухгалтерия','alias'=>'accounting','parent_id'=>null,'manager_id'=>null],['id'=>3,'name'=>'Клиентский аккаунтинг','alias'=>'account-management','parent_id'=>null,'manager_id'=>null]]);
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
$check(($run('/api/equipment-directory/3', 'synthetic-admin')->getData(true)['data']['employee']['employment_status'] ?? null) === 'Уволен', 'Historical equipment lookup must find former employees');
$check($run('/api/equipment-directory/999', 'synthetic-admin')->getStatusCode() === 404, 'Missing employee accepted');
// The directory trusts Employees' effective roles, never a claimed realm role.
foreach (['platform-admin','sysadmin','accounting','accountant','system_admin'] as $role) {
    $check($run('/api/equipment-directory', 'synthetic-user', [$role])->getStatusCode() === 403, 'Unassigned realm role grants directory access: '.$role);
}
foreach (['Системный администратор','System Administrator','Бухгалтер','Accountant'] as $position) {
    $db->table('employees')->where('id', 2)->update(['position'=>$position]);
    $check($run('/api/equipment-directory', 'synthetic-user')->getStatusCode() === 403, 'Text job title grants directory access: '.$position);
}
$db->table('employees')->where('id', 2)->update(['position'=>'Developer']);
foreach (['system-admin','platform-admin','platform-tester'] as $role) {
    $db->table('employee_access_roles')->insert(['employee_id'=>2,'role'=>$role]);
    $expected = $role === 'platform-tester' ? 403 : 200;
    $check($run('/api/equipment-directory', 'synthetic-user')->getStatusCode() === $expected, 'Explicit Employees role has incorrect privileges: '.$role);
    $db->table('employee_access_roles')->where('employee_id',2)->where('role',$role)->delete();
}
$db->table('employees')->where('id',2)->update(['department_id'=>2]);
$check($run('/api/equipment-directory', 'synthetic-user')->getStatusCode() === 200, 'Accounting alias denied');
$db->table('employees')->where('id',2)->update(['department_id'=>3]);
$check($run('/api/equipment-directory', 'synthetic-user')->getStatusCode() === 403, 'Client account management incorrectly gains accounting rights');
$db->table('departments')->where('id',2)->update(['manager_id'=>2]);
$check($run('/api/equipment-directory', 'synthetic-user')->getStatusCode() === 200, 'Accounting head outside accounting not recognized');
$db->table('departments')->where('id',2)->update(['manager_id'=>null]);
$check($run('/api/equipment-directory', 'synthetic-user')->getStatusCode() === 403, 'Removed accounting head still has access');

// The public catalog is visible to every authenticated identity, including users
// without a mapped employee. This grants no extra access to protected services.
foreach (['synthetic-admin', 'synthetic-user', 'synthetic-unmapped'] as $subject) {
    $request = Request::create('/api/access/me', 'GET');
    $request->attributes->set('identity', ['sub' => $subject]);
    $access = (new EmployeesAccess())->resolve($request);
    $check(($access['services']['design-system'] ?? false) === true, 'Public catalog missing from authenticated service access');
    if ($subject === 'synthetic-unmapped') {
        $check(($access['services']['employees'] ?? true) === false, 'Public catalog opened private Employees service');
        $check(($access['services']['system'] ?? true) === false, 'Public catalog opened private System service');
    }
}

// A platform tester is not an administrator and can only simulate a role.
// The identity remains the real tester employee: no account impersonation.
$db->table('departments')->insert(['id'=>4,'name'=>'Synthetic production direction','alias'=>'backend','parent_id'=>1,'manager_id'=>null,'is_production'=>1]);
$db->table('employee_access_roles')->insert(['employee_id'=>2,'role'=>'platform-tester']);
$request = Request::create('/api/access/me', 'GET');
$request->attributes->set('identity', ['sub'=>'synthetic-user']);
$resolver = new EmployeesAccess();
$tester = $resolver->resolve($request);
$check(!$tester['allowed'], 'Tester unexpectedly gained Employees UI access');
$check(!$tester['permissions']['employees.manage'], 'Tester can mutate Employees');
$check(!$tester['permissions']['access.manage'], 'Tester can manage roles');
$check(!$tester['services']['system'], 'Tester gained System access');
$check(!$tester['services']['finance'], 'Tester gained Finance access');
$check($resolver->canPreview($tester), 'Tester cannot enter role-preview mode');
$options = $resolver->previewCatalog($tester);
$check(in_array('commercial-director', array_column($options, 'value'), true), 'Composite director role missing');
$check(in_array('backend-head', array_column($options, 'value'), true), 'Production direction head missing');
$check(!in_array('platform-admin', array_column($options, 'value'), true), 'Platform admin impersonation offered');
$preview = $resolver->preview($tester, 'backend-head');
$check($preview['employee_id'] === 2, 'Preview changed the true employee identity');
$check($preview['scope'] === 'subtree' && in_array(4, $preview['department_ids'], true), 'Head direction scope missing');
$check($preview['services']['specialists'], 'Production director preview missing Specialists');
$check(!$preview['permissions']['employees.manage'], 'Head preview acquired administrator rights');
$commercial = $resolver->preview($tester, 'commercial-director');
$check($commercial['services']['clients'], 'Composite commercial role missing Clients');
$check(!$commercial['services']['specialists'], 'Commercial role incorrectly acquired production rights');
$check($resolver->preview($tester, 'platform-admin') === null, 'Privilege escalation through preview');
$check($resolver->preview($tester, 'employee-id:1') === null, 'Account impersonation through preview');
$db->table('employee_access_roles')->where('employee_id',2)->where('role','platform-tester')->delete();
$check($resolver->preview($resolver->resolve($request), 'personnel-officer') === null,
    'Revoked tester can still preview roles');

echo "Equipment directory authorization, privacy, employment lookup and role preview: PASS\n";
