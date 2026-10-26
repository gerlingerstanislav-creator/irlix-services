<?php

/**
 * PostgreSQL-only refactor contract on the real Clients schema.
 * A single outer transaction is always rolled back; no persistent CI fixtures.
 * Invoked after the real Laravel migrations by client-member-migration-pg.yml.
 */
require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ClientRegistryService;
use App\Services\LeadManagementService;
use App\Services\LegacyProjectMembershipService;
use App\Services\FinanceClientsService;
use App\Models\ClientMember;
use App\Models\MemberTerm;
use App\Models\ProjectAssignment;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

function pgCheck(bool $ok, string $description): void
{
    if (!$ok) throw new RuntimeException($description);
}
function pgReject(callable $operation, int $expectedCode, string $description): void
{
    try {
        $operation();
    } catch (HttpException $error) {
        pgCheck($error->getStatusCode() === $expectedCode, $description.': wrong HTTP status');
        return;
    }
    throw new RuntimeException($description.': operation was accepted');
}

$connection = DB::connection();
pgCheck($connection->getDriverName() === 'pgsql', 'This smoke requires PostgreSQL');
$connection->beginTransaction();
try {
    $registry = new ClientRegistryService();
    $client = $registry->create([
        'name' => 'Synthetic PG Refactor Contract',
        'sales_employee_id' => 970001,
    ]);
    pgCheck($client->id > 0 && $client->account_employee_id === null,
        'Client registry creation changed nullable ownership');
    pgCheck(!DB::table('projects')->where('client_id', $client->id)->exists(),
        'Client creation incorrectly requires a default project');

    $handoff = $registry->transfer((int)$client->id, 970002, 'synthetic-pg-check');
    pgCheck((int)$handoff->account_employee_id === 970002,
        'Initial account handoff failed on PostgreSQL');
    pgReject(static fn () => $registry->transfer((int)$client->id, 970003, 'synthetic-pg-check'),
        422, 'Second account handoff');
    pgCheck(DB::table('client_history_events')->where('client_id', $client->id)
        ->where('type', 'account_assigned')->count() === 1, 'Account handoff audit duplicated');

    $project = $registry->createProject((int)$client->id, 'Synthetic Project');
    pgCheck(!(bool)$project->is_default, 'Project unexpectedly becomes default');
    $members = new LegacyProjectMembershipService();
    $legacyId = $members->attach((int)$project->id, $project, [
        'specialist_id' => 970004, 'specialist_name' => 'Synthetic Specialist',
        'technology' => 'PHP', 'level' => 'Senior',
        'hourly_rate' => '123.45', 'hours_per_day' => '8.00',
        'valid_from' => '2027-06-01', 'valid_to' => '2027-06-30',
    ], false);
    $member = ClientMember::query()->where('client_id', $client->id)
        ->where('employee_id', 970004)->first();
    pgCheck($member !== null && $legacyId > 0, 'Legacy adapter lost canonical membership');
    pgCheck(MemberTerm::query()->where('client_member_id', $member->id)
        ->where('project_member_id', $legacyId)->count() === 1,
        'Historical commercial term is missing');
    pgCheck(ProjectAssignment::query()->where('client_member_id', $member->id)
        ->where('project_id', $project->id)->count() === 1,
        'Project assignment not preserved');
    pgReject(static fn () => $members->attach((int)$project->id, $project, [
        'specialist_id' => 970004, 'specialist_name' => 'Synthetic Specialist',
        'technology' => 'PHP', 'level' => 'Senior',
        'hourly_rate' => '124.00', 'hours_per_day' => '8.00',
        'valid_from' => '2027-06-15', 'valid_to' => '2027-07-15',
    ], false), 422, 'Overlapping commercial terms');
    pgCheck(MemberTerm::query()->where('client_member_id', $member->id)->count() === 1,
        'Rejected overlap left an extra term');

    $leads = new LeadManagementService();
    $lead = $leads->create(['name' => 'Synthetic PG Lead'], 970005);
    $successful = $leads->update((int)$lead->id,
        ['status' => 'Сделка закрыта - Успех'], 970005);
    $contact = $leads->attachContact((int)$lead->id, [
        'full_name' => 'Synthetic PG Contact', 'email' => 'pg-smoke@example.invalid',
    ]);
    $converted = $leads->convert((int)$lead->id, $successful, []);
    pgCheck((int)$converted->sales_employee_id === 970005
        && $converted->account_employee_id === null, 'Lead conversion ownership changed');
    pgCheck(DB::table('contact_relations')->where('entity_type', 'client')
        ->where('entity_id', $converted->id)
        ->where('contact_person_id', $contact->id)->exists(),
        'Lead contact was not copied');
    pgReject(static fn () => $leads->convert((int)$lead->id, $successful, []),
        422, 'Duplicate lead conversion');
    pgReject(static fn () => $leads->update((int)$lead->id,
        ['name' => 'Improper edit'], 970005), 409, 'Converted lead edit');

    $finance = new FinanceClientsService();
    pgCheck($finance->directCostEntity((int)$client->id, (int)$project->id)['project_id']
        === (int)$project->id, 'Finance project ownership contract failed');
    pgReject(static fn () => $finance->directCostEntity((int)$converted->id,
        (int)$project->id), 422, 'Finance cross-client project access');

    fwrite(STDOUT, "Clients PostgreSQL Eloquent contract: PASS (transaction rolled back)\n");
} finally {
    $connection->rollBack();
}
