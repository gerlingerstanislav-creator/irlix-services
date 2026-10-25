<?php

use App\Http\Controllers\InternalPurgeController;
use Illuminate\Support\Facades\Route;

Route::post('/employees/{employee}/purge', [InternalPurgeController::class, 'employee']);
