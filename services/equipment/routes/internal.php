<?php

use App\Http\Controllers\EmployeePurgeController;
use Illuminate\Support\Facades\Route;

Route::post('/employees/{employee}/purge', EmployeePurgeController::class)
    ->whereNumber('employee');
