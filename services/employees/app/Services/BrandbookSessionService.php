<?php

namespace App\Services;

use App\Models\Employee;
use App\Support\BrandbookGate;

final class BrandbookSessionService
{
    public function __construct(private readonly SpecialRoleService $roles) {}

    public function isAllowedByAccess(array $access): bool
    {
        return ($access['services']['brandbook'] ?? false)
            && BrandbookGate::hasRole((array) ($access['roles'] ?? []));
    }

    public function verifiedEmployee(array $access, array $identity): ?Employee
    {
        $employee = Employee::query()->whereKey((int) ($access['employee_id'] ?? 0))
            ->where('keycloak_user_id', (string) ($identity['sub'] ?? ''))->first();

        if (!BrandbookGate::active($employee)) return null;
        if (!$this->roles->hasBrandbookRole((int) $employee->id)) return null;

        return $employee;
    }
}
