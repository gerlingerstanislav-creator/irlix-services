<?php

namespace App\Services;

use App\Models\Employee;
use App\Support\BrandbookGate;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class BrandbookAccessService
{
    public function __construct(private readonly SpecialRoleService $roles) {}

    /**
     * Return the nginx auth_request HTTP status. Errors fail closed, never
     * granting access on a database or decryption exception.
     */
    public function statusForCookie(string $header): int
    {
        if (!preg_match('/(?:^|;\\s*)irlix_brandbook=([^;]*)/', $header, $match)) return 401;

        try {
            $decoded = Crypt::decryptString(rawurldecode($match[1]));
            $session = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return 401;
        }
        if (!BrandbookGate::validSession($session)) return 401;

        try {
            $employee = Employee::query()
                ->whereKey((int) $session['employee_id'])
                ->where('keycloak_user_id', (string) $session['subject'])
                ->first();
            if (!BrandbookGate::active($employee)) return 403;
            if (!$this->roles->hasBrandbookRole((int) $employee->id)) return 403;
            return 204;
        } catch (Throwable) {
            return 503;
        }
    }
}
