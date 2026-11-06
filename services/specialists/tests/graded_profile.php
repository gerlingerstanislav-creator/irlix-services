<?php

/** Synthetic SQLite rehearsal of historical secondary records and distinct catalogs. */
require __DIR__.'/../vendor/autoload.php';

use App\Models\AdditionalTechnology;
use App\Services\SpecialistProfessionalService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('date', new Illuminate\Support\DateFactory());
$app->instance('validator', new Illuminate\Validation\Factory(
    new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'ru'), $app));
$capsule = new Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$app->instance('db', $capsule->getDatabaseManager());
DB::swap($capsule->getConnection());
Model::setConnectionResolver($capsule->getDatabaseManager());
$db = $capsule->getConnection();
Schema::swap($db->getSchemaBuilder());
$check = static function ($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$db->statement('CREATE TABLE specialist_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER UNIQUE NOT NULL, grade TEXT, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE technologies (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, category TEXT, active INTEGER, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE specialist_technologies (employee_id INTEGER, technology_id INTEGER, kind TEXT DEFAULT "secondary", grade TEXT, created_at TEXT, updated_at TEXT, PRIMARY KEY(employee_id, technology_id))');
$db->statement('CREATE TABLE specialist_timeline_events (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, type TEXT, title TEXT, text TEXT, author_employee_id INTEGER, author TEXT, created_at TEXT, updated_at TEXT)');
$db->table('technologies')->insert([
    ['id'=>1, 'name'=>'Vue', 'active'=>1],
    ['id'=>2, 'name'=>'PHP', 'active'=>1],
    ['id'=>3, 'name'=>'Hidden', 'active'=>0],
]);
$db->table('specialist_profiles')->insert(['employee_id'=>11,'grade'=>'Middle']);
$db->table('specialist_technologies')->insert([
    ['employee_id'=>11,'technology_id'=>1,'kind'=>'primary','grade'=>'Middle'],
    ['employee_id'=>11,'technology_id'=>2,'kind'=>'secondary','grade'=>null],
    ['employee_id'=>12,'technology_id'=>2,'kind'=>'secondary','grade'=>null],
]);
$upgrade = require __DIR__.'/../database/migrations/2026_11_06_000004_separate_additional_technologies.php';
$upgrade->up();
$service = new SpecialistProfessionalService();
$check($db->table('specialist_technologies')->count() === 1,
    'Migrated secondary assignments must leave the primary relation');
$check($db->table('additional_technologies')->count() === 1,
    'Historical secondary catalog must not duplicate shared PHP name');
$check($db->table('specialist_additional_technologies')->count() === 2,
    'All historical secondary assignments, including another employee, must survive');
// Both associations are a required snapshot, not required-to-be-nonempty.
$rules = \\App\\Http\\Controllers\\SpecialistProfessionalController::profileUpdateRules();
$validSnapshots = [
    ['primary'=>[], 'secondary_ids'=>[]],
    ['primary'=>[['technology_id'=>1,'grade'=>'Junior']], 'secondary_ids'=>[]],
    ['primary'=>[], 'secondary_ids'=>[1]],
];
foreach ($validSnapshots as $snapshot) {
    $check(\\Illuminate\\Support\\Facades\\Validator::make($snapshot, $rules)->passes(),
        'Empty primary or additional array must pass the HTTP validation contract');
}
$invalidSnapshots = [
    ['primary'=>[]],
    ['secondary_ids'=>[]],
    ['primary'=>null, 'secondary_ids'=>[]],
    ['primary'=>[], 'secondary_ids'=>null],
    ['primary'=>'x', 'secondary_ids'=>[]],
    ['primary'=>[], 'secondary_ids'=>['not-an-id']],
    ['primary'=>[['technology_id'=>1]], 'secondary_ids'=>[]],
    ['primary'=>[['technology_id'=>1,'grade'=>'Unknown']], 'secondary_ids'=>[]],
];
foreach ($invalidSnapshots as $snapshot) {
    $check(\\Illuminate\\Support\\Facades\\Validator::make($snapshot, $rules)->fails(),
        'Missing, malformed or ungraded technologies must still be rejected');
}
$check($service->technologies(11)[0]['kind'] === 'primary',
    'Only primary technologies should come from main catalog');
$check($service->additionalTechnologies(11)[0]['name'] === 'PHP',
    'Historical additional name lost after upgrade');
$check($service->technologies(12) === [], 'Another employee acquired main technologies');
$check(count($service->additionalTechnologies(12)) === 1, 'Other employee extras disappeared');

$phpId = (int) $service->additionalTechnologies(11)[0]['id'];
$csharp = AdditionalTechnology::query()->create(['name'=>'C#','active'=>true]);
$vueExtra = AdditionalTechnology::query()->create(['name'=>'VUE','active'=>true]);
$hiddenExtra = AdditionalTechnology::query()->create(['name'=>'Hidden extra','active'=>false]);
$payload = ['primary'=>[['technology_id'=>1,'grade'=>'Senior']],
    'secondary_ids'=>[$phpId,(int) $csharp->id]];
$after = $service->replace(11, $payload, 21, 'Synthetic Manager');
$check($after['grade'] === 'Middle', 'Removed overall grade control must not change legacy grade');
$check(count($after['technologies']) === 1 && $after['technologies'][0]['grade'] === 'Senior',
    'Primary grade was not saved');
$check(count($after['additional_technologies']) === 2,
    'Additional technology associations were not saved independently');
$check($db->table('specialist_technologies')->where('employee_id',11)->count() === 1,
    'Additional technologies leaked back into the main relation');
$check($db->table('specialist_additional_technologies')->where('employee_id',11)->count() === 2,
    'Additional technologies missing from dedicated relation');
$check(count($service->timeline(11)) === 1, 'A successful edit must add one timeline event');
$service->replace(11, $payload, 21, 'Synthetic Manager');
$check(count($service->timeline(11)) === 1, 'No-op save duplicated history');

foreach ([
    ['primary'=>[['technology_id'=>1,'grade'=>'Senior'],
                 ['technology_id'=>1,'grade'=>'Junior']],'secondary_ids'=>[]],
    ['primary'=>[['technology_id'=>3,'grade'=>'Junior']],'secondary_ids'=>[]],
    ['primary'=>[['technology_id'=>1,'grade'=>'Root']],'secondary_ids'=>[]],
    ['primary'=>[['technology_id'=>1,'grade'=>'Junior']],'secondary_ids'=>[$vueExtra->id]],
    ['primary'=>[],'secondary_ids'=>[$hiddenExtra->id]],
    ['primary'=>[],'secondary_ids'=>[$phpId,$phpId]],
] as $invalid) {
    try {
        $service->replace(11, $invalid, 21, 'Synthetic Manager');
        throw new RuntimeException('Invalid technology/grade mutation was permitted');
    } catch (ValidationException) {
        // Expected invalid duplicates, grade, inactive catalog item or cross-kind name.
    }
}
$check(count($service->technologies(11)) === 1, 'Invalid edit mutated main technology');
$check(count($service->additionalTechnologies(11)) === 2, 'Invalid edit mutated extras');
$service->comment(11, 'Synthetic note', 21, 'Synthetic Manager');
$events = $service->timeline(11);
$check(count($events) === 2 && $events[1]['type'] === 'comment'
    && $events[1]['author'] === 'Synthetic Manager', 'History comment was not attributed');

// Removing the last additional skill, then the last main skill, must persist.
$service->replace(11, ['primary'=>[['technology_id'=>1,'grade'=>'Senior']], 'secondary_ids'=>[]],
    21, 'Synthetic Manager');
$check(count($service->additionalTechnologies(11)) === 0,
    'Removing the final additional technology did not clear the association');
$check(count($service->technologies(11)) === 1,
    'Removing extras must preserve main technologies');
$service->replace(11, ['primary'=>[], 'secondary_ids'=>[]], 21, 'Synthetic Manager');
$check(count($service->technologies(11)) === 0
    && count($service->additionalTechnologies(11)) === 0,
    'Empty full snapshot must clear both lists');
$service->replace(11, ['primary'=>[], 'secondary_ids'=>[$phpId]], 21, 'Synthetic Manager');
$check(count($service->technologies(11)) === 0
    && count($service->additionalTechnologies(11)) === 1,
    'An additional-only profile must be supported');
$service->replace(11, $payload, 21, 'Synthetic Manager');
$check(count($service->technologies(11)) === 1
    && count($service->additionalTechnologies(11)) === 2,
    'Restoring valid assignments after clear must succeed');

// Rollback restores all additions and original associations without deleting
// a main assignment, including the later created C# additional skill.
$upgrade->down();
$check(!$db->getSchemaBuilder()->hasTable('additional_technologies'), 'Rollback left duplicate catalog');
$check($db->table('specialist_technologies')->where('employee_id',11)->count() === 3,
    'Rollback lost either a main assignment or an additional technology');
$check($db->table('specialist_technologies')->where('employee_id',12)->count() === 1,
    'Rollback lost another employee secondary link');
echo "Specialists separate main/additional catalogs, historical migration, rollback and audited editor: PASS\n";
