<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('api', [\App\Http\Middleware\RestoreMaintenance::class]);
        // In JWT mode the migration endpoints validate Keycloak JWT locally.
        // Legacy Employees request-time authorization remains behind a guarded toggle.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API routes return explicit JSON errors at their boundary.
    })->create();
