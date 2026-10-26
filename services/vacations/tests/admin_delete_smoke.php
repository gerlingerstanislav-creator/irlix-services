<?php

// Isolated synthetic data: verify permanent deletion and exact-role eligibility.
require __DIR__.'/../vendor/autoload.php';

use App\Application\AbsenceDeletionService;
use App\Support\EmployeesDirectory;
use App\Support\VacationsAccess;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

$storage = sys_get_temp_dir().'/irlix-vacations-delete-smoke-'.getmypid();
$documents = $storage.'/app/vacations/attachments';
if (!is_dir($documents) && !mkdir($documents, 0700, true)) {
    throw new RuntimeException('Failed to create synthetic attachment directory');
}
$app = new Application(dirname(__DIR__));
$app->useStoragePath($storage);
Facade::setFacadeApplication($app);
$app->instance('date', new DateFactory());
$capsule = new Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$app->instance('db', $capsule->getDatabaseManager());
Model::setConnectionResolver($capsule->getDatabaseManager());

$db = $capsule->getConnection();
$db->statement('CREATE TABLE absences (id INTEGER PRIMARY KEY, employee_id INTEGER, status TEXT, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE absence_approvals (id INTEGER PRIMARY KEY, absence_id INTEGER)');
$db->statement('CREATE TABLE absence_status_history (id INTEGER PRIMARY KEY, absence_id INTEGER)');
$db->statement('CREATE TABLE absence_audit_log (id INTEGER PRIMARY KEY, absence_id INTEGER)');
$db->statement('CREATE TABLE absence_attachments (id INTEGER PRIMARY KEY, absence_id INTEGER, storage_path TEXT)');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$access = new VacationsAccess(new EmployeesDirectory());
$assert($access->isAdmin(['roles' => ['platform-admin']]), 'Exact platform admin must have access');
foreach (['personnel-officer', 'hr', 'manager', 'department-head', 'platform-administrator'] as $role) {
    $assert(!$access->isAdmin(['roles' => [$role]]), "Role {$role} must not delete absence");
}

$db->table('absences')->insert([
    ['id' => 10, 'employee_id' => 7, 'status' => 'confirmed'],
    ['id' => 11, 'employee_id' => 9, 'status' => 'planned'],
]);
foreach (['absence_approvals', 'absence_status_history', 'absence_audit_log', 'absence_attachments'] as $table) {
    $db->table($table)->insert(['id' => 1, 'absence_id' => 10] + ($table === 'absence_attachments'
        ? ['storage_path' => 'attachments/synthetic-document.pdf'] : []));
    $db->table($table)->insert(['id' => 2, 'absence_id' => 11] + ($table === 'absence_attachments'
        ? ['storage_path' => 'attachments/surviving-document.pdf'] : []));
}
$db->table('absence_attachments')->insert([
    'id' => 3, 'absence_id' => 10, 'storage_path' => 'imported-link',
]);
file_put_contents($documents.'/synthetic-document.pdf', 'synthetic test document');
file_put_contents($documents.'/surviving-document.pdf', 'untouched test document');

try {
    (new AbsenceDeletionService())->delete(10);
    $assert($db->table('absences')->where('id', 10)->count() === 0, 'Confirmed absence still exists');
    $assert($db->table('absences')->where('id', 11)->count() === 1, 'Unrelated absence was removed');
    foreach (['absence_approvals', 'absence_status_history', 'absence_audit_log', 'absence_attachments'] as $table) {
        $assert($db->table($table)->where('absence_id', 10)->count() === 0, "{$table} was not cleared");
        $assert($db->table($table)->where('absence_id', 11)->count() === 1, "Unrelated {$table} was changed");
    }
    $assert(!is_file($documents.'/synthetic-document.pdf'), 'Owned attachment file survived deletion');
    $assert(is_file($documents.'/surviving-document.pdf'), 'Unrelated attachment file was removed');

    $notFound = false;
    try {
        (new AbsenceDeletionService())->delete(10);
    } catch (DomainException) {
        $notFound = true;
    }
    $assert($notFound, 'Repeated delete must report not-found');
} finally {
    @unlink($documents.'/synthetic-document.pdf');
    @unlink($documents.'/surviving-document.pdf');
    @rmdir($documents);
    @rmdir(dirname($documents));
    @rmdir(dirname(dirname($documents)));
    @rmdir(dirname(dirname(dirname($documents))));
    @rmdir($storage);
}

echo "Vacations admin deletion and exact-role smoke: PASS\n";
