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

    /**
     * Current authenticated admin pilot session with verified active Employees ID.
     * No user-controlled HTTP header, query or form parameter is trusted.
     * Internal Storage API calls the gate on every request. Do not expose the ID
     * to nginx-authenticated end users or use it to widen pilot permissions.
     */
    public function contextForCookie(string $header): array
    {
        if (!preg_match('/(?:^|;\\s*)irlix_storage_pilot=([^;]*)/', $header, $match)) {
            return ['status' => 401, 'employee_id' => null];
        }
        try {
            $session = json_decode(Crypt::decryptString(rawurldecode($match[1])), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['status' => 401, 'employee_id' => null];
        }
        if (!BrandbookGate::validSession($session)) {
            return ['status' => 401, 'employee_id' => null];
        }
        try {
            $employee = Employee::query()->whereKey($session['employee_id'])
                ->where('keycloak_user_id', $session['subject'])->first();
            if (!BrandbookGate::active($employee)) {
                return ['status' => 403, 'employee_id' => null];
            }
            if (!EmployeeAccessRole::query()->where('employee_id', $employee->id)
                ->where('role', SpecialRoles::PlatformAdmin)->exists()) {
                return ['status' => 403, 'employee_id' => null];
            }
            return ['status' => 204, 'employee_id' => (int) $employee->id];
        } catch (Throwable) {
            return ['status' => 503, 'employee_id' => null];
        }
    }

    public function statusForCookie(string $header): int
    {
        return $this->contextForCookie($header)['status'];
    }
}
