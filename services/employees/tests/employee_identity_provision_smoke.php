<?php

// Isolated Keycloak/Employees contract; synthetic records and no external HTTP.
require __DIR__.'/../vendor/autoload.php';

use App\Models\Employee;
use App\Services\EmployeeIdentityService;
use App\Services\EmployeeIdentityProvisionService;
use App\Services\EmployeeReadService;
use App\Http\Controllers\EmployeeIdentityProvisionController;
use App\Support\EmployeeOnboardingService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('date', new Illuminate\Support\DateFactory());
$capsule = new Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
DB::swap($db);
Model::setConnectionResolver($capsule->getDatabaseManager());

$db->statement('CREATE TABLE employees (
 id INTEGER PRIMARY KEY, account_type TEXT, login TEXT, work_email TEXT,
 personal_email TEXT, first_name TEXT, last_name TEXT, full_name TEXT,
 department_id INTEGER, employment_status TEXT, identity_status TEXT,
 keycloak_user_id TEXT UNIQUE, onboarding_email_status TEXT,
 created_at TEXT, updated_at TEXT
)');
$db->statement('CREATE TABLE departments (id INTEGER PRIMARY KEY, ldap_group TEXT)');
$db->statement('CREATE TABLE salary_history (id INTEGER PRIMARY KEY, employee_id INTEGER, gross_salary INTEGER)');
$db->table('employees')->insert([
 ['id'=>7,'account_type'=>'employee','login'=>'synthetic.imported','work_email'=>'synthetic.imported@irlix.ru',
  'personal_email'=>'synthetic@example.invalid','employment_status'=>'Трудоустроен',
  'identity_status'=>'not_provisioned','keycloak_user_id'=>null],
 ['id'=>8,'account_type'=>'employee','login'=>'synthetic.legacy','work_email'=>'synthetic.legacy@irlix.ru',
  'personal_email'=>'legacy@example.invalid','employment_status'=>'Трудоустроен',
  'identity_status'=>'active','keycloak_user_id'=>'old-identity'],
 ['id'=>9,'account_type'=>'employee','login'=>'synthetic.conflicting','work_email'=>'synthetic.conflicting@irlix.ru',
  'personal_email'=>'conflict@example.invalid','employment_status'=>'Трудоустроен',
  'identity_status'=>'not_provisioned','keycloak_user_id'=>null],
 ['id'=>10,'account_type'=>'employee','login'=>'synthetic.unreliable','work_email'=>'synthetic.unreliable@irlix.ru',
  'personal_email'=>'unreliable@example.invalid','employment_status'=>'Трудоустроен',
  'identity_status'=>'not_provisioned','keycloak_user_id'=>null],
]);
$db->table('salary_history')->insert(['id'=>1,'employee_id'=>7,'gross_salary'=>100]);
$onboarding = new class extends EmployeeOnboardingService {
    public array $sent = [];
    public function sendPasswordSetup(int $id): array {
        $this->sent[] = $id;
        Employee::query()->whereKey($id)->update(['onboarding_email_status'=>'sent']);
        return ['status'=>'sent'];
    }
};
$factory = new Factory();
Http::swap($factory);
$factory->preventStrayRequests();
$posts = [];
$factory->fake(static function ($request) use (&$posts, $factory) {
    $url = $request->url();
    if (str_ends_with($url, '/realms/master/protocol/openid-connect/token')) {
        return $factory->response(['access_token'=>'synthetic-test-token'],200);
    }
    if (str_ends_with($url, '/admin/realms/irlix/users')) {
        $username = $request->data()['username'] ?? '';
        $posts[] = $username;
        if ($username === 'synthetic.conflicting') return $factory->response(null,409);
        if ($username === 'synthetic.unreliable') throw new ConnectionException('synthetic uncertain create');
        return $factory->response(null, 201,
          ['Location' => 'http://keycloak.test/admin/realms/irlix/users/synthetic-new-identity']);
    }
    if (str_ends_with($url, '/admin/realms/irlix/users/synthetic-new-identity/groups')) {
        return $factory->response([],200);
    }
    throw new RuntimeException('Unexpected HTTP in identity test');
});

$check = static fn (bool $ok, string $message) => $ok ?: throw new RuntimeException($message);
$service = new EmployeeIdentityProvisionService(new EmployeeIdentityService(), $onboarding);

$notAuthorized = Request::create('/api/employees/7/keycloak-provision','POST');
$notAuthorized->attributes->set('employees_access',['roles'=>['hr'],'permissions'=>['access.manage'=>true,'employees.manage'=>true]]);
$denied = (new EmployeeIdentityProvisionController())->provision($notAuthorized,7,$service,new EmployeeReadService());
$check($denied->getStatusCode()===403, 'Non-admin was allowed to provision identity');
$check(!$posts, 'Denied request called Keycloak');

$result = $service->provision(7);
$check(($result['status']??'')==='provisioned' && ($result['onboarding_email_status']??'')==='sent',
  'Provision did not link employee and send onboarding');
$check(Employee::query()->whereKey(7)->value('keycloak_user_id')==='synthetic-new-identity'
  && Employee::query()->whereKey(7)->value('identity_status')==='active', 'Identity not saved');
$check($db->table('salary_history')->where('employee_id',7)->value('gross_salary')===100
  && Employee::query()->whereKey(7)->value('employment_status')==='Трудоустроен',
  'Keycloak provision changed HR history or payroll');
$check(Employee::query()->whereKey(8)->value('keycloak_user_id')==='old-identity',
  'Legacy employee identity was modified');
$check($onboarding->sent===[7] && $posts===['synthetic.imported'], 'Unexpected onboarding / duplicate creation');

$repeat=false;
try { $service->provision(7); } catch (DomainException) { $repeat=true; }
$check($repeat && count($posts)===1,'Second request duplicated Keycloak identity');

$existing=false;
try { $service->provision(9); } catch (DomainException) { $existing=true; }
$check($existing && Employee::query()->whereKey(9)->value('identity_status')==='not_provisioned',
  'Explicit Keycloak collision must not lock or overwrite employee');

$uncertain=false;
try { $service->provision(10); } catch (ConnectionException) { $uncertain=true; }
$check($uncertain && Employee::query()->whereKey(10)->value('identity_status')==='provisioning'
  && Employee::query()->whereKey(10)->value('keycloak_user_id')===null,
  'Ambiguous Keycloak POST failure must block blind retry');
$check(count($posts)===3,'Non-idempotent Keycloak create was retried');

echo "Employees existing employee Keycloak provision, authorization and failure isolation: PASS\n";
