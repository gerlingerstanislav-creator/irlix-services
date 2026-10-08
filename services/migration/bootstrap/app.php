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
        // Authorization for the temporary migration API is resolved against Employees on every
        // request. No browser session or local role cache is trusted here.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API routes return explicit JSON errors at their boundary.
    })->create();
