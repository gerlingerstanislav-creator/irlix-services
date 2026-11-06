<?php

// Synthetic SQLite contract: all employee-owned review and auth/audit records
// disappear, while unrelated business reviews remain usable and anonymous.
require __DIR__.'/../vendor/autoload.php';

use App\Models\Employee;
use App\Support\EmployeePurgeRepository;
use App\Http\Middleware\EmployeeMutationJournal;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$capsule = new Manager($app);
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$db = $capsule->getConnection();
DB::swap($db);
Model::setConnectionResolver($capsule->getDatabaseManager());

$definitions = [
 'employees' => 'id INTEGER PRIMARY KEY,login TEXT,full_name TEXT,keycloak_user_id TEXT,created_at TEXT,updated_at TEXT',
 'salary_reviews' => 'id INTEGER PRIMARY KEY,employee_id INTEGER,created_by INTEGER',
 'salary_review_versions' => 'id INTEGER PRIMARY KEY,review_id INTEGER,changed_by INTEGER',
 'salary_review_approvals' => 'id INTEGER PRIMARY KEY,review_id INTEGER,approver_id INTEGER',
 'audit_log' => 'id INTEGER PRIMARY KEY,actor_employee_id INTEGER,actor_sub TEXT,target_type TEXT,target_id TEXT,before TEXT,after TEXT,metadata TEXT',
 'outbox_events' => 'id TEXT PRIMARY KEY,aggregate_type TEXT,aggregate_id TEXT,payload TEXT',
 'keycloak_auth_sync' => 'employee_id INTEGER PRIMARY KEY',
 'employee_access_roles' => 'id INTEGER PRIMARY KEY,employee_id INTEGER,role TEXT',
 'salary_history' => 'id INTEGER PRIMARY KEY,employee_id INTEGER,effective_to TEXT',
];
foreach ($definitions as $table => $schema) $db->statement("CREATE TABLE {$table} ({$schema})");
$db->table('employees')->insert([
 ['id'=>7,'login'=>'synthetic.7','full_name'=>'Synthetic 7','keycloak_user_id'=>'test-identity-7'],
 ['id'=>8,'login'=>'synthetic.8','full_name'=>'Synthetic 8','keycloak_user_id'=>'test-identity-8'],
]);
$db->table('salary_reviews')->insert([
 ['id'=>10,'employee_id'=>7,'created_by'=>8],
 ['id'=>20,'employee_id'=>8,'created_by'=>7],
]);
$db->table('salary_review_versions')->insert([
 ['id'=>11,'review_id'=>10,'changed_by'=>8],
 ['id'=>21,'review_id'=>20,'changed_by'=>7],
]);
$db->table('salary_review_approvals')->insert([
 ['id'=>12,'review_id'=>10,'approver_id'=>8],
 ['id'=>22,'review_id'=>20,'approver_id'=>7],
]);
$db->table('audit_log')->insert([
 ['id'=>30,'actor_employee_id'=>7,'actor_sub'=>'test-identity-7','target_type'=>'client','target_id'=>'8','before'=>'{}','after'=>'{}','metadata'=>'{}'],
 ['id'=>31,'actor_employee_id'=>8,'actor_sub'=>'test-identity-8','target_type'=>'employee','target_id'=>'7','before'=>'{}','after'=>'{}','metadata'=>'{}'],
 ['id'=>32,'actor_employee_id'=>8,'actor_sub'=>'test-identity-8','target_type'=>'client','target_id'=>'20','before'=>'{}','after'=>'{}','metadata'=>'{}'],
]);
$db->table('outbox_events')->insert([
 ['id'=>'e1','aggregate_type'=>'employee','aggregate_id'=>'7','payload'=>'{"employee_id":7,"login":"synthetic.7"}'],
 ['id'=>'e2','aggregate_type'=>'department','aggregate_id'=>'6','payload'=>'{"employee_id":7}'],
 ['id'=>'e3','aggregate_type'=>'employee','aggregate_id'=>'8','payload'=>'{"employee_id":8}'],
]);
$db->table('keycloak_auth_sync')->insert([['employee_id'=>7],['employee_id'=>8]]);
$employee=Employee::query()->findOrFail(7);
(new EmployeePurgeRepository())->erase($employee);
$assert=static function($ok,$msg){if(!$ok)throw new RuntimeException($msg);};
$assert(!Employee::query()->whereKey(7)->exists() && Employee::query()->whereKey(8)->exists(),'Wrong employee deleted');
$assert($db->table('salary_reviews')->pluck('id')->all()===[20], 'Employee payroll reviews were not erased');
$assert($db->table('salary_review_approvals')->pluck('id')->all()===[22], 'Employee review approvals remain');
$assert($db->table('salary_review_versions')->pluck('id')->all()===[21], 'Employee review versions remain');
$assert((int)$db->table('salary_reviews')->first()->created_by===9223372036854775807,
  'Review of another employee retains actor link');
$assert((int)$db->table('salary_review_approvals')->first()->approver_id===9223372036854775807,
  'Foreign salary approver link remains');
$assert((int)$db->table('salary_review_versions')->first()->changed_by===9223372036854775807,
  'Foreign salary editor link remains');
$assert($db->table('audit_log')->pluck('id')->all()===[32], 'Employees audit leaked deleted identity');
$assert($db->table('outbox_events')->pluck('id')->all()===['e3'], 'Employees outbox leaked deleted identity');
$assert($db->table('keycloak_auth_sync')->pluck('employee_id')->all()===[8],
  'Deleted employee auth-sync record remains');

$request=Request::create('/api/employees/8','DELETE');
$request->attributes->set('employees_access',['employee_id'=>99]);
$request->attributes->set('identity',['sub'=>'synthetic-admin']);
$response=(new EmployeeMutationJournal())->handle($request,
    static fn($r)=>new Response('',204));
$assert($response->getStatusCode()===204,'Hard deletion middleware response changed');
$assert($db->table('audit_log')->pluck('id')->all()===[32],
    'Hard deletion middleware recreated identifying audit snapshot');

echo "Employees local payroll, audit, outbox, sync and middleware purge: PASS\n";
