<?php

// Isolated synthetic records: hard delete must erase only the chosen employee
// while preserving unrelated clients and coworkers during paid-period erasure.
require __DIR__.'/client_members_bootstrap.php';

use App\Services\EmployeePurgeService;
use Illuminate\Support\Facades\DB;

$schema = DB::connection()->getSchemaBuilder();
$columns = [
    'clients' => ['account_employee_id', 'sales_employee_id'],
    'projects' => ['client_id'],
    'project_members' => ['project_id', 'specialist_id', 'partner_specialist_id'],
    'client_members' => ['client_id', 'employee_id'],
    'member_terms' => ['client_member_id', 'project_member_id', 'superseded_by_term_id', 'valid_from', 'valid_to'],
    'member_term_lineage' => ['canonical_term_id'],
    'client_member_feedbacks' => ['client_member_id', 'created_by_username'],
    'project_assignments' => ['client_member_id'],
    'reporting_periods' => ['client_id', 'status', 'period_start', 'period_end', 'timesheet_snapshot', 'timesheet_revision', 'confirmed_hours', 'confirmed_amount'],
    'reporting_period_approved_hours' => ['reporting_period_id', 'member_term_id', 'updated_by'],
    'connection_attempts' => ['specialist_id', 'responsible_employee_id', 'cv_storage_path'],
    'leads' => ['responsible_employee_id'],
    'positions' => ['responsible_rn_employee_id'],
    'client_requests' => ['responsible_employee_id'],
    'client_contour_permission_audit' => ['actor_employee_id'],
    'lead_events' => ['created_by_employee_id'],
    'connection_attempt_events' => ['actor_employee_id'],
    'client_account_delegations' => ['from_employee_id', 'to_employee_id', 'created_by_employee_id'],
    'project_member_feedbacks' => ['created_by_username'],
    'client_history_events' => ['created_by_username'],
    'client_notes' => ['client_id', 'created_by_username'],
    'reporting_period_history' => ['reporting_period_id', 'author'],
    'employee_purge_files' => ['employee_id', 'filename'],
];
foreach ($columns as $table => $names) {
    $schema->create($table, static function ($t) use ($names, $table): void {
        $t->id();
        foreach ($names as $name) {
            if (in_array($name, ['period_start', 'period_end', 'valid_from', 'valid_to'], true)) $t->date($name)->nullable();
            elseif ($name === 'timesheet_snapshot') $t->text($name)->nullable();
            elseif (in_array($name, ['status','created_by_username','updated_by','author','filename','cv_storage_path'], true)) $t->string($name)->nullable();
            else $t->unsignedBigInteger($name)->nullable();
        }
        if (in_array('client_member_id', $names, true) && !in_array($table, ['client_members'], true)) {
            $t->foreign('client_member_id')->references('id')->on('client_members')->restrictOnDelete();
        }
        if ($table === 'project_members') $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
        if ($table === 'member_terms') $t->foreign('project_member_id')->references('id')->on('project_members')->restrictOnDelete();
        if ($table === 'reporting_period_approved_hours') $t->foreign('member_term_id')->references('id')->on('member_terms')->cascadeOnDelete();
        $t->timestamps();
    });
}
DB::table('clients')->insert([['id'=>10, 'account_employee_id'=>7, 'sales_employee_id'=>7],
    ['id'=>11, 'account_employee_id'=>8, 'sales_employee_id'=>8]]);
DB::table('projects')->insert([['id'=>20, 'client_id'=>10], ['id'=>21, 'client_id'=>11]]);
DB::table('project_members')->insert([['id'=>300,'project_id'=>20,'specialist_id'=>7],
    ['id'=>301,'project_id'=>21,'specialist_id'=>8],['id'=>302,'project_id'=>20,'specialist_id'=>7]]);
DB::table('client_members')->insert([['id'=>100, 'client_id'=>10, 'employee_id'=>7],
    ['id'=>101, 'client_id'=>11, 'employee_id'=>8]]);
