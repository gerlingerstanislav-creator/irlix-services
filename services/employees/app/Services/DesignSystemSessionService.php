<?php

namespace App\Services;

use App\Models\Employee;
use App\Support\BrandbookGate;

final class DesignSystemSessionService
{
    public function verifiedEmployee(array $access, array $identity): ?Employee
    {
        if (($access['services']['design-system'] ?? false) !== true) return null;
        if ((int) ($access['employee_id'] ?? 0) <= 0 || empty($identity['sub'])) return null;
        $employee = Employee::query()
            ->whereKey((int) $access['employee_id'])
            ->where('keycloak_user_id', (string) $identity['sub'])
            ->first();
        return BrandbookGate::active($employee) ? $employee : null;
    }
}
