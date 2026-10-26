<?php

namespace App\Http\Controllers;

use App\Services\ClientsHealthService;

final class ClientsHealthController extends Controller
{
    public function __invoke()
    {
        return response()->json(app(ClientsHealthService::class)->status());
    }
}
