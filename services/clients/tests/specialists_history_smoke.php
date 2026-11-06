<?php

/** Synthetic client-side history projection, with no cross-database reads. */
require __DIR__.'/../vendor/autoload.php';
use App\Services\SpecialistsProjectDirectory;
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
$app->instance('db',$capsule->getDatabaseManager());
DB::swap($capsule->getConnection());
Model::setConnectionResolver($capsule->getDatabaseManager());
$db=$capsule->getConnection();
$db->statement('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT)');
$db->statement('CREATE TABLE projects (id INTEGER PRIMARY KEY, client_id INTEGER, name TEXT)');
$db->statement('CREATE TABLE client_members (id INTEGER PRIMARY KEY, employee_id INTEGER)');
$db->statement('CREATE TABLE project_assignments (id INTEGER PRIMARY KEY, client_member_id INTEGER, project_id INTEGER, valid_from TEXT, valid_to TEXT)');
$db->statement('CREATE TABLE client_member_feedbacks (id INTEGER PRIMARY KEY, client_member_id INTEGER, project_id INTEGER, text TEXT, occurred_on TEXT, created_at TEXT, created_by_username TEXT)');
$db->table('clients')->insert(['id'=>1,'name'=>'Synthetic Client']);
$db->table('projects')->insert(['id'=>3,'client_id'=>1,'name'=>'Synthetic Project']);
$db->table('client_members')->insert([['id'=>7,'employee_id'=>11],['id'=>8,'employee_id'=>12]]);
$db->table('project_assignments')->insert([
    ['id'=>20,'client_member_id'=>7,'project_id'=>3,'valid_from'=>'2026-01-01','valid_to'=>'2026-02-01'],
    ['id'=>21,'client_member_id'=>7,'project_id'=>3,'valid_from'=>'2026-03-01','valid_to'=>null],
]);
$db->table('client_member_feedbacks')->insert([
    'id'=>30,'client_member_id'=>7,'project_id'=>3,'text'=>'Strong delivery',
    'occurred_on'=>'2026-01-20','created_at'=>'2026-01-21 08:00:00','created_by_username'=>'synthetic',
]);
$result=(new SpecialistsProjectDirectory())->historyForEmployee(11);
$assert=static function (bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$assert(count($result)===4,'Expected reconnect, disconnect and feedback events');
$assert(count(array_filter($result,fn($event)=>$event['title']==='Подключение к проекту'))===2,
    'Repeated project assignments must not be silently deduplicated');
$assert(count(array_filter($result,fn($event)=>$event['type']==='feedback'))===1,
    'Canonical feedback omitted');
$assert(array_keys($result[0]) === ['id','type','title','text','created_at'],
    'Sensitive Clients fields leaked in the lifecycle projection');
$assert((new SpecialistsProjectDirectory())->historyForEmployee(12)===[],
    'Another employee inherited project timeline');
echo "Clients scoped Specialists project history: PASS\n";
