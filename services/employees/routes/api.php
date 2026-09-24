<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    DB::select('select 1');

    return response()->json([
        'service' => 'employees',
        'status' => 'ok',
        'database' => 'ok',
    ]);
});

Route::get('/employees', function () {
    return response()->json([
        'data' => [],
        'meta' => [
            'service' => 'employees',
            'stage' => 'iteration-1-scaffold',
        ],
    ]);
});
