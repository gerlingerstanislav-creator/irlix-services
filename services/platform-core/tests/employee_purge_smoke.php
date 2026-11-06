<?php

require __DIR__.'/../vendor/autoload.php';

use App\Reports\EmployeePurgeService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$capsule = new Manager($app);
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$db = $capsule->getConnection();
DB::swap($db);
Model::setConnectionResolver($capsule->getDatabaseManager());
$db->statement('CREATE TABLE bug_reports (id INTEGER PRIMARY KEY,employee_id INTEGER,employee_name TEXT,reporter_keycloak_sub TEXT,service_key TEXT,page_path TEXT,description TEXT,status TEXT,closed_by_employee_id INTEGER,closed_at TEXT,created_at TEXT)');
$db->table('bug_reports')->insert([
 ['id'=>1,'employee_id'=>7,'employee_name'=>'Synthetic 7','reporter_keycloak_sub'=>'s7','status'=>'open','closed_by_employee_id'=>null],
 ['id'=>2,'employee_id'=>8,'employee_name'=>'Synthetic 8','reporter_keycloak_sub'=>'s8','status'=>'closed','closed_by_employee_id'=>7]
]);
$purge = new EmployeePurgeService();
$purge->purge(7);
$purge->purge(7);
if ($db->table('bug_reports')->where('employee_id',7)->exists() ||
    $db->table('bug_reports')->where('id',2)->count() !== 1 ||
    $db->table('bug_reports')->where('id',2)->value('closed_by_employee_id') !== null) {
    throw new RuntimeException('Platform Core employee report erasure violated owner isolation');
}
echo "Platform Core employee reports purge: PASS\n";
