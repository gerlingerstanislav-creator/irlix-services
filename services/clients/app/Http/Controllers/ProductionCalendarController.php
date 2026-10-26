<?php

namespace App\Http\Controllers;

use App\Services\ProductionCalendarService;
use Illuminate\Http\Request;

final class ProductionCalendarController extends Controller
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']]);
        $result = app(ProductionCalendarService::class)->fetch((int) $validated['year'], $request->bearerToken());
        return response()->json($result, array_key_exists('data', $result) ? 200 : 503);
    }
}
