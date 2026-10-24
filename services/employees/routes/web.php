<?php

use App\Http\Controllers\BrandbookGateController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'employees',
    'status' => 'ok',
]));

// nginx auth_request only; the gate rechecks the cookie against current Employees grants.
Route::get('/_brandbook_access', [BrandbookGateController::class, 'check']);
