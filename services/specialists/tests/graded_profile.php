<?php

/** Isolated Eloquent/SQLite contract: no external service or production data. */
require __DIR__.'/../vendor/autoload.php';

use App\Services\SpecialistProfessionalService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$capsule = new Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$app->instance('db', $capsule->getDatabaseManager());
DB::swap($capsule->getConnection());
Model::setConnectionResolver($capsule->getDatabaseManager());
$db = $capsule->getConnection();
$db->statement('CREATE TABLE specialist_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER UNIQUE NOT NULL, grade TEXT, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE technologies (id INTEGER PRIMARY KEY, name TEXT, active INTEGER, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE specialist_technologies (employee_id INTEGER, technology_id INTEGER, kind TEXT DEFAULT "secondary", grade TEXT, created_at TEXT, updated_at TEXT, PRIMARY KEY(employee_id, technology_id))');
$db->statement('CREATE TABLE specialist_timeline_events (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, type TEXT, title TEXT, text TEXT, author_employee_id INTEGER, author TEXT, created_at TEXT, updated_at TEXT)');
$db->table('technologies')->insert([
    ['id'=>1, 'name'=>'Vue', 'active'=>1],
    ['id'=>2, 'name'=>'PHP', 'active'=>1],
    ['id'=>3, 'name'=>'Hidden', 'active'=>0],
]);
$db->table('specialist_profiles')->insert(['employee_id'=>11,'grade'=>'Middle']);
$db->table('specialist_technologies')->insert(['employee_id'=>11,'technology_id'=>2]);
$service = new SpecialistProfessionalService();
$check = static function ($ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$prior = $service->technologies(11);
$check($prior[0]['kind']==='secondary' && $prior[0]['grade'] === null,
    'Legacy technology association must survive without invented grade');
$payload = ['grade'=>'Senior',
    'primary'=>[['technology_id'=>1,'grade'=>'Middle+']],
    'secondary_ids'=>[2]];
$after = $service->replace(11, $payload, 21, 'Synthetic Manager');
$check($after['grade']==='Senior', 'Overall grade lost');
$check(count($after['technologies'])===2, 'Technology association count wrong');
$check($db->table('specialist_technologies')->where('technology_id',1)->value('grade')==='Middle+',
    'Primary grade was not persisted');
$check($db->table('specialist_technologies')->where('technology_id',2)->value('kind')==='secondary',
    'Legacy auxiliary association was lost');
$check(count($service->timeline(11))===1, 'A successful change must create one event');
$service->replace(11, $payload, 21, 'Synthetic Manager');
$check(count($service->timeline(11))===1, 'No-op save should not duplicate timeline');
foreach ([
    ['primary'=>[['technology_id'=>1,'grade'=>'Senior']],'secondary_ids'=>[1]],
    ['primary'=>[['technology_id'=>3,'grade'=>'Junior']],'secondary_ids'=>[]],
    ['primary'=>[['technology_id'=>1,'grade'=>'Root']],'secondary_ids'=>[]],
] as $invalid) {
    try {
        $service->replace(11, $invalid, 21, 'Synthetic Manager');
        throw new RuntimeException('Invalid technology/grade mutation was permitted');
    } catch (ValidationException) {
        // Expected: overlapping, inactive and invalid grade all rejected.
    }
}
$check(count($service->technologies(11))===2, 'Invalid request mutated technologies');
$service->comment(11, 'Synthetic note', 21, 'Synthetic Manager');
$events = $service->timeline(11);
$check(count($events)===2 && $events[1]['type']==='comment'
    && $events[1]['author']==='Synthetic Manager', 'History comment was not attributed');
echo "Specialists graded technologies and immutable timeline contract: PASS\n";
