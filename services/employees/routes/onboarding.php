<?php

use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\EmployeeIdentityProvisionController;
use Illuminate\Support\Facades\Route;

Route::post('/employees/{employee}/onboarding-email', [OnboardingController::class, 'resend']);
Route::post('/employees/{employee}/keycloak-provision', [EmployeeIdentityProvisionController::class, 'provision']);
