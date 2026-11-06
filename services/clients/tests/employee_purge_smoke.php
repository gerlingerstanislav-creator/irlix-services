<?php

// Isolated synthetic records: hard delete must erase only the chosen employee
// while preserving unrelated client data and immutable financial periods.
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
    'reporting_periods' => ['client_id', 'status', 'period_start', 'period_end'],
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
            elseif (in_array($name, ['status','created_by_username','updated_by','author','filename','cv_storage_path'], true)) $t->string($name)->nullable();
            else $t->unsignedBigInteger($name)->nullable();
        }
        if (in_array('client_member_id', $names, true) && !in_array($table, ['client_members'], true)) {
            $t->foreign('client_member_id')->references('id')->on('client_members')->restrictOnDelete();
        }
        if ($table === 'project_members') $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
        if ($table === 'member_terms') $t->foreign('project_member_id')->references('id')->on('project_members')->restrictOnDelete();
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
$purge->purge(7, 'synthetic.employee', true); // strictly read-only preflight
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

DB::table('client_members')->insert(['id'=>102, 'client_id'=>10, 'employee_id'=>9]);
DB::table('member_terms')->insert(['id'=>202, 'client_member_id'=>102,
    'valid_from'=>'2026-02-01', 'valid_to'=>'2026-02-28']);
DB::table('reporting_periods')->insert(['client_id'=>10, 'period_start'=>'2026-02-01',
    'period_end'=>'2026-02-28', 'status'=>'Счет оплачен']);
clientReject(static fn()=> $purge->purge(9, 'synthetic.protected', true), 423,
    'Protected reporting periods must reject before destructive work');
clientReject(static fn()=> $purge->purge(9, 'synthetic.protected'), 423,
    'Locked client reporting facts must reject hard delete');
clientCheck(DB::table('client_members')->where('id',102)->exists()
    && DB::table('member_terms')->where('id',202)->exists(),
    'Protected records were partially erased');

// Approved-hour linkage must protect a paid period even if dates were edited
// and no longer overlap the commercial term.
DB::table('client_members')->insert(['id'=>103, 'client_id'=>10, 'employee_id'=>10]);
DB::table('member_terms')->insert(['id'=>204,'client_member_id'=>103,
    'valid_from'=>'2026-03-01', 'valid_to'=>'2026-03-31']);
DB::table('reporting_period_approved_hours')->insert([
    'reporting_period_id'=>2,'member_term_id'=>204,
]);
clientReject(static fn()=> $purge->purge(10, 'synthetic.protected'), 423,
    'Approved hours linked to paid period must block purge regardless of term dates');
clientCheck(DB::table('client_members')->where('id',103)->exists(),
    'Protected approved-hours membership was erased');

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

echo "Clients employee hard delete, retries, isolation and immutable reporting guard PASS\n";
