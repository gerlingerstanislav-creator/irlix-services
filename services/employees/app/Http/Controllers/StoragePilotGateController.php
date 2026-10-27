<?php

namespace App\Http\Controllers;

use App\Services\StoragePilotAccessService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

final class StoragePilotGateController extends Controller
{
    public function __construct(private readonly StoragePilotAccessService $access) {}

    public function check(Request $request): Response
    {
        return response('', $this->access->statusForCookie((string) $request->header('Cookie', '')),
            ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie']);
    }
}
