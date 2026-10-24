<?php

use App\Http\Controllers\EmployeeDeletionController;
use Illuminate\Support\Facades\Route;

Route::delete('/employees/{employee}', [EmployeeDeletionController::class, 'destroy']);
