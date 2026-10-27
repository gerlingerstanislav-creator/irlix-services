<?php

namespace App\Http\Controllers;

use App\Services\StoragePilotAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;

final class StoragePilotSessionController extends Controller
{
    public function __construct(private readonly StoragePilotAccessService $access) {}

    public function store(Request $request): JsonResponse
    {
        $grants = (array) $request->attributes->get('employees_access', []);
        $identity = (array) $request->attributes->get('identity', []);
        $employee = $this->access->verifiedEmployee($grants, $identity);
        if (!$employee) return response()->json(['message'=>'platform-admin required'],403);

        $encrypted = Crypt::encryptString(json_encode([
            'employee_id'=>(int)$employee->id,
            'subject'=>(string)$identity['sub'],
            'expires_at'=>time()+300,
        ], JSON_THROW_ON_ERROR));
        $secure = $request->isSecure() || $request->header('X-Forwarded-Proto') === 'https';
        // Must cover port 8110 on the *same hostname*. HttpOnly and short lifetime.
        $cookie = cookie('irlix_storage_pilot', $encrypted, 5, '/', null, $secure, true, false, 'lax');
        return response()->json(['data'=>['expires_in'=>300]])
            ->header('Cache-Control','private, no-store')->cookie($cookie);
    }
}
