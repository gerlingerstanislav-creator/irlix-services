<?php

// Disposable SQLite fixture: deleting an employee must not delete a shared asset.
require __DIR__.'/../vendor/autoload.php';

use App\Services\EmployeePurgeService;
use App\Support\EmployeePurgeRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$capsule = new Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
DB::swap($db);

$db->statement('CREATE TABLE equipment_assignments (id INTEGER PRIMARY KEY, equipment_item_id INTEGER, employee_id INTEGER, issued_by TEXT, returned_by TEXT)');
$db->statement('CREATE TABLE equipment_audit_log (id INTEGER PRIMARY KEY, equipment_item_id INTEGER, actor TEXT, payload TEXT)');
$db->statement('CREATE TABLE equipment_damages (id INTEGER PRIMARY KEY, actor TEXT)');
$db->statement('CREATE TABLE equipment_write_offs (id INTEGER PRIMARY KEY, actor TEXT)');
$db->statement('CREATE TABLE equipment_permission_audit (id INTEGER PRIMARY KEY, actor TEXT)');
$db->table('equipment_permission_audit')->insert(['id'=>1,'actor'=>'synthetic.login']);
$db->table('equipment_assignments')->insert([
    ['id'=>1,'equipment_item_id'=>55,'employee_id'=>7,'issued_by'=>'synthetic.login','returned_by'=>null],
    ['id'=>2,'equipment_item_id'=>56,'employee_id'=>8,'issued_by'=>'synthetic.login','returned_by'=>'other.user'],
]);
$db->table('equipment_audit_log')->insert([
    ['id'=>1,'equipment_item_id'=>55,'actor'=>'other.user','payload'=>'{"employee_id":7}'],
    ['id'=>2,'equipment_item_id'=>56,'actor'=>'synthetic.login','payload'=>'{}'],
    ['id'=>3,'equipment_item_id'=>56,'actor'=>'other.user','payload'=>'{}'],
]);
$db->table('equipment_damages')->insert(['id'=>1,'actor'=>'Synthetic Name']);
$db->table('equipment_write_offs')->insert(['id'=>1,'actor'=>'synthetic.login']);

$purge = new EmployeePurgeService(new EmployeePurgeRepository());
$purge->purge(7,'synthetic.login','Synthetic Name');
$purge->purge(7,'synthetic.login','Synthetic Name');
$check=static function($v,$reason){if(!$v)throw new RuntimeException($reason);};
$check($db->table('equipment_assignments')->where('employee_id',7)->count()===0,'Deleted person still assigned equipment');
$check($db->table('equipment_assignments')->where('employee_id',8)->count()===1,'Another employee lost assignment');
$check($db->table('equipment_assignments')->where('id',2)->value('issued_by')==='Удалённый сотрудник','Foreign issue actor retained login');
$check($db->table('equipment_audit_log')->count()===1 && $db->table('equipment_audit_log')->first()->id===3,'Employee audit records were not scrubbed');
$check($db->table('equipment_damages')->first()->actor==='Удалённый сотрудник','Damage actor retained name');
$check($db->table('equipment_write_offs')->first()->actor==='Удалённый сотрудник','Write-off actor retained login');
$check($db->table('equipment_permission_audit')->count()===0,'Permission audit retained employee actor');
echo "Equipment employee full reference erase, isolation and retry: PASS\n";
