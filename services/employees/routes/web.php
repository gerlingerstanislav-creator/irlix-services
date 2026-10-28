<?php

use App\Http\Controllers\BrandbookGateController;
use App\Http\Controllers\StoragePilotGateController;
use App\Http\Controllers\StorageV1GateController;
use App\Http\Controllers\DesignSystemGateController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'employees',
    'status' => 'ok',
]));

// nginx auth_request only; the gate rechecks the cookie against current Employees grants.
Route::get('/_brandbook_access', [BrandbookGateController::class, 'check']);
Route::get('/_design_system_access', [DesignSystemGateController::class, 'check']);

Route::get('/_storage_pilot_access', [StoragePilotGateController::class, 'check']);
Route::get('/_storage_pilot_recipient/{employee}', [StoragePilotGateController::class, 'recipient'])->whereNumber('employee');

Route::get('/_storage_v1_access', [StorageV1GateController::class, 'check']);
Route::get('/_storage_v1_recipient/{employee}', [StorageV1GateController::class, 'recipient'])->whereNumber('employee');