DB::table('member_terms')->insert([['id'=>200,'client_member_id'=>100, 'project_member_id'=>300,
        'valid_from'=>'2026-01-01', 'valid_to'=>'2026-01-31'],
    ['id'=>201,'client_member_id'=>101,'project_member_id'=>301,
        'valid_from'=>'2026-01-01', 'valid_to'=>'2026-01-31']]);
    // Archived historical term has no direct ClientMember, but references its canonical term.
DB::table('member_terms')->insert([
    'id'=>203,'client_member_id'=>null,'project_member_id'=>302,'superseded_by_term_id'=>200,
    'valid_from'=>'2026-01-01', 'valid_to'=>'2026-01-31',
]);
DB::table('member_term_lineage')->insert(['canonical_term_id'=>200]);
DB::table('client_member_feedbacks')->insert(['client_member_id'=>100, 'created_by_username'=>'synthetic.employee']);
DB::table('project_assignments')->insert(['client_member_id'=>100]);
$cvDirectory = storage_path('app/clients/cv');
if (!is_dir($cvDirectory) && !mkdir($cvDirectory, 0770, true) && !is_dir($cvDirectory)) {
    throw new RuntimeException('Could not create synthetic CV test directory');
}
$cvFilename = 'employee-purge-synthetic-7.txt';
file_put_contents($cvDirectory.'/'.$cvFilename, 'synthetic CV');
DB::table('connection_attempts')->insert(['specialist_id'=>7, 'responsible_employee_id'=>7,
    'cv_storage_path'=>$cvFilename]);
DB::table('clients')->where('id', 10)->update(['sales_employee_id'=>7]);
DB::table('client_history_events')->insert(['created_by_username'=>'synthetic.employee']);
DB::table('client_notes')->insert(['client_id'=>10,'created_by_username'=>'synthetic.employee']);
DB::table('reporting_period_history')->insert(['author'=>'synthetic.employee']);
DB::table('reporting_period_approved_hours')->insert(['reporting_period_id'=>null,
    'member_term_id'=>201,'updated_by'=>'synthetic.employee']);
DB::table('lead_events')->insert(['created_by_employee_id'=>7]);
DB::table('client_account_delegations')->insert(['from_employee_id'=>7, 'to_employee_id'=>8, 'created_by_employee_id'=>7]);
DB::table('reporting_periods')->insert(['client_id'=>10, 'period_start'=>'2026-01-01',
    'period_end'=>'2026-01-31', 'status'=>'Новый']);

$purge = new EmployeePurgeService();

// A failure late in DB mutation must be detected by preflight and rolled back.
// The previous selection-only preflight passed and let Timesheets erase hours
// before Clients failed on this same operation.
DB::unprepared(<<<'SQL'
CREATE TRIGGER synthetic_purge_late_failure BEFORE UPDATE OF sales_employee_id ON clients
WHEN OLD.sales_employee_id = 7 AND NEW.sales_employee_id IS NULL
BEGIN SELECT RAISE(ABORT, 'synthetic late purge failure'); END
SQL);
$rejectedLateFailure = false;
try {
    $purge->purge(7, 'synthetic.employee', true);
} catch (\Illuminate\Database\QueryException $error) {
    $rejectedLateFailure = str_contains($error->getMessage(), 'synthetic late purge failure');
} finally {
    DB::unprepared('DROP TRIGGER IF EXISTS synthetic_purge_late_failure');
}
clientCheck($rejectedLateFailure, 'Preflight missed a late database mutation failure');
clientCheck(DB::table('connection_attempts')->where('specialist_id', 7)->exists()
    && DB::table('member_terms')->where('id', 200)->exists()
    && DB::table('client_members')->where('id', 100)->exists()
    && DB::table('clients')->where('id', 10)->value('sales_employee_id') === 7
    && !DB::table('employee_purge_files')->exists()
    && file_exists($cvDirectory.'/'.$cvFilename),
    'Failed preflight did not roll back all DB changes and retain CV files');

