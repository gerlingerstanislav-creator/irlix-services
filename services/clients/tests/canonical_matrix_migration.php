<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Services\ClientContourPermissionService;

config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>':memory:']);
DB::purge('sqlite');
Schema::create('client_contour_permissions', static function (Blueprint $t): void {
    $t->id();$t->string('role');$t->string('permission');
    $t->boolean('allowed');$t->string('scope');$t->boolean('denied')->default(false);
    $t->timestamps();$t->unique(['role','permission']);
});
Schema::create('client_contour_permission_audit', static function (Blueprint $t): void {
    $t->id();$t->unsignedBigInteger('actor_employee_id')->nullable();
    $t->string('role');$t->string('permission');
    $t->json('before')->nullable();$t->json('after')->nullable();
    $t->timestamp('created_at')->nullable();
});
$put=static function(string $role,string $permission,bool $allowed,string $scope,bool $denied=false):void {
    DB::table('client_contour_permissions')->insert([
        'role'=>$role,'permission'=>$permission,
        'allowed'=>$allowed,'scope'=>$scope,'denied'=>$denied,
        'created_at'=>now(),'updated_at'=>now(),
    ]);
};
$check=static function(bool $value,string $message):void {
    if (!$value) throw new RuntimeException($message);
};
$put('account-manager','clients.view',true,'own');
$put('org:account-management','clients.view',false,'none',true);
DB::table('client_contour_permission_audit')->insert([
    'role'=>'org:account-management','permission'=>'clients.view',
    'actor_employee_id'=>42,'before'=>'{}','after'=>'{}','created_at'=>now(),
]);
$put('sales-manager','clients.manage',true,'own');
$put('org:sales','clients.manage',false,'none');
DB::table('client_contour_permission_audit')->insert([
    'role'=>'sales-manager','permission'=>'clients.manage',
    'actor_employee_id'=>42,'before'=>'{}','after'=>'{}','created_at'=>now(),
]);
$put('sales-manager','attempts.view',true,'own');
$put('org:sales','attempts.view',true,'own');
$put('client-service','contacts.view',true,'own');
$put('accounting-head','members.view',true,'team');
$put('department-manager','timesheets.management.view',true,'team');
$put('department-manager','positions.view',true,'team');
$put('department-manager','attempts.manage',true,'team');
$put('head:backend','attempts.manage',false,'none',true);
$put('head:backend','positions.view',true,'own');
$put('org:backend','timesheets.mine.view',true,'own');
$put('platform-admin','clients.manage',true,'all');
$put('platform-tester','clients.view',false,'none');
$put('special:system-admin','clients.view',false,'none',true);
$put('head:client-service','attempts.view',false,'none');
$put('head:client-service','clients.view',false,'none',true);
$migration = require __DIR__.'/../database/migrations/2026_10_29_000002_remove_legacy_client_matrix_roles.php';
$migration->up();
// Timesheets transfer must read original Clients rows before any cleanup.
$check(DB::table('client_contour_permissions')->where('role','department-manager')
    ->where('permission','timesheets.management.view')->exists(),
    'Timesheets source row removed before transfer');
app(\App\Services\FinalizeLegacyClientPermissions::class)->run(['backend','qa']);
$rows=DB::table('client_contour_permissions')->get();
foreach($rows as $row){
    $check(preg_match('/^(org|head|special):[a-z0-9]+(?:-[a-z0-9]+)*$/D',(string)$row->role)===1,
        'Legacy matrix role persisted');
    $check(!str_starts_with((string)$row->permission,'timesheets.'),'Timesheets permission leaked into Clients');
}
$check((bool)DB::table('client_contour_permissions')->where('role','org:account-management')
    ->where('permission','clients.view')->value('denied')===true,
    'Manually configured canonical V2 explicit deny was overwritten');
$check(DB::table('client_contour_permissions')->where('role','org:sales')
    ->where('permission','clients.manage')->value('scope')==='own',
    'Newer audited legacy admin edit was lost instead of copied to V2');
$check(DB::table('client_contour_permissions')->where('role','head:account-management')
    ->where('permission','members.view')->value('scope')==='team',
    'Old head grant was not transferred to canonical V2');
$check(DB::table('client_contour_permissions')->where('role','head:client-service')
    ->where('permission','attempts.view')->value('scope')==='all',
    'Client Service head did not aggregate Sales capability');
$check((bool) DB::table('client_contour_permissions')->where('role','head:client-service')
    ->where('permission','clients.view')->value('denied') === true,
    'Client Service head aggregation overwrote an explicit V2 deny');
$check(DB::table('client_contour_permissions')->where('role','head:qa')
    ->where('permission','positions.view')->value('scope')==='team',
    'Production manager permissions not transferred to current production heads');
$check(DB::table('client_contour_permissions')->where('role','head:backend')
    ->where('permission','positions.view')->value('scope')==='team',
    'Old manager team scope did not widen unedited head own scope');
$check((bool)DB::table('client_contour_permissions')->where('role','head:backend')
    ->where('permission','attempts.manage')->value('denied')===true,
    'Existing canonical head deny was overwritten by generic manager grant');
$check(!DB::table('client_contour_permissions')->where('role','department-manager')->exists(),
    'Legacy manager rows remain');
$service=app(ClientContourPermissionService::class);
$matrix=$service->matrix();
$check(isset($matrix['special:platform-admin']['clients.manage'])
    && $matrix['special:platform-admin']['clients.manage']['locked']===true,
    'Canonical superuser column is missing or editable');
$check(!isset($matrix['account-manager'])&&!isset($matrix['platform-admin']),
    'Legacy role leaked into effective matrix');
