<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require __DIR__.'/resources.php';

Route::get('/health', function () {
    DB::select('select 1');

    return response()->json([
        'service' => 'platform-core',
        'status' => 'ok',
        'database' => 'ok',
    ]);
});
