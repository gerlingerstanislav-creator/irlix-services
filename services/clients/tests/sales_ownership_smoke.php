<?php

// Synthetic regression: imported Sales ownership is not determined by Client assignment alone.
require __DIR__.'/client_members_bootstrap.php';

use App\Models\ClientRequest;
use App\Services\ClientWorkflowVisibility;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Schema::create('clients', static function (Blueprint $t): void {
    $t->id(); $t->string('name'); $t->integer('sales_employee_id')->nullable();
    $t->integer('account_employee_id')->nullable(); $t->timestamps();
});
Schema::create('client_account_delegations', static function (Blueprint $t): void {
    $t->id(); $t->integer('client_id'); $t->integer('to_employee_id');
    $t->date('starts_at'); $t->date('ends_at'); $t->timestamp('revoked_at')->nullable();
});
Schema::create('client_requests', static function (Blueprint $t): void {
    $t->id(); $t->integer('client_id')->nullable(); $t->integer('lead_id')->nullable();
    $t->integer('responsible_employee_id'); $t->string('title');
    $t->string('status')->default('Открыт'); $t->timestamps();
});
Schema::create('positions', static function (Blueprint $t): void {
    $t->id(); $t->integer('client_request_id');
    $t->integer('direction_department_id')->nullable(); $t->string('direction')->nullable();
    $t->timestamps();
});
Schema::create('client_contour_permissions', static function (Blueprint $t): void {
    $t->id(); $t->string('role'); $t->string('permission');
    $t->boolean('allowed')->default(false); $t->string('scope')->default('none');
    $t->boolean('denied')->default(false); $t->timestamps(); $t->unique(['role','permission']);
});
Schema::create('client_contour_permission_audit', static function (Blueprint $t): void {
    $t->id(); $t->string('role'); $t->string('permission'); $t->timestamp('created_at')->nullable();
});

DB::table('clients')->insert([
    ['id'=>1,'name'=>'Sales client','sales_employee_id'=>7],
    ['id'=>2,'name'=>'Other client','sales_employee_id'=>8],
    ['id'=>3,'name'=>'Delegated client','account_employee_id'=>9],
]);
DB::table('client_account_delegations')->insert([
    'client_id'=>3,'to_employee_id'=>7,'starts_at'=>'2026-01-01','ends_at'=>'2099-12-31',
]);
foreach (range(1, 100) as $id) {
    DB::table('client_requests')->insert([
        'id'=>$id,'client_id'=>2,'responsible_employee_id'=>99,
        'title'=>'Foreign request', 'created_at'=>'2026-11-06 10:00:00',
    ]);
}
DB::table('client_requests')->insert([
    ['id'=>101,'client_id'=>2,'responsible_employee_id'=>7,'title'=>'Own migrated request','status'=>'Закрыт','created_at'=>'2026-01-01 00:00:00'],
    ['id'=>102,'client_id'=>1,'responsible_employee_id'=>99,'title'=>'Own client request','status'=>'Открыт','created_at'=>'2026-01-02 00:00:00'],
    ['id'=>103,'client_id'=>3,'responsible_employee_id'=>99,'title'=>'Delegated request','status'=>'Открыт','created_at'=>'2026-01-03 00:00:00'],
]);
DB::table('positions')->insert([
    ['id'=>1,'client_request_id'=>101,'direction_department_id'=>20],
    ['id'=>2,'client_request_id'=>100,'direction'=>'Legacy production'],
]);

$visibility = new ClientWorkflowVisibility();
$access = [
    'platform_admin'=>false,
    'employee'=>['id'=>7],
    'team_employee_ids'=>[7],
    'roles'=>['sales-manager'],
    'permissions'=>[
        'requests.view'=>['allowed'=>true,'scope'=>'own'],
        'positions.view'=>['allowed'=>true,'scope'=>'own'],
        'attempts.view'=>['allowed'=>true,'scope'=>'own'],
        'clients.view'=>['allowed'=>true,'scope'=>'own'],
    ],
];
$scopes = $visibility->viewScopes($access);
$clientScope = $visibility->scopeFor($access, 'clients.view');
clientCheck($clientScope['clients'] === [1,3], 'Sales and active delegated client ownership');
clientCheck(ClientWorkflowVisibility::requestAllowed(['client_id'=>2, 'responsible_employee_id'=>7], $scopes['requests.view']),
    'Sales loses own request when client belongs to another Sales');
clientCheck(!ClientWorkflowVisibility::requestAllowed(['client_id'=>2, 'responsible_employee_id'=>99], $scopes['requests.view']),
    'Foreign Sales request incorrectly visible');
