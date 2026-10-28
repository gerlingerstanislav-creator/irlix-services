<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\BrandbookGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;

/**
 * Storage V1 issues a different short-lived cookie from the legacy admin pilot.
 * Only an authenticated active employee with Storage service access can enter.
 */
final class StorageV1SessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $access = (array) $request->attributes->get('employees_access', []);
        $identity = (array) $request->attributes->get('identity', []);
        $id = (int) ($access['employee_id'] ?? 0);
        $subject = (string) ($identity['sub'] ?? '');
        if ($id <= 0 || $subject === '' || ($access['services']['storage'] ?? false) !== true) {
            return response()->json(['message' => 'Storage access denied'], 403);
        }
        $employee = Employee::query()->whereKey($id)
            ->where('keycloak_user_id', $subject)->first();
        if (!BrandbookGate::active($employee)) {
            return response()->json(['message' => 'Inactive employee'], 403);
        }
        $token = Crypt::encryptString(json_encode([
            'employee_id' => (int) $employee->id,
            'subject' => $subject,
            'expires_at' => time() + 300,
        ], JSON_THROW_ON_ERROR));
        $secure = $request->isSecure() || $request->header('X-Forwarded-Proto') === 'https';
        return response()->json(['data' => ['expires_in' => 300]])
            ->header('Cache-Control', 'private, no-store')
            ->cookie(cookie('irlix_storage_v1', $token, 5, '/', null, $secure, true, false, 'lax'));
    }
}
