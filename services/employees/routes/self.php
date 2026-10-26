<?php

use App\Http\Controllers\EmployeeDirectoryController;
use Illuminate\Support\Facades\Route;

Route::get('/access/preview', [EmployeeDirectoryController::class, 'preview']);
Route::get('/self', [EmployeeDirectoryController::class, 'self']);
Route::get('/equipment-directory', [EmployeeDirectoryController::class, 'equipment']);
Route::get('/equipment-directory/{employee}', [EmployeeDirectoryController::class, 'equipment'])
    ->whereNumber('employee');
Route::get('/clients-directory', [EmployeeDirectoryController::class, 'clients']);
Route::get('/client-specialist-profile/{employee}', [EmployeeDirectoryController::class, 'clientSpecialistProfile'])
    ->whereNumber('employee');
Route::get('/vacations-directory', [EmployeeDirectoryController::class, 'vacations']);
Route::get('/specialists-directory', [EmployeeDirectoryController::class, 'specialists']);
Route::get('/self/absence-approval-context', [EmployeeDirectoryController::class, 'selfAbsence']);
Route::get('/absence-approval-context/{employee}', [EmployeeDirectoryController::class, 'absence']);
