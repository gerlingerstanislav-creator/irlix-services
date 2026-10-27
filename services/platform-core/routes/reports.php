<?php

use App\Http\Controllers\BugReportController;
use Illuminate\Support\Facades\Route;

Route::post('/reports', [BugReportController::class, 'store'])->middleware('throttle:15,1');
Route::get('/reports', [BugReportController::class, 'index']);
Route::patch('/reports/status', [BugReportController::class, 'updateStatus']);
Route::get('/reports/export', [BugReportController::class, 'export']);
