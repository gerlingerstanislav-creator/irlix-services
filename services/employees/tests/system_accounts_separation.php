<?php

/** Technical identities remain authorized but cannot appear as company staff. */
require __DIR__.'/../vendor/autoload.php';

use App\Services\EmployeeReadService;
use App\Services\EmployeeDirectoryService;
use App\Services\FinanceDirectoryService;
use App\Services\SpecialRoleService;
use App\Services\SystemAccountService;
use App\Services\StorageV1DirectoryService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$capsule = new Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
DB::swap($db);
Model::setConnectionResolver($capsule->getDatabaseManager());

$db->statement('CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT, parent_id INTEGER, alias TEXT, manager_id INTEGER, is_production INTEGER)');
$db->statement('CREATE TABLE employees (id INTEGER PRIMARY KEY, full_name TEXT, login TEXT, account_type TEXT DEFAULT "employee", keycloak_user_id TEXT, department_id INTEGER, position TEXT, employment_status TEXT, identity_status TEXT)');
$db->statement('CREATE TABLE employee_access_roles (id INTEGER PRIMARY KEY, employee_id INTEGER, role TEXT, created_at TEXT)');
$db->statement('CREATE TABLE salary_history (id INTEGER PRIMARY KEY, employee_id INTEGER, effective_from TEXT, effective_to TEXT, gross_salary NUMERIC, status TEXT)');
$db->table('departments')->insert(['id'=>1,'name'=>'Synthetic Team','alias'=>'engineering','parent_id'=>null,'manager_id'=>null,'is_production'=>1]);
$db->table('employees')->insert([
    ['id'=>1,'full_name'=>'Synthetic Engineer','login'=>'engineer.test','account_type'=>'employee','keycloak_user_id'=>'employee-sub','department_id'=>1,'employment_status'=>'Трудоустроен','identity_status'=>'active'],
    ['id'=>2,'full_name'=>'Synthetic Bootstrap','login'=>'admin','account_type'=>'system','keycloak_user_id'=>'admin-sub','department_id'=>1,'employment_status'=>'Трудоустроен','identity_status'=>'active'],
]);
$db->table('employee_access_roles')->insert(['id'=>1,'employee_id'=>2,'role'=>'platform-admin']);
$db->table('salary_history')->insert([
    ['id'=>1,'employee_id'=>1,'effective_from'=>'2026-01-01','effective_to'=>null,'gross_salary'=>100000,'status'=>'Действует'],
    ['id'=>2,'employee_id'=>2,'effective_from'=>'2026-01-01','effective_to'=>null,'gross_salary'=>99999,'status'=>'Действует'],
]);
$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException($why);
};
$read = new EmployeeReadService();
$staff = $read->list([]);
$check($staff['meta']['count'] === 1 && (int) $staff['data'][0]->id === 1, 'System account included in employee list');
$check($read->details(2) === null && $read->recordBusiness(2) === null, 'HR profile exposed for system account');
$check($read->record(2)?->login === 'admin', 'Bootstrap identity anchor was removed');

$directory = new EmployeeDirectoryService();
$recipients = $directory->equipmentDirectory(true, null);
$check(count($recipients) === 1 && (int) $recipients[0]->id === 1, 'Equipment directory leaked system account');
$check($directory->equipmentDirectory(true, 2) === null, 'Direct equipment lookup leaked system account');

$system = (new SystemAccountService())->list();
$check(count($system) === 1 && $system[0]['login'] === 'admin' && $system[0]['protected']
    && $system[0]['identity_linked'] && $system[0]['roles'] === ['platform-admin'],
    'System account administrative projection lost bootstrap role or identity');

$catalog = (new SpecialRoleService())->catalogWithMembers();
$adminRole = collect($catalog)->firstWhere('key', 'platform-admin');
$check($adminRole !== null && $adminRole['member_count'] === 0, 'System identity included in employee role membership');

$finance = (new FinanceDirectoryService())->payrollConditions('2026-11');
$check(count($finance['employees']) === 1 && count($finance['salary_conditions']) === 1,
    'Finance payroll directory contains system identity or its salary');

$storage = (new StorageV1DirectoryService())->people();
$check(count($storage) === 1 && $storage[0]['id'] === 1, 'Storage recipient list contains system identity');

echo "System accounts separation: PASS\n";
