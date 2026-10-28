<?php

use App\Http\Controllers\AnalyticsTimesheetsController;
use App\Http\Controllers\AuditTrailController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ManagementTimesheetsController;
use App\Http\Controllers\PersonalTimesheetsController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TimesheetsPermissionsController;

Route::get('/health', HealthController::class);
Route::get('/permissions', [TimesheetsPermissionsController::class, 'index']);
Route::put('/permissions', [TimesheetsPermissionsController::class, 'update']);
Route::get('/workspace', [PersonalTimesheetsController::class, 'workspace']);
Route::put('/entries', [PersonalTimesheetsController::class, 'write']);
Route::post('/confirm', [PersonalTimesheetsController::class, 'confirm']);
Route::post('/unconfirm', [PersonalTimesheetsController::class, 'unconfirm']);

Route::get('/management', [ManagementTimesheetsController::class, 'month']);
Route::put('/management/entries', [ManagementTimesheetsController::class, 'write']);
Route::put('/management/bulk-hours', [ManagementTimesheetsController::class, 'bulkHours']);
Route::post('/management/final-approval', [ManagementTimesheetsController::class, 'finalApproval']);

Route::get('/analytics', AnalyticsTimesheetsController::class);
Route::get('/audit', AuditTrailController::class);

require __DIR__.'/attention.php';
require __DIR__.'/partner-hours.php';
