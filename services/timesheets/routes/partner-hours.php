<?php

use App\Http\Controllers\PartnerHoursController;
use Illuminate\Support\Facades\Route;

Route::get('/partner-hours', [PartnerHoursController::class, 'calendar']);
Route::put('/partner-hours', [PartnerHoursController::class, 'write']);
Route::post('/partner-hours/confirm', [PartnerHoursController::class, 'confirm']);
Route::post('/partner-hours/final-approval', [PartnerHoursController::class, 'finalApproval']);
