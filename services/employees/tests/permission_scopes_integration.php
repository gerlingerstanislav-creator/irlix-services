<?php

/** SQLite end-to-end role composition: no production data or identities. */
require __DIR__.'/../vendor/autoload.php';

use App\Models\Employee;
use App\Services\PermissionRuleService;
use App\Support\EmployeesAccess;
use App\Support\SpecialRoles;
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

$db->statement('CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT, alias TEXT, parent_id INTEGER, manager_id INTEGER, hr_id INTEGER, is_production INTEGER DEFAULT 0)');
$db->statement('CREATE TABLE employees (id INTEGER PRIMARY KEY, full_name TEXT, login TEXT, department_id INTEGER, position TEXT, employment_status TEXT, keycloak_user_id TEXT, account_type TEXT DEFAULT "employee")');
$db->statement('CREATE TABLE employee_access_roles (id INTEGER PRIMARY KEY, employee_id INTEGER, role TEXT)');
$db->statement('CREATE TABLE employee_permission_rules (id INTEGER PRIMARY KEY, role TEXT, permission TEXT, enabled INTEGER, scope TEXT, created_at TEXT, updated_at TEXT, UNIQUE(role, permission))');

$db->table('departments')->insert([
    ['id'=>1,'name'=>'Company','alias'=>'irlix','parent_id'=>null,'manager_id'=>null,'hr_id'=>null],
    ['id'=>2,'name'=>'Engineering','alias'=>'engineering','parent_id'=>1,'manager_id'=>10,'hr_id'=>12],
    ['id'=>3,'name'=>'QA','alias'=>'qa','parent_id'=>2,'manager_id'=>null,'hr_id'=>null],
    ['id'=>4,'name'=>'Sales','alias'=>'sales','parent_id'=>1,'manager_id'=>null,'hr_id'=>null],
    ['id'=>5,'name'=>'Accounting','alias'=>'accounting','parent_id'=>1,'manager_id'=>null,'hr_id'=>null],
]);
foreach ([
    [10,'Manager + personnel',4],
    [11,'Personnel',4],
    [12,'Assigned HR',4],
    [13,'Accountant',5],
    [14,'Platform admin',4],
    [15,'QA employee',3],
] as [$id,$name,$department]) {
    $db->table('employees')->insert([
        'id'=>$id, 'login'=>'synthetic.'.$id, 'full_name'=>$name,
        'department_id'=>$department, 'position'=>'Specialist',
        'employment_status'=>'Трудоустроен',
        'keycloak_user_id'=>'synthetic-id-'.$id, 'account_type'=>'employee',
    ]);
}
$db->table('employee_access_roles')->insert([
    ['employee_id'=>10,'role'=>SpecialRoles::PersonnelOfficer],
    ['employee_id'=>11,'role'=>SpecialRoles::PersonnelOfficer],
    ['employee_id'=>14,'role'=>SpecialRoles::PlatformAdmin],
]);
$access = new EmployeesAccess();
$resolve = static fn (int $id): array => $access->resolveEmployee(Employee::findOrFail($id));
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$manager = $resolve(10);
$check(in_array(SpecialRoles::Managers, $manager['roles'], true), 'Manager aggregate missing');
$check($manager['scope'] === 'all', 'Personnel role should grant company-wide employee visibility');
$check(($manager['permission_scopes']['employees.salary.read']['scope'] ?? '') === 'subtree',
    'Personnel role must not expand management salary scope to company');
$check($manager['permission_scopes']['employees.salary.read']['department_ids'] === [2,3],
    'Manager salary subtree should contain directly managed department and its child');
$check($access->canSeeEmployee($manager, 11), 'Personnel employee read should include Sales');
$check(!$access->canSeePermissionEmployee($manager, 'employees.salary.read', 11),
    'A manager+personnel employee must not read Sales salaries');
$check($access->canSeePermissionEmployee($manager, 'employees.salary.read', 15),
    'Manager must see managed QA salaries');
$check($manager['permission_scopes']['employees.fot.view']['scope'] === 'subtree',
    'Company-wide personnel visibility cannot expand FOT');

