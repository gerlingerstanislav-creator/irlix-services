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
echo "Clients contour permissions routes: PASS\n";