// Successful preflight must run all mutations yet persist none of them.
$purge->purge(7, 'synthetic.employee', true); // transactional rollback
clientCheck(DB::table('client_members')->where('employee_id',7)->exists()
    && DB::table('connection_attempts')->where('specialist_id',7)->exists()
    && file_exists($cvDirectory.'/'.$cvFilename)
    && !DB::table('employee_purge_files')->exists(),
    'Preflight modified client membership, attempt, CV or file deletion ledger');
$purge->purge(7, 'synthetic.employee');
$purge->purge(7, 'synthetic.employee'); // safe retry
clientCheck(!DB::table('client_members')->where('employee_id',7)->exists(), 'Membership was not erased');
clientCheck(!DB::table('member_terms')->whereIn('id',[200,203])->exists(),
    'Canonical or legacy archived terms were not erased');
clientCheck(!DB::table('project_members')->where('specialist_id',7)->exists(),
    'Legacy project members were not erased');
clientCheck(!file_exists($cvDirectory.'/'.$cvFilename), 'CV file was not erased');
clientCheck(!DB::table('employee_purge_files')->exists(), 'CV purge ledger was not drained');
clientCheck(!DB::table('project_assignments')->where('client_member_id',100)->exists(), 'Assignment was not erased');
clientCheck(!DB::table('connection_attempts')->where('specialist_id',7)->exists(), 'Attempt remained');
clientCheck(DB::table('clients')->count()===2 && DB::table('client_members')->count()===1
    && DB::table('member_terms')->where('id',201)->exists(), 'Other people or clients were erased');
clientCheck(DB::table('clients')->where('id',10)->value('sales_employee_id')===null,
    'Sales reference remains');
clientCheck(DB::table('lead_events')->first()->created_by_employee_id===null,
    'Foreign history actor remains');
clientCheck(DB::table('client_history_events')->first()->created_by_username===null,
    'Foreign authored history was not anonymized');
clientCheck(DB::table('client_notes')->first()->created_by_username===null
    && DB::table('reporting_period_history')->first()->author===null
    && DB::table('reporting_period_approved_hours')->first()->updated_by===null,
    'Foreign audit authors were not anonymized');

// Paid-period rows are exceptional platform-admin deletion targets, not a
// prohibition. The client's period and another specialist's facts survive.
DB::table('client_members')->insert([
    ['id'=>102, 'client_id'=>10, 'employee_id'=>9],
    ['id'=>104, 'client_id'=>10, 'employee_id'=>12],
]);
DB::table('member_terms')->insert([
    ['id'=>202, 'client_member_id'=>102, 'valid_from'=>'2026-02-01', 'valid_to'=>'2026-02-28'],
    ['id'=>206, 'client_member_id'=>104, 'valid_from'=>'2026-02-01', 'valid_to'=>'2026-02-28'],
]);
$paidSnapshot = [
    'entries' => [
        ['id'=>1,'employee_id'=>9,'client_member_id'=>102,'member_term_id'=>202,'work_date'=>'2026-02-10','hours'=>8],
        ['id'=>2,'employee_id'=>12,'client_member_id'=>104,'member_term_id'=>206,'work_date'=>'2026-02-10','hours'=>7],
    ],
    'final_approvals' => [
        ['employee_id'=>9,'client_member_id'=>102,'month'=>'2026-02-01'],
        ['employee_id'=>12,'client_member_id'=>104,'month'=>'2026-02-01'],
    ],
    'final_approval_exceptions' => [
        ['employee_id'=>9,'client_member_id'=>102,'work_date'=>'2026-02-11'],
        ['employee_id'=>12,'client_member_id'=>104,'work_date'=>'2026-02-11'],
    ],
    'count'=>2, 'revision'=>'old-test-revision', 'membership_revision'=>'old-test-directory',
];
DB::table('reporting_periods')->insert([
    'client_id'=>10, 'period_start'=>'2026-02-01', 'period_end'=>'2026-02-28',
    'status'=>'Счет оплачен', 'timesheet_snapshot'=>json_encode($paidSnapshot),
    'timesheet_revision'=>'old-test-revision', 'confirmed_hours'=>999, 'confirmed_amount'=>999,
]);
DB::table('reporting_period_approved_hours')->insert([
    ['reporting_period_id'=>2,'member_term_id'=>202],
    ['reporting_period_id'=>2,'member_term_id'=>206],
]);
$purge->purge(9, 'synthetic.paid', true);
clientCheck(DB::table('member_terms')->where('id',202)->exists()
    && DB::table('reporting_periods')->where('id',2)->value('confirmed_hours')==999,
    'Paid preflight wrote to the database');