$personnel = $resolve(11);
$check($personnel['scope'] === 'all', 'Personnel employee read is company-wide');
$check(!$personnel['permissions']['employees.salary.read'], 'Personnel alone never grants salary access');
$check($personnel['permission_scopes']['employees.salary.read']['scope'] === 'none',
    'Personnel salary scope must be none');

$directionHr = $resolve(12);
$check(in_array(SpecialRoles::DirectionHr, $directionHr['roles'], true), 'Assigned HR role missing');
$check($directionHr['scope'] === 'subtree' && $directionHr['department_ids'] === [2, 3],
    'Assigned HR must only see their assigned tree');
$check(!$access->canSeeEmployee($directionHr, 11), 'Assigned HR must not see unrelated Sales staff');
$check(!$directionHr['permissions']['employees.salary.read'],
    'Direction HR is not granted salaries');
$check(isset((new PermissionRuleService())->roleCatalog()[SpecialRoles::DirectionHr]),
    'Direction HR must appear in permission catalog');

$accountant = $resolve(13);
$check($accountant['scope'] === 'all'
    && $accountant['permission_scopes']['employees.salary.read']['scope'] === 'all',
    'Accounting must keep existing company-wide salary rights');

$admin = $resolve(14);
$check($admin['scope'] === 'all'
    && $admin['permission_scopes']['employees.salary.read']['scope'] === 'all',
    'Platform admin must keep full scoped rights');

$rules = new PermissionRuleService();
// The selectable catalog is intentionally wider than default visible roles.
// Selecting a column never grants Employees access without explicit rules.
$availableRoles = $rules->availableRoleCatalog();
$defaultRoles = $rules->roleCatalog();
$check(isset($availableRoles['sales']) && !isset($defaultRoles['sales']),
    'Sales must be selectable, but not automatically visible before Employees admission');
$check(isset($availableRoles[SpecialRoles::TopManagement])
    && isset($availableRoles[SpecialRoles::SystemAdministrator]),
    'Known special roles must be selectable even without Employees grants');
$check(!isset($availableRoles[SpecialRoles::PlatformTester]),
    'Platform tester is a preview marker and cannot receive business grants');
$check(($rules->scopeCatalog()['sales']['employees.read']['default'] ?? '') === 'deny',
    'Unrelated roles must not obtain Employees read by displaying a column');
$db->table('employees')->insert([
    'id'=>16, 'login'=>'synthetic.16', 'full_name'=>'Sales staff',
    'department_id'=>4, 'position'=>'Sales', 'employment_status'=>'Трудоустроен',
    'keycloak_user_id'=>'synthetic-id-16', 'account_type'=>'employee',
]);
$check(!$resolve(16)['services']['employees'],
    'A selectable role must not grant Employees entry automatically');
$rules->setRule('sales', 'employees.read', true, 'all');
$check(!$resolve(16)['services']['employees'],
    'Reading Employees alone must not bypass service entry');
$rules->setRule('sales', 'services.employees', true);
$check($resolve(16)['services']['employees'] && $resolve(16)['permissions']['employees.read'],
    'Administrator must be able to grant Employees access through a selectable role');
$rules->setRule(SpecialRoles::Managers, 'employees.salary.read', true, 'all');
$managerAllSalary = $resolve(10);
$check($managerAllSalary['permission_scopes']['employees.salary.read']['scope'] === 'all',
    'Explicit manager company-wide scope must be applied only to its permission');
// If a manager does NOT hold a separate company-wide read grant, a
// salary=all override remains bounded by readable employees.
$db->table('employee_access_roles')->where('employee_id', 10)
    ->where('role', SpecialRoles::PersonnelOfficer)->delete();
$managerOnly = $resolve(10);
$check($managerOnly['permission_scopes']['employees.read']['scope'] === 'subtree',
    'Manager-only read should be scoped');
$check($managerOnly['permission_scopes']['employees.salary.read']['scope'] === 'subtree',
    'Salary=all must not bypass employee-read scope');
$rules->setRule(SpecialRoles::Managers, 'employees.salary.read', false);
$managerDenied = $resolve(10);
$check(!$managerDenied['permissions']['employees.salary.read'],
    'Explicit aggregate manager deny must prevail over legacy grants');

echo "Employees scoped grants integration checks passed\n";
