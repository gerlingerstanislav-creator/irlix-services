<?php

use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::get('/staff-positions', [OrganizationController::class, 'index']);
Route::post('/staff-positions', [OrganizationController::class, 'store']);
Route::put('/staff-positions/{position}', [OrganizationController::class, 'update']);
Route::post('/staff-positions/import', [OrganizationController::class, 'import']);
Route::post('/staff-positions/{position}/close', [OrganizationController::class, 'close']);
Route::post('/staff-positions/{position}/reopen', [OrganizationController::class, 'reopen']);
Route::delete('/staff-positions/{position}', [OrganizationController::class, 'destroyPosition']);
Route::delete('/departments/{department}', [OrganizationController::class, 'destroyDepartment']);
