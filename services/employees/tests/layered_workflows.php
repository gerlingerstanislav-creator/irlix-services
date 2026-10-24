<?php

/** Synthetic SQLite workflows for the new Employees Eloquent service boundaries. */
require __DIR__.'/../vendor/autoload.php';

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmploymentAssignmentHistory;
use App\Models\EmploymentPeriod;
use App\Models\SalaryHistory;
use App\Services\EmployeeReadService;
use App\Services\EmployeeHistoryService;
use App\Services\DepartmentService;
use App\Services\SalaryReviewService;
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

$schema = [
    'departments' => 'id INTEGER PRIMARY KEY, name TEXT, alias TEXT, parent_id INTEGER, manager_id INTEGER, hr_id INTEGER, is_production INTEGER, created_at TEXT, updated_at TEXT',
    'employees' => 'id INTEGER PRIMARY KEY, full_name TEXT, login TEXT, keycloak_user_id TEXT, position TEXT, position_id INTEGER, department_id INTEGER, employment_status TEXT, created_at TEXT, updated_at TEXT',
    'staff_positions' => 'id INTEGER PRIMARY KEY, name TEXT, direction_id INTEGER, base_salary NUMERIC, closed_at TEXT, created_at TEXT, updated_at TEXT',
    'employment_periods' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, cooperation_type TEXT, started_at TEXT, ended_at TEXT, department_id INTEGER, position TEXT, position_id INTEGER, created_at TEXT, updated_at TEXT',
    'employee_status_history' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, status TEXT, effective_from TEXT, effective_to TEXT, reason TEXT, created_at TEXT, updated_at TEXT',
    'employment_assignment_history' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, department_id INTEGER, position TEXT, position_id INTEGER, effective_from TEXT, effective_to TEXT, created_at TEXT, updated_at TEXT',
    'salary_history' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, effective_from TEXT, effective_to TEXT, gross_salary NUMERIC, status TEXT, bonus NUMERIC, comment TEXT, created_at TEXT, updated_at TEXT',
    'salary_reviews' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, created_by INTEGER, effective_month TEXT, gross_salary NUMERIC, comment TEXT, status TEXT, cycle INTEGER, created_at TEXT, updated_at TEXT',
    'salary_review_approvals' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, review_id INTEGER, cycle INTEGER, step INTEGER, approver_id INTEGER, decision TEXT, comment TEXT, acted_at TEXT, created_at TEXT, updated_at TEXT',
    'salary_review_versions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, review_id INTEGER, effective_month TEXT, gross_salary NUMERIC, comment TEXT, status TEXT, changed_by INTEGER, created_at TEXT',
];
foreach ($schema as $table => $columns) $db->statement("CREATE TABLE {$table} ({$columns})");

$assert = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException($why);
};
Department::query()->create(['id' => 1, 'name' => 'Root', 'alias' => 'root', 'is_production' => 1, 'manager_id' => 1]);
Department::query()->create(['id' => 2, 'name' => 'Engineering', 'alias' => 'engineering', 'parent_id' => 1, 'is_production' => 1]);
Employee::query()->create([
    'id' => 1, 'full_name' => 'Synthetic Lead', 'login' => 'lead.test',
    'department_id' => 1, 'employment_status' => 'Трудоустроен',
]);
Employee::query()->create([
    'id' => 2, 'full_name' => 'Synthetic Engineer', 'login' => 'engineer.test',
    'department_id' => 2, 'employment_status' => 'Трудоустроен',
]);

$departments = new DepartmentService();
$assert($departments->wouldCycle(1, 2), 'Department cycle accepted');
$assert(!$departments->wouldCycle(2, 1), 'Valid department parent rejected');
$denied = $departments->deleteEmpty(2);
$assert($denied['status'] === 409 && $denied['meta']['employee_count'] === 1,
    'Nonempty department was deleted');

$history = new EmployeeHistoryService();
$history->openStatus(2, 'Трудоустроен', '2026-01-01', 'Synthetic fixture');
$history->closeStatus(2, '2026-02-28');
$history->openStatus(2, 'Уволен', '2026-03-01');
$assert(EmployeeStatusHistory::query()->where('employee_id', 2)->count() === 2,
    'Status history records disappeared');
$history->openAssignment(2, 2, 'Engineer', '2026-01-01');
$history->closeAssignment(2, '2026-03-01');
$assert(EmploymentAssignmentHistory::query()->where('employee_id', 2)->first()?->effective_to === '2026-03-01',
    'Historical assignment not closed');
EmploymentPeriod::query()->create([
    'employee_id' => 2, 'cooperation_type' => 'Штат',
    'started_at' => '2026-01-01', 'department_id' => 2,
]);
SalaryHistory::query()->create([
    'employee_id' => 2, 'effective_from' => '2026-01-01',
    'gross_salary' => 100000, 'status' => 'Действует',
]);

$read = new EmployeeReadService();
$assert($read->list([])['meta']['count'] === 2, 'Eloquent employee listing failed');
$details = $read->details(2);
$assert(count($details['employment_periods']) === 1
    && count($details['salary_history']) === 1
    && count($details['status_history']) === 2
    && count($details['assignment_history']) === 1,
    'Employee history API projection changed');
$assert($read->details(999) === null, 'Unknown employee details were invented');

$reviews = new SalaryReviewService();
$access = ['scope' => 'subtree', 'department_ids' => [2]];
$assert($reviews->scope($access, 2), 'Valid direction scope rejected');
$assert(!$reviews->scope($access, 1), 'Foreign direction payroll leaked');
$created = $reviews->create([
    'employee_id' => 2, 'effective_month' => '2026-04-01',
    'gross_salary' => 115000, 'comment' => 'Synthetic',
], 1);
$assert($created->status === 'draft', 'New salary review is not a draft');
$assert($reviews->conflicts(2, '2026-04-01'), 'Duplicate salary review allowed');
$updated = $reviews->edit($reviews->review((int) $created->id),
    ['gross_salary' => 120000], 1);
$assert($updated->status === 'draft' && (float) $updated->gross_salary === 120000.0,
    'Draft salary revision lost');
$assert($db->table('salary_review_versions')->count() === 1,
    'Salary review history version was not recorded');
$submitted = $reviews->submit($reviews->review((int) $created->id), [1]);
$assert($submitted->status === 'pending' && (int) $submitted->cycle === 1,
    'Review submission state invalid');
$wrong = $reviews->decide((int) $created->id, 2, ['decision' => 'approved']);
$assert($wrong['status'] === 403, 'Unauthorized approver gained access');
$good = $reviews->decide((int) $created->id, 1, ['decision' => 'approved']);
$assert($good['status'] === 200 && $good['data']->status === 'approved',
    'Approved review workflow failed');
$assert($reviews->inbox(1)->isEmpty(), 'Completed review remained in pending inbox');

echo "Employees layered Eloquent workflows: PASS\n";
