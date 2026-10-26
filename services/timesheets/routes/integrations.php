<?php

use App\Http\Controllers\CashFlowFactsController;
use App\Http\Controllers\CommercialDataController;
use Illuminate\Support\Facades\Route;

Route::get('/commercial-data', [CommercialDataController::class, 'clients']);
Route::get('/cash-flow-facts', CashFlowFactsController::class);
