<?php

// Isolated synthetic records: hard delete must erase only the chosen employee
// while preserving unrelated client data and immutable financial periods.
require __DIR__.'/client_members_bootstrap.php';

use App\Services\EmployeePurgeService;
use Illuminate\Support\Facades\DB;

$schema = DB::connection()->getSchemaBuilder();
$columns = [
    'clients' => ['account_employee_id', 'sales_employee_id'],
    'client_members' => ['client_id', 'employee_id'],
    'member_terms' => ['client_member_id', 'superseded_by_term_id'],
    'member_term_lineage' => ['canonical_term_id'],
    'client_member_feedbacks' => ['client_member_id', 'created_by_username'],
    'project_assignments' => ['client_member_id'],
    'reporting_periods' => ['client_id', 'status', 'period_start', 'period_end'],
    'connection_attempts' => ['specialist_id', 'responsible_employee_id'],
    'project_members' => ['specialist_id'],
    'leads' => ['responsible_employee_id'],
    'positions' => ['responsible_rn_employee_id'],
    'client_requests' => ['responsible_employee_id'],
    'client_contour_permission_audit' => ['actor_employee_id'],
    'lead_events' => ['created_by_employee_id'],
    'connection_attempt_events' => ['actor_employee_id'],
    'client_account_delegations' => ['from_employee_id', 'to_employee_id', 'created_by_employee_id'],
    'project_member_feedbacks' => ['created_by_username'],
    'client_history_events' => ['created_by_username'],
];
foreach ($columns as $table => $names) {
    $schema->create($table, static function ($t) use ($names): void {
        $t->id();
        foreach ($names as $name) {
            if (in_array($name, ['period_start', 'period_end'], true)) $t->date($name)->nullable();
            elseif ($name === 'status' || $name === 'created_by_username') $t->string($name)->nullable();
            else $t->unsignedBigInteger($name)->nullable();
        }
        if (in_array('client_member_id', $names, true) && !in_array($table, ['client_members'], true)) {
            $t->foreign('client_member_id')->references('id')->on('client_members')->restrictOnDelete();
        }
        $t->timestamps();
    });
}
DB::table('clients')->insert([['id'=>10, 'account_employee_id'=>7, 'sales_employee_id'=>7],
    ['id'=>11, 'account_employee_id'=>8, 'sales_employee_id'=>8]]);
DB::table('client_members')->insert([['id'=>100, 'client_id'=>10, 'employee_id'=>7],
    ['id'=>101, 'client_id'=>11, 'employee_id'=>8]]);
DB::table('member_terms')->insert([['id'=>200,'client_member_id'=>100, 'valid_from'=>'2026-01-01', 'valid_to'=>'2026-01-31'],
    ['id'=>201,'client_member_id'=>101, 'valid_from'=>'2026-01-01', 'valid_to'=>'2026-01-31']]);
DB::table('member_term_lineage')->insert(['canonical_term_id'=>200]);
DB::table('client_member_feedbacks')->insert(['client_member_id'=>100, 'created_by_username'=>'synthetic.employee']);
DB::table('project_assignments')->insert(['client_member_id'=>100]);
DB::table('connection_attempts')->insert(['specialist_id'=>7, 'responsible_employee_id'=>7]);
DB::table('project_members')->insert(['specialist_id'=>7]);
DB::table('clients')->where('id', 10)->update(['sales_employee_id'=>7]);
DB::table('client_history_events')->insert(['created_by_username'=>'synthetic.employee']);
DB::table('lead_events')->insert(['created_by_employee_id'=>7]);
DB::table('client_account_delegations')->insert(['from_employee_id'=>7, 'to_employee_id'=>8, 'created_by_employee_id'=>7]);
DB::table('reporting_periods')->insert(['client_id'=>10, 'period_start'=>'2026-01-01',
    'period_end'=>'2026-01-31', 'status'=>'Новый']);

$purge = new EmployeePurgeService();
$purge->purge(7, 'synthetic.employee');
$purge->purge(7, 'synthetic.employee'); // safe retry
clientCheck(!DB::table('client_members')->where('employee_id',7)->exists(), 'Membership was not erased');
clientCheck(!DB::table('member_terms')->where('id',200)->exists(), 'Commercial terms were not erased');
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

DB::table('client_members')->insert(['id'=>102, 'client_id'=>10, 'employee_id'=>9]);
DB::table('member_terms')->insert(['id'=>202, 'client_member_id'=>102,
    'valid_from'=>'2026-02-01', 'valid_to'=>'2026-02-28']);
DB::table('reporting_periods')->insert(['client_id'=>10, 'period_start'=>'2026-02-01',
    'period_end'=>'2026-02-28', 'status'=>'Счет оплачен']);
clientReject(static fn()=> $purge->purge(9, 'synthetic.protected'), 423,
    'Locked client reporting facts must reject hard delete');
clientCheck(DB::table('client_members')->where('id',102)->exists()
    && DB::table('member_terms')->where('id',202)->exists(),
    'Protected records were partially erased');

echo "Clients employee hard delete, retries, isolation and immutable reporting guard PASS\n";
