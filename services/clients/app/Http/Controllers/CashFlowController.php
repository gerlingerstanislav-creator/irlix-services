<?php

namespace App\Http\Controllers;

use App\Services\CashFlowReportService;
use Illuminate\Http\Request;

class CashFlowController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        return response()->json(['data' => app(CashFlowReportService::class)->report($request, $data['month'])]);
    }
}
