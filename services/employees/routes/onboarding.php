<?php

use App\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;

Route::post('/employees/{employee}/onboarding-email', [OnboardingController::class, 'resend']);
