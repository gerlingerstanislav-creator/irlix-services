<?php

use App\Http\Controllers\AccessRoleController;
use App\Http\Controllers\BrandbookSessionController;
use App\Http\Controllers\DesignSystemSessionController;
use Illuminate\Support\Facades\Route;

Route::get('/access/me', [AccessRoleController::class, 'me']);
Route::get('/access/roles', [AccessRoleController::class, 'index']);
Route::put('/access/roles/{role}/{employee}', [AccessRoleController::class, 'assign']);
Route::delete('/access/roles/{role}/{employee}', [AccessRoleController::class, 'revoke']);

// Cookie creation stays guarded by the current Keycloak identity and Employees role assignment.
Route::post('/brandbook/session', [BrandbookSessionController::class, 'store']);
Route::post('/design-system/session', [DesignSystemSessionController::class, 'store']);
