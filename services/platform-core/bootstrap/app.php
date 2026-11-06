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
        then: static function (): void {
            \Illuminate\Support\Facades\Route::prefix('internal/system')
                ->group(base_path('routes/internal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Platform middleware is added as authentication/permissions are implemented.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Exception handling will be extended with structured platform errors.
    })->create();
