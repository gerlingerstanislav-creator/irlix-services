<?php

use App\Support\CommercialData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/commercial-data', function (Request $request, CommercialData $commercial) {
    $expected=(string)env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN','');
    $provided=(string)$request->header('X-Irlix-Timesheets-Token','');
    abort_unless(strlen($expected)>=32 && $provided!=='' && hash_equals($expected,$provided),403,'Invalid reporting integration token');
    $data = $request->validate([
        'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        'client_id' => ['nullable', 'integer', 'min:1'], 'freeze_token' => ['nullable', 'string', 'size:64'],
    ]);
    return response()->json(['data' => $commercial->read($data['from'], $data['to'], isset($data['client_id']) ? (int) $data['client_id'] : null, $data['freeze_token'] ?? null)]);
});

// Clients-owned cash-flow calculations consume facts without a reverse request
// to Clients. Reuses the existing Keycloak and integration-token boundaries.
Route::get('/cash-flow-facts', \App\Http\Controllers\CashFlowFactsController::class);
