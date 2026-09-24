<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/api/health', function () {
    DB::select('select 1');

    return response()->json([
        'service' => 'employees',
        'status' => 'ok',
        'database' => 'ok',
    ]);
});
