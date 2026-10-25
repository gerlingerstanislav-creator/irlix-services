<?php

// Synthetic SQLite integration smoke. No external APIs, secrets or user data.
require __DIR__.'/../vendor/autoload.php';

use App\Application\AbsenceReadService;
use App\Application\ApprovalQueryService;
use App\Application\AbsenceService;
use App\Domain\Absence\AbsenceDayCalculator;
use App\Domain\Absence\AbsenceWorkflow;
use App\Models\Absence;
use App\Models\AbsenceApproval;
use App\Models\AbsenceAttachment;
use App\Models\AbsenceStatusHistory;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

$container = new Container();
Container::setInstance($container);
Facade::setFacadeApplication($container);
$container->instance('date', new DateFactory());
$capsule = new Manager($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$container->instance('db', $capsule->getDatabaseManager());
Model::setConnectionResolver($capsule->getDatabaseManager());

$db = $capsule->getConnection();
$db->statement('CREATE TABLE absences (
    id INTEGER PRIMARY KEY, employee_id INTEGER, type TEXT, status TEXT,
    starts_on TEXT, ends_on TEXT, calendar_days INTEGER, entitlement_days INTEGER,
    comment TEXT, created_by_subject TEXT, created_at TEXT, updated_at TEXT
)');
$db->statement('CREATE TABLE absence_approvals (
    id INTEGER PRIMARY KEY, absence_id INTEGER, sequence INTEGER, stage TEXT,
    status TEXT, required_role TEXT, approver_employee_id INTEGER,
    acted_by_subject TEXT, acted_by_employee_id INTEGER, created_at TEXT, updated_at TEXT
)');
$db->statement('CREATE TABLE absence_attachments (
    id INTEGER PRIMARY KEY, absence_id INTEGER, kind TEXT, original_name TEXT,
    mime_type TEXT, size_bytes INTEGER, uploaded_by_employee_id INTEGER, created_at TEXT, updated_at TEXT
)');
$db->statement('CREATE TABLE absence_status_history (
    id INTEGER PRIMARY KEY, absence_id INTEGER, from_status TEXT, to_status TEXT,
    actor_subject TEXT, actor_employee_id INTEGER, reason TEXT, created_at TEXT
)');
$db->statement('CREATE TABLE absence_audit_log (
    id INTEGER PRIMARY KEY, absence_id INTEGER, event TEXT, actor_subject TEXT,
    actor_employee_id INTEGER, before TEXT, after TEXT, created_at TEXT
)');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

Absence::query()->insert([
    'id' => 10, 'employee_id' => 7, 'type' => 'paid_vacation',
    'status' => 'planned', 'starts_on' => '2026-04-10', 'ends_on' => '2026-04-20',
    'created_at' => '2026-04-01 08:00:00', 'updated_at' => '2026-04-01 08:00:00',
]);
Absence::query()->insert([
    'id' => 11, 'employee_id' => 9, 'type' => 'sick_leave',
    'status' => 'confirmed', 'starts_on' => '2026-04-12', 'ends_on' => null,
]);
AbsenceApproval::query()->insert([
    'id' => 30, 'absence_id' => 10, 'sequence' => 1, 'stage' => 'hr_review',
    'status' => 'pending', 'required_role' => 'hr',
    'approver_employee_id' => 90, 'created_at' => '2026-04-01 08:00:00',
]);
AbsenceAttachment::query()->insert([
    'id' => 50, 'absence_id' => 10, 'kind' => 'application',
    'original_name' => 'synthetic.pdf', 'mime_type' => 'application/pdf',
    'size_bytes' => 1, 'uploaded_by_employee_id' => 7,
    'created_at' => '2026-04-01 08:00:00',
]);
AbsenceStatusHistory::query()->insert([
    'id' => 60, 'absence_id' => 10, 'to_status' => 'planned', 'actor_subject' => 'synthetic',
    'reason' => 'created', 'created_at' => '2026-04-01 08:00:00',
]);

$absenceService = new AbsenceService(new AbsenceDayCalculator(), new AbsenceWorkflow());
$read = new AbsenceReadService();
$approval = new ApprovalQueryService();

$own = $absenceService->getOwn(10, 7);
$assert($own['id'] === 10 && $own['created_at'] === '2026-04-01 08:00:00',
    'Eloquent changed the raw absence API contract');
$blocked = false;
try { $absenceService->getOwn(11, 7); } catch (DomainException) { $blocked = true; }
$assert($blocked, 'Absence lookup bypassed ownership');
$assert(count($read->registry([7], [])) === 1, 'Scoped registry leaked a second employee');
$assert(count($read->occupied(7, ['cancelled', 'rejected'])) === 1, 'Occupied calendar lookup failed');
$assert(count($read->statusHistory(10)) === 1, 'Status history lookup failed');
$assert($read->attachmentCounts([10])[10] === 1, 'Attachment aggregate changed');
$assert($read->hasAttachment(10) && !$read->hasAttachment(11), 'Attachment existence changed');
$assert($read->isAssignedApprover(10, 90), 'Pending approver not recognized');
$assert(!$read->isAssignedApprover(10, 91), 'Wrong approver gained access');
$assert($approval->pending(30)?->id === 30, 'Current approval not found');
$assert($approval->stagePendingIds($approval->pending(30)) === [30], 'Active sequence not resolved');
$assert(count($approval->pendingQueue(90, false, false, false)) === 1, 'Approval queue missed assignee');
$assert(count($approval->pendingQueue(91, false, false, false)) === 0, 'Approval queue leaked to stranger');

$history = $read->auditHistory([7], []);
$assert(count($history) === 0, 'Unexpected audit events in synthetic fixture');
$absenceService->recordCreationActor(10, 42);
$assert((int) AbsenceStatusHistory::query()->find(60)->actor_employee_id === 42,
    'Real creator identity was not attributed');

echo "Vacations Eloquent query contracts and scope: PASS\n";
