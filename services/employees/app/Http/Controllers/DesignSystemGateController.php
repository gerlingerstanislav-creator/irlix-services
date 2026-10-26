<?php

namespace App\Http\Controllers;

use App\Services\DesignSystemAccessService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

final class DesignSystemGateController extends Controller
{
    public function __construct(private readonly DesignSystemAccessService $access) {}

    public function check(Request $request): Response
    {
        return response('', $this->access->statusForCookie((string) $request->header('Cookie', '')),
            ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie']);
    }
}
