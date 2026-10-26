<?php

namespace App\Services;

use App\Models\Employee;
use App\Support\BrandbookGate;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class DesignSystemAccessService
{
    // Fail closed on missing/expired cookie, inactive identity and DB failures.
    public function statusForCookie(string $header): int
    {
        if (!preg_match('/(?:^|;\s*)irlix_design_system=([^;]*)/', $header, $match)) return 401;
        try {
            $session = json_decode(Crypt::decryptString(rawurldecode($match[1])), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return 401;
        }
        if (!BrandbookGate::validSession($session)) return 401;
        try {
            $employee = Employee::query()
                ->whereKey($session['employee_id'])
                ->where('keycloak_user_id', $session['subject'])
                ->first();
            return BrandbookGate::active($employee) ? 204 : 403;
        } catch (Throwable) {
            return 503;
        }
    }
}
