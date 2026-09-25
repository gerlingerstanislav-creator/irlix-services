<?php

use App\Http\Middleware\EmployeeMutationJournal;
use App\Http\Middleware\EmployeeOnboarding;
use App\Http\Middleware\EmployeesAuthorization;
use App\Http\Middleware\KeycloakBearer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')->prefix('api')->group(base_path('routes/self.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/access.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/audit.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/onboarding.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/delete.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [KeycloakBearer::class, EmployeesAuthorization::class, EmployeeMutationJournal::class, EmployeeOnboarding::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Service exception handling will be extended with structured API errors.
    })->create();
