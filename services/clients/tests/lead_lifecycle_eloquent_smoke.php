<?php

// Synthetic lead-to-client transfer and status/audit invariants.
require __DIR__.'/client_members_bootstrap.php';

use App\Services\LeadManagementService;
use Illuminate\Support\Facades\DB;

clientSchemaLegacy();
$s = DB::connection()->getSchemaBuilder();
$s->table('clients', static function ($t): void {
    $t->string('type')->nullable();
    $t->string('sector')->nullable();
});
$s->table('client_requests', static function ($t): void {
    $t->integer('lead_id')->nullable();
});
$s->create('leads', static function ($t): void {
    $t->id(); $t->string('name'); $t->string('source')->nullable();
    $t->integer('responsible_employee_id'); $t->string('status');
    $t->integer('converted_client_id')->nullable(); $t->timestamps();
});
$s->create('lead_events', static function ($t): void {
    $t->id(); $t->integer('lead_id'); $t->string('type');
    $t->string('status')->nullable(); $t->text('comment')->nullable();
    $t->integer('created_by_employee_id')->nullable(); $t->timestamps();
});
$s->create('lead_legal_entities', static function ($t): void {
    $t->id(); $t->integer('lead_id'); $t->string('name'); $t->string('inn');
    $t->string('full_name'); $t->string('ogrn')->nullable(); $t->string('kpp')->nullable();
    $t->date('registration_date')->nullable(); $t->string('okpo')->nullable();
    $t->string('oktmo')->nullable(); $t->text('address')->nullable(); $t->timestamps();
});
$s->create('client_legal_entities', static function ($t): void {
    $t->id(); $t->integer('client_id'); $t->string('name'); $t->string('inn');
    $t->string('full_name'); $t->string('ogrn')->nullable(); $t->string('kpp')->nullable();
    $t->date('registration_date')->nullable(); $t->string('okpo')->nullable();
    $t->string('oktmo')->nullable(); $t->text('address')->nullable(); $t->timestamps();
});
$s->create('contact_people', static function ($t): void {
    $t->id(); $t->string('full_name'); $t->string('position')->nullable();
    $t->string('phone')->nullable(); $t->string('email')->nullable(); $t->timestamps();
});
$s->create('contact_methods', static function ($t): void {
    $t->id(); $t->integer('contact_person_id'); $t->string('type');
    $t->string('contact'); $t->boolean('is_active'); $t->boolean('is_preferred');
    $t->timestamps();
});
$s->create('contact_relations', static function ($t): void {
    $t->id(); $t->integer('contact_person_id'); $t->string('entity_type');
    $t->integer('entity_id'); $t->string('relation_role')->nullable();
    $t->boolean('active'); $t->timestamps();
});

$leads = new LeadManagementService();
$lead = $leads->create(['name' => 'Synthetic Lead', 'source' => 'Synthetic Source'], 17);
clientCheck($lead->status === 'Новый лид' && $lead->responsible_employee_id == 17,
    'Lead initial status or responsible Sales was not set');
$updated = $leads->update((int) $lead->id, ['status' => 'Сделка закрыта - Успех'], 17);
clientCheck($updated->status === 'Сделка закрыта - Успех',
    'Lead status transition did not persist');
$contact = $leads->attachContact((int) $lead->id, [
    'full_name' => 'Synthetic Contact', 'phone' => '+70000000000',
    'email' => 'synthetic@example.invalid', 'position' => 'Manager',
]);
clientCheck($contact->id > 0, 'Lead contact creation failed');
DB::table('lead_legal_entities')->insert([
    'lead_id'=>$lead->id, 'name'=>'Synthetic Entity', 'inn'=>'0000000000',
    'full_name'=>'Synthetic Entity LLC',
]);
$client = $leads->convert((int) $lead->id, $updated, ['name'=>'Synthetic Converted Client']);
clientCheck($client->name === 'Synthetic Converted Client'
    && (int) $client->sales_employee_id === 17
    && $client->account_employee_id === null,
    'Lead conversion changed owner or required account manager');
clientCheck(DB::table('clients')->where('id',$client->id)->count() === 1
    && DB::table('projects')->where('client_id', $client->id)->count() === 0,
    'Lead conversion must create exactly one project-free client');
clientCheck(DB::table('contact_relations')->where('entity_type','client')
    ->where('entity_id',$client->id)->where('contact_person_id',$contact->id)->count() === 1,
    'Lead contact was not copied to client');
clientCheck(DB::table('client_legal_entities')->where('client_id',$client->id)->count() === 1,
    'Lead legal entity was not copied to client');
clientCheck(DB::table('lead_events')->where('lead_id',$lead->id)->where('type','converted')->count() === 1,
    'Lead conversion audit missing');
clientReject(static fn () => $leads->convert((int)$lead->id,$updated,[]),
    422,'Lead converted twice');
clientCheck(DB::table('clients')->count() === 1,
    'Duplicate conversion created a second client');
clientReject(static fn () => $leads->update((int)$lead->id,['name'=>'Tampered'],17),
    409,'Converted lead remained writable');
echo "Clients lead lifecycle / conversion Eloquent smoke: PASS\n";
