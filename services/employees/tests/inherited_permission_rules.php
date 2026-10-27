<?php

/** Synthetic in-memory contract for Employees department-ancestor base rights. */
require __DIR__.'/../vendor/autoload.php';

use App\Support\EmployeesAccess;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('date', new DateFactory());
$capsule = new Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$app->instance('db', $capsule->getDatabaseManager());
$db = $capsule->getConnection();
DB::swap($db);
Schema::swap($db->getSchemaBuilder());
Model::setConnectionResolver($capsule->getDatabaseManager());

$db->statement('CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT, alias TEXT, parent_id INTEGER, manager_id INTEGER)');
$db->statement('CREATE TABLE employees (id INTEGER PRIMARY KEY, full_name TEXT, login TEXT, department_id INTEGER, position TEXT, employment_status TEXT, keycloak_user_id TEXT)');
$db->statement('CREATE TABLE employee_access_roles (id INTEGER PRIMARY KEY, employee_id INTEGER, role TEXT)');
$db->statement('CREATE TABLE employee_permission_rules (id INTEGER PRIMARY KEY, role TEXT, permission TEXT, enabled INTEGER)');
$db->table('departments')->insert([
    ['id'=>1,'name'=>'Irlix','alias'=>'irlix','parent_id'=>null,'manager_id'=>null],
    ['id'=>2,'name'=>'Development','alias'=>'development','parent_id'=>1,'manager_id'=>null],
    ['id'=>3,'name'=>'Backend','alias'=>'backend','parent_id'=>2,'manager_id'=>null],
    ['id'=>4,'name'=>'Client Service','alias'=>'client-service','parent_id'=>1,'manager_id'=>null],
    ['id'=>5,'name'=>'Account Management','alias'=>'account-management','parent_id'=>4,'manager_id'=>null],
    ['id'=>6,'name'=>'Sales','alias'=>'sales','parent_id'=>4,'manager_id'=>null],
    ['id'=>7,'name'=>'Client Operations','alias'=>'client-operations','parent_id'=>4,'manager_id'=>null],
    ['id'=>8,'name'=>'Finance','alias'=>'finance','parent_id'=>1,'manager_id'=>null],
    ['id'=>9,'name'=>'Accounting','alias'=>'accounting','parent_id'=>8,'manager_id'=>null],
]);
foreach ([
    ['id'=>31,'login'=>'backend.example','department_id'=>3],
    ['id'=>32,'login'=>'account.example','department_id'=>5],
    ['id'=>33,'login'=>'sales.example','department_id'=>6],
    ['id'=>34,'login'=>'operations.example','department_id'=>7],
    ['id'=>35,'login'=>'finance.example','department_id'=>9],
    ['id'=>36,'login'=>'account-root.example','department_id'=>4, 'position'=>'Account Manager'],
    ['id'=>37,'login'=>'finance-title.example','department_id'=>9, 'position'=>'Account Manager'],
] as $row) {
    $db->table('employees')->insert([
        ...$row,
        'full_name'=>'Synthetic User '.(int)$row['id'],
        'position'=>$row['position'] ?? 'Specialist',
        'employment_status'=>'Трудоустроен',
        'keycloak_user_id'=>'synthetic-id-'.(int)$row['id'],
    ]);
}
$addRule = static fn (string $role, string $permission, bool $enabled) =>
    $db->table('employee_permission_rules')->insert(compact('role','permission') + ['enabled'=>$enabled]);
$addRule('development', 'services.specialists', true);
$addRule('development', 'organization.read', true);
$addRule('development', 'employees.salary.read', true); // must NOT inherit
$addRule('development', 'services.finance', true); // must NOT inherit
$addRule('irlix', 'services.equipment', true);
$addRule('backend', 'organization.read', false);
$resolve = static function (int $id): array {
    $request = \Illuminate\Http\Request::create('/api/access/me', 'GET');
    $request->attributes->set('identity', ['sub'=>'synthetic-id-'.$id]);
    return (new EmployeesAccess())->resolve($request);
};
$check = static function (bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
};
$backend = $resolve(31);
$check($backend['roles'] === ['backend'], 'Backend JWT roles unexpectedly contain ancestors');
$check($backend['services']['specialists'] === true, 'Backend missing Development service rule');
$check($backend['services']['equipment'] === true, 'Backend missing grandparent Irlix service rule');
$check($backend['permissions']['organization.read'] === false, 'Child explicit denial did not override parent grant');
$check($backend['permissions']['employees.salary.read'] === false, 'Backend inherited sensitive salary read');
$check($backend['services']['finance'] === false, 'Backend inherited Finance entry');
$check($backend['scope'] === 'none', 'Backend gained parent manager scope');

$account = $resolve(32);
$check(in_array('account-manager', $account['roles'], true), 'Account functional role missing from Employees');
$check(!in_array('client-service', $account['roles'], true), 'Account inherited organizational parent JWT role');
$check($account['services']['clients'] === true && $account['services']['timesheets'] === true,
    'Account cannot enter Clients or Timesheets');
$check($account['services']['finance'] === false, 'Account gained Finance access');
$sales = $resolve(33);
$check(in_array('sales-manager', $sales['roles'], true) && $sales['services']['clients'] === true,
    'Sales functional role or Clients admission missing');
$operations = $resolve(34);
$check($operations['roles'] === ['client-operations'] && $operations['services']['clients'] === true,
    'Generic Client Service descendant cannot enter Clients');
$check($operations['services']['timesheets'] === true, 'Generic Client Service descendant cannot enter Timesheets');
$finance = $resolve(35);
$check($finance['services']['clients'] === false && $finance['services']['finance'] === true,
    'Finance Accounting confused with client Accounting');

$rootAccount = $resolve(36);
$check(in_array('account-manager', $rootAccount['roles'], true),
    'An Account Manager position at the Client Service root lost functional role');
$check($rootAccount['services']['clients'] === true && $rootAccount['services']['timesheets'] === true,
    'Client Service Account Manager cannot enter Clients and Timesheets');
$jwtGrants = \App\Support\KeycloakAuthorizationSync::grants($rootAccount);
$check(in_array('account-manager', $jwtGrants, true)
    && in_array('service:clients', $jwtGrants, true)
    && in_array('service:timesheets', $jwtGrants, true),
    'JWT authorization misses Account Manager or service entitlement');
$financeTitle = $resolve(37);
$check(!in_array('account-manager', $financeTitle['roles'], true)
    && $financeTitle['services']['clients'] === false,
    'Finance position was incorrectly promoted to client Account Manager');

// Explicit local service denial must override inherited client-team entry.
$addRule('client-operations', 'services.clients', false);
$check($resolve(34)['services']['clients'] === false, 'Explicit local service denial was bypassed');

echo "Employees inherited baseline permissions and service gates: PASS\n";
