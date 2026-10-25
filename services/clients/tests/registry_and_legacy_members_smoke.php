<?php

// Synthetic regression for registry writes, locked handoff and project-free identity.
require __DIR__.'/client_members_bootstrap.php';

use App\Services\ClientRegistryService;
use App\Services\LegacyProjectMembershipService;
use Illuminate\Support\Facades\DB;

clientSchemaLegacy();
clientExpand();

$clients = new ClientRegistryService();
$client = $clients->create(['name' => 'Synthetic Registry', 'sales_employee_id' => 12]);
clientCheck($client->id > 0 && $client->account_employee_id === null,
    'Client creation unexpectedly required a default project or account manager');
clientCheck(DB::table('projects')->where('client_id', $client->id)->count() === 0,
    'Client creation introduced a mandatory project');
$updated = $clients->update((int) $client->id, ['name' => 'Synthetic Renamed']);
clientCheck($updated->name === 'Synthetic Renamed', 'Client registry update lost values');

$transferred = $clients->transfer((int) $client->id, 21, 'synthetic-author');
clientCheck((int) $transferred->account_employee_id === 21,
    'Primary AM handoff did not persist');
clientReject(static fn () => $clients->transfer((int) $client->id, 22, 'synthetic-author'),
    422, 'Duplicate AM transfer was accepted');
clientCheck(DB::table('client_history_events')->where('client_id', $client->id)
    ->where('type', 'account_assigned')->count() === 1, 'Handoff audit lost exactly-once history');

$project = $clients->createProject((int) $client->id, 'Synthetic Project');
clientCheck($project->name === 'Synthetic Project' && !(bool) $project->is_default,
    'Project creation changed default project semantics');
$legacy = new LegacyProjectMembershipService();
$memberId = $legacy->attach((int) $project->id, $project, [
    'specialist_id' => 45, 'specialist_name' => 'Synthetic Employee',
    'technology' => 'PHP', 'level' => 'Middle', 'hourly_rate' => 150,
    'hours_per_day' => 8, 'valid_from' => '2027-02-01', 'valid_to' => '2027-02-28',
], false);
$canonical = DB::table('client_members')->where('client_id', $client->id)
    ->where('employee_id', 45)->first();
clientCheck($memberId > 0 && $canonical !== null,
    'Legacy route did not create canonical client membership');
clientCheck(DB::table('member_terms')->where('client_member_id', $canonical->id)
    ->where('project_member_id', $memberId)->count() === 1,
    'Historical member term link was not retained');
clientCheck(DB::table('project_assignments')->where('client_member_id', $canonical->id)
    ->where('project_id', $project->id)->count() === 1,
    'Project assignment history was not created');
clientReject(static fn () => $legacy->attach((int) $project->id, $project, [
    'specialist_id' => 45, 'specialist_name' => 'Synthetic Employee',
    'technology' => 'PHP', 'level' => 'Middle', 'hourly_rate' => 151,
    'hours_per_day' => 8, 'valid_from' => '2027-02-10', 'valid_to' => '2027-02-20',
], false), 422, 'Overlapping commercial terms were accepted');
clientCheck(DB::table('member_terms')->where('client_member_id', $canonical->id)->count() === 1,
    'Rejected overlapping terms left partial writes');
echo "Clients registry / legacy membership Eloquent smoke: PASS\n";
