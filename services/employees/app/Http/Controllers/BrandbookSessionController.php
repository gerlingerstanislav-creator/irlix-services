<?php

namespace App\Http\Controllers;

use App\Services\BrandbookSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;

final class BrandbookSessionController extends Controller
{
    public function __construct(private readonly BrandbookSessionService $sessions) {}

    public function store(Request $request): JsonResponse
    {
        $access = (array) $request->attributes->get('employees_access', []);
        $identity = (array) $request->attributes->get('identity', []);
        if (!$this->sessions->isAllowedByAccess($access)) {
            return response()->json(['message' => 'Brandbook access requires top-management'], 403);
        }

        $employee = $this->sessions->verifiedEmployee($access, $identity);
        if (!$employee) return response()->json(['message' => 'Brandbook role is not assigned'], 403);

        $encrypted = Crypt::encryptString(json_encode([
            'employee_id' => (int) $employee->id,
            'subject' => (string) $identity['sub'],
            'expires_at' => time() + 300,
        ], JSON_THROW_ON_ERROR));
        $secure = $request->isSecure() || $request->header('X-Forwarded-Proto') === 'https';
        $cookie = cookie('irlix_brandbook', $encrypted, 5, '/brandbook/', null, $secure, true, false, 'lax');
        return response()->json(['data' => ['expires_in' => 300]])
            ->header('Cache-Control', 'private, no-store')
            ->cookie($cookie);
    }
}
