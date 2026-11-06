<?php

/** Synthetic SQLite transaction rehearsal; no real employee data or external services. */
require __DIR__.'/../vendor/autoload.php';

use App\Models\AdditionalTechnology;
use App\Models\SpecialistAdditionalTechnology;
use App\Models\SpecialistTimelineEvent;
use App\Services\AdditionalTechnologyCatalogService;
use App\Services\SpecialistProfessionalService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Validation\ValidationException;

// The isolated Eloquent harness has no Foundation app; implement only its
// abort() boundary so the domain service can raise standard HTTP exceptions.
$app = new class extends Container {
    public function abort(int $code, string $message = '', array $headers = []): never
    {
        throw new \Symfony\Component\HttpKernel\Exception\HttpException($code, $message, null, $headers);
    }
};
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('date', new Illuminate\Support\DateFactory());
$app->instance('validator', new Illuminate\Validation\Factory(
    new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'ru'), $app));
$capsule = new Manager($app);
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$app->instance('db', $capsule->getDatabaseManager());
DB::swap($capsule->getConnection());
Model::setConnectionResolver($capsule->getDatabaseManager());
$db = $capsule->getConnection();
Schema::swap($db->getSchemaBuilder());
$check = static function (bool $condition, string $description): void {
    if (!$condition) throw new RuntimeException($description);
};
$reject = static function (callable $action, int $status) use ($check): void {
    try {
        $action();
    } catch (HttpExceptionInterface $e) {
        $check($e->getStatusCode() === $status, 'Wrong HTTP status for rejected catalog mutation');
        return;
    }
    throw new RuntimeException('A rejected catalog mutation unexpectedly succeeded');
};

$db->statement('CREATE TABLE additional_technologies (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, origin_technology_id INTEGER, active INTEGER, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE specialist_additional_technologies (employee_id INTEGER, additional_technology_id INTEGER, created_at TEXT, updated_at TEXT, PRIMARY KEY(employee_id,additional_technology_id))');
$db->statement('CREATE TABLE specialist_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER UNIQUE, grade TEXT, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE specialist_timeline_events (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, type TEXT, title TEXT, text TEXT, author_employee_id INTEGER, author TEXT, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE technologies (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, active INTEGER, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE specialist_technologies (employee_id INTEGER, technology_id INTEGER, kind TEXT, grade TEXT, created_at TEXT, updated_at TEXT, PRIMARY KEY(employee_id,technology_id))');

foreach ([
    [1,'Deprecated',1], [2,'Replacement',1], [3,'Core',1],
    [4,'Archived',0], [5,'Unused',1], [6,'Rollback source',1],
] as [$id,$name,$active]) {
    AdditionalTechnology::query()->create(['id'=>$id,'name'=>$name,'active'=>$active]);
}
$db->table('technologies')->insert(['id'=>41,'name'=>'Core','active'=>1]);
foreach ([11,12,13,14] as $id) $db->table('specialist_profiles')->insert(['employee_id'=>$id]);
$db->table('specialist_technologies')->insert([
    ['employee_id'=>12,'technology_id'=>41,'kind'=>'primary','grade'=>'Middle'],
]);
foreach ([
    [11,1],[11,2],[12,1],[13,1],[14,2],[15,1],[17,6],
] as [$employee,$technology]) {
    SpecialistAdditionalTechnology::query()->create([
        'employee_id'=>$employee,'additional_technology_id'=>$technology,
    ]);
}
$service = new AdditionalTechnologyCatalogService();
$count = static fn (int $id): int => SpecialistAdditionalTechnology::query()
    ->where('additional_technology_id',$id)->count();

$reject(static fn () => $service->removeOrReplace(1,1,100,'Admin'), 422);
$reject(static fn () => $service->removeOrReplace(1,4,100,'Admin'), 422);
$reject(static fn () => $service->removeOrReplace(1,999,100,'Admin'), 422);
$reject(static fn () => $service->removeOrReplace(999,null,100,'Admin'), 404);
$reject(static fn () => $service->removeOrReplace(1,3,100,'Admin'), 409);
$check($count(1) === 4 && $count(3) === 0, 'Conflict must not partially alter any specialist');
$check(AdditionalTechnology::query()->findOrFail(1)->active, 'Conflict must not archive source');

// A rename keeps the catalog identity and every employee association intact.
$reject(static fn () => $service->rename(999,'Renamed',100,'Admin'),404);
$reject(static fn () => $service->rename(4,'Renamed',100,'Admin'),404);
$reject(static fn () => $service->rename(1,' ',100,'Admin'),422);
$reject(static fn () => $service->rename(1,'Replacement',100,'Admin'),422);
$reject(static fn () => $service->rename(1,'  replacement  ',100,'Admin'),422);
$reject(static fn () => $service->rename(1,'Core',100,'Admin'),409);
$check($service->rename(1,'Deprecated',100,'Admin')['changed'] === false,
    'Same-name rename should be a no-op');
