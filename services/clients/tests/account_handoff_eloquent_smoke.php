<?php

// Synthetic account handoff permissions, concurrency-sensitive state and audit.
require __DIR__.'/client_members_bootstrap.php';

use App\Services\ClientAccountHandoffService;
use Illuminate\Support\Facades\DB;

clientSchemaLegacy();
clientExpand();
DB::connection()->getSchemaBuilder()->create('client_account_delegations', function ($t): void {
    $t->id();
    $t->integer('client_id');
    $t->integer('from_employee_id');
    $t->integer('to_employee_id');
    $t->date('starts_at');
    $t->date('ends_at');
    $t->timestamp('revoked_at')->nullable();
    $t->integer('created_by_employee_id')->nullable();
    $t->timestamps();
});
DB::table('clients')->insert(['id'=>3,'name'=>'Synthetic Client','account_employee_id'=>10]);
$s = new ClientAccountHandoffService();
$notOwner = ['platform_admin'=>false,'roles'=>[],'employee'=>['id'=>99]];
$owner = ['platform_admin'=>false,'roles'=>[],'employee'=>['id'=>10]];
$head = ['platform_admin'=>false,'roles'=>['client-service-head'],'employee'=>['id'=>99]];

clientReject(static fn () => $s->delegate(3, [
    'to_employee_id'=>12,'starts_at'=>'2027-01-01','ends_at'=>'2027-01-31'
], $notOwner, 'stranger'),403,'Non-owner delegated an account');
clientReject(static fn () => $s->permanent(3,10,$owner,'synthetic'),422,
    'Assigning same account must fail');
$delegation = $s->delegate(3, [
    'to_employee_id'=>12,'starts_at'=>'2027-01-01','ends_at'=>'2027-01-31'
], $owner, 'synthetic');
clientCheck((int)$delegation->to_employee_id === 12 &&
    (int)$delegation->created_by_employee_id === 10,'Delegation actor changed');
clientReject(static fn () => $s->delegate(3, [
    'to_employee_id'=>13,'starts_at'=>'2027-01-15','ends_at'=>'2027-02-15'
], $owner,'synthetic'),422,'Overlapping delegation was accepted');
$revoked = $s->revoke(3,(int)$delegation->id,$head,'synthetic');
clientCheck($revoked->revoked_at !== null,'Delegation revoke lost history');
clientReject(static fn () => $s->revoke(3,(int)$delegation->id,$head,'synthetic'),404,
    'Already revoked delegation was revoked again');

$permanent = $s->permanent(3,11,$head,'synthetic');
clientCheck((int)$permanent->account_employee_id === 11,'Permanent ownership did not change');
clientCheck(DB::table('client_history_events')->where('client_id',3)->count() === 3,
    'Delegation and permanent reassignment audit is incomplete');
echo "Clients account handoff permissions, overlap, revocation and audit: PASS\n";
