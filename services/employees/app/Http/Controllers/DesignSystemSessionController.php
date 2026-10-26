<?php

namespace App\Http\Controllers;

use App\Services\DesignSystemSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;

final class DesignSystemSessionController extends Controller
{
    public function __construct(private readonly DesignSystemSessionService $sessions) {}

    public function store(Request $request): JsonResponse
    {
        $access = (array) $request->attributes->get('employees_access', []);
        $identity = (array) $request->attributes->get('identity', []);
        $employee = $this->sessions->verifiedEmployee($access, $identity);
        if (!$employee) return response()->json(['message' => 'Active corporate identity required'], 403);

        $encrypted = Crypt::encryptString(json_encode([
            'employee_id' => (int) $employee->id,
            'subject' => (string) $identity['sub'],
            'expires_at' => time() + 300,
        ], JSON_THROW_ON_ERROR));
        $secure = $request->isSecure() || $request->header('X-Forwarded-Proto') === 'https';
        $cookie = cookie('irlix_design_system', $encrypted, 5, '/design-system/', null, $secure, true, false, 'lax');
        return response()->json(['data' => ['expires_in' => 300]])
            ->header('Cache-Control', 'private, no-store')
            ->cookie($cookie);
    }
}
