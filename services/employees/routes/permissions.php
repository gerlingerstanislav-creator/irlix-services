<?php

use App\Http\Controllers\PermissionRuleController;
use Illuminate\Support\Facades\Route;

Route::get('/access/permissions', [PermissionRuleController::class, 'index']);
Route::put('/access/permissions/{role}/{permission}', [PermissionRuleController::class, 'update']);
