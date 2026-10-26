<?php
namespace App\Http\Controllers;

use App\Services\AnalyticsTimesheetsService;
use App\Support\CurrentEmployee;
use Illuminate\Http\Request;

final class AnalyticsTimesheetsController
{
    public function __invoke(Request $request, CurrentEmployee $actor, AnalyticsTimesheetsService $service)
    {
        return response()->json(['data' => $service->report($request, $actor->resolve($request))]);
    }
}
