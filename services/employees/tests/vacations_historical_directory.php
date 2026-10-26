<?php
// Synthetic, read-only-to-production regression for Employees -> Vacations historical directory.
require __DIR__.'/../vendor/autoload.php';

use App\Services\VacationsHistoricalDirectoryService;
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
$app->instance('db.schema', $db->getSchemaBuilder());
DB::swap($db);
\Illuminate\Database\Eloquent\Model::setConnectionResolver($capsule->getDatabaseManager());

$db->statement('CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT, parent_id INTEGER)');
$db->statement('CREATE TABLE employees (id INTEGER PRIMARY KEY, full_name TEXT, employment_status TEXT)');
$db->statement('CREATE TABLE employment_periods (id INTEGER PRIMARY KEY, employee_id INTEGER, started_at TEXT, ended_at TEXT, department_id INTEGER)');
$db->statement('CREATE TABLE employment_assignment_history (id INTEGER PRIMARY KEY, employee_id INTEGER, department_id INTEGER, effective_from TEXT, effective_to TEXT)');
$db->table('departments')->insert([
    ['id'=>1,'name'=>'Аналитика','parent_id'=>null],['id'=>2,'name'=>'Разработка','parent_id'=>null],
]);
$db->table('employees')->insert([
    ['id'=>1,'full_name'=>'Бывший сотрудник','employment_status'=>'Уволен'],
    ['id'=>2,'full_name'=>'Переведённый сотрудник','employment_status'=>'Трудоустроен'],
    ['id'=>3,'full_name'=>'Будущий сотрудник','employment_status'=>'Трудоустроен'],
    ['id'=>4,'full_name'=>'Повторно принятый','employment_status'=>'Уволен'],
]);
$db->table('employment_periods')->insert([
    ['id'=>1,'employee_id'=>1,'started_at'=>'2026-01-01','ended_at'=>'2026-10-10','department_id'=>1],
    ['id'=>2,'employee_id'=>2,'started_at'=>'2026-01-01','ended_at'=>null,'department_id'=>1],
    ['id'=>3,'employee_id'=>3,'started_at'=>'2027-01-01','ended_at'=>null,'department_id'=>2],
    ['id'=>4,'employee_id'=>4,'started_at'=>'2026-01-01','ended_at'=>'2026-02-28','department_id'=>1],
    ['id'=>5,'employee_id'=>4,'started_at'=>'2026-10-05','ended_at'=>'2026-10-20','department_id'=>2],
]);
$db->table('employment_assignment_history')->insert([
    ['id'=>1,'employee_id'=>1,'department_id'=>1,'effective_from'=>'2026-01-01','effective_to'=>'2026-10-10'],
    ['id'=>2,'employee_id'=>2,'department_id'=>1,'effective_from'=>'2026-01-01','effective_to'=>'2026-10-14'],
    ['id'=>3,'employee_id'=>2,'department_id'=>2,'effective_from'=>'2026-10-15','effective_to'=>null],
    ['id'=>4,'employee_id'=>4,'department_id'=>1,'effective_from'=>'2026-01-01','effective_to'=>'2026-02-28'],
    ['id'=>5,'employee_id'=>4,'department_id'=>2,'effective_from'=>'2026-10-05','effective_to'=>'2026-10-20'],
]);

$service = new VacationsHistoricalDirectoryService();
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$onlyAnalytics = $service->forYear(['roles'=>['manager'],'department_ids'=>[1]],2026);
$check(array_column($onlyAnalytics['employees'],'id') === [1,2,4], 'Historical former/transfer/rehire directory mismatch');
$check(count($onlyAnalytics['departments']) === 1, 'Manager sees unauthorized departments');
$check(array_keys($onlyAnalytics['employees'][0]) === ['id','full_name','employment_status','department_id','department_name','employment_segments'], 'Unexpected sensitive employee field');
$transferred = $onlyAnalytics['employees'][1];
$check(count($transferred['employment_segments']) === 1 && $transferred['employment_segments'][0]['to'] === '2026-10-14', 'Transfer leaked other department interval');

$onlyDev = $service->forYear(['roles'=>['manager'],'department_ids'=>[2]],2026);
$check(array_column($onlyDev['employees'],'id') === [2,4], 'Historical target department incorrect');
$check($onlyDev['employees'][0]['employment_segments'][0]['from'] === '2026-10-15', 'Transferred start incorrect');
$check($service->forYear(['roles'=>['manager'],'department_ids'=>[]],2026)['employees'] === [], 'Missing scope leaked data');
$check($service->forYear(['roles'=>['hr'],'department_ids'=>[]],2026)['employees'] !== [], 'HR should have global workforce history');
$check(array_column($service->forYear(['roles'=>['hr']],2026)['employees'],'id') === [1,2,4], 'Future-only employee entered the past year');
echo "Historical Vacations directory (scopes, transfers, former and rehires): PASS\n";
