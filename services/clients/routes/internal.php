<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;


// Trusted Finance integration only. Token verified in FinanceClientsController.
Route::get('/finance/client-projects', [\App\Http\Controllers\FinanceClientsController::class, 'projects']);
Route::get('/finance/direct-cost-entity', [\App\Http\Controllers\FinanceClientsController::class, 'directCostEntity']);
Route::get('/finance/reporting-periods', [\App\Http\Controllers\FinanceClientsController::class, 'periods']);
Route::get('/finance/commercial-terms', [\App\Http\Controllers\FinanceClientsController::class, 'terms']);
Route::get('/finance/reporting-periods/{period}', [\App\Http\Controllers\FinanceClientsController::class, 'period'])->whereNumber('period');
Route::post('/finance/reporting-periods/{period}/paid', [\App\Http\Controllers\FinanceClientsController::class, 'paid'])->whereNumber('period');

Route::post('/employees/{employee}/purge', \App\Http\Controllers\EmployeePurgeController::class)
    ->whereNumber('employee');

/**
 * Authoritative complete client-level membership directory for Timesheets v2.
 * Keep the old project-based endpoint during a staged expand/contract rollout.
 */
Route::get('/timesheet-memberships', \App\Http\Controllers\TimesheetMembershipsController::class);
