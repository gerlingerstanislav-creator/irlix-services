<?php

namespace App\Http\Controllers;

use App\Services\CashFlowReportService;
use Illuminate\Http\Request;

class CashFlowController extends Controller
{
    public function index(Request $request, CashFlowReportService $service)
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        return response()->json(['data' => $service->report($request, $data['month'])]);
    }
}
