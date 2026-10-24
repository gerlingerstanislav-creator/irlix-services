<?php

require __DIR__.'/../vendor/autoload.php';

use App\Models\Department;
use App\Models\Employee;
use App\Services\BrandbookSessionService;
use App\Services\PermissionRuleService;
use App\Services\SpecialRoleService;
use App\Support\SpecialRoles;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('date', new Illuminate\Support\DateFactory());
$capsule = new Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
DB::swap($db);
Model::setConnectionResolver($capsule->getDatabaseManager());

$db->statement('CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT, alias TEXT, parent_id INTEGER, manager_id INTEGER, is_production INTEGER, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE employees (id INTEGER PRIMARY KEY, full_name TEXT, login TEXT, work_email TEXT, position TEXT, department_id INTEGER, employment_status TEXT, keycloak_user_id TEXT, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE employee_access_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, role TEXT, created_at TEXT, updated_at TEXT, UNIQUE(employee_id, role))');
$db->statement('CREATE TABLE employee_permission_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, role TEXT, permission TEXT, enabled INTEGER, created_at TEXT, updated_at TEXT, UNIQUE(role, permission))');

$db->table('departments')->insert(['id' => 5, 'name' => 'Synthetic team', 'alias' => 'engineering', 'parent_id' => null, 'is_production' => 1]);
$db->table('employees')->insert([
    'id' => 7, 'full_name' => 'Synthetic Staff', 'login' => 'synthetic.user',
    'work_email' => 'synthetic@example.invalid', 'position' => 'Engineer', 'department_id' => 5,
    'employment_status' => 'Трудоустроен', 'keycloak_user_id' => 'synthetic-identity',
]);
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$roles = new SpecialRoleService();
$roles->assign(7, SpecialRoles::TopManagement);
$roles->assign(7, SpecialRoles::TopManagement);
$check($db->table('employee_access_roles')->where('employee_id', 7)->count() === 1,
    'Role assignments must be idempotent');
$catalog = $roles->catalogWithMembers();
$top = collect($catalog)->firstWhere('key', SpecialRoles::TopManagement);
$check(($top['member_count'] ?? null) === 1, 'Role catalog count changed');
$member = $top['members'][0] ?? null;
$check(($member['department_name'] ?? null) === 'Synthetic team', 'Member local department join failed');
$check(array_keys($member) === [
    'id', 'full_name', 'login', 'work_email', 'position', 'department_id', 'department_name', 'created_at',
], 'Member contract changed or leaked fields');
$check(isset($member['created_at']) && is_string($member['created_at']), 'Assignment timestamp must remain raw');
$check(Employee::query()->whereKey(7)->first()?->department?->id === 5, 'Employee belongsTo relation failed');
$check(Department::query()->whereKey(5)->first()?->is_production === true, 'Department boolean cast failed');

$rules = new PermissionRuleService();
$check(isset($rules->roleCatalog()['engineering-head']), 'Department-head permission alias missing');
$rules->setRule('engineering-head', 'employees.read', false);
$rules->setRule('engineering-head', 'employees.read', true);
$check($db->table('employee_permission_rules')->count() === 1, 'Rule upsert must be idempotent');
$check($rules->rules()->first()?->enabled === true, 'Rule Eloquent cast/overwrite failed');

$brandbook = new BrandbookSessionService($roles);
$access = ['employee_id' => 7, 'roles' => [SpecialRoles::TopManagement], 'services' => ['brandbook' => true]];
$check($brandbook->isAllowedByAccess($access), 'Top management gate rejected');
$check($brandbook->verifiedEmployee($access, ['sub' => 'synthetic-identity'])?->id === 7,
    'Brandbook identity binding rejected');
$check($brandbook->verifiedEmployee($access, ['sub' => 'forged-identity']) === null,
    'Brandbook allowed mismatched identity');
$check(!$brandbook->isAllowedByAccess([...$access, 'services' => ['brandbook' => false]]),
    'Brandbook granted unapproved service entry');
Employee::query()->whereKey(7)->update(['employment_status' => 'Уволен']);
$check($brandbook->verifiedEmployee($access, ['sub' => 'synthetic-identity']) === null,
    'Dismissed staff retained brandbook access');
Employee::query()->whereKey(7)->update(['employment_status' => 'Трудоустроен']);
$roles->revoke(7, SpecialRoles::TopManagement);
$check($brandbook->verifiedEmployee($access, ['sub' => 'synthetic-identity']) === null,
    'Revoked role retained brandbook access');

echo "Employees Eloquent role, permission, and brandbook services: PASS\n";