$purge->purge(9, 'synthetic.paid');
$purge->purge(9, 'synthetic.paid');
clientCheck(!DB::table('client_members')->where('employee_id',9)->exists()
    && !DB::table('member_terms')->where('id',202)->exists()
    && !DB::table('reporting_period_approved_hours')->where('member_term_id',202)->exists(),
    'Paid-period employee commercial records remain');
clientCheck(DB::table('reporting_periods')->where('id',2)->value('status')==='Счет оплачен'
    && DB::table('reporting_periods')->where('id',2)->value('confirmed_amount')===null,
    'Client period or stale aggregate remained after paid employee deletion');
$cleanSnapshot = json_decode(DB::table('reporting_periods')->where('id',2)->value('timesheet_snapshot'), true);
clientCheck(count($cleanSnapshot['entries'])===1
    && $cleanSnapshot['entries'][0]['employee_id']===12
    && count($cleanSnapshot['final_approvals'])===1
    && count($cleanSnapshot['final_approval_exceptions'])===1
    && $cleanSnapshot['count']===1
    && $cleanSnapshot['revision']===null,
    'Paid snapshot still contains erased employee or lost coworker facts');
clientCheck(DB::table('client_members')->where('employee_id',12)->exists()
    && DB::table('reporting_period_approved_hours')->where('member_term_id',206)->exists(),
    'Unrelated coworker facts were erased from paid period');

// An old approved-hours FK is also erasable even if date ranges were edited.
DB::table('client_members')->insert(['id'=>103, 'client_id'=>10, 'employee_id'=>10]);
DB::table('member_terms')->insert(['id'=>204,'client_member_id'=>103,
    'valid_from'=>'2026-03-01', 'valid_to'=>'2026-03-31']);
DB::table('reporting_period_approved_hours')->insert([
    'reporting_period_id'=>2,'member_term_id'=>204,
]);
$purge->purge(10, 'synthetic.outside.period');
clientCheck(!DB::table('client_members')->where('id',103)->exists()
    && !DB::table('member_terms')->where('id',204)->exists()
    && !DB::table('reporting_period_approved_hours')->where('member_term_id',204)->exists(),
    'Historical paid approval FK retained erased employee');

// A legacy row that points to a different employee's canonical membership
// must require reconciliation rather than deleting another person's terms.
DB::table('project_members')->insert(['id'=>400,'project_id'=>20,'specialist_id'=>11]);
DB::table('member_terms')->insert(['id'=>205, 'project_member_id'=>400,
    'client_member_id'=>101,'valid_from'=>'2026-04-01', 'valid_to'=>'2026-04-30']);
clientReject(static fn()=> $purge->purge(11, 'synthetic.conflict'), 409,
    'Cross-employee legacy term must fail closed');
clientCheck(DB::table('member_terms')->where('id',205)->exists()
    && DB::table('client_members')->where('id',101)->exists(),
    'Cross-employee legacy term was lost');

echo "Clients employee hard delete, paid snapshots, retries and isolation PASS\n";
