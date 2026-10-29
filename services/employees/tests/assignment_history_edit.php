<?php

/** Synthetic in-memory regression for admin corrections of assignment history. */
require __DIR__.'/../vendor/autoload.php';

use App\Models\Employee;
use App\Models\EmploymentAssignmentHistory;
use App\Models\EmploymentPeriod;
use App\Models\SalaryHistory;
use App\Models\StaffPosition;
use App\Services\EmployeeAssignmentHistoryService;
use App\Services\EmployeeIdentityService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('date', new DateFactory());
$capsule = new Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
DB::swap($db);
Model::setConnectionResolver($capsule->getDatabaseManager());

foreach ([
    'employees' => 'id INTEGER PRIMARY KEY, full_name TEXT, department_id INTEGER, position TEXT, position_id INTEGER, employment_status TEXT, keycloak_user_id TEXT, identity_status TEXT, created_at TEXT, updated_at TEXT',
    'staff_positions' => 'id INTEGER PRIMARY KEY, name TEXT, direction_id INTEGER, closed_at TEXT, created_at TEXT, updated_at TEXT',
    'employment_periods' => 'id INTEGER PRIMARY KEY, employee_id INTEGER, cooperation_type TEXT, started_at TEXT, ended_at TEXT, created_at TEXT, updated_at TEXT',
    'employment_assignment_history' => 'id INTEGER PRIMARY KEY, employee_id INTEGER, department_id INTEGER, position TEXT, position_id INTEGER, effective_from TEXT, effective_to TEXT, created_at TEXT, updated_at TEXT',
    'salary_history' => 'id INTEGER PRIMARY KEY, employee_id INTEGER, effective_from TEXT, effective_to TEXT, gross_salary NUMERIC, comment TEXT, created_at TEXT, updated_at TEXT',
] as $name => $columns) {
    $db->statement("CREATE TABLE {$name} ({$columns})");
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$denies = static function (callable $operation, string $message): void {
    try { $operation(); } catch (DomainException $error) { return; }
    throw new RuntimeException($message);
};

Employee::query()->create(['id' => 1, 'full_name' => 'Fixture Employee',
    'department_id' => 2, 'position_id' => 2, 'position' => 'Analyst',
    'employment_status' => 'Трудоустроен']);
StaffPosition::query()->create(['id' => 1, 'name' => 'Engineer', 'direction_id' => 1]);
StaffPosition::query()->create(['id' => 2, 'name' => 'Analyst', 'direction_id' => 2]);
EmploymentPeriod::query()->create(['id' => 1, 'employee_id' => 1, 'cooperation_type' => 'Штат', 'started_at' => '2026-01-01']);
EmploymentAssignmentHistory::query()->create(['id' => 10, 'employee_id' => 1,
    'department_id' => 1, 'position_id' => 1, 'position' => 'Engineer',
    'effective_from' => '2026-01-01', 'effective_to' => '2026-02-28']);
EmploymentAssignmentHistory::query()->create(['id' => 11, 'employee_id' => 1,
    'department_id' => 2, 'position_id' => 2, 'position' => 'Analyst',
    'effective_from' => '2026-03-01']);
SalaryHistory::query()->create(['id' => 1, 'employee_id' => 1,
    'effective_from' => '2026-01-01', 'gross_salary' => 100000]);

$service = new EmployeeAssignmentHistoryService(new EmployeeIdentityService());
$person = Employee::query()->findOrFail(1);

$save = static fn (array $data) => $service->update($person, 11, $data);
$updated = $save([
    'department_id' => 1, 'position_id' => 1,
    'effective_from' => '2026-03-05', 'effective_to' => null,
]);
$assert($updated->effective_from === '2026-03-05', 'Assignment start not corrected');
$assert(EmploymentAssignmentHistory::query()->find(10)->effective_to === '2026-03-04',
    'Continuous previous assignment was not adjusted');
$assert(Employee::query()->find(1)->department_id === 1
    && Employee::query()->find(1)->position_id === 1,
    'Active employee projection did not follow the correction');
$assert(SalaryHistory::query()->find(1)->gross_salary == 100000,
    'Salary was changed by the assignment correction');

$denies(static fn () => $save([
    'department_id' => 2, 'position_id' => 2,
    'effective_from' => '2026-01-01', 'effective_to' => null,
]), 'Overlapping correction was accepted');
$assert(EmploymentAssignmentHistory::query()->find(10)->effective_to === '2026-03-04',
    'Invalid correction changed previous history');

$denies(static fn () => $save([
    'department_id' => 1, 'position_id' => 2,
    'effective_from' => '2026-03-05', 'effective_to' => null,
]), 'Foreign-department position was accepted');
$denies(static fn () => $save([
    'department_id' => 1, 'position_id' => 1,
    'effective_from' => '2026-03-05', 'effective_to' => '2026-03-12',
]), 'Active assignment was allowed to become closed');

StaffPosition::query()->find(2)->update(['closed_at' => '2026-03-10']);
$denies(static fn () => $save([
    'department_id' => 2, 'position_id' => 2,
    'effective_from' => '2026-03-05', 'effective_to' => null,
]), 'Closed position was newly assigned');
SalaryHistory::query()->create(['id' => 2, 'employee_id' => 1,
    'effective_from' => '2026-03-05', 'gross_salary' => 120000,
    'comment' => 'Изменение оклада при кадровом переводе']);
$denies(static fn () => $save([
    'department_id' => 1, 'position_id' => 1,
    'effective_from' => '2026-03-06', 'effective_to' => null,
]), 'Salary-linked transfer date was moved silently');
$assert(SalaryHistory::query()->find(2)->effective_from === '2026-03-05',
    'Salary history was overwritten');

echo "PASS assignment_history_edit\n";
