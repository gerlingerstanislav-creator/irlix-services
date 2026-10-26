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

echo "Clients contour permissions routes: PASS\n";
