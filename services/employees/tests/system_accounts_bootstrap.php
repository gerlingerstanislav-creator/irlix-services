<?php

/** The legacy bootstrap migration can be already applied without an admin row. */
require __DIR__.'/../vendor/autoload.php';

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

$db->statement('CREATE TABLE employees (id INTEGER PRIMARY KEY, full_name TEXT, first_name TEXT, last_name TEXT, login TEXT UNIQUE, work_email TEXT, position TEXT, employment_status TEXT, work_format TEXT, cooperation_type TEXT, account_type TEXT DEFAULT "employee", identity_status TEXT, keycloak_user_id TEXT, onboarding_email_status TEXT, is_remote INTEGER, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE employee_access_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, role TEXT, created_at TEXT, updated_at TEXT, UNIQUE(employee_id, role))');
$db->table('employees')->insert([
    ['id'=>1,'full_name'=>'Real Admin', 'login'=>'real.admin', 'account_type'=>'employee','employment_status'=>'Трудоустроен'],
    ['id'=>2,'full_name'=>'Integration Bot', 'login'=>'integration.bot','account_type'=>'employee','cooperation_type'=>'Системный аккаунт'],
]);

$migration = require __DIR__.'/../database/migrations/2026_11_06_190000_reconcile_system_accounts.php';
$check = static fn (bool $ok, string $message) => $ok ?: throw new RuntimeException($message);

$migration->up();
$bootstrap = $db->table('employees')->where('login','admin')->first();
$check($bootstrap !== null && $bootstrap->account_type === 'system', 'Missing bootstrap identity was not recovered');
$check($db->table('employee_access_roles')->where('employee_id',$bootstrap->id)->where('role','platform-admin')->count() === 1,
    'Bootstrap role not created');
$check($db->table('employees')->where('id',1)->value('account_type') === 'employee',
    'Human administrator incorrectly reclassified');
$check($db->table('employees')->where('id',2)->value('account_type') === 'system',
    'Existing technical account was not reclassified');

$db->table('employees')->where('id',$bootstrap->id)->update([
    'account_type'=>'employee', 'keycloak_user_id'=>'known-keycloak-sub'
]);
$migration->up();
$check($db->table('employees')->where('login','admin')->count() === 1, 'Duplicate bootstrap identity');
$check($db->table('employees')->where('login','admin')->value('keycloak_user_id') === 'known-keycloak-sub',
    'Existing Keycloak identity link was overwritten');
$check($db->table('employees')->where('login','admin')->value('account_type') === 'system',
    'Misclassified bootstrap was not repaired');
$check($db->table('employee_access_roles')->where('employee_id',$bootstrap->id)->where('role','platform-admin')->count() === 1,
    'Duplicate bootstrap role');

echo "System accounts bootstrap reconciliation: PASS\n";
