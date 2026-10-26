<?php
namespace App\Http\Controllers;

use App\Services\AuditTrailService;
use App\Support\CurrentEmployee;
use Illuminate\Http\Request;

final class AuditTrailController
{
    public function __invoke(Request $request, CurrentEmployee $actor, AuditTrailService $service)
    {
        return response()->json(['data' => $service->history($request, $actor->resolve($request))]);
    }
}
