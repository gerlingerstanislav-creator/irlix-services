<?php

use App\Http\Controllers\ClientsController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['service'=>'clients','status'=>'ok','database'=>DB::select('select 1')?'ok':'error']));
Route::get('/overview', [ClientsController::class, 'overview']);
Route::post('/clients', [ClientsController::class, 'storeClient']);
Route::post('/leads', [ClientsController::class, 'storeLead']);
Route::post('/projects/{project}/members', [ClientsController::class, 'storeMember'])->whereNumber('project');
Route::post('/members/{member}/terms', [ClientsController::class, 'storeTerms'])->whereNumber('member');
Route::patch('/members/{member}/project', [ClientsController::class, 'moveMember'])->whereNumber('member');
