<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'employees',
    'status' => 'ok',
]));

// Only the host's nginx auth_request location routes to this path. It never
// trusts browser-provided role names or the caller's Authorization header.
Route::get('/_brandbook_access', function (\Illuminate\Http\Request $request) {
    $headers = ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie'];
    $header = (string) $request->header('Cookie', '');
    if (!preg_match('/(?:^|;\\s*)irlix_brandbook=([^;]*)/', $header, $match)) {
        return response('', 401, $headers);
    }
    try {
        $decoded = \Illuminate\Support\Facades\Crypt::decryptString(rawurldecode($match[1]));
        $session = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
    } catch (\Throwable) {
        return response('', 401, $headers);
    }
    if (!\App\Support\BrandbookGate::validSession($session)) return response('', 401, $headers);
    try {
        $employee = \Illuminate\Support\Facades\DB::table('employees')
            ->where('id', (int) $session['employee_id'])
            ->where('keycloak_user_id', (string) $session['subject'])->first();
        if (!\App\Support\BrandbookGate::active($employee)) return response('', 403, $headers);
        $roles = \Illuminate\Support\Facades\DB::table('employee_access_roles')
            ->where('employee_id', $employee->id)->pluck('role')->all();
        if (!\App\Support\BrandbookGate::hasRole($roles)) return response('', 403, $headers);
        return response('', 204, $headers);
    } catch (\Throwable) {
        // DB/availability errors must fail closed: do not serve static files.
        return response('', 503, $headers);
    }
});
