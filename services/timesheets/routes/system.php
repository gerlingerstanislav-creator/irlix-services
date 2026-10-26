<?php

use App\Http\Controllers\CommercialDataController;
use App\Http\Controllers\EmployeePurgeController;
use App\Http\Controllers\ReportingFreezeController;
use Illuminate\Support\Facades\Route;

Route::post('/employees/{employee}/purge', EmployeePurgeController::class);
Route::get('/finance/commercial-data', [CommercialDataController::class, 'finance']);
Route::post('/reporting-freezes', [ReportingFreezeController::class, 'createLegacy']);
Route::delete('/reporting-freezes/{period}', [ReportingFreezeController::class, 'release']);
Route::post('/reporting-freezes-v2', [ReportingFreezeController::class, 'createCanonical']);
