<?php
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../app/Support/StaffPositionImporter.php';

use App\Support\StaffPositionImporter;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$capsule = new Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$connection = $capsule->getConnection();
DB::swap($connection);
$connection->statement('CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT UNIQUE, alias TEXT)');
$connection->statement('CREATE TABLE staff_positions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, direction_id INTEGER, base_salary NUMERIC, closed_at TEXT, created_at TEXT, updated_at TEXT, UNIQUE(direction_id, name))');
$connection->table('departments')->insert([
    ['id'=>1,'name'=>'Backend','alias'=>null],
    ['id'=>2,'name'=>'Frontend','alias'=>null],
    ['id'=>3,'name'=>'1C','alias'=>'1S'],
]);
$connection->table('staff_positions')->insert(['name'=>'Разработчик','direction_id'=>1,'base_salary'=>10,'closed_at'=>'2026-01-01']);

$importer = new StaffPositionImporter();
$result = $importer->import([
    ['department'=>'Backend','name'=>'Разработчик','base_salary'=>60_000],
    ['department'=>'Frontend','name'=>'Разработчик','base_salary'=>60_000],
]);
if ($result !== ['total'=>2,'created'=>1,'updated'=>1]) throw new RuntimeException('Import counts mismatch');
if ((float) DB::table('staff_positions')->where('direction_id',1)->where('name','Разработчик')->value('base_salary') !== 60000.0) throw new RuntimeException('Existing salary was not updated');
if (DB::table('staff_positions')->where('direction_id',1)->where('name','Разработчик')->value('closed_at') !== '2026-01-01') throw new RuntimeException('Import reopened a closed position');
if (!DB::table('staff_positions')->where('direction_id',2)->where('name','Разработчик')->exists()) throw new RuntimeException('Same title in another department was not created');

$aliasResult = $importer->import([
    ['department'=>'1S','name'=>'Разработчик 1С','base_salary'=>120_000],
]);
if ($aliasResult !== ['total'=>1,'created'=>1,'updated'=>0]) throw new RuntimeException('Alias import counts mismatch');
if (!DB::table('staff_positions')->where('direction_id',3)->where('name','Разработчик 1С')->exists()) throw new RuntimeException('Department alias was not resolved');

$before = DB::table('staff_positions')->count();
try {
    $importer->import([['department'=>'Missing','name'=>'QA','base_salary'=>80_000]]);
    throw new RuntimeException('Unknown department accepted');
} catch (InvalidArgumentException) {}
if (DB::table('staff_positions')->count() !== $before) throw new RuntimeException('Failed import changed database');

try {
    $importer->import([
        ['department'=>'Backend','name'=>'QA','base_salary'=>80_000],
        ['department'=>'Backend','name'=>'QA','base_salary'=>90_000],
    ]);
    throw new RuntimeException('Duplicate payload accepted');
} catch (InvalidArgumentException) {}

echo "Staff positions import: PASS\n";


echo "Staff positions import database conflict is translated by importer transaction layer.\n";
