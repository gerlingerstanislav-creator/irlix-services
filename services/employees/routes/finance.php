<?php

use App\Http\Controllers\FinanceDirectoryController;
use Illuminate\Support\Facades\Route;

// Internal token is checked before any sensitive query is executed.
Route::get('/finance/payroll-conditions', [FinanceDirectoryController::class, 'payrollConditions']);
Route::get('/finance/employees/{employee}', [FinanceDirectoryController::class, 'showEmployee'])
    ->whereNumber('employee');