$check(!$service->isEditableRole('account-manager')
    && !$service->isEditableRole('special:platform-admin')
    && $service->isEditableRole('org:account-management')
    && !$service->isEditableRole('org:not-created'),
    'Matrix editor can modify legacy or nonexistent roles');
$check(!in_array('timesheets.mine.view',array_keys($matrix['special:platform-admin']),true),
    'Timesheets permission exposed in Clients superuser column');
$put('unknown-old-role','clients.view',true,'all');
$refused=false;
try {
    app(\App\Services\FinalizeLegacyClientPermissions::class)->run(['backend','qa']);
} catch (\RuntimeException) {
    $refused=true;
}
$check($refused, 'Unmapped active historical role was silently deleted');
$check(DB::table('client_contour_permissions')->where('role','unknown-old-role')->exists(),
    'Rejected cleanup was not atomic');
DB::table('client_contour_permissions')->where('role','unknown-old-role')->delete();
// A real historical Account Manager role is KNOWN; its rule must have
// arrived in V2 before the finalizer can delete its source row.
$put('account-manager','leads.manage',true,'own');
$refused = false;
try {
    app(\App\Services\FinalizeLegacyClientPermissions::class)->run(['backend','qa']);
} catch (\RuntimeException) {
    $refused = true;
}
$check($refused && DB::table('client_contour_permissions')
    ->where('role','account-manager')->where('permission','leads.manage')->exists(),
    'Known old permission was deleted without reaching V2');
$put('org:account-management','leads.manage',true,'own');
app(\App\Services\FinalizeLegacyClientPermissions::class)->run(['backend','qa']);
$check(!DB::table('client_contour_permissions')->where('role','account-manager')->exists(),
    'Known migrated Account Manager role was incorrectly treated as unknown');
$check(DB::table('client_contour_permissions')->where('role','org:account-management')
    ->where('permission','leads.manage')->value('scope')==='own',
    'Transferred Account Manager grant changed');
$put('sales-manager','leads.manage',true,'team');
$put('org:sales','leads.manage',false,'none');
$refused = false;
try {
    app(\App\Services\FinalizeLegacyClientPermissions::class)->run(['backend','qa']);
} catch (\RuntimeException) {
    $refused = true;
}
$check($refused && DB::table('client_contour_permissions')->where('role','sales-manager')->exists(),
    'Incompatible old/new policies were silently discarded');
// A missing canonical Sales column is created once, but admin choices win.
DB::table('client_contour_permissions')->where('role','org:sales')->delete();
$salesSeed = require __DIR__.'/../database/migrations/2026_11_05_000001_ensure_sales_v2_matrix_role.php';
$salesSeed->up();
$check((string) DB::table('client_contour_permissions')->where('role','org:sales')
    ->where('permission','leads.view')->value('scope') === 'own',
    'Missing Sales V2 column did not receive initial own-scope lead access');
$check((bool) DB::table('client_contour_permissions')->where('role','org:sales')
    ->where('permission','cashflow.view')->value('allowed') === false,
    'Sales role gained unauthorized financial access');
DB::table('client_contour_permissions')->where('role','org:sales')
    ->where('permission','attempts.view')
    ->update(['allowed'=>false,'scope'=>'none','denied'=>true]);
$salesSeed->up();
$check((bool) DB::table('client_contour_permissions')->where('role','org:sales')
    ->where('permission','attempts.view')->value('denied') === true,
    'Reapplying Sales V2 seed overwrote administrator explicit deny');

// Adding a new platform role never grants access. Deleting it atomically
// revokes both grants and explicit denies; re-adding is a clean slate.
$syntheticRole = 'org:example-department';
$check($service->addRole($syntheticRole, 42), 'New V2 role column could not be created');
$check(!$service->addRole($syntheticRole, 42), 'Duplicate role column was created');
$check(DB::table('client_contour_permissions')->where('role', $syntheticRole)->count()
    === count(\\App\\Support\\ClientContourAccess::PERMISSION_LABELS),
    'Created role does not contain all empty permission cells');
$check(!DB::table('client_contour_permissions')->where('role', $syntheticRole)
    ->where('allowed', true)->exists(), 'New role was granted permissions by default');
$service->update([
    'role' => $syntheticRole, 'permission' => 'clients.view',
    'allowed' => true, 'scope' => 'all', 'denied' => false,
], 42);
$service->update([
    'role' => $syntheticRole, 'permission' => 'contacts.view',
    'allowed' => false, 'scope' => 'none', 'denied' => true,
], 42);
$check($service->removeRole($syntheticRole, 42), 'Existing role was not removed');
$check(!$service->removeRole($syntheticRole, 42), 'Removed role was unexpectedly still present');
$check(!DB::table('client_contour_permissions')->where('role', $syntheticRole)->exists(),
    'Deleted role retains effective permissions');
$check(!isset($service->matrix()[$syntheticRole]), 'Deleted role remains in matrix');
$check(DB::table('client_contour_permission_audit')->where('role', $syntheticRole)
    ->whereNull('after')->count() === count(\\App\\Support\\ClientContourAccess::PERMISSION_LABELS),
    'Role removal was not audited for every permission');
$check($service->addRole($syntheticRole, 42), 'Removed role could not be added back');
$check(!DB::table('client_contour_permissions')->where('role', $syntheticRole)
    ->where(static fn ($q) => $q->where('allowed', true)->orWhere('denied', true))->exists(),
    'Re-added role resurrected revoked grants or denies');
$check(!$service->removeRole('special:platform-admin', 42),
    'Platform administrator column must remain locked');
$check(isset($service->matrix()['special:platform-admin']['clients.view']),
    'Platform administrator access was lost');

echo "Clients canonical matrix data migration and API: PASS\\n";
