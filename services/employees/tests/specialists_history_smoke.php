<?php

/** Synthetic employment history projection without salary or private metadata. */
require __DIR__.'/../vendor/autoload.php';
use App\Services\EmployeeSpecialistHistoryService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

$app=new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$capsule=new Manager($app);
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$app->instance('db',$capsule->getDatabaseManager());
DB::swap($capsule->getConnection());
Model::setConnectionResolver($capsule->getDatabaseManager());
$db=$capsule->getConnection();
$db->statement('CREATE TABLE employment_periods (id INTEGER PRIMARY KEY, employee_id INTEGER, started_at TEXT, ended_at TEXT)');
$db->statement('CREATE TABLE employment_assignment_history (id INTEGER PRIMARY KEY, employee_id INTEGER, department_id INTEGER, position TEXT, effective_from TEXT)');
$db->statement('CREATE TABLE employee_status_history (id INTEGER PRIMARY KEY, employee_id INTEGER, status TEXT, effective_from TEXT)');
$db->statement('CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT)');
$db->table('departments')->insert(['id'=>1,'name'=>'Backend']);
$db->table('employment_periods')->insert([
    ['id'=>7,'employee_id'=>11,'started_at'=>'2025-02-01','ended_at'=>'2026-01-31'],
    ['id'=>8,'employee_id'=>11,'started_at'=>'2026-03-01','ended_at'=>null],
]);
$db->table('employment_assignment_history')->insert([
    'id'=>9,'employee_id'=>11,'department_id'=>1,'position'=>'Developer','effective_from'=>'2026-03-01',
]);
$db->table('employee_status_history')->insert([
    'id'=>10,'employee_id'=>11,'status'=>'Трудоустроен','effective_from'=>'2026-03-01',
]);
$timeline=(new EmployeeSpecialistHistoryService())->timeline(11);
if(count($timeline)!==5)throw new RuntimeException('Hiring, rehiring, dismissal, assignment and status missing');
if(count(array_filter($timeline,fn($item)=>$item['title']==='Трудоустройство'))!==2)
    throw new RuntimeException('Rehiring must remain visible');
if(str_contains(json_encode($timeline,JSON_UNESCAPED_UNICODE),'salary'))
    throw new RuntimeException('Salary data leaked into Specialists history');
if((new EmployeeSpecialistHistoryService())->timeline(12)!==[])
    throw new RuntimeException('Events leaked between employee IDs');
echo "Employees sanitized Specialists history: PASS\n";
