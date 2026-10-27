<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAccessRole;
use App\Support\BrandbookGate;
use App\Support\SpecialRoles;
use App\Support\StoragePilotGate;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class StoragePilotAccessService
{
    public function verifiedEmployee(array $access, array $identity): ?Employee
    {
        if (!StoragePilotGate::canEnter((array) ($access['roles'] ?? []))
            || ($access['services']['storage'] ?? false) !== true) return null;

        $subject = (string) ($identity['sub'] ?? '');
        if ($subject === '') return null;
        $employee = Employee::query()->whereKey((int) ($access['employee_id'] ?? 0))
            ->where('keycloak_user_id', $subject)->first();
        if (!BrandbookGate::active($employee)) return null;
        return EmployeeAccessRole::query()->where('employee_id', $employee->id)
            ->where('role', SpecialRoles::PlatformAdmin)->exists() ? $employee : null;
    }

    public function statusForCookie(string $header): int
    {
        if (!preg_match('/(?:^|;\\s*)irlix_storage_pilot=([^;]*)/', $header, $match)) return 401;
        try {
            $session = json_decode(Crypt::decryptString(rawurldecode($match[1])), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return 401;
        }
        if (!BrandbookGate::validSession($session)) return 401;
        try {
            $employee = Employee::query()->whereKey($session['employee_id'])
                ->where('keycloak_user_id', $session['subject'])->first();
            if (!BrandbookGate::active($employee)) return 403;
            return EmployeeAccessRole::query()->where('employee_id', $employee->id)
                ->where('role', SpecialRoles::PlatformAdmin)->exists() ? 204 : 403;
        } catch (Throwable) {
            return 503;
        }
    }
}
