<?php

/** Regression: a successful one-shot migration is not sufficient after drift. */
require __DIR__.'/../vendor/autoload.php';

use App\Models\Employee;
use App\Models\EmployeeAccessRole;
use App\Services\EmployeeReadService;
use App\Services\SystemAccountBootstrapService;
use App\Services\SystemAccountService;
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

$db->statement('CREATE TABLE employees (id INTEGER PRIMARY KEY, full_name TEXT, first_name TEXT, last_name TEXT, login TEXT UNIQUE, work_email TEXT, position TEXT, employment_status TEXT, work_format TEXT, cooperation_type TEXT, account_type TEXT DEFAULT "employee", identity_status TEXT, keycloak_user_id TEXT, onboarding_email_status TEXT, is_remote INTEGER, department_id INTEGER, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT)');
$db->statement('CREATE TABLE employee_access_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, role TEXT, created_at TEXT, updated_at TEXT, UNIQUE(employee_id, role))');
$db->table('employees')->insert([
    ['id'=>1,'full_name'=>'Human Platform Admin','login'=>'human.admin','account_type'=>'employee',
        'cooperation_type'=>null,'keycloak_user_id'=>'human-sub','employment_status'=>'Трудоустроен'],
    ['id'=>2,'full_name'=>'Legacy Integration','login'=>'integration.bot','account_type'=>'employee',
        'cooperation_type'=>'Системный аккаунт','keycloak_user_id'=>'bot-sub','employment_status'=>'Трудоустроен'],
]);
$db->table('employee_access_roles')->insert([
    'employee_id'=>1, 'role'=>'platform-admin', 'created_at'=>'2026-01-01',
    'updated_at'=>'2026-01-01',
]);

$check = static function (bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
};
$projection = new SystemAccountService();
$check(array_column($projection->list(), 'login') === ['integration.bot'],
    'Legacy unclassified technical identity omitted');
$check((new EmployeeReadService())->list([])['meta']['count'] === 1,
    'Misclassified system identity leaked into the employees directory');

$bootstrapService = new SystemAccountBootstrapService();
$bootstrapService->reconcile();
$admin = Employee::query()->where('login','admin')->firstOrFail();
$check($admin->account_type === 'system' && $admin->cooperation_type === 'Системный аккаунт',
    'Missing bootstrap identity was not restored');
$check(EmployeeAccessRole::query()->where('employee_id',$admin->id)
    ->where('role','platform-admin')->count() === 1, 'Protected grant missing');
$check(Employee::query()->where('login','integration.bot')->value('account_type') === 'system',
    'Legacy technical marker was not reconciled');
$check(Employee::query()->where('login','human.admin')->value('account_type') === 'employee',
    'Human platform-admin incorrectly reclassified');

$admin->keycloak_user_id = 'protected-existing-sub';
$admin->account_type = 'employee';
$admin->save();
$bootstrapService->reconcile();
$check(Employee::query()->where('login','admin')->value('keycloak_user_id') === 'protected-existing-sub',
    'Existing Keycloak link was overwritten');
$check(Employee::query()->where('login','admin')->value('account_type') === 'system',
    'Bootstrap classification drift was not repaired');
$check(Employee::query()->where('login','admin')->count() === 1 &&
    EmployeeAccessRole::query()->where('employee_id',$admin->id)->where('role','platform-admin')->count() === 1,
    'Idempotent reconciliation created duplicate identities or roles');
$check(count($projection->list()) === 2 && (new EmployeeReadService())->list([])['meta']['count'] === 1,
    'Directories disagreed after the runtime repair');

echo "System accounts runtime integrity: PASS\n";
