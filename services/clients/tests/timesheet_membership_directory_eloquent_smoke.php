<?php

// Integration regression for the authoritative Clients -> Timesheets v2 directory.
require __DIR__.'/client_members_bootstrap.php';

use App\Services\TimesheetMembershipDirectory;
use App\Http\Controllers\TimesheetMembershipsController;
use Illuminate\Support\Facades\DB;

clientSchemaLegacy();
clientExpand();

DB::connection()->getSchemaBuilder()->create('client_account_delegations', function ($t): void {
    $t->id();
    $t->integer('client_id');
    $t->integer('to_employee_id');
    $t->date('starts_at');
    $t->date('ends_at');
    $t->timestamp('revoked_at')->nullable();
});
DB::table('clients')->insert([
    ['id' => 10, 'name' => 'No Projects', 'account_employee_id' => 100],
    ['id' => 20, 'name' => 'Other Client', 'account_employee_id' => 200],
]);
DB::table('partner_specialists')->insert(['id' => 7, 'full_name' => 'Synthetic Partner']);
DB::table('client_members')->insert([
    ['id' => 30, 'client_id' => 10, 'employee_id' => 8, 'partner_specialist_id' => null, 'specialist_name' => 'Synthetic Employee'],
    ['id' => 40, 'client_id' => 20, 'employee_id' => null, 'partner_specialist_id' => 7, 'specialist_name' => 'Synthetic Partner'],
]);
DB::table('member_terms')->insert([
    ['id' => 50, 'client_member_id' => 30, 'project_member_id' => null,
        'technology' => 'PHP', 'level' => 'Middle', 'hours_per_day' => 8,
        'valid_from' => '2026-01-01', 'valid_to' => '2026-01-31'],
    ['id' => 60, 'client_member_id' => 30, 'project_member_id' => null,
        'technology' => 'PHP', 'level' => 'Middle', 'hours_per_day' => 8,
        'valid_from' => '2026-02-01', 'valid_to' => null],
]);
DB::table('client_account_delegations')->insert([
    ['client_id' => 10, 'to_employee_id' => 101, 'starts_at' => now()->subDay()->toDateString(),
        'ends_at' => now()->addDay()->toDateString()],
]);
DB::table('reporting_periods')->insert([
    ['id'=>70, 'client_id'=>10, 'period_start'=>'2026-01-01', 'period_end'=>'2026-01-31', 'status'=>'Новый'],
    ['id'=>80, 'client_id'=>20, 'period_start'=>'2026-01-01', 'period_end'=>'2026-01-31', 'status'=>'ТШ на согласовании'],
]);

$service = new TimesheetMembershipDirectory();
$one = $service->snapshot();
$two = $service->snapshot();
clientCheck($one['schema_version'] === 2, 'Schema version changed');
clientCheck($one['complete'] === true && $one['count'] === 3, 'Term and no-term members must be complete');
clientCheck($one['revision'] === $two['revision'], 'Directory revision changed without facts');
clientCheck(hash('sha256', json_encode($one['data'], JSON_THROW_ON_ERROR)) === $one['revision'],
    'Revision must hash unchanged sorted directory contract');
clientCheck($one['data'][0]['client_member_id'] === 30 && $one['data'][0]['member_term_id'] === 50,
    'Canonical member terms were not ordered');
clientCheck($one['data'][2]['employee_id'] === null && $one['data'][2]['partner_specialist_id'] === 7
    && $one['data'][2]['valid_from'] === null, 'Partner without terms must not disappear');
clientCheck($one['data'][0]['delegated_account_employee_ids'] === [101], 'Active delegation lost');
clientCheck($one['locked_reporting_periods_count'] === 1 && $one['locked_reporting_periods'][0]['id'] === 80,
    'Locked reporting periods must exclude New');
clientCheck(!array_key_exists('hourly_rate', $one['data'][0]), 'Commercial rates must not leak');
clientCheck(App\Support\TimesheetMemberships::snapshot()['revision'] === $one['revision'],
    'Legacy support callers changed revision');

putenv('IRLIX_TIMESHEETS_INTEGRATION_TOKEN='.str_repeat('s',32));
$controller = new TimesheetMembershipsController($service);
$bad = Illuminate\Http\Request::create('/internal/system/timesheet-memberships');
clientReject(static fn () => $controller($bad), 403, 'Missing internal token accepted');
$bad->headers->set('X-Irlix-Timesheets-Token', 'wrong');
clientReject(static fn () => $controller($bad), 403, 'Invalid internal token accepted');
$good = Illuminate\Http\Request::create('/internal/system/timesheet-memberships');
$good->headers->set('X-Irlix-Timesheets-Token', str_repeat('s',32));
$res = $controller($good);
clientCheck($res->getStatusCode() === 200 && json_decode($res->getContent(), true)['revision'] === $one['revision'],
    'Trusted API JSON contract changed');
putenv('IRLIX_TIMESHEETS_INTEGRATION_TOKEN=');
echo "Clients canonical Timesheets membership directory v2: PASS\n";
