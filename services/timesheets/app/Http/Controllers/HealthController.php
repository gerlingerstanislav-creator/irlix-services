<?php
namespace App\Http\Controllers;

use App\Services\HealthStatusService;

final class HealthController
{
    public function __invoke(HealthStatusService $service)
    {
        return response()->json($service->status());
    }
}
