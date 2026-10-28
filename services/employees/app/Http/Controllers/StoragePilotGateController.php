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
        $context = $this->access->contextForCookie((string) $request->header('Cookie', ''));
        $headers = ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie'];
        // Only the server-to-server 204 response carries the verified identity.
        if ($context['status'] === 204) {
            $headers['X-Storage-Employee-ID'] = (string) $context['employee_id'];
            $headers['X-Storage-Platform-Admin'] = '1';
        }
        return response('', $context['status'], $headers);
    }
}
