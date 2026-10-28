<?php

namespace App\Http\Controllers;

use App\Services\StoragePilotAccessService;
use App\Models\Employee;
use App\Support\BrandbookGate;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

final class StoragePilotGateController extends Controller
{
    public function __construct(private readonly StoragePilotAccessService $access) {}

    /**
     * Internal recipient eligibility check for administrator-triggered grants.
     * No employee details are returned, only a status; every call revalidates the
     * administrator's short-lived signed cookie.
     */
    public function recipient(Request $request, int $employee): Response
    {
        $context = $this->access->contextForCookie((string) $request->header('Cookie', ''));
        if ($context['status'] !== 204) {
            return response('', $context['status'], ['Cache-Control' => 'private, no-store']);
        }
        $target = Employee::query()->find($employee);
        $active = BrandbookGate::active($target);
        return response('', $active ? 204 : 404, ['Cache-Control' => 'private, no-store']);
    }

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