$beforeCount = $count(1);
$rename = $service->rename(1,'Updated Demo',100,'Synthetic Catalog Admin');
$check($rename === ['id'=>1,'name'=>'Updated Demo','affected_specialists'=>4,'changed'=>true],
    'Renaming must report updated name and every affected specialist');
$check(AdditionalTechnology::query()->findOrFail(1)->name === 'Updated Demo'
    && $count(1) === $beforeCount && AdditionalTechnology::query()->findOrFail(1)->active,
    'Rename must not change the catalog ID, active state, or any assignments');
$check(SpecialistTimelineEvent::query()->whereIn('employee_id',[11,12,13,15])
    ->where('title','Переименование дополнительной технологии')->count() === 4,
    'Every affected specialist must have an audit event for the catalog rename');

$result = $service->removeOrReplace(1,2,100,'Synthetic Catalog Admin');
$check($result === ['deleted'=>true,'id'=>1,'replacement_id'=>2,'affected_specialists'=>4],
    'Atomic replacement response did not identify all affected employees');
$check($count(1) === 0 && $count(2) === 5,
    'Replace must reach all profiles, including formerly selected target and missing profiles');
$check(!AdditionalTechnology::query()->findOrFail(1)->active,
    'Retired source must no longer be active in the global catalog');
$check(SpecialistAdditionalTechnology::query()->where('employee_id',11)
    ->where('additional_technology_id',2)->count() === 1,
    'Already selected replacement was duplicated');
$check($db->table('specialist_technologies')->where('employee_id',12)->where('technology_id',41)->count() === 1,
    'Additional catalog replacement must not touch the main technology catalog');
$check(SpecialistTimelineEvent::query()->whereIn('employee_id',[11,12,13,15])
    ->where('title','Замена дополнительной технологии')->count() === 4,
    'Every affected specialist must receive an auditable history event');
$check(SpecialistTimelineEvent::query()->where('employee_id',15)->count() === 1,
    'Former/out-of-scope specialist must receive the same global catalog operation');
$check(SpecialistTimelineEvent::query()->where('employee_id',11)->first()->author_employee_id === 100,
    'Global change history must retain actor attribution');

$reject(static fn () => $service->removeOrReplace(1,null,100,'Admin'), 404);
try {
    (new SpecialistProfessionalService())->replace(11, ['primary'=>[], 'secondary_ids'=>[1]], 100,'Admin');
    throw new RuntimeException('Archived catalog item was reattached by autosave');
} catch (ValidationException) {
    // A previously selected, deleted ID cannot be reintroduced.
}

$removed = $service->removeOrReplace(2,null,100,'Synthetic Catalog Admin');
$check($removed['affected_specialists'] === 5 && $count(2) === 0,
    'Remove-without-replacement must drop every additional association');
$check(!AdditionalTechnology::query()->findOrFail(2)->active,
    'Remove-without-replacement must also retire the global catalog entry');
$check(SpecialistTimelineEvent::query()->where('title','Удаление дополнительной технологии')->count() === 5,
    'Remove-without-replacement must be auditable for each affected specialist');

$db->statement("CREATE TRIGGER reject_timeline BEFORE INSERT ON specialist_timeline_events WHEN NEW.employee_id = 17 BEGIN SELECT RAISE(ABORT, 'synthetic history failure'); END");
try {
    $service->removeOrReplace(6,5,100,'Admin');
    throw new RuntimeException('Failed history write did not abort global replacement');
} catch (\Illuminate\Database\QueryException) {
    // A failure during audit must roll back BOTH catalog retirement and links.
}
$check(AdditionalTechnology::query()->findOrFail(6)->active
    && $count(6) === 1 && $count(5) === 0,
    'Transaction failure must restore all assignments and source catalog');
try {
    $service->rename(6,'Rollback Renamed',100,'Admin');
    throw new RuntimeException('Failed audit write did not abort rename');
} catch (\Illuminate\Database\QueryException) {
    // Name and all assignments stay unchanged on failed timeline insert.
}
$check(AdditionalTechnology::query()->findOrFail(6)->name === 'Rollback source'
    && $count(6) === 1,
    'A failed timeline audit must roll back a rename without losing associations');
$db->statement('DROP TRIGGER reject_timeline');
$check($service->removeOrReplace(6,null,100,'Admin')['affected_specialists'] === 1,
    'Retirement must be retryable after rolled-back attempt');
$check($count(6) === 0 && !AdditionalTechnology::query()->findOrFail(6)->active,
    'Safe retry did not remove catalog assignment');

echo "Specialists additional catalog: replace/remove, dedup, all scopes, validation, audit and rollback PASS\n";
