<?php

/** Synthetic HTTP-middleware policy rehearsal; never contacts live identity/Employees. */
require dirname(__DIR__).'/vendor/autoload.php';

use App\Http\Middleware\ServiceEntitlement;
use App\Models\SpecialistsRolePermission;
use App\Support\SpecialistsCatalogAuthorizer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

foreach (['IRLIX_AUTHZ_JWT_ENABLED'=>'true','IRLIX_SPECIALISTS_LOCAL_AUTHZ_ENABLED'=>'true'] as $name=>$value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:']);
DB::purge('sqlite');
Schema::create('specialists_role_permissions', static function (Blueprint $table): void {
    $table->id();
    $table->string('role');
    $table->string('permission');
    $table->boolean('allowed');
    $table->string('scope');
    $table->timestamps();
});
SpecialistsRolePermission::query()->create([
    'role'=>'org:profiles','permission'=>'specialists.profiles.manage','allowed'=>true,'scope'=>'team',
]);
SpecialistsRolePermission::query()->create([
    'role'=>'org:catalog','permission'=>'specialists.catalog.manage','allowed'=>true,'scope'=>'all',
]);

// The synthetic Employees projection allows checking both auth layers with
// a signed employee identity; no real entitlement or JWT is altered.
Http::fake(['*'=>Http::response(['data'=>[
    'employee_id'=>77,
    'services'=>['specialists'=>true],
    'roles'=>['platform-admin'],
    'role_claims'=>['org:profiles','org:catalog'],
]],200)]);
$guard = new ServiceEntitlement();
$check = static function (bool $value,string $reason): void {
    if (!$value) throw new RuntimeException($reason);
};
$make = static function (string $method,string $path,array $roles): Request {
    $request=Request::create($path,$method,[],[],[],['HTTP_X_IRLIX_ACCESS_TOKEN'=>'synthetic-auth']);
    $request->attributes->set('identity',['realm_roles'=>array_merge(['employee-id:77','service:specialists'],$roles)]);
    return $request;
};
$pass = static fn (Request $request) => response()->json([
    'data'=>$request->attributes->get('specialists_local_permissions'),
]);
$path='/api/catalog/additional-technologies/15';

$platformAdmin=$make('DELETE',$path,['platform-admin']);
$adminResult=$guard->handle($platformAdmin,$pass);
$check($adminResult->getStatusCode()===200
    && $platformAdmin->attributes->get('specialists_local_permissions')['specialists.catalog.manage']['scope']==='all',
    'Platform admin should reach the new DELETE controller with an all-scope catalog grant');
$catalogAdmin=$make('DELETE',$path,['org:catalog']);
$catalogResult=$guard->handle($catalogAdmin,$pass);
$check($catalogResult->getStatusCode()===200,
    'Explicit all-scope catalog manager should reach the new DELETE controller');
$authorizer=app(SpecialistsCatalogAuthorizer::class);
$check(is_array($authorizer->assertCanManage($catalogAdmin)),
    'Catalog authorizer rejected a caller admitted by the middleware');
$check($guard->handle($make('DELETE',$path,['org:profiles']),$pass)->getStatusCode()===403,
    'Profile edit grants must never authorize a global catalog deletion');
$check($guard->handle($make('DELETE',$path,[]),$pass)->getStatusCode()===403,
    'Service admission alone must never authorize global catalog deletion');
$check($guard->handle($make('GET',$path,['org:catalog']),$pass)->getStatusCode()===403,
    'DELETE permission must not admit an unrelated GET endpoint');
$check($guard->handle($make('POST',$path,['org:catalog']),$pass)->getStatusCode()===403,
    'DELETE permission must not admit an unrelated POST endpoint');
$preview=$make('DELETE',$path,['platform-admin']);
$preview->headers->set('X-Irlix-Role-Preview','org:catalog');
$check($guard->handle($preview,$pass)->getStatusCode()===403,
    'Role preview must never authorize destructive catalog changes');

echo "Specialists additional delete route: admin, catalog grant, profile-only deny, preview deny PASS\n";
