<?php

use App\Http\Controllers\SalaryReviewController;
use Illuminate\Support\Facades\Route;

Route::get('/fot/overview', [SalaryReviewController::class, 'overview']);
Route::get('/fot/reviews', [SalaryReviewController::class, 'index']);
Route::get('/fot/inbox', [SalaryReviewController::class, 'inbox']);
Route::post('/fot/reviews', [SalaryReviewController::class, 'store']);
Route::patch('/fot/reviews/{review}', [SalaryReviewController::class, 'update']);
Route::post('/fot/reviews/{review}/submit', [SalaryReviewController::class, 'submit']);
Route::post('/fot/reviews/{review}/decision', [SalaryReviewController::class, 'decision']);
Route::post('/fot/reviews/{review}/cancel', [SalaryReviewController::class, 'cancel']);
Route::post('/employees/{employee}/transfer', [SalaryReviewController::class, 'transfer']);
