<?php

// Clients V2 has a SINGLE canonical role matrix. Timesheets owns its own
// permissions. The Employees access API must not be called for local admission.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$controller = \App\Http\Controllers\ClientContourPermissionsController::class;
foreach ([
    ['GET', '/api/permissions/me', 'me'],
    ['GET', '/api/permissions', 'index'],
    ['PUT', '/api/permissions', 'update'],
] as [$method, $path, $action]) {
    $route = $app['router']->getRoutes()->match(\Illuminate\Http\Request::create($path, $method));
    if ($route->getActionName() !== $controller.'@'.$action
        || !in_array('api', $route->gatherMiddleware(), true)) {
        throw new \RuntimeException('Clients permissions routes lost auth middleware');
    }
}

if (array_filter(array_keys(\App\Support\ClientContourAccess::PERMISSION_LABELS),
    static fn ($key) => str_starts_with($key, 'timesheets.'))) {
    throw new \RuntimeException('Clients V2 still advertises Timesheets permissions');
}

$oldJwt=getenv('IRLIX_AUTHZ_JWT_ENABLED');
$oldLocal=getenv('IRLIX_CLIENTS_LOCAL_AUTHZ_ENABLED');
putenv('IRLIX_AUTHZ_JWT_ENABLED=true');
putenv('IRLIX_CLIENTS_LOCAL_AUTHZ_ENABLED=true');
\Illuminate\Support\Facades\Http::preventStrayRequests();
$fakeAccess = new class extends \App\Support\ClientContourAccess {
    public array $permissions = ['clients.view'=>['allowed'=>true,'scope'=>'own']];
    public bool $admin = false;
    public function resolve(\Illuminate\Http\Request $request): array {
        return [
            'platform_admin'=>$this->admin,
            'permissions'=>$this->permissions,
            'employee'=>['id'=>42],
            'roles'=>['org:client-service'],
        ];
    }
};
app()->instance(\App\Support\ClientContourAccess::class, $fakeAccess);
$gate = new \App\Http\Middleware\ServiceEntitlement();
$check = static function (array $roles, string $path='/api/clients') use ($gate): int {
    $req=\Illuminate\Http\Request::create($path, 'GET');
    $req->headers->set('Authorization', 'Bearer signed-upstream-test-token');
    // The real KeycloakBearer middleware authenticates and binds these claims.
    $req->attributes->set('identity',['realm_roles'=>$roles]);
    $response=$gate->handle($req, static fn ($req) =>
        response()->json(['data'=>['permissions'=>$req->attributes
            ->get('client_contour_access')['permissions'] ?? []]],200));
    return $response->getStatusCode();
};

if ($check(['service:clients','org:client-service'])!==200)
    throw new \RuntimeException('Signed Keycloak Clients service entry was denied');
if ($check(['service:timesheets','org:client-service'])!==403)
    throw new \RuntimeException('Timesheets admission leaked into Clients');
if ($check(['org:client-service'])!==403)
    throw new \RuntimeException('Missing signed Clients admission was accepted');
$fakeAccess->permissions=[];
if ($check(['service:clients','org:client-service'])!==403)
    throw new \RuntimeException('Clients V2 business access bypassed empty local matrix');
$fakeAccess->admin=true;
if ($check(['service:clients','special:platform-admin'])!==200)
    throw new \RuntimeException('Platform administrator lost Clients entry');

if ($oldJwt===false) putenv('IRLIX_AUTHZ_JWT_ENABLED');
else putenv('IRLIX_AUTHZ_JWT_ENABLED='.$oldJwt);
if ($oldLocal===false) putenv('IRLIX_CLIENTS_LOCAL_AUTHZ_ENABLED');
else putenv('IRLIX_CLIENTS_LOCAL_AUTHZ_ENABLED='.$oldLocal);
echo "Clients canonical V2 permissions and signed admission: PASS\n";
