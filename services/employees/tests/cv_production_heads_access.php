<?php

/** Synthetic Employees service admission and signed JWT grant contract for CV. */
require __DIR__.'/../vendor/autoload.php';

use App\Support\EmployeesAccess;
use App\Support\KeycloakAuthorizationSync;
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

$db->statement('CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT, alias TEXT, parent_id INTEGER, manager_id INTEGER, is_production INTEGER)');
$db->statement('CREATE TABLE employees (id INTEGER PRIMARY KEY, full_name TEXT, login TEXT, department_id INTEGER, position TEXT, employment_status TEXT, keycloak_user_id TEXT)');
$db->statement('CREATE TABLE employee_access_roles (id INTEGER PRIMARY KEY, employee_id INTEGER, role TEXT)');
$db->statement('CREATE TABLE employee_permission_rules (id INTEGER PRIMARY KEY, role TEXT, permission TEXT, enabled INTEGER)');

$db->table('departments')->insert([
    ['id'=>1, 'name'=>'Irlix', 'alias'=>'irlix', 'parent_id'=>null, 'manager_id'=>null, 'is_production'=>0],
    ['id'=>2, 'name'=>'Development', 'alias'=>'development', 'parent_id'=>1, 'manager_id'=>10, 'is_production'=>1],
    ['id'=>3, 'name'=>'Backend', 'alias'=>'backend', 'parent_id'=>2, 'manager_id'=>null, 'is_production'=>1],
    ['id'=>4, 'name'=>'Legal', 'alias'=>'legal', 'parent_id'=>1, 'manager_id'=>11, 'is_production'=>0],
]);
foreach ([
    ['id'=>10, 'department_id'=>3, 'position'=>'Developer'],
    ['id'=>11, 'department_id'=>4, 'position'=>'Head of Legal'],
    ['id'=>12, 'department_id'=>3, 'position'=>'Руководитель направления'],
    ['id'=>13, 'department_id'=>4, 'position'=>'Administrator'],
    ['id'=>14, 'department_id'=>3, 'position'=>'Tester'],
] as $row) {
    $db->table('employees')->insert([
        ...$row,
        'full_name'=>'Synthetic Employee '.(int) $row['id'],
        'login'=>'synthetic.'.(int) $row['id'],
        'employment_status'=>'Трудоустроен',
        'keycloak_user_id'=>'synthetic-'.$row['id'],
    ]);
}
$db->table('employee_access_roles')->insert([
    ['employee_id'=>13, 'role'=>'platform-admin'],
    ['employee_id'=>14, 'role'=>'platform-tester'],
]);

$resolve = static function (int $id): array {
    $request = \Illuminate\Http\Request::create('/api/access/me', 'GET');
    $request->attributes->set('identity', ['sub'=>'synthetic-'.$id]);
    return (new EmployeesAccess())->resolve($request);
};
$check = static function (bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
};
$grant = static fn (array $access): bool =>
    in_array('service:cv-converter', KeycloakAuthorizationSync::grants($access), true);

$lead = $resolve(10);
$check($lead['services']['cv-converter'] === true, 'Assigned production manager cannot enter CV');
$check($grant($lead), 'Production manager was not published as a signed CV service entitlement');
$check(in_array('development-head', $lead['roles'], true), 'Assigned head label missing');
$check($lead['services']['system'] === false, 'CV admission incorrectly grants System privileges');

foreach ([11, 12, 14] as $id) {
    $access = $resolve($id);
    $check($access['services']['cv-converter'] === false, "Non-production leader, staff or tester $id gained CV");
    $check(!$grant($access), "Non-production staff $id gained a signed service entitlement");
}
$check($resolve(13)['services']['cv-converter'] === true, 'Existing platform admin lost CV');

$db->table('departments')->where('id', 2)->update(['manager_id'=>null]);
$revoked = $resolve(10);
$check($revoked['services']['cv-converter'] === false && !$grant($revoked),
    'Removing department manager did not revoke CV at source and Keycloak grant');

$db->table('departments')->where('id', 2)->update(['manager_id'=>10]);
$catalog = (new \App\Services\PermissionRuleService())->permissionCatalog();
$check(isset($catalog['services.cv-converter']),
    'CV service entry is absent from the configurable Employees role matrix');

$db->table('employee_permission_rules')->insert([
    'role'=>'development-head', 'permission'=>'services.cv-converter', 'enabled'=>0,
]);
$denied = $resolve(10);
$check($denied['services']['cv-converter'] === false && !$grant($denied),
    'Explicit Employees service denial was bypassed');

echo "CV production manager entitlement, JWT publication and revocation: PASS\n";
