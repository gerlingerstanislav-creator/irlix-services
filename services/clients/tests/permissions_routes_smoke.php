<?php

// Regression: both Clients and Timesheets must be able to retrieve the Clients
// contour permissions. Exercise the actual Laravel route collection rather
// than asserting textual fragments in routes/api.php.
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
    if ($route->getActionName() !== $controller.'@'.$action) {
        throw new \RuntimeException("Wrong {$method} {$path} handler: ".$route->getActionName());
    }
    if (!in_array('api', $route->gatherMiddleware(), true)) {
        throw new \RuntimeException("Missing authenticated API middleware for {$method} {$path}");
    }
}

// A Timesheets-only request must not expose Clients capabilities,
// employee directory metadata or other Clients service information.
$request = \Illuminate\Http\Request::create('/api/permissions/me', 'GET');
$request->attributes->set('clients_timesheets_only', true);
$request->attributes->set('client_contour_access', [
    'permissions'=>[
        'timesheets.mine.view'=>['allowed'=>true,'scope'=>'own'],
        'clients.view'=>['allowed'=>true,'scope'=>'all'],
    ],
    'employee'=>['id'=>112], 'roles'=>['account-manager'],
    'legacy_timesheets_implicit_mine_view'=>true,
]);
$response = (new $controller())->me($request, app(\App\Support\ClientContourAccess::class));
$data = json_decode($response->getContent(), true);
if (($data['data']['permissions']['timesheets.mine.view']['scope'] ?? null) !== 'own'
    || isset($data['data']['permissions']['clients.view'])
    || ($data['data']['legacy_timesheets_implicit_mine_view'] ?? null) !== true
    || isset($data['data']['employee']) || isset($data['data']['roles'])) {
    throw new RuntimeException('Timesheets-only permission projection leaks Clients data');
}
// An empty legacy row and an explicitly unassigned/denied row are not
// interchangeable. The source resolver must not create extra grants.
$historical = \App\Support\ClientContourAccess::class;
if (!$historical::historicalPersonalTimesheetsViewWasImplicit(false, false)
    || $historical::historicalPersonalTimesheetsViewWasImplicit(true, false)
    || $historical::historicalPersonalTimesheetsViewWasImplicit(false, true)
    || $historical::historicalPersonalTimesheetsViewWasImplicit(true, true)) {
    throw new RuntimeException('Implicit personal Timesheets baseline ignored legacy veto');
}

// Exercise the real Clients admission middleware with a controlled Employees
// response and a synthetic policy resolver. No real employee or service data.
// A Timesheets actor can be admitted to Employees Clients entry yet have no
// Clients business grants; their policy ceiling must still be readable.
$oldJwt = getenv('IRLIX_AUTHZ_JWT_ENABLED');
$oldLocal = getenv('IRLIX_CLIENTS_LOCAL_AUTHZ_ENABLED');
putenv('IRLIX_AUTHZ_JWT_ENABLED=true');
putenv('IRLIX_CLIENTS_LOCAL_AUTHZ_ENABLED=true');

$syntheticServices = ['clients'=>true, 'timesheets'=>true];
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Http::fake(
    static function ($req) use (&$syntheticServices) {
        if (str_ends_with((string)$req->url(), '/access/me')) {
            return \Illuminate\Support\Facades\Http::response(
                ['data'=>['services'=>$syntheticServices]], 200);
        }
        return \Illuminate\Support\Facades\Http::response([], 404);
    }
);
$fakeAccess = new class extends \App\Support\ClientContourAccess {
    public array $permissions = [
        'timesheets.mine.view'=>['allowed'=>true,'scope'=>'own'],
    ];
    public function resolve(\Illuminate\Http\Request $request): array {
        return [
            'platform_admin'=>false,
            'permissions'=>$this->permissions,
            'employee'=>['id'=>42],
            'roles'=>['employee'],
            'legacy_timesheets_implicit_mine_view'=>true,
        ];
    }
};
app()->instance(\App\Support\ClientContourAccess::class, $fakeAccess);
$gate = new \App\Http\Middleware\ServiceEntitlement();
$checkAdmission = static function (string $path) use ($gate): array {
    $req = \Illuminate\Http\Request::create($path, 'GET');
    $req->headers->set('Authorization', 'Bearer already-verified-synthetic-jwt');
    $response = $gate->handle($req,
        static function ($allowed) {
            return (new \App\Http\Controllers\ClientContourPermissionsController())->me(
                $allowed, app(\App\Support\ClientContourAccess::class));
        });
    return [$response->getStatusCode(), json_decode((string)$response->getContent(), true)];
};

[$admitted, $policy] = $checkAdmission('/api/permissions/me');
if ($admitted !== 200
    || ($policy['data']['permissions']['timesheets.mine.view']['scope'] ?? null) !== 'own'
    || isset($policy['data']['employee']) || isset($policy['data']['roles'])) {
    throw new RuntimeException('Clients entry without domain grants blocked/leaked Timesheets ceiling');
}
if ($checkAdmission('/api/clients')[0] !== 403) {
    throw new RuntimeException('Timesheets policy consumer entered Clients business API');
}
$syntheticServices['timesheets'] = false;
if ($checkAdmission('/api/permissions/me')[0] !== 403) {
    throw new RuntimeException('Clients entry without grants or Timesheets entitlement was admitted');
}
$syntheticServices = ['clients'=>false, 'timesheets'=>true];
if ($checkAdmission('/api/permissions/me')[0] !== 200) {
    throw new RuntimeException('Timesheets-only service entry lost its private policy ceiling');
}
$syntheticServices = ['clients'=>true, 'timesheets'=>true];
$fakeAccess->permissions['clients.view'] = ['allowed'=>true,'scope'=>'own'];
[$admittedFull, $fullPolicy] = $checkAdmission('/api/permissions/me');
if ($admittedFull !== 200 || !isset($fullPolicy['data']['employee'])
    || !isset($fullPolicy['data']['permissions']['clients.view'])) {
    throw new RuntimeException('Clients-authorized actor lost its full own policy view');
}
if ($oldJwt === false) putenv('IRLIX_AUTHZ_JWT_ENABLED');
else putenv('IRLIX_AUTHZ_JWT_ENABLED='.$oldJwt);
if ($oldLocal === false) putenv('IRLIX_CLIENTS_LOCAL_AUTHZ_ENABLED');
else putenv('IRLIX_CLIENTS_LOCAL_AUTHZ_ENABLED='.$oldLocal);

echo "Clients contour permissions routes: PASS\n";
