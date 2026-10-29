<?php

use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeReadController;
use App\Http\Controllers\EmployeeTransferController;
use App\Http\Controllers\EmployeesSystemController;
use Illuminate\Support\Facades\Route;

// API contracts and middleware remain unchanged; data access is in Eloquent services.
Route::get('/health', [EmployeesSystemController::class, 'health']);
Route::get('/reference-data', [EmployeesSystemController::class, 'reference']);
Route::get('/login-suggestions', [EmployeeReadController::class, 'loginSuggestions']);

Route::get('/departments', [DepartmentController::class, 'index']);
Route::post('/departments', [DepartmentController::class, 'store']);
Route::put('/departments/{department}', [DepartmentController::class, 'update']);

Route::get('/employees', [EmployeeReadController::class, 'index']);
Route::get('/employees/{employee}', [EmployeeReadController::class, 'show']);
Route::post('/employees', [EmployeeController::class, 'store']);
Route::post('/employees/{employee}/hire', [EmployeeController::class, 'hire']);
Route::patch('/employees/{employee}', [EmployeeController::class, 'update']);
Route::post('/employees/{employee}/dismiss', [EmployeeController::class, 'dismiss']);
Route::post('/employees/{employee}/rehire', [EmployeeController::class, 'rehire']);
Route::post('/employees/{employee}/change-cooperation', [EmployeeController::class, 'changeCooperation']);
Route::post('/employees/{employee}/transfer', [EmployeeTransferController::class, 'transfer']);
Route::put('/employees/{employee}/assignment-history/{assignment}', [EmployeeTransferController::class, 'updateHistory']);
Route::post('/employees/{employee}/salary-history', [EmployeeController::class, 'addSalary']);
Route::delete('/employees/{employee}/salary-history/{salary}', [EmployeeController::class, 'deleteSalary']);

// FOT and salary-review routes retain their historical /api prefix and middleware.
require __DIR__.'/fot.php';