$filter = ClientRequest::query()->orderByDesc('created_at')->orderByDesc('id');
$visibility->restrictRequestQuery($filter, $access, $scopes);
clientCheck($filter->count()===3, 'SQL prefilter should return assigned request, Sales client and delegated client');
clientCheck($filter->forPage(1, 1)->first()->id === 103, 'Pagination must page only visible requests');
$emptyScope = ['allowed'=>false,'employees'=>[],'clients'=>[]];
$rows = [[
    'id'=>101,'client_id'=>2,'responsible_employee_id'=>7,'title'=>'Own',
    'positions'=>[['id'=>1,'client_request_id'=>101,'technology'=>'PHP','private_term'=>'hidden',
        'attempts'=>[['id'=>7,'status'=>'Новая']]]],
]];
$redacted = $visibility->projectRequests($rows, $access,
    [...$scopes, 'attempts.view'=>$emptyScope]);
clientCheck(count($redacted)===1 && $redacted[0]['positions'][0]['attempts']===[],
    'requests/positions view leaked attempts.view data');
$redacted = $visibility->projectRequests($rows, $access,
    [...$scopes, 'requests.view'=>$emptyScope, 'positions.view'=>$emptyScope]);
clientCheck(count($redacted)===1 && !isset($redacted[0]['positions'][0]['private_term'])
    && !isset($redacted[0]['target_name']) && count($redacted[0]['positions'][0]['attempts'])===1,
    'Attempt-only permission leaked position or request details');
$redacted = $visibility->projectRequests($rows, $access,
    [...$scopes, 'positions.view'=>$emptyScope,'attempts.view'=>$emptyScope]);
clientCheck($redacted[0]['positions'] === [], 'requests.view should not silently grant positions.view');
$denyAccess = $access;
$denyAccess['permissions']['requests.view'] = ['allowed'=>false,'scope'=>'none'];
$denyAccess['permissions']['positions.view'] = ['allowed'=>false,'scope'=>'none'];
$denyAccess['permissions']['attempts.view'] = ['allowed'=>false,'scope'=>'none'];
$denyScopes = $visibility->viewScopes($denyAccess);
$denyQuery = ClientRequest::query();
$visibility->restrictRequestQuery($denyQuery, $denyAccess, $denyScopes);
clientCheck($denyQuery->count()===0, 'Denied workflow still returned rows');
$teamAccess = $access;
$teamAccess['team_employee_ids'] = [7,99];
$teamAccess['permissions']['requests.view']['scope']='team';
clientCheck($visibility->scopeFor($teamAccess, 'requests.view')['employees'] === [7,99],
    'Team scope ignored Employees team ids');
$allAccess = $access;
$allAccess['permissions']['requests.view']['scope']='all';
clientCheck(ClientWorkflowVisibility::requestAllowed(['client_id'=>2,'responsible_employee_id'=>99],
    $visibility->scopeFor($allAccess,'requests.view')), 'All scope should include foreign requests');
$prodAccess = $access;
$prodAccess['roles']=['department-manager'];
// Production-only member has position/attempt grants, not an implicit request grant.
$prodAccess['permissions']['requests.view']=['allowed'=>false,'scope'=>'none'];
$prodAccess['production_department_ids']=[20];
$prodAccess['legacy_direction_ids']=['Legacy production'=>20];
$prodScopes = $visibility->viewScopes($prodAccess);
$production = ClientRequest::query();
$visibility->restrictRequestQuery($production, $prodAccess, $prodScopes);
clientCheck($production->count()===2, 'Production head must only see its department positions');
clientCheck(ClientWorkflowVisibility::positionAllowed(['direction_department_id'=>20], ['responsible_employee_id'=>99], $prodScopes['positions.view'], $prodAccess),
    'Production manager lost own direction');
clientCheck(!ClientWorkflowVisibility::positionAllowed(['direction_department_id'=>21], ['responsible_employee_id'=>99], $prodScopes['positions.view'], $prodAccess),
    'Production manager received other direction');
$prodAccess['production_department_ids']=[];
$emptyProduction = ClientRequest::query();
$visibility->restrictRequestQuery($emptyProduction, $prodAccess, $prodScopes);
clientCheck($emptyProduction->count()===0, 'Empty production scope exposes all requests');

$insert = static function (string $permission, bool $allowed=false, string $scope='none', bool $denied=false): void {
    DB::table('client_contour_permissions')->insert([
        'role'=>'org:sales','permission'=>$permission,'allowed'=>$allowed,'scope'=>$scope,
        'denied'=>$denied,'created_at'=>now(),'updated_at'=>now(),
    ]);
};
$insert('requests.view',true,'own');
$insert('clients.view');
$seed = require __DIR__.'/../database/migrations/2026_11_06_000002_grant_sales_own_clients_view.php';
$seed->up();
$grant = DB::table('client_contour_permissions')->where('role','org:sales')->where('permission','clients.view')->first();
clientCheck((bool)$grant->allowed && $grant->scope==='own','Sales client view baseline missing');
DB::table('client_contour_permissions')->where('id',$grant->id)->update(['allowed'=>false,'scope'=>'none']);
DB::table('client_contour_permission_audit')->insert(['role'=>'org:sales','permission'=>'clients.view','created_at'=>now()]);
$seed->up();
clientCheck(!(bool)DB::table('client_contour_permissions')->where('id',$grant->id)->value('allowed'),
    'Seed overwrote an audited Sales denial');

echo "Sales ownership and scoped workflow smoke passed\n";
