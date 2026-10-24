<?php

namespace App\Support;

/** Pure policy shared by signed session issuance and every nginx subrequest. */
final class BrandbookGate
{
    public static function hasRole(array $roles): bool
    {
        return in_array(SpecialRoles::TopManagement, $roles, true)
            || in_array(SpecialRoles::PlatformAdmin, $roles, true);
    }

    public static function active(?object $employee): bool
    {
        return $employee !== null && !empty($employee->keycloak_user_id)
            && (($employee->employment_status ?? '') === 'Трудоустроен'
                || ($employee->login ?? '') === 'admin');
    }

    public static function validSession(mixed $session): bool
    {
        return is_array($session)
            && is_int($session['employee_id'] ?? null) && $session['employee_id'] > 0
            && is_string($session['subject'] ?? null) && $session['subject'] !== ''
            && is_int($session['expires_at'] ?? null) && $session['expires_at'] > time()
            && $session['expires_at'] <= time() + 300;
    }
}
