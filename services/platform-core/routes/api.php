<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require __DIR__.'/resources.php';

Route::get('/production-calendar', function (\Illuminate\Http\Request $request, \App\Calendar\ProductionCalendar $calendar) {
    $data=$request->validate(['year'=>['required','integer','min:2000','max:2100']]);
    return response()->json(['data'=>$calendar->year((int)$data['year'])]);
});

Route::get('/health', function () {
    DB::select('select 1');

    return response()->json([
        'service' => 'platform-core',
        'status' => 'ok',
        'database' => 'ok',
    ]);
});
